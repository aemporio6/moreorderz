<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Stock count (R-D-50, R-H-46): open (the system quantity is frozen per line) → count → recount (a big difference: another person, blind) → submit → approve (only above
 * the limits, maker-checker) → post. A count never overwrites stock: only the differences are posted. A loss moves the units from the bin to MISSING (the book stays);
 * a gain takes them back from MISSING first, the rest is found stock. Units left in MISSING longer than WH_MISSING_WRITE_OFF_DAYS raise WH_MISSING_STOCK and are written off
 * by their lots (WH_LOSS_LOT_RULE: FIFO or pro rata by owner) into stock loss.
 */
class CountService
{
    public static function open(int $ent, int $locationId, ?array $productIds = null, bool $blind = true): int
    {
        return DB::transaction(function () use ($ent, $locationId, $productIds, $blind) {
            $id = DB::table('t_stock_counts')->insertGetId(['fk_id_ent' => $ent, 'count_code' => Numbering::next('CNT', $ent), 'fk_id_location' => $locationId, 'd_count' => date('Y-m-d'), 'status' => 'OPEN',
                'counted_by' => Context::userId(), 'created_at' => now(), 'count_type' => $productIds ? 'SPOT' : 'FULL', 'f_blind' => $blind ? 1 : 0]);
            $q = DB::table('fin_lot_balances as b')->join('fin_lots as l', 'l.id', '=', 'b.fk_id_lot')->where('l.company_id', $ent)->where('b.fk_id_location', $locationId)->groupBy('l.fk_id_product')->selectRaw('l.fk_id_product p, SUM(b.qty) q');
            $sys = $q->pluck('q', 'p')->all();
            $products = $productIds ?: array_keys(array_filter($sys, fn ($v) => $v > 0));
            foreach ($products as $p) {
                DB::table('t_stock_count_lines')->insert(['fk_id_count' => $id, 'fk_id_product' => $p, 'qty_system' => (int) ($sys[$p] ?? 0), 'fk_id_location' => $locationId, 'snapshot_at' => now(), 'line_status' => 'OPEN']);
            }
            return $id;
        });
    }

    public static function enter(int $countId, int $productId, int $qty, ?string $note = null): void
    {
        if ($qty < 0) throw new \InvalidArgumentException('A counted quantity cannot be below zero.');
        $c = self::get($countId, ['OPEN']);
        $line = DB::table('t_stock_count_lines')->where(['fk_id_count' => $countId, 'fk_id_product' => $productId])->first();
        if ($line) DB::table('t_stock_count_lines')->where('id', $line->id)->update(['qty_counted' => $qty, 'note' => $note, 'counted_by' => Context::userId(), 'line_status' => 'COUNTED']);
        else DB::table('t_stock_count_lines')->insert(['fk_id_count' => $countId, 'fk_id_product' => $productId, 'qty_system' => StockService::balance($productId, (int) $c->fk_id_location), 'qty_counted' => $qty,
            'note' => $note, 'fk_id_location' => $c->fk_id_location, 'snapshot_at' => now(), 'counted_by' => Context::userId(), 'line_status' => 'COUNTED']);   // found a product the system did not expect here
        $l = DB::table('t_stock_count_lines')->where(['fk_id_count' => $countId, 'fk_id_product' => $productId])->first();
        DB::table('t_stock_count_lines')->where('id', $l->id)->update(['qty_recount' => null, 'recounted_by' => null]);      // a new count asks for a new recount
        $sku = (string) DB::table('t_product_vendors')->where('id', $productId)->value('SKU_VENDOR');
        if (self::needsRecount((int) $l->qty_system, $qty, (int) $c->fk_id_ent)) TaskEngine::open('WH_COUNT_RECOUNT', [$c->count_code, $sku], "Recount $sku in count {$c->count_code} (counted $qty) – by another person", ['ent' => (int) $c->fk_id_ent, 'module' => 't_stock_counts', 'record_id' => $countId]);
        else TaskEngine::close('WH_COUNT_RECOUNT', [$c->count_code, $sku], 'Difference within the limits');
    }

    /** Above WH_RECOUNT_THRESHOLD_UNITS (2) or WH_RECOUNT_THRESHOLD_PCT (5 %) the line needs a recount. */
    public static function needsRecount(int $system, int $counted, int $ent): bool
    {
        $d = abs($counted - $system); if ($d === 0) return false;
        return $d > (int) Settings::get('WH_RECOUNT_THRESHOLD_UNITS', $ent, 2) || ($system > 0 && $d / $system * 100 > (float) Settings::get('WH_RECOUNT_THRESHOLD_PCT', $ent, 5));
    }

    /** The recount: another person than the one who counted, without seeing the first number. The recount is the number that counts. */
    public static function recount(int $countId, int $productId, int $qty): void
    {
        if ($qty < 0) throw new \InvalidArgumentException('A counted quantity cannot be below zero.');
        $c = self::get($countId, ['OPEN']); $l = DB::table('t_stock_count_lines')->where(['fk_id_count' => $countId, 'fk_id_product' => $productId])->first() ?? throw new \InvalidArgumentException('This product is not on the count.');
        if ($l->qty_counted === null) throw new \InvalidArgumentException('Count it first.');
        if ((int) $l->counted_by === (int) Context::userId()) throw new \RuntimeException('You counted this line. Another person has to recount it.');
        DB::table('t_stock_count_lines')->where('id', $l->id)->update(['qty_recount' => $qty, 'recounted_by' => Context::userId(), 'line_status' => 'RECOUNTED']);
        TaskEngine::close('WH_COUNT_RECOUNT', [$c->count_code, (string) DB::table('t_product_vendors')->where('id', $productId)->value('SKU_VENDOR')], 'Recounted');
    }

    public static function submit(int $countId): array
    {
        $c = self::get($countId, ['OPEN']); $ent = (int) $c->fk_id_ent;
        $missing = DB::table('t_stock_count_lines')->where('fk_id_count', $countId)->whereNull('qty_counted')->count();
        if ($missing) throw new \RuntimeException("$missing line(s) are not counted yet.");
        $need = DB::table('t_stock_count_lines')->where('fk_id_count', $countId)->whereNull('qty_recount')->get()->filter(fn ($l) => self::needsRecount((int) $l->qty_system, (int) $l->qty_counted, $ent))->count();
        if ($need) throw new \RuntimeException("$need line(s) differ more than the limits and need a recount by another person first.");
        $value = self::variance($countId); $maxUnits = (int) collect(self::differences($countId))->max(fn ($d) => abs((int) $d->diff));
        $approval = abs($value) > (float) Settings::get('WH_COUNT_APPROVAL_VALUE', $ent, 100) || $maxUnits > (int) Settings::get('ADJUST_APPROVAL_QTY', $ent, 50);
        DB::table('t_stock_counts')->where('id', $countId)->update(['status' => 'SUBMITTED', 'submitted_at' => now(), 'variance_value' => $value]);
        EventBus::fire('COUNT_SUBMITTED', 't_stock_counts', $countId, ['variance' => $value], $ent);
        if ($approval) {      // above the limits: maker-checker
            TaskEngine::open('FIN_APPROVE', ['COUNT', $c->count_code], "Approve the differences of count {$c->count_code} (" . number_format($value, 2) . ')', ['ent' => $ent, 'module' => 't_stock_counts', 'record_id' => $countId, 'amount' => abs($value), 'owner_user_id' => TaskEngine::fewestOpen('MANAGER', $ent) ?: null]);
            return ['variance_value' => $value, 'differences' => self::differences($countId), 'needs_approval' => true, 'message' => 'Count submitted for approval'];
        }
        DB::table('t_stock_counts')->where('id', $countId)->update(['status' => 'APPROVED', 'approved_at' => now()]);      // below the limits: posted at once
        $r = self::post($countId);
        return ['variance_value' => $value, 'differences' => self::differences($countId), 'needs_approval' => false, 'posted' => $r, 'message' => 'Count posted (within the limits)'];
    }

    public static function approve(int $countId): void
    {
        $c = self::get($countId, ['SUBMITTED']);
        if ((int) $c->counted_by === (int) Context::userId()) throw new \RuntimeException('You counted this yourself. Somebody else has to approve it.');
        DB::table('t_stock_counts')->where('id', $countId)->update(['status' => 'APPROVED', 'approved_by' => Context::userId(), 'approved_at' => now()]);
        Audit::log('t_stock_counts', $countId, 'APPROVE', ['variance_value' => $c->variance_value], null, (int) $c->fk_id_ent);
        TaskEngine::close('FIN_APPROVE', ['COUNT', $c->count_code], 'Approved');
    }

    /** Post the differences. Stock that moved after the snapshot is respected: the difference is posted, the counted number is never written. */
    public static function post(int $countId): array
    {
        $c = self::get($countId, ['APPROVED']); $ent = (int) $c->fk_id_ent;
        return DB::transaction(function () use ($c, $countId, $ent) {
            $n = 0; $value = 0.0; $miss = StockService::systemLocation($ent, 'MISSING'); $why = "Stock count {$c->count_code}";
            foreach (self::differences($countId) as $d) {
                $pid = (int) $d->fk_id_product; $loc = (int) $d->fk_id_location; $diff = (int) $d->diff;
                if ($diff < 0) {      // loss: bin → MISSING, the book unchanged – the units are missing, not yet lost
                    StockService::transfer($ent, $pid, -$diff, $loc, $miss, $why . ($d->note ? ': ' . $d->note : ''), ['type_out' => 'count_loss', 'type_in' => 'count_loss', 'source_type' => 'COUNT', 'source_id' => $countId]);
                    DB::table('wh_missing_units')->insert(['fk_id_ent' => $ent, 'fk_id_product' => $pid, 'fk_id_count' => $countId, 'fk_id_from_location' => $loc, 'qty' => -$diff, 'qty_open' => -$diff, 'created_at' => now()]);
                } else {      // gain: back from MISSING first, the rest is found stock
                    $back = min($diff, StockService::balance($pid, $miss));
                    if ($back > 0) { StockService::transfer($ent, $pid, $back, $miss, $loc, "$why: found", ['type_out' => 'count_found', 'type_in' => 'count_found', 'source_type' => 'COUNT', 'source_id' => $countId]); self::closeMissing($ent, $pid, $back, 'FOUND'); }
                    if ($diff - $back > 0) { $r = StockService::adjust($ent, $pid, $loc, $diff - $back, "$why: found stock" . ($d->note ? ' – ' . $d->note : ''), ['type' => 'count_adjust', 'source_type' => 'COUNT', 'source_id' => $countId, 'unit_cost' => 0]); $value += $r['value']; }
                }
                $n++;
            }
            DB::table('t_stock_counts')->where('id', $countId)->update(['status' => 'POSTED']);
            DB::table('t_stock_count_lines')->where('fk_id_count', $countId)->update(['line_status' => 'POSTED']);
            EventBus::fire('STOCK_COUNT_POSTED', 't_stock_counts', $countId, ['lines' => $n, 'value' => round($value, 2)], $ent);
            return ['lines_posted' => $n, 'value' => round($value, 2)];
        });
    }

    private static function closeMissing(int $ent, int $pid, int $qty, string $how): void
    {
        foreach (DB::table('wh_missing_units')->where('fk_id_ent', $ent)->where('fk_id_product', $pid)->where('qty_open', '>', 0)->orderBy('id')->get() as $m) {
            if ($qty <= 0) break; $take = min($qty, (int) $m->qty_open); $qty -= $take;
            DB::table('wh_missing_units')->where('id', $m->id)->update(['qty_open' => $m->qty_open - $take] + ($m->qty_open - $take === 0 ? ['closed_how' => $how, 'closed_at' => now()] : []));
        }
    }

    /** Units in MISSING longer than WH_MISSING_WRITE_OFF_DAYS (30): task WH_MISSING_STOCK per product, closed when they are found or written off. */
    public static function missingTasks(int $ent, ?string $today = null): array
    {
        $limit = date('Y-m-d H:i:s', strtotime(($today ?? date('Y-m-d')) . ' -' . (int) Settings::get('WH_MISSING_WRITE_OFF_DAYS', $ent, 30) . ' days'));
        $rows = DB::table('wh_missing_units as m')->join('t_product_vendors as p', 'p.id', '=', 'm.fk_id_product')->where('m.fk_id_ent', $ent)->where('m.qty_open', '>', 0)->where('m.created_at', '<=', $limit)
            ->groupBy('m.fk_id_product', 'p.SKU_VENDOR')->selectRaw('m.fk_id_product, p.SKU_VENDOR sku, SUM(m.qty_open) q')->get();
        return TaskEngine::sync('WH_MISSING_STOCK', $rows->map(fn ($r) => ['key' => [$r->sku], 'title' => "{$r->q} unit(s) of {$r->sku} missing for more than the limit – find them or write them off", 'opt' => ['module' => 't_product_vendors', 'record_id' => $r->fk_id_product]])->all(), null, $ent);
    }

    /** Write off missing units (R-D-47): their lots by WH_LOSS_LOT_RULE – FIFO (default) or pro rata by owner – into stock loss (5500, category STOCK_LOSS). */
    public static function writeOffMissing(int $ent, int $productId, ?int $qty = null, ?string $reason = null): array
    {
        $miss = StockService::systemLocation($ent, 'MISSING'); $have = StockService::balance($productId, $miss); $open = (int) DB::table('wh_missing_units')->where('fk_id_ent', $ent)->where('fk_id_product', $productId)->sum('qty_open');
        $qty ??= min($have, $open); if ($qty <= 0) throw new \InvalidArgumentException('Nothing of this product is missing.'); if ($qty > $have) throw new \InvalidArgumentException("Only $have unit(s) are in Missing.");
        return DB::transaction(function () use ($ent, $productId, $qty, $miss, $reason) {
            $why = 'Missing units written off' . ($reason ? ": $reason" : ''); $value = 0.0;
            if (Choices::get('WH_LOSS_LOT_RULE', $ent) === 'PRO_RATA') {      // each owner loses in proportion to the units of his lots in Missing
                $by = DB::table('fin_lot_balances as b')->join('fin_lots as l', 'l.id', '=', 'b.fk_id_lot')->where('l.fk_id_product', $productId)->where('b.fk_id_location', $miss)->where('b.qty', '>', 0)->groupBy('l.fk_id_owner')->selectRaw('l.fk_id_owner o, SUM(b.qty) q')->get();
                $tot = (int) $by->sum('q'); $left = $qty; $i = 0;
                foreach ($by as $o) { $i++; $part = $i === $by->count() ? $left : min($left, (int) round($qty * $o->q / $tot)); if ($part <= 0) continue; $left -= $part;
                    $value += StockService::adjust($ent, $productId, $miss, -$part, $why, ['type' => 'written_off', 'source_type' => 'MISSING', 'owner' => $o->o])['value']; }
            } else $value += StockService::adjust($ent, $productId, $miss, -$qty, $why, ['type' => 'written_off', 'source_type' => 'MISSING'])['value'];
            self::closeMissing($ent, $productId, $qty, 'WRITTEN_OFF'); self::missingTasks($ent);
            return ['written_off' => $qty, 'value' => round($value, 2), 'message' => "$qty unit(s) written off as stock loss (" . number_format(abs($value), 2) . ').'];
        });
    }

    public static function cancel(int $countId): void
    {
        self::get($countId, ['OPEN', 'SUBMITTED', 'APPROVED']);
        DB::table('t_stock_counts')->where('id', $countId)->update(['status' => 'CANCELLED']);
    }

    public static function differences(int $countId): array
    {
        return DB::table('t_stock_count_lines as l')->join('t_product_vendors as p', 'p.id', '=', 'l.fk_id_product')->where('l.fk_id_count', $countId)->whereNotNull('l.qty_counted')->whereRaw('COALESCE(l.qty_recount, l.qty_counted) <> l.qty_system')
            ->get(['l.fk_id_product', 'l.fk_id_location', 'p.SKU_VENDOR as sku', 'l.qty_system', DB::raw('COALESCE(l.qty_recount, l.qty_counted) as qty_counted'), 'l.note', DB::raw('COALESCE(l.qty_recount, l.qty_counted) - l.qty_system as diff')])->all();      // the recount is the number that counts
    }

    /** Variance value at each product's last FINAL cost (else its last cost). */
    private static function variance(int $countId): float
    {
        $v = 0.0;
        foreach (self::differences($countId) as $d) {
            $q = fn () => DB::table('fin_lots')->where('fk_id_product', $d->fk_id_product)->where('source', '<>', 'DAMAGED')->orderByDesc('received_date')->orderByDesc('id');
            $cost = (float) ($q()->where('landed_status', 'FINAL')->value('unit_cost_gbp') ?? $q()->value('unit_cost_gbp') ?? 0);
            $v += $d->diff * $cost;
        }
        return round($v, 2);
    }

    private static function get(int $id, array $statuses): object
    {
        $c = DB::table('t_stock_counts')->where('id', $id)->where('fk_id_ent', Context::ent())->lockForUpdate()->first() ?? throw new \RuntimeException('Count not found.');
        if (! in_array($c->status, $statuses, true)) throw new \RuntimeException("This count is {$c->status}; this step is not possible now.");
        return $c;
    }
}
