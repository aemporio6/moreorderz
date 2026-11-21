<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Pack verification, weight, box and packing materials (R-H-29).
 * - Scan each item (product SKU, EAN, UPC or the shop's listing SKU): wrong or extra item → refused, missing item → "Short" sends it back to picking.
 * - Box suggestion: the product's own pack box for a single-SKU parcel, else the smallest box of WH_BOX (wh_box_sizes) that fits the summed volume and weight.
 * - Weight is required when the carrier preset needs it.
 * - Packing materials as stock (WH_PACKING_STOCK = Y): the box's packing product −1, reason PACKING_USE, owner DEFAULT_STOCK_OWNER, cost to the shop as packing cost.
 */
class PackService
{
    public static function scanOn(int $ent): bool { return strtoupper((string) Settings::get('WH_SCAN_CONFIRM_PACK', $ent, 'Y')) === 'Y'; }
    public static function stockOn(int $ent): bool { return strtoupper((string) Settings::get('WH_PACKING_STOCK', $ent, 'N')) === 'Y'; }

    /** The open lines of one order with the codes that identify each item. */
    public static function lines(int $ent, string $orderNo, int $branch): array
    {
        return DB::table('t_sales as s')->leftJoin('t_product_vendors as p', 'p.id', '=', 's.fk_product_id')->where('s.fk_id_ent', $ent)->where('s.order_no', $orderNo)->where('s.fk_id_branch', $branch)
            ->where('s.status', 'Pending')->where('s.sale_source', 'active')->orderBy('s.id')
            ->whereNotExists(fn ($q) => $q->from('wh_parcel_lines as pl')->join('wh_parcels as pp', 'pp.id', '=', 'pl.fk_id_parcel')->whereColumn('pl.fk_id_sale', 's.id')->whereNotIn('pp.status', ['VOID', 'CANCELLED']))
            ->get(['s.id as sale', 's.fk_product_id as product', 's.sku as listing_sku', 's.qty', 's.wh_status', 'p.SKU_VENDOR as sku', 'p.product_name_vendor as name', 'p.ean', 'p.upc', 'p.fk_id_pack_box', 'p.pack_weight_g', 'p.pack_l_mm', 'p.pack_w_mm', 'p.pack_h_mm', 'p.unit_gross_weight'])
            ->map(fn ($r) => (array) $r)->all();
    }

    /** Which product a scanned code is (by product SKU, EAN, UPC or listing SKU), or null. */
    public static function productOf(string $code, array $lines): ?int
    {
        $c = mb_strtoupper(trim($code));
        foreach ($lines as $l) foreach (['sku', 'ean', 'upc', 'listing_sku'] as $k) if ($c !== '' && mb_strtoupper(trim((string) $l[$k])) === $c) return (int) $l['product'];
        return null;
    }

    /** Scans against the order: ok, wrong codes, extra units, missing units per product. */
    public static function verify(array $lines, array $scans): array
    {
        $need = []; $name = [];
        foreach ($lines as $l) { $need[(int) $l['product']] = ($need[(int) $l['product']] ?? 0) + (int) $l['qty']; $name[(int) $l['product']] = $l['sku']; }
        $got = []; $wrong = [];
        foreach ($scans as $s) { $s = trim((string) $s); if ($s === '') continue; $p = self::productOf($s, $lines); if ($p === null) $wrong[] = $s; else $got[$p] = ($got[$p] ?? 0) + 1; }
        $extra = []; $missing = [];
        foreach ($need as $p => $q) { $g = $got[$p] ?? 0; if ($g > $q) $extra[$name[$p]] = $g - $q; if ($g < $q) $missing[$name[$p]] = $q - $g; }
        return ['ok' => ! $wrong && ! $extra && ! $missing, 'wrong' => $wrong, 'extra' => $extra, 'missing' => $missing, 'scanned' => $got];
    }

    public static function refusal(array $v): string
    {
        $m = [];
        if ($v['wrong']) $m[] = 'Wrong item: ' . implode(', ', $v['wrong']) . ' is not in this order';
        foreach ($v['extra'] as $sku => $n) $m[] = "Extra item: $n × $sku more than ordered";
        foreach ($v['missing'] as $sku => $n) $m[] = "Missing: $n × $sku – scan it or press Short";
        return implode('. ', $m) . '.';
    }

    /** Box and weight suggestion. Returns box id (or null), weight in grams (or null) and why. */
    public static function suggest(int $ent, array $lines): array
    {
        $boxes = DB::table('wh_box_sizes')->whereIn('fk_id_ent', [$ent, 0])->where('f_active', 1)->get()->sortBy(fn ($b) => (int) $b->inner_l_mm * (int) $b->inner_w_mm * (int) $b->inner_h_mm)->values();
        $w = 0; $vol = 0; $known = true; $units = 0;
        foreach ($lines as $l) {
            $q = (int) $l['qty']; $units += $q;
            $g = $l['pack_weight_g'] !== null ? (int) $l['pack_weight_g'] : ($l['unit_gross_weight'] !== null && (float) $l['unit_gross_weight'] > 0 ? (int) round((float) $l['unit_gross_weight'] * 1000) : null);
            if ($g === null) $known = false; else $w += $g * $q;
            if ($l['pack_l_mm'] && $l['pack_w_mm'] && $l['pack_h_mm']) $vol += (int) $l['pack_l_mm'] * (int) $l['pack_w_mm'] * (int) $l['pack_h_mm'] * $q; else $known = false;
        }
        $skus = array_unique(array_column($lines, 'product'));
        $box = null; $why = 'No box fits the parcel – choose one.';
        if (count($skus) === 1 && ! empty($lines[0]['fk_id_pack_box'])) { $box = $boxes->firstWhere('id', (int) $lines[0]['fk_id_pack_box']); $why = 'The product\'s own pack box.'; }
        if (! $box && $vol > 0) {
            $box = $boxes->first(fn ($b) => (int) $b->inner_l_mm * (int) $b->inner_w_mm * (int) $b->inner_h_mm >= $vol && ($w === 0 || (int) $b->max_weight_g >= $w));
            if ($box) $why = 'Smallest box that fits ' . round($vol / 1000) . ' cm³ and ' . $w . ' g.';
        }
        $weight = $w > 0 ? $w + (int) ($box->tare_g ?? 0) : null;
        return ['box' => $box ? (int) $box->id : null, 'box_code' => $box->box_code ?? null, 'weight_g' => $weight, 'weight_exact' => $known && $w > 0, 'why' => $why, 'units' => $units];
    }

    /** Does the carrier preset need the parcel weight? */
    public static function needsWeight(?int $presetId): bool { return $presetId && (bool) DB::table('t_carrier_pkg_svc')->where('id', $presetId)->value('needs_weight'); }

    /** "Short" at pack: the missing units go from Dispatch to Missing and the line goes back to picking. */
    public static function short(int $ent, string $orderNo, int $branch, int $productId, int $qty): array
    {
        if ($qty <= 0) throw new \InvalidArgumentException('Say how many units are missing.');
        return DB::transaction(function () use ($ent, $orderNo, $branch, $productId, $qty) {
            $left = $qty; $sales = [];
            foreach (self::lines($ent, $orderNo, $branch) as $l) {
                if ((int) $l['product'] !== $productId || $left <= 0) continue;
                $r = DB::table('wh_reservations')->where('fk_id_sale', $l['sale'])->whereNotIn('status', ['RELEASED'])->lockForUpdate()->first();
                if (! $r || (int) $r->qty_picked <= 0) continue;
                $n = min($left, (int) $r->qty_picked); $left -= $n;
                StockService::transfer($ent, $productId, $n, StockService::systemLocation($ent, 'DISPATCH'), StockService::systemLocation($ent, 'MISSING'), "Short at pack: order $orderNo",
                    ['rule' => $r->owner_rule === 'PRIVATE' ? 'PRIVATE' : 'POOL', 'owner' => $r->fk_id_owner_seg ?: null, 'source_type' => 'PACK_SHORT', 'source_id' => $l['sale']]);
                DB::table('wh_reservations')->where('id', $r->id)->update(['qty_picked' => (int) $r->qty_picked - $n, 'status' => 'OPEN', 'updated_at' => now()]);
                DB::table('t_sales')->where('id', $l['sale'])->update(['wh_status' => 'RESERVED']); $sales[] = (int) $l['sale'];
            }
            if ($left === $qty) throw new \InvalidArgumentException('Nothing of this product is picked on this order.');
            $sku = DB::table('t_product_vendors')->where('id', $productId)->value('SKU_VENDOR');
            EventBus::fire('PACK_SHORT', 't_sales', $sales[0], ['order' => $orderNo, 'qty' => $qty - $left], $ent);
            return ['back_to_picking' => $qty - $left, 'message' => ($qty - $left) . " × $sku back to picking; the missing units are in Missing."];
        });
    }

    /** One box used from stock (WH_PACKING_STOCK = Y). Returns the cost booked, or null. */
    public static function useBox(int $ent, int $parcelId, ?int $boxId): ?float
    {
        if (! $boxId || ! self::stockOn($ent)) return null;
        $prod = (int) DB::table('wh_box_sizes')->where('id', $boxId)->value('fk_id_packing_product'); if (! $prod) return null;
        $loc = DB::table('fin_lot_balances as b')->join('fin_lots as l', 'l.id', '=', 'b.fk_id_lot')->where('l.fk_id_product', $prod)->where('l.company_id', $ent)->where('b.qty', '>', 0)
            ->groupBy('b.fk_id_location')->orderByRaw('SUM(b.qty) DESC')->value('b.fk_id_location');
        if (! $loc) throw new \InvalidArgumentException('No packing stock of this box left – receive more boxes, or switch packing stock off.');
        $p = DB::table('wh_parcels')->where('id', $parcelId)->first();
        $owner = Settings::get('DEFAULT_STOCK_OWNER', $ent);
        $r = StockService::adjust($ent, $prod, (int) $loc, -1, 'PACKING_USE', ['type' => 'packing_use', 'source_type' => 'PACKING_USE', 'source_id' => $parcelId, 'owner' => $owner ? (int) $owner : null, 'expense' => '5200', 'memo' => "Packing for parcel {$p->parcel_code} (shop {$p->fk_id_branch})"]);
        $cost = round(-(float) $r['value'], 2);
        DB::table('wh_parcels')->where('id', $parcelId)->update(['packing_cost' => $cost]);
        return $cost;
    }
}
