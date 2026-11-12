<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Stock count (R-D-50): open (the system quantity is frozen per line) → count → submit → approve → post.
 * A count never overwrites stock: only the differences are posted, as count_adjust lines through the stock service.
 * The person who counted cannot approve his own count.
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
    }

    public static function submit(int $countId): array
    {
        $c = self::get($countId, ['OPEN']);
        $missing = DB::table('t_stock_count_lines')->where('fk_id_count', $countId)->whereNull('qty_counted')->count();
        if ($missing) throw new \RuntimeException("$missing line(s) are not counted yet.");
        $value = self::variance($countId);
        DB::table('t_stock_counts')->where('id', $countId)->update(['status' => 'SUBMITTED', 'submitted_at' => now(), 'variance_value' => $value]);
        EventBus::fire('COUNT_SUBMITTED', 't_stock_counts', $countId, ['variance' => $value], (int) $c->fk_id_ent);
        return ['variance_value' => $value, 'differences' => self::differences($countId)];
    }

    public static function approve(int $countId): void
    {
        $c = self::get($countId, ['SUBMITTED']);
        if ((int) $c->counted_by === (int) Context::userId()) throw new \RuntimeException('You counted this yourself. Somebody else has to approve it.');
        DB::table('t_stock_counts')->where('id', $countId)->update(['status' => 'APPROVED', 'approved_by' => Context::userId(), 'approved_at' => now()]);
        Audit::log('t_stock_counts', $countId, 'APPROVE', ['variance_value' => $c->variance_value], null, (int) $c->fk_id_ent);
    }

    /** Post the differences. Stock that moved after the snapshot is respected: the difference is posted, the counted number is never written. */
    public static function post(int $countId): array
    {
        $c = self::get($countId, ['APPROVED']);
        return DB::transaction(function () use ($c, $countId) {
            $n = 0; $value = 0.0;
            foreach (self::differences($countId) as $d) {
                $r = StockService::adjust((int) $c->fk_id_ent, (int) $d->fk_id_product, (int) $d->fk_id_location, (int) $d->diff, "Stock count {$c->count_code}" . ($d->note ? ': ' . $d->note : ''),
                    ['type' => 'count_adjust', 'source_type' => 'COUNT', 'source_id' => $countId, 'unit_cost' => 0]);
                $value += $r['value']; $n++;
            }
            DB::table('t_stock_counts')->where('id', $countId)->update(['status' => 'POSTED']);
            DB::table('t_stock_count_lines')->where('fk_id_count', $countId)->update(['line_status' => 'POSTED']);
            EventBus::fire('STOCK_COUNT_POSTED', 't_stock_counts', $countId, ['lines' => $n, 'value' => round($value, 2)], (int) $c->fk_id_ent);
            return ['lines_posted' => $n, 'value' => round($value, 2)];
        });
    }

    public static function cancel(int $countId): void
    {
        self::get($countId, ['OPEN', 'SUBMITTED', 'APPROVED']);
        DB::table('t_stock_counts')->where('id', $countId)->update(['status' => 'CANCELLED']);
    }

    public static function differences(int $countId): array
    {
        return DB::table('t_stock_count_lines as l')->join('t_product_vendors as p', 'p.id', '=', 'l.fk_id_product')->where('l.fk_id_count', $countId)->whereNotNull('l.qty_counted')->whereRaw('l.qty_counted <> l.qty_system')
            ->get(['l.fk_id_product', 'l.fk_id_location', 'p.SKU_VENDOR as sku', 'l.qty_system', 'l.qty_counted', 'l.note', DB::raw('l.qty_counted - l.qty_system as diff')])->all();
    }

    private static function variance(int $countId): float
    {
        $v = 0.0;
        foreach (self::differences($countId) as $d) {
            $cost = (float) (DB::table('fin_lots')->where('fk_id_product', $d->fk_id_product)->orderByDesc('received_date')->orderByDesc('id')->value('unit_cost_gbp') ?? 0);
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
