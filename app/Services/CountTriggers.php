<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Automatic counts (R-H-47, decision W-10). A TRIGGERED count line for a bin is created when:
 *  (1) a short pick happens there; (2) a bin reaches 0 by a pick (WH_COUNT_ON_ZERO, default Y) – the picker is asked "Is <bin> empty?":
 *      Yes counts as the count (posted at 0), No → a TRIGGERED line; (3) any negative attempt (taking more than the bin holds); (4) a putaway refused for no room.
 * Plus the ABC schedule (WH_CYCLE_COUNT_MODE = ABC_SCHEDULE): SKUs ranked by units moved in WH_ABC_DAYS (A = top WH_ABC_A_PCT % of units, B = next WH_ABC_B_PCT %, C = rest);
 * each SKU's bins are counted every WH_COUNT_DAYS_A / _B / _C days; the nightly job builds today's list within WH_COUNT_DAILY_MAX_BINS. Task WH_COUNT_DUE per count session.
 * A negative attempt happens inside a transaction that is rolled back, so it is remembered and written after the request (flush()).
 */
class CountTriggers
{
    private static array $pending = [];
    private static bool $hooked = false;

    /** Add a TRIGGERED line for the bin (and product) to the bin's open triggered count, or open one. Returns the count id. */
    public static function trigger(int $ent, int $bin, ?int $product, string $source): int
    {
        $c = DB::table('t_stock_counts')->where('fk_id_ent', $ent)->where('fk_id_location', $bin)->where('count_type', 'TRIGGERED')->where('status', 'OPEN')->first();
        if (! $c) {
            $id = (int) DB::table('t_stock_counts')->insertGetId(['fk_id_ent' => $ent, 'count_code' => Numbering::next('CNT', $ent), 'fk_id_location' => $bin, 'd_count' => date('Y-m-d'), 'status' => 'OPEN', 'counted_by' => Context::userId(),
                'created_at' => now(), 'count_type' => 'TRIGGERED', 'trigger_source' => mb_substr($source, 0, 30), 'f_blind' => Settings::get('WH_COUNT_BLIND', $ent, 'Y') === 'Y' ? 1 : 0]);
            $name = DB::table('cat_inventory_locations')->where('id', $bin)->value('location_name');
            TaskEngine::open('WH_COUNT_DUE', [$id], "Count bin $name (" . strtolower(str_replace('_', ' ', $source)) . ')', ['ent' => $ent, 'module' => 't_stock_counts', 'record_id' => $id, 'fix_url' => '#/stock/counts/' . $id]);
        } else $id = (int) $c->id;
        $products = $product ? [$product] : DB::table('fin_lot_balances as b')->join('fin_lots as l', 'l.id', '=', 'b.fk_id_lot')->where('b.fk_id_location', $bin)->where('b.qty', '>', 0)->distinct()->pluck('l.fk_id_product')->all();
        foreach ($products as $p) if (! DB::table('t_stock_count_lines')->where('fk_id_count', $id)->where('fk_id_product', $p)->exists())
            DB::table('t_stock_count_lines')->insert(['fk_id_count' => $id, 'fk_id_product' => $p, 'qty_system' => self::inBin((int) $p, $bin), 'fk_id_location' => $bin, 'snapshot_at' => now(), 'line_status' => 'OPEN', 'note' => mb_substr('Triggered: ' . $source, 0, 255)]);
        return $id;
    }

    public static function inBin(int $product, int $bin): int
    {
        return (int) DB::table('fin_lot_balances as b')->join('fin_lots as l', 'l.id', '=', 'b.fk_id_lot')->where('l.fk_id_product', $product)->where('b.fk_id_location', $bin)->sum('b.qty');
    }

    /** "Is <bin> empty?" after a pick took the last unit. Yes = the count (posted at 0); No = a TRIGGERED count line. */
    public static function emptyAnswer(int $ent, int $bin, int $product, bool $empty): array
    {
        if (! $empty) { $id = self::trigger($ent, $bin, $product, 'EMPTY_NO'); return ['count' => $id, 'message' => 'Thank you – the bin is on the count list.']; }
        $id = (int) DB::table('t_stock_counts')->insertGetId(['fk_id_ent' => $ent, 'count_code' => Numbering::next('CNT', $ent), 'fk_id_location' => $bin, 'd_count' => date('Y-m-d'), 'status' => 'POSTED', 'counted_by' => Context::userId(),
            'created_at' => now(), 'count_type' => 'TRIGGERED', 'trigger_source' => 'EMPTY_YES', 'f_blind' => 0, 'submitted_at' => now(), 'variance_value' => 0]);
        DB::table('t_stock_count_lines')->insert(['fk_id_count' => $id, 'fk_id_product' => $product, 'qty_system' => self::inBin($product, $bin), 'qty_counted' => 0, 'fk_id_location' => $bin, 'snapshot_at' => now(), 'line_status' => 'DONE', 'counted_by' => Context::userId(), 'note' => 'Is it empty? Yes']);
        return ['count' => $id, 'message' => 'Counted: the bin is empty.'];
    }

    /** A negative attempt (taking more than a bin holds): remembered now, written after the request (the attempt's transaction is rolled back). */
    public static function negativeAttempt(int $ent, int $bin, int $product): void
    {
        self::$pending[] = [$ent, $bin, $product];
        if (DB::transactionLevel() === 0) { self::flush(); return; }
        if (! self::$hooked) { self::$hooked = true; app()->terminating(fn () => self::flush()); }
    }

    public static function flush(): int
    {
        $n = 0; $list = self::$pending; self::$pending = [];
        foreach ($list as [$ent, $bin, $product]) { try { self::trigger($ent, $bin, $product, 'NEGATIVE'); $n++; } catch (\Throwable $e) { report($e); } }
        return $n;
    }

    /** ABC class per product from units moved in the last WH_ABC_DAYS (picks / consumption). */
    public static function classes(int $ent): array
    {
        $days = (int) (Settings::get('WH_ABC_DAYS', $ent) ?: 90); $a = (float) (Settings::get('WH_ABC_A_PCT', $ent) ?: 80); $b = (float) (Settings::get('WH_ABC_B_PCT', $ent) ?: 15);
        $units = DB::table('fin_lot_consumption as c')->join('fin_lots as l', 'l.id', '=', 'c.lot_id')->where('l.company_id', $ent)->where('c.consumed_on', '>=', date('Y-m-d', strtotime("-$days days")))
            ->groupBy('l.fk_id_product')->selectRaw('l.fk_id_product p, SUM(c.qty) u')->orderByDesc('u')->pluck('u', 'p')->all();
        $tot = array_sum($units) ?: 1; $run = 0; $out = [];
        foreach ($units as $p => $u) { $out[(int) $p] = $run / $tot * 100 < $a ? 'A' : ($run / $tot * 100 < $a + $b ? 'B' : 'C'); $run += $u; }
        return $out;
    }

    /** Nightly ABC schedule: bins whose SKUs are due, oldest first, at most WH_COUNT_DAILY_MAX_BINS; one count session (and WH_COUNT_DUE) per bin. */
    public static function schedule(int $ent, ?string $today = null): array
    {
        if (Settings::get('WH_CYCLE_COUNT_MODE', $ent, 'TRIGGERS_ONLY') !== 'ABC_SCHEDULE') return ['sessions' => 0, 'bins' => []];
        $today ??= date('Y-m-d'); $cls = self::classes($ent);
        $every = ['A' => (int) (Settings::get('WH_COUNT_DAYS_A', $ent) ?: 30), 'B' => (int) (Settings::get('WH_COUNT_DAYS_B', $ent) ?: 90), 'C' => (int) (Settings::get('WH_COUNT_DAYS_C', $ent) ?: 180)];
        $due = [];
        foreach (DB::table('fin_lot_balances as b')->join('fin_lots as l', 'l.id', '=', 'b.fk_id_lot')->join('cat_inventory_locations as c', 'c.id', '=', 'b.fk_id_location')->where('l.company_id', $ent)->where('b.qty', '>', 0)->where('c.kind', 'BIN')
            ->groupBy('b.fk_id_location', 'l.fk_id_product')->get(['b.fk_id_location as bin', 'l.fk_id_product as p']) as $r) {
            $last = DB::table('t_stock_count_lines as cl')->join('t_stock_counts as sc', 'sc.id', '=', 'cl.fk_id_count')->where('sc.fk_id_location', $r->bin)->where('cl.fk_id_product', $r->p)->where('sc.status', 'POSTED')->max('sc.d_count');
            $class = $cls[(int) $r->p] ?? 'C';
            if ($last && $last > date('Y-m-d', strtotime("$today -{$every[$class]} days"))) continue;
            $due[(int) $r->bin] = min($due[(int) $r->bin] ?? '9999-12-31', $last ?: '0000-00-00');
        }
        asort($due); $bins = array_slice(array_keys($due), 0, (int) (Settings::get('WH_COUNT_DAILY_MAX_BINS', $ent) ?: 20));
        $made = 0;
        foreach ($bins as $bin) {
            if (DB::table('t_stock_counts')->where('fk_id_location', $bin)->where('status', 'OPEN')->exists()) continue;
            $id = CountService::open($ent, $bin, null, Settings::get('WH_COUNT_BLIND', $ent, 'Y') === 'Y');
            DB::table('t_stock_counts')->where('id', $id)->update(['count_type' => 'ABC', 'trigger_source' => 'ABC_SCHEDULE']);
            TaskEngine::open('WH_COUNT_DUE', [$id], 'Count bin ' . DB::table('cat_inventory_locations')->where('id', $bin)->value('location_name') . ' (ABC schedule)', ['ent' => $ent, 'module' => 't_stock_counts', 'record_id' => $id, 'fix_url' => '#/stock/counts/' . $id]);
            $made++;
        }
        return ['sessions' => $made, 'bins' => $bins];
    }
}
