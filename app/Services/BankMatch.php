<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-FIN-70 / IC-15 bank matching added to MoneyService::autoMatch (each match stores its rule code):
 * M6 invoice – an IN line = an UNPAID / PART_PAID invoice issued by our company (issuer party f_is_sbs), an OUT line = one addressed to our company (we owe it);
 *   amount = balance ±0.01 and the invoice number in the description / reference; exactly one → INVOICE_RECEIPT / INVOICE_PAYMENT, the invoice payment is made
 *   with the bank line as its proof (InvestorDocs::refresh sets the status);
 * sum matching – an OUT line = the sum (±0.01) of several open expenses of one payee paid from this account within the supplier window; exactly one set → M3S;
 *   by hand: several targets ticked (matchMany);
 * payment-provider profile – an account of kind PROVIDER (PayPal, Payoneer, Wise balance …): a money-out line whose text says fee → BANK_FEES (MPF).
 */
class BankMatch
{
    private static function link(object $l, string $type, string $ids, string $rule, string $status = 'AUTO', ?string $cat = null): void
    {
        DB::table('fin_bank_transactions')->where('id', $l->id)->update(['matched_to_type' => $type, 'matched_to_id' => $ids, 'match_status' => $status, 'match_rule' => $rule, 'category_code' => $cat ?? $l->category_code, 'matched_by' => Context::userId(), 'matched_at' => now()]);
        TaskEngine::close('FIN_BANK_UNMATCHED', [$l->id], 'Line matched');
        EventBus::fire('BANK_LINE_MATCHED', 'fin_bank_transactions', $l->id, ['to' => $type, 'id' => $ids, 'rule' => $rule], (int) $l->company_id);
    }

    private static function bal(object $i): float
    {
        return round((float) $i->total - (float) DB::table('t_invoice_payments')->where('fk_id_invoice', $i->id)->where('f_deleted', 0)->sum('amount'), 2);
    }

    /** M6. Returns true when the line was matched. */
    public static function invoice(int $ent, object $l): bool
    {
        $amt = round((float) $l->amount, 2); if ($amt == 0.0) return false;
        $ours = DB::table('t_inv_parties')->where('fk_id_ent', $ent)->where('f_is_sbs', 1)->pluck('id')->all(); if (! $ours) return false;
        $text = mb_strtoupper(($l->description ?? '') . ' ' . ($l->reference_no ?? '') . ' ' . ($l->counterparty ?? ''));
        $c = DB::table('t_invoices')->where('fk_id_ent', $ent)->whereIn('status', ['UNPAID', 'PART_PAID'])->where('invoice_type', '<>', 'CREDIT_NOTE')
            ->whereIn($amt > 0 ? 'fk_issuer_party' : 'fk_recipient_party', $ours)->get()
            ->filter(fn ($i) => $i->invoice_no !== '' && str_contains($text, mb_strtoupper((string) $i->invoice_no)) && abs(self::bal($i) - abs($amt)) <= 0.01)->values();
        if ($c->count() !== 1) return false;
        $i = $c[0];
        DB::transaction(function () use ($i, $l, $amt) {
            DB::table('t_invoice_payments')->insert(['fk_id_invoice' => $i->id, 'd_payment' => $l->txn_date, 'amount' => min(abs($amt), self::bal($i)), 'method' => 'Bank transfer', 'received_into' => mb_substr('Bank line #' . $l->id, 0, 200),
                'reference' => mb_substr((string) ($l->reference_no ?: $l->description), 0, 200), 'notes' => 'Matched from the bank statement (M6)', 'proof_file' => '', 'f_deleted' => 0, 'fk_id_bank_line' => $l->id, 'created_by' => (int) Context::userId(), 'created_at' => now()]);
            InvestorDocs::refresh((int) $i->id);
            self::link($l, 'INVOICE', (string) $i->id, 'M6', 'AUTO', $amt > 0 ? 'INVOICE_RECEIPT' : 'INVOICE_PAYMENT');
        });
        return true;
    }

    public static function undoInvoice(int $lineId): void
    {
        foreach (DB::table('t_invoice_payments')->where('fk_id_bank_line', $lineId)->where('f_deleted', 0)->get() as $p) {
            DB::table('t_invoice_payments')->where('id', $p->id)->update(['f_deleted' => 1, 'deleted_by' => (int) Context::userId(), 'deleted_at' => now()]);
            InvestorDocs::refresh((int) $p->fk_id_invoice);
        }
    }

    /** Subsets (≤ 12 items) whose amounts add up to $target ±0.01. */
    public static function subsets(array $amounts, float $target): array
    {
        $amounts = array_slice($amounts, 0, 12, true); $keys = array_keys($amounts); $n = count($keys); $hits = [];
        for ($m = 1; $m < (1 << $n); $m++) {
            if (substr_count(decbin($m), '1') < 2) continue;
            $s = 0.0; $set = []; for ($b = 0; $b < $n; $b++) if ($m & (1 << $b)) { $s += $amounts[$keys[$b]]; $set[] = $keys[$b]; }
            if (abs(round($s, 2) - $target) <= 0.01) $hits[] = $set;
        }
        return $hits;
    }

    /** Sum matching on an OUT line: exactly one payee with exactly one set of open expenses. */
    public static function sum(int $ent, object $l, int $days): bool
    {
        $target = abs(round((float) $l->amount, 2)); $found = [];
        $rows = DB::table('fin_expenses')->where('fk_id_ent', $ent)->where('f_active', 1)->whereNull('fk_id_bank_line')->where('fk_id_paid_from', $l->bank_account_id)->where('fk_id_payee', '>', 0)
            ->whereBetween('d_expense', [date('Y-m-d', strtotime("$l->txn_date -$days day")), date('Y-m-d', strtotime("$l->txn_date +$days day"))])->orderBy('d_expense')->orderBy('id')->get();
        foreach ($rows->groupBy('fk_id_payee') as $g) {
            $hits = self::subsets($g->mapWithKeys(fn ($e) => [(int) $e->id => (float) $e->amount])->all(), $target);
            foreach ($hits as $h) $found[] = $h;
        }
        if (count($found) !== 1) return false;
        DB::transaction(function () use ($found, $l) {
            DB::table('fin_expenses')->whereIn('id', $found[0])->update(['fk_id_bank_line' => $l->id]);
            self::link($l, 'EXPENSE', implode(',', $found[0]), 'M3S');
        });
        return true;
    }

    /** By hand: several open expenses (or supplier payments) of one payee for one line – the amounts must add up to the line. */
    public static function matchMany(int $ent, int $lineId, string $kind, array $ids): array
    {
        return DB::transaction(function () use ($ent, $lineId, $kind, $ids) {
            $l = DB::table('fin_bank_transactions')->where('id', $lineId)->where('company_id', $ent)->lockForUpdate()->first() ?? throw new \InvalidArgumentException('Bank line not found.');
            if ($l->match_status !== 'UNMATCHED') throw new \InvalidArgumentException('This line is already matched. Undo the match first.');
            if ((float) $l->amount >= 0) throw new \InvalidArgumentException('Several payables are matched to money going out only.');
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids)))); if (count($ids) < 1) throw new \InvalidArgumentException('Tick at least one item.');
            if ($kind !== 'EXPENSE') throw new \InvalidArgumentException('Choose expenses to match.');
            $rows = DB::table('fin_expenses')->where('fk_id_ent', $ent)->where('f_active', 1)->whereIn('id', $ids)->lockForUpdate()->get();
            if ($rows->count() !== count($ids)) throw new \InvalidArgumentException('One of the ticked items was not found.');
            if ($rows->whereNotNull('fk_id_bank_line')->isNotEmpty()) throw new \InvalidArgumentException('One of the ticked items is already matched to a bank line.');
            if ($rows->pluck('fk_id_payee')->unique()->count() > 1) throw new \InvalidArgumentException('All ticked items must be for the same payee.');
            $sum = round((float) $rows->sum('amount'), 2); $line = abs(round((float) $l->amount, 2));
            if (abs($sum - $line) > 0.01) throw new \InvalidArgumentException('The ticked items add up to ' . number_format($sum, 2) . ', the bank line is ' . number_format($line, 2) . '.');
            DB::table('fin_expenses')->whereIn('id', $ids)->update(['fk_id_bank_line' => $l->id]);
            self::link($l, 'EXPENSE', implode(',', $ids), 'BY_HAND', 'MANUAL');
            Audit::log('fin_bank_transactions', (int) $l->id, 'UPDATE', ['matched' => $ids], 'several payables', $ent);
            return ['message' => 'Matched to ' . count($ids) . ' item(s).'];
        });
    }

    /** Payment-provider profile: a fee line of a PROVIDER account is booked as bank fees. */
    public static function providerFee(int $ent, object $l): bool
    {
        if ((float) $l->amount >= 0 || ! preg_match('/\bfees?\b/i', (string) $l->description)) return false;
        if (DB::table('fin_bank_accounts')->where('id', $l->bank_account_id)->value('account_kind') !== 'PROVIDER') return false;
        if (! DB::table('fin_categories')->where('code', 'BANK_FEES')->where('f_active', 1)->exists()) return false;
        MoneyService::categorise($ent, (int) $l->id, ['category' => 'BANK_FEES', 'note' => mb_substr('Provider fee · ' . $l->description, 0, 200)]);
        DB::table('fin_bank_transactions')->where('id', $l->id)->update(['match_status' => 'AUTO', 'match_rule' => 'MPF']);
        return true;
    }
}
