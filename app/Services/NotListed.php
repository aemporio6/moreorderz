<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Products not listed per shop (R-D-58).
 * Universe per shop = products visible to that shop (R-D-20: Pool on its marketplace, or Private with the shop ticked; a child follows its parent),
 * active, own stock > 0 and not manually out of stock, child or alone level (not parents) – minus products with an ACTIVE listing in that shop.
 * Summary per shop (Total, Not used, View, Upload); drill-down with filters Shop / Type / Preset group / Packing; bulk "Create listing task"
 * (LST_CREATE, one per shop + product, to the shop's TL), closed by itself when the listing exists or the product is no longer in the shop's universe.
 * Shop scope applies.
 */
class NotListed
{
    /** The shops this person may see (active). */
    private static function shops(int $ent)
    {
        return Perm::mine(DB::table('t_branches as b'), 'b.id')->leftJoin('cat_marketplaces as m', 'm.id', '=', 'b.fk_id_marketplace')->leftJoin('t_account as o', 'o.id', '=', 'b.fk_id_owner')
            ->where('b.fk_id_ent', $ent)->where('b.f_active', 1)->orderBy('b.shop_no')
            ->get(['b.id', 'b.shop_no', 'b.shop_name', 'b.fk_id_marketplace', DB::raw("COALESCE(NULLIF(m.marketplace_short,''), m.marketplace_descr) as marketplace"), DB::raw("TRIM(CONCAT_WS(' ', NULLIF(o.first_name,''), NULLIF(o.sur_name,''))) as owner")]);
    }

    /** The universe of one shop: [product id => row] (visible, active, stock > 0, not OOS, not a parent). */
    public static function universe(int $ent, object $shop, array $f = []): array
    {
        $parents = DB::table('t_product_vendors')->where('fk_id_ent', $ent)->where('f_id_parent', '>', 0)->where('f_active', 1)->distinct()->pluck('f_id_parent')->all();
        $q = DB::table('t_product_vendors as p')->where('p.fk_id_ent', $ent)->where('p.f_active', 1)->where('p.stock', '>', 0)->where(fn ($w) => $w->whereNull('p.f_out_of_stock')->orWhere('p.f_out_of_stock', 0))
            ->whereNotIn('p.id', $parents ?: [0])->whereNull('p.fk_id_merged_into')
            ->whereExists(fn ($v) => $v->from('t_product_visibility as v')->whereRaw('v.fk_id_product IN (p.id, p.f_id_parent)')->where('v.fk_id_marketplace', $shop->fk_id_marketplace)
                ->where(fn ($w) => $w->where(fn ($x) => $x->where('v.fk_id_branch', 0)->orWhereNull('v.fk_id_branch'))->where('v.visibility_mode', 'POOL')->orWhere('v.fk_id_branch', $shop->id)));
        if (! empty($f['type'])) $q->where('p.e_product_type', $f['type']);
        if (! empty($f['preset'])) $q->where('p.fk_id_car_pkg_svc', (int) $f['preset']);
        if (! empty($f['packing'])) $q->where('p.fk_id_packing', (int) $f['packing']);
        return $q->orderBy('p.SKU_VENDOR')->get(['p.id', 'p.SKU_VENDOR as sku', 'p.product_name_vendor as name', 'p.stock', 'p.e_product_type as type'])->keyBy('id')->all();
    }

    /** Products of the shop with an ACTIVE listing there. */
    public static function listed(object $shop, array $ids): array
    {
        return DB::table('t_product_listings')->where('fk_id_branch', $shop->id)->where('status', 'ACTIVE')->where('f_active', 1)->whereIn('fk_id_product', $ids ?: [0])->pluck('fk_id_product')->map(fn ($x) => (int) $x)->all();
    }

    /** Summary per shop: Total (universe), Not used (not listed). */
    public static function summary(int $ent, array $f = []): array
    {
        $rows = [];
        foreach (self::shops($ent) as $s) {
            if (! empty($f['shop']) && (int) $f['shop'] !== (int) $s->id) continue;
            $u = self::universe($ent, $s, $f); $listed = self::listed($s, array_keys($u));
            $rows[] = ['shop' => (int) $s->id, 'shop_label' => trim($s->shop_no . ' ' . $s->shop_name), 'detail' => trim(($s->marketplace ?? '') . ($s->owner ? ' · ' . $s->owner : '')), 'total' => count($u), 'not_used' => count($u) - count(array_intersect(array_keys($u), $listed))];
        }
        return ['rows' => $rows, 'shops' => self::shops($ent)->map(fn ($s) => ['id' => (int) $s->id, 'name' => trim($s->shop_no . ' ' . $s->shop_name)])->all(),
            'types' => DB::table('t_product_vendors')->where('fk_id_ent', $ent)->whereNotNull('e_product_type')->distinct()->pluck('e_product_type')->all(),
            'presets' => DB::table('t_carrier_pkg_svc')->where('f_active', 1)->orderBy('pkg_svc_name')->get(['id', 'pkg_svc_name as name']), 'packings' => DB::table('t_packings')->where('f_active', 1)->orderBy('packing_name')->get(['id', 'packing_name as name'])];
    }

    /** Drill-down: Shop no, SKU, Product – the not-listed products of the shops in scope (or one shop). */
    public static function lines(int $ent, array $f = []): array
    {
        $out = [];
        foreach (self::shops($ent) as $s) {
            if (! empty($f['shop']) && (int) $f['shop'] !== (int) $s->id) continue;
            $u = self::universe($ent, $s, $f); $listed = array_flip(self::listed($s, array_keys($u)));
            foreach ($u as $id => $p) if (! isset($listed[$id])) $out[] = ['shop' => (int) $s->id, 'shop_no' => $s->shop_no, 'product' => (int) $id, 'sku' => $p->sku, 'name' => $p->name, 'stock' => (int) $p->stock, 'type' => $p->type,
                'task_open' => TaskEngine::isOpen('LST_CREATE', [$s->id, $id])];
        }
        return ['rows' => $out, 'count' => count($out)];
    }

    /** Bulk action "Create listing task": one LST_CREATE per shop + product, to the shop's TL. $pairs = [[shop, product], …]; empty = every line of the filter. */
    public static function createTasks(int $ent, array $pairs, array $f = []): array
    {
        if (! $pairs) $pairs = array_map(fn ($r) => [$r['shop'], $r['product']], self::lines($ent, $f)['rows']);
        $shops = self::shops($ent)->keyBy('id'); $made = 0; $skipped = 0;
        foreach ($pairs as [$shop, $product]) {
            $s = $shops[(int) $shop] ?? null; if (! $s) { $skipped++; continue; }
            $u = self::universe($ent, $s); if (! isset($u[(int) $product]) || in_array((int) $product, self::listed($s, [(int) $product]), true)) { $skipped++; continue; }
            if (TaskEngine::isOpen('LST_CREATE', [$s->id, $product])) { $skipped++; continue; }
            $tl = ShopHealth::tlOf((int) $s->id);
            TaskEngine::open('LST_CREATE', [$s->id, $product], "List {$u[(int) $product]->sku} in shop " . trim($s->shop_no . ' ' . $s->shop_name), ['ent' => $ent, 'module' => 't_product_vendors', 'record_id' => (int) $product, 'shop' => (int) $s->id, 'fix_url' => '#/not-listed?shop=' . $s->id] + ($tl ? ['owner_user_id' => $tl] : []));
            $made++;
        }
        return ['made' => $made, 'skipped' => $skipped, 'message' => "$made listing task(s) made" . ($skipped ? ", $skipped skipped (already listed, already a task, or not in the shop's list)" : '') . '.'];
    }

    /** Close LST_CREATE tasks whose listing now exists or whose product left the shop's universe (nightly and after the page opens). */
    public static function closeDone(int $ent): int
    {
        $n = 0; $cache = [];
        foreach (DB::table('tasks')->where('rule_code', 'LST_CREATE')->where('fk_id_ent', $ent)->whereNotIn('status', ['DONE', 'APPROVED', 'CANCELLED'])->get(['dedupe_key']) as $t) {
            [, $shop, $product] = array_pad(explode(':', (string) $t->dedupe_key), 3, 0);
            $s = DB::table('t_branches')->where('id', (int) $shop)->first(['id', 'fk_id_marketplace', 'shop_no', 'shop_name']);
            $u = $s ? ($cache[$s->id] ??= self::universe($ent, $s)) : [];
            if (! $s || ! isset($u[(int) $product])) { TaskEngine::close('LST_CREATE', [(int) $shop, (int) $product], 'The product is no longer for this shop'); $n++; continue; }
            if (in_array((int) $product, self::listed($s, [(int) $product]), true)) { TaskEngine::close('LST_CREATE', [(int) $shop, (int) $product], 'The listing exists'); $n++; }
        }
        return $n;
    }
}
