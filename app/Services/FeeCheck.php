<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * MR-1536 fee engine: per order of the money files (fin_settlement_lines), the fee the marketplace charged against the fee expected from its
 * typical fee % + fixed fee per order (Marketplaces › fee_pct / fee_value; Settings FEE_PCT_SHOP_<shop> overrides for one shop).
 * An order charged more than expected by more than FEE_CHECK_TOLERANCE (£0.10) and FEE_CHECK_TOLERANCE_PCT (2 % of the sale) is an overcharge;
 * per shop × month one claim task FEE_OVERCHARGE with the total and the proof file (orders, settlement ids, file lines).
 */
class FeeCheck
{
    public static function rate(int $ent, int $shop): array
    {
        $m = DB::table('t_branches as b')->leftJoin('cat_marketplaces as m', 'm.id', '=', 'b.fk_id_marketplace')->where('b.id', $shop)->first(['m.fee_pct', 'm.fee_value', 'm.marketplace_short']);
        $own = Settings::get('FEE_PCT_SHOP_' . $shop, $ent, null);
        return ['pct' => $own !== null && $own !== '' ? (float) $own : ($m && $m->fee_pct !== null ? (float) $m->fee_pct : null), 'fixed' => (float) ($m->fee_value ?? 0), 'marketplace' => $m->marketplace_short ?? null];
    }

    /** Orders of the month with their sale, fee charged, fee expected and the difference; overcharges first. */
    public static function month(int $ent, string $ym, ?int $shop = null): array
    {
        $from = "$ym-01"; $to = date('Y-m-t', strtotime($from)) . ' 23:59:59';
        $tolA = (float) Settings::get('FEE_CHECK_TOLERANCE', $ent, 0.10); $tolP = (float) Settings::get('FEE_CHECK_TOLERANCE_PCT', $ent, 2);
        $lines = DB::table('fin_settlement_lines')->where('company_id', $ent)->whereNotNull('order_id')->where('order_id', '<>', '')->whereBetween('posted_date', [$from, $to])
            ->when($shop, fn ($q) => $q->where('branch_id', $shop))->whereIn('bucket', ['SALE', 'SHIPPING', 'FEE'])
            ->get(['id', 'branch_id', 'order_id', 'bucket', 'amount', 'settlement_id', 'amount_description']);
        $orders = []; $rates = [];
        foreach ($lines as $l) {
            $k = $l->branch_id . '|' . $l->order_id; $o = &$orders[$k];
            $o ??= ['shop' => (int) $l->branch_id, 'order_id' => (string) $l->order_id, 'sale' => 0.0, 'fee' => 0.0, 'settlements' => [], 'line_ids' => []];
            if ($l->bucket === 'FEE') $o['fee'] -= (float) $l->amount; else $o['sale'] += (float) $l->amount;
            $o['settlements'][(string) $l->settlement_id] = 1; $o['line_ids'][] = (int) $l->id; unset($o);
        }
        $rows = []; $miss = [];
        foreach ($orders as $o) {
            $r = $rates[$o['shop']] ??= self::rate($ent, $o['shop']);
            if ($r['pct'] === null) { $miss[$o['shop']] = $r['marketplace']; continue; }
            if ($o['sale'] <= 0) continue;      // refunded or fee-only orders are not judged
            $exp = round($o['sale'] * $r['pct'] / 100 + $r['fixed'], 2); $diff = round($o['fee'] - $exp, 2);
            $over = $diff > max($tolA, $o['sale'] * $tolP / 100);
            $rows[] = ['shop' => $o['shop'], 'order_id' => $o['order_id'], 'sale' => round($o['sale'], 2), 'fee' => round($o['fee'], 2), 'expected' => $exp, 'pct' => $r['pct'], 'diff' => $diff, 'over' => $over,
                'settlements' => implode(', ', array_keys($o['settlements'])), 'line_ids' => $o['line_ids']];
        }
        usort($rows, fn ($a, $b) => $b['over'] <=> $a['over'] ?: $b['diff'] <=> $a['diff']);
        $over = array_values(array_filter($rows, fn ($r) => $r['over']));
        return ['ym' => $ym, 'rows' => $rows, 'orders' => count($rows), 'overcharged' => count($over), 'over_total' => round(array_sum(array_column($over, 'diff')), 2),
            'no_rate' => array_values(array_unique(array_filter($miss))), 'no_rate_shops' => array_keys($miss)];
    }

    /** One FEE_OVERCHARGE task per shop × month with overcharges; closes when the month has none (e.g. refunded by the marketplace). */
    public static function tasks(int $ent, ?string $ym = null): int
    {
        $ym ??= date('Y-m', strtotime('first day of last month')); $d = self::month($ent, $ym); $per = [];
        foreach ($d['rows'] as $r) if ($r['over']) { $per[$r['shop']]['n'] = ($per[$r['shop']]['n'] ?? 0) + 1; $per[$r['shop']]['sum'] = round(($per[$r['shop']]['sum'] ?? 0) + $r['diff'], 2); }
        $probs = [];
        foreach ($per as $shop => $x) { $no = DB::table('t_branches')->where('id', $shop)->value('shop_no');
            $probs[] = ['key' => [$shop, $ym], 'title' => "Claim marketplace fees: $no {$ym} – {$x['n']} order(s) charged £{$x['sum']} more than expected", 'opt' => ['fix_url' => "#/fee-check?ym=$ym&shop=$shop", 'amount' => $x['sum'],
                'description' => 'Proof: download the overcharge list (orders, settlement ids, file lines) from the fee check page and attach it to the claim.']]; }
        return TaskEngine::sync('FEE_OVERCHARGE', $probs, null, $ent)['opened'];
    }

    /** Proof file (CSV) of the overcharged orders of a shop-month. */
    public static function proof(int $ent, string $ym, int $shop): string
    {
        $out = fopen('php://temp', 'r+'); fputcsv($out, ['Order', 'Sale', 'Fee charged', 'Fee expected', 'Fee %', 'Overcharge', 'Settlement ids', 'Money-file line ids']);
        foreach (self::month($ent, $ym, $shop)['rows'] as $r) if ($r['over']) fputcsv($out, [$r['order_id'], $r['sale'], $r['fee'], $r['expected'], $r['pct'], $r['diff'], $r['settlements'], implode(' ', $r['line_ids'])]);
        rewind($out); return stream_get_contents($out);
    }
}
