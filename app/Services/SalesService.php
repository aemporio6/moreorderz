<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Sale lines (t_sales): intake of orders, matching to product / listing / price period, and Sales Month Confirm –
 * the only place where the cost of a sale is taken from the lots (RULES_A_SALES-32, IC-1).
 */
class SalesService
{
    /**
     * Take in order lines for one shop. Each line: order_no, d_order, sku (product SKU or the listing's marketplace SKU), qty, sale_rate,
     * optional cust_name, ship_to_*, tracking_no, dispatch_by, currency. The same order line arriving again (shop + order no + SKU) updates
     * the line instead of making a second one. Returns counts and the ids.
     */
    public static function intake(int $branchId, array $lines, array $opt = []): array
    {
        $ent = (int) Context::ent();
        $shop = DB::table('t_branches')->where('id', $branchId)->where('fk_id_ent', $ent)->first() ?? throw new \RuntimeException('Shop not found in this company.');
        $out = ['new' => 0, 'updated' => 0, 'unmatched' => 0, 'short' => 0, 'ids' => []];
        $batch = $opt['batch'] ?? null;
        foreach ($lines as $i => $l) {
            $r = DB::transaction(function () use ($l, $i, $shop, $ent, $batch) {
                foreach (['order_no', 'd_order', 'sku', 'qty'] as $k) if (! isset($l[$k]) || $l[$k] === '') throw new \InvalidArgumentException('Line ' . ($i + 1) . ": $k is missing.");
                $qty = (int) $l['qty']; if ($qty <= 0) throw new \InvalidArgumentException('Line ' . ($i + 1) . ': quantity must be more than zero.');
                $sku = trim((string) $l['sku']); $pack = 1; $listing = null;
                $product = DB::table('t_product_vendors')->where('fk_id_ent', $ent)->where('SKU_VENDOR', $sku)->first();
                if (! $product) {      // the shop's own SKU for it (channel SKU map), with pack quantity
                    $listing = DB::table('t_product_listings')->where('fk_id_ent', $ent)->where('fk_id_branch', $shop->id)->where(fn ($w) => $w->where('marketplace_sku', $sku)->orWhere('item_id', $sku))->where('f_active', 1)->first();
                    if ($listing) { $product = DB::table('t_product_vendors')->where('id', $listing->fk_id_product)->first(); $pack = max(1, (int) $listing->pack_qty); }
                }
                $rate = round((float) ($l['sale_rate'] ?? 0), 2); $total = round($qty * $rate, 2);
                $cur = strtoupper($l['currency'] ?? Fx::code($shop->fk_id_payout_currency, $ent));
                $period = $product ? PriceService::on((int) $product->id, (int) $shop->fk_id_marketplace, $l['d_order']) : null;
                $row = ['fk_id_ent' => $ent, 'fk_id_import' => (int) ($batch ?? 0), 'fk_id_branch' => $shop->id, 'fk_id_marketplace' => (int) $shop->fk_id_marketplace, 'fk_product_id' => $product->id ?? 0, 'order_no' => trim((string) $l['order_no']),
                    'd_order' => $l['d_order'], 'cust_name' => (string) ($l['cust_name'] ?? ''), 'cust_contacts' => (string) ($l['cust_contacts'] ?? ''), 'sku' => $sku, 'product_info' => (string) ($l['product_info'] ?? ($product->product_name_vendor ?? '')),
                    'qty' => $qty * $pack, 'sale_rate' => $pack > 1 ? round($rate / $pack, 2) : $rate, 'total_amount' => $total, 'raw_price' => $period->raw_price ?? 0, 'fk_id_price_period' => $period->id ?? null, 'f_out_of_period' => $product && ! $period ? 1 : 0,
                    'status' => 'Pending', 'sale_source' => $product ? 'active' : 'unmatched', 'fk_id_listing' => $listing->id ?? null, 'fk_id_currency' => BuyingService::currencyId($cur), 'total_amount_gbp' => Fx::toBase($total, $cur, $l['d_order'], $ent),
                    'fk_id_batch' => $batch, 'line_status' => 'OPEN', 'fulfilment_channel' => $l['fulfilment_channel'] ?? ($shop->fulfilment_channel ?: 'SELF'), 'fk_id_sell_location' => $shop->fk_id_main_location,
                    'tracking_no' => $l['tracking_no'] ?? null, 'ship_to_name' => $l['ship_to_name'] ?? ($l['cust_name'] ?? null), 'ship_to_phone' => $l['ship_to_phone'] ?? null, 'ship_to_address' => $l['ship_to_address'] ?? null,
                    'ship_to_city' => $l['ship_to_city'] ?? null, 'ship_to_postcode' => $l['ship_to_postcode'] ?? null, 'ship_to_country_code' => isset($l['ship_to_country_code']) ? strtoupper(substr($l['ship_to_country_code'], 0, 2)) : null, 'dispatch_by' => $l['dispatch_by'] ?? null];
                $old = DB::table('t_sales')->where('fk_id_ent', $ent)->where('fk_id_branch', $shop->id)->where('order_no', $row['order_no'])->where('sku', $sku)->lockForUpdate()->first();
                if ($old) {
                    if ($old->status !== 'Pending' || $old->f_locked) return ['id' => (int) $old->id, 'kind' => 'locked'];
                    DB::table('t_sales')->where('id', $old->id)->update(array_intersect_key($row, array_flip(['qty', 'sale_rate', 'total_amount', 'total_amount_gbp', 'tracking_no', 'ship_to_name', 'ship_to_phone', 'ship_to_address', 'ship_to_city', 'ship_to_postcode', 'dispatch_by', 'fk_product_id', 'sale_source', 'fk_id_listing', 'fk_id_price_period', 'raw_price'])) + ['updated_at' => now(), 'f_edited' => 1]);
                    $id = (int) $old->id; $kind = 'updated';
                } else {
                    $id = (int) DB::table('t_sales')->insertGetId(RecordService::fillNotNull('t_sales', $row + ['created_by' => Context::userId(), 'created_at' => now()]));
                    $kind = 'new';
                }
                $short = 0;
                if ($product && $row['fulfilment_channel'] === 'SELF') { DispatchService::reserve($id); $short = (int) DB::table('wh_reservations')->where('fk_id_sale', $id)->whereNotIn('status', ['RELEASED'])->value('qty_short'); }
                if ($total <= 0) TaskEngine::open('SAL_ZERO', [$id], "Sale line with zero value: order {$row['order_no']} · $sku", ['ent' => $ent, 'module' => 't_sales', 'record_id' => $id]);
                return ['id' => $id, 'kind' => $kind, 'unmatched' => ! $product, 'short' => $short > 0];
            });
            $out['ids'][] = $r['id'];
            if ($r['kind'] === 'new') $out['new']++; elseif ($r['kind'] === 'updated') $out['updated']++;
            if (! empty($r['unmatched'])) $out['unmatched']++;
            if (! empty($r['short'])) $out['short']++;
        }
        $month = substr((string) ($lines[0]['d_order'] ?? date('Y-m-d')), 0, 7);
        $unm = DB::table('t_sales')->where('fk_id_ent', $ent)->where('fk_id_branch', $branchId)->where('sale_source', 'unmatched')->where('status', 'Pending')->whereRaw("DATE_FORMAT(d_order,'%Y-%m') = ?", [$month])->count();
        $unm ? TaskEngine::open('SAL_UNMATCHED', [$branchId, $month], "$unm sale line(s) of {$shop->shop_name} $month are not matched to a product", ['ent' => $ent, 'module' => 't_branches', 'record_id' => $branchId])
             : TaskEngine::close('SAL_UNMATCHED', [$branchId, $month], 'All lines matched');
        return $out;
    }

    /** Lines of one shop and month that Confirm would work on. */
    private static function monthLines(int $ent, int $branchId, string $month)
    {
        return DB::table('t_sales')->where('fk_id_ent', $ent)->where('fk_id_branch', $branchId)->whereRaw("DATE_FORMAT(d_order,'%Y-%m') = ?", [$month])->whereIn('status', ['Pending', 'Refunded'])->where(fn ($w) => $w->whereNull('f_locked')->orWhere('f_locked', 0))->orderBy('id');
    }

    /** What Confirm will do, before doing it: counts, the units that leave stock, lines not shipped through the tool, and shortages. */
    public static function confirmPreview(int $branchId, string $month): array
    {
        $ent = (int) Context::ent();
        $lines = self::monthLines($ent, $branchId, $month)->get();
        $shipped = StockService::systemLocation($ent, 'SHIPPED');
        $take = []; $unshipped = []; $unmatched = 0; $refunded = 0;
        foreach ($lines as $s) {
            if ($s->status === 'Refunded') { $refunded++; continue; }
            if ($s->sale_source !== 'active' || ! $s->fk_product_id) { $unmatched++; continue; }
            if (($s->fulfilment_channel ?: 'SELF') !== 'SELF') continue;
            $out = (int) $s->qty_shipped - (int) $s->qty_returned_unconfirmed;
            if ($out < $s->qty) $unshipped[] = ['id' => $s->id, 'order_no' => $s->order_no, 'sku' => $s->sku, 'qty' => (int) $s->qty - max(0, $out), 'wh_status' => $s->wh_status];
            $take[$s->fk_product_id] = ($take[$s->fk_product_id] ?? ['shipped' => 0, 'unshipped' => 0]);
            $take[$s->fk_product_id]['shipped'] += max(0, $out); $take[$s->fk_product_id]['unshipped'] += (int) $s->qty - max(0, $out);
        }
        $short = [];
        foreach ($take as $pid => $t) {
            $inShipped = StockService::balance((int) $pid, $shipped);
            $onShelf = StockService::available((int) $pid) + StockService::balance((int) $pid, StockService::systemLocation($ent, 'DISPATCH'))       // sellable + already picked + what these very lines have reserved
                + (int) DB::table('wh_reservations')->where('fk_id_product', $pid)->whereIn('status', ['OPEN', 'ALLOCATED'])->sum(DB::raw('qty - COALESCE(qty_picked,0)'));
            if ($t['shipped'] > $inShipped || $t['unshipped'] > $onShelf) {
                $p = DB::table('t_product_vendors')->where('id', $pid)->first(['SKU_VENDOR', 'product_name_vendor']);
                $short[] = ['product' => (int) $pid, 'sku' => $p->SKU_VENDOR, 'name' => $p->product_name_vendor, 'in_stock' => $inShipped + $onShelf, 'needed' => $t['shipped'] + $t['unshipped']];
            }
        }
        $pending = $lines->where('status', 'Pending');
        return ['month' => $month, 'lines' => $lines->count(), 'to_confirm' => $pending->count(), 'refunded_kept' => $refunded, 'unmatched' => $unmatched, 'units_out' => array_sum(array_map(fn ($t) => $t['shipped'] + $t['unshipped'], $take)),
            'sales_total' => round((float) $pending->sum('total_amount_gbp'), 2), 'unshipped' => $unshipped, 'shortages' => $short, 'already_confirmed' => $lines->isEmpty() && DB::table('t_sales')->where('fk_id_ent', $ent)->where('fk_id_branch', $branchId)->whereRaw("DATE_FORMAT(d_order,'%Y-%m') = ?", [$month])->where('status', 'Confirmed')->exists()];
    }

    /**
     * Confirm the sales of one shop and month, all or nothing. Stock lines take their units out of SHIPPED (oldest lot first, owner rule),
     * which books the cost of sale; revenue is booked against the marketplace receivable of the shop. Lines that never went through
     * pick / pack / handover are booked as shipped now when $bookUnshipped is true (setting WH_UNSHIPPED_AT_CONFIRM), else Confirm is refused.
     */
    public static function confirmMonth(int $branchId, string $month, bool $bookUnshipped = false): array
    {
        $ent = (int) Context::ent();
        $pre = self::confirmPreview($branchId, $month);
        if ($pre['already_confirmed']) throw new \RuntimeException('This month is already confirmed.');
        if ($pre['lines'] === 0) throw new \RuntimeException('There are no sale lines to confirm in this month.');
        $shop = DB::table('t_branches')->where('id', $branchId)->first();
        if ($pre['shortages']) {
            foreach ($pre['shortages'] as $sh) TaskEngine::open('SAL_STOCK_SHORT', [$sh['sku'], $branchId . '-' . $month], "Not enough stock to confirm $month of {$shop->shop_name}: {$sh['sku']} needs {$sh['needed']}, has {$sh['in_stock']}", ['ent' => $ent, 'module' => 't_product_vendors', 'record_id' => $sh['product']]);
            throw new \RuntimeException('Not enough stock for ' . count($pre['shortages']) . ' product(s). Nothing was confirmed.');
        }
        $mode = (string) Settings::get('WH_UNSHIPPED_AT_CONFIRM', $ent, 'ASK');
        if ($pre['unshipped'] && ! $bookUnshipped && $mode !== 'AUTO') {
            TaskEngine::open('WH_UNSHIPPED_LINES', [$branchId . '-' . $month], count($pre['unshipped']) . " own-stock line(s) of {$shop->shop_name} $month have no shipment in the tool", ['ent' => $ent, 'module' => 't_branches', 'record_id' => $branchId]);
            throw new \RuntimeException(count($pre['unshipped']) . ' line(s) were not shipped through the tool. Choose "Book as shipped now" to confirm them, or ship them first.');
        }
        return DB::transaction(function () use ($ent, $branchId, $month, $shop) {
            $shipped = StockService::systemLocation($ent, 'SHIPPED'); $end = date('Y-m-t', strtotime($month . '-01'));
            // a late line of a locked month is booked forward, dated today; the locked month itself never changes (R-FIN-47)
            $postDate = Ledger::periodLocked($ent, $month) ? date('Y-m-d') : min($end, date('Y-m-d'));
            $n = 0; $units = 0; $cogs = 0.0; $revenue = 0.0; $backfilled = 0;
            foreach (self::monthLines($ent, $branchId, $month)->lockForUpdate()->get() as $s) {
                $upd = ['f_locked' => 1, 'd_confirmed' => now(), 'confirmed_by' => Context::userId(), 'updated_at' => now()];
                if ($s->status === 'Pending') {
                    $upd['status'] = 'Confirmed'; $revenue += (float) $s->total_amount_gbp; $n++;
                    if ($s->sale_source === 'active' && $s->fk_product_id && ($s->fulfilment_channel ?: 'SELF') === 'SELF') {
                        $r = DB::table('wh_reservations')->where('fk_id_sale', $s->id)->orderByDesc('id')->first();
                        $rule = ['rule' => ($r->owner_rule ?? 'POOL') === 'PRIVATE' ? 'PRIVATE' : 'POOL', 'owner' => ($r->fk_id_owner_seg ?? 0) ?: null];
                        $out = max(0, (int) $s->qty_shipped - (int) $s->qty_returned_unconfirmed); $miss = (int) $s->qty - $out;
                        if ($miss > 0) {      // shipped outside the tool: book it as shipped now, from the shelf (or from Dispatch when already picked)
                            foreach (DB::table('wh_reservations')->where('fk_id_sale', $s->id)->whereIn('status', ['OPEN', 'ALLOCATED', 'PICKED'])->get() as $x) {
                                DB::table('wh_pick_task_lines')->where('fk_id_reservation', $x->id)->where('line_status', 'OPEN')->update(['line_status' => 'CANCELLED']);
                                DB::table('wh_reservations')->where('id', $x->id)->update(['status' => 'SHIPPED', 'release_reason' => 'Booked as shipped at Month Confirm', 'updated_at' => now()]);
                            }
                            foreach (self::shelfBins((int) $s->fk_product_id, $ent, $miss) as [$loc, $q]) StockService::transfer($ent, (int) $s->fk_product_id, $q, $loc, $shipped, 'Backfill at Month Confirm', $rule + ['source_type' => 'SHIP', 'source_id' => $s->id]);
                            $upd['f_ship_backfill'] = 1; $upd['qty_shipped'] = (int) $s->qty_shipped + $miss; $backfilled++;
                        }
                        $c = StockService::consumeForSale($ent, (int) $s->id, (int) $s->fk_product_id, (int) $s->qty, $shipped, $rule['owner'], ['date' => $postDate]);
                        $upd += ['f_stock_deducted' => 1, 'cost_gbp' => $c['cost'], 'buy_amount' => $c['cost'], 'buy_rate' => $s->qty > 0 ? round($c['cost'] / $s->qty, 2) : 0, 'cost_source' => 'lots', 'profit' => round((float) $s->total_amount_gbp - $c['cost'], 2), 'wh_status' => 'CONFIRMED'];
                        $units += (int) $s->qty; $cogs += $c['cost'];
                    } else {
                        $upd += ['f_stock_deducted' => 0, 'profit' => $s->sale_source === 'active' ? (float) $s->total_amount_gbp : 0];
                    }
                }
                DB::table('t_sales')->where('id', $s->id)->update($upd);
            }
            $revenue = round($revenue, 2);
            if ($revenue > 0) Ledger::post($ent, $postDate, 'SALE_CONFIRM', $branchId, [['account' => '1100', 'debit' => $revenue, 'ref_id' => $branchId], ['account' => '4000', 'credit' => $revenue, 'ref_id' => $branchId]], ['memo' => "Sales {$shop->shop_name} $month"]);
            Audit::log('t_branches', $branchId, 'LOCK', ['month' => $month, 'lines' => $n, 'units' => $units, 'sales' => $revenue, 'cost' => round($cogs, 2)], 'Sales Month Confirm', $ent);
            TaskEngine::close('WH_UNSHIPPED_LINES', [$branchId . '-' . $month], 'Confirmed');
            EventBus::fire('SALE_MONTH_CONFIRMED', 't_branches', $branchId, ['month' => $month, 'lines' => $n, 'units' => $units, 'sales' => $revenue, 'cost' => round($cogs, 2)], $ent);
            return ['lines' => $n, 'units' => $units, 'sales' => $revenue, 'cost' => round($cogs, 2), 'profit_before_fees' => round($revenue - $cogs, 2), 'backfilled' => $backfilled];
        });
    }

    /** Bins to take $qty units from for a backfill: Dispatch first (already picked), then sellable shelves in walking order. */
    private static function shelfBins(int $productId, int $ent, int $qty): array
    {
        $out = []; $left = $qty;
        $rows = DB::table('fin_lot_balances as b')->join('fin_lots as l', 'l.id', '=', 'b.fk_id_lot')->join('cat_inventory_locations as c', 'c.id', '=', 'b.fk_id_location')->where('l.fk_id_product', $productId)->where('l.company_id', $ent)->where('b.qty', '>', 0)
            ->where(fn ($w) => $w->where('c.kind', 'DISPATCH')->orWhere(fn ($x) => $x->where(fn ($y) => $y->whereNull('c.kind')->orWhereNotIn('c.kind', ['DAMAGED', 'RETURNS', 'PRODUCTION', 'TRANSIT', 'WRITTEN_OFF', 'QUARANTINE', 'MISSING', 'SUPPLIER', 'CUSTOMER', 'SHIPPED', 'RECEIVING']))->where(fn ($y) => $y->whereNull('c.f_sellable')->orWhere('c.f_sellable', 1))))
            ->groupBy('b.fk_id_location', 'c.kind', 'c.pick_seq')->orderByRaw("c.kind = 'DISPATCH' DESC")->orderByRaw('c.pick_seq IS NULL, c.pick_seq')->get(['b.fk_id_location as loc', DB::raw('SUM(b.qty) as qty')]);
        foreach ($rows as $r) { if ($left <= 0) break; $t = min($left, (int) $r->qty); $out[] = [(int) $r->loc, $t]; $left -= $t; }
        if ($left > 0) throw new \RuntimeException('Not enough stock on the shelves to book the unshipped lines.');
        return $out;
    }
}
