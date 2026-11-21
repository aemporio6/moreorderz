<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * The one shop label of the tool (R-A_SALES-81): "[marketplace – ]shop_no · owner · company · shop_name (inactive)". Empty parts are left out.
 * The short form "shop_no (shop_name)" is only for messages (SalesImport::label).
 */
class ShopLabel
{
    /** One label from a row that has shop_no, shop_name, f_active and (optional) marketplace, owner, company. */
    public static function text(object $s, bool $withMarketplace = false): string
    {
        $parts = array_values(array_filter([trim((string) $s->shop_no), trim((string) ($s->owner ?? '')), trim((string) ($s->company ?? '')), trim((string) $s->shop_name)], fn ($x) => $x !== ''));
        $mp = trim((string) ($s->marketplace ?? ''));
        return ($withMarketplace && $mp !== '' ? $mp . ' – ' : '') . implode(' · ', $parts) . ((int) ($s->f_active ?? 1) ? '' : ' (inactive)');
    }

    /**
     * The shops a person may see, sorted by marketplace then shop no, each with its label, its VA (the shop seller) and that VA's commission % for the marketplace.
     * $o: marketplace (id – the cascade), active_only (the import picker), today (the date the VA % is read for).
     */
    public static function list(?object $u = null, array $o = []): array
    {
        $ent = (int) Context::ent(); $name = fn (string $a) => "NULLIF(TRIM(CONCAT_WS(' ', NULLIF($a.first_name,''), NULLIF($a.middle_name,''), NULLIF($a.sur_name,''))), '')";
        $q = DB::table('t_branches as b')->leftJoin('cat_marketplaces as m', 'm.id', '=', 'b.fk_id_marketplace')->leftJoin('t_account as o', 'o.id', '=', 'b.fk_id_owner')->leftJoin('t_account as v', 'v.id', '=', 'b.fk_id_seller')->leftJoin('t_enterprise as c', 'c.id', '=', 'b.fk_id_company')->where('b.fk_id_ent', $ent);
        Perm::mine($q, 'b.id');
        if (! empty($o['marketplace'])) $q->where('b.fk_id_marketplace', (int) $o['marketplace']);
        if (! empty($o['active_only'])) $q->where('b.f_active', 1);
        $rows = $q->orderByRaw("COALESCE(NULLIF(m.marketplace_short,''), m.marketplace_descr, '')")->orderBy('b.shop_no')->orderBy('b.id')
            ->get(['b.id', 'b.shop_no', 'b.shop_name', 'b.f_active', 'b.fk_id_marketplace', 'b.fk_id_seller', 'b.fk_id_owner', 'b.fk_id_company', DB::raw("COALESCE(NULLIF(m.marketplace_short,''), NULLIF(m.marketplace_descr,''), CONCAT('Marketplace ', b.fk_id_marketplace)) as marketplace"),
                DB::raw($name('o') . ' as owner'), DB::raw($name('v') . ' as va'), 'v.account_code as va_code', 'c.ent_name as company']);
        $mixed = empty($o['marketplace']) && $rows->pluck('fk_id_marketplace')->unique()->count() > 1; $day = $o['today'] ?? date('Y-m-d');
        return $rows->map(function ($s) use ($mixed, $day) {
            $pct = null;
            if ($s->fk_id_seller) $pct = DB::table('t_account_job_history')->where('fk_id_account', $s->fk_id_seller)->where('fk_id_marketplace', $s->fk_id_marketplace)->where(fn ($w) => $w->whereNull('d_job_start_date')->orWhere('d_job_start_date', '<=', $day))->where(fn ($w) => $w->whereNull('d_end_date')->orWhere('d_end_date', '>=', $day))->orderByDesc('d_job_start_date')->orderByDesc('id')->value('share_percentage');
            return ['id' => (int) $s->id, 'label' => self::text($s, $mixed), 'label_mp' => self::text($s, true), 'short' => SalesImport::label($s), 'shop_no' => $s->shop_no, 'marketplace' => (int) $s->fk_id_marketplace, 'marketplace_name' => $s->marketplace, 'active' => (int) $s->f_active === 1,
                'owner' => $s->owner, 'owner_id' => (int) $s->fk_id_owner ?: null, 'company' => $s->company, 'company_id' => (int) $s->fk_id_company ?: null, 'va' => $s->va, 'va_id' => (int) $s->fk_id_seller ?: null, 'va_code' => $s->va_code, 'va_pct' => $pct === null ? null : round((float) $pct, 2)];
        })->all();
    }
}
