<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Double-entry ledger. Every money movement is one journal whose debits equal its credits, to the penny.
 * Journals are never edited or deleted: a mistake is fixed by a reversal journal. A locked month takes no new journals.
 */
class Ledger
{
    /** Standard chart of accounts (shared by all companies). Code => [name, kind, sub ledger]. */
    public const CHART = [
        '1000' => ['Bank accounts', 'ASSET', 'BANK'],
        '1050' => ['Cash and wallets', 'ASSET', 'BANK'],
        '1100' => ['Marketplace receivable (unsettled sales)', 'ASSET', 'SHOP'],
        '1150' => ['Other receivables', 'ASSET', 'PARTY'],
        '1200' => ['Stock (at landed cost)', 'ASSET', null],
        '1210' => ['Goods in transit', 'ASSET', null],
        '1300' => ['Supplier advances', 'ASSET', 'PARTY'],
        '1400' => ['VAT receivable', 'ASSET', null],
        '2000' => ['Supplier payables', 'LIABILITY', 'PARTY'],
        '2100' => ['Partner / investor payable', 'LIABILITY', 'PARTY'],
        '2150' => ['Partner / investor capital', 'LIABILITY', 'PARTY'],
        '2200' => ['VAT payable', 'LIABILITY', null],
        '2300' => ['Salaries and VA payable', 'LIABILITY', 'PARTY'],
        '2400' => ['Loans', 'LIABILITY', 'PARTY'],
        '2500' => ['Customer refunds payable', 'LIABILITY', null],
        '3000' => ['Owner equity', 'EQUITY', null],
        '3100' => ['Retained profit', 'EQUITY', null],
        '4000' => ['Sales', 'INCOME', 'SHOP'],
        '4100' => ['Other income', 'INCOME', null],
        '5000' => ['Cost of goods sold', 'EXPENSE', null],
        '5100' => ['Marketplace fees', 'EXPENSE', 'SHOP'],
        '5200' => ['Shipping and fulfilment', 'EXPENSE', null],
        '5300' => ['Advertising', 'EXPENSE', 'SHOP'],
        '5400' => ['Refunds and returns', 'EXPENSE', 'SHOP'],
        '5500' => ['Stock loss and write-off', 'EXPENSE', null],
        '6000' => ['Operating expenses', 'EXPENSE', null],
        '6100' => ['Salaries and VA cost', 'EXPENSE', null],
        '6200' => ['Bank charges and FX difference', 'EXPENSE', null],
        '7000' => ['Partner / investor profit share', 'EXPENSE', 'PARTY'],
        '9999' => ['Suspense (unmatched)', 'ASSET', null],
    ];

    public static function seedChart(): void
    {
        foreach (self::CHART as $code => [$name, $kind, $sub]) {
            DB::table('gl_accounts')->updateOrInsert(['fk_id_ent' => null, 'code' => $code], ['name' => $name, 'kind' => $kind, 'sub_ledger' => $sub, 'f_system' => 1, 'f_active' => 1]);
        }
    }

    public static function accountId(string $code): int
    {
        static $map = [];
        return $map[$code] ??= (int) (DB::table('gl_accounts')->where('code', $code)->value('id') ?? throw new \RuntimeException("Ledger account $code does not exist."));
    }

    /**
     * Post one journal.
     * $lines: each ['account' => '1000', 'debit' => 12.34] or ['account' => '2000', 'credit' => 12.34], optional ref_type, ref_id, memo, amount_cur.
     */
    public static function post(int $ent, string $date, string $sourceType, ?int $sourceId, array $lines, array $opt = []): int
    {
        if (count($lines) < 2) throw new \InvalidArgumentException('A journal needs at least two lines.');
        $dr = 0; $cr = 0;
        foreach ($lines as $i => $l) {
            $d = self::pence($l['debit'] ?? 0); $c = self::pence($l['credit'] ?? 0);
            if ($d < 0 || $c < 0) throw new \InvalidArgumentException('Debit and credit cannot be negative; swap the side instead.');
            if (($d > 0) === ($c > 0)) throw new \InvalidArgumentException('Line ' . ($i + 1) . ' needs a debit or a credit, not both and not neither.');
            $dr += $d; $cr += $c;
        }
        if ($dr !== $cr) {
            throw new \InvalidArgumentException(sprintf('Journal does not balance: debit %.2f, credit %.2f.', $dr / 100, $cr / 100));
        }
        $period = substr($date, 0, 7);
        if (self::periodLocked($ent, $period) && empty($opt['allow_locked'])) {
            throw new \RuntimeException("Month $period is locked. Post in the open month or ask the Owner to unlock.");
        }
        return DB::transaction(function () use ($ent, $date, $period, $sourceType, $sourceId, $lines, $opt) {
            $id = DB::table('gl_journals')->insertGetId([
                'fk_id_ent' => $ent, 'journal_no' => Numbering::next('JNL', $ent, $date), 'journal_date' => $date, 'period' => $period,
                'source_type' => $sourceType, 'source_id' => $sourceId, 'memo' => $opt['memo'] ?? null, 'currency' => $opt['currency'] ?? 'GBP',
                'fx_rate' => $opt['fx_rate'] ?? 1, 'reverses_id' => $opt['reverses_id'] ?? null, 'created_by' => Context::userId(), 'created_at' => now(),
            ]);
            foreach ($lines as $l) {
                $acc = DB::table('gl_accounts')->where('code', $l['account'])->first() ?? throw new \RuntimeException("Ledger account {$l['account']} does not exist.");
                if ($acc->sub_ledger && empty($l['ref_id']) && empty($opt['allow_no_ref'])) {
                    throw new \InvalidArgumentException("Account {$acc->code} {$acc->name} needs a {$acc->sub_ledger} reference on the line.");
                }
                DB::table('gl_lines')->insert([
                    'journal_id' => $id, 'account_id' => $acc->id, 'debit' => round((float) ($l['debit'] ?? 0), 2), 'credit' => round((float) ($l['credit'] ?? 0), 2),
                    'amount_cur' => $l['amount_cur'] ?? null, 'ref_type' => $l['ref_type'] ?? $acc->sub_ledger, 'ref_id' => $l['ref_id'] ?? null, 'memo' => $l['memo'] ?? null,
                ]);
            }
            return $id;
        });
    }

    /** Reverse a journal: same lines with the sides swapped, dated $date (default today). */
    public static function reverse(int $journalId, ?string $date = null, ?string $memo = null): int
    {
        $j = DB::table('gl_journals')->where('id', $journalId)->first() ?? throw new \RuntimeException('Journal not found.');
        if ($j->reversed_by_id) throw new \RuntimeException("Journal {$j->journal_no} is already reversed.");
        $lines = DB::table('gl_lines as l')->join('gl_accounts as a', 'a.id', '=', 'l.account_id')->where('l.journal_id', $journalId)
            ->get(['a.code', 'l.debit', 'l.credit', 'l.ref_type', 'l.ref_id', 'l.memo', 'l.amount_cur'])
            ->map(fn ($l) => ['account' => $l->code, 'debit' => $l->credit, 'credit' => $l->debit, 'ref_type' => $l->ref_type, 'ref_id' => $l->ref_id, 'memo' => $l->memo,
                'amount_cur' => $l->amount_cur === null ? null : -$l->amount_cur])->all();
        foreach ($lines as &$l) { if ((float) $l['debit'] == 0.0) unset($l['debit']); else unset($l['credit']); }
        return DB::transaction(function () use ($j, $lines, $date, $memo) {
            $rid = self::post((int) $j->fk_id_ent, $date ?? date('Y-m-d'), $j->source_type, $j->source_id, $lines,
                ['memo' => $memo ?? "Reversal of {$j->journal_no}", 'currency' => $j->currency, 'fx_rate' => $j->fx_rate, 'reverses_id' => $j->id, 'allow_no_ref' => true]);
            DB::table('gl_journals')->where('id', $j->id)->update(['reversed_by_id' => $rid]);
            return $rid;
        });
    }

    /** Balance of one account (debit minus credit), optionally for one reference and up to a date. */
    public static function balance(int $ent, string $account, ?string $refType = null, ?int $refId = null, ?string $upTo = null): float
    {
        $q = DB::table('gl_lines as l')->join('gl_journals as j', 'j.id', '=', 'l.journal_id')->join('gl_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('j.fk_id_ent', $ent)->where('a.code', $account);
        if ($refId !== null) $q->where('l.ref_id', $refId)->when($refType, fn ($x) => $x->where('l.ref_type', $refType));
        if ($upTo) $q->where('j.journal_date', '<=', $upTo);
        $r = $q->selectRaw('COALESCE(SUM(l.debit),0) d, COALESCE(SUM(l.credit),0) c')->first();
        return round($r->d - $r->c, 2);
    }

    /** Trial balance: one row per account; total debit must equal total credit. */
    public static function trialBalance(int $ent, ?string $upTo = null): array
    {
        $rows = DB::table('gl_lines as l')->join('gl_journals as j', 'j.id', '=', 'l.journal_id')->join('gl_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('j.fk_id_ent', $ent)->when($upTo, fn ($x) => $x->where('j.journal_date', '<=', $upTo))
            ->groupBy('a.code', 'a.name', 'a.kind')->orderBy('a.code')
            ->selectRaw('a.code, a.name, a.kind, SUM(l.debit) debit, SUM(l.credit) credit')->get();
        $out = ['rows' => [], 'debit' => 0.0, 'credit' => 0.0];
        foreach ($rows as $r) {
            $bal = round($r->debit - $r->credit, 2);
            $out['rows'][] = ['code' => $r->code, 'name' => $r->name, 'kind' => $r->kind, 'debit' => $bal > 0 ? $bal : 0.0, 'credit' => $bal < 0 ? -$bal : 0.0];
            $out['debit'] += $bal > 0 ? $bal : 0; $out['credit'] += $bal < 0 ? -$bal : 0;
        }
        $out['debit'] = round($out['debit'], 2); $out['credit'] = round($out['credit'], 2);
        $out['balanced'] = $out['debit'] === $out['credit'];
        return $out;
    }

    /** Profit and loss of a period range: income minus expenses. */
    public static function profitAndLoss(int $ent, string $from, string $to): array
    {
        $rows = DB::table('gl_lines as l')->join('gl_journals as j', 'j.id', '=', 'l.journal_id')->join('gl_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('j.fk_id_ent', $ent)->whereBetween('j.journal_date', [$from, $to])->whereIn('a.kind', ['INCOME', 'EXPENSE'])
            ->groupBy('a.code', 'a.name', 'a.kind')->orderBy('a.code')->selectRaw('a.code, a.name, a.kind, SUM(l.credit - l.debit) amount')->get();
        $income = 0; $expense = 0; $lines = [];
        foreach ($rows as $r) {
            $amt = round($r->amount, 2);
            $lines[] = ['code' => $r->code, 'name' => $r->name, 'kind' => $r->kind, 'amount' => $r->kind === 'INCOME' ? $amt : -$amt];
            if ($r->kind === 'INCOME') $income += $amt; else $expense += -$amt;
        }
        return ['lines' => $lines, 'income' => round($income, 2), 'expense' => round($expense, 2), 'profit' => round($income - $expense, 2)];
    }

    /** A month is locked for the ledger when it is on or before the company setting GL_LOCKED_UPTO (YYYY-MM), set by Month Close. */
    public static function periodLocked(int $ent, string $period): bool
    {
        $upTo = (string) Settings::get('GL_LOCKED_UPTO', $ent, '');
        return $upTo !== '' && $period <= $upTo;
    }

    private static function pence(mixed $v): int
    {
        return (int) round(((float) $v) * 100);
    }
}
