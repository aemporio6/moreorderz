<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-FIN-51 nightly stock snapshot (00:30), one row per company + SKU + day in fin_stock_snapshots.
 * Improved as the rule asks: read from the stock ledger by location type, so every unit is counted in ONE place only (fixes the old FBA
 * double count B9) and a past date uses the ledger balance on that date (current balance − every movement after it), not today's qty_left.
 *   warehouse   = own stock locations (warehouse, shelf, bin, dispatch, receiving, quarantine, others that are sellable)
 *   at Amazon   = marketplace fulfilment centres: locations of kind FBA / FC / AMAZON / MARKETPLACE / 3PL or the destination of an inbound
 *                 shipment; for a SKU with no such ledger units, the latest FBA report ≤ date (fulfillable + reserved; unfulfillable → lost)
 *   transit     = TRANSIT locations + PO lines of shipped POs not received yet;  production = PO lines of confirmed POs not shipped yet
 *   damaged / returned pending / written off / lost = DAMAGED / RETURNS / WRITTEN_OFF / MISSING locations (never sellable)
 *   unit cost   = weighted lot cost (Σ qty_left × cost ÷ Σ qty_left), else the last lot cost, else the PO line price
 *   days since last sale = from sale lines dated ≤ the snapshot date; value_sellable = (warehouse + Amazon) × cost + production + transit value.
 * Slow / dead (R-FIN-51 / 54) are only counted when value_sellable > 0: dead ≥ dead_days, else slow ≥ slow_days.
 */
class StockSnapshot
{
    /* the kinds that count as "at Amazon" are the Settings › Choices multiple choice FBA_LOCATION_KINDS */
    private const BUCKET = ['TRANSIT' => 'transit', 'DAMAGED' => 'damaged', 'RETURNS' => 'returned', 'WRITTEN_OFF' => 'written_off', 'MISSING' => 'lost',
        'SHIPPED' => null, 'CUSTOMER' => null, 'SUPPLIER' => null, 'PRODUCTION' => 'production'];      // null = not ours any more (sold / sent back)

    /** location id => bucket (warehouse / amazon / transit / …, null = not counted). */
    public static function buckets(int $ent): array
    {
        $fbaDest = DB::table('fba_shipments')->where('fk_id_ent', $ent)->whereNotNull('fk_id_dest_location')->pluck('fk_id_dest_location')->map(fn ($x) => (int) $x)->flip();
        $out = []; $fba = Choices::list('FBA_LOCATION_KINDS', $ent);      // Settings › Choices (default: marketplace fulfilment, third-party warehouse, FBA)
        foreach (DB::table('cat_inventory_locations')->where(fn ($w) => $w->where('fk_id_ent', $ent)->orWhereNull('fk_id_ent')->orWhere('fk_id_ent', 0))->get(['id', 'kind']) as $l) {
            $k = strtoupper((string) $l->kind);
            $out[(int) $l->id] = isset($fbaDest[(int) $l->id]) || in_array($k, $fba, true) ? 'amazon' : (array_key_exists($k, self::BUCKET) ? self::BUCKET[$k] : 'warehouse');
        }
        return $out;
    }

    /** A location's bucket; null (sold / sent back) stays null, an unknown location counts as warehouse. */
    private static function bucketOf(array $b, int $loc): ?string { return array_key_exists($loc, $b) ? $b[$loc] : 'warehouse'; }

    /** Build (or rebuild) the snapshot of one company for one date. Returns the number of SKU rows. */
    public static function build(int $ent, ?string $date = null): int
    {
        $date ??= date('Y-m-d'); $end = $date . ' 23:59:59'; $today = date('Y-m-d');
        $bucket = self::buckets($ent); $z = ['warehouse' => 0, 'amazon' => 0, 'transit' => 0, 'production' => 0, 'damaged' => 0, 'returned' => 0, 'written_off' => 0, 'lost' => 0];
        $u = [];      // sku => bucket => units
        $later = $date < $today ? DB::table('t_stock_movements')->where('created_at', '>', $end)->whereNotNull('fk_id_lot')
            ->whereNot(fn ($w) => $w->where('movement_type', 'purchase_in')->orWhere(fn ($x) => $x->where('movement_type', 'damaged')->where('notes', 'Arrived damaged')))      // a lot's own arrival counts on its received date
            ->groupBy('fk_id_lot', 'fk_id_location')->selectRaw('fk_id_lot, fk_id_location, SUM(qty_change) q')->get()
            ->mapWithKeys(fn ($r) => [$r->fk_id_lot . ':' . $r->fk_id_location => (int) $r->q])->all() : [];
        $bal = DB::table('fin_lots as l')->leftJoin('fin_lot_balances as b', 'b.fk_id_lot', '=', 'l.id')->where('l.company_id', $ent)->where('l.received_date', '<=', $date)->get(['l.id', 'l.sku', 'b.fk_id_location', 'b.qty']);
        $seen = [];
        foreach ($bal as $b) {
            if ($b->fk_id_location === null) continue;
            $k = $b->id . ':' . $b->fk_id_location; $seen[$k] = true;
            $q = (int) $b->qty - (int) ($later[$k] ?? 0); $bk = self::bucketOf($bucket, (int) $b->fk_id_location);
            if ($q !== 0 && $bk) { $u[$b->sku] ??= $z; $u[$b->sku][$bk] += $q; }
        }
        // a lot that has left a location since the date has no balance row there any more: give its units back
        if ($later) foreach (DB::table('fin_lots')->where('company_id', $ent)->where('received_date', '<=', $date)->pluck('sku', 'id') as $lot => $sku)
            foreach ($later as $k => $q) if (str_starts_with($k, $lot . ':') && ! isset($seen[$k])) { $bk = self::bucketOf($bucket, (int) explode(':', $k)[1]); if ($bk && $q) { $u[$sku] ??= $z; $u[$sku][$bk] -= $q; } }
        // FBA report for SKUs with no ledger units at a centre
        foreach (DB::table('fin_fba_stock_snapshots as f')->join('t_branches as br', 'br.id', '=', 'f.branch_id')->where('br.fk_id_ent', $ent)->where('f.snapshot_date', '<=', $date)
            ->whereRaw('f.snapshot_date = (SELECT MAX(x.snapshot_date) FROM fin_fba_stock_snapshots x WHERE x.branch_id = f.branch_id AND x.snapshot_date <= ?)', [$date])->get(['f.sku', 'f.fulfillable', 'f.reserved', 'f.unfulfillable']) as $f) {
            $u[$f->sku] ??= $z; if (($u[$f->sku]['amazon'] ?? 0) > 0) continue;
            $u[$f->sku]['amazon'] += (int) $f->fulfillable + (int) $f->reserved; $u[$f->sku]['lost'] += (int) $f->unfulfillable;
        }
        // pipeline: PO lines not received yet (confirmed → production, shipped → transit), valued at the line price
        $pipeCost = [];
        /* R-G_CORE-87: received = GRNs whose status is in GRN_POSTED_STATUSES; in production / in transit = PO status in PO_PRODUCTION_STATUSES / TRANSIT_STATUSES (Reference Register lists) */
        $prodSt = OpeningBalances::statuses('PO_PRODUCTION_STATUSES'); $tranSt = OpeningBalances::statuses('TRANSIT_STATUSES');
        $got = DB::table('t_src_grn_lines as gl')->join('t_src_grn as g', 'g.id', '=', 'gl.fk_id_grn')->whereIn(DB::raw('UPPER(g.status)'), OpeningBalances::statuses('GRN_POSTED_STATUSES'))->groupBy('gl.fk_id_po_line')->selectRaw('gl.fk_id_po_line, SUM(COALESCE(gl.received_qty, gl.accepted_qty, 0)) q')->pluck('q', 'fk_id_po_line');
        foreach (DB::table('t_src_po_lines as l')->join('t_src_po as p', 'p.id', '=', 'l.fk_id_po')->where('l.fk_id_ent', $ent)->where('l.f_active', 1)->whereIn(DB::raw("UPPER(REPLACE(p.status, ' ', '_'))"), array_merge($prodSt, $tranSt))->whereDate('p.created_at', '<=', $date)
            ->get(['l.id', 'l.sku', 'l.qty_ordered', 'l.qty_confirmed', 'l.unit_price', 'p.status', 'p.fk_id_currency']) as $l) {
            $open = (int) ($l->qty_confirmed ?: $l->qty_ordered) - (int) ($got[$l->id] ?? 0); if ($open <= 0 || ! $l->sku) continue;
            $u[$l->sku] ??= $z; $u[$l->sku][in_array(strtoupper(str_replace(' ', '_', (string) $l->status)), $tranSt, true) ? 'transit' : 'production'] += $open;
            $pipeCost[$l->sku] ??= round((float) $l->unit_price * Fx::rate(Fx::code($l->fk_id_currency, $ent), $date, $ent), 4);
        }
        $w = DB::table('fin_lots')->where('company_id', $ent)->where('received_date', '<=', $date)->groupBy('sku')->selectRaw('sku, SUM(qty_left * unit_cost_gbp) v, SUM(qty_left) q')->get()->keyBy('sku');
        $lastLot = DB::table('fin_lots as l')->where('company_id', $ent)->where('received_date', '<=', $date)->whereNotNull('unit_cost_gbp')->orderBy('received_date')->orderBy('id')->pluck('unit_cost_gbp', 'sku');
        $lastSale = DB::table('t_sales as s')->join('t_product_vendors as p', 'p.id', '=', 's.fk_product_id')->where('s.fk_id_ent', $ent)->whereNotIn('s.status', ['Cancelled'])->where('s.d_order', '<=', $end)
            ->groupBy('p.SKU_VENDOR')->selectRaw('p.SKU_VENDOR sku, MAX(s.d_order) d')->pluck('d', 'sku');
        $rows = [];
        foreach ($u as $sku => $x) {
            if (! array_filter($x)) continue;
            $cost = isset($w[$sku]) && (float) $w[$sku]->q > 0 ? (float) $w[$sku]->v / (float) $w[$sku]->q : (isset($lastLot[$sku]) ? (float) $lastLot[$sku] : ($pipeCost[$sku] ?? 0.0));
            $pc = $pipeCost[$sku] ?? $cost; $v = fn ($n, $c = null) => round($n * ($c ?? $cost), 2);
            $rows[] = ['snapshot_date' => $date, 'company_id' => $ent, 'sku' => mb_substr((string) $sku, 0, 80), 'units_on_hand_warehouse' => $x['warehouse'], 'units_at_amazon' => $x['amazon'], 'units_in_transit' => $x['transit'],
                'units_in_production' => $x['production'], 'units_damaged' => $x['damaged'], 'units_returned_pending' => $x['returned'], 'units_written_off' => $x['written_off'], 'units_lost_at_amazon' => $x['lost'],
                'landed_cost_per_unit' => round($cost, 4), 'value_in_production' => $v($x['production'], $pc), 'value_in_transit' => $v($x['transit'], $pc), 'value_damaged' => $v($x['damaged']), 'value_returned' => $v($x['returned']), 'value_written_off' => $v($x['written_off']),
                'value_sellable' => round($v($x['warehouse'] + $x['amazon']) + $v($x['production'], $pc) + $v($x['transit'], $pc), 2),
                'days_since_last_sale' => isset($lastSale[$sku]) ? (int) floor((strtotime($date) - strtotime(substr((string) $lastSale[$sku], 0, 10))) / 86400) : null];
        }
        DB::transaction(function () use ($ent, $date, $rows) {
            DB::table('fin_stock_snapshots')->where('company_id', $ent)->where('snapshot_date', $date)->delete();
            foreach (array_chunk($rows, 500) as $c) DB::table('fin_stock_snapshots')->insert($c);
        });
        return count($rows);
    }

    /** The snapshot of a date: built on demand when that day has none yet (any date reads its snapshot). */
    public static function rows(int $ent, string $date): array
    {
        if (! DB::table('fin_stock_snapshots')->where('company_id', $ent)->where('snapshot_date', $date)->exists()) self::build($ent, $date);
        return DB::table('fin_stock_snapshots')->where('company_id', $ent)->where('snapshot_date', $date)->orderBy('sku')->get()->map(fn ($r) => (array) $r)->all();
    }

    public static function flag(array $r, int $slow, int $dead): ?string
    {
        if ((float) $r['value_sellable'] <= 0) return null;
        $d = $r['days_since_last_sale'];
        if ($d === null) return 'DEAD';      // never sold and still holding value
        return $d >= $dead ? 'DEAD' : ($d >= $slow ? 'SLOW' : null);
    }

    /** R-FIN-51 cards + rows for a date. */
    public static function view(int $ent, string $date): array
    {
        $slow = FinanceSettings::int($ent, 'slow_days') ?: 90; $dead = FinanceSettings::int($ent, 'dead_days') ?: 180;
        $rows = array_map(fn ($r) => $r + ['flag' => self::flag($r, $slow, $dead)], self::rows($ent, $date));
        $sum = fn ($k) => array_sum(array_column($rows, $k)); $cost = fn ($r, $k) => $r[$k] * (float) $r['landed_cost_per_unit'];
        $flagged = fn ($f) => array_values(array_filter($rows, fn ($r) => $r['flag'] === $f));
        $cards = [
            'on_hand' => ['units' => $sum('units_on_hand_warehouse'), 'value' => round(array_sum(array_map(fn ($r) => $cost($r, 'units_on_hand_warehouse'), $rows)), 2)],
            'fba' => ['units' => $sum('units_at_amazon'), 'value' => round(array_sum(array_map(fn ($r) => $cost($r, 'units_at_amazon'), $rows)), 2)],
            'transit' => ['units' => $sum('units_in_transit'), 'value' => round($sum('value_in_transit'), 2)],
            'production' => ['units' => $sum('units_in_production'), 'value' => round($sum('value_in_production'), 2)],
            'damaged' => ['units' => $sum('units_damaged'), 'value' => round($sum('value_damaged'), 2)],
            'returned' => ['units' => $sum('units_returned_pending'), 'value' => round($sum('value_returned'), 2)],
            'written_off' => ['units' => $sum('units_written_off'), 'value' => round($sum('value_written_off'), 2)],
            'slow' => ['count' => count($flagged('SLOW')), 'value' => round(array_sum(array_column($flagged('SLOW'), 'value_sellable')), 2)],
            'dead' => ['count' => count($flagged('DEAD')), 'value' => round(array_sum(array_column($flagged('DEAD'), 'value_sellable')), 2)],
        ];
        return ['date' => $date, 'cards' => $cards, 'rows' => $rows, 'slow_days' => $slow, 'dead_days' => $dead, 'base' => Fx::base($ent),
            'built_at' => DB::table('sys_job_runs')->where('job_code', 'FIN_NIGHTLY')->where('fk_id_ent', $ent)->where('run_status', 'OK')->max('finished_at')];
    }
}
