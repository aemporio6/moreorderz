<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Owner 26-11 23:14 (approved design) – the Inventory screen, the heart of the business, the way large sellers run it:
 * one line per product (photo · SKU · ASIN · parent › children) with every state of its stock read from the ledger – warehouse (sellable),
 * FBA per shop / country (Amazon reports), in transit, inbound on open POs, reserved for orders, damaged – who owns it (company / partner /
 * investor, units and value at FIFO cost), where it may be sold (Pool marketplaces, Private shops), its listings and prices, 30-day sales,
 * days of cover and the age of the oldest unit. story() is the product's whole trail. Reads only; every change goes through the ledger screens.
 * Who sees what: a person limited to shops (Perm::shops) sees only the products visible or listed on his shops, and only his shops' rows.
 */
class InventoryHub
{
    public const SELLABLE_NOT = ['TRANSIT', 'SHIPPED', 'DAMAGED', 'DISPATCH'];

    /** The products the person may see (null = all of the company). */
    private static function scope(object $u, int $ent): ?array
    {
        $shops = Perm::shops($u); if ($shops === null) return null;
        if (! $shops) return [];
        $mps = DB::table('t_branches')->whereIn('id', $shops)->pluck('fk_id_marketplace')->filter()->unique()->all();
        return DB::table('t_product_listings')->where('fk_id_ent', $ent)->whereIn('fk_id_branch', $shops)->pluck('fk_id_product')
            ->merge(DB::table('t_product_visibility')->whereIn('fk_id_branch', $shops)->pluck('fk_id_product'))
            ->merge(DB::table('t_product_visibility')->whereIn('fk_id_marketplace', $mps ?: [0])->where('visibility_mode', 'pool')->pluck('fk_id_product'))
            ->map(fn ($x) => (int) $x)->unique()->values()->all();
    }

    public static function page(int $ent, object $u, array $f): array
    {
        $scope = self::scope($u, $ent); $per = 50; $pg = max(1, (int) ($f['page'] ?? 1));
        $q = DB::table('t_product_vendors as p')->where('p.fk_id_ent', $ent)->where('p.f_active', 1)->whereNull('p.fk_id_merged_into');
        if ($scope !== null) $q->whereIn('p.id', $scope ?: [0]);
        if (($s = trim((string) ($f['q'] ?? ''))) !== '') $q->where(fn ($w) => $w->where('p.SKU_VENDOR', 'like', "%$s%")->orWhere('p.product_name_vendor', 'like', "%$s%")
            ->orWhereIn('p.id', DB::table('t_product_listings')->where('fk_id_ent', $ent)->where(fn ($x) => $x->where('asin', $s)->orWhere('item_id', $s)->orWhere('marketplace_sku', 'like', "%$s%"))->select('fk_id_product')));
        if (! empty($f['shop'])) $q->where(fn ($w) => $w->whereIn('p.id', DB::table('t_product_listings')->where('fk_id_branch', (int) $f['shop'])->select('fk_id_product'))->orWhereIn('p.id', DB::table('t_product_visibility')->where('fk_id_branch', (int) $f['shop'])->select('fk_id_product')));
        if (! empty($f['marketplace'])) $q->whereIn('p.id', DB::table('t_product_visibility')->where('fk_id_marketplace', (int) $f['marketplace'])->select('fk_id_product'));
        if (in_array($f['mode'] ?? '', ['pool', 'private'], true)) $q->where('p.e_product_type', $f['mode']);
        if (! empty($f['owner'])) $q->whereIn('p.id', DB::table('fin_lots as l')->join('fin_lot_balances as b', 'b.fk_id_lot', '=', 'l.id')->where('b.qty', '>', 0)->where('l.fk_id_owner', $f['owner'] === 'company' ? null : (int) $f['owner'])->select('l.fk_id_product'));
        $state = (string) ($f['state'] ?? '');
        if ($state === 'in') $q->where('p.stock', '>', 0); elseif ($state === 'out') $q->where('p.stock', '<=', 0); elseif ($state === 'low') $q->where('p.stock', '>', 0)->whereColumn('p.stock', '<=', 'p.min_level')->where('p.min_level', '>', 0);
        elseif ($state === 'order') $q->whereIn('p.icc_status', ['RED', 'YELLOW']);
        elseif ($state === 'aged') $q->whereIn('p.id', DB::table('fin_lots as l')->join('fin_lot_balances as b', 'b.fk_id_lot', '=', 'l.id')->where('b.qty', '>', 0)->where('l.received_date', '<', now()->subDays(180)->toDateString())->select('l.fk_id_product'));
        if (empty($f['children'])) $q->where(fn ($w) => $w->whereNull('p.f_id_parent')->orWhere('p.f_id_parent', 0)->orWhere('p.f_parent', '<>', 3));
        $total = (clone $q)->count();
        $sort = ['stock' => 'p.stock DESC', 'sku' => 'p.SKU_VENDOR', 'name' => 'p.product_name_vendor', 'order' => "FIELD(p.icc_status,'RED','YELLOW') DESC, p.stock DESC"][$f['sort'] ?? 'stock'] ?? 'p.stock DESC';
        $rows = $q->orderByRaw($sort)->orderBy('p.id')->forPage($pg, $per)->get(['p.id', 'p.SKU_VENDOR as sku', 'p.product_name_vendor as name', 'p.product_img', 'p.f_parent', 'p.f_id_parent', 'p.e_product_type as mode', 'p.stock', 'p.min_level', 'p.sale_price', 'p.landing_price', 'p.raw_price', 'p.icc_status', 'p.icc_stock_out']);
        return ['rows' => self::fill($rows->all(), $ent, $scope !== null ? (Perm::shops($u) ?: [0]) : null), 'total' => $total, 'page' => $pg, 'per' => $per, 'summary' => $pg === 1 ? self::summary($ent, $scope) : null, 'filters' => self::filters($ent, $u)];
    }

    /** The figures of each product line, in a few grouped queries for the whole page. */
    public static function fill(array $rows, int $ent, ?array $shops): array
    {
        $ids = array_map(fn ($r) => (int) $r->id, $rows); if (! $ids) return [];
        $kids = DB::table('t_product_vendors')->whereIn('f_id_parent', $ids)->where('f_active', 1)->groupBy('f_id_parent')->selectRaw('f_id_parent p, COUNT(*) n')->pluck('n', 'p');
        $img = DB::table('t_product_vendors_images')->whereIn('fk_id_product_vendors', $ids)->orderByDesc('f_main')->orderBy('sort_no')->get(['fk_id_product_vendors as p', 'image_url', 'thumb_url'])->groupBy('p');
        $bal = DB::table('fin_lot_balances as b')->join('fin_lots as l', 'l.id', '=', 'b.fk_id_lot')->leftJoin('cat_inventory_locations as c', 'c.id', '=', 'b.fk_id_location')->whereIn('l.fk_id_product', $ids)->where('b.qty', '<>', 0)
            ->get(['l.fk_id_product as p', 'b.qty', 'l.unit_cost_gbp as cost', 'l.fk_id_owner as owner', 'l.visibility', 'l.received_date', 'c.kind', 'c.f_sellable'])->groupBy('p');
        $res = DB::table('wh_reservations')->whereIn('fk_id_product', $ids)->whereIn('status', ['OPEN', 'ALLOCATED'])->groupBy('fk_id_product')->selectRaw('fk_id_product p, SUM(qty - COALESCE(qty_picked,0)) n')->pluck('n', 'p');
        $icc = DB::table('icc_results')->whereIn('id', DB::table('icc_results')->whereIn('fk_id_product', $ids)->selectRaw('MAX(id)')->groupBy('fk_id_product'))->get(['fk_id_product as p', 'ordered', 'production', 'transit', 'daily', 'cover_days'])->keyBy('p');
        $lst = DB::table('t_product_listings as l')->join('t_branches as b', 'b.id', '=', 'l.fk_id_branch')->whereIn('l.fk_id_product', $ids)->where('l.f_active', 1)->when($shops, fn ($q) => $q->whereIn('l.fk_id_branch', $shops))
            ->get(['l.fk_id_product as p', 'l.fk_id_branch', 'b.shop_name', 'l.price', 'l.asin', 'l.item_id', 'l.marketplace_sku', 'l.status'])->groupBy('p');
        $vis = DB::table('t_product_visibility as v')->leftJoin('cat_marketplaces as m', 'm.id', '=', 'v.fk_id_marketplace')->leftJoin('t_branches as b', 'b.id', '=', 'v.fk_id_branch')->whereIn('v.fk_id_product', $ids)
            ->get(['v.fk_id_product as p', 'v.visibility_mode as mode', 'm.marketplace_short as mp', 'b.shop_name', 'v.fk_id_branch'])->groupBy('p');
        $from = now()->subDays(30)->toDateString();
        $sold = DB::table('t_sales')->whereIn('fk_product_id', $ids)->where('d_order', '>=', $from)->where(fn ($w) => $w->whereNull('status')->orWhereNotIn('status', ['Cancelled', 'CANCELLED']))->when($shops, fn ($q) => $q->whereIn('fk_id_branch', $shops))
            ->groupBy('fk_product_id')->selectRaw('fk_product_id p, SUM(qty) n, SUM(COALESCE(total_amount_gbp, total_amount)) v')->get()->keyBy('p');
        $skuMap = []; foreach ($rows as $r) { $skuMap[mb_strtoupper((string) $r->sku)] = (int) $r->id; foreach ($lst[$r->id] ?? [] as $l) if ($l->marketplace_sku) $skuMap[mb_strtoupper($l->marketplace_sku)] = (int) $r->id; }
        $fba = $skuMap ? DB::table('fin_fba_stock_snapshots as s')->join('t_branches as b', 'b.id', '=', 's.branch_id')->whereIn('s.id', DB::table('fin_fba_stock_snapshots')->whereIn('sku', array_keys($skuMap))->selectRaw('MAX(id)')->groupBy('branch_id', 'sku'))
            ->when($shops, fn ($q) => $q->whereIn('s.branch_id', $shops))->get(['s.sku', 's.fulfillable', 's.reserved', 's.inbound_working', 's.inbound_shipped', 's.inbound_receiving', 's.unfulfillable', 'b.shop_name', 's.snapshot_date']) : collect();
        $fbaBy = []; foreach ($fba as $x) { $pid = $skuMap[mb_strtoupper($x->sku)] ?? null; if ($pid) $fbaBy[$pid][] = $x; }
        $owners = [];
        $out = [];
        foreach ($rows as $r) {
            $b = $bal[$r->id] ?? collect(); $sell = 0; $tr = 0; $dmg = 0; $val = 0.0; $own = []; $oldest = null;
            foreach ($b as $x) { $k = strtoupper((string) $x->kind); $qn = (float) $x->qty; $val += $qn * (float) $x->cost;
                if (in_array($k, ['TRANSIT', 'SHIPPED'], true)) $tr += $qn; elseif ($k === 'DAMAGED' || (string) $x->f_sellable === '0') $dmg += $qn; else $sell += $qn;
                $ok = $x->owner ? (int) $x->owner : 0; $own[$ok] = ['units' => ($own[$ok]['units'] ?? 0) + $qn, 'value' => round(($own[$ok]['value'] ?? 0) + $qn * (float) $x->cost, 2)];
                if ($qn > 0 && $x->received_date && (! $oldest || $x->received_date < $oldest)) $oldest = $x->received_date; }
            foreach (array_keys($own) as $ok) $owners[$ok] ??= $ok ? PartnerService::name($ok) : 'Company';
            $reserved = (float) ($res[$r->id] ?? 0); $ic = $icc[$r->id] ?? null; $s = $sold[$r->id] ?? null; $daily = $s ? (float) $s->n / 30 : 0;
            $fb = $fbaBy[$r->id] ?? []; $fbaUnits = array_sum(array_map(fn ($x) => (int) $x->fulfillable, $fb)); $fbaIn = array_sum(array_map(fn ($x) => (int) $x->inbound_working + (int) $x->inbound_shipped + (int) $x->inbound_receiving, $fb));
            $ls = $lst[$r->id] ?? collect(); $vs = $vis[$r->id] ?? collect(); $avail = max(0, $sell - $reserved);
            $im = $img[$r->id][0] ?? null; $photo = $im ? ($im->thumb_url ?: $im->image_url) : (preg_match('~^https?://~', (string) $r->product_img) ? $r->product_img : null);
            $out[] = ['id' => (int) $r->id, 'sku' => $r->sku, 'name' => $r->name, 'photo' => $photo, 'parent' => (int) $r->f_parent === 2 || ($kids[$r->id] ?? 0) > 0, 'children' => (int) ($kids[$r->id] ?? 0), 'child_of' => (int) $r->f_id_parent ?: null,
                'asin' => $ls->pluck('asin')->filter()->unique()->values()->all(), 'mode' => $r->mode,
                'stock' => ['warehouse' => $sell, 'available' => $avail, 'reserved' => $reserved, 'transit' => $tr + ($ic ? (int) $ic->transit : 0), 'inbound' => $ic ? (int) $ic->ordered + (int) $ic->production : 0, 'damaged' => $dmg, 'fba' => $fbaUnits, 'fba_inbound' => $fbaIn, 'total' => $sell + $tr + $dmg + $fbaUnits],
                'fba' => array_map(fn ($x) => ['shop' => $x->shop_name, 'units' => (int) $x->fulfillable, 'inbound' => (int) $x->inbound_working + (int) $x->inbound_shipped + (int) $x->inbound_receiving, 'reserved' => (int) $x->reserved, 'unsellable' => (int) $x->unfulfillable, 'date' => $x->snapshot_date], $fb),
                'owners' => array_map(fn ($ok, $o) => ['id' => $ok, 'name' => $owners[$ok], 'units' => $o['units'], 'value' => $o['value']], array_keys($own), $own),
                'value' => round($val, 2), 'pool' => $vs->where('mode', 'pool')->pluck('mp')->filter()->unique()->values()->all(), 'private' => $vs->where('mode', 'private')->pluck('shop_name')->filter()->unique()->values()->all(),
                'listings' => ['count' => $ls->count(), 'shops' => $ls->pluck('shop_name')->unique()->values()->take(8)->all(), 'min' => $ls->min('price'), 'max' => $ls->max('price')],
                'sold30' => $s ? (int) $s->n : 0, 'sales30' => $s ? round((float) $s->v, 2) : 0, 'cover_days' => $daily > 0 ? (int) floor(($avail + $fbaUnits) / $daily) : null,
                'age_days' => $oldest ? (int) floor((time() - strtotime((string) $oldest)) / 86400) : null, 'icc' => $r->icc_status, 'cost' => (float) ($r->landing_price ?: $r->raw_price), 'price' => (float) $r->sale_price];
        }
        return $out;
    }

    public static function summary(int $ent, ?array $scope): array
    {
        $b = DB::table('fin_lot_balances as b')->join('fin_lots as l', 'l.id', '=', 'b.fk_id_lot')->leftJoin('cat_inventory_locations as c', 'c.id', '=', 'b.fk_id_location')->where('l.company_id', $ent)->where('b.qty', '>', 0)
            ->when($scope !== null, fn ($q) => $q->whereIn('l.fk_id_product', $scope ?: [0]));
        $units = (float) (clone $b)->sum('b.qty'); $value = (float) (clone $b)->sum(DB::raw('b.qty * l.unit_cost_gbp'));
        $transit = (float) (clone $b)->whereIn('c.kind', ['TRANSIT', 'SHIPPED'])->sum('b.qty');
        $aged = (float) (clone $b)->where('l.received_date', '<', now()->subDays(180)->toDateString())->sum(DB::raw('b.qty * l.unit_cost_gbp'));
        $p = DB::table('t_product_vendors')->where('fk_id_ent', $ent)->where('f_active', 1)->when($scope !== null, fn ($q) => $q->whereIn('id', $scope ?: [0]));
        $fba = (int) DB::table('fin_fba_stock_snapshots as s')->join('t_branches as br', 'br.id', '=', 's.branch_id')->where('br.fk_id_ent', $ent)->whereIn('s.id', DB::table('fin_fba_stock_snapshots')->selectRaw('MAX(id)')->groupBy('branch_id', 'sku'))->sum('s.fulfillable');
        return ['units' => $units, 'value' => round($value, 2), 'transit' => $transit, 'aged_value' => round($aged, 2), 'fba' => $fba, 'products' => (clone $p)->count(), 'in_stock' => (clone $p)->where('stock', '>', 0)->count(),
            'out' => (clone $p)->where('stock', '<=', 0)->count(), 'to_order' => (clone $p)->whereIn('icc_status', ['RED', 'YELLOW'])->count(), 'listings' => DB::table('t_product_listings')->where('fk_id_ent', $ent)->where('f_active', 1)->count(), 'base' => Fx::base($ent)];
    }

    public static function filters(int $ent, object $u): array
    {
        $shops = Perm::shops($u);
        return ['shops' => DB::table('t_branches as b')->leftJoin('cat_marketplaces as m', 'm.id', '=', 'b.fk_id_marketplace')->where('b.fk_id_ent', $ent)->where('b.f_active', 1)->when($shops !== null, fn ($q) => $q->whereIn('b.id', $shops ?: [0]))
                ->orderBy('m.marketplace_short')->orderBy('b.shop_name')->get(['b.id', 'b.shop_name as name', 'm.marketplace_short as mp']),
            'marketplaces' => DB::table('cat_marketplaces')->where('f_active', 1)->orderBy('marketplace_short')->get(['id', 'marketplace_short as name']),
            'owners' => DB::table('fin_lots')->where('company_id', $ent)->whereNotNull('fk_id_owner')->distinct()->pluck('fk_id_owner')->map(fn ($o) => ['id' => (int) $o, 'name' => PartnerService::name((int) $o)])->values()];
    }

    /** One product's whole trail: where it is now (per location, owner, lot), where it may be sold and listed, FBA, and every movement. */
    public static function story(int $ent, object $u, int $id): array
    {
        $scope = self::scope($u, $ent); if ($scope !== null && ! in_array($id, $scope, true)) throw new \InvalidArgumentException('This product is not on your shops.');
        $p = DB::table('t_product_vendors')->where('id', $id)->where('fk_id_ent', $ent)->first() ?? throw new \InvalidArgumentException('Product not found.');
        $line = self::fill([$p->id ? (object) ['id' => $p->id, 'sku' => $p->SKU_VENDOR, 'name' => $p->product_name_vendor, 'product_img' => $p->product_img, 'f_parent' => $p->f_parent, 'f_id_parent' => $p->f_id_parent, 'mode' => $p->e_product_type, 'stock' => $p->stock,
            'min_level' => $p->min_level, 'sale_price' => $p->sale_price, 'landing_price' => $p->landing_price, 'raw_price' => $p->raw_price, 'icc_status' => $p->icc_status, 'icc_stock_out' => $p->icc_stock_out] : null], $ent, $scope !== null ? (Perm::shops($u) ?: [0]) : null)[0];
        $where = DB::table('fin_lot_balances as b')->join('fin_lots as l', 'l.id', '=', 'b.fk_id_lot')->leftJoin('cat_inventory_locations as c', 'c.id', '=', 'b.fk_id_location')->where('l.fk_id_product', $id)->where('b.qty', '<>', 0)
            ->orderBy('c.location_name')->get(['c.id as loc', 'c.location_name', 'c.kind', 'l.lot_code', 'l.fk_id_owner', 'l.visibility', 'l.received_date', 'l.unit_cost_gbp', 'b.qty'])
            ->map(fn ($x) => ['location' => $x->location_name ?: 'Location ' . $x->loc, 'kind' => $x->kind, 'lot' => $x->lot_code, 'owner' => $x->fk_id_owner ? PartnerService::name((int) $x->fk_id_owner) : 'Company', 'mode' => $x->visibility, 'received' => $x->received_date, 'cost' => (float) $x->unit_cost_gbp, 'qty' => (float) $x->qty])->all();
        $moves = DB::table('t_stock_movements as m')->leftJoin('cat_inventory_locations as c', 'c.id', '=', 'm.fk_id_location')->leftJoin('t_users as u', 'u.id', '=', 'm.created_by')->where('m.fk_id_product', $id)->orderByDesc('m.id')->limit(60)
            ->get(['m.created_at', 'm.movement_type', 'm.movement_code', 'm.qty_change', 'm.stock_after', 'c.location_name', 'm.fk_id_owner', 'm.source_type', 'm.source_id', 'm.notes', 'u.display_name as by'])
            ->map(fn ($x) => ['at' => $x->created_at, 'what' => $x->movement_code ?: $x->movement_type, 'qty' => (float) $x->qty_change, 'after' => $x->stock_after !== null ? (float) $x->stock_after : null, 'location' => $x->location_name, 'owner' => $x->fk_id_owner ? PartnerService::name((int) $x->fk_id_owner) : null, 'source' => trim(($x->source_type ?? '') . ' ' . ($x->source_id ?? '')), 'notes' => $x->notes, 'by' => $x->by])->all();
        $shops = Perm::shops($u);
        $listings = DB::table('t_product_listings as l')->join('t_branches as b', 'b.id', '=', 'l.fk_id_branch')->leftJoin('cat_marketplaces as m', 'm.id', '=', 'b.fk_id_marketplace')->where('l.fk_id_product', $id)->when($shops !== null, fn ($q) => $q->whereIn('l.fk_id_branch', $shops ?: [0]))
            ->get(['l.id', 'b.shop_name', 'm.marketplace_short as mp', 'l.price', 'l.status', 'l.asin', 'l.item_id', 'l.marketplace_sku', 'l.listing_url'])
            ->map(fn ($x) => (array) $x + ['offered' => (function () use ($x) { try { return ListingQty::qty(DB::table('t_product_listings')->where('id', $x->id)->first()); } catch (\Throwable $e) { return null; } })()])->all();
        $children = DB::table('t_product_vendors')->where('f_id_parent', $id)->where('f_active', 1)->limit(200)->get(['id', 'SKU_VENDOR as sku', 'product_name_vendor as name', 'stock']);
        return ['product' => $line, 'where' => $where, 'moves' => $moves, 'listings' => $listings, 'children' => $children, 'parent' => $p->f_id_parent ? DB::table('t_product_vendors')->where('id', $p->f_id_parent)->first(['id', 'SKU_VENDOR as sku', 'product_name_vendor as name']) : null];
    }
}
