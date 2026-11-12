<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Profit views. One set of numbers, three ways to slice it: by shop, by product, by month (of the order date).
 * Sales and cost of goods come from confirmed sale lines and the lots they used; marketplace costs from the money files that were loaded
 * (so a shop / month with no money file yet shows "marketplace costs not in yet" – its profit is before those costs);
 * other costs from the expenses. The ledger's own profit for the same dates is shown beside it as the control figure.
 */
class ProfitService
{
    public const VIEWS = ['shop' => 'By shop', 'product' => 'By product', 'month' => 'By month'];

    public static function view(int $ent, string $by, string $from, string $to): array
    {
        if (! isset(self::VIEWS[$by])) $by = 'shop';
        $rows = []; $blank = fn (string $key, string $name) => ['key' => $key, 'name' => $name, 'units' => 0, 'sales' => 0.0, 'cost' => 0.0, 'mp_costs' => 0.0, 'expenses' => 0.0, 'mp_in' => false];
        $shops = DB::table('t_branches')->where('fk_id_ent', $ent)->pluck('shop_name', 'id');
        $keyOf = function ($shopId, $productId, $sku, $name, $date) use ($by, $shops) {
            return match ($by) {
                'shop' => ['S' . (int) $shopId, $shops[$shopId] ?? 'No shop'],
                'product' => $productId ? ['P' . (int) $productId, trim(($sku ?: '') . ' · ' . ($name ?: ''), ' ·')] : ['P0', 'Not linked to a product'],
                default => [substr((string) $date, 0, 7), date('M Y', strtotime(substr((string) $date, 0, 7) . '-01'))],
            };
        };
        // 1. confirmed sale lines and the cost of the lots they used
        $sales = DB::table('t_sales as s')->leftJoin('t_product_vendors as p', 'p.id', '=', 's.fk_product_id')->where('s.fk_id_ent', $ent)->where('s.sale_source', 'active')->where('s.status', 'Confirmed')->whereBetween('s.d_order', [$from, $to])
            ->get(['s.id', 's.fk_id_branch', 's.fk_product_id', 'p.SKU_VENDOR as sku', 'p.product_name_vendor as name', 's.d_order', 's.qty', 's.total_amount_gbp', 's.order_no', 's.refunded_at', 's.refund_loss_amount']);
        $cost = DB::table('fin_lot_consumption')->whereIn('fk_id_sale', $sales->pluck('id'))->groupBy('fk_id_sale')->selectRaw('fk_id_sale, SUM(qty * unit_cost_gbp) c')->pluck('c', 'fk_id_sale');
        $orderKey = []; $refunds = 0.0;
        foreach ($sales as $s) {
            [$k, $n] = $keyOf($s->fk_id_branch, $s->fk_product_id, $s->sku, $s->name, $s->d_order); $rows[$k] ??= $blank($k, $n);
            $rows[$k]['units'] += (int) $s->qty; $rows[$k]['sales'] += (float) $s->total_amount_gbp; $rows[$k]['cost'] += (float) ($cost[$s->id] ?? 0);
            if ($s->refunded_at) { $rows[$k]['mp_costs'] += (float) $s->refund_loss_amount; $refunds += (float) $s->refund_loss_amount; }
            $orderKey[$s->fk_id_branch . '|' . $s->order_no] ??= [$s->fk_product_id, $s->sku, $s->name, $s->d_order];
        }
        // 2. marketplace costs from booked statements (everything in the money file except net sales; a cost is a positive number here)
        $lines = DB::table('fin_settlement_lines as l')->join('mo_settlements as m', fn ($j) => $j->on('m.fk_id_branch', '=', 'l.branch_id')->on('m.statement_id', '=', 'l.settlement_id'))->where('l.company_id', $ent)->whereIn('m.status', ['POSTED', 'PAID'])
            ->where('l.amount_type', '<>', 'NET_SALES')->whereBetween('m.statement_date', [$from, $to])->groupBy('l.branch_id', 'l.order_id', 'm.statement_date')->selectRaw('l.branch_id, l.order_id, m.statement_date d, SUM(l.amount) v')->get();
        $mpTotal = 0.0;
        foreach ($lines as $l) {
            $o = $orderKey[$l->branch_id . '|' . $l->order_id] ?? null;
            [$k, $n] = $by === 'product' ? $keyOf($l->branch_id, $o[0] ?? null, $o[1] ?? null, $o[2] ?? null, $l->d) : $keyOf($l->branch_id, null, null, null, $l->d);
            $rows[$k] ??= $blank($k, $n); $rows[$k]['mp_costs'] += -1 * (float) $l->v; $rows[$k]['mp_in'] = true; $mpTotal += -1 * (float) $l->v;
        }
        // 3. other costs (expenses); those of one shop go to that shop, the rest is overhead
        $exp = DB::table('fin_expenses')->where('fk_id_ent', $ent)->where('f_active', 1)->whereBetween('d_expense', [$from, $to])->get(['fk_id_branch', 'd_expense', 'amount_gbp']);
        foreach ($exp as $e) {
            [$k, $n] = $by === 'month' ? $keyOf(null, null, null, null, $e->d_expense) : ($by === 'shop' && $e->fk_id_branch ? $keyOf($e->fk_id_branch, null, null, null, null) : ['ZZ', 'Overheads (not for one ' . ($by === 'shop' ? 'shop' : 'product') . ')']);
            $rows[$k] ??= $blank($k, $n); $rows[$k]['expenses'] += (float) $e->amount_gbp;
        }
        ksort($rows); $tot = $blank('TOTAL', 'Total');
        foreach ($rows as &$r) {
            foreach (['sales', 'cost', 'mp_costs', 'expenses'] as $c) { $r[$c] = round($r[$c], 2); $tot[$c] += $r[$c]; } $tot['units'] += $r['units'];
            $r['gross'] = round($r['sales'] - $r['cost'], 2); $r['profit'] = round($r['gross'] - $r['mp_costs'] - $r['expenses'], 2); $r['margin_pct'] = $r['sales'] > 0 ? round($r['profit'] / $r['sales'] * 100, 1) : null;
            $r['estimate'] = $r['sales'] > 0 && ! $r['mp_in'];   // sales but no marketplace costs loaded for this row
        }
        unset($r);
        foreach (['sales', 'cost', 'mp_costs', 'expenses'] as $c) $tot[$c] = round($tot[$c], 2);
        $tot['gross'] = round($tot['sales'] - $tot['cost'], 2); $tot['profit'] = round($tot['gross'] - $tot['mp_costs'] - $tot['expenses'], 2); $tot['margin_pct'] = $tot['sales'] > 0 ? round($tot['profit'] / $tot['sales'] * 100, 1) : null;
        $pl = Ledger::profitAndLoss($ent, $from, $to);
        // sales lines of the period that are not in the figures yet
        $open = DB::table('t_sales')->where('fk_id_ent', $ent)->where('sale_source', 'active')->where('status', 'Pending')->whereBetween('d_order', [$from, $to])->selectRaw('COUNT(*) n, COALESCE(SUM(total_amount_gbp),0) v')->first();
        return ['by' => $by, 'from' => $from, 'to' => $to, 'rows' => array_values($rows), 'total' => $tot, 'base' => Fx::base($ent), 'views' => self::VIEWS,
            'books' => ['profit' => $pl['profit'], 'income' => $pl['income'], 'expense' => $pl['expense'], 'difference' => round($tot['profit'] - $pl['profit'], 2)],
            'not_confirmed' => ['lines' => (int) $open->n, 'value' => round((float) $open->v, 2)], 'estimate_rows' => count(array_filter($rows, fn ($r) => $r['estimate']))];
    }
}
