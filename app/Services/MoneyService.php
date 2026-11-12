<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Money: bank accounts, bank statement import, matching, expenses.
 * Rule of the ledger: a bank line never posts by itself. What it is matched to posts (supplier payment, expense, payout, transfer…).
 * So for every bank account: ledger balance of 1000 (that account) = opening + all statement lines, once every line is matched.
 */
class MoneyService
{
    /** Category → ledger account. Anything not listed: money out → 6000 operating expenses, money in → 4100 other income. */
    public const ACCOUNTS = [
        'SALES_PAYOUT' => '1100', 'REFUND_RECEIVED' => '5400', 'CAPITAL_IN' => '2150', 'WITHDRAWAL' => '2100', 'STOCK_LOSS' => '5500', 'IMPORT_FREIGHT' => '1210', 'IMPORT_DUTY' => '1210',
        'DUTY_VAT' => '1400', 'VA_SHARE_PAYOUT' => '2300', 'SALARY_VA' => '6100', 'SALARIES_OTHER' => '6100', 'HMRC_VAT' => '2200', 'HMRC_PAYE' => '6100', 'ADS_EXTERNAL' => '5300', 'PPC' => '5300',
        'POSTAGE' => '5200', 'PACKAGING' => '5200', 'FREIGHT' => '5200', 'BANK_FEES' => '6200', 'LOAN_IN' => '2400', 'LOAN_OUT' => '2400', 'OTHER_IN' => '4100', 'SUPPLIER_REFUND' => '1300',
        'PLATFORM_FEE' => '5100', 'INTERCOMPANY_IN' => '1150', 'INTERCOMPANY_OUT' => '1150', 'INVOICE_RECEIPT' => '1150', 'INVOICE_PAYMENT' => '2100',
    ];
    private const NOT_BY_HAND = ['SUPPLIER_PAYMENT' => 'Enter the payment on the purchase order (Buying); the bank line is then matched to it.', 'SPLIT' => 'Split lines come in a later step.',
        'OWN_TRANSFER_IN' => 'Use "Transfer between own accounts".', 'OWN_TRANSFER_OUT' => 'Use "Transfer between own accounts".'];

    public static function accountFor(string $category): string
    {
        if (isset(self::ACCOUNTS[$category])) return self::ACCOUNTS[$category];
        $c = DB::table('fin_categories')->where('code', $category)->where('f_active', 1)->first() ?? throw new \InvalidArgumentException('Choose a category from the list.');
        return $c->direction === 'IN' ? '4100' : '6000';
    }

    private static function bank(int $ent, int $id): object
    {
        return DB::table('fin_bank_accounts')->where('id', $id)->where('company_id', $ent)->first() ?? throw new \InvalidArgumentException('Choose a bank account of this company.');
    }

    // ───────────── bank accounts ─────────────

    public static function createBank(int $ent, array $d): int
    {
        return DB::transaction(function () use ($ent, $d) {
            $open = round((float) ($d['opening_balance'] ?? 0), 2); $date = $d['opening_date'] ?? date('Y-m-d'); $cur = strtoupper($d['currency'] ?? Fx::base($ent));
            $id = DB::table('fin_bank_accounts')->insertGetId(['company_id' => $ent, 'bank_name' => $d['bank_name'], 'account_label' => $d['account_label'], 'currency' => $cur, 'opening_balance' => $open, 'opening_date' => $date, 'is_active' => 1]);
            if ($open != 0.0) {
                $base = round(abs($open) * Fx::rate($cur, $date, $ent), 2); $side = $open > 0 ? ['debit', 'credit'] : ['credit', 'debit'];
                Ledger::post($ent, $date, 'OPENING_BANK', $id, [['account' => '1000', $side[0] => $base, 'ref_id' => $id, 'amount_cur' => $open], ['account' => '3000', $side[1] => $base]], ['memo' => "Opening balance {$d['account_label']}", 'currency' => $cur]);
            }
            Audit::log('fin_bank_accounts', $id, 'INSERT', ['label' => $d['account_label'], 'opening' => $open], null, $ent);
            return $id;
        });
    }

    /** One row per account: balance by the books, balance by the statement lines, and what is still unmatched. */
    public static function banks(int $ent): array
    {
        return DB::table('fin_bank_accounts')->where('company_id', $ent)->orderBy('id')->get()->map(function ($b) use ($ent) {
            // lines set aside are not counted: they are money that is already inside the opening balance
            $t = DB::table('fin_bank_transactions')->where('bank_account_id', $b->id)->where('match_status', '<>', 'IGNORED')->selectRaw("COALESCE(SUM(amount),0) s, COALESCE(SUM(amount_gbp),0) g, COUNT(*) n, COALESCE(SUM(match_status = 'UNMATCHED'),0) u, COALESCE(SUM(IF(match_status = 'UNMATCHED', amount_gbp, 0)),0) ug, MAX(txn_date) last")->first();
            $openBase = round((float) $b->opening_balance * ((float) $b->opening_balance != 0.0 ? Fx::rate($b->currency ?: Fx::base($ent), $b->opening_date ?: date('Y-m-d'), $ent) : 1), 2);
            $books = Ledger::balance($ent, '1000', null, (int) $b->id); $stmt = round($openBase + (float) $t->g, 2);
            // booked in the tool but not seen on a bank line yet: supplier payments and hand-entered expenses without a bank line
            $pending = (float) DB::table('gl_lines as l')->join('gl_journals as j', 'j.id', '=', 'l.journal_id')->join('gl_accounts as a', 'a.id', '=', 'l.account_id')->where('j.fk_id_ent', $ent)->where('a.code', '1000')->where('l.ref_id', $b->id)
                ->where(fn ($w) => $w->where(fn ($x) => $x->where('j.source_type', 'PAYMENT')->whereIn('j.source_id', DB::table('t_src_payments')->where('fk_id_ent', $ent)->whereNull('fk_id_bank_line')->select('id')))
                    ->orWhere(fn ($x) => $x->where('j.source_type', 'EXPENSE')->whereIn('j.source_id', DB::table('fin_expenses')->where('fk_id_ent', $ent)->whereNull('fk_id_bank_line')->select('id'))))
                ->selectRaw('COALESCE(SUM(l.debit - l.credit),0) v')->value('v');
            $diff = round($stmt - $books, 2);
            return ['id' => $b->id, 'bank_name' => $b->bank_name, 'label' => $b->account_label, 'currency' => $b->currency, 'active' => (int) $b->is_active, 'statement_balance' => round((float) $b->opening_balance + (float) $t->s, 2),
                'statement_base' => $stmt, 'books_base' => $books, 'difference' => $diff, 'unmatched' => (int) $t->u, 'unmatched_base' => round((float) $t->ug, 2), 'not_on_bank_yet' => round($pending, 2), 'lines' => (int) $t->n, 'last_line' => $t->last,
                'unexplained' => round($diff - (float) $t->ug + $pending, 2), 'explained' => abs(round($diff - (float) $t->ug + $pending, 2)) < 0.005];   // bank − books = lines not matched − things booked but not on the bank yet
        })->all();
    }

    // ───────────── statement import ─────────────

    /** Read a bank CSV by its profile. Lines already loaded are skipped (bank's own id, or date + amount + text + position among equals). */
    public static function importStatement(int $ent, int $bankId, int $profileId, string $csv, string $fileName = 'statement.csv'): array
    {
        $bank = self::bank($ent, $bankId);
        $p = DB::table('bank_statement_profiles')->where('id', $profileId)->whereIn('fk_id_ent', [0, $ent])->first() ?? throw new \InvalidArgumentException('Choose the layout of this bank file.');
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $rows = array_values(array_filter(array_map('str_getcsv', preg_split('/\r\n|\n|\r/', trim($csv))), fn ($r) => $r && implode('', $r) !== ''));
        $hi = max(1, (int) $p->header_row) - 1;
        if (! isset($rows[$hi])) throw new \InvalidArgumentException('The file is empty.');
        $head = array_map(fn ($h) => strtolower(trim((string) $h)), $rows[$hi]);
        $col = function (?string $name, bool $must = false) use ($head) {
            if ($name === null || $name === '') return null;
            $i = array_search(strtolower(trim($name)), $head, true);
            if ($i === false && $must) throw new \InvalidArgumentException("Column \"$name\" is not in this file. Columns found: " . implode(', ', $head) . '. Choose the right layout for this bank.');
            return $i === false ? null : $i;
        };
        $two = $p->amount_rule !== 'ONE_SIGNED';
        $cDate = $col($p->date_column, true); $cDesc = $col($p->description_column, true); $cAmt = $two ? null : $col($p->amount_column, true);
        $cOut = $two ? $col($p->debit_column, true) : null; $cIn = $two ? $col($p->credit_column, true) : null;
        $cRef = $col($p->reference_column); $cParty = $col($p->counterparty_column); $cId = $col($p->txn_id_column); $cCur = $col($p->currency_column);
        $num = fn ($v) => (float) str_replace([',', '£', '$', '€', ' '], '', (string) $v);
        $cur = $bank->currency ?: Fx::base($ent); $errors = []; $new = 0; $dup = 0; $seen = []; $from = null; $to = null;

        return DB::transaction(function () use ($ent, $bank, $rows, $hi, $p, $two, $cDate, $cDesc, $cAmt, $cOut, $cIn, $cRef, $cParty, $cId, $cCur, $num, $cur, $fileName, $csv, &$errors, &$new, &$dup, &$seen, &$from, &$to) {
            $batch = DB::table('fin_import_batches')->insertGetId(['report_type' => 'BANK_STATEMENT', 'company_id' => $ent, 'bank_account_id' => $bank->id, 'file_name' => mb_substr($fileName, 0, 200), 'file_hash' => sha1($csv), 'imported_by' => Context::userId(), 'imported_at' => now()]);
            foreach (array_slice($rows, $hi + 1) as $n => $r) {
                $line = $hi + 2 + $n;
                $d = \DateTime::createFromFormat('!' . $p->date_format, trim((string) ($r[$cDate] ?? '')));
                if (! $d) { $errors[] = "Row $line: date \"" . ($r[$cDate] ?? '') . "\" is not in the form {$p->date_format}"; continue; }
                $amt = round($two ? $num($r[$cIn] ?? 0) - abs($num($r[$cOut] ?? 0)) : $num($r[$cAmt] ?? 0), 2);
                if ($amt == 0.0) { $errors[] = "Row $line: no amount"; continue; }
                if ($cCur !== null && ($rc = strtoupper(trim((string) ($r[$cCur] ?? '')))) && $rc !== strtoupper($cur)) { $errors[] = "Row $line: currency $rc but the account is $cur"; continue; }
                $date = $d->format('Y-m-d'); $desc = trim((string) ($r[$cDesc] ?? ''));
                $key = $cId !== null && trim((string) ($r[$cId] ?? '')) !== '' ? trim((string) $r[$cId]) : null;
                if ($key === null) { $k = "$date|$amt|$desc"; $seen[$k] = ($seen[$k] ?? 0) + 1; $key = 'H' . substr(sha1($k . '|' . $seen[$k]), 0, 30); }
                if (DB::table('fin_bank_transactions')->where('bank_account_id', $bank->id)->where('bank_txn_id', $key)->exists()) { $dup++; continue; }
                DB::table('fin_bank_transactions')->insert(['batch_id' => $batch, 'bank_account_id' => $bank->id, 'company_id' => $ent, 'txn_date' => $date, 'amount' => $amt, 'currency' => $cur, 'amount_gbp' => Fx::toBase($amt, $cur, $date, $ent),
                    'description' => mb_substr($desc, 0, 250), 'counterparty' => $cParty !== null ? mb_substr(trim((string) ($r[$cParty] ?? '')), 0, 120) : null, 'bank_txn_id' => mb_substr($key, 0, 80),
                    'reference_no' => $cRef !== null ? mb_substr(trim((string) ($r[$cRef] ?? '')), 0, 60) : null, 'match_status' => 'UNMATCHED']);
                $new++; $from = $from === null || $date < $from ? $date : $from; $to = $to === null || $date > $to ? $date : $to;
            }
            DB::table('fin_import_batches')->where('id', $batch)->update(['rows_total' => $new + $dup + count($errors), 'rows_new' => $new, 'rows_duplicate' => $dup, 'rows_error' => count($errors), 'period_from' => $from, 'period_to' => $to]);
            $matched = self::autoMatch($ent, (int) $bank->id);
            TaskEngine::close('FIN_BANK_STALE', [$bank->id], 'New bank lines loaded');
            return ['batch' => $batch, 'new' => $new, 'duplicates' => $dup, 'errors' => $errors, 'matched' => $matched, 'from' => $from, 'to' => $to];
        });
    }

    // ───────────── matching ─────────────

    private static function link(object $l, string $type, int $id, string $rule, string $status = 'AUTO'): void
    {
        DB::table('fin_bank_transactions')->where('id', $l->id)->update(['matched_to_type' => $type, 'matched_to_id' => $id, 'match_status' => $status, 'match_rule' => $rule, 'matched_by' => Context::userId(), 'matched_at' => now()]);
        TaskEngine::close('FIN_BANK_UNMATCHED', [$l->id], 'Line matched');
        EventBus::fire('BANK_LINE_MATCHED', 'fin_bank_transactions', $l->id, ['to' => $type, 'id' => $id, 'rule' => $rule], (int) $l->company_id);
    }

    /** Match what can only mean one thing: same amount, close date, same account, exactly one candidate. Two candidates → left for a person. */
    public static function autoMatch(int $ent, int $bankId): int
    {
        $n = 0; $dSup = (int) (Settings::get('match_supplier_days', $ent, 5) ?: 5); $dPay = (int) (Settings::get('MATCH_PAYOUT_DAYS', $ent, 5) ?: 5);
        $near = fn ($q, string $col, string $date, int $days) => $q->whereBetween($col, [date('Y-m-d', strtotime("$date -$days day")), date('Y-m-d', strtotime("$date +$days day"))]);
        foreach (DB::table('fin_bank_transactions')->where('bank_account_id', $bankId)->where('company_id', $ent)->where('match_status', 'UNMATCHED')->orderBy('txn_date')->orderBy('id')->get() as $l) {
            $amt = round((float) $l->amount, 2);
            if ($amt < 0) {
                $pays = $near(DB::table('t_src_payments as p')->join('gl_journals as j', fn ($j) => $j->on('j.source_id', '=', 'p.id')->where('j.source_type', 'PAYMENT')->whereNull('j.reverses_id')->whereNull('j.reversed_by_id'))
                    ->join('gl_lines as gl', 'gl.journal_id', '=', 'j.id')->join('gl_accounts as a', fn ($j) => $j->on('a.id', '=', 'gl.account_id')->where('a.code', '1000'))
                    ->where('p.fk_id_ent', $ent)->where('p.f_active', 1)->whereNull('p.fk_id_bank_line')->where('gl.ref_id', $bankId)->where(fn ($w) => $w->where('gl.amount_cur', $amt)->orWhere('gl.credit', abs((float) $l->amount_gbp))), 'p.payment_date', $l->txn_date, $dSup)->distinct()->pluck('p.id');
                if ($pays->count() === 1) { DB::table('t_src_payments')->where('id', $pays[0])->update(['fk_id_bank_line' => $l->id]); self::link($l, 'PAYMENT', (int) $pays[0], 'AMOUNT_DATE_ACCOUNT'); $n++; continue; }
                $exp = $near(DB::table('fin_expenses')->where('fk_id_ent', $ent)->where('f_active', 1)->whereNull('fk_id_bank_line')->where('fk_id_paid_from', $bankId)->where('amount', abs($amt)), 'd_expense', $l->txn_date, $dSup)->pluck('id');
                if ($exp->count() === 1) { DB::table('fin_expenses')->where('id', $exp[0])->update(['fk_id_bank_line' => $l->id]); self::link($l, 'EXPENSE', (int) $exp[0], 'AMOUNT_DATE_ACCOUNT'); $n++; continue; }
            } else {
                $c = SettlementService::payoutCandidates($ent, $amt, (string) $l->currency, $l->txn_date, $dPay);
                if (count($c) > 1) { $same = array_values(array_filter($c, fn ($x) => $x->pay_date === $l->txn_date)); if (count($same) === 1) $c = $same; }   // several payouts of the same amount: the one dated the same day
                if (count($c) === 1) { $first = SettlementService::paid($ent, (int) $c[0]->fk_id_branch, (string) $c[0]->pay_key, $l); self::link($l, 'PAYOUT', $first, 'PAYOUT_AMOUNT_DATE'); $n++; continue; }
            }
        }
        return $n;
    }

    /**
     * A person says what an unmatched line is. $d: category, branch (shop, for shop accounts), party, note, vat_amount.
     * Money-out categories that are costs also make an expense record.
     */
    public static function categorise(int $ent, int $lineId, array $d): array
    {
        return DB::transaction(function () use ($ent, $lineId, $d) {
            $l = DB::table('fin_bank_transactions')->where('id', $lineId)->where('company_id', $ent)->lockForUpdate()->first() ?? throw new \InvalidArgumentException('Bank line not found.');
            if ($l->match_status !== 'UNMATCHED') throw new \InvalidArgumentException('This line is already matched. Undo the match first.');
            $cat = DB::table('fin_categories')->where('code', $d['category'] ?? '')->where('f_active', 1)->first() ?? throw new \InvalidArgumentException('Choose a category from the list.');
            if (isset(self::NOT_BY_HAND[$cat->code])) throw new \InvalidArgumentException(self::NOT_BY_HAND[$cat->code]);
            $in = (float) $l->amount > 0;
            if ($cat->direction !== 'BOTH' && ($cat->direction === 'IN') !== $in) throw new \InvalidArgumentException("\"{$cat->name}\" is for money " . ($cat->direction === 'IN' ? 'in' : 'out') . ', this line is money ' . ($in ? 'in' : 'out') . '.');
            $acc = self::accountFor($cat->code); $sub = DB::table('gl_accounts')->where('code', $acc)->value('sub_ledger'); $base = abs((float) $l->amount_gbp); $ref = null; $noRef = false;
            if ($sub === 'SHOP') { $ref = (int) ($d['branch'] ?? 0); if (! $ref || ! DB::table('t_branches')->where('id', $ref)->where('fk_id_ent', $ent)->exists()) throw new \InvalidArgumentException('Choose the shop this money belongs to.'); }
            elseif ($sub === 'PARTY') { $ref = (int) ($d['party'] ?? 0) ?: null; $noRef = $ref === null; }
            $memo = $cat->name . ' · ' . mb_substr((string) $l->description, 0, 80);
            $isCost = ! $in && in_array($cat->profit_role, ['FIXED', 'VARIABLE', 'COGS'], true); $expId = null;
            if ($isCost) {
                $expId = DB::table('fin_expenses')->insertGetId(['fk_id_ent' => $ent, 'd_expense' => $l->txn_date, 'category_code' => $cat->code, 'fk_id_branch' => $d['branch'] ?? null, 'amount' => abs((float) $l->amount), 'fk_id_currency' => self::currencyId($l->currency, $ent),
                    'exchange_rate' => (float) $l->amount != 0.0 ? round($base / abs((float) $l->amount), 6) : 1, 'amount_gbp' => $base, 'vat_amount' => $d['vat_amount'] ?? null, 'fk_id_paid_from' => $l->bank_account_id, 'fk_id_bank_line' => $l->id,
                    'source' => 'BANK', 'note' => $d['note'] ?? mb_substr((string) $l->description, 0, 200), 'f_active' => 1, 'created_by' => Context::userId(), 'created_at' => now()]);
            }
            $bankLine = ['account' => '1000', $in ? 'debit' : 'credit' => $base, 'ref_id' => (int) $l->bank_account_id, 'amount_cur' => (float) $l->amount];
            $other = ['account' => $acc, $in ? 'credit' : 'debit' => $base, 'ref_id' => $ref];
            Ledger::post($ent, $l->txn_date, $isCost ? 'EXPENSE' : 'BANK_LINE', $isCost ? $expId : (int) $l->id, [$bankLine, $other], ['memo' => $memo, 'currency' => $l->currency, 'allow_no_ref' => $noRef]);
            DB::table('fin_bank_transactions')->where('id', $l->id)->update(['category_code' => $cat->code, 'branch_id' => $d['branch'] ?? null]);
            self::link($l, $isCost ? 'EXPENSE' : 'CATEGORY', $isCost ? $expId : (int) $l->id, 'BY_HAND', 'MANUAL');
            return ['line' => (int) $l->id, 'account' => $acc, 'expense' => $expId];
        });
    }

    /** A person picks which booked marketplace payout this money-in line is (when the tool could not be sure). */
    public static function matchPayout(int $ent, int $lineId, int $branchId, string $payKey): void
    {
        DB::transaction(function () use ($ent, $lineId, $branchId, $payKey) {
            $l = DB::table('fin_bank_transactions')->where('id', $lineId)->where('company_id', $ent)->lockForUpdate()->first() ?? throw new \InvalidArgumentException('Bank line not found.');
            if ($l->match_status !== 'UNMATCHED') throw new \InvalidArgumentException('This line is already matched.');
            $ok = array_filter(SettlementService::payoutCandidates($ent, (float) $l->amount, (string) $l->currency, $l->txn_date, 3650), fn ($c) => (int) $c->fk_id_branch === $branchId && (string) $c->pay_key === $payKey);
            if (! $ok) throw new \InvalidArgumentException('That payout is not the same amount as this line, or it is not booked yet.');
            self::link($l, 'PAYOUT', SettlementService::paid($ent, $branchId, $payKey, $l), 'BY_HAND', 'MANUAL');
        });
    }

    /** Money moved between two of the company's own accounts. The matching line in the other account is linked when it is there. */
    public static function transfer(int $ent, int $lineId, int $otherBankId): array
    {
        return DB::transaction(function () use ($ent, $lineId, $otherBankId) {
            $l = DB::table('fin_bank_transactions')->where('id', $lineId)->where('company_id', $ent)->lockForUpdate()->first() ?? throw new \InvalidArgumentException('Bank line not found.');
            if ($l->match_status !== 'UNMATCHED') throw new \InvalidArgumentException('This line is already matched.');
            $other = self::bank($ent, $otherBankId);
            if ($other->id == $l->bank_account_id) throw new \InvalidArgumentException('Choose the other account, not the same one.');
            $base = abs((float) $l->amount_gbp); $in = (float) $l->amount > 0; $days = (int) (Settings::get('match_intercompany_days', $ent, 2) ?: 2);
            $jid = Ledger::post($ent, $l->txn_date, 'TRANSFER', (int) $l->id, [
                ['account' => '1000', $in ? 'debit' : 'credit' => $base, 'ref_id' => (int) $l->bank_account_id, 'amount_cur' => (float) $l->amount],
                ['account' => '1000', $in ? 'credit' : 'debit' => $base, 'ref_id' => (int) $other->id]], ['memo' => 'Transfer between own accounts', 'currency' => $l->currency]);
            self::link($l, 'TRANSFER', $jid, 'BY_HAND', 'MANUAL');
            $mirror = DB::table('fin_bank_transactions')->where('bank_account_id', $other->id)->where('match_status', 'UNMATCHED')->where('amount_gbp', -1 * (float) $l->amount_gbp)
                ->whereBetween('txn_date', [date('Y-m-d', strtotime("{$l->txn_date} -$days day")), date('Y-m-d', strtotime("{$l->txn_date} +$days day"))])->orderBy('id')->get();
            if ($mirror->count() === 1) self::link($mirror[0], 'TRANSFER', $jid, 'MIRROR_OF_TRANSFER', 'AUTO');
            DB::table('fin_bank_transactions')->where('id', $l->id)->update(['category_code' => $in ? 'OWN_TRANSFER_IN' : 'OWN_TRANSFER_OUT']);
            return ['journal' => $jid, 'mirror' => $mirror->count() === 1 ? (int) $mirror[0]->id : null];
        });
    }

    /** Mark a line as not ours to book (for example a line of the opening balance). Needs a reason. */
    public static function ignore(int $ent, int $lineId, string $reason): void
    {
        $l = DB::table('fin_bank_transactions')->where('id', $lineId)->where('company_id', $ent)->where('match_status', 'UNMATCHED')->first() ?? throw new \InvalidArgumentException('Only an unmatched line can be set aside.');
        if (trim($reason) === '') throw new \InvalidArgumentException('Give the reason.');
        DB::table('fin_bank_transactions')->where('id', $l->id)->update(['match_status' => 'IGNORED', 'match_rule' => 'SET_ASIDE', 'matched_by' => Context::userId(), 'matched_at' => now()]);
        TaskEngine::close('FIN_BANK_UNMATCHED', [$l->id], 'Line set aside');
        Audit::log('fin_bank_transactions', $l->id, 'UPDATE', ['ignored' => true], $reason, $ent);
    }

    /** Undo a match. What was only linked is unlinked; what the match itself booked is reversed. */
    public static function unmatch(int $ent, int $lineId): void
    {
        DB::transaction(function () use ($ent, $lineId) {
            $l = DB::table('fin_bank_transactions')->where('id', $lineId)->where('company_id', $ent)->lockForUpdate()->first() ?? throw new \InvalidArgumentException('Bank line not found.');
            if ($l->match_status === 'UNMATCHED') throw new \InvalidArgumentException('This line is not matched.');
            $rev = function (string $type, int $id) { foreach (DB::table('gl_journals')->where('source_type', $type)->where('source_id', $id)->whereNull('reverses_id')->whereNull('reversed_by_id')->pluck('id') as $j) Ledger::reverse((int) $j, null, 'Bank match undone'); };
            switch ($l->matched_to_type) {
                case 'PAYMENT': DB::table('t_src_payments')->where('id', $l->matched_to_id)->update(['fk_id_bank_line' => null]); break;
                case 'EXPENSE':
                    $e = DB::table('fin_expenses')->where('id', $l->matched_to_id)->first();
                    if ($e && $e->source === 'BANK') { $rev('EXPENSE', (int) $e->id); DB::table('fin_expenses')->where('id', $e->id)->update(['f_active' => 0, 'fk_id_bank_line' => null]); }
                    else DB::table('fin_expenses')->where('id', $l->matched_to_id)->update(['fk_id_bank_line' => null]);
                    break;
                case 'CATEGORY': $rev('BANK_LINE', (int) $l->id); break;
                case 'PAYOUT': SettlementService::unpaid((int) $l->matched_to_id); break;
                case 'TRANSFER':
                    $j = DB::table('gl_journals')->where('id', $l->matched_to_id)->first();
                    if ($j && ! $j->reversed_by_id) Ledger::reverse((int) $j->id, null, 'Transfer match undone');
                    DB::table('fin_bank_transactions')->where('matched_to_type', 'TRANSFER')->where('matched_to_id', $l->matched_to_id)->where('id', '<>', $l->id)
                        ->update(['matched_to_type' => null, 'matched_to_id' => null, 'match_status' => 'UNMATCHED', 'match_rule' => null, 'category_code' => null]);
                    break;
            }
            DB::table('fin_bank_transactions')->where('id', $l->id)->update(['matched_to_type' => null, 'matched_to_id' => null, 'match_status' => 'UNMATCHED', 'match_rule' => null, 'category_code' => null, 'matched_by' => null, 'matched_at' => null]);
            EventBus::fire('BANK_LINE_UNMATCHED', 'fin_bank_transactions', $l->id, [], $ent);
            Audit::log('fin_bank_transactions', $l->id, 'UPDATE', ['unmatched_from' => $l->matched_to_type], null, $ent);
        });
    }

    // ───────────── expenses ─────────────

    /**
     * An expense entered by hand (before the bank line arrives). Over the limit (setting APPROVAL_LIMIT_EXPENSE, base currency) and not entered by the Owner:
     * it waits for a second person and books nothing until approved.
     */
    public static function addExpense(int $ent, array $d): array
    {
        return DB::transaction(function () use ($ent, $d) {
            $bank = self::bank($ent, (int) $d['bank']); $amt = round((float) $d['amount'], 2);
            if ($amt <= 0) throw new \InvalidArgumentException('The amount must be more than zero.');
            $cat = DB::table('fin_categories')->where('code', $d['category'])->where('f_active', 1)->whereIn('direction', ['OUT', 'BOTH'])->first() ?? throw new \InvalidArgumentException('Choose a money-out category.');
            if (isset(self::NOT_BY_HAND[$cat->code])) throw new \InvalidArgumentException(self::NOT_BY_HAND[$cat->code]);
            $date = $d['date'] ?? date('Y-m-d'); $cur = $bank->currency ?: Fx::base($ent); $rate = Fx::rate($cur, $date, $ent); $base = round($amt * $rate, 2);
            $wait = Approvals::needed($ent, 'EXPENSE', $base, 250);
            $id = DB::table('fin_expenses')->insertGetId(['fk_id_ent' => $ent, 'd_expense' => $date, 'category_code' => $cat->code, 'fk_id_branch' => $d['branch'] ?? null, 'fk_id_payee' => $d['party'] ?? null, 'amount' => $amt,
                'fk_id_currency' => self::currencyId($cur, $ent), 'exchange_rate' => $rate, 'amount_gbp' => $base, 'vat_amount' => $d['vat_amount'] ?? null, 'fk_id_paid_from' => $bank->id, 'source' => 'MANUAL',
                'note' => $d['note'] ?? null, 'f_active' => $wait ? 0 : 1, 'created_by' => Context::userId(), 'created_at' => now()]);
            Audit::log('fin_expenses', $id, 'INSERT', ['category' => $cat->code, 'amount' => $amt, 'currency' => $cur], null, $ent);
            if ($wait) { $a = Approvals::request($ent, 'EXPENSE', 'fin_expenses', $id, $base, ['category' => $cat->name, 'amount' => $amt, 'currency' => $cur, 'note' => $d['note'] ?? null], "Approve expense: {$cat->name} " . number_format($amt, 2) . " $cur"); return ['id' => $id, 'status' => 'WAITING', 'approval' => $a]; }
            self::postExpense($id);
            return ['id' => $id, 'status' => 'BOOKED'];
        });
    }

    public static function postExpense(int $id): int
    {
        $e = DB::table('fin_expenses')->where('id', $id)->first(); $bank = DB::table('fin_bank_accounts')->where('id', $e->fk_id_paid_from)->first();
        $cat = DB::table('fin_categories')->where('code', $e->category_code)->first(); $acc = self::accountFor($e->category_code); $sub = DB::table('gl_accounts')->where('code', $acc)->value('sub_ledger');
        $ref = $sub === 'SHOP' ? ($e->fk_id_branch ?: throw new \InvalidArgumentException('Choose the shop for this category.')) : ($sub === 'PARTY' ? $e->fk_id_payee : null);
        return Ledger::post((int) $e->fk_id_ent, $e->d_expense, 'EXPENSE', $id, [['account' => $acc, 'debit' => (float) $e->amount_gbp, 'ref_id' => $ref], ['account' => '1000', 'credit' => (float) $e->amount_gbp, 'ref_id' => (int) $bank->id, 'amount_cur' => -1 * (float) $e->amount]],
            ['memo' => ($cat->name ?? $e->category_code) . ($e->note ? ' · ' . mb_substr($e->note, 0, 80) : ''), 'currency' => $bank->currency ?: 'GBP', 'fx_rate' => $e->exchange_rate ?: 1, 'allow_no_ref' => $sub === 'PARTY' && ! $ref]);
    }

    /** Take a manual expense back (wrong entry). Not possible once its bank line is matched – undo the match first. */
    public static function cancelExpense(int $ent, int $id, string $reason): void
    {
        DB::transaction(function () use ($ent, $id, $reason) {
            $e = DB::table('fin_expenses')->where('id', $id)->where('fk_id_ent', $ent)->lockForUpdate()->first() ?? throw new \InvalidArgumentException('Expense not found.');
            if (trim($reason) === '') throw new \InvalidArgumentException('Give the reason.');
            if ($e->fk_id_bank_line) throw new \InvalidArgumentException('This expense is matched to a bank line. Undo the match on the bank screen first.');
            foreach (DB::table('gl_journals')->where('source_type', 'EXPENSE')->where('source_id', $id)->whereNull('reverses_id')->whereNull('reversed_by_id')->pluck('id') as $j) Ledger::reverse((int) $j, null, 'Expense cancelled: ' . $reason);
            DB::table('fin_expenses')->where('id', $id)->update(['f_active' => 0, 'updated_by' => Context::userId(), 'updated_at' => now()]);
            DB::table('sys_approvals')->where('entity_table', 'fin_expenses')->where('entity_id', $id)->where('status', 'PENDING')->update(['status' => 'CANCELLED', 'reason' => $reason]);
            Audit::log('fin_expenses', $id, 'DELETE', [], $reason, $ent);
        });
    }

    public static function currencyId(string $code, int $ent): int
    {
        return (int) (DB::table('cat_currency')->where('currency_code', $code)->orderByRaw('fk_id_ent = ? DESC', [$ent])->value('id')
            ?? DB::table('cat_currency')->insertGetId(['fk_id_ent' => $ent, 'currency_code' => $code, 'currency_name' => $code, 'f_active' => 1, 'created_at' => now()]));
    }

    /** Nightly: one task per bank line that stays unmatched longer than BANK_UNMATCHED_DAYS. */
    public static function unmatchedTasks(int $ent): int
    {
        $days = (int) Settings::get('BANK_UNMATCHED_DAYS', $ent, 7); $n = 0;
        foreach (DB::table('fin_bank_transactions as t')->join('fin_bank_accounts as b', 'b.id', '=', 't.bank_account_id')->where('t.company_id', $ent)->where('t.match_status', 'UNMATCHED')->where('t.txn_date', '<', date('Y-m-d', strtotime("-$days day")))->get(['t.id', 't.txn_date', 't.amount', 't.amount_gbp', 't.description', 'b.account_label']) as $l) {
            TaskEngine::open('FIN_BANK_UNMATCHED', [$l->id], "Bank line not matched: {$l->account_label} {$l->txn_date} " . number_format($l->amount, 2) . ' ' . mb_substr((string) $l->description, 0, 60), ['ent' => $ent, 'module' => 'fin_bank_transactions', 'record_id' => $l->id, 'amount' => abs((float) $l->amount_gbp), 'fix_url' => '#/bank']);
            $n++;
        }
        return $n;
    }
}
