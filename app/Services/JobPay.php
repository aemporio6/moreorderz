<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * VA share and salary from the job history (R-C-40).
 * - The row: person × marketplace, valid on the day (start ≤ day, end blank or ≥ day); a row for the shop wins over a row with no shop.
 * - Salary in GBP = salary ÷ the row's £ rate when it is above 0, else × the month-end FX rate of its currency (never 1.0); base-currency salary as it is.
 * - Team engagements report: one row per running engagement, file team_engagements_{YYYY-MM}.xlsx.
 */
class JobPay
{
    /** The job-history row valid on $day for this person, marketplace and shop (shop row first). */
    public static function row(int $person, int $marketplace, ?int $shop, string $day): ?object
    {
        return DB::table('t_account_job_history')->where('fk_id_account', $person)->where('fk_id_marketplace', $marketplace)->where(fn ($w) => $w->whereNull('f_active')->orWhere('f_active', 1))
            ->where(fn ($w) => $w->whereNull('fk_id_branch')->orWhere('fk_id_branch', 0)->when($shop, fn ($x) => $x->orWhere('fk_id_branch', $shop)))
            ->where(fn ($w) => $w->whereNull('d_job_start_date')->orWhere('d_job_start_date', '<=', $day))->where(fn ($w) => $w->whereNull('d_end_date')->orWhere('d_end_date', '>=', $day))
            ->orderByRaw('fk_id_branch = ? DESC', [(int) $shop])->orderByDesc('d_job_start_date')->orderByDesc('id')->first();
    }

    /** Salary of one row in GBP for the month of $day. Null when the currency has no rate at all. */
    public static function salaryGbp(object $j, int $ent, string $day): ?float
    {
        if (! (float) $j->salary) return 0.0;
        $base = Fx::base($ent); $cur = $j->fk_id_currency ? (Fx::code((int) $j->fk_id_currency, $ent) ?: $base) : $base;
        if ($cur === $base) return round((float) $j->salary, 2);
        if ((float) $j->pound_exchange_rate > 0) return round((float) $j->salary / (float) $j->pound_exchange_rate, 2);
        try { return round((float) $j->salary * Fx::rate($cur, date('Y-m-t', strtotime($day)), $ent), 2); } catch (\RuntimeException) { return null; }
    }

    /** Running engagements at the end of the month: person, marketplace, shop, dates, share, salary and GBP salary. */
    public static function engagements(int $ent, string $month): array
    {
        $end = date('Y-m-t', strtotime($month . '-01'));
        $rows = DB::table('t_account_job_history as j')->join('t_account as a', 'a.id', '=', 'j.fk_id_account')->leftJoin('cat_marketplaces as m', 'm.id', '=', 'j.fk_id_marketplace')->leftJoin('t_branches as b', 'b.id', '=', 'j.fk_id_branch')->leftJoin('cat_currency as c', 'c.id', '=', 'j.fk_id_currency')
            ->where(fn ($w) => $w->whereNull('j.f_active')->orWhere('j.f_active', 1))->where(fn ($w) => $w->where('a.fk_id_ent', $ent)->orWhere('b.fk_id_ent', $ent))
            ->where(fn ($w) => $w->whereNull('j.d_job_start_date')->orWhere('j.d_job_start_date', '<=', $end))->where(fn ($w) => $w->whereNull('j.d_end_date')->orWhere('j.d_end_date', '>=', $end))
            ->orderBy('a.first_name')->orderBy('m.marketplace_short')->get(['j.*', 'a.account_code', DB::raw("TRIM(CONCAT_WS(' ', NULLIF(a.first_name,''), NULLIF(a.sur_name,''))) as person"), 'm.marketplace_short as marketplace', 'b.shop_no', 'b.shop_name', 'c.currency_code']);
        return $rows->map(fn ($j) => ['code' => $j->account_code, 'name' => $j->person, 'marketplace' => $j->marketplace ?? '', 'shop' => $j->shop_no ? trim($j->shop_no . ' ' . $j->shop_name) : '', 'start' => $j->d_job_start_date, 'renewal' => $j->d_contract_renewal_date,
            'share' => $j->share_percentage !== null ? (float) $j->share_percentage : null, 'salary' => (float) $j->salary, 'currency' => $j->currency_code ?: Fx::base($ent), 'salary_gbp' => self::salaryGbp($j, $ent, $end)])->all();
    }

    public static function xlsx(int $ent, string $month): array
    {
        $rows = self::engagements($ent, $month);
        $x = new \PhpOffice\PhpSpreadsheet\Spreadsheet(); $sh = $x->getActiveSheet(); $sh->setTitle('Team engagements');
        $head = ['Person code', 'Name', 'Marketplace', 'Shop', 'Start', 'Renewal', 'Share %', 'Salary', 'Currency', 'GBP salary'];
        foreach ($head as $i => $h) $sh->setCellValue([$i + 1, 1], $h);
        foreach ($rows as $n => $r) foreach (array_values([$r['code'], $r['name'], $r['marketplace'], $r['shop'], $r['start'], $r['renewal'], $r['share'], $r['salary'], $r['currency'], $r['salary_gbp']]) as $i => $v)
            $sh->setCellValueExplicit([$i + 1, $n + 2], $v ?? '', is_float($v) || is_int($v) ? \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC : \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $last = count($rows) + 2; $sh->setCellValue([1, $last], 'Total'); $sh->setCellValue([10, $last], round(array_sum(array_map(fn ($r) => (float) $r['salary_gbp'], $rows)), 2));
        $tmp = tempnam(sys_get_temp_dir(), 'te'); \App\Services\Brand::xlsx($x); (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($x))->save($tmp); $body = file_get_contents($tmp); @unlink($tmp);
        return ['name' => "team_engagements_$month.xlsx", 'body' => $body, 'rows' => count($rows)];
    }
}
