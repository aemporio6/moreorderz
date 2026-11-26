<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The sales list and the actions on one sale line (RULES_A_SALES §3, §5, §6).
 * One line formula everywhere (R-A_SALES-28); actions follow one state table (R-A_SALES-36);
 * every change is written to the line's log (t_sale_change_log) field by field.
 */
class SaleLines
{
    public const NATURES = ['Product' => 'Product Loss', 'Carrier' => 'Carrier Loss', 'Packing' => 'Packing Loss', 'Carrier_Packing' => 'Carrier + Packing Loss', 'Product_Carrier' => 'Product + Carrier Loss',
        'Product_Packing' => 'Product + Packing Loss', 'Product_Carrier_Packing' => 'Product + Packing + Carrier Loss'];
    public const MONEY = '/^\d{1,8}(\.\d{1,2})?$/';
    public const SOURCE_TAGS = ['current' => ['current price', 'No price history for this date: used the marketplace price set on the product today'], 'product' => ['main price', 'No marketplace price: used the product main price and carrier'],
        'period_old' => ['before history', 'Order is older than the first price change: used the price from before that change'], 'legacy_period' => ['old history', 'Used a price period from before per-marketplace prices']];

    // ---------- R-A_SALES-28: the one line formula ----------
    /** product_cost = raw × qty · postage = carrier + packing, once per line · buy amount = both · profit = total − both. */
    public static function calc(float $total, float $raw, int $qty, float $carrier, float $packing): array
    {
        $product = round($raw * $qty, 2); $postage = round($carrier + $packing, 2);
        return ['product_cost' => $product, 'postage' => $postage, 'buy_amount' => round($product + $postage, 2), 'profit' => round($total - $product - $postage, 2)];
    }

    // ---------- R-A_SALES-27: expected cost of an own-stock line on its order date ----------
    public static function carrierRate(?int $presetId, int $ent, ?string $date = null): float
    {
        return $presetId ? Carriers::rateOn('CARRIER', $presetId, $date, (float) (self::presets($ent)->where('cv.id', $presetId)->value('cv.rate') ?? 0)) : 0.0;      // the rate of that date
    }

    /** Carrier presets (carrier × package × service with a rate) of the company. The old tool's view of them is not used – it is rebuilt from the tables here. */
    public static function presets(int $ent)
    {
        return DB::table('t_carrier_pkg_svc as cv')->join('t_carriers as c', 'c.id', '=', 'cv.fk_id_carrier')->whereIn('c.fk_id_ent', [0, $ent]);
    }

    private static function preset(?int $id, int $ent): ?object
    {
        return $id ? self::presets($ent)->where('cv.id', $id)->first(['cv.id', 'cv.pkg_svc_name', 'cv.rate', 'cv.fk_id_carrier', 'c.carrier_name']) : null;
    }

    public static function packingRate(?int $packingId, ?string $date = null): float
    {
        return $packingId ? Carriers::rateOn('PACKING', $packingId, $date, (float) (DB::table('t_packings')->where('id', $packingId)->value('packing_rate') ?? 0)) : 0.0;
    }

    /** [raw, sale, fk_id_car_pkg_svc, carrier_rate, fk_id_packing, packing_rate, cost_source, fk_id_price_period] */
    public static function expectedCost(object $product, int $marketplaceId, string $date, int $ent): array
    {
        $pid = (int) $product->id;
        // today's carrier and packing of the product for this marketplace, else the product's own
        $child = (int) ($product->f_parent ?? 0) === 3;      // a variation child has its own price and shipping tables per marketplace
        $ship = $child ? DB::table('t_variation_marketplace_shipping')->where(['fk_id_variation' => $pid, 'fk_id_marketplace' => $marketplaceId])->first() : DB::table('t_product_marketplace_shipping')->where(['fk_id_product' => $pid, 'fk_id_marketplace' => $marketplaceId])->first();
        $svc = (int) (($ship->fk_id_car_pkg_svc ?? 0) ?: ($product->fk_id_car_pkg_svc ?? 0)); $pack = (int) (($ship->fk_id_packing ?? 0) ?: ($product->fk_id_packing ?? 0));
        $carToday = self::carrierRate($svc, $ent, $date); $packToday = self::packingRate($pack, $date);      // carrier and packing rates carry a start date: the order date decides
        $log = fn (?int $mp) => DB::table('t_product_inventory_change_log')->where(['fk_product_id' => $pid, 'change_type' => 'price_change'])->when($mp !== null, fn ($q) => $q->where('fk_id_marketplace', $mp), fn ($q) => $q->where(fn ($w) => $w->whereNull('fk_id_marketplace')->orWhere('fk_id_marketplace', 0)));
        $cover = fn ($q) => $q->where('from_date', '<=', $date)->where(fn ($w) => $w->whereNull('to_date')->orWhere('to_date', '0000-00-00')->orWhere('to_date', '>=', $date))->orderByDesc('from_date')->orderByDesc('id')->first();
        $src = null; $raw = 0.0; $sale = 0.0; $period = null;
        if ($p = $cover($log($marketplaceId))) { $src = 'period'; $period = $p; $raw = (float) $p->new_raw_price; $sale = (float) $p->new_price; }
        elseif ($p = $cover($log(null))) { $src = 'legacy_period'; $period = $p; $raw = (float) $p->new_raw_price; $sale = (float) $p->new_price; }
        elseif (($p = $log($marketplaceId)->orderBy('from_date')->orderBy('id')->first()) && $date < $p->from_date && (float) $p->old_price > 0) { $src = 'period_old'; $period = $p; $raw = (float) $p->old_raw_price; $sale = (float) $p->old_price; }
        elseif (($c = $child ? DB::table('t_variation_marketplace_prices')->where(['fk_id_variation' => $pid, 'fk_id_marketplace' => $marketplaceId])->first() : DB::table('t_product_marketplace_prices')->where(['fk_id_product' => $pid, 'fk_id_marketplace' => $marketplaceId])->first()) && ((float) $c->raw_price > 0 || (float) $c->sale_price > 0)) { $src = 'current'; $raw = (float) $c->raw_price; $sale = (float) $c->sale_price; }
        else { $src = 'product'; $raw = (float) ($product->raw_price ?? 0); $sale = (float) ($product->sale_price ?? 0); }
        // carrier and packing split
        if (in_array($src, ['current', 'product'], true)) { $car = $carToday; $pk = $packToday; }
        elseif ($src !== 'period_old' && $period->carrier_rate !== null) { $car = (float) $period->carrier_rate; $pk = (float) ($period->packing_rate ?? 0); $svc = (int) ($period->fk_id_car_pkg_svc ?: $svc); $pack = (int) ($period->fk_id_packing ?: $pack); }
        else { $shipTotal = $raw > 0 ? max(0, $sale - $raw) : $carToday + $packToday; $car = min($carToday, $shipTotal); $pk = round($shipTotal - $car, 2); }
        if ($raw <= 0) $raw = max(0, $sale - $car - $pk);
        return ['raw' => round($raw, 2), 'sale' => round($sale, 2), 'fk_id_car_pkg_svc' => $svc ?: null, 'carrier_rate' => round($car, 2), 'fk_id_packing' => $pack ?: null, 'packing_rate' => round($pk, 2), 'cost_source' => $src, 'fk_id_price_period' => $period->id ?? null];
    }

    /** The cost columns of a line at intake (own-stock line with a product), and the tasks about missing price history / a price that differs. */
    /**
     * Pool / Private of a product for a shop (R-A_SALES-30): the product's own row for that shop, else its row for the marketplace, else the parent's (shop, then marketplace). 'POOL' | 'PRIVATE' | ''.
     * $rows may hold the visibility rows already read (for a list), else they are read here.
     */
    public static function visibility(int $productId, ?int $parentId, int $marketplaceId, int $shopId, ?array $rows = null): string
    {
        $ids = array_values(array_filter([$productId, (int) $parentId])); if (! $ids) return '';
        $rows ??= DB::table('t_product_visibility')->whereIn('fk_id_product', $ids)->where('fk_id_marketplace', $marketplaceId)->whereIn('fk_id_branch', [0, $shopId])->get()->all();
        foreach ($ids as $pid) foreach ([$shopId, 0] as $b) foreach ($rows as $r) if ((int) $r->fk_id_product === $pid && (int) $r->fk_id_marketplace === $marketplaceId && (int) $r->fk_id_branch === $b) return strtoupper((string) $r->visibility_mode);
        return '';
    }

    /** Names of the investors of a product: its own and its parent's, in that order, without repeats. */
    public static function investorsOf(int $productId, ?int $parentId, ?array $byProduct = null): array
    {
        $ids = array_values(array_filter([$productId, (int) $parentId])); if (! $ids) return [];
        if ($byProduct === null) { $byProduct = []; foreach (DB::table('t_product_investors as pi')->join('t_account as a', 'a.id', '=', 'pi.fk_id_account')->whereIn('pi.fk_id_product', $ids)->orderBy('pi.id')->get(['pi.fk_id_product', DB::raw("TRIM(CONCAT_WS(' ', NULLIF(a.first_name,''), NULLIF(a.sur_name,''))) as n")]) as $i) $byProduct[$i->fk_id_product][] = $i->n; }
        $out = []; foreach ($ids as $pid) foreach ($byProduct[$pid] ?? [] as $n) if (! in_array($n, $out, true)) $out[] = $n; return $out;
    }

    public static function intakeCost(object $product, object $shop, string $date, int $qty, float $rate, float $total, int $ent): array
    {
        $c = self::expectedCost($product, (int) $shop->fk_id_marketplace, $date, $ent); $x = self::calc($total, $c['raw'], $qty, $c['carrier_rate'], $c['packing_rate']);
        if (in_array($c['cost_source'], ['current', 'product'], true)) TaskEngine::open('PRICE_HISTORY_MISSING', [$product->id, $shop->fk_id_marketplace], 'No price history for ' . ($product->SKU_VENDOR ?? 'product') . ' on this marketplace – set its price with a start date', ['ent' => $ent, 'module' => 't_product_vendors', 'record_id' => (int) $product->id, 'fix_url' => '#/prices/' . $product->id]);
        elseif ($c['sale'] > 0 && abs($c['sale'] - $rate) >= 0.01) TaskEngine::open('SAL_PRICE_MISMATCH', [$product->id, $shop->id, $date], 'Sold at ' . number_format($rate, 2) . ', listed at ' . number_format($c['sale'], 2) . ': ' . ($product->SKU_VENDOR ?? ''), ['ent' => $ent, 'module' => 't_product_vendors', 'record_id' => (int) $product->id, 'fix_url' => '#/prices/' . $product->id]);
        return ['visibility_mode' => self::visibility((int) $product->id, (int) ($product->f_id_parent ?? 0) ?: null, (int) $shop->fk_id_marketplace, (int) $shop->id),      // Pool / Private as it stands at import
            'raw_price' => $c['raw'], 'fk_id_car_pkg_svc' => $c['fk_id_car_pkg_svc'], 'carrier_rate' => $c['carrier_rate'], 'fk_id_packing' => $c['fk_id_packing'], 'packing_rate' => $c['packing_rate'], 'cost_source' => $c['cost_source'],
            'fk_id_price_period' => $c['fk_id_price_period'], 'buy_rate' => $x['product_cost'], 'buy_amount' => $x['buy_amount'], 'profit' => $x['profit']];
    }

    // ---------- R-A_SALES-36: one state table ----------
    public static function kind(object $s): string { return $s->sale_source === 'ds' ? 'DS' : ($s->sale_source === 'unmatched' ? 'NM' : 'OWN'); }
    public static function confirmed(object $s): bool { return $s->status === 'Confirmed' || (int) ($s->f_locked ?? 0) === 1; }

    /** action => [allowed, message when not] for one line and one person. */
    public static function actions(object $s, ?object $u): array
    {
        $own = self::kind($s) === 'OWN'; $pending = $s->status === 'Pending' && ! (int) ($s->f_locked ?? 0); $can = fn (string $r) => $u && Perm::has($u, $r) && Perm::shopAllowed($u, (int) $s->fk_id_branch);
        $no = fn (string $right) => $can($right) ? null : 'Your role cannot do this on this shop.';
        return [
            'edit' => [$pending && $can('orders.saleEdit'), $pending ? $no('orders.saleEdit') : 'Only Pending lines can be changed'],
            'carrier' => [$pending && $own && $can('orders.saleCarrier'), ! $own ? 'Only active inventory lines have a carrier and packing of ours' : ($pending ? $no('orders.saleCarrier') : 'Only Pending lines can be changed')],
            'refund' => [$pending && $can('orders.saleRefund'), $pending ? $no('orders.saleRefund') : 'Only Pending lines can be refunded here'],
            'view_refund' => [$s->status === 'Refunded', null],
            'log' => [true, null],
            'case' => [$can('orders.saleCase') && $s->sale_source !== 'summary', $no('orders.saleCase')],
            'delete' => [! self::confirmed($s) && $can('orders.saleDelete'), self::confirmed($s) ? 'Confirmed lines are saved permanently' : $no('orders.saleDelete')],
            'open_product' => [(int) $s->fk_product_id > 0, null], 'open_ds' => [(int) ($s->fk_drp_item ?? 0) > 0, null],
        ];
    }

    private static function line(int $id, bool $lock = false): object
    {
        $q = DB::table('t_sales')->where('id', $id)->where('fk_id_ent', Context::ent()); Perm::mine($q, 'fk_id_branch'); if ($lock) $q->lockForUpdate();
        return $q->first() ?? abort(404, 'Sale line not found.');
    }

    private static function need(object $s, string $right): void
    {
        $u = Auth::user(); Perm::need($u, $right);
        if (! Perm::shopAllowed($u, (int) $s->fk_id_branch)) abort(response()->json(['message' => "You don't have rights to change sales for this shop."], 403));
    }

    private static function log(int $saleId, string $action, array $rows, ?string $key = null): void
    {
        $u = Auth::user(); $key = $key ?: uniqid('s', true);
        foreach ($rows as [$field, $label, $old, $new]) DB::table('t_sale_change_log')->insert(['fk_id_sale' => $saleId, 'save_key' => mb_substr($key, 0, 40), 'action' => $action, 'field_name' => $field, 'field_label' => $label, 'old_value' => $old === null ? null : (string) $old, 'new_value' => $new === null ? null : (string) $new,
            'fk_id_user' => $u->id ?? null, 'changed_by' => $u ? ($u->display_name ?: $u->login_id) : 'system', 'changed_at' => now()]);
    }

    private static function money(float $v): string { return number_format($v, 2, '.', ''); }
    private static function shopMonth(object $s): string { return date('M Y', strtotime((string) $s->d_order)); }

    /** Now / After for Total, Buy Amount, Profit of an edit – by the kind of line. */
    private static function recalc(object $s, int $qty, float $rate, float $total): array
    {
        $kind = self::kind($s);
        if ($kind === 'OWN') { $x = self::calc($total, (float) $s->raw_price, $qty, (float) $s->carrier_rate, (float) $s->packing_rate); return ['sale_rate' => $rate, 'total_amount' => $total, 'buy_rate' => $x['product_cost'], 'buy_amount' => $x['buy_amount'], 'profit' => $x['profit']]; }
        if ($kind === 'DS') return ['sale_rate' => $total, 'total_amount' => $total, 'buy_rate' => (float) $s->buy_rate, 'buy_amount' => (float) $s->buy_amount, 'profit' => round($total - (float) $s->buy_amount, 2)];
        return ['sale_rate' => $rate, 'total_amount' => $total, 'buy_rate' => 0.0, 'buy_amount' => 0.0, 'profit' => 0.0];
    }

    // ---------- R-A_SALES-37: Edit Sale ----------
    /** $apply false = the preview (Now / After save). */
    public static function edit(int $id, array $d, bool $apply = true): array
    {
        return DB::transaction(function () use ($id, $d, $apply) {
            $s = self::line($id, true); self::need($s, 'orders.saleEdit');
            if ($s->status !== 'Pending' || (int) $s->f_locked) throw new \InvalidArgumentException("Only Pending lines can be changed. This line is {$s->status}.");
            $no = array_key_exists('order_no', $d) ? trim((string) $d['order_no']) : (string) $s->order_no; $qty = (string) ($d['qty'] ?? $s->qty); $rate = (string) ($d['sale_rate'] ?? $s->sale_rate); $total = (string) ($d['total_amount'] ?? '');
            if ($no === '') throw new \InvalidArgumentException('Order Number is required.');
            if (mb_strlen($no) > 100) throw new \InvalidArgumentException('Order Number can be at most 100 characters.');
            if (! preg_match('/^\d{1,6}$/', $qty) || (int) $qty < 1) throw new \InvalidArgumentException('Quantity must be a whole number, 1 or more.');
            if (! preg_match(self::MONEY, $rate)) throw new \InvalidArgumentException('Sale Rate must be a number with up to 2 decimals, e.g. 8 or 8.50.');
            if ($total === '') $total = self::money((int) $qty * (float) $rate);
            if (! preg_match(self::MONEY, $total)) throw new \InvalidArgumentException('Total Amount must be a number with up to 2 decimals, e.g. 16 or 16.50.');
            if ($no !== $s->order_no && ($dup = DB::table('t_sales')->where('fk_id_ent', $s->fk_id_ent)->where('fk_id_branch', $s->fk_id_branch)->where('order_no', $no)->where('sku', $s->sku)->where('id', '<>', $s->id)->first()))
                throw new \InvalidArgumentException("Order $no with SKU {$s->sku} already exists in this shop (" . self::shopMonth($dup) . ' import).');
            $new = self::recalc($s, (int) $qty, (float) $rate, (float) $total) + ['order_no' => $no, 'qty' => (int) $qty, 'cust_name' => array_key_exists('cust_name', $d) ? (string) $d['cust_name'] : (string) $s->cust_name, 'cust_contacts' => array_key_exists('cust_contacts', $d) ? (string) $d['cust_contacts'] : (string) $s->cust_contacts];
            $labels = ['order_no' => 'Order Number', 'cust_name' => 'Customer Name', 'cust_contacts' => 'Customer Contacts', 'qty' => 'Quantity', 'sale_rate' => 'Sale Rate', 'total_amount' => 'Total Amount', 'buy_rate' => 'Buy Rate', 'buy_amount' => 'Buy Amount', 'profit' => 'Profit'];
            $rows = []; $typed = false;
            foreach ($labels as $f => $label) {
                $num = in_array($f, ['sale_rate', 'total_amount', 'buy_rate', 'buy_amount', 'profit'], true);
                $old = $num ? self::money((float) $s->$f) : (string) $s->$f; $nw = $num ? self::money((float) $new[$f]) : (string) $new[$f];
                if ($old === $nw) continue;
                $calc = in_array($f, ['buy_rate', 'buy_amount', 'profit'], true); if (! $calc) $typed = true;
                $rows[] = [$f, $label . ($calc ? ' (recalculated)' : ''), $old, $nw];
            }
            $preview = ['now' => ['total' => (float) $s->total_amount, 'buy_amount' => (float) $s->buy_amount, 'profit' => (float) $s->profit], 'after' => ['total' => $new['total_amount'], 'buy_amount' => $new['buy_amount'], 'profit' => $new['profit']]];
            if (! $apply) return ['preview' => $preview, 'changed' => array_map(fn ($r) => $r[1], $rows)];
            if (! $typed) throw new \InvalidArgumentException('Nothing changed.');
            $cur = Fx::code((int) $s->fk_id_currency ?: null, (int) $s->fk_id_ent);
            $n = DB::table('t_sales')->where('id', $s->id)->where('status', 'Pending')->update($new + ['total_amount_gbp' => Fx::toBase((float) $new['total_amount'], $cur, (string) $s->d_order, (int) $s->fk_id_ent), 'f_edited' => 1, 'updated_at' => now()]);
            if (! $n) throw new \InvalidArgumentException("This line is no longer Pending, so it can't be changed.");
            self::log((int) $s->id, 'Edit Sale', $rows);
            if ((int) $new['qty'] !== (int) $s->qty && self::kind($s) === 'OWN' && ($s->fulfilment_channel ?: 'SELF') === 'SELF') { DispatchService::release((int) $s->id, 'Quantity changed'); DispatchService::reserve((int) $s->id); }
            return ['message' => 'Sale updated and the line recalculated.', 'preview' => $preview, 'log_rows' => count($rows)];
        });
    }

    // ---------- R-A_SALES-38: Edit Carrier / Packing ----------
    public static function carrierOptions(object $s): array
    {
        $ent = (int) $s->fk_id_ent;
        $day = (string) $s->d_order; $mp = (int) $s->fk_id_marketplace;      // rates of the order date; services offered on this marketplace
        $svc = self::presets($ent)->leftJoin('t_carrier_services as sv', 'sv.id', '=', 'cv.fk_id_car_service')->where(fn ($w) => $w->where('cv.f_active', 1)->orWhere('cv.id', (int) $s->fk_id_car_pkg_svc))->orderBy('c.carrier_name')->orderBy('cv.pkg_svc_name')->get(['cv.id', 'cv.pkg_svc_name', 'cv.rate', 'cv.fk_id_carrier', 'c.carrier_name', 'sv.offered_on'])
            ->filter(fn ($r) => (int) $r->id === (int) $s->fk_id_car_pkg_svc || Carriers::offered($r->offered_on, $mp))
            ->map(function ($r) use ($day) { $rate = Carriers::rateOn('CARRIER', (int) $r->id, $day, (float) $r->rate); return ['id' => (int) $r->id, 'key' => (string) $r->id, 'label' => $r->carrier_name . '-' . $r->pkg_svc_name . ($rate > 0 ? '' : ' (no rate set)'), 'rate' => $rate, 'carrier' => (int) $r->fk_id_carrier, 'has_rate' => $rate > 0]; })->values()->all();
        if ($s->fk_id_car_pkg_svc && ! in_array((int) $s->fk_id_car_pkg_svc, array_column($svc, 'id'), true)) $svc[] = ['id' => (int) $s->fk_id_car_pkg_svc, 'key' => (string) $s->fk_id_car_pkg_svc, 'label' => 'Current carrier service (no longer in the list)', 'rate' => (float) $s->carrier_rate, 'carrier' => 0, 'has_rate' => true];
        $packs = DB::table('t_packings')->whereIn('fk_id_ent', [0, $ent])->where(fn ($w) => $w->where('f_active', 1)->orWhere('id', (int) $s->fk_id_packing))->orderBy('packing_name')->get()
            ->map(function ($p) use ($day) { $rate = Carriers::rateOn('PACKING', (int) $p->id, $day, (float) $p->packing_rate); return ['id' => (int) $p->id, 'label' => $p->packing_name . '-£' . self::money($rate) . ($p->f_active ? '' : ' (inactive)'), 'rate' => $rate]; })->all();
        // suggestion: the cheapest service whose package takes the product's weight and size (else simply the cheapest with a rate)
        $prod = (int) $s->fk_product_id ? DB::table('t_product_vendors')->where('id', $s->fk_product_id)->first(['weight', 'unit_gross_weight', 'dimensions']) : null; $dims = $prod ? array_map('floatval', array_slice(preg_split('/[^\d.]+/', (string) $prod->dimensions, -1, PREG_SPLIT_NO_EMPTY), 0, 3)) + [0, 0, 0] : [0, 0, 0];
        $fit = $prod ? Carriers::cheapest((float) (($prod->unit_gross_weight ?: $prod->weight) ?: 0) * max(1, (int) $s->qty), (float) $dims[0], (float) $dims[1], (float) $dims[2], $mp, $day) : null;
        $cheapest = $fit && in_array($fit['id'], array_column($svc, 'id'), true) ? $fit : collect($svc)->where('rate', '>', 0)->sortBy('rate')->first();
        $dd = CarrierPresets::dropdown($mp ?: null, (int) ($s->fk_id_branch ?? 0) ?: null, (int) $s->fk_id_car_pkg_svc ?: null);      /* R-D-65: the sale's "Edit carrier" offers the groups of its marketplace / shop in the same order as every carrier field */
        return ['services' => $svc, 'groups' => array_map(fn ($g) => ['header' => $g['header'], 'ids' => array_column($g['items'], 'id')], $dd['groups']), 'packings' => array_merge([['id' => 0, 'label' => 'No packing', 'rate' => 0.0]], $packs), 'cheapest' => $cheapest['id'] ?? null,
            'formula' => 'Buy Amount = raw × qty + carrier + packing (carrier and packing once per line). Profit = Total − Buy Amount.'];
    }

    public static function carrierPacking(int $id, array $d, bool $apply = true): array
    {
        return DB::transaction(function () use ($id, $d, $apply) {
            $s = self::line($id, true); self::need($s, 'orders.saleCarrier');
            if (self::kind($s) !== 'OWN') throw new \InvalidArgumentException('Only active inventory lines have a carrier and packing of ours');
            if ($s->status !== 'Pending' || (int) $s->f_locked) throw new \InvalidArgumentException("Only Pending lines can be changed. This line is {$s->status}.");
            $svcId = (int) ($d['fk_id_car_pkg_svc'] ?? 0); $packId = (int) ($d['fk_id_packing'] ?? 0);
            if (! $svcId) throw new \InvalidArgumentException('Select a carrier service.');
            $svc = self::preset($svcId, (int) $s->fk_id_ent);
            if (! $svc && $svcId !== (int) $s->fk_id_car_pkg_svc) throw new \InvalidArgumentException('That carrier service was not found.');
            $pack = $packId ? DB::table('t_packings')->where('id', $packId)->whereIn('fk_id_ent', [0, (int) $s->fk_id_ent])->first() : null;
            if ($packId && ! $pack) throw new \InvalidArgumentException('That packing was not found.');
            $car = $svc ? Carriers::rateOn('CARRIER', $svcId, (string) $s->d_order, (float) $svc->rate) : (float) $s->carrier_rate; $pk = $pack ? Carriers::rateOn('PACKING', $packId, (string) $s->d_order, (float) $pack->packing_rate) : 0.0;      // the rates of the line's own date
            $x = self::calc((float) $s->total_amount, (float) $s->raw_price, (int) $s->qty, $car, $pk);
            $name = fn ($v, float $rate) => $v ? ($v->carrier_name . '-' . $v->pkg_svc_name) . ' (£' . self::money($rate) . ')' : 'None';
            $oldSvc = self::preset((int) $s->fk_id_car_pkg_svc, (int) $s->fk_id_ent); $oldPack = $s->fk_id_packing ? DB::table('t_packings')->where('id', $s->fk_id_packing)->first() : null;
            $preview = ['rows' => [
                ['Carrier', $name($oldSvc, (float) $s->carrier_rate), $name($svc, $car), $svcId !== (int) $s->fk_id_car_pkg_svc],
                ['Packing', $oldPack ? $oldPack->packing_name . ' (£' . self::money((float) $s->packing_rate) . ')' : 'None', $pack ? $pack->packing_name . ' (£' . self::money($pk) . ')' : 'None', $packId !== (int) $s->fk_id_packing],
                ['Buy Amount', self::money((float) $s->buy_amount), self::money($x['buy_amount']), self::money((float) $s->buy_amount) !== self::money($x['buy_amount'])],
                ['Profit', self::money((float) $s->profit), self::money($x['profit']), self::money((float) $s->profit) !== self::money($x['profit'])]]];
            if (! $apply) return ['preview' => $preview];
            if ($svcId === (int) $s->fk_id_car_pkg_svc && $packId === (int) $s->fk_id_packing) throw new \InvalidArgumentException('Nothing changed: this line already uses that carrier and packing.');
            $n = DB::table('t_sales')->where('id', $s->id)->where('status', 'Pending')->update(['fk_id_car_pkg_svc' => $svcId, 'carrier_rate' => $car, 'fk_id_packing' => $packId ?: null, 'packing_rate' => $pk, 'buy_rate' => $x['product_cost'], 'buy_amount' => $x['buy_amount'], 'profit' => $x['profit'], 'f_edited' => 1, 'updated_at' => now()]);
            if (! $n) throw new \InvalidArgumentException("This line is no longer Pending, so it can't be changed.");
            $rows = [];
            foreach ($preview['rows'] as [$label, $old, $new, $changed]) if ($changed) $rows[] = [strtolower(str_replace(' ', '_', $label)), $label . (in_array($label, ['Buy Amount', 'Profit'], true) ? ' (recalculated)' : ''), $old, $new];
            self::log((int) $s->id, 'Edit Carrier/Packing', $rows);
            return ['message' => 'Carrier/packing updated and the line recalculated.', 'preview' => $preview];
        });
    }

    // ---------- R-A_SALES-39: refund of a Pending line with the nature of the loss ----------
    /** The cost parts of a line and the loss each nature suggests. */
    public static function refundInfo(int $id): array
    {
        $s = self::line($id); $own = self::kind($s) === 'OWN';
        $parts = ['Product' => $own ? round((float) $s->raw_price * (int) $s->qty, 2) : round((float) $s->buy_amount, 2), 'Carrier' => (float) $s->carrier_rate, 'Packing' => (float) $s->packing_rate];
        $sug = []; foreach (self::NATURES as $k => $label) $sug[$k] = ['label' => $label, 'loss' => round(array_sum(array_map(fn ($p) => $parts[$p], explode('_', $k))), 2)];
        $view = $s->status === 'Refunded' ? ['by' => $s->refunded_by_name, 'on' => $s->refunded_at ? date('d.m.Y', strtotime((string) $s->refunded_at)) : null, 'text' => 'Refunded by ' . ($s->refunded_by_name ?: 'someone') . ' on ' . ($s->refunded_at ? date('d.m.Y', strtotime((string) $s->refunded_at)) : ''),
            'nature' => self::NATURES[$s->refund_loss_nature] ?? $s->refund_loss_nature, 'loss' => (float) $s->refund_loss_amount, 'profit' => (float) $s->profit, 'reason' => $s->refund_reason, 'total' => (float) $s->total_amount] : null;
        return ['line' => ['id' => $s->id, 'order_no' => $s->order_no, 'sku' => $s->sku, 'qty' => (int) $s->qty, 'sale_rate' => (float) $s->sale_rate, 'total_amount' => (float) $s->total_amount, 'status' => $s->status], 'parts' => $parts, 'natures' => $sug,
            'hint' => "This line's costs: product £" . self::money($parts['Product']) . ', carrier £' . self::money($parts['Carrier']) . ', packing £' . self::money($parts['Packing']) . '.', 'view' => $view];
    }

    public static function refund(int $id, array $d): array
    {
        return DB::transaction(function () use ($id, $d) {
            $s = self::line($id, true); self::need($s, 'orders.saleRefund');
            $rate = (string) ($d['sale_rate'] ?? ''); $total = (string) ($d['total_amount'] ?? ''); $nature = (string) ($d['nature'] ?? ''); $loss = (string) ($d['loss_amount'] ?? ''); $reason = trim((string) ($d['reason'] ?? ''));
            if (! preg_match(self::MONEY, $rate)) throw new \InvalidArgumentException('Sale Rate must be a number with up to 2 decimals (0 for a full refund).');
            if ($total === '') $total = self::money((int) $s->qty * (float) $rate);
            if (! preg_match(self::MONEY, $total)) throw new \InvalidArgumentException('Total Amount must be a number with up to 2 decimals (0 for a full refund).');
            if ((float) $rate > (float) $s->sale_rate + 0.001) throw new \InvalidArgumentException("Sale Rate can't be more than the current " . self::money((float) $s->sale_rate) . '.');
            if ((float) $total > (float) $s->total_amount + 0.001) throw new \InvalidArgumentException("Total Amount can't be more than the current " . self::money((float) $s->total_amount) . '.');
            if (! isset(self::NATURES[$nature])) throw new \InvalidArgumentException('Choose the nature of the loss.');
            if (! preg_match(self::MONEY, $loss)) throw new \InvalidArgumentException('Loss Amount must be a number with up to 2 decimals, e.g. 4.57.');
            if ($reason === '') throw new \InvalidArgumentException('Enter the refund reason.');
            if (mb_strlen($reason) > 2000) throw new \InvalidArgumentException('Refund reason can be at most 2000 characters.');
            $u = Auth::user(); $profit = round(-1 * (float) $loss, 2); $cur = Fx::code((int) $s->fk_id_currency ?: null, (int) $s->fk_id_ent); $full = (float) $total <= 0;
            $n = DB::table('t_sales')->where('id', $s->id)->where('status', 'Pending')->where(fn ($w) => $w->whereNull('f_locked')->orWhere('f_locked', 0))->update(['sale_rate' => (float) $rate, 'total_amount' => (float) $total, 'total_amount_gbp' => Fx::toBase((float) $total, $cur, (string) $s->d_order, (int) $s->fk_id_ent),
                'profit' => $profit, 'status' => 'Refunded', 'refunded_at' => now(), 'refunded_by' => $u->id ?? null, 'refunded_by_name' => $u ? ($u->display_name ?: $u->login_id) : null, 'refund_loss_nature' => $nature, 'refund_loss_amount' => (float) $loss, 'refund_reason' => $reason, 'f_edited' => 1, 'updated_at' => now()]);
            if (! $n) throw new \InvalidArgumentException("This line is no longer Pending, so it can't be refunded.");
            self::log((int) $s->id, 'Refund Sale', [['sale_rate', 'Sale Rate', self::money((float) $s->sale_rate), self::money((float) $rate)], ['total_amount', 'Total Amount', self::money((float) $s->total_amount), self::money((float) $total)], ['status', 'Status', 'Pending', 'Refunded'],
                ['refund_loss_nature', 'Nature of the loss', null, self::NATURES[$nature]], ['refund_loss_amount', 'Loss Amount', null, self::money((float) $loss)], ['profit', 'Profit', self::money((float) $s->profit), self::money($profit)], ['refund_reason', 'Refund reason', null, $reason]]);
            // the same refund as a charge line, so live profit and Month Close read it
            DB::table('t_sale_charges')->insert(['fk_id_ent' => $s->fk_id_ent, 'fk_id_sale' => $s->id, 'fk_id_branch' => $s->fk_id_branch, 'order_no' => $s->order_no, 'sku' => $s->sku, 'fk_id_product' => (int) $s->fk_product_id, 'kind' => $full ? 'REFUND_FULL' : 'REFUND_PARTIAL',
                'amount' => round((float) $s->total_amount - (float) $total, 2), 'fk_id_currency' => $s->fk_id_currency, 'amount_gbp' => round((float) $s->total_amount_gbp - Fx::toBase((float) $total, $cur, (string) $s->d_order, (int) $s->fk_id_ent), 2), 'd_charge' => date('Y-m-d'), 'f_estimated' => 0, 'note' => mb_substr(self::NATURES[$nature] . ' · loss ' . self::money((float) $loss), 0, 255), 'created_at' => now()]);
            // the item comes back only on a full refund whose loss is not the product: its reservation is freed and a return is expected (R-A_SALES-33)
            $returnExpected = $full && ! str_contains($nature, 'Product') && self::kind($s) === 'OWN';
            if ($returnExpected) { if ((int) $s->qty_shipped === 0) DispatchService::release((int) $s->id, 'Refunded in full, item not lost');
                TaskEngine::open('SAL_RETURN_EXPECTED', [$s->id], "Return expected: order {$s->order_no} · {$s->sku} was refunded in full and the item is not lost", ['ent' => (int) $s->fk_id_ent, 'module' => 't_sales', 'record_id' => (int) $s->id, 'fix_url' => '#/returns']); }
            Audit::log('t_sales', $s->id, 'UPDATE', ['status' => ['Pending', 'Refunded'], 'nature' => $nature, 'loss' => (float) $loss], $reason, (int) $s->fk_id_ent);
            return ['message' => 'Sale refunded. Profit is now ' . self::money($profit) . '.', 'profit' => $profit, 'return_expected' => $returnExpected];
        });
    }

    /** R-A_SALES-33: does a line take stock at Confirm? */
    public static function takesStock(object $s): bool
    {
        if (self::kind($s) !== 'OWN' || ! (int) $s->fk_product_id) return false;
        if ($s->status === 'Pending') return true;
        if ($s->status !== 'Refunded') return false;
        return ! ((float) $s->total_amount <= 0 && ! str_contains((string) $s->refund_loss_nature, 'Product'));
    }

    // ---------- R-A_SALES-40: delete a line or a whole month (to the recycle bin, with Undo) ----------
    // ---------- R-A_SALES-53: bulk delete of the Pending sales of a shop between two dates ----------
    public const BULK_WARNING = 'This deletes all rows between From and To for the selected shop.';
    public const BULK_SECOND_APPROVAL = 500;      // the default; Settings › Choices SALE_BULK_APPROVAL_LINES
    public static function bulkLimit(): int { return (int) Choices::get('SALE_BULK_APPROVAL_LINES', (int) (Context::ent() ?: 0) ?: null) ?: self::BULK_SECOND_APPROVAL; }
    public static function binDays(): int { return (int) Choices::get('SALE_RECYCLE_DAYS', (int) (Context::ent() ?: 0) ?: null) ?: 30; }      // Settings › Choices

    private static function bulkQuery(int $shopId, string $from, string $to) { return DB::table('t_sales')->where('fk_id_ent', Context::ent())->where('fk_id_branch', $shopId)->whereBetween('d_order', [$from, $to])->where('sale_source', '<>', 'history')->where('status', 'Pending')->where(fn ($w) => $w->whereNull('f_locked')->orWhere('f_locked', 0)); }

    /** Preview: "n lines in m batches will be deleted" – only Pending lines; confirmed / refunded ones are counted as left alone. */
    public static function bulkPreview(int $shopId, ?string $from, ?string $to, ?object $u): array
    {
        Perm::need($u, 'orders.saleBulkDelete');
        if (! $shopId) throw new \InvalidArgumentException('Select a shop.'); foreach ([$from, $to] as $d) if (! $d || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) throw new \InvalidArgumentException('From and To dates are required.');
        if ($from > $to) throw new \InvalidArgumentException('From must be on or before To.');
        $shop = DB::table('t_branches')->where('id', $shopId)->where('fk_id_ent', Context::ent())->first() ?? throw new \InvalidArgumentException('Shop not found.'); if (! Perm::shopAllowed($u, $shopId)) abort(403, "You don't have rights for this shop.");
        $q = self::bulkQuery($shopId, $from, $to); $n = (clone $q)->count(); $m = (clone $q)->where('fk_id_import', '>', 0)->distinct()->count('fk_id_import');
        $other = DB::table('t_sales')->where('fk_id_ent', Context::ent())->where('fk_id_branch', $shopId)->whereBetween('d_order', [$from, $to])->where('sale_source', '<>', 'history')->count() - $n;
        return ['lines' => $n, 'batches' => $m, 'left_alone' => $other, 'text' => "$n line(s) in $m batch(es) will be deleted" . ($other ? ". $other line(s) that are confirmed or refunded stay." : '.'), 'warning' => self::BULK_WARNING, 'shop' => SalesImport::label($shop),
            'needs_approval' => $n > self::bulkLimit(), 'approval_text' => $n > self::bulkLimit() ? 'More than ' . self::bulkLimit() . ' lines: a second person must approve before anything is deleted.' : null];
    }

    /** Apply with a reason. Up to 500 lines at once; above that the request waits for a second person (Approvals) and is carried out on approval. */
    public static function bulkDelete(int $shopId, ?string $from, ?string $to, string $reason, ?object $u): array
    {
        $p = self::bulkPreview($shopId, $from, $to, $u); if (mb_strlen(trim($reason)) < 3) throw new \InvalidArgumentException('Write the reason.');
        if (! $p['lines']) throw new \InvalidArgumentException('There are no Pending sale lines between these dates for this shop.');
        if ($p['needs_approval']) { $id = Approvals::request((int) Context::ent(), 'SALE_BULK_DELETE', 't_branches', $shopId, (float) $p['lines'], ['shop' => $shopId, 'from' => $from, 'to' => $to, 'reason' => trim($reason), 'lines' => $p['lines']], "Bulk delete of {$p['lines']} sale lines of {$p['shop']} ($from to $to)");
            return ['message' => "{$p['lines']} lines is more than " . self::BULK_SECOND_APPROVAL . ': the request waits for a second person. Nothing was deleted yet.', 'waiting' => true, 'approval' => $id, 'lines' => $p['lines']]; }
        return self::bulkApply($shopId, $from, $to, trim($reason));
    }

    /** The delete itself: every Pending line goes to the recycle bin (30 days, Undo), its stock reservation is given back. */
    public static function bulkApply(int $shopId, string $from, string $to, string $reason): array
    {
        return DB::transaction(function () use ($shopId, $from, $to, $reason) {
            $group = "bulk:$shopId:" . time(); $n = 0; $imports = [];
            foreach (self::bulkQuery($shopId, $from, $to)->lockForUpdate()->get() as $s) { if ((int) $s->fk_id_import) $imports[(int) $s->fk_id_import] = 1; self::bin($s, $group, mb_substr("Bulk delete $from to $to: $reason", 0, 250)); $n++; }
            Audit::log('t_branches', $shopId, 'DELETE', ['from' => $from, 'to' => $to, 'lines' => $n, 'batches' => count($imports)], 'Bulk delete of sales: ' . $reason, (int) Context::ent());
            return ['message' => "$n sale line(s) deleted. They stay in the recycle bin for " . self::binDays() . " days.", 'lines' => $n, 'undo' => $group, 'waiting' => false];
        });
    }

    private static function bin(object $s, string $group, string $note): void
    {
        DB::table('mo_recycle_bin')->insert(['fk_id_ent' => (int) $s->fk_id_ent, 'table_name' => 't_sales', 'row_id' => (int) $s->id, 'label' => mb_substr("Order {$s->order_no} · {$s->sku}", 0, 255), 'group_key' => $group, 'row_json' => json_encode((array) $s), 'deleted_by' => Auth::id(), 'deleted_at' => now(), 'note' => $note]);
        if (self::kind($s) === 'OWN') DispatchService::release((int) $s->id, $note);
        DB::table('t_sales')->where('id', $s->id)->delete();
        if ((int) $s->fk_id_import) DB::table('t_sale_imports')->where('id', $s->fk_id_import)->where('saved_rows', '>', 0)->decrement('saved_rows');
    }

    public static function deleteLine(int $id): array
    {
        return DB::transaction(function () use ($id) {
            $s = self::line($id, true); $u = Auth::user(); Perm::need($u, 'orders.saleDelete');
            if (! Perm::shopAllowed($u, (int) $s->fk_id_branch)) abort(response()->json(['message' => "You don't have rights to delete sales for this shop."], 403));
            if (self::confirmed($s)) throw new \InvalidArgumentException("This line is confirmed and saved permanently, so it can't be deleted.");
            $group = 'line:' . $s->id . ':' . time(); self::bin($s, $group, "Sale {$s->order_no} deleted");
            self::log((int) $s->id, 'Delete Sale', [['status', 'Status', $s->status, 'Deleted']]);
            Audit::log('t_sales', $s->id, 'DELETE', ['order_no' => $s->order_no, 'sku' => $s->sku], null, (int) $s->fk_id_ent);
            return ['message' => 'Sale line deleted.', 'undo' => $group];
        });
    }

    public static function monthInfo(int $branchId, string $month): array
    {
        $shop = DB::table('t_branches')->where('id', $branchId)->where('fk_id_ent', Context::ent())->first() ?? abort(404, 'Shop not found.');
        $q = DB::table('t_sales')->where('fk_id_ent', Context::ent())->where('fk_id_branch', $branchId)->whereRaw("DATE_FORMAT(d_order,'%Y-%m') = ?", [$month])->where('sale_source', '<>', 'history');
        $n = (clone $q)->count(); $conf = (clone $q)->where(fn ($w) => $w->where('status', 'Confirmed')->orWhere('f_locked', 1))->count(); $name = date('F Y', strtotime($month . '-01'));
        return ['shop' => $shop, 'lines' => $n, 'confirmed' => $conf, 'month_name' => $name,
            'text' => "All $n sale line(s) of {$shop->shop_no} - $name and its error list will be deleted. You can import this month again afterwards. They go to the recycle bin for " . self::binDays() . " days."];
    }

    public static function deleteMonth(int $branchId, string $month): array
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) throw new \InvalidArgumentException('Choose the month.');
        return DB::transaction(function () use ($branchId, $month) {
            $u = Auth::user(); Perm::need($u, 'orders.saleDeleteMonth');
            if (! Perm::shopAllowed($u, $branchId)) abort(response()->json(['message' => "You don't have rights to delete sales for this shop."], 403));
            $i = self::monthInfo($branchId, $month);
            if ($i['confirmed']) throw new \InvalidArgumentException("This month is confirmed and saved permanently, so it can't be deleted.");
            if (! $i['lines']) throw new \InvalidArgumentException('There are no sale lines in this month.');
            $group = "month:$branchId:$month:" . time(); $imports = [];
            foreach (DB::table('t_sales')->where('fk_id_ent', Context::ent())->where('fk_id_branch', $branchId)->whereRaw("DATE_FORMAT(d_order,'%Y-%m') = ?", [$month])->where('sale_source', '<>', 'history')->lockForUpdate()->get() as $s) { if ((int) $s->fk_id_import) $imports[(int) $s->fk_id_import] = 1; self::bin($s, $group, "Sales for {$i['month_name']} deleted"); }
            if ($imports) DB::table('t_sale_import_errors')->whereIn('fk_id_import', array_keys($imports))->delete();
            TaskEngine::close('SAL_UNMATCHED', [$branchId, $month], 'Month deleted');
            Audit::log('t_branches', $branchId, 'DELETE', ['month' => $month, 'lines' => $i['lines']], 'Sales month deleted', (int) Context::ent());
            return ['message' => "Sales for {$i['month_name']} deleted.", 'lines' => $i['lines'], 'undo' => $group];
        });
    }

    /** Put back what one delete took out (a line or a whole month), while it is still in the bin and its order line was not imported again. */
    public static function restore(string $group): array
    {
        return DB::transaction(function () use ($group) {
            $rows = DB::table('mo_recycle_bin')->where('group_key', $group)->where('fk_id_ent', Context::ent())->where('table_name', 't_sales')->whereNull('restored_at')->lockForUpdate()->get();
            if ($rows->isEmpty()) throw new \InvalidArgumentException('Nothing to put back – it was restored already, or the ' . self::binDays() . ' days are over.');
            $u = Auth::user(); Perm::need($u, 'orders.saleDelete'); $back = 0; $skipped = 0;
            foreach ($rows as $b) {
                $row = json_decode($b->row_json, true);
                if (! Perm::shopAllowed($u, (int) $row['fk_id_branch'])) { $skipped++; continue; }
                if (DB::table('t_sales')->where('id', $row['id'])->exists() || DB::table('t_sales')->where(['fk_id_ent' => $row['fk_id_ent'], 'fk_id_branch' => $row['fk_id_branch'], 'order_no' => $row['order_no'], 'sku' => $row['sku']])->exists()) { $skipped++; continue; }
                DB::table('t_sales')->insert($row); DB::table('mo_recycle_bin')->where('id', $b->id)->update(['restored_at' => now()]);
                if ((int) $row['fk_id_import']) DB::table('t_sale_imports')->where('id', $row['fk_id_import'])->increment('saved_rows');
                if ($row['sale_source'] === 'active' && $row['fk_product_id'] && $row['status'] === 'Pending' && ($row['fulfilment_channel'] ?: 'SELF') === 'SELF') DispatchService::reserve((int) $row['id']);
                self::log((int) $row['id'], 'Restore Sale', [['status', 'Status', 'Deleted', $row['status']]]); $back++;
            }
            return ['message' => "$back sale line(s) put back" . ($skipped ? " · $skipped left out (imported again since, or not your shop)" : '') . '.', 'back' => $back, 'skipped' => $skipped];
        });
    }

    /** Night job: the bin is emptied after 30 days. */
    public static function purgeBin(?int $days = null): int { return DB::table('mo_recycle_bin')->where('deleted_at', '<', now()->subDays($days ?? self::binDays()))->delete(); }

    /** R-A_SALES-61 the "Bought from" fields and R-A_SALES-16 the named file fields of one line, with every column of the file row. */
    public static function details(int $id): array
    {
        $s = DB::table('t_sales')->where('id', $id)->where('fk_id_ent', Context::ent())->first() ?? abort(404, 'Sale line not found.');
        if (! Perm::shopAllowed(Auth::user(), (int) $s->fk_id_branch)) abort(403, 'This shop is not one of your shops.');
        $v = $s->bought_vendor_id ? DB::table('t_vendors')->where('id', $s->bought_vendor_id)->first(['vendor_name', 'iso_vendor']) : null;
        $raw = json_decode((string) $s->raw_csv, true);
        return ['bought' => ['local' => (bool) $s->bought_local, 'supplier' => $v ? trim(($v->iso_vendor ?? '') . ' ' . ($v->vendor_name ?? '')) : null, 'ref' => $s->bought_ref, 'hold_ref' => $s->bought_ref_hold, 'date' => $s->bought_date, 'qty' => $s->bought_qty, 'rate' => $s->bought_rate, 'total' => $s->bought_total, 'reason' => $s->bought_reason, 'note' => $s->ds_note],
            'named' => ['promoted' => (bool) $s->f_promoted, 'vat' => $s->vat_amount, 'marketplace_tax' => $s->mp_tax_amount, 'tax_status' => $s->tax_status, 'dispatched_on' => $s->d_dispatched, 'tracking' => $s->tracking_no,
                'postage_charged' => DB::table('t_sale_charges')->where('fk_id_sale', $id)->where('kind', 'POSTAGE_CHARGED')->value('amount')],
            'file_row' => is_array($raw) ? $raw : []];
    }

    public static function history(int $id): array
    {
        $s = DB::table('t_sales')->where('id', $id)->where('fk_id_ent', Context::ent())->first();
        if ($s && ! Perm::shopAllowed(Auth::user(), (int) $s->fk_id_branch)) abort(403, 'This shop is not one of your shops.');
        return DB::table('t_sale_change_log')->where('fk_id_sale', $id)->orderByDesc('id')->limit(300)->get(['action', 'field_label', 'old_value', 'new_value', 'changed_by', 'changed_at', 'save_key'])->map(fn ($r) => (array) $r)->all();
    }

    // ---------- R-A_SALES-42 / 48: the list ----------
    /** The six period chips: this month, last month, 2–5 months ago – whole calendar months counted from $today. */
    public static function periods(?string $today = null): array
    {
        $y = (int) date('Y', strtotime($today ?: 'today')); $m = (int) date('n', strtotime($today ?: 'today')); $out = [];
        for ($i = 0; $i <= 5; $i++) { $t = mktime(0, 0, 0, $m - $i, 1, $y); $name = date('M-Y', $t);
            $out[] = ['key' => $i, 'label' => $i === 0 ? "Current Month ($name)" : ($i === 1 ? "Prev Month ($name)" : $name), 'from' => date('Y-m-01', $t), 'to' => date('Y-m-t', $t)]; }
        return $out;
    }

    public const PAGE_SIZES = [20, 50, 100, 200];

    /** The filtered query of the list (alias s), for other services (export, Re-match, the not-matched CSV). */
    public static function query(array $f, ?object $u) { if (empty($f['batch']) && empty($f['from']) && empty($f['to']) && ! empty($f['marketplace'])) { $f['from'] = date('Y-m-01'); $f['to'] = date('Y-m-t'); } return self::filtered($f, $u); }

    private static function filtered(array $f, ?object $u)
    {
        $ent = (int) Context::ent();
        $q = DB::table('t_sales as s')->where('s.fk_id_ent', $ent)->where('s.sale_source', '<>', 'history'); Perm::mine($q, 's.fk_id_branch');
        if (($f['type'] ?? '') === 'summary') $q->where('s.sale_source', 'summary'); elseif (empty($f['batch'])) $q->where('s.sale_source', '<>', 'summary');      // daily totals are shown apart, never inside the order lines
        if (! empty($f['batch'])) $q->where('s.fk_id_import', (int) $f['batch']);
        else { if (! empty($f['marketplace']) || empty($f['created_by'])) $q->where('s.fk_id_marketplace', (int) ($f['marketplace'] ?? 0)); if (! empty($f['from'])) $q->where('s.d_order', '>=', $f['from']); if (! empty($f['to'])) $q->where('s.d_order', '<=', $f['to']); }
        if (! empty($f['shop'])) $q->where('s.fk_id_branch', (int) $f['shop']);
        if (! empty($f['created_by'])) $q->where('s.created_by', (int) $f['created_by']);      // R-F_SETUP-26: drill from the Sales card "By VA"
        if (($f['status'] ?? '') === 'open') $q->whereNotIn('s.status', ['Cancelled', 'Refunded']);
        if (! empty($f['status']) && in_array($f['status'], ['Pending', 'Confirmed', 'Refunded', 'Cancelled'], true)) $q->where('s.status', $f['status']);
        if (! empty($f['type']) && $f['type'] !== 'summary') $q->where('s.sale_source', ['inventory' => 'active', 'ds' => 'ds', 'nm' => 'unmatched'][$f['type']] ?? 'active');
        if (($f['show'] ?? '') === 'loss') $q->where('s.profit', '<', 0);
        if (($f['show'] ?? '') === 'out') $q->where('s.f_out_of_period', '>=', 1);      // 1 = not answered yet, 2 = kept with a reason
        if (($f['show'] ?? '') === 'nocost') $q->whereIn('s.cost_source', ['current', 'product']);
        if (! empty($f['visibility']) && in_array($f['visibility'], ['POOL', 'PRIVATE'], true)) {      // live: the product's row for the shop, then the marketplace, then the parent's; the value stored at import only when no row exists
            $v = fn (string $pid, string $branch) => "(SELECT v.visibility_mode FROM t_product_visibility v WHERE v.fk_id_product = $pid AND v.fk_id_marketplace = s.fk_id_marketplace AND v.fk_id_branch = $branch LIMIT 1)"; $par = '(SELECT pp.f_id_parent FROM t_product_vendors pp WHERE pp.id = s.fk_product_id)';
            $q->where('s.fk_product_id', '>', 0)->whereRaw('UPPER(COALESCE(' . $v('s.fk_product_id', 's.fk_id_branch') . ', ' . $v('s.fk_product_id', '0') . ', ' . $v($par, 's.fk_id_branch') . ', ' . $v($par, '0') . ", NULLIF(s.visibility_mode, ''))) = ?", [$f['visibility']]); }
        if (! empty($f['vendor_inventory'])) $q->whereIn('s.fk_product_id', DB::table('t_product_vendors')->where('fk_id_vendor', (int) $f['vendor_inventory'])->select('id'));      // "Vendor inventory": lines of that vendor's products
        if (($mine = Perm::vendors(\Illuminate\Support\Facades\Auth::user())) !== null) $q->whereIn('s.fk_product_id', DB::table('t_product_vendors')->whereIn('fk_id_vendor', $mine)->select('id'));      // a person limited to vendors sees only their inventory
        if (! empty($f['company'])) { $c = (int) $f['company']; $q->where(fn ($w) => $w->where('s.fk_id_company', $c)->orWhere(fn ($x) => $x->where(fn ($y) => $y->whereNull('s.fk_id_company')->orWhere('s.fk_id_company', 0))->whereIn('s.fk_id_branch', DB::table('t_branches')->where('fk_id_company', $c)->select('id')))); }
        if (! empty($f['vendor'])) $q->where('s.fk_id_vendor', (int) $f['vendor']);
        if (! empty($f['investor'])) { $inv = (int) $f['investor']; $pids = DB::table('t_product_investors')->where('fk_id_account', $inv)->select('fk_id_product');      // the product's investor, or its parent's
            $q->where(fn ($w) => $w->whereIn('s.fk_product_id', $pids)->orWhereIn('s.fk_product_id', DB::table('t_product_vendors')->whereIn('f_id_parent', DB::table('t_product_investors')->where('fk_id_account', $inv)->select('fk_id_product'))->select('id'))); }
        if (! empty($f['ds_platform'])) $q->whereIn('s.fk_drp_item', DB::table('drp_items')->where('fk_platform', (int) $f['ds_platform'])->select('id'));
        if (! empty($f['carrier'])) { [$k, $v] = array_pad(explode(':', (string) $f['carrier'], 2), 2, '');
            if ($k === 'svc') $q->where('s.fk_id_car_pkg_svc', (int) $v); elseif ($k === 'car') $q->whereIn('s.fk_id_car_pkg_svc', DB::table('t_carrier_pkg_svc')->where('fk_id_carrier', (int) $v)->select('id')); }
        if (($t = trim((string) ($f['q'] ?? ''))) !== '') { $like = '%' . addcslashes($t, '%_\\') . '%'; $q->where(fn ($w) => $w->where('s.order_no', 'like', $like)->orWhere('s.sku', 'like', $like)->orWhere('s.cust_name', 'like', $like)->orWhere('s.product_info', 'like', $like)); }
        return $q;
    }

    /** What the filter controls offer (R-A_SALES-42): batches of the shop, vendors, investors, companies, drop-ship platforms, carriers with their services. */
    public static function filterLists(array $f, ?object $u): array
    {
        $ent = (int) Context::ent(); $name = "TRIM(CONCAT_WS(' ', a.first_name, a.sur_name))";
        $batches = Perm::mine(DB::table('t_sale_imports as i'), 'i.fk_id_branch')->join('t_branches as b', 'b.id', '=', 'i.fk_id_branch')->where('i.fk_id_ent', $ent)->when(! empty($f['marketplace']), fn ($q) => $q->where('i.fk_id_marketplace', (int) $f['marketplace']))->when(! empty($f['shop']), fn ($q) => $q->where('i.fk_id_branch', (int) $f['shop']))
            ->orderByDesc('i.period_year')->orderByDesc('i.period_month')->limit(60)->get(['i.id', 'b.shop_no', 'i.period_month', 'i.period_year', 'i.status'])->map(fn ($b) => ['id' => $b->id, 'name' => $b->shop_no . ' - ' . SalesImport::monthName((int) $b->period_month, (int) $b->period_year) . ($b->status === 'Pending' ? '' : ' (' . $b->status . ')')])->all();
        $carriers = []; foreach (DB::table('t_carrier_pkg_svc as v')->join('t_carriers as c', 'c.id', '=', 'v.fk_id_carrier')->whereIn('c.fk_id_ent', [0, $ent])->orderBy('c.carrier_name')->orderBy('v.pkg_svc_name')->get(['v.id', 'v.pkg_svc_name', 'c.id as cid', 'c.carrier_name']) as $v) { $carriers[$v->cid] ??= ['id' => 'car:' . $v->cid, 'name' => $v->carrier_name, 'services' => []]; $carriers[$v->cid]['services'][] = ['id' => 'svc:' . $v->id, 'name' => $v->pkg_svc_name]; }
        return ['batches' => $batches, 'carriers' => array_values($carriers), 'visibility' => [['id' => 'POOL', 'name' => 'Central (Pool)'], ['id' => 'PRIVATE', 'name' => 'Private']],
            'vendor_inventory' => DB::table('t_account as a')->whereIn('a.id', DB::table('t_product_vendors')->where('fk_id_ent', $ent)->where('fk_id_vendor', '>', 0)->when(Perm::vendors(\Illuminate\Support\Facades\Auth::user()) !== null, fn ($x) => $x->whereIn('fk_id_vendor', Perm::vendors(\Illuminate\Support\Facades\Auth::user())))->distinct()->select('fk_id_vendor'))->orderByRaw($name)->limit(300)->get(['a.id', DB::raw("$name as name")])->all(),
            'vendors' => DB::table('t_account as a')->whereIn('a.id', DB::table('t_sales')->where('fk_id_ent', $ent)->whereNotNull('fk_id_vendor')->where('fk_id_vendor', '>', 0)->distinct()->select('fk_id_vendor'))->orderByRaw($name)->limit(300)->get(['a.id', DB::raw("$name as name")])->all(),
            'investors' => DB::table('t_account as a')->whereIn('a.id', DB::table('t_product_investors')->distinct()->select('fk_id_account'))->where('a.fk_id_ent', $ent)->orderByRaw($name)->limit(300)->get(['a.id', DB::raw("$name as name")])->all(),
            'companies' => DB::table('t_enterprise')->whereIn('id', DB::table('t_branches')->where('fk_id_ent', $ent)->whereNotNull('fk_id_company')->distinct()->select('fk_id_company'))->orWhere('id', $ent)->orderBy('ent_name')->get(['id', 'ent_name as name'])->all(),
            'ds_platforms' => DB::table('drp_platforms')->orderBy('platform_name')->get(['id', DB::raw("CONCAT(platform_name, IF(f_active = 1, '', ' (inactive)')) as name")])->all()];
    }

    public static function list(array $f, ?object $u): array
    {
        $ent = (int) Context::ent();
        $mps = DB::table('cat_marketplaces')->whereIn('fk_id_ent', [0, $ent])->orderBy('marketplace_short')->get(['id', DB::raw("COALESCE(NULLIF(marketplace_short,''), NULLIF(marketplace_descr,''), CONCAT('Marketplace ', id)) as name"), 'f_active']);
        $shops = array_map(fn ($x) => ['id' => $x['id'], 'marketplace' => $x['marketplace'], 'name' => $x['label'], 'active' => $x['active'], 'va' => $x['va'], 'va_pct' => $x['va_pct']], ShopLabel::list($u));      // the one shop label, sorted by marketplace then shop no
        $base = ['marketplaces' => $mps, 'shops' => $shops, 'periods' => self::periods(), 'page_sizes' => self::PAGE_SIZES, 'natures' => self::NATURES, 'source_tags' => self::SOURCE_TAGS,
            'rights' => ['see_cost' => $u && Perm::has($u, 'orders.MONEY'), 'delete_month' => $u && Perm::has($u, 'orders.saleDeleteMonth'), 'export' => $u && Perm::has($u, 'orders.saleExport'), 'bulk_delete' => $u && Perm::has($u, 'orders.saleBulkDelete'), 'rematch' => $u && Perm::has($u, 'orders.dsRematch'), 'ds_link' => $u && Perm::has($u, 'orders.dsLink'), 'unlock' => $u && Perm::has($u, 'orders.saleUnlock'), 'import' => $u && Perm::has($u, 'orders.saleImport'), 'confirm' => $u && Perm::has($u, 'orders.CONFIRM')],
            'self_check' => SalesImport::selfCheckMessage()];
        if (! empty($f['batch']) && ($bt = DB::table('t_sale_imports as i')->join('t_branches as b', 'b.id', '=', 'i.fk_id_branch')->where('i.id', (int) $f['batch'])->where('i.fk_id_ent', $ent)->first(['i.id', 'i.status', 'i.error_rows', 'i.period_month', 'i.period_year', 'i.fk_id_branch', 'b.shop_no', 'b.shop_name'])))
            $base['batch_info'] = ['id' => $bt->id, 'label' => 'Showing one import only: ' . $bt->shop_no . ' (' . $bt->shop_name . ') - ' . SalesImport::monthName((int) $bt->period_month, (int) $bt->period_year), 'status' => $bt->status, 'errors' => (int) $bt->error_rows, 'shop' => (int) $bt->fk_id_branch, 'month' => sprintf('%04d-%02d', $bt->period_year, $bt->period_month)];
        if (empty($f['marketplace']) && empty($f['batch']) && empty($f['created_by'])) return $base + ['need' => 'Select a Marketplace (and a Shop, or all shops) to show the sales.', 'rows' => [], 'total' => 0];
        if (empty($f['batch']) && empty($f['from']) && empty($f['to'])) { $f['from'] = date('Y-m-01'); $f['to'] = date('Y-m-t'); }
        $per = (int) ($f['per_page'] ?? 50); if (! in_array($per, self::PAGE_SIZES, true)) $per = 50; $page = max(1, (int) ($f['page'] ?? 1));
        if (! empty($f['_limit'])) { $per = (int) $f['_limit']; $page = 1; }      // the export takes the whole filtered list
        $q = self::filtered($f, $u); $total = (clone $q)->count();
        $strip = SaleView::strip($q, (bool) $base['rights']['see_cost']);
        // counts for the presets, under the same scope but without the "show" / type choice
        $g = $f; unset($g['show'], $g['type'], $g['status']); $cq = self::filtered($g, $u);
        $counts = (array) (clone $cq)->selectRaw("SUM(s.profit < 0) loss, SUM(s.f_out_of_period >= 1) `out`, SUM(s.cost_source IN ('current','product')) nocost, SUM(s.sale_source = 'unmatched') nm, SUM(s.sale_source = 'ds') ds, SUM(s.status = 'Refunded') refunded")->first();
        $rows = $q->leftJoin('t_branches as b', 'b.id', '=', 's.fk_id_branch')->leftJoin('t_carrier_pkg_svc as cv', 'cv.id', '=', 's.fk_id_car_pkg_svc')->leftJoin('t_carriers as cc', 'cc.id', '=', 'cv.fk_id_carrier')->leftJoin('t_packings as pk', 'pk.id', '=', 's.fk_id_packing')->leftJoin('t_enterprise as co', 'co.id', '=', DB::raw('COALESCE(NULLIF(s.fk_id_company,0), b.fk_id_company, s.fk_id_ent)'))
            ->orderByDesc('s.d_order')->orderByDesc('s.id')->offset(($page - 1) * $per)->limit($per)
            ->get(['s.id', 's.fk_id_branch', 's.fk_id_import', 's.order_no', 's.d_order', 's.cust_name', 's.cust_contacts', 's.sku', 's.product_info', 's.qty', 's.sale_rate', 's.total_amount', 's.raw_price', 's.carrier_rate', 's.packing_rate', 's.buy_rate', 's.buy_amount', 's.profit', 's.d_buy', 's.product_url',
                's.cost_source', 's.f_out_of_period', 's.f_not_in_file', 's.status', 's.f_locked', 's.f_edited', 's.refund_loss_nature', 's.refund_loss_amount', 's.refund_reason', 's.visibility_mode', 's.sale_source', 's.fk_drp_item', 's.ds_match_count', 's.ds_note', 's.fk_product_id', 's.fk_id_car_pkg_svc', 's.fk_id_packing', 's.fulfilment_channel', 's.raw_csv', 's.fk_id_marketplace',
                'b.shop_no', 'b.shop_name', DB::raw("CONCAT(cc.carrier_name, '-', cv.pkg_svc_name) as carrier_name"), 'pk.packing_name', 'co.ent_name as company']);
        $money = $base['rights']['see_cost'];
        $pids = $rows->pluck('fk_product_id')->filter()->unique()->values(); $parents = $pids->isEmpty() ? [] : DB::table('t_product_vendors')->whereIn('id', $pids)->where('f_id_parent', '>', 0)->pluck('f_id_parent', 'id')->all(); $both = $pids->merge(array_values($parents))->unique()->values();
        $invs = []; foreach (DB::table('t_product_investors as pi')->join('t_account as a', 'a.id', '=', 'pi.fk_id_account')->whereIn('pi.fk_id_product', $both)->orderBy('pi.id')->get(['pi.fk_id_product', DB::raw("TRIM(CONCAT_WS(' ', NULLIF(a.first_name,''), NULLIF(a.sur_name,''))) as n")]) as $i) $invs[$i->fk_id_product][] = $i->n;      // a child shows its own + its parent's investors
        $vis = $both->isEmpty() ? [] : DB::table('t_product_visibility')->whereIn('fk_id_product', $both)->get()->all();      // Pool / Private is shown live, with the parent as fallback
        $lotOwners = []; $done = $rows->where('status', 'Confirmed')->pluck('id'); if ($done->isNotEmpty()) foreach (DB::table('fin_lot_consumption as c')->leftJoin('t_account as a', 'a.id', '=', 'c.fk_id_owner')->whereIn('c.fk_id_sale', $done)->whereNull('c.fk_id_reverses')->get(['c.fk_id_sale', 'c.fk_id_owner', DB::raw("TRIM(CONCAT_WS(' ', NULLIF(a.first_name,''), NULLIF(a.sur_name,''))) as n")]) as $c) { $n = $c->fk_id_owner ? ($c->n ?: 'Account ' . $c->fk_id_owner) : 'Pool'; if (! in_array($n, $lotOwners[$c->fk_id_sale] ?? [], true)) $lotOwners[$c->fk_id_sale][] = $n; }
        $dsi = $rows->pluck('fk_drp_item')->filter()->isEmpty() ? collect() : DB::table('drp_items as d')->leftJoin('drp_platforms as p', 'p.id', '=', 'd.fk_platform')->whereIn('d.id', $rows->pluck('fk_drp_item')->filter()->unique())->get(['d.id', 'd.ship_name', 'd.qty', 'p.platform_name'])->keyBy('id');
        $plats = $dsi->map(fn ($x) => $x->platform_name)->all();
        $seeInv = $u && Perm::has($u, 'sale.see_investor');      // investors of a line: a right of its own (R-A_SALES-82)
        $vaPct = $money ? DB::table('t_sale_imports')->whereIn('id', $rows->pluck('fk_id_import')->filter()->unique()->all() ?: [0])->pluck('va_share_percentage', 'id')->all() : [];      // the batch's frozen VA % (R-C-40)
        $out = $rows->map(function ($s) use ($u, $money, $invs, $plats, $dsi, $vis, $parents, $lotOwners, $seeInv, $vaPct) {
            $kind = self::kind($s); $a = self::actions($s, $u);
            $row = ['id' => $s->id, 'order_no' => $s->order_no, 'd_order' => $s->d_order, 'cust_name' => $s->cust_name, 'cust_contacts' => $s->cust_contacts, 'sku' => $kind === 'DS' && preg_match('/^(NOSKU|DS-\d+)/', (string) $s->sku) ? '-' : $s->sku, 'product_info' => $s->product_info, 'qty' => (int) $s->qty,
                'sale_rate' => (float) $s->sale_rate, 'total_amount' => (float) $s->total_amount, 'status' => $s->status, 'confirmed' => self::confirmed($s), 'kind' => $kind, 'edited' => (bool) $s->f_edited, 'out_of_period' => (bool) $s->f_out_of_period, 'not_in_file' => (bool) ($s->f_not_in_file ?? 0), 'shop' => trim($s->shop_no . ' ' . $s->shop_name), 'shop_no' => $s->shop_no, 'fk_id_branch' => $s->fk_id_branch,
                'visibility' => $kind === 'DS' ? 'Drop shipping' : ($kind === 'NM' ? 'Not matched' : ((($v = self::visibility((int) $s->fk_product_id, $parents[$s->fk_product_id] ?? null, (int) $s->fk_id_marketplace, (int) $s->fk_id_branch, $vis)) ?: strtoupper((string) $s->visibility_mode)) === 'POOL' ? 'Pool' : (($v ?: strtoupper((string) $s->visibility_mode)) === 'PRIVATE' ? 'Private' : 'Not set'))), 'company' => $s->company, 'nm_note' => $kind === 'NM' ? $s->ds_note : null, 'product_id' => (int) $s->fk_product_id ?: null, 'ds_item' => (int) $s->fk_drp_item ?: null,
                'refund' => $s->status === 'Refunded' ? ['nature' => self::NATURES[$s->refund_loss_nature] ?? $s->refund_loss_nature, 'loss' => (float) $s->refund_loss_amount, 'reason' => $s->refund_reason] : null,
                'actions' => array_map(fn ($x) => ['ok' => (bool) $x[0], 'why' => $x[0] ? null : $x[1]], $a), 'month' => substr((string) $s->d_order, 0, 7),
                'investor' => ! $seeInv ? null : ($kind === 'OWN' ? (implode(', ', self::investorsOf((int) $s->fk_product_id, $parents[$s->fk_product_id] ?? null, $invs)) ?: 'None') : ($kind === 'DS' ? 'Drop shipping' : 'None')), 'lot_owners' => isset($lotOwners[$s->id]) ? implode(', ', $lotOwners[$s->id]) : null, 'ds_platform' => $plats[$s->fk_drp_item] ?? null,
                'ds' => $kind === 'DS' ? ['n' => (int) $s->ds_match_count, 'colour' => (int) $s->ds_match_count >= 5 ? '#28a745' : ((int) $s->ds_match_count === 4 ? '#20c997' : '#ffc107'), 'buyer' => $dsi[$s->fk_drp_item]->ship_name ?? null, 'platform' => $plats[$s->fk_drp_item] ?? null, 'qty' => isset($dsi[$s->fk_drp_item]) ? (int) $dsi[$s->fk_drp_item]->qty : null,
                    'differs' => isset($dsi[$s->fk_drp_item]) && (int) $dsi[$s->fk_drp_item]->qty !== (int) $s->qty ? 'Not the same as the sale quantity (' . (int) $s->qty . ')' : null, 'gone' => ! isset($dsi[$s->fk_drp_item])] : null, 'best' => $kind === 'NM' ? 'best ' . (int) $s->ds_match_count . '/5' : null, 'xc' => SaleView::extraValues($s->raw_csv, (int) $s->fk_id_marketplace)];
            if ($money) $row += ['raw_price' => $kind === 'OWN' ? (float) $s->raw_price : 0.0, 'carrier_rate' => $kind === 'OWN' ? (float) $s->carrier_rate : 0.0, 'packing_rate' => $kind === 'OWN' ? (float) $s->packing_rate : 0.0, 'buy_rate' => $kind === 'DS' ? 0.0 : (float) $s->buy_rate, 'buy_amount' => (float) $s->buy_amount, 'profit' => (float) $s->profit,
                'carrier_name' => $s->carrier_name, 'packing_name' => $s->packing_name, 'cost_source' => $s->cost_source, 'cost_tag' => self::SOURCE_TAGS[$s->cost_source] ?? null, 'd_buy' => $s->d_buy, 'product_url' => $s->product_url, 'loss' => (float) $s->profit < 0]
                + (($p = $vaPct[$s->fk_id_import] ?? null) !== null ? ['va_pct' => (float) $p, 'va_commission' => round((float) $s->profit * (float) $p / 100, 2), 'net_after_va' => round((float) $s->profit - round((float) $s->profit * (float) $p / 100, 2), 2)] : []);      // per-line commission: information only, totals use the batch formula
            return $row;
        })->all();
        return $base + ['rows' => $out, 'total' => $total, 'page' => $page, 'per_page' => $per, 'pages' => max(1, (int) ceil($total / $per)), 'filter' => $f, 'counts' => array_map('intval', $counts),
            'totals' => $strip, 'columns' => SaleView::columns($u), 'lists' => self::filterLists($f, $u)];
    }
}
