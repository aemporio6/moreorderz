<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Taxation (R-TAX-1 … R-TAX-6): the accounts worksheet of a period of accounts (P&L, micro balance sheet FRS 105, tax adjustments),
 * Corporation Tax with marginal relief (the exact old method: CT periods of ≤ 12 months, split by HMRC financial year, limits ÷ (associated + 1)),
 * the company size test with its warnings, the deadlines read from the Accountancy Hub obligations (one source, R-ACC-2), the setup / entry checks
 * and the form helpers (next period dates, first period flag, label). Entries can be filled from the company ledger (source FINANCE).
 */
class TaxService
{
    public const ROLES = ['FIXED_ASSET', 'CURRENT_ASSET', 'PREPAYMENT', 'CREDITOR_SHORT', 'CREDITOR_LONG', 'PROVISION', 'ACCRUAL', 'CAPITAL', 'TURNOVER', 'COST_OF_SALES', 'OTHER_INCOME', 'EXPENSE', 'TAX_ADD', 'TAX_DEDUCT'];
    public const ALWAYS_GROUPS = ['GAE', 'DEC', 'LPC', 'PRC'];
    public const DEFAULT_RATE = ['small' => 19.0, 'main' => 25.0, 'lower' => 50000.0, 'upper' => 250000.0, 'num' => 3, 'den' => 200];

    private static function r2(float $v): float { return round($v + 0.0, 2); }
    public static function addMonths(string $d, int $m): string
    {
        $t = new \DateTime($d); $day = (int) $t->format('j'); $t->modify('first day of this month')->modify("+$m months");
        $t->setDate((int) $t->format('Y'), (int) $t->format('n'), min($day, (int) $t->format('t')));      // month-end clamp
        return $t->format('Y-m-d');
    }
    public static function days(string $from, string $to): int { return (int) (new \DateTime($from))->diff(new \DateTime($to))->days + 1; }
    private static function plusDays(string $d, int $n): string { return (new \DateTime($d))->modify(($n >= 0 ? '+' : '') . $n . ' days')->format('Y-m-d'); }

    /** R-TAX-1 role totals = Σ active entries of the period, by role; expense groups by P&L category. */
    public static function worksheet(int $periodId): array
    {
        $rows = DB::table('tax_entries as e')->join('tax_line_items as i', 'i.id', '=', 'e.fk_line_item')->leftJoin('tax_categories as c', 'c.id', '=', 'i.fk_category')
            ->where('e.fk_period', $periodId)->where('e.f_active', 1)->get(['i.line_role', 'i.tax_treatment', 'c.code as cat', 'c.name as cat_name', 'e.amount']);
        $t = array_fill_keys(self::ROLES, 0.0); $groups = []; $disallow = 0.0;
        foreach ($rows as $r) {
            $t[$r->line_role] = ($t[$r->line_role] ?? 0) + (float) $r->amount;
            if ($r->line_role === 'EXPENSE') { $k = $r->cat ?: 'GAE'; $groups[$k] = ($groups[$k] ?? 0) + (float) $r->amount; if ($r->tax_treatment === 'DISALLOW') $disallow += (float) $r->amount; }
        }
        foreach (self::ALWAYS_GROUPS as $g) $groups[$g] ??= 0.0;
        $t = array_map(fn ($v) => self::r2($v), $t);
        $gross = self::r2($t['TURNOVER'] - $t['COST_OF_SALES']); $net = self::r2($gross + $t['OTHER_INCOME'] - $t['EXPENSE']);
        $pct = fn ($v) => $t['TURNOVER'] != 0.0 ? round($v / $t['TURNOVER'] * 100, 1) : 0.0;
        $netCurrent = self::r2($t['CURRENT_ASSET'] + $t['PREPAYMENT'] - $t['CREDITOR_SHORT'] - $t['ACCRUAL']);
        $talcl = self::r2($t['FIXED_ASSET'] + $netCurrent); $netAssets = self::r2($talcl - $t['CREDITOR_LONG'] - $t['PROVISION']);
        $diff = self::r2($netAssets - $t['CAPITAL']);
        $addBacks = self::r2($disallow + $t['TAX_ADD']); $adjusted = self::r2($net + $addBacks - $t['TAX_DEDUCT']);
        return ['totals' => $t,
            'pl' => ['turnover' => $t['TURNOVER'], 'cost_of_sales' => $t['COST_OF_SALES'], 'gross' => $gross, 'other_income' => $t['OTHER_INCOME'], 'expenses' => $t['EXPENSE'], 'net' => $net,
                'gross_margin_pct' => $pct($gross), 'net_margin_pct' => $pct($net), 'expense_groups' => array_map(fn ($v) => self::r2($v), $groups)],
            'bs' => ['fixed_assets' => $t['FIXED_ASSET'], 'current_assets' => $t['CURRENT_ASSET'], 'prepayments' => $t['PREPAYMENT'], 'creditors_short' => $t['CREDITOR_SHORT'], 'accruals' => $t['ACCRUAL'],
                'net_current' => $netCurrent, 'total_assets_less_cl' => $talcl, 'creditors_long' => $t['CREDITOR_LONG'], 'provisions' => $t['PROVISION'], 'net_assets' => $netAssets,
                'capital' => $t['CAPITAL'], 'difference' => $diff, 'balanced' => abs($diff) <= 0.99],
            'tax' => ['net' => $net, 'disallowed' => self::r2($disallow), 'tax_add' => $t['TAX_ADD'], 'add_backs' => $addBacks, 'tax_deduct' => $t['TAX_DEDUCT'], 'adjusted' => $adjusted,
                'taxable' => max(0.0, $adjusted), 'loss_cf' => $adjusted < 0 ? abs($adjusted) : 0.0]];
    }

    /** The rate row of a financial year: its own active row, else the latest FY ≤ it, else the earliest row; no rows at all → the defaults. */
    public static function rate(int $fy, ?array $table = null): array
    {
        $rows = $table ?? DB::table('tax_rates')->where('f_active', 1)->orderBy('fy_start_year')->get()->map(fn ($r) => (array) $r)->all();
        if (! $rows) return self::DEFAULT_RATE + ['fy' => $fy, 'source' => 'default'];
        $pick = null; foreach ($rows as $r) if ((int) $r['fy_start_year'] <= $fy) $pick = $r;
        $pick ??= $rows[0];
        return ['small' => (float) $pick['small_rate'], 'main' => (float) $pick['main_rate'], 'lower' => (float) $pick['lower_limit'], 'upper' => (float) $pick['upper_limit'],
            'num' => (int) $pick['mr_fraction_num'], 'den' => max(1, (int) $pick['mr_fraction_den']), 'fy' => (int) $pick['fy_start_year'], 'source' => 'table'];
    }

    /** R-TAX-2 Corporation Tax of a period of accounts. */
    public static function ct(float $taxable, string $start, string $end, int $associated = 0, ?array $rates = null, float $paid = 0.0): array
    {
        $total = self::days($start, $end); $cts = []; $s = $start; $sum = 0.0;
        while ($s <= $end) {
            $e = min(self::plusDays(self::addMonths($s, 12), -1), $end);
            $ctDays = self::days($s, $e); $ctProfit = $taxable * $ctDays / $total; $segs = []; $ctTax = 0.0; $a = $s;
            while ($a <= $e) {
                $y = (int) substr($a, 0, 4); $fy = substr($a, 5) >= '04-01' ? $y : $y - 1;
                $b = min(sprintf('%04d-03-31', $fy + 1), $e); $dd = self::days($a, $b);
                $p = $ctProfit * $dd / $ctDays; $rt = self::rate($fy, $rates);
                $lower = $rt['lower'] * $dd / 365 / ($associated + 1); $upper = $rt['upper'] * $dd / 365 / ($associated + 1); $mr = 0.0;
                if ($p <= 0) { $band = 'No profit'; $tax = 0.0; }
                elseif ($upper <= 0 || $rt['small'] == $rt['main']) { $band = 'Main rate'; $tax = $p * $rt['main'] / 100; }
                elseif ($p <= $lower) { $band = 'Small profits rate'; $tax = $p * $rt['small'] / 100; }
                elseif ($p >= $upper) { $band = 'Main rate'; $tax = $p * $rt['main'] / 100; }
                else { $band = 'Main rate less marginal relief'; $mr = $rt['num'] / $rt['den'] * ($upper - $p); $tax = $p * $rt['main'] / 100 - $mr; }
                $segs[] = ['fy' => $fy, 'from' => $a, 'to' => $b, 'days' => $dd, 'profit' => self::r2($p), 'lower' => self::r2($lower), 'upper' => self::r2($upper), 'band' => $band, 'marginal_relief' => self::r2($mr), 'tax' => self::r2($tax)];
                $ctTax += self::r2($tax); $a = self::plusDays($b, 1);
            }
            $cts[] = ['from' => $s, 'to' => $e, 'days' => $ctDays, 'profit' => self::r2($ctProfit), 'tax' => self::r2($ctTax), 'segments' => $segs, 'payment_due' => self::plusDays(self::addMonths($e, 9), 1)];
            $sum += self::r2($ctTax); $s = self::plusDays($e, 1);
        }
        $sum = self::r2($sum);
        return ['taxable' => self::r2($taxable), 'periods' => $cts, 'ct' => $sum, 'effective_rate' => $taxable > 0 ? round($sum / $taxable * 100, 2) : 0.0, 'balance_due' => max(0.0, self::r2($sum - $paid))];
    }

    /** R-TAX-3 size test and warnings. */
    public static function size(object $p, array $w): array
    {
        $days = self::days((string) $p->period_start, (string) $p->period_end);
        $turnover = $w['pl']['turnover'] * 365 / max(1, $days); $bs = $w['bs']['fixed_assets'] + $w['bs']['current_assets'] + $w['bs']['prepayments']; $emp = (int) $p->avg_employees;
        $two = fn (array $lim) => (int) ($turnover <= $lim[0]) + (int) ($bs <= $lim[1]) + (int) ($emp <= $lim[2]) >= 2;
        $size = $two([1000000, 500000, 10]) ? 'MICRO' : ($two([15000000, 7500000, 50]) ? 'SMALL' : 'MEDIUM_LARGE');
        $warn = [];
        if (! $w['bs']['balanced']) $warn[] = 'The balance sheet does not balance (difference ' . number_format($w['bs']['difference'], 2) . ').';
        if (self::addMonths((string) $p->period_start, 12) <= (string) $p->period_end) $warn[] = 'This period is longer than 12 months: two CT accounting periods (two CT600 returns), profits time-apportioned.';
        if ($w['pl']['turnover'] <= 0 && $p->accounts_type !== 'DORMANT') $warn[] = 'No turnover recorded.';
        if ($w['tax']['adjusted'] < 0) $warn[] = 'Tax-adjusted loss: record it as Trading losses brought forward in the next period.';
        if ($size !== 'MICRO' && $p->accounts_type === 'MICRO') $warn[] = 'The company is not micro by the size test – check whether micro-entity accounts are still allowed.';
        return ['annual_turnover' => self::r2($turnover), 'balance_sheet_total' => self::r2($bs), 'employees' => $emp, 'size' => $size, 'warnings' => $warn];
    }

    /** R-TAX-5 the deadlines are the Accountancy Hub obligations of the same company and period (one source). */
    public static function deadlines(object $p): array
    {
        if (! $p->fk_company_house) return [];
        return DB::table('acc_obligations')->where('entity_type', 'C')->where('entity_id', $p->fk_company_house)->where('period_end', $p->period_end)
            ->whereIn('type_code', ['ACCOUNTS', 'CT600', 'CT_PAYMENT'])->orderBy('due_date')->get(['id', 'type_code', 'title', 'due_date', 'status', 'f_done'])
            ->map(fn ($o) => ['id' => (int) $o->id, 'type' => $o->type_code, 'title' => $o->title, 'due' => $o->due_date, 'status' => $o->status, 'done' => (bool) $o->f_done])->all();
    }

    public static function period(int $id): array
    {
        $p = DB::table('tax_periods')->where('id', $id)->first() ?? throw new \InvalidArgumentException('Period not found.');
        $w = self::worksheet($id);
        $ct = self::ct($w['tax']['taxable'], (string) $p->period_start, (string) $p->period_end, (int) $p->associated_companies, null, (float) $p->tax_paid_amount);
        $dl = self::deadlines($p); $next = collect($dl)->where('done', false)->sortBy('due')->first();
        return ['period' => $p, 'worksheet' => $w, 'ct' => $ct, 'size' => self::size($p, $w), 'deadlines' => $dl, 'next_deadline' => $next];
    }

    /** R-TAX-6 dashboard "All periods". */
    public static function dashboard(int $ent): array
    {
        $rows = [];
        foreach (DB::table('tax_periods as p')->leftJoin('cat_company_house as c', 'c.id', '=', 'p.fk_company_house')->where('p.fk_id_ent', $ent)->where('p.f_active', 1)->orderByDesc('p.period_end')->get(['p.id', 'c.company_name']) as $r) {
            $x = self::period((int) $r->id);
            $rows[] = ['id' => (int) $r->id, 'company' => $r->company_name, 'label' => $x['period']->period_label, 'from' => $x['period']->period_start, 'to' => $x['period']->period_end, 'status' => $x['period']->status,
                'turnover' => $x['worksheet']['pl']['turnover'], 'net' => $x['worksheet']['pl']['net'], 'taxable' => $x['worksheet']['tax']['taxable'], 'ct' => $x['ct']['ct'], 'balance_due' => $x['ct']['balance_due'],
                'balanced' => $x['worksheet']['bs']['balanced'], 'next_deadline' => $x['next_deadline']['due'] ?? null, 'next_what' => $x['next_deadline']['title'] ?? null];
        }
        return ['rows' => $rows];
    }

    /** R-TAX-6 the next period of a company: start = last end + 1 day, end = start + 1 year − 1 day; the first one is ticked "first accounts". */
    public static function nextPeriod(int $companyHouse): array
    {
        $last = DB::table('tax_periods')->where('fk_company_house', $companyHouse)->where('f_active', 1)->max('period_end');
        if ($last) { $s = self::plusDays((string) $last, 1); $first = 0; }
        else { $inc = DB::table('cat_company_house')->where('id', $companyHouse)->value('incorporate_date'); $s = $inc ?: date('Y-m-01'); $first = 1; }
        $e = self::plusDays(self::addMonths($s, 12), -1);
        return ['period_start' => $s, 'period_end' => $e, 'f_first_period' => $first, 'period_label' => 'Period ending ' . date('d M Y', strtotime($e))];
    }

    /** R-TAX-4 FormRules hook for the four tax forms. */
    public static function check(string $table, array &$data, array $old, ?int $id): array
    {
        $v = fn ($c) => array_key_exists($c, $data) ? $data[$c] : ($old[$c] ?? null); $e = [];
        if ($table === 'tax_periods') {
            if (! $id && ! $v('period_start') && ($co = (int) $v('fk_company_house'))) foreach (self::nextPeriod($co) as $k => $x) if (! $v($k)) $data[$k] = $x;      // R-TAX-6 helpers
            $s = (string) $v('period_start'); $en = (string) $v('period_end');
            if ($s === '' || $en === '') return ['period_start' => 'Enter the start and the end of the period.'];
            if ($s >= $en) return ['period_end' => 'The end must be after the start.'];
            if ($en >= self::addMonths($s, 18)) return ['period_end' => 'A period of accounts cannot exceed 18 months.'];
            if (DB::table('tax_periods')->where('fk_company_house', $v('fk_company_house'))->where('period_start', $s)->where('f_active', 1)->when($id, fn ($q) => $q->where('id', '<>', $id))->exists()) $e['period_start'] = 'This company already has a period starting on ' . $s . '.';
            if (trim((string) $v('period_label')) === '') $data['period_label'] = 'Period ending ' . date('d M Y', strtotime($en));
            foreach (['associated_companies', 'avg_employees'] as $c) if ($v($c) !== null && $v($c) !== '' && (int) $v($c) < 0) $e[$c] = 'Must be 0 or more.';
            if (! $id && ! $v('fk_id_ent')) $data['fk_id_ent'] = (int) Context::ent();
        }
        if ($table === 'tax_line_items') {
            if (array_key_exists('code', $data) && $data['code'] !== null) {
                $data['code'] = strtoupper(trim((string) $data['code']));
                if ($data['code'] !== '' && DB::table('tax_line_items')->where('code', $data['code'])->when($id, fn ($q) => $q->where('id', '<>', $id))->exists()) $e['code'] = 'This code is already used.';
            }
            if (trim((string) $v('name')) === '') $e['name'] = 'Name is required.';
            if (! $v('tax_treatment')) $data['tax_treatment'] = 'NA';
        }
        if ($table === 'tax_entries') {
            if (! $v('fk_period')) $e['fk_period'] = 'Choose the period.';
            if (! $v('fk_line_item')) $e['fk_line_item'] = 'Choose the line item.';
            if (array_key_exists('amount', $data)) { $a = str_replace([',', '£', ' '], '', (string) $data['amount']); if (! is_numeric($a)) $e['amount'] = 'Amount must be a number.'; else $data['amount'] = round((float) $a, 2); }
            if (! $id && ! $v('entry_date') && ($p = DB::table('tax_periods')->where('id', (int) $v('fk_period'))->value('period_end'))) $data['entry_date'] = $p;
            if (! $id && ! $v('source')) $data['source'] = 'MANUAL';
        }
        if ($table === 'tax_rates') {
            $fy = (int) $v('fy_start_year');
            if ($fy < 2000 || $fy > 2100) $e['fy_start_year'] = 'Financial year from 2000 to 2100.';
            elseif (DB::table('tax_rates')->where('fy_start_year', $fy)->when($id, fn ($q) => $q->where('id', '<>', $id))->exists()) $e['fy_start_year'] = "FY $fy already has a rate row.";
            if ($v('mr_fraction_den') !== null && $v('mr_fraction_den') !== '' && (int) $v('mr_fraction_den') < 1) $e['mr_fraction_den'] = 'The denominator must be 1 or more.';
        }
        return $e;
    }

    /** Improved R-TAX-1: turnover, cost of sales, other income and expenses of the period from the company ledger (source FINANCE, replaced on each fill). */
    public static function fillFromFinance(int $periodId): array
    {
        $p = DB::table('tax_periods')->where('id', $periodId)->first() ?? throw new \InvalidArgumentException('Period not found.');
        $ent = (int) $p->fk_id_ent; $pl = Ledger::profitAndLoss($ent, (string) $p->period_start, (string) $p->period_end);
        $item = fn (string $code) => (int) DB::table('tax_line_items')->where('code', $code)->value('id');
        $map = fn (string $acct, string $kind) => match (true) { $acct === '4000' => 'TO01', $kind === 'INCOME' => 'OI01', $acct === '5000' => 'CS01', default => 'GAE01' };
        return DB::transaction(function () use ($p, $pl, $item, $map) {
            DB::table('tax_entries')->where('fk_period', $p->id)->where('source', 'FINANCE')->delete();
            $n = 0;
            foreach ($pl['lines'] as $l) {
                $li = $item($map((string) $l['code'], (string) $l['kind'])); if (! $li || abs((float) $l['amount']) < 0.005) continue;
                $amt = (float) $l['amount'];      // profitAndLoss gives income and costs both as positive amounts
                DB::table('tax_entries')->insert(['fk_id_ent' => $p->fk_id_ent, 'fk_period' => $p->id, 'fk_line_item' => $li, 'entry_date' => $p->period_end, 'amount' => round($amt, 2), 'reference' => 'Ledger ' . $l['code'],
                    'description' => $l['name'], 'source' => 'FINANCE', 'source_ref_id' => (string) $l['code'], 'f_active' => 1, 'created_by' => Context::userId(), 'created_at' => now()]);
                $n++;
            }
            Audit::log('tax_periods', (int) $p->id, 'UPDATE', ['filled_from_finance' => $n], 'Entries from the ledger', (int) $p->fk_id_ent);
            return ['lines' => $n, 'message' => "$n line(s) filled from the ledger (earlier ledger lines replaced; your own entries kept)."];
        });
    }
}
