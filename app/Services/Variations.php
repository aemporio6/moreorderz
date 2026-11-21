<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Variations (R-D-4): children of a parent product in one grid. A child stores only what it overrides – an empty value shows the parent's, grey "from parent".
 * One save path (the grid): each card resolves to its id, else to the child with the same SKU under this parent, else a new child. Stock is never copied and never
 * typed on a child (opening stock goes through the ledger). Duplicate child: a new card pre-filled from a child with the next free SKU.
 */
class Variations
{
    public const MP_FIELDS = ['raw_price', 'sale_price', 'list_price', 'marketplace_fee'];
    public const SHIP_FIELDS = ['fk_id_car_pkg_svc', 'fk_id_packing'];

    private static function parent(int $id): object
    {
        $p = DB::table('t_product_vendors')->where('id', $id)->where('fk_id_ent', Context::ent())->first() ?? throw new \InvalidArgumentException('Product not found.');
        if ((int) $p->f_parent === 3) throw new \InvalidArgumentException('This product is a child product and child sku can not be added for this product');      // old text
        return $p;
    }

    private static function children(int $parentId) { return DB::table('t_product_vendors')->where('f_id_parent', $parentId)->where('f_parent', 3)->where('f_active', 1)->orderBy('id')->get(); }

    private static function mpName(): \Illuminate\Database\Query\Expression { $lc = Registry::labelColumn('cat_marketplaces') ?: 'id'; return DB::raw("COALESCE(NULLIF(marketplace_short, ''), NULLIF($lc, ''), id) as name"); }

    /** The grid: rows = children, column groups = marketplaces; each value says whether it is the child's own or from the parent. */
    public static function grid(int $parentId, ?object $u): array
    {
        Perm::need($u, 'stock.prices'); $p = self::parent($parentId);
        $mps = DB::table('cat_marketplaces')->where('f_active', 1)->orderBy('marketplace_short')->get(['id', self::mpName()]);
        $pp = DB::table('t_product_marketplace_prices')->where('fk_id_product', $parentId)->get()->keyBy('fk_id_marketplace'); $ps = DB::table('t_product_marketplace_shipping')->where('fk_id_product', $parentId)->get()->keyBy('fk_id_marketplace');
        $rate = fn ($t, $id) => $id ? (float) DB::table($t)->where('id', $id)->value($t === 't_packings' ? 'packing_rate' : 'rate') : 0.0;
        $rows = [];
        foreach (self::children($parentId) as $c) {
            $cp = DB::table('t_variation_marketplace_prices')->where('fk_id_variation', $c->id)->where('f_active', 1)->get()->keyBy('fk_id_marketplace'); $cs = DB::table('t_variation_marketplace_shipping')->where('fk_id_variation', $c->id)->where('f_active', 1)->get()->keyBy('fk_id_marketplace');
            $m = [];
            foreach ($mps as $mp) {
                $cell = [];
                foreach (self::MP_FIELDS as $f) { $own = isset($cp[$mp->id]) ? $cp[$mp->id]->$f : null; $cell[$f] = ['value' => $own !== null ? (float) $own : (isset($pp[$mp->id]) ? (float) $pp[$mp->id]->$f : null), 'own' => $own !== null]; }
                foreach (self::SHIP_FIELDS as $f) { $own = isset($cs[$mp->id]) ? $cs[$mp->id]->$f : null; $cell[$f] = ['value' => $own !== null ? (int) $own : (isset($ps[$mp->id]) && $ps[$mp->id]->$f ? (int) $ps[$mp->id]->$f : null), 'own' => $own !== null]; }
                $sale = (float) ($cell['sale_price']['value'] ?? 0); $cell['margin_before_fees'] = round($sale - $rate('t_carrier_pkg_svc', $cell['fk_id_car_pkg_svc']['value']) - $rate('t_packings', $cell['fk_id_packing']['value']), 2);      // read-only: Sale − carrier − packing
                $m[$mp->id] = $cell;
            }
            $rows[] = ['id' => (int) $c->id, 'key' => 'v' . $c->id, 'sku' => $c->SKU_VENDOR, 'name' => $c->product_name_vendor, 'name_from_parent' => trim((string) $c->product_name_vendor) === '', 'colour' => $c->f_color, 'size' => $c->f_size, 'sale_price' => (float) $c->sale_price,
                'out_of_stock' => (int) $c->f_out_of_stock, 'landing_price' => (float) $c->landing_price, 'stock' => (int) $c->stock, 'marketplaces' => $m];
        }
        return ['parent' => ['id' => (int) $p->id, 'sku' => $p->SKU_VENDOR, 'name' => $p->product_name_vendor, 'landing_price' => (float) $p->landing_price], 'marketplaces' => $mps, 'rows' => $rows,
            'colours' => DB::table('sys_field_options')->where('list_code', 'VARIANT_COLOUR')->where('f_active', 1)->orderBy('sort')->pluck('label', 'value'), 'sizes' => DB::table('sys_field_options')->where('list_code', 'VARIANT_SIZE')->where('f_active', 1)->orderBy('sort')->pluck('label', 'value'),
            'presets' => DB::table('t_carrier_pkg_svc')->where('f_active', 1)->orderBy('pkg_svc_name')->get(['id', 'pkg_svc_name as name', 'rate']), 'packings' => DB::table('t_packings')->where('f_active', 1)->orderBy('packing_name')->get(['id', 'packing_name as name', 'packing_rate as rate']),
            'can_edit' => Perm::has($u, 'stock.variations')];
    }

    /** Save the grid. Returns "n created · n saved · n skipped" with the first error of each skipped card. */
    public static function save(int $parentId, array $cards, ?object $u): array
    {
        Perm::need($u, 'stock.variations'); $p = self::parent($parentId); $created = 0; $saved = 0; $errors = [];
        foreach (array_values($cards) as $i => $card) {
            try { DB::transaction(function () use ($p, $card, $u, &$created, &$saved) {
                $sku = strtoupper(trim((string) ($card['sku'] ?? ''))); if ($sku === '') throw new \InvalidArgumentException('Child SKU is required.');
                $id = preg_match('/^v(\d+)$/', (string) ($card['key'] ?? ''), $m) ? (int) $m[1] : 0;
                $child = $id ? DB::table('t_product_vendors')->where('id', $id)->where('f_id_parent', $p->id)->where('f_parent', 3)->first() : DB::table('t_product_vendors')->where('f_id_parent', $p->id)->where('f_parent', 3)->where('f_active', 1)->where('SKU_VENDOR', $sku)->first();
                if ($id && ! $child) throw new \InvalidArgumentException("Child #$id is not a child of {$p->SKU_VENDOR}.");
                if (DB::table('t_product_vendors')->where('fk_id_ent', Context::ent())->where('SKU_VENDOR', $sku)->when($child, fn ($q) => $q->where('id', '<>', $child->id))->exists()) throw new \InvalidArgumentException("$sku: " . SkuCheck::BLOCK . '.');      // R-D-2 same message as the product form
                foreach (['landing_price', 'sale_price'] as $f) if (isset($card[$f]) && $card[$f] !== '' && (! is_numeric($card[$f]) || (float) $card[$f] < 0)) throw new \InvalidArgumentException(ucfirst(str_replace('_', ' ', $f)) . ' must be a number of 0 or more.');
                $base = ['SKU_VENDOR' => $sku, 'product_name_vendor' => trim((string) ($card['name'] ?? '')) ?: ($p->product_name_vendor ?: $sku), 'f_color' => trim((string) ($card['colour'] ?? '')) ?: null, 'f_size' => trim((string) ($card['size'] ?? '')) ?: null,
                    'sale_price' => round((float) ($card['sale_price'] ?? 0), 2), 'f_out_of_stock' => ! empty($card['out_of_stock']) ? 1 : 0, 'landing_price' => trim((string) ($card['landing_price'] ?? '')) === '' ? 0 : round((float) $card['landing_price'], 4)];      // empty landing price → 0 (the settlement then uses the parent's)
                if ($child) { DB::table('t_product_vendors')->where('id', $child->id)->update($base); $cid = (int) $child->id; Audit::log('t_product_vendors', $cid, 'UPDATE', $base + ['variation of' => $p->SKU_VENDOR]); $saved++; }
                else {      // a new child: the card's values + the parent's vendor (else 21) and type; never stock
                    $cid = (int) DB::table('t_product_vendors')->insertGetId(RecordService::fillNotNull('t_product_vendors', $base + ['fk_id_ent' => Context::ent(), 'f_parent' => 3, 'f_id_parent' => $p->id, 'fk_id_vendor' => $p->fk_id_vendor ?: 21, 'fk_id_product_type' => $p->fk_id_product_type, 'e_product_type' => $p->e_product_type,
                        'f_active' => 1, 'stock' => 0, 'product_description' => null, 'created_by' => $u->id ?? null, 'd_created' => date('Y-m-d'), 'hs_code' => $card['hs_code'] ?? $p->hs_code, 'weight' => $card['weight'] ?? $p->weight]));
                    Audit::log('t_product_vendors', $cid, 'INSERT', $base + ['variation of' => $p->SKU_VENDOR]); $created++;
                }
                foreach ((array) ($card['marketplaces'] ?? []) as $mp => $vals) {      // only what the child overrides is stored; an empty value goes back to the parent's
                    $mp = (int) $mp; if (! $mp || ! DB::table('cat_marketplaces')->where('id', $mp)->exists()) continue;
                    // a field not sent stays as it is; an empty one goes back to the parent's
                    $pr = []; foreach (self::MP_FIELDS as $f) { if (! array_key_exists($f, (array) $vals)) continue; $v = $vals[$f]; if ($v !== null && $v !== '' && ! is_numeric($v)) throw new \InvalidArgumentException(str_replace('_', ' ', ucfirst($f)) . ' must be a number.'); $pr[$f] = $v === null || $v === '' ? null : round((float) $v, 2); }
                    $sh = []; foreach (self::SHIP_FIELDS as $f) if (array_key_exists($f, (array) $vals)) $sh[$f] = (int) $vals[$f] ?: null;
                    if ($pr && (array_filter($pr, fn ($x) => $x !== null) || DB::table('t_variation_marketplace_prices')->where(['fk_id_variation' => $cid, 'fk_id_marketplace' => $mp])->exists()))
                        DB::table('t_variation_marketplace_prices')->updateOrInsert(['fk_id_variation' => $cid, 'fk_id_marketplace' => $mp], $pr + ['f_active' => 1, 'created_by' => $u->id ?? null, 'updated_at' => now()]);
                    if ($sh && (array_filter($sh) || DB::table('t_variation_marketplace_shipping')->where(['fk_id_variation' => $cid, 'fk_id_marketplace' => $mp])->exists()))
                        DB::table('t_variation_marketplace_shipping')->updateOrInsert(['fk_id_variation' => $cid, 'fk_id_marketplace' => $mp], $sh + ['f_active' => 1, 'updated_at' => now()]);
                    if (array_key_exists('sale_price', $vals) || array_key_exists('raw_price', $vals)) { $eff = self::effective($cid, $p->id, $mp); if ($eff['sale'] !== null) { $old = PriceService::on($cid, $mp, date('Y-m-d'));      // a changed child price opens its price period (R-D-30)
                        if (! $old || round((float) $old->sale_price, 2) !== round($eff['sale'], 2) || round((float) $old->raw_price, 2) !== round($eff['raw'], 2)) { $same = DB::table('t_product_inventory_change_log')->where(['fk_product_id' => $cid, 'fk_id_marketplace' => $mp, 'change_type' => 'price_change', 'from_date' => date('Y-m-d')])->first();
                            if ($same) DB::table('t_product_inventory_change_log')->where('id', $same->id)->update(['new_price' => $eff['sale'], 'new_raw_price' => $eff['raw']]); else { PriceService::set($cid, $mp, $eff['raw'], max(0, $eff['sale']), date('Y-m-d')); DB::table('t_product_marketplace_prices')->where('fk_id_product', $cid)->where('fk_id_marketplace', $mp)->delete(); } } } }      // the period only – the child's row stays in the variation tables
                }
            }); } catch (\Throwable $e) { $errors[] = 'Card ' . ($i + 1) . ' (' . ($card['sku'] ?? '—') . '): ' . ($e instanceof \InvalidArgumentException ? $e->getMessage() : 'could not be saved'); if (! $e instanceof \InvalidArgumentException) report($e); }
        }
        return ['message' => "$created created · $saved saved · " . count($errors) . ' skipped', 'created' => $created, 'saved' => $saved, 'skipped' => count($errors), 'errors' => $errors];
    }

    /** The child's own value, else the parent's, for one marketplace. */
    public static function effective(int $childId, int $parentId, int $mp): array
    {
        $c = DB::table('t_variation_marketplace_prices')->where(['fk_id_variation' => $childId, 'fk_id_marketplace' => $mp, 'f_active' => 1])->first(); $p = DB::table('t_product_marketplace_prices')->where(['fk_id_product' => $parentId, 'fk_id_marketplace' => $mp])->first();
        $v = fn ($f) => $c && $c->$f !== null ? (float) $c->$f : ($p ? (float) $p->$f : null);
        return ['raw' => $v('raw_price') ?? 0.0, 'sale' => $v('sale_price'), 'list' => $v('list_price'), 'fee' => $v('marketplace_fee')];
    }

    /** "Copy parent prices to all children": every child gets the parent's marketplace values as its own. */
    public static function copyParentPrices(int $parentId, ?object $u): array
    {
        Perm::need($u, 'stock.variations'); $p = self::parent($parentId); $n = 0;
        $pp = DB::table('t_product_marketplace_prices')->where('fk_id_product', $parentId)->get(); $ps = DB::table('t_product_marketplace_shipping')->where('fk_id_product', $parentId)->get()->keyBy('fk_id_marketplace');
        foreach (self::children($parentId) as $c) { foreach ($pp as $r) {
            DB::table('t_variation_marketplace_prices')->updateOrInsert(['fk_id_variation' => $c->id, 'fk_id_marketplace' => $r->fk_id_marketplace], ['raw_price' => $r->raw_price, 'sale_price' => $r->sale_price, 'list_price' => $r->list_price, 'marketplace_fee' => $r->marketplace_fee, 'f_active' => 1, 'updated_at' => now()]);
            if (isset($ps[$r->fk_id_marketplace])) DB::table('t_variation_marketplace_shipping')->updateOrInsert(['fk_id_variation' => $c->id, 'fk_id_marketplace' => $r->fk_id_marketplace], ['fk_id_car_pkg_svc' => $ps[$r->fk_id_marketplace]->fk_id_car_pkg_svc, 'fk_id_packing' => $ps[$r->fk_id_marketplace]->fk_id_packing, 'f_active' => 1, 'updated_at' => now()]); }
            Audit::log('t_product_vendors', (int) $c->id, 'UPDATE', ['copied parent prices from' => $p->SKU_VENDOR]); $n++; }
        return ['message' => "Parent prices copied to $n child(ren)."];
    }

    /** Remove a child (soft): its marketplace rows go with it. */
    public static function remove(int $parentId, int $childId, ?object $u): array
    {
        Perm::need($u, 'stock.variations'); self::parent($parentId);
        $c = DB::table('t_product_vendors')->where('id', $childId)->where('f_id_parent', $parentId)->where('f_parent', 3)->first() ?? throw new \InvalidArgumentException('Child not found.');
        if ((int) $c->stock !== 0) throw new \InvalidArgumentException("{$c->SKU_VENDOR} still has {$c->stock} unit(s) in stock. Move or adjust them first.");
        DB::table('t_product_vendors')->where('id', $childId)->update(['f_active' => 0]); foreach (['t_variation_marketplace_prices', 't_variation_marketplace_shipping'] as $t) DB::table($t)->where('fk_id_variation', $childId)->update(['f_active' => 0]);
        Audit::log('t_product_vendors', $childId, 'DELETE', ['variation of' => $parentId]); return ['message' => "{$c->SKU_VENDOR} removed (it can be brought back from the recycle bin)."];
    }

    /** Duplicate child: a new card pre-filled from a child (never stock, lots or ids) with the next free SKU suffix. */
    public static function duplicate(int $parentId, int $childId, ?object $u): array
    {
        Perm::need($u, 'stock.variations'); self::parent($parentId);
        $c = DB::table('t_product_vendors')->where('id', $childId)->where('f_id_parent', $parentId)->first() ?? throw new \InvalidArgumentException('Child not found.');
        $root = preg_replace('/-\d+$/', '', (string) $c->SKU_VENDOR); $n = 2; while (DB::table('t_product_vendors')->where('fk_id_ent', Context::ent())->where('SKU_VENDOR', "$root-$n")->exists()) $n++;
        $mps = []; foreach (DB::table('t_variation_marketplace_prices')->where('fk_id_variation', $childId)->where('f_active', 1)->get() as $r) $mps[$r->fk_id_marketplace] = array_intersect_key((array) $r, array_flip(self::MP_FIELDS));
        foreach (DB::table('t_variation_marketplace_shipping')->where('fk_id_variation', $childId)->where('f_active', 1)->get() as $r) $mps[$r->fk_id_marketplace] = ($mps[$r->fk_id_marketplace] ?? []) + array_intersect_key((array) $r, array_flip(self::SHIP_FIELDS));
        return ['card' => ['key' => '', 'sku' => "$root-$n", 'name' => $c->product_name_vendor, 'colour' => $c->f_color, 'size' => $c->f_size, 'sale_price' => (float) $c->sale_price, 'landing_price' => (float) $c->landing_price, 'hs_code' => $c->hs_code, 'weight' => $c->weight, 'marketplaces' => $mps],
            'lookalike' => FormAssist::lookalike((string) RecordService::formOfTable('t_product_vendors'), 'SKU_VENDOR', "$root-$n")];
    }
}
