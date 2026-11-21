<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-FIN-63 VAT card (record only) per VAT period.
 * Periods follow the company's VAT stagger and frequency from the Accountancy Hub (acc_company_settings, R-ACC-4): stagger 1 = quarters ending
 * Mar / Jun / Sep / Dec, 2 = Apr / Jul / Oct / Jan, 3 = May / Aug / Nov / Feb; monthly = one month; annual = 12 months ending with the stagger's
 * December / January / February. No Hub row → the old calendar quarters (setting VAT_QUARTER_START_MONTH). Each period shows its locked months
 * (shop × month runs LOCKED) and the VAT_RETURN obligation of the same period end when the Hub has one.
 * Output VAT = Σ settlement TAX lines by posted date, converted to the base currency (old: not converted). Input VAT = Σ |amount| (base) of OUT
 * bank lines with category DUTY_VAT or the VAT flag set; Ignored lines are left out (old: counted).
 */
class VatCard
{
    /** [frequency Q / M / A, stagger 1-3, where it came from] */
    public static function plan(int $ent): array
    {
        $h = DB::table('acc_company_settings')->where('fk_id_ent', $ent)->where('vat_registered', 1)->orderBy('id')->first(['vat_frequency', 'vat_stagger']);
        if ($h) return [$h->vat_frequency ?: 'Q', max(1, min(3, (int) $h->vat_stagger ?: 1)), 'Accountancy Hub'];
        $start = max(1, min(12, (int) Settings::get('VAT_QUARTER_START_MONTH', $ent, 1)));
        return ['Q', [0 => 1, 1 => 2, 2 => 3][($start + 2) % 3], 'calendar quarters (no VAT stagger in the Accountancy Hub)'];      // quarters starting in month 1 end 3 / 6 / 9 / 12 = stagger 1
    }

    /** The last $n periods up to and including the one holding $today (newest first): [from, to]. */
    public static function periods(int $ent, ?string $today = null, int $n = 8): array
    {
        $today ??= date('Y-m-d'); [$freq, $st] = self::plan($ent);
        $len = ['M' => 1, 'Q' => 3, 'A' => 12][$freq] ?? 3;
        // an end month of the stagger: stagger 1 → 3, 2 → 4, 3 → 5 (then every $len months); annual → 12 / 1 / 2
        $anchor = $freq === 'A' ? [1 => 12, 2 => 1, 3 => 2][$st] : ($freq === 'M' ? 1 : [1 => 3, 2 => 4, 3 => 5][$st]);
        $y = (int) substr($today, 0, 4); $m = (int) substr($today, 5, 2);
        // the first period end on or after today
        $endM = $anchor; $endY = $y - 1;
        while (sprintf('%04d-%02d', $endY, $endM) < sprintf('%04d-%02d', $y, $m)) { $endM += $len; while ($endM > 12) { $endM -= 12; $endY++; } }
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $to = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $endY, $endM)));
            $from = date('Y-m-01', strtotime(sprintf('%04d-%02d-01', $endY, $endM) . ' -' . ($len - 1) . ' months'));
            $out[] = ['from' => $from, 'to' => $to];
            $endM -= $len; while ($endM < 1) { $endM += 12; $endY--; }
        }
        return $out;
    }

    public static function card(int $ent, string $from, string $to): array
    {
        $out = 0.0;
        foreach (DB::table('fin_settlement_lines')->where('company_id', $ent)->where('bucket', 'TAX')->whereBetween('posted_date', [$from, $to])->get(['amount', 'currency', 'posted_date']) as $l)
            $out += Fx::toBase((float) $l->amount, $l->currency ?: Fx::base($ent), (string) $l->posted_date, $ent);
        $in = (float) DB::table('fin_bank_transactions')->where('company_id', $ent)->where('amount', '<', 0)->where('match_status', '<>', 'IGNORED')->whereBetween('txn_date', [$from, $to])
            ->where(fn ($w) => $w->where('category_code', 'DUTY_VAT')->orWhere('vat_flag', 1))->sum(DB::raw('ABS(COALESCE(amount_gbp, amount))'));
        $months = []; for ($d = substr($from, 0, 7); $d <= substr($to, 0, 7); $d = date('Y-m', strtotime("$d-01 +1 month"))) $months[] = $d;
        $shops = DB::table('t_branches')->where('fk_id_ent', $ent)->where('f_active', 1)->count();
        $locked = DB::table('aud_runs')->where('fk_id_ent', $ent)->whereIn('period_month', $months)->where('status', 'LOCKED')->count();
        $ob = DB::table('acc_obligations')->where('fk_id_ent', $ent)->where('type_code', 'VAT_RETURN')->where('period_end', $to)->first(['id', 'due_date', 'status', 'filed_date']);
        [$freq, $st, $src] = self::plan($ent);
        return ['from' => $from, 'to' => $to, 'output_vat' => round($out, 2), 'input_vat' => round($in, 2), 'net' => round($out - $in, 2), 'base' => Fx::base($ent),
            'locked_months' => $locked, 'shop_months' => $shops * count($months), 'all_locked' => $shops > 0 && $locked >= $shops * count($months),
            'obligation' => $ob ? ['id' => (int) $ob->id, 'due' => $ob->due_date, 'status' => $ob->status, 'filed' => $ob->filed_date] : null,
            'plan' => ['frequency' => $freq, 'stagger' => $st, 'source' => $src]];
    }
}
