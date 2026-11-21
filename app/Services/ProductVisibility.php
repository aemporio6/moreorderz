<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Visibility per marketplace × shop (R-D-20). Set per marketplace, not per product:
 *  - a marketplace chip on/off ("sold there"); when on, mode POOL (= every active shop of that marketplace, no shop rows) or PRIVATE (= only the ticked active shops of it).
 *  - stored by upsert (never delete-all + re-insert): t_product_visibility keeps one row per product × marketplace for Pool / Private-with-no-shop (fk_id_branch 0)
 *    and one row per ticked shop for Private (fk_id_branch = shop) – the shape every reader uses (sales, not-listed, products list);
 *    t_product_visibility_shops mirrors the ticked shops.
 *  - any mode other than "private" is saved as pool; Pool never stores shops; a shop of another marketplace is refused; Private with 0 shops is allowed with a warning;
 *    unticking a marketplace clears it and its shops. Saved only when the Visibility tab is posted.
 *  - lot default at receipt = the product's mode for the marketplace of the receiving location's shop, else the old rule.
 */
class ProductVisibility
{
    /** The shop label (R-A_SALES-81): shop_no - owner - company - shop_name, empty parts left out. */
    private static function shops(int $ent)
    {
        return DB::table('t_branches as b')->leftJoin('t_account as o', 'o.id', '=', 'b.fk_id_owner')->leftJoin('t_enterprise as e', 'e.id', '=', 'b.fk_id_ent')->where('b.fk_id_ent', $ent)->where('b.f_active', 1)->orderBy('b.shop_no')
            ->get(['b.id', 'b.fk_id_marketplace', 'b.shop_no', 'b.shop_name', DB::raw("TRIM(CONCAT_WS(' ', NULLIF(o.first_name,''), NULLIF(o.sur_name,''))) as owner"), 'e.ent_name as company'])
            ->map(fn ($s) => ['id' => (int) $s->id, 'marketplace' => (int) $s->fk_id_marketplace, 'label' => implode(' - ', array_filter([$s->shop_no, $s->owner, $s->company, $s->shop_name], fn ($x) => trim((string) $x) !== ''))]);
    }

    /** The matrix marketplace × shop for one product. */
    public static function get(int $ent, int $product): array
    {
        $rows = DB::table('t_product_visibility')->where('fk_id_product', $product)->get();
        $shops = self::shops($ent)->groupBy('marketplace');
        $mps = DB::table('cat_marketplaces')->whereIn('fk_id_ent', [0, $ent])->where('f_active', 1)->orderBy('id')->get(['id', DB::raw("COALESCE(NULLIF(marketplace_short,''), NULLIF(marketplace_descr,''), id) as name")]);
        return ['product' => $product, 'marketplaces' => $mps->map(function ($m) use ($rows, $shops) {
            $r = $rows->where('fk_id_marketplace', $m->id); $mode = $r->isEmpty() ? null : strtoupper((string) $r->first()->visibility_mode);
            $ticked = $r->where('visibility_mode', 'private')->pluck('fk_id_branch')->map(fn ($x) => (int) $x)->filter()->values()->all();
            return ['id' => (int) $m->id, 'name' => $m->name, 'on' => $mode !== null, 'mode' => $mode ?? 'POOL', 'shops' => ($shops[$m->id] ?? collect())->values()->all(), 'ticked' => $ticked,
                'summary' => $mode === null ? 'Not sold there' : ($mode === 'POOL' ? 'Pool – every active shop' : count($ticked) . ' shop(s) selected')];
        })->all()];
    }

    /**
     * Save the Visibility tab. $tab = [marketplace id => ['on' => bool, 'mode' => 'POOL'|'PRIVATE', 'shops' => [ids]]]. Returns warnings.
     * Throws ValidationErrors for a shop of another marketplace.
     */
    public static function save(int $ent, int $product, array $tab, ?object $u = null): array
    {
        DB::table('t_product_vendors')->where('id', $product)->exists() || throw new \InvalidArgumentException('Product not found.');
        $shopMp = self::shops($ent)->pluck('marketplace', 'id'); $e = []; $warn = []; $plan = [];
        foreach ($tab as $mp => $v) {
            $mp = (int) $mp; $on = filter_var($v['on'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $mode = strtolower((string) ($v['mode'] ?? 'pool')) === 'private' ? 'private' : 'pool';      // any other mode is pool (old rule)
            $ids = array_values(array_unique(array_map('intval', (array) ($v['shops'] ?? []))));
            if ($on && $mode === 'private') foreach ($ids as $s) if ((int) ($shopMp[$s] ?? 0) !== $mp) $e["shops_$mp"] = 'Shop ' . $s . ' is not a shop of this marketplace.';
            $plan[$mp] = [$on, $mode, $mode === 'private' ? $ids : []];
            if ($on && $mode === 'private' && ! $ids) $warn[] = 'Private with no shop – nobody can sell it (' . DB::table('cat_marketplaces')->where('id', $mp)->value(DB::raw("COALESCE(NULLIF(marketplace_short,''), marketplace_descr)")) . ').';
        }
        if ($e) throw new ValidationErrors($e);
        DB::transaction(function () use ($product, $plan, $u) {
            foreach ($plan as $mp => [$on, $mode, $ids]) {
                $old = DB::table('t_product_visibility')->where('fk_id_product', $product)->where('fk_id_marketplace', $mp)->get();
                if (! $on) {      // unticking a marketplace clears it and its shops
                    if ($old->isNotEmpty()) { DB::table('t_product_visibility')->where('fk_id_product', $product)->where('fk_id_marketplace', $mp)->delete(); DB::table('t_product_visibility_shops')->where('fk_id_product', $product)->where('fk_id_marketplace', $mp)->delete(); Audit::log('t_product_vendors', $product, 'UPDATE', ['visibility' => [$mp, 'off']]); }
                    continue;
                }
                $keep = $mode === 'pool' || ! $ids ? [0] : $ids;
                DB::table('t_product_visibility')->where('fk_id_product', $product)->where('fk_id_marketplace', $mp)->whereNotIn(DB::raw('COALESCE(fk_id_branch, 0)'), $keep)->delete();
                foreach ($keep as $b) {
                    $q = DB::table('t_product_visibility')->where('fk_id_product', $product)->where('fk_id_marketplace', $mp)->whereRaw('COALESCE(fk_id_branch, 0) = ?', [$b]);
                    if ($q->exists()) $q->update(['visibility_mode' => $mode, 'updated_at' => now()]);
                    else DB::table('t_product_visibility')->insert(['fk_id_product' => $product, 'fk_id_marketplace' => $mp, 'visibility_mode' => $mode, 'fk_id_branch' => $b, 'created_by' => $u->id ?? 0, 'created_at' => now(), 'updated_at' => now()]);
                }
                DB::table('t_product_visibility_shops')->where('fk_id_product', $product)->where('fk_id_marketplace', $mp)->whereNotIn('fk_id_branch', $mode === 'private' && $ids ? $ids : [0])->delete();
                foreach ($mode === 'private' ? $ids : [] as $b) if (! DB::table('t_product_visibility_shops')->where(['fk_id_product' => $product, 'fk_id_marketplace' => $mp, 'fk_id_branch' => $b])->exists())
                    DB::table('t_product_visibility_shops')->insert(['fk_id_product' => $product, 'fk_id_marketplace' => $mp, 'fk_id_branch' => $b, 'created_by' => $u->id ?? 0, 'created_at' => now()]);
                $was = $old->isEmpty() ? 'off' : strtoupper((string) $old->first()->visibility_mode) . ($old->where('visibility_mode', 'private')->pluck('fk_id_branch')->filter()->count() ? ' ' . $old->pluck('fk_id_branch')->filter()->implode(',') : '');
                $now = strtoupper($mode) . ($mode === 'private' && $ids ? ' ' . implode(',', $ids) : '');
                if ($was !== $now) Audit::log('t_product_vendors', $product, 'UPDATE', ['visibility' => [$mp, $was, $now]]);
            }
        });
        return ['warnings' => $warn, 'message' => 'Visibility saved.' . ($warn ? ' ' . implode(' ', $warn) : '')];
    }

    /** A lot's default visibility at receipt: the product's mode for the marketplace of the receiving location's shop; null = not known (caller keeps its own rule). */
    public static function lotDefault(int $product, ?int $location): ?string
    {
        if (! $location) return null;
        $shop = (int) DB::table('cat_inventory_locations')->where('id', $location)->value('fk_id_branch'); if (! $shop) return null;
        $mp = (int) DB::table('t_branches')->where('id', $shop)->value('fk_id_marketplace'); if (! $mp) return null;
        $parent = (int) DB::table('t_product_vendors')->where('id', $product)->value('f_id_parent');
        $mode = DB::table('t_product_visibility')->whereIn('fk_id_product', array_filter([$product, $parent !== $product ? $parent : 0]))->where('fk_id_marketplace', $mp)->value('visibility_mode');
        return $mode ? strtoupper((string) $mode) : 'POOL';
    }
}
