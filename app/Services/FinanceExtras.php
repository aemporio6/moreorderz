<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * VAT return (9 boxes, output VAT per the choice OUTPUT_VAT_MODE of each shop), loans with payments, budgets against actual, capital calls,
 * and the partner's answer on a statement (Accept / Ask a question). Everything that moves money posts to the double-entry ledger.
 */
class FinanceExtras
{
    private static function m(float $v): float { return round($v, 2); }

    private static function bank(int $ent, int $id): object
    {
        return DB::table('fin_bank_accounts')->where('id', $id)->where('company_id', $ent)->first() ?? throw new \InvalidArgumentException('Choose a bank account of this company.');
    }

    /** A booking date inside an open month: the given date, or today when that month is locked. */
    private static function openDate(int $ent, string $date): string
    {
        return Ledger::periodLocked($ent, substr($date, 0, 7)) ? date('Y-m-d') : $date;
    }

    // ===================== VAT return =====================
    /** Sums of an account's lines in a period, per shop when $byShop. */
    private static function moves(int $ent, string $code, string $from, string $to, bool $byShop = false, array $skipSources = ['VAT_RETURN', 'VAT_PAYMENT']): array
    {
        $q = DB::table('gl_lines as l')->join('gl_journals as j', 'j.id', '=', 'l.journal_id')->join('gl_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('j.fk_id_ent', $ent)->where('a.code', $code)->whereBetween('j.journal_date', [$from, $to])->whereNotIn('j.source_type', $skipSources);
        if ($byShop) return $q->groupBy('l.ref_id')->selectRaw('l.ref_id shop, SUM(l.debit) dr, SUM(l.credit) cr')->get()->keyBy('shop')->map(fn ($x) => ['dr' => (float) $x->dr, 'cr' => (float) $x->cr])->all();
        $r = $q->selectRaw('COALESCE(SUM(l.debit),0) dr, COALESCE(SUM(l.credit),0) cr')->first();
        return ['dr' => (float) $r->dr, 'cr' => (float) $r->cr];
    }

    /** Work out the 9 boxes from the books. Nothing is saved. */
    public static function vatCalc(int $ent, string $from, string $to): array
    {
        if (Choices::get('VAT_REGISTERED', $ent) !== '1') throw new \InvalidArgumentException('The company is set as not registered for VAT (Settings › Choices). A VAT return is only for a registered company.');
        $rate = (float) Settings::get('VAT_RATE', $ent, 20); $shops = DB::table('t_branches')->where('fk_id_ent', $ent)->pluck('shop_name', 'id');
        $sales = self::moves($ent, '4000', $from, $to, true); $refunds = self::moves($ent, '5400', $from, $to, true);
        $out = 0.0; $salesEx = 0.0; $rows = [];
        foreach ($shops as $id => $name) {
            $gross = ($sales[$id]['cr'] ?? 0) - ($sales[$id]['dr'] ?? 0) - (($refunds[$id]['dr'] ?? 0) - ($refunds[$id]['cr'] ?? 0));
            if ($gross == 0.0) continue;
            $mode = Choices::get('OUTPUT_VAT_MODE', $ent, (int) $id);
            $vat = $mode === 'SELLER' ? self::m($gross * $rate / (100 + $rate)) : 0.0;
            $out += $vat; $salesEx += $gross - $vat;
            $rows[] = ['shop' => $name, 'mode' => $mode, 'sales_less_refunds' => self::m($gross), 'output_vat' => $vat];
        }
        $in = self::moves($ent, '1400', $from, $to); $input = self::m($in['dr'] - $in['cr']);
        // purchases without VAT: stock bought, goods on the way, costs (not cost of goods sold, not partner shares)
        $buy = 0.0; foreach (['1200' => ['STOCK_RECEIVE', 'GRN_POST', 'LANDED_COST', 'QUICK_BUY'], '1210' => null, '5100' => null, '5200' => null, '5300' => null, '6000' => null, '6200' => null] as $code => $only) {
            $q = DB::table('gl_lines as l')->join('gl_journals as j', 'j.id', '=', 'l.journal_id')->join('gl_accounts as a', 'a.id', '=', 'l.account_id')->where('j.fk_id_ent', $ent)->where('a.code', $code)->whereBetween('j.journal_date', [$from, $to]);
            if ($only) $q->whereIn('j.source_type', $only);
            $buy += (float) $q->sum('l.debit') - ($only ? 0 : (float) $q->sum('l.credit'));
        }
        $b = ['box1' => self::m($out), 'box2' => 0.0, 'box4' => $input, 'box6' => round($salesEx), 'box7' => round(max(0, $buy)), 'box8' => 0.0, 'box9' => 0.0];
        $b['box3'] = self::m($b['box1'] + $b['box2']); $b['box5'] = self::m($b['box3'] - $b['box4']);
        return $b + ['rate' => $rate, 'shops' => $rows, 'from' => $from, 'to' => $to,
            'note' => 'Worked out from the books: box 1 from confirmed sales less refunds of the shops whose VAT the company pays (choice per shop), box 4 from VAT to claim back (fees, expenses, import VAT), boxes 6 / 7 without VAT, whole pounds. Check it before you file it with HMRC; the tool does not file it.'];
    }

    public static function vatPeriods(int $ent): array
    {
        $start = max(1, min(12, (int) Settings::get('VAT_QUARTER_START_MONTH', $ent, 1))); $out = [];
        for ($i = 0; $i < 8; $i++) {
            $m = (int) date('n') - (((int) date('n') - $start + 12) % 3) - 3 * $i; $y = (int) date('Y'); while ($m < 1) { $m += 12; $y--; }
            $from = sprintf('%04d-%02d-01', $y, $m); $to = date('Y-m-t', strtotime("$from +2 months"));
            $out[] = ['from' => $from, 'to' => $to, 'saved' => DB::table('mo_vat_returns')->where(['fk_id_ent' => $ent, 'period_from' => $from, 'period_to' => $to])->first()];
        }
        return $out;
    }

    /** Book the return: Dr Sales (output VAT, per shop) / Cr VAT payable; Dr VAT payable / Cr VAT to claim back (input VAT). Once per period. */
    public static function vatBook(int $ent, string $from, string $to, ?string $reference = null): array
    {
        if (! in_array(Context::role(), ['OWNER', 'FINANCE', 'ACCOUNTANT'], true)) throw new \InvalidArgumentException('Only the Owner, Finance or the Accountant books a VAT return.');
        if ($to >= date('Y-m-d')) throw new \InvalidArgumentException('The VAT period has not ended yet.');
        if (DB::table('mo_vat_returns')->where(['fk_id_ent' => $ent, 'period_from' => $from, 'period_to' => $to])->whereIn('status', ['BOOKED', 'PAID'])->exists()) throw new \InvalidArgumentException('This VAT return is booked already.');
        $c = self::vatCalc($ent, $from, $to);
        return DB::transaction(function () use ($ent, $from, $to, $reference, $c) {
            $date = self::openDate($ent, $to); $lines = [];
            $shopIds = DB::table('t_branches')->where('fk_id_ent', $ent)->pluck('id', 'shop_name');
            foreach ($c['shops'] as $s) if ($s['output_vat'] > 0) $lines[] = ['account' => '4000', 'debit' => $s['output_vat'], 'ref_id' => (int) $shopIds[$s['shop']], 'memo' => 'Output VAT ' . $s['shop']];
            if ($c['box1'] > 0) $lines[] = ['account' => '2200', 'credit' => $c['box1']];
            if ($c['box4'] > 0) { $lines[] = ['account' => '2200', 'debit' => $c['box4']]; $lines[] = ['account' => '1400', 'credit' => $c['box4']]; }
            $jid = count($lines) >= 2 ? Ledger::post($ent, $date, 'VAT_RETURN', null, $lines, ['memo' => "VAT return $from – $to"]) : null;
            $row = ['box1' => $c['box1'], 'box2' => $c['box2'], 'box3' => $c['box3'], 'box4' => $c['box4'], 'box5' => $c['box5'], 'box6' => $c['box6'], 'box7' => $c['box7'], 'box8' => 0, 'box9' => 0,
                'detail_json' => json_encode($c['shops']), 'status' => 'BOOKED', 'journal_id' => $jid, 'reference' => $reference ? mb_substr($reference, 0, 60) : null, 'booked_by' => Context::userId(), 'booked_at' => now()];
            DB::table('mo_vat_returns')->updateOrInsert(['fk_id_ent' => $ent, 'period_from' => $from, 'period_to' => $to], $row);
            $id = (int) DB::table('mo_vat_returns')->where(['fk_id_ent' => $ent, 'period_from' => $from, 'period_to' => $to])->value('id');
            Audit::log('mo_vat_returns', $id, 'INSERT', ['box5' => $c['box5'], 'journal' => $jid], null, $ent);
            return ['id' => $id, 'box5' => $c['box5'], 'journal' => $jid];
        });
    }

    /** Pay HMRC (box 5 > 0) or receive the refund (box 5 < 0) from / into a bank account. */
    public static function vatPay(int $ent, int $id, int $bankId, string $date): array
    {
        if (! in_array(Context::role(), ['OWNER', 'FINANCE'], true)) throw new \InvalidArgumentException('Only the Owner or Finance pays the VAT.');
        $r = DB::table('mo_vat_returns')->where('id', $id)->where('fk_id_ent', $ent)->first() ?? throw new \InvalidArgumentException('VAT return not found.');
        if ($r->status !== 'BOOKED') throw new \InvalidArgumentException($r->status === 'PAID' ? 'This VAT return is paid already.' : 'Book the VAT return first.');
        $bank = self::bank($ent, $bankId); $amt = (float) $r->box5; if ($amt == 0.0) throw new \InvalidArgumentException('Nothing to pay or receive.');
        $date = self::openDate($ent, $date);
        $lines = $amt > 0 ? [['account' => '2200', 'debit' => $amt], ['account' => '1000', 'credit' => $amt, 'ref_id' => (int) $bank->id]] : [['account' => '1000', 'debit' => -$amt, 'ref_id' => (int) $bank->id], ['account' => '2200', 'credit' => -$amt]];
        $jid = Ledger::post($ent, $date, 'VAT_PAYMENT', $id, $lines, ['memo' => ($amt > 0 ? 'VAT paid to HMRC ' : 'VAT refund from HMRC ') . $r->period_from . ' – ' . $r->period_to]);
        DB::table('mo_vat_returns')->where('id', $id)->update(['status' => 'PAID', 'pay_journal_id' => $jid, 'paid_at' => now()]);
        Audit::log('mo_vat_returns', $id, 'UPDATE', ['status' => ['BOOKED', 'PAID'], 'amount' => $amt], null, $ent);
        return ['journal' => $jid];
    }

    // ===================== loans =====================
    /** A new loan: TAKEN = money in (Dr bank / Cr 2400 Loans); GIVEN = money out (Dr 1150 Other receivables / Cr bank). */
    public static function loanNew(int $ent, array $d): int
    {
        if (! in_array(Context::role(), ['OWNER', 'FINANCE'], true)) throw new \InvalidArgumentException('Only the Owner or Finance records a loan.');
        $amt = round((float) ($d['amount'] ?? 0), 2); if ($amt <= 0) throw new \InvalidArgumentException('The amount must be more than zero.');
        $dir = ($d['direction'] ?? 'TAKEN') === 'GIVEN' ? 'GIVEN' : 'TAKEN'; $bank = self::bank($ent, (int) ($d['bank'] ?? 0)); $date = (string) ($d['date'] ?? date('Y-m-d'));
        $who = trim((string) ($d['counterparty'] ?? '')); if ($who === '') throw new \InvalidArgumentException('Who is the lender or borrower?');
        $party = (int) ($d['party'] ?? 0) ?: null; $base = Fx::toBase($amt, $bank->currency ?: Fx::base($ent), $date, $ent);
        return DB::transaction(function () use ($ent, $d, $amt, $dir, $bank, $date, $who, $party, $base) {
            $id = DB::table('fin_loans')->insertGetId(['company_id' => $ent, 'counterparty' => mb_substr($who, 0, 120), 'direction' => $dir, 'amount' => $amt, 'currency' => $bank->currency ?: Fx::base($ent), 'start_date' => $date, 'balance' => $base,
                'note' => mb_substr((string) ($d['note'] ?? ''), 0, 255) ?: null, 'is_active' => 1, 'interest_rate' => ($d['interest_rate'] ?? '') === '' ? null : (float) $d['interest_rate'], 'instalment' => ($d['instalment'] ?? '') === '' ? null : (float) $d['instalment'],
                'next_due_date' => ($d['next_due_date'] ?? '') ?: null, 'frequency' => in_array($d['frequency'] ?? '', ['MONTHLY', 'QUARTERLY', 'YEARLY', 'ONE_OFF'], true) ? $d['frequency'] : 'MONTHLY', 'fk_id_bank_account' => $bank->id, 'fk_id_account' => $party]);
            $ref = $party ?: $id;
            $lines = $dir === 'TAKEN' ? [['account' => '1000', 'debit' => $base, 'ref_id' => (int) $bank->id, 'amount_cur' => $amt], ['account' => '2400', 'credit' => $base, 'ref_id' => $ref]]
                : [['account' => '1150', 'debit' => $base, 'ref_id' => $ref], ['account' => '1000', 'credit' => $base, 'ref_id' => (int) $bank->id, 'amount_cur' => $amt]];
            $jid = Ledger::post($ent, self::openDate($ent, $date), 'LOAN_START', $id, $lines, ['memo' => ($dir === 'TAKEN' ? 'Loan from ' : 'Loan to ') . $who]);
            DB::table('fin_loans')->where('id', $id)->update(['journal_id' => $jid]);
            Audit::log('fin_loans', $id, 'INSERT', ['direction' => $dir, 'amount' => $amt], null, $ent);
            return $id;
        });
    }

    /** A repayment: principal lowers the balance; interest is a bank / finance cost (TAKEN) or other income (GIVEN). */
    public static function loanPay(int $ent, int $loanId, array $d): array
    {
        if (! in_array(Context::role(), ['OWNER', 'FINANCE'], true)) throw new \InvalidArgumentException('Only the Owner or Finance records a loan payment.');
        $l = DB::table('fin_loans')->where('id', $loanId)->where('company_id', $ent)->first() ?? throw new \InvalidArgumentException('Loan not found.');
        $p = round((float) ($d['principal'] ?? 0), 2); $i = round((float) ($d['interest'] ?? 0), 2); $date = (string) ($d['date'] ?? date('Y-m-d'));
        if ($p < 0 || $i < 0 || $p + $i <= 0) throw new \InvalidArgumentException('Enter the principal and / or the interest.');
        if ($p > (float) $l->balance + 0.005) throw new \InvalidArgumentException('The principal is more than what is left of the loan (' . number_format((float) $l->balance, 2) . ').');
        $bank = self::bank($ent, (int) ($d['bank'] ?? $l->fk_id_bank_account)); $ref = $l->fk_id_account ?: $l->id;
        return DB::transaction(function () use ($ent, $l, $p, $i, $date, $bank, $ref, $d) {
            $lines = $l->direction === 'TAKEN'
                ? array_merge($p > 0 ? [['account' => '2400', 'debit' => $p, 'ref_id' => $ref]] : [], $i > 0 ? [['account' => '6200', 'debit' => $i, 'memo' => 'Loan interest']] : [], [['account' => '1000', 'credit' => $p + $i, 'ref_id' => (int) $bank->id]])
                : array_merge([['account' => '1000', 'debit' => $p + $i, 'ref_id' => (int) $bank->id]], $p > 0 ? [['account' => '1150', 'credit' => $p, 'ref_id' => $ref]] : [], $i > 0 ? [['account' => '4100', 'credit' => $i, 'memo' => 'Loan interest received']] : []);
            $pid = DB::table('fin_loan_payments')->insertGetId(['fk_id_loan' => $l->id, 'pay_date' => $date, 'principal' => $p, 'interest' => $i, 'fk_id_bank_account' => $bank->id, 'note' => mb_substr((string) ($d['note'] ?? ''), 0, 255) ?: null, 'created_by' => Context::userId()]);
            $jid = Ledger::post($ent, self::openDate($ent, $date), 'LOAN_PAYMENT', $pid, $lines, ['memo' => 'Loan ' . $l->counterparty]);
            $bal = round((float) $l->balance - $p, 2);
            $next = $l->next_due_date && $l->frequency !== 'ONE_OFF' ? Subscriptions::next($l->next_due_date, $l->frequency) : null;
            DB::table('fin_loans')->where('id', $l->id)->update(['balance' => $bal, 'is_active' => $bal > 0 ? 1 : 0, 'next_due_date' => $bal > 0 ? $next : null]);
            DB::table('fin_loan_payments')->where('id', $pid)->update(['journal_id' => $jid]);
            TaskEngine::close('FIN_LOAN_DUE', [$l->id], 'Payment recorded');
            Audit::log('fin_loans', $l->id, 'UPDATE', ['paid_principal' => $p, 'interest' => $i, 'balance' => [(float) $l->balance, $bal]], null, $ent);
            return ['balance' => $bal, 'next_due_date' => $bal > 0 ? $next : null];
        });
    }

    public static function loansPage(int $ent): array
    {
        $loans = DB::table('fin_loans')->where('company_id', $ent)->orderByDesc('is_active')->orderBy('next_due_date')->get();
        foreach ($loans as $l) $l->payments = DB::table('fin_loan_payments')->where('fk_id_loan', $l->id)->orderByDesc('pay_date')->get(['pay_date', 'principal', 'interest', 'note']);
        return ['loans' => $loans, 'taken' => self::m($loans->where('direction', 'TAKEN')->where('is_active', 1)->sum('balance')), 'given' => self::m($loans->where('direction', 'GIVEN')->where('is_active', 1)->sum('balance')),
            'ledger_2400' => self::m(-Ledger::balance($ent, '2400')), 'banks' => DB::table('fin_bank_accounts')->where('company_id', $ent)->where('is_active', 1)->get(['id', DB::raw("CONCAT(bank_name, ' · ', COALESCE(account_label,'')) name"), 'currency'])];
    }

    /** Night: a task for every loan payment that is due. */
    public static function loanTasks(): int
    {
        $n = 0;
        foreach (DB::table('fin_loans')->where('is_active', 1)->whereNotNull('next_due_date')->where('next_due_date', '<=', date('Y-m-d', strtotime('+3 days')))->get() as $l)
            if (TaskEngine::open('FIN_LOAN_DUE', [$l->id], 'Loan ' . ($l->direction === 'TAKEN' ? 'payment to ' : 'payment from ') . $l->counterparty . ' due ' . $l->next_due_date . ($l->instalment ? ' (' . number_format((float) $l->instalment, 2) . ')' : ''), ['ent' => (int) $l->company_id, 'module' => 'fin_loans', 'record_id' => $l->id, 'fix_url' => '#/loans'])) $n++;
        return $n;
    }

    // ===================== budgets =====================
    /** Budget per cost category and month against what was booked (expenses of the category). */
    public static function budgets(int $ent, int $year): array
    {
        $cats = DB::table('fin_categories')->whereIn('direction', ['OUT', 'BOTH'])->where('f_active', 1)->orderBy('name')->get(['code', 'name']);
        $bud = DB::table('fin_budgets')->where('company_id', $ent)->where('year', $year)->get()->groupBy('category_code');
        $act = DB::table('fin_expenses')->where('fk_id_ent', $ent)->whereYear('d_expense', $year)->where('f_active', 1)->selectRaw('category_code, MONTH(d_expense) m, SUM(amount_gbp) s')->groupBy('category_code', 'm')->get()->groupBy('category_code');
        $rows = [];
        foreach ($cats as $c) {
            $b = array_fill(1, 12, 0.0); $a = array_fill(1, 12, 0.0);
            foreach ($bud[$c->code] ?? [] as $x) $b[(int) $x->month] = (float) $x->amount;
            foreach ($act[$c->code] ?? [] as $x) $a[(int) $x->m] = (float) $x->s;
            if (! array_sum($b) && ! array_sum($a)) continue;
            $rows[] = ['code' => $c->code, 'name' => $c->name, 'budget' => $b, 'actual' => $a, 'budget_total' => self::m(array_sum($b)), 'actual_total' => self::m(array_sum($a)), 'over' => array_sum($a) > array_sum($b) && array_sum($b) > 0];
        }
        return ['year' => $year, 'rows' => $rows, 'categories' => $cats];
    }

    /** Set the budget of a category: one amount for every month, or 12 amounts. */
    public static function budgetSet(int $ent, int $year, string $cat, array $amounts): void
    {
        if (! in_array(Context::role(), ['OWNER', 'FINANCE', 'MANAGER'], true)) throw new \InvalidArgumentException('Only the Owner, a Manager or Finance sets budgets.');
        if (! DB::table('fin_categories')->where('code', $cat)->exists()) throw new \InvalidArgumentException('Choose a cost category.');
        if (count($amounts) === 1) $amounts = array_fill(1, 12, (float) reset($amounts));
        foreach ($amounts as $m => $v) { $m = (int) $m; if ($m < 1 || $m > 12) continue;
            DB::table('fin_budgets')->updateOrInsert(['company_id' => $ent, 'year' => $year, 'month' => $m, 'category_code' => $cat], ['amount' => round((float) $v, 2)]); }
        Audit::log('fin_budgets', 0, 'UPDATE', ['year' => $year, 'category' => $cat, 'total' => array_sum(array_map('floatval', $amounts))], null, $ent);
    }

    // ===================== capital calls =====================
    /** Ask the partners for money: each is asked his share (agreements active today on the shop / pool, or the amounts given). WhatsApp / e-mail through the outbox. */
    public static function callNew(int $ent, array $d): int
    {
        if (! in_array(Context::role(), ['OWNER', 'FINANCE'], true)) throw new \InvalidArgumentException('Only the Owner or Finance makes a capital call.');
        $amt = round((float) ($d['amount'] ?? 0), 2); if ($amt <= 0) throw new \InvalidArgumentException('The amount must be more than zero.');
        $reason = trim((string) ($d['reason'] ?? '')); if (mb_strlen($reason) < 3) throw new \InvalidArgumentException('Say what the money is for.');
        $shop = (int) ($d['shop'] ?? 0) ?: null;
        $ags = collect(PartnerService::activeOn($ent, date('Y-m-d')))->filter(fn ($a) => $a->scope === 'POOL' || ($shop && (int) ($a->branch_id ?? 0) === $shop));
        $by = []; foreach ($ags as $a) $by[(int) $a->investor_id] = ($by[(int) $a->investor_id] ?? 0) + (float) $a->profit_share_pct;
        if (! empty($d['partners'])) $by = array_map('floatval', (array) $d['partners']);
        $total = array_sum($by); if (! $by || $total <= 0) throw new \InvalidArgumentException('No partner agreement covers this; choose the partners and their shares.');
        return DB::transaction(function () use ($ent, $d, $amt, $reason, $shop, $by, $total) {
            $id = DB::table('fin_capital_calls')->insertGetId(['company_id' => $ent, 'po_id' => ($d['po'] ?? null) ?: null, 'amount_requested' => $amt, 'reason' => mb_substr($reason, 0, 255), 'created_by' => Context::userId(), 'created_at' => now(),
                'due_date' => ($d['due_date'] ?? '') ?: date('Y-m-d', strtotime('+7 days')), 'status' => 'OPEN', 'fk_id_branch' => $shop]);
            foreach ($by as $inv => $pct) {
                $ask = round($amt * $pct / $total, 2);
                DB::table('fin_capital_call_responses')->insert(['call_id' => $id, 'investor_id' => $inv, 'amount_accepted' => 0, 'amount_asked' => $ask, 'status' => 'SENT']);
                try {
                    $vars = ['amount' => number_format($ask, 2), 'cycle' => $reason, 'reason' => $reason, 'due' => ($d['due_date'] ?? '') ?: date('Y-m-d', strtotime('+7 days')), 'link' => rtrim((string) config('app.url'), '/') . '/#/partners/' . $inv, 'name' => (string) DB::table('t_account')->where('id', $inv)->value('first_name')];
                    if ($p = Connect\Messenger::whatsappOf($inv)) Connect\Messenger::queueWa($ent, $inv, $p, 'CAPITAL_CALL', $vars, 'fin_capital_calls', $id);
                    if ($e = Connect\Messenger::emailOf($inv)) Connect\Messenger::queueEmail($ent, $inv, $e, 'Capital call: ' . $reason, Connect\Messenger::render('CAPITAL_CALL', $vars)[0], null, 'CAPITAL_CALL', 'fin_capital_calls', $id);
                } catch (\InvalidArgumentException) {}
            }
            Audit::log('fin_capital_calls', $id, 'INSERT', ['amount' => $amt, 'partners' => count($by)], $reason, $ent);
            return $id;
        });
    }

    /** A partner's answer: ACCEPTED / DECLINED; RECEIVED books the money as capital in (Dr bank / Cr 2150) for that partner. */
    public static function callAnswer(int $ent, int $responseId, string $status, ?float $amount = null, ?int $bank = null, ?string $date = null): void
    {
        $r = DB::table('fin_capital_call_responses as r')->join('fin_capital_calls as c', 'c.id', '=', 'r.call_id')->where('r.id', $responseId)->where('c.company_id', $ent)->first(['r.*', 'c.reason', 'c.status as call_status']) ?? throw new \InvalidArgumentException('Answer not found.');
        if ($r->call_status !== 'OPEN') throw new \InvalidArgumentException('This capital call is closed.');
        $partnerSelf = Context::role() === 'PARTNER' && DB::table('fin_partner_users')->where('user_id', Context::userId())->value('investor_id') == $r->investor_id;
        if (! $partnerSelf && ! in_array(Context::role(), ['OWNER', 'FINANCE'], true)) throw new \InvalidArgumentException('Only the partner himself, the Owner or Finance answers a capital call.');
        if ($status === 'RECEIVED') {
            if ($partnerSelf) throw new \InvalidArgumentException('Finance books the money when it is on the bank.');
            $amt = round((float) ($amount ?: $r->amount_asked), 2);
            PartnerService::capitalIn($ent, (int) $r->investor_id, $amt, (int) $bank, $date ?: date('Y-m-d'), 'Capital call: ' . $r->reason);
            DB::table('fin_capital_call_responses')->where('id', $responseId)->update(['status' => 'RECEIVED', 'amount_received' => DB::raw('amount_received + ' . $amt), 'responded_at' => now()]);
        } elseif (in_array($status, ['ACCEPTED', 'DECLINED'], true)) {
            DB::table('fin_capital_call_responses')->where('id', $responseId)->update(['status' => $status, 'amount_accepted' => $status === 'ACCEPTED' ? ($amount ?: $r->amount_asked) : 0, 'responded_at' => now()]);
        } else throw new \InvalidArgumentException('Unknown answer.');
        if (! DB::table('fin_capital_call_responses')->where('call_id', $r->call_id)->whereIn('status', ['SENT', 'ACCEPTED'])->exists()) DB::table('fin_capital_calls')->where('id', $r->call_id)->update(['status' => 'CLOSED']);
        Audit::log('fin_capital_call_responses', $responseId, 'UPDATE', ['status' => $status], null, $ent);
    }

    public static function callsPage(int $ent): array
    {
        $calls = DB::table('fin_capital_calls')->where('company_id', $ent)->orderByDesc('id')->limit(100)->get();
        foreach ($calls as $c) $c->answers = DB::table('fin_capital_call_responses as r')->leftJoin('t_account as a', 'a.id', '=', 'r.investor_id')->where('r.call_id', $c->id)
            ->get(['r.id', 'r.investor_id', 'r.status', 'r.amount_asked', 'r.amount_accepted', 'r.amount_received', 'r.responded_at', DB::raw("TRIM(CONCAT(a.first_name, ' ', a.sur_name)) who")]);
        return ['calls' => $calls, 'shops' => DB::table('t_branches')->where('fk_id_ent', $ent)->where('f_active', 1)->get(['id', 'shop_name as name']),
            'banks' => DB::table('fin_bank_accounts')->where('company_id', $ent)->where('is_active', 1)->get(['id', DB::raw("CONCAT(bank_name, ' · ', COALESCE(account_label,'')) name"), 'currency'])];
    }

    // ===================== partner answer on a statement =====================
    public static function statementAnswer(int $ent, int $statementId, string $what, ?string $question = null): array
    {
        $s = DB::table('fin_statements as s')->join('aud_runs as r', 'r.id', '=', 's.period_id')->where('s.id', $statementId)->where('r.fk_id_ent', $ent)->first(['s.*', 'r.run_code']) ?? throw new \InvalidArgumentException('Statement not found.');
        $mine = DB::table('fin_partner_users')->where('user_id', Context::userId())->value('investor_id');
        if (Context::role() !== 'PARTNER' || (int) $mine !== (int) $s->investor_id) throw new \InvalidArgumentException('Only the partner of this statement answers it.');
        if ($s->superseded) throw new \InvalidArgumentException('A newer version of this statement exists: answer that one.');
        if ($what === 'ACCEPT') {
            DB::table('fin_statements')->where('id', $statementId)->update(['partner_status' => 'ACCEPTED', 'accepted_at' => now()]);
            Audit::log('fin_statements', $statementId, 'APPROVE', ['accepted' => true], null, $ent);
            return ['status' => 'ACCEPTED'];
        }
        $q = trim((string) $question); if (mb_strlen($q) < 5) throw new \InvalidArgumentException('Write the question (a few words at least).');
        $tid = TaskEngine::open('FIN_STATEMENT_QUERY', [$statementId, date('YmdHis')], 'Partner question on statement ' . $s->run_code . ' v' . $s->version . ': ' . mb_substr($q, 0, 140), ['ent' => $ent, 'module' => 'fin_statements', 'record_id' => $statementId, 'fix_url' => '#/partners/' . $s->investor_id]);
        $id = DB::table('fin_statement_queries')->insertGetId(['statement_id' => $statementId, 'investor_id' => $s->investor_id, 'question' => mb_substr($q, 0, 2000), 'task_id' => is_int($tid) ? $tid : null, 'asked_at' => now()]);
        DB::table('fin_statements')->where('id', $statementId)->update(['partner_status' => 'QUERIED']);
        Audit::log('fin_statements', $statementId, 'UPDATE', ['query' => $id], null, $ent);
        return ['status' => 'QUERIED', 'query' => $id];
    }

    public static function statementReply(int $ent, int $queryId, string $answer): void
    {
        if (! in_array(Context::role(), ['OWNER', 'FINANCE', 'MANAGER'], true)) throw new \InvalidArgumentException('Only the Owner, Finance or a Manager answers a partner.');
        $q = DB::table('fin_statement_queries as q')->join('fin_statements as s', 's.id', '=', 'q.statement_id')->join('aud_runs as r', 'r.id', '=', 's.period_id')->where('q.id', $queryId)->where('r.fk_id_ent', $ent)->first(['q.*']) ?? throw new \InvalidArgumentException('Question not found.');
        if (mb_strlen(trim($answer)) < 3) throw new \InvalidArgumentException('Write the answer.');
        DB::table('fin_statement_queries')->where('id', $queryId)->update(['answer' => mb_substr(trim($answer), 0, 2000), 'answered_at' => now(), 'answered_by' => Context::userId()]);
        if ($q->task_id) DB::table('tasks')->where('id', $q->task_id)->update(['status' => 'DONE', 'done_at' => now(), 'last_update_at' => now()]);
        if (! DB::table('fin_statement_queries')->where('statement_id', $q->statement_id)->whereNull('answered_at')->exists()) DB::table('fin_statements')->where('id', $q->statement_id)->update(['partner_status' => 'NEW']);
        try { if ($p = Connect\Messenger::whatsappOf((int) $q->investor_id)) Connect\Messenger::queueWa($ent, (int) $q->investor_id, $p, 'TASK_NEW', ['name' => (string) DB::table('t_account')->where('id', $q->investor_id)->value('first_name'), 'task' => 'answer to your statement question: ' . mb_substr(trim($answer), 0, 300), 'due' => date('Y-m-d'), 'link' => rtrim((string) config('app.url'), '/') . '/#/partners/' . $q->investor_id], 'fin_statement_queries', $queryId); } catch (\InvalidArgumentException) {}
        Audit::log('fin_statements', (int) $q->statement_id, 'UPDATE', ['answered' => $queryId], null, $ent);
    }
}
