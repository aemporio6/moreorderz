<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * MR-1544 buyer / return-abuse records: returns (t_sale_returns, return intakes) and refunds (refunded lines, accepted refund proposals) of the last
 * RETURN_ABUSE_DAYS (365) grouped by the buyer (customer id, else name + postcode) and by the delivery address (postcode + first address line).
 * A buyer or address with RETURN_ABUSE_MIN (3) or more returns / claims is listed with its orders and rate; task RET_BUYER_ABUSE once per buyer;
 * warning() answers "is this order's buyer on the list?" for the order screens.
 */
class ReturnAbuse
{
    private static function norm(?string $s): string { return trim(preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower((string) $s))); }

    public static function buyerKey(object $s): ?string
    {
        if (! empty($s->fk_id_customer)) return 'C' . (int) $s->fk_id_customer;
        $n = self::norm($s->ship_to_name ?? ''); $p = str_replace(' ', '', self::norm($s->ship_to_postcode ?? ''));
        return $n !== '' && $p !== '' ? "N:$n|$p" : null;
    }

    public static function addressKey(object $s): ?string
    {
        $p = str_replace(' ', '', self::norm($s->ship_to_postcode ?? '')); $a = self::norm(explode(',', (string) ($s->ship_to_address ?? ''))[0] ?? '');
        return $p !== '' && $a !== '' ? "A:$p|$a" : null;
    }

    /** Sale lines with a return or a claim in the window, and every line of the same buyers (for the rate). */
    public static function scan(int $ent, ?string $today = null): array
    {
        $today ??= now()->toDateString(); $days = (int) Settings::get('RETURN_ABUSE_DAYS', $ent, 365); $min = max(2, (int) Settings::get('RETURN_ABUSE_MIN', $ent, 3));
        $from = date('Y-m-d', strtotime("$today -$days days"));
        $bad = DB::table('t_sale_returns')->where('fk_id_ent', $ent)->whereNotNull('fk_id_sale')->where('created_at', '>=', $from)->pluck('fk_id_sale')
            ->merge(DB::table('wh_return_intakes')->where('fk_id_ent', $ent)->whereNotNull('fk_id_sale')->where('received_at', '>=', $from)->pluck('fk_id_sale'))
            ->merge(DB::table('t_sale_refund_proposals')->whereNotNull('fk_id_sale')->where('status', 'ACCEPTED')->where('created_at', '>=', $from)->pluck('fk_id_sale'))
            ->merge(DB::table('t_sales')->where('fk_id_ent', $ent)->where('status', 'Refunded')->where('d_order', '>=', $from)->pluck('id'))
            ->map(fn ($x) => (int) $x)->unique()->values()->all();
        if (! $bad) return ['rows' => [], 'min' => $min, 'days' => $days];
        $cols = ['id', 'order_no', 'd_order', 'fk_id_branch', 'fk_id_customer', 'ship_to_name', 'ship_to_postcode', 'ship_to_address', 'total_amount'];
        $groups = [];
        foreach (DB::table('t_sales')->where('fk_id_ent', $ent)->whereIn('id', $bad)->get($cols) as $s)
            foreach (array_filter([self::buyerKey($s), self::addressKey($s)]) as $k) { $groups[$k]['claims'][$s->id] = $s; }
        $rows = [];
        foreach ($groups as $k => $g) {
            if (count($g['claims']) < $min) continue;
            $one = reset($g['claims']);
            $all = DB::table('t_sales')->where('fk_id_ent', $ent)->where('d_order', '>=', $from)
                ->when(str_starts_with($k, 'C'), fn ($q) => $q->where('fk_id_customer', (int) substr($k, 1)), fn ($q) => $q->whereRaw('REPLACE(LOWER(ship_to_postcode),\' \',\'\') = ?', [str_replace(' ', '', self::norm($one->ship_to_postcode))]))
                ->get($cols)->filter(fn ($s) => in_array($k, array_filter([self::buyerKey($s), self::addressKey($s)]), true));
            $orders = $all->pluck('order_no')->unique()->count();
            $rows[] = ['key' => $k, 'kind' => str_starts_with($k, 'A:') ? 'Address' : 'Buyer', 'name' => (string) $one->ship_to_name, 'postcode' => (string) $one->ship_to_postcode,
                'claims' => count($g['claims']), 'orders' => max($orders, count($g['claims'])), 'rate' => round(count($g['claims']) / max(1, $all->count()) * 100), 'shops' => $all->pluck('fk_id_branch')->unique()->count(),
                'value' => round((float) collect($g['claims'])->sum('total_amount'), 2), 'last' => (string) collect($g['claims'])->max('d_order'), 'sale_ids' => array_keys($g['claims'])];
        }
        usort($rows, fn ($a, $b) => $b['claims'] <=> $a['claims'] ?: $b['value'] <=> $a['value']);
        return ['rows' => $rows, 'min' => $min, 'days' => $days];
    }

    /** Nightly: one task per listed buyer / address; gone from the list → closed. */
    public static function tasks(int $ent): int
    {
        $probs = array_map(fn ($r) => ['key' => [substr(md5($r['key']), 0, 16)], 'title' => "{$r['kind']} with {$r['claims']} returns / claims in {$r['orders']} orders – {$r['name']} {$r['postcode']}", 'opt' => ['fix_url' => '#/return-abuse', 'amount' => $r['value']]], self::scan($ent)['rows']);
        return TaskEngine::sync('RET_BUYER_ABUSE', $probs, null, $ent)['opened'];
    }

    /** Is the buyer or address of this sale line on the list? */
    public static function warning(int $ent, int $saleId): ?array
    {
        $s = DB::table('t_sales')->where('id', $saleId)->where('fk_id_ent', $ent)->first(['id', 'fk_id_customer', 'ship_to_name', 'ship_to_postcode', 'ship_to_address']); if (! $s) return null;
        $keys = array_filter([self::buyerKey($s), self::addressKey($s)]);
        foreach (self::scan($ent)['rows'] as $r) if (in_array($r['key'], $keys, true)) return ['text' => "Warning: this {$r['kind']} has {$r['claims']} returns / claims in {$r['orders']} orders (last {$r['last']}).", 'claims' => $r['claims']];
        return null;
    }
}
