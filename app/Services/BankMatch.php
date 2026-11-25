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

    /**
     * R-FIN-27 the old rule set, finished: M3W partner withdrawal (OUT, a PAID withdrawal paid from this account with no bank line yet, amount equal ±0.01,
     * paid within match_withdrawal_days, oldest first – the withdrawal was booked when it was approved, the line only links to it); MC capital call
     * (IN, an ACCEPTED answer of an OPEN call of this company, |amount − accepted| ≤ payout_tolerance, no date window – the capital is booked from the line
     * and the answer becomes RECEIVED); M4 inter-company (either sign, an UNMATCHED line of another own company with the opposite amount ±0.01 within
     * match_intercompany_days – both lines are booked INTERCOMPANY_OUT / _IN). M5 reserve release has no own rule: a released reserve is part of the next
     * settlement payable, so the payout match (M1) takes it.
     */
    public static function withdrawal(int $ent, object $l): bool
    {
        $amt = round((float) $l->amount, 2); if ($amt >= 0) return false; $days = FinanceSettings::int($ent, 'match_withdrawal_days');
        $w = DB::table('fin_withdrawals')->where('company_id', $ent)->where('status', 'PAID')->whereNull('bank_txn_id')->where(fn ($q) => $q->whereNull('fk_id_pay_to_bank')->orWhere('fk_id_pay_to_bank', $l->bank_account_id))
            ->whereRaw('ABS(amount - ?) <= 0.01', [abs($amt)])->whereBetween(DB::raw('DATE(COALESCE(paid_at, approved_at))'), [date('Y-m-d', strtotime("{$l->txn_date} -$days day")), date('Y-m-d', strtotime("{$l->txn_date} +$days day"))])
            ->orderBy('approved_at')->orderBy('id')->first();
        if (! $w) return false;
        DB::table('fin_withdrawals')->where('id', $w->id)->update(['bank_txn_id' => (string) $l->id]);
        self::link($l, 'WITHDRAWAL', (string) $w->id, 'M3W', 'AUTO', 'PARTNER_WITHDRAWAL');
        return true;
    }

    public static function capitalCall(int $ent, object $l): bool
    {
        $amt = round((float) $l->amount, 2); if ($amt <= 0) return false; $tol = (float) FinanceSettings::num($ent, 'payout_tolerance');
        $c = DB::table('fin_capital_call_responses as r')->join('fin_capital_calls as c', 'c.id', '=', 'r.call_id')->where('c.company_id', $ent)->where('c.status', 'OPEN')->where('r.status', 'ACCEPTED')
            ->whereRaw('ABS(r.amount_accepted - ?) <= ?', [$amt, $tol + 0.0001])->get(['r.id', 'r.investor_id', 'r.call_id', 'c.reason']);
        if ($c->count() !== 1) return false;
        $r = $c[0];
        DB::transaction(function () use ($ent, $l, $amt, $r) {
            $lid = PartnerService::capitalIn($ent, (int) $r->investor_id, $amt, (int) $l->bank_account_id, (string) $l->txn_date, 'Capital call: ' . $r->reason);
            DB::table('fin_capital_call_responses')->where('id', $r->id)->update(['status' => 'RECEIVED', 'amount_received' => DB::raw('amount_received + ' . $amt), 'bank_txn_id' => (string) $l->id, 'responded_at' => now()]);
            if (! DB::table('fin_capital_call_responses')->where('call_id', $r->call_id)->whereIn('status', ['SENT', 'ACCEPTED'])->exists()) DB::table('fin_capital_calls')->where('id', $r->call_id)->update(['status' => 'CLOSED']);
            self::link($l, 'CAPITAL_CALL', $r->id . ':' . $lid, 'MC', 'AUTO', 'PARTNER_CAPITAL');
        });
        return true;
    }

    public static function undoCapitalCall(string $ids): void
    {
        [$rid, $lid] = array_map('intval', explode(':', $ids . ':0'));
        foreach (DB::table('gl_journals')->where('source_type', 'PARTNER_CAPITAL')->where('source_id', $lid)->whereNull('reverses_id')->whereNull('reversed_by_id')->pluck('id') as $j) Ledger::reverse((int) $j, null, 'Bank match undone');
        $amt = (float) DB::table('fin_investor_ledger')->where('id', $lid)->value('amount');
        DB::table('fin_investor_ledger')->where('id', $lid)->delete();
        $r = DB::table('fin_capital_call_responses')->where('id', $rid)->first();
        if ($r) { DB::table('fin_capital_call_responses')->where('id', $rid)->update(['status' => 'ACCEPTED', 'amount_received' => max(0, round((float) $r->amount_received - $amt, 2)), 'bank_txn_id' => null]); DB::table('fin_capital_calls')->where('id', $r->call_id)->update(['status' => 'OPEN']); }
    }

    public static function intercompany(int $ent, object $l): bool
    {
        $amt = round((float) $l->amount, 2); if ($amt == 0.0) return false; $days = FinanceSettings::int($ent, 'match_intercompany_days');
        $o = DB::table('fin_bank_transactions')->where('company_id', '<>', $ent)->where('match_status', 'UNMATCHED')->whereRaw('ABS(amount + ?) <= 0.01', [$amt])
            ->whereBetween('txn_date', [date('Y-m-d', strtotime("{$l->txn_date} -$days day")), date('Y-m-d', strtotime("{$l->txn_date} +$days day"))])->get();
        if ($o->count() !== 1) return false;
        $x = $o[0];
        foreach ([[$ent, $l], [(int) $x->company_id, $x]] as [$e, $line])
            MoneyService::categorise($e, (int) $line->id, ['category' => (float) $line->amount > 0 ? 'INTERCOMPANY_IN' : 'INTERCOMPANY_OUT', 'note' => 'Inter-company transfer (M4)']);
        DB::table('fin_bank_transactions')->whereIn('id', [$l->id, $x->id])->update(['match_rule' => 'M4', 'match_status' => 'AUTO']);
        return true;
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

    /**
     * R-FIN-28 by hand to one target, with the direction guard: INVOICE (money in = an open invoice our company issued, money out = one addressed to us; the line
     * may not be more than the balance; the invoice payment carries the bank line), LOAN (money out repays a TAKEN loan, money in is a repayment of a GIVEN loan;
     * principal = line − interest; the loan payment is booked by FinanceExtras::loanPay from the line's account). Undo takes it all back.
     */
    public static function matchTo(int $ent, int $lineId, string $kind, int $id, array $d = []): array
    {
        return DB::transaction(function () use ($ent, $lineId, $kind, $id, $d) {
            $l = DB::table('fin_bank_transactions')->where('id', $lineId)->where('company_id', $ent)->lockForUpdate()->first() ?? throw new \InvalidArgumentException('Bank line not found.');
            if ($l->match_status !== 'UNMATCHED') throw new \InvalidArgumentException('This line is already matched. Undo the match first.');
            $amt = round((float) $l->amount, 2); $abs = abs($amt); $in = $amt > 0;
            if ($kind === 'INVOICE') {
                $i = DB::table('t_invoices')->where('id', $id)->where('fk_id_ent', $ent)->lockForUpdate()->first() ?? throw new \InvalidArgumentException('Invoice not found.');
                if (! in_array($i->status, ['UNPAID', 'PART_PAID'], true) || $i->invoice_type === 'CREDIT_NOTE') throw new \InvalidArgumentException("Invoice {$i->invoice_no} is not open.");
                $ours = DB::table('t_inv_parties')->where('fk_id_ent', $ent)->where('f_is_sbs', 1)->pluck('id')->map(fn ($x) => (int) $x)->all();
                $weIssued = in_array((int) $i->fk_issuer_party, $ours, true); $toUs = in_array((int) $i->fk_recipient_party, $ours, true);
                if ($in && ! $weIssued) throw new \InvalidArgumentException("Money in can only pay an invoice our company issued – {$i->invoice_no} is not ours to receive.");
                if (! $in && ! $toUs) throw new \InvalidArgumentException("Money out can only pay an invoice addressed to our company – {$i->invoice_no} is not ours to pay.");
                if ($abs > self::bal($i) + 0.01) throw new \InvalidArgumentException('The bank line (' . number_format($abs, 2) . ') is more than the invoice balance (' . number_format(self::bal($i), 2) . ').');
                DB::table('t_invoice_payments')->insert(['fk_id_invoice' => $i->id, 'd_payment' => $l->txn_date, 'amount' => $abs, 'method' => 'Bank transfer', 'received_into' => mb_substr('Bank line #' . $l->id, 0, 200),
                    'reference' => mb_substr((string) ($l->reference_no ?: $l->description), 0, 200), 'notes' => 'Matched by hand from the bank statement', 'proof_file' => '', 'f_deleted' => 0, 'fk_id_bank_line' => $l->id, 'created_by' => (int) Context::userId(), 'created_at' => now()]);
                InvestorDocs::refresh((int) $i->id);
                self::link($l, 'INVOICE', (string) $i->id, 'BY_HAND', 'MANUAL', $in ? 'INVOICE_RECEIPT' : 'INVOICE_PAYMENT');
                Audit::log('fin_bank_transactions', (int) $l->id, 'UPDATE', ['matched' => "invoice {$i->invoice_no}"], 'by hand', $ent);
                return ['message' => "Matched to invoice {$i->invoice_no}."];
            }
            if ($kind === 'LOAN') {
                $loan = DB::table('fin_loans')->where('id', $id)->where('company_id', $ent)->first() ?? throw new \InvalidArgumentException('Loan not found.');
                if ($in === ($loan->direction === 'TAKEN')) throw new \InvalidArgumentException($loan->direction === 'TAKEN' ? 'A loan we took is repaid with money going out.' : 'A loan we gave is repaid with money coming in.');
                $int = round(max(0, (float) ($d['interest'] ?? 0)), 2); if ($int > $abs) throw new \InvalidArgumentException('The interest is more than the bank line.');
                $base = abs((float) $l->amount_gbp) ?: $abs; $rate = $abs > 0 ? $base / $abs : 1;
                $r = FinanceExtras::loanPay($ent, (int) $loan->id, ['principal' => round(($abs - $int) * $rate, 2), 'interest' => round($int * $rate, 2), 'date' => $l->txn_date, 'bank' => (int) $l->bank_account_id, 'note' => 'Bank line #' . $l->id]);
                $pid = (int) DB::table('fin_loan_payments')->where('fk_id_loan', $loan->id)->orderByDesc('id')->value('id');
                self::link($l, 'LOAN', (string) $pid, 'BY_HAND', 'MANUAL', $loan->direction === 'TAKEN' ? 'LOAN_REPAYMENT' : 'LOAN_RECEIPT');
                return ['message' => "Matched to the loan {$loan->counterparty}. Balance left " . number_format((float) $r['balance'], 2) . '.'];
            }
            if ($kind === 'CLAIM') {      /* a supplier claim settled as REFUND: the supplier pays us back – Dr bank 1000 / Cr supplier 1300 */
                if (! $in) throw new \InvalidArgumentException('A supplier refund comes in as money in.');
                $g = DB::table('t_src_grn')->where('id', $id)->where('fk_id_ent', $ent)->first() ?? throw new \InvalidArgumentException('Claim not found.');
                if ($g->claim_status !== 'SETTLED' || $g->claim_settled_as !== 'REFUND') throw new \InvalidArgumentException('Only a claim settled as a refund is paid back through the bank.');
                $base = abs((float) $l->amount_gbp) ?: $abs; $got = (float) DB::table('fin_bank_transactions')->where('matched_to_type', 'CLAIM')->where('matched_to_id', (string) $g->id)->sum('amount_gbp');
                $left = round((float) $g->claim_base_amount - $got, 2);
                if ($base > $left + 0.01) throw new \InvalidArgumentException('The bank line (' . number_format($base, 2) . ') is more than the refund still due (' . number_format($left, 2) . ').');
                $po = SupplierClaims::poOf($g); $sup = (int) ($po->fk_id_supplier ?? 0);
                Ledger::post($ent, $l->txn_date, 'CLAIM_REFUND', (int) $l->id, [['account' => '1000', 'debit' => $base, 'ref_id' => (int) $l->bank_account_id, 'amount_cur' => (float) $l->amount], ['account' => '1300', 'credit' => $base, 'ref_id' => $sup ?: null]], ['memo' => 'Supplier refund ' . ($g->grn_number ?? $g->id), 'currency' => $l->currency, 'allow_no_ref' => ! $sup]);
                self::link($l, 'CLAIM', (string) $g->id, 'BY_HAND', 'MANUAL', 'SUPPLIER_REFUND');
                return ['message' => 'Matched to the supplier refund of ' . ($g->grn_number ?? 'GRN ' . $g->id) . '.'];
            }
            if ($kind === 'REFUND') {      /* R-A_SALES-86 / R-FIN-70: a refund paid outside the marketplace (bank / payment provider) – the proposal becomes Outside and is booked: Dr refunds 5400 / Cr shop 1100 (refund), then Dr 1100 / Cr bank 1000 (paid from the bank, not by the marketplace) */
                if ($in) throw new \InvalidArgumentException('A refund paid to a buyer is money going out.');
                $p = DB::table('t_sale_refund_proposals')->where('id', $id)->lockForUpdate()->first();
                $s = $p && $p->fk_id_sale ? DB::table('t_sales')->where('id', $p->fk_id_sale)->where('fk_id_ent', $ent)->first() : null;
                if (! $p || ! $s) throw new \InvalidArgumentException('Refund proposal not found.');
                if ($p->status !== 'PROPOSED') throw new \InvalidArgumentException('This refund proposal was already decided.');
                if ($s->status !== 'Confirmed') throw new \InvalidArgumentException("Order {$s->order_no} is not confirmed yet – confirm its month first, then match the bank line.");
                $base = abs((float) $l->amount_gbp) ?: $abs;
                DB::table('t_sale_refund_proposals')->where('id', $p->id)->update(['refund_kind' => 'OUTSIDE', 'amount' => $base]);
                SettlementService::refund($ent, (int) $s->id, $base, 'Refund paid outside the marketplace – bank line #' . $l->id, (int) $p->id);
                Ledger::post($ent, $l->txn_date, 'REFUND_OUTSIDE', (int) $l->id, [['account' => '1100', 'debit' => $base, 'ref_id' => (int) $s->fk_id_branch], ['account' => '1000', 'credit' => $base, 'ref_id' => (int) $l->bank_account_id, 'amount_cur' => (float) $l->amount]], ['memo' => "Refund of order {$s->order_no} paid from the bank"]);
                DB::table('t_sale_refund_proposals')->where('id', $p->id)->update(['status' => 'ACCEPTED', 'decided_by' => Context::userId(), 'decided_at' => now()]);
                self::link($l, 'REFUND', (string) $p->id, 'BY_HAND', 'MANUAL', 'MP_REFUND_OUTSIDE');
                Audit::log('fin_bank_transactions', (int) $l->id, 'UPDATE', ['matched' => "refund of order {$s->order_no}", 'amount' => $base], 'by hand', $ent);
                return ['message' => "Matched to the refund of order {$s->order_no} – booked as a refund paid outside the marketplace."];
            }
            throw new \InvalidArgumentException('Choose an invoice, a loan, a supplier claim or a refund.');
        });
    }

    /** Undo a LOAN match: the loan payment's journal is reversed, the balance goes back, the payment row is removed. */
    public static function undoLoan(int $paymentId): void
    {
        $p = DB::table('fin_loan_payments')->where('id', $paymentId)->first(); if (! $p) return;
        if ($p->journal_id && ($j = DB::table('gl_journals')->where('id', $p->journal_id)->first()) && ! $j->reversed_by_id) Ledger::reverse((int) $j->id, null, 'Bank match undone');
        $loan = DB::table('fin_loans')->where('id', $p->fk_id_loan)->first(); $bal = round((float) $loan->balance + (float) $p->principal, 2);
        DB::table('fin_loans')->where('id', $loan->id)->update(['balance' => $bal, 'is_active' => $bal > 0 ? 1 : 0]);
        DB::table('fin_loan_payments')->where('id', $p->id)->delete();
        Audit::log('fin_loans', (int) $loan->id, 'UPDATE', ['payment undone' => (float) $p->principal, 'balance' => [(float) $loan->balance, $bal]]);
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
