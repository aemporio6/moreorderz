<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * 07_ICC – Inventory Cycle Calculator (IC-18, MR-0031).
 * inputs() merges the saved inputs (icc_inputs) with what the system knows (sales, stock, open POs, product sizes) and the defaults – every default it used is listed in "assumed";
 * calc() is the pure formula block of 07_ICC §3; run() stores the result (icc_results), the product cache columns, the ICC_* event on a colour change and the STK_REORDER task.
 */
class Icc
{
    public const MODES = ['AIR' => 14, 'SEA' => 60, 'TRUCK' => 33, 'RAIL' => 25, 'COURIER' => 7];
    public const STATUS_LABEL = ['RED' => 'Order now', 'YELLOW' => 'Order this week', 'GREEN' => 'Covered', 'NONE' => 'No sales'];

    /** Demand from the system: units sold in the last 90 days, scaled to 30 days by the days really covered (IC-18). */
    public static function monthlyFromSales(int $pid, ?string $today = null): ?int
    {
        $today ??= now()->toDateString(); $from = date('Y-m-d', strtotime("$today -90 days"));
        $q = DB::table('t_sales')->where('fk_product_id', $pid)->whereBetween('d_order', [$from, $today . ' 23:59:59'])->where(fn ($w) => $w->whereNull('line_status')->orWhere('line_status', '<>', 'CANCELLED'))
            ->where(fn ($w) => $w->whereNull('status')->orWhereNotIn('status', ['Cancelled', 'CANCELLED']));
        $units = (float) (clone $q)->sum('qty'); if ($units <= 0) return null;
        $first = (string) DB::table('t_sales')->where('fk_product_id', $pid)->min('d_order');
        $days = max(7, min(90, (int) floor((strtotime($today) - strtotime(substr($first, 0, 10))) / 86400) + 1));
        return (int) round($units / $days * 30);
    }

    /** The pipeline of open POs of the product: ordered / in production / in transit – each PO unit counted once (F-03). */
    public static function pipeline(int $pid): array
    {
        $lines = DB::table('t_src_po_lines as l')->join('t_src_po as p', 'p.id', '=', 'l.fk_id_po')->where('l.fk_id_product', $pid)->where('l.f_active', 1)->where('p.f_active', 1)
            ->whereNotIn(DB::raw("UPPER(COALESCE(p.status,''))"), ['CANCELLED', 'CLOSED', 'DRAFT'])->where(fn ($w) => $w->whereNull('l.line_type')->orWhereNotIn(DB::raw('UPPER(l.line_type)'), ['RFQ', 'SAMPLE']))
            ->get(['l.id', 'l.fk_id_po', 'l.qty_ordered', 'l.qty_confirmed']);
        $o = $pr = $t = 0; $pos = [];
        foreach ($lines as $l) {
            $qty = (int) ((float) $l->qty_confirmed > 0 ? $l->qty_confirmed : $l->qty_ordered); if ($qty <= 0) continue;
            $poQty = (int) DB::table('t_src_po_lines')->where('fk_id_po', $l->fk_id_po)->where('f_active', 1)->sum(DB::raw('COALESCE(NULLIF(qty_confirmed,0), qty_ordered)')) ?: $qty;
            $made = (int) DB::table('t_src_production')->where('fk_id_po', $l->fk_id_po)->where('f_active', 1)->sum(DB::raw('COALESCE(NULLIF(produced_qty,0), accepted_qty, 0)'));
            $made = (int) floor($made * $qty / max(1, $poQty));
            $shipped = (int) DB::table('t_src_shipment_po_link')->where('f_active', 1)->where(fn ($w) => $w->where('fk_id_po_line', $l->id)->orWhere(fn ($x) => $x->whereNull('fk_id_po_line')->where('fk_id_po', $l->fk_id_po)->where('fk_id_product', $pid)))->sum(DB::raw('COALESCE(actual_qty, 0)'));
            $recv = (int) DB::table('t_src_grn_lines')->where('fk_id_po_line', $l->id)->sum(DB::raw('COALESCE(NULLIF(accepted_qty,0), received_qty, 0)'));
            $shipped = min($qty, max($shipped, $recv)); $made = min($qty, max($made, $shipped)); $recv = min($recv, $shipped);
            if ($qty - $recv <= 0) continue;
            $o += $qty - $made; $pr += $made - $shipped; $t += $shipped - $recv; $pos[] = (int) $l->fk_id_po;
        }
        return ['ordered' => $o, 'production' => $pr, 'transit' => $t, 'po_ids' => array_values(array_unique($pos))];
    }

    /** Season factor of a calendar month (07_ICC §2). */
    public static function season(int $month): float { return in_array($month, [10, 11, 12, 1], true) ? 1.5 : (in_array($month, [6, 7, 8], true) ? 0.9 : 1.0); }

    public static function rates(): array
    {
        $out = [];
        foreach (DB::table('icc_freight_rates')->where(fn ($w) => $w->whereNull('valid_from')->orWhere('valid_from', '<=', now()->toDateString()))->orderBy('valid_from')->get() as $r)
            $out[$r->ship_mode] = ['per_kg' => $r->per_kg !== null ? (float) $r->per_kg : null, 'per_cbm' => $r->per_cbm !== null ? (float) $r->per_cbm : null, 'min' => (float) $r->min_charge, 'days' => (int) $r->days_default];
        foreach (self::MODES as $m => $d) $out[$m] ??= ['per_kg' => null, 'per_cbm' => null, 'min' => 0.0, 'days' => $d];
        return $out;
    }

    /** All inputs of a product: saved input → system figure → product field → default. */
    public static function inputs(int $pid, array $over = [], ?string $today = null): array
    {
        $today ??= now()->toDateString();
        $p = DB::table('t_product_vendors')->where('id', $pid)->first();
        if (! $p) throw new \InvalidArgumentException('Product not found.');
        $s = (array) (DB::table('icc_inputs')->where('fk_id_product', $pid)->first() ?? []);
        $s = array_merge($s, array_filter($over, fn ($v) => $v !== null && $v !== ''));
        $assumed = [];
        $pick = function (string $key, array $sources, $default, string $label) use (&$assumed) {
            foreach ($sources as $v) if ($v !== null && $v !== '' && (! is_numeric($v) || (float) $v > 0)) return $v;
            $assumed[] = $label . ' = ' . (is_float($default) ? rtrim(rtrim(number_format($default, 2, '.', ''), '0'), '.') : $default);
            return $default;
        };
        $hist = self::monthlyFromSales($pid, $today);
        $in = ['product_id' => $pid, 'ent' => (int) $p->fk_id_ent, 'sku' => $p->SKU_VENDOR, 'name' => $p->product_name_vendor];
        $in['monthly'] = (int) $pick('monthly', [$s['monthly_sales'] ?? null, $hist], 0, 'Sales per month (no sales in the last 90 days)');
        $in['monthly_source'] = ! empty($s['monthly_sales']) ? 'typed' : ($hist ? 'sales of the last 90 days' : 'none');
        $in['season'] = (float) ($s['season_factor'] ?? 0) ?: self::season((int) date('n', strtotime($today)));
        $in['growth'] = (float) ($s['growth_pct'] ?? 0);
        $in['mode'] = strtoupper((string) $pick('mode', [$s['ship_mode'] ?? null], 'SEA', 'Shipping mode'));
        $in['safety_days'] = (int) $pick('safety', [$s['safety_days'] ?? null, $p->safety_days ?? null], $in['mode'] === 'SEA' ? 21 : 14, 'Safety stock days');
        $in['fulfil'] = $s['fulfil'] ?? 'FBM';
        $in['units_per_carton'] = (int) $pick('upc', [$s['units_per_carton'] ?? null, $p->units_per_carton ?? null], 1, 'Units per carton');
        $in['carton'] = [(float) $pick('cl', [$s['carton_l_cm'] ?? null, $p->carton_l ?? null], 0.0, 'Carton length cm'), (float) ($s['carton_w_cm'] ?? 0 ?: ($p->carton_w ?? 0)), (float) ($s['carton_h_cm'] ?? 0 ?: ($p->carton_h ?? 0))];
        $packedG = (float) ($s['packed_weight_g'] ?? 0 ?: ($p->pack_weight_g ?? 0)) ?: ((float) ($s['unit_weight_g'] ?? 0) * 1.12);
        $in['packed_g'] = $packedG;
        $in['carton_kg'] = (float) $pick('ckg', [$s['carton_kg'] ?? null, $p->carton_gross_weight ?? null], round($packedG / 1000 * $in['units_per_carton'] + 0.8, 2), 'Carton gross kg (packed weight × units + 0.8)');
        $in['unit_dims'] = [(float) ($s['unit_l_cm'] ?? 0), (float) ($s['unit_w_cm'] ?? 0), (float) ($s['unit_h_cm'] ?? 0)];
        $in['divisor'] = (float) (($s['vol_divisor'] ?? 0) ?: CartonCalc::divisor($in['ent'], $in['mode']));
        $rates = self::rates();
        $in['d'] = ['order' => (int) $pick('do', [$s['d_order'] ?? null], 2, 'Order processing days'), 'production' => (int) $pick('dp', [$s['d_prod'] ?? null, $p->production_lead_days ?? null], 30, 'Production days'),
            'to_forwarder' => (int) $pick('dt', [$s['d_to_fwd'] ?? null], 3, 'Factory → forwarder days'), 'forwarder' => (int) $pick('df', [$s['d_fwd'] ?? null], 5, 'Forwarder handling days'),
            'transit' => (int) (($s['d_transit'] ?? 0) ?: $rates[$in['mode']]['days']), 'clearance' => (int) $pick('dc', [$s['d_clear'] ?? null], 5, 'UK clearance + delivery days'),
            'fba_inbound' => (int) (($s['d_fba'] ?? 0) ?: ($in['fulfil'] === 'FBA' ? 7 : 0))];
        $in['holiday_cal'] = ! array_key_exists('f_holiday_cal', $s) || $s['f_holiday_cal'] === null || (int) $s['f_holiday_cal'] === 1;
        $in['unit_cost'] = (float) $pick('cost', [$s['unit_cost'] ?? null, $p->landing_price ?? null, $p->raw_price ?? null], 0.0, 'Unit cost');
        $in['currency'] = $s['currency'] ?? 'GBP';
        $in['moq'] = (int) $pick('moq', [$s['moq'] ?? null, $p->moq ?? null], 500, 'MOQ');
        $in['deposit_pct'] = (float) (($s['deposit_pct'] ?? 0) ?: 30);
        $in['sale_price'] = (float) (($s['sale_price'] ?? 0) ?: ($p->sale_price ?? 0));
        $in['margin'] = (float) (($s['margin_unit'] ?? 0) ?: round($in['sale_price'] * 0.72 - $in['unit_cost'] - 1.4, 4));
        $in['duty_pct'] = (float) Settings::get('ICC_DUTY_PCT', $in['ent'], 0); $in['customs_fee'] = (float) Settings::get('ICC_CUSTOMS_FEE', $in['ent'], 0); $in['vat_pct'] = (float) Settings::get('ICC_IMPORT_VAT_PCT', $in['ent'], 20);
        $in['available'] = StockService::available($pid);
        $in['pipeline'] = self::pipeline($pid);
        $in['holidays'] = DB::table('icc_holidays')->where('f_active', 1)->get(['name', 'date_from', 'date_to', 'add_days'])->map(fn ($h) => (array) $h)->all();
        $in['rates'] = $rates; $in['today'] = $today; $in['assumed'] = $assumed;
        return $in;
    }

    /** The formula block of 07_ICC §3 – pure, no database. */
    public static function calc(array $in): array
    {
        $r6 = fn (float $x) => round($x, 6);
        $today = $in['today']; $add = fn (int $days) => date('Y-m-d', strtotime("$today " . ($days >= 0 ? '+' : '') . $days . ' days'));
        $daily = $in['monthly'] * $in['season'] / 30;
        $d = $in['d']; $holAdd = 0; $holName = null;
        if ($in['holiday_cal']) {      // the production window overlaps a holiday (CNY, Golden Week) → its extra days
            $ps = $add($d['order']); $pe = $add($d['order'] + $d['production']);
            foreach ($in['holidays'] as $h) if ($h['date_from'] <= $pe && $h['date_to'] >= $ps && (int) $h['add_days'] > $holAdd) { $holAdd = (int) $h['add_days']; $holName = $h['name']; }
        }
        $cycleFor = fn (int $transit) => $d['order'] + $d['production'] + $holAdd + $d['to_forwarder'] + $d['forwarder'] + $transit + $d['clearance'] + $d['fba_inbound'];
        $cycle = $cycleFor($d['transit']);
        $safetyUnits = (int) ceil($r6($daily * $in['safety_days']));
        $reorder = (int) ceil($r6($daily * $cycle)) + $safetyUnits;
        $raw = (int) ceil($r6($daily * $cycle * (1 + $in['growth'] / 100))) + $safetyUnits;
        $upc = max(1, (int) $in['units_per_carton']);
        $qty = $daily > 0 ? max($in['moq'], (int) (ceil($raw / $upc) * $upc)) : 0;
        $cartons = (int) ceil($qty / $upc);
        [$cl, $cw, $ch] = $in['carton'];
        $cbm = round($cartons * $cl * $cw * $ch / 1e6, 3);
        $gross = round($cartons * $in['carton_kg'], 2);
        [$ul, $uw, $uh] = $in['unit_dims'];
        $vol = $ul * $uw * $uh > 0 ? round($ul * $uw * $uh / $in['divisor'] * $qty, 2) : round($cartons * $cl * $cw * $ch / $in['divisor'], 2);
        $charge = max($gross, $vol);
        $freightFor = function (string $mode) use ($in, $charge, $cbm) { $r = $in['rates'][$mode]; $f = $r['per_kg'] !== null ? $charge * $r['per_kg'] : $cbm * (float) $r['per_cbm']; return round(max($r['min'], $f), 2); };
        $freight = $qty ? $freightFor($in['mode']) : 0.0;
        $goods = round($qty * $in['unit_cost'], 2);
        $landed = $qty ? round($in['unit_cost'] + $freight / $qty + $in['unit_cost'] * $in['duty_pct'] / 100 + $in['customs_fee'] / $qty, 4) : 0.0;
        $pl = $in['pipeline'];
        $covered = $in['available'] + $pl['ordered'] + $pl['production'] + $pl['transit'];
        $coverDays = $daily > 0 ? round($covered / $daily, 6) : null;
        $status = 'NONE'; $orderBy = $stockOut = null; $late = 0; $lost = 0.0;
        if ($daily > 0) {
            $stockOut = $add((int) floor($coverDays)); $orderBy = $add((int) floor(round($coverDays - $cycle - $in['safety_days'], 6)));
            $status = $orderBy < $today ? 'RED' : ($orderBy <= $add(7) ? 'YELLOW' : 'GREEN');
            $late = (int) max(0, ceil($r6($cycle + $in['safety_days'] - $coverDays)));
            $lost = round($late * $daily * max(0, $in['margin']), 2);
        }
        $compare = [];
        if ($daily > 0) foreach ($in['rates'] as $m => $r) {
            $c = $cycleFor($r['days']); $l = (int) max(0, ceil($r6($c + $in['safety_days'] - $coverDays)));
            $f = $freightFor($m); $lp = round($l * $daily * max(0, $in['margin']), 2);
            $compare[] = ['mode' => $m, 'cycle_days' => $c, 'arrival' => $add($c), 'late_days' => $l, 'freight' => $f, 'lost_profit' => $lp, 'total' => round($f + $lp, 2)];
        }
        usort($compare, fn ($a, $b) => $a['total'] <=> $b['total']);
        $best = $compare[0]['mode'] ?? null; foreach ($compare as &$c) $c['best'] = $c['mode'] === $best; unset($c);
        $perYear = $qty ? $in['monthly'] * 12 / $qty : 0; $every = $perYear > 0 ? (int) round(365 / $perYear) : null;
        $duty = round($goods * $in['duty_pct'] / 100 + $in['customs_fee'], 2); $vat = round(($goods + $freight + $duty) * $in['vat_pct'] / 100, 2);
        $cash = $qty ? [['when' => $today, 'what' => 'Deposit ' . rtrim(rtrim(number_format($in['deposit_pct'], 2, '.', ''), '0'), '.') . ' %', 'amount' => round($goods * $in['deposit_pct'] / 100, 2)],
            ['when' => $add($d['order'] + $d['production'] + $holAdd), 'what' => 'Balance', 'amount' => round($goods * (1 - $in['deposit_pct'] / 100), 2)],
            ['when' => $add($cycle - $d['clearance'] - $d['fba_inbound']), 'what' => 'Freight + duty + import VAT', 'amount' => round($freight + $duty + $vat, 2)]] : [];
        return ['monthly_used' => $in['monthly'], 'daily' => round($daily, 3), 'cycle_days' => $cycle, 'holiday_add' => $holAdd, 'holiday_name' => $holName,
            'stages' => ['Order' => $d['order'], 'Production' => $d['production'] + $holAdd, 'To forwarder' => $d['to_forwarder'], 'Forwarder' => $d['forwarder'], 'Transit (' . $in['mode'] . ')' => $d['transit'], 'Clearance + delivery' => $d['clearance'], 'FBA inbound' => $d['fba_inbound']],
            'safety_units' => $safetyUnits, 'reorder_point' => $reorder, 'order_qty_raw' => $raw, 'order_qty' => $qty, 'cartons' => $cartons, 'cbm' => $cbm, 'gross_kg' => $gross, 'vol_kg' => $vol, 'chargeable_kg' => $charge,
            'freight' => $freight, 'goods' => $goods, 'landed_unit' => $landed, 'avail' => $in['available'], 'ordered' => $pl['ordered'], 'production' => $pl['production'], 'transit' => $pl['transit'],
            'cover_days' => $coverDays === null ? null : (int) floor($coverDays), 'stock_out' => $stockOut, 'stock_out_stock_only' => $daily > 0 ? $add((int) floor(round($in['available'] / $daily, 6))) : null,
            'order_by' => $orderBy, 'arrival' => $add($cycle), 'status' => $status, 'late_days' => $late, 'lost_profit' => $lost, 'every_days' => $every, 'overlap_orders' => $every ? (int) ceil($cycle / $every) : 0,
            'pipeline_text' => $every ? "Every $every days one PO of $qty units – " . (int) ceil($cycle / $every) . ' order(s) overlap.' : null,
            'best_mode' => $best, 'compare' => $compare, 'cash' => $cash, 'assumed' => $in['assumed']];
    }

    /** Work out one product, store the result, keep the product's ICC columns, the event and the STK_REORDER task current. */
    public static function run(int $pid, array $over = [], bool $save = true): array
    {
        $in = self::inputs($pid, $over); $r = self::calc($in);
        if (! $save) return ['inputs' => $in, 'result' => $r];
        $old = (string) DB::table('t_product_vendors')->where('id', $pid)->value('icc_status');
        DB::table('icc_results')->where('fk_id_product', $pid)->where('run_at', '<', now()->subDays(90))->delete();      // a 90-day trail per product
        DB::table('icc_results')->insert(['fk_id_product' => $pid, 'run_at' => now()] + array_intersect_key($r, array_flip(['monthly_used', 'daily', 'cycle_days', 'holiday_add', 'holiday_name', 'reorder_point', 'order_qty_raw', 'order_qty', 'cartons', 'cbm', 'gross_kg', 'vol_kg', 'chargeable_kg',
            'freight', 'goods', 'landed_unit', 'avail', 'ordered', 'production', 'transit', 'cover_days', 'stock_out', 'order_by', 'arrival', 'status', 'late_days', 'lost_profit', 'every_days', 'overlap_orders', 'best_mode']))
            + ['compare_json' => json_encode($r['compare']), 'cash_json' => json_encode($r['cash']), 'assumed_json' => json_encode($r['assumed'], JSON_UNESCAPED_UNICODE)]);
        DB::table('t_product_vendors')->where('id', $pid)->update(['icc_status' => $r['status'], 'icc_order_by' => $r['order_by'], 'icc_stock_out' => $r['stock_out'], 'icc_order_qty' => $r['order_qty'], 'icc_cycle_days' => $r['cycle_days'], 'icc_run_at' => now(),
            'reorder_point' => $r['reorder_point'] ?: null]);
        if ($old !== $r['status'] && $r['status'] !== 'NONE') EventBus::fire(['RED' => 'ICC_RED', 'YELLOW' => 'ICC_YELLOW', 'GREEN' => 'ICC_OK'][$r['status']], 't_product_vendors', $pid, ['order_qty' => $r['order_qty'], 'order_by' => $r['order_by']], $in['ent']);
        self::task($in, $r);
        return ['inputs' => $in, 'result' => $r];
    }

    /** STK_REORDER (06C #51): RED → URGENT "Order N × SKU now – X days late", YELLOW → due on the order-by date; covered → closed. One per product. */
    public static function task(array $in, array $r): void
    {
        $key = [$in['product_id']]; $sku = $in['sku'] ?: ('product ' . $in['product_id']);
        if (in_array($r['status'], ['RED', 'YELLOW'], true)) {
            $title = $r['status'] === 'RED' ? "Order {$r['order_qty']} × $sku now – {$r['late_days']} days late" : "Order {$r['order_qty']} × $sku by " . date('d M', strtotime($r['order_by']));
            $id = TaskEngine::open('STK_REORDER', $key, $title, ['ent' => $in['ent'], 'icc_red' => $r['status'] === 'RED', 'module' => 'icc', 'record_id' => $in['product_id'], 'fix_url' => '#/icc/' . $in['product_id'], 'amount' => $r['lost_profit'] ?: null,
                'description' => "Stock cover {$r['cover_days']} days, cycle {$r['cycle_days']} days, safety {$in['safety_days']} days. Best mode: {$r['best_mode']}." . ($r['lost_profit'] > 0 ? " Lost profit if late: £{$r['lost_profit']}." : '')]);
            if ($id) DB::table('tasks')->where('id', $id)->update(['title' => mb_substr($title, 0, 255), 'priority' => $r['status'] === 'RED' ? 'URGENT' : DB::raw('priority')] + ($r['status'] === 'YELLOW' ? ['due_at' => max($r['order_by'], now()->toDateString())] : []));
        } else TaskEngine::close('STK_REORDER', $key, $r['status'] === 'GREEN' ? 'ICC: covered (PO / cycle created or stock arrived)' : 'ICC: no sales');
    }

    /** Nightly (07_ICC §6): every active product of the company that sold or has stock / open POs. */
    public static function recalcAll(int $ent): array
    {
        $n = ['run' => 0, 'RED' => 0, 'YELLOW' => 0, 'GREEN' => 0, 'NONE' => 0, 'failed' => 0];
        $ids = DB::table('t_product_vendors')->where('fk_id_ent', $ent)->where('f_active', 1)->where(fn ($w) => $w->whereNotNull('icc_run_at')
            ->orWhereExists(fn ($q) => $q->from('t_sales')->whereColumn('t_sales.fk_product_id', 't_product_vendors.id')->where('d_order', '>=', now()->subDays(90)))
            ->orWhereExists(fn ($q) => $q->from('icc_inputs')->whereColumn('icc_inputs.fk_id_product', 't_product_vendors.id')))->pluck('id');
        foreach ($ids as $id) {
            try { $s = self::run((int) $id)['result']['status']; $n['run']++; $n[$s]++; }
            catch (\Throwable $e) { $n['failed']++; report($e); }
        }
        return $n;
    }

    /** Save the typed inputs of a product (only the known columns). */
    public static function saveInputs(int $pid, array $v, ?int $user = null): void
    {
        $cols = ['monthly_sales', 'season_factor', 'growth_pct', 'safety_days', 'fulfil', 'unit_weight_g', 'packed_weight_g', 'unit_l_cm', 'unit_w_cm', 'unit_h_cm', 'vol_divisor', 'units_per_carton', 'carton_l_cm', 'carton_w_cm', 'carton_h_cm', 'carton_kg',
            'd_order', 'd_prod', 'd_to_fwd', 'd_fwd', 'ship_mode', 'd_transit', 'd_clear', 'd_fba', 'f_holiday_cal', 'fk_id_supplier', 'fk_id_forwarder', 'unit_cost', 'currency', 'moq', 'deposit_pct', 'sale_price', 'margin_unit', 'fk_id_paid_by'];
        $row = [];
        foreach ($cols as $c) if (array_key_exists($c, $v)) $row[$c] = $v[$c] === '' ? null : $v[$c];
        if (isset($row['ship_mode']) && $row['ship_mode'] !== null && ! isset(self::MODES[strtoupper($row['ship_mode'])])) throw new \InvalidArgumentException('Shipping mode must be one of ' . implode(', ', array_keys(self::MODES)) . '.');
        foreach ($row as $c => $x) if ($x !== null && ! in_array($c, ['fulfil', 'ship_mode', 'currency'], true) && (! is_numeric($x) || (float) $x < 0)) throw new \InvalidArgumentException("$c must be a number of 0 or more.");
        $ent = (int) DB::table('t_product_vendors')->where('id', $pid)->value('fk_id_ent');
        $before = (array) (DB::table('icc_inputs')->where('fk_id_product', $pid)->first() ?? []);
        DB::table('icc_inputs')->updateOrInsert(['fk_id_product' => $pid], $row + ['fk_id_enterprise' => $ent, 'updated_by' => $user, 'updated_at' => now()]);
        $diff = []; foreach ($row as $c => $x) if ((string) ($before[$c] ?? '') !== (string) $x) $diff[$c] = [$before[$c] ?? null, $x];
        if ($diff) Audit::log('icc_inputs', $pid, $before ? 'UPDATE' : 'INSERT', $diff, 'ICC inputs');
    }

    /** #/icc: every product with a result, worst first, and the Sourcing counts (order now / this week / covered + cash for the orders due). */
    public static function board(int $ent, ?string $status = null): array
    {
        $rows = DB::table('t_product_vendors as p')->where('p.fk_id_ent', $ent)->where('p.f_active', 1)->whereNotNull('p.icc_run_at')->when($status, fn ($q) => $q->where('p.icc_status', $status))
            ->orderByRaw("FIELD(p.icc_status,'RED','YELLOW','GREEN','NONE')")->orderBy('p.icc_order_by')->limit(500)
            ->get(['p.id', 'p.SKU_VENDOR as sku', 'p.product_name_vendor as name', 'p.icc_status as status', 'p.icc_order_by as order_by', 'p.icc_stock_out as stock_out', 'p.icc_order_qty as order_qty', 'p.icc_cycle_days as cycle_days', 'p.icc_run_at as run_at']);
        $latest = DB::table('icc_results')->whereIn('fk_id_product', $rows->pluck('id')->all() ?: [0])->whereIn('id', DB::table('icc_results')->selectRaw('MAX(id)')->groupBy('fk_id_product'))->get()->keyBy('fk_id_product');
        $cash = 0.0;
        foreach ($rows as $r) { $x = $latest[$r->id] ?? null; $r->label = self::STATUS_LABEL[$r->status] ?? $r->status; $r->late_days = $x ? (int) $x->late_days : 0; $r->lost_profit = $x ? (float) $x->lost_profit : 0; $r->cover_days = $x ? $x->cover_days : null; $r->best_mode = $x->best_mode ?? null; $r->goods = $x ? (float) $x->goods : 0;
            if (in_array($r->status, ['RED', 'YELLOW'], true)) $cash += $r->goods; }
        $count = DB::table('t_product_vendors')->where('fk_id_ent', $ent)->where('f_active', 1)->whereNotNull('icc_run_at')->groupBy('icc_status')->selectRaw('icc_status s, COUNT(*) n')->pluck('n', 's');
        return ['rows' => $rows->values(), 'counts' => ['order_now' => (int) ($count['RED'] ?? 0), 'this_week' => (int) ($count['YELLOW'] ?? 0), 'covered' => (int) ($count['GREEN'] ?? 0), 'no_sales' => (int) ($count['NONE'] ?? 0)],
            'cash_needed' => round($cash, 2), 'currency' => Fx::base($ent)];
    }
}
