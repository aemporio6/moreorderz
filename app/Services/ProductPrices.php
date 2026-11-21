<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Price per marketplace (R-D-22, K6) with shipping per marketplace (R-D-23): Sale = max(0, Raw + carrier preset rate + packing rate), rates read from the numeric
 * fields (never from option text), the fee / margin line and the price guard (floor = FIFO cost + fee + packing fee + storage fee + PRICE_TARGET_MARGIN → PRICE_BELOW_FLOOR).
 */
class ProductPrices
{
    private static function m(mixed $v): float { return round(max(0.0, (float) $v), 2); }

    public static function sale(float $raw, float $carrier, float $packing): float { return round(max(0.0, $raw + $carrier + $packing), 2); }

    /** Carrier and packing rates of a marketplace row (today's rate of the chosen preset / packing; none or 0 = "rate not set"). */
    public static function rates(?int $presetId, ?int $packingId): array
    {
        $c = $presetId ? DB::table('t_carrier_pkg_svc')->where('id', $presetId)->first(['id', 'pkg_svc_name', 'rate']) : null; $p = $packingId ? DB::table('t_packings')->where('id', $packingId)->first(['id', 'packing_name', 'packing_rate']) : null;
        $cr = $c ? Carriers::rateOn('CARRIER', (int) $c->id, date('Y-m-d'), (float) $c->rate) : 0.0; $pr = $p ? Carriers::rateOn('PACKING', (int) $p->id, date('Y-m-d'), (float) $p->packing_rate) : 0.0;
        $warn = []; if (! $c) $warn[] = 'No carrier preset chosen – carrier counts as £0.00.'; elseif ($cr <= 0) $warn[] = "The rate of carrier preset \"{$c->pkg_svc_name}\" is not set (0) – set it under Carriers.";
        return ['carrier' => round($cr, 2), 'packing' => round($pr, 2), 'carrier_name' => $c->pkg_svc_name ?? null, 'packing_name' => $p->packing_name ?? null, 'warnings' => $warn];
    }

    /** FIFO cost: the unit cost of the oldest lot that still has units, else the landing price. */
    public static function fifo(int $productId): float
    {
        $c = DB::table('fin_lots')->where('fk_id_product', $productId)->where('qty_left', '>', 0)->orderBy('received_date')->orderBy('id')->value('unit_cost_gbp');
        return round((float) ($c ?? DB::table('t_product_vendors')->where('id', $productId)->value('landing_price') ?? 0), 4);
    }

    /** The lines the form shows, the margin and the guard for one marketplace row. */
    public static function figures(int $productId, array $r): array
    {
        $rates = self::rates((int) ($r['fk_id_car_pkg_svc'] ?? 0) ?: null, (int) ($r['fk_id_packing'] ?? 0) ?: null);
        $raw = (float) ($r['raw_price'] ?? 0); $sale = (float) ($r['sale_price'] ?? 0); $fee = (float) ($r['marketplace_fee'] ?? 0); $pf = (float) ($r['packing_fee'] ?? 0); $sf = (float) ($r['storage_fee'] ?? 0);
        $fifo = self::fifo($productId); $tpl = ThreePlTariff::feeCost((int) Context::ent(), (int) ($r['fk_id_marketplace'] ?? 0), $pf, $sf, 1); $margin = round($sale - $fee - $fifo - $tpl, 2); $target = (float) Settings::get('PRICE_TARGET_MARGIN', Context::ent(), 0);
        $floor = round($fifo + $fee + $tpl + $target, 2); $f = fn ($x) => '£' . number_format($x, 2);
        return ['rates' => $rates, 'formula_sale' => self::sale($raw, $rates['carrier'], $rates['packing']), 'fifo' => $fifo, 'margin' => $margin, 'margin_pct' => $sale > 0 ? round($margin / $sale * 100, 1) : null, 'floor' => $floor, 'below_floor' => $sale > 0 && $sale < $floor,
            'line_sale' => 'Sale = Raw ' . $f($raw) . ' + Carrier ' . $f($rates['carrier']) . ' + Packing ' . $f($rates['packing']), 'line_fee' => 'Fee ' . $f($fee) . ' · Margin after fees ' . $f($margin) . ($sale > 0 ? ' (' . round($margin / $sale * 100, 1) . '%)' : ''),
            'line_ship' => 'Carrier ' . $f($rates['carrier']) . ' | Packing ' . $f($rates['packing']), 'line_3pl' => '3PL fees ' . $f($tpl) . ' a unit (packing ' . $f($pf) . ', storage ' . $f($sf) . ')'];
    }

    public static function page(int $productId, ?object $u): array
    {
        Perm::need($u, 'stock.prices'); $lc = Registry::labelColumn('cat_marketplaces') ?: 'id'; $name = DB::raw("COALESCE(NULLIF(marketplace_short, ''), NULLIF($lc, ''), id) as name");
        $pr = DB::table('t_product_marketplace_prices')->where('fk_id_product', $productId)->get()->keyBy('fk_id_marketplace'); $sh = DB::table('t_product_marketplace_shipping')->where('fk_id_product', $productId)->get()->keyBy('fk_id_marketplace');
        $rows = []; $dft = fn ($mp) => ThreePlTariff::defaults((int) Context::ent(), (int) $mp);      // a row with no fees yet shows the company 3PL tariff (R-D-23)
        foreach (DB::table('cat_marketplaces')->where(fn ($q) => $q->where('f_active', 1)->orWhereIn('id', $pr->keys()->all() ?: [0]))->orderBy('marketplace_short')->get(['id', $name]) as $m) {
            $p = $pr[$m->id] ?? null; $s = $sh[$m->id] ?? null;
            $r = ['fk_id_marketplace' => (int) $m->id, 'marketplace' => $m->name, 'saved' => (bool) $p, 'raw_price' => (float) ($p->raw_price ?? 0), 'sale_price' => (float) ($p->sale_price ?? 0), 'list_price' => (float) ($p->list_price ?? 0), 'marketplace_fee' => (float) ($p->marketplace_fee ?? 0),
                'fk_id_car_pkg_svc' => $s->fk_id_car_pkg_svc ?? null, 'fk_id_packing' => $s->fk_id_packing ?? null, 'packing_fee' => (float) ($s ? $s->packing_fee : $dft($m->id)['packing_fee']), 'storage_fee' => (float) ($s ? $s->storage_fee : $dft($m->id)['storage_fee']),
                'fee_source' => $s ? ($s->fee_source ?: 'TYPED') : ($dft($m->id)['from_tariff'] ? 'TARIFF' : null)];
            $rows[] = $r + self::figures($productId, $r);
        }
        return ['rows' => $rows, 'presets' => DB::table('t_carrier_pkg_svc')->where('f_active', 1)->orderBy('pkg_svc_name')->get(['id', 'pkg_svc_name as name', 'rate']), 'packings' => DB::table('t_packings')->where('f_active', 1)->orderBy('packing_name')->get(['id', 'packing_name as name', 'packing_rate as rate']),
            'fifo' => self::fifo($productId), 'target' => (float) Settings::get('PRICE_TARGET_MARGIN', Context::ent(), 0), 'can_edit' => Perm::has($u, 'stock.setPrice')];
    }

    /**
     * Save one marketplace row. Sale is worked out again only when Raw, Carrier or Packing was changed by the user (and Sale was not typed in the same save) – never on opening,
     * so a saved manual Sale stays. A change of Raw or Sale opens a price period (R-D-30). A Sale below the floor saves with a warning and the task PRICE_BELOW_FLOOR.
     */
    public static function save(int $productId, int $mp, array $d, ?object $u): array
    {
        Perm::need($u, 'stock.setPrice');
        if (! DB::table('t_product_vendors')->where('id', $productId)->where('fk_id_ent', Context::ent())->exists()) throw new \InvalidArgumentException('Product not found.');
        if (! DB::table('cat_marketplaces')->where('id', $mp)->exists()) throw new \InvalidArgumentException('Choose the marketplace.');
        $oldP = DB::table('t_product_marketplace_prices')->where('fk_id_product', $productId)->where('fk_id_marketplace', $mp)->first(); $oldS = DB::table('t_product_marketplace_shipping')->where('fk_id_product', $productId)->where('fk_id_marketplace', $mp)->first();
        $num = function (string $k, $old) use ($d) { if (! array_key_exists($k, $d) || $d[$k] === '' || $d[$k] === null) return $old; if (! is_numeric($d[$k])) throw new \InvalidArgumentException(str_replace('_', ' ', ucfirst($k)) . ' must be a number.'); return round((float) $d[$k], 2); };
        $raw = $num('raw_price', (float) ($oldP->raw_price ?? 0)); $preset = array_key_exists('fk_id_car_pkg_svc', $d) ? ((int) $d['fk_id_car_pkg_svc'] ?: null) : ($oldS->fk_id_car_pkg_svc ?? null); $packing = array_key_exists('fk_id_packing', $d) ? ((int) $d['fk_id_packing'] ?: null) : ($oldS->fk_id_packing ?? null);
        if ($preset && ! DB::table('t_carrier_pkg_svc')->where('id', $preset)->exists()) throw new \InvalidArgumentException('Choose a carrier preset from the list.');
        if ($packing && ! DB::table('t_packings')->where('id', $packing)->exists()) throw new \InvalidArgumentException('Choose a packing from the list.');
        $drivers = (float) $raw !== (float) ($oldP->raw_price ?? 0) || (int) $preset !== (int) ($oldS->fk_id_car_pkg_svc ?? 0) || (int) $packing !== (int) ($oldS->fk_id_packing ?? 0) || ! $oldP;
        $typedSale = array_key_exists('sale_price', $d) && $d['sale_price'] !== '' && $d['sale_price'] !== null && (! $oldP || round((float) $d['sale_price'], 2) !== round((float) $oldP->sale_price, 2));
        $rates = self::rates($preset, $packing);
        $sale = $typedSale ? max(0.0, (float) $num('sale_price', 0)) : ($drivers ? self::sale($raw, $rates['carrier'], $rates['packing']) : (float) $oldP->sale_price);
        $list = $num('list_price', $oldP ? (float) $oldP->list_price : $sale); $fee = self::m($num('marketplace_fee', (float) ($oldP->marketplace_fee ?? 0))); $dft = $oldS ? null : ThreePlTariff::defaults((int) Context::ent(), $mp);      // a new row starts with the company 3PL tariff (R-D-23)
        $pf = self::m($num('packing_fee', (float) ($oldS->packing_fee ?? $dft['packing_fee']))); $sf = self::m($num('storage_fee', (float) ($oldS->storage_fee ?? $dft['storage_fee'])));
        $feeSrc = (array_key_exists('packing_fee', $d) && $d['packing_fee'] !== '' && $d['packing_fee'] !== null) || (array_key_exists('storage_fee', $d) && $d['storage_fee'] !== '' && $d['storage_fee'] !== null) ? 'TYPED' : ($oldS ? $oldS->fee_source : ($dft['from_tariff'] ? 'TARIFF' : null));
        return DB::transaction(function () use ($productId, $mp, $d, $u, $oldP, $raw, $sale, $list, $fee, $preset, $packing, $pf, $sf, $rates, $feeSrc) {
            $priceChanged = ! $oldP || round((float) $oldP->raw_price, 2) !== round($raw, 2) || round((float) $oldP->sale_price, 2) !== round($sale, 2);
            if ($priceChanged) {      // R-D-30: a new period from today; a second change on the same day replaces that day's period
                $today = date('Y-m-d'); $same = DB::table('t_product_inventory_change_log')->where(['fk_product_id' => $productId, 'fk_id_marketplace' => $mp, 'change_type' => 'price_change', 'from_date' => $today])->first();
                if ($same) DB::table('t_product_inventory_change_log')->where('id', $same->id)->update(['new_price' => $sale, 'new_raw_price' => $raw, 'carrier_rate' => $rates['carrier'], 'packing_rate' => $rates['packing'], 'fk_id_reason' => $d['reason'] ?? $same->fk_id_reason]);
                else PriceService::set($productId, $mp, $raw, $sale, $today, isset($d['reason']) ? (int) $d['reason'] ?: null : null, ['carrier_rate' => $rates['carrier'], 'packing_rate' => $rates['packing'], 'list_price' => $list, 'marketplace_fee' => $fee]);
            }
            DB::table('t_product_marketplace_prices')->updateOrInsert(['fk_id_product' => $productId, 'fk_id_marketplace' => $mp], ['raw_price' => $raw, 'sale_price' => $sale, 'list_price' => $list, 'marketplace_fee' => $fee, 'created_by' => $u->id ?? null, 'updated_at' => now()]);
            DB::table('t_product_marketplace_shipping')->updateOrInsert(['fk_id_product' => $productId, 'fk_id_marketplace' => $mp], ['fk_id_car_pkg_svc' => $preset, 'fk_id_packing' => $packing, 'packing_fee' => $pf, 'storage_fee' => $sf, 'fee_source' => $feeSrc, 'created_by' => $u->id ?? null, 'updated_at' => now()]);
            $row = ['raw_price' => $raw, 'sale_price' => $sale, 'list_price' => $list, 'marketplace_fee' => $fee, 'fk_id_car_pkg_svc' => $preset, 'fk_id_packing' => $packing, 'packing_fee' => $pf, 'storage_fee' => $sf, 'fk_id_marketplace' => $mp, 'fee_source' => $feeSrc]; $fig = self::figures($productId, $row);
            $sku = (string) DB::table('t_product_vendors')->where('id', $productId)->value('SKU_VENDOR'); $mpName = (string) (DB::table('cat_marketplaces')->where('id', $mp)->value('marketplace_short') ?: $mp);
            if ($fig['below_floor']) TaskEngine::open('PRICE_BELOW_FLOOR', [$productId, $mp], "Price below the floor: $sku on $mpName sells at £" . number_format($sale, 2) . ' – floor £' . number_format($fig['floor'], 2), ['module' => 't_product_vendors', 'record_id' => $productId, 'fix_url' => "#/prices/$productId"]);
            else TaskEngine::close('PRICE_BELOW_FLOOR', [$productId, $mp], 'Price is above the floor');
            Audit::log('t_product_marketplace_prices', $productId, 'UPDATE', ['marketplace' => $mpName] + $row);
            return ['message' => 'Saved: Sale £' . number_format($sale, 2) . ($fig['below_floor'] ? ' – below the floor £' . number_format($fig['floor'], 2) . ' (a task is open)' : ''), 'row' => $row + $fig, 'warning' => $fig['below_floor'] ? 'Sale £' . number_format($sale, 2) . ' is below the floor £' . number_format($fig['floor'], 2) . ' (FIFO cost + fee + packing fee + storage fee + target margin).' : null];
        });
    }
}
