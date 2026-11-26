<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Gen-2 money rules kept for the migration compare (R-A_SALES-52).
 * - The Gen-2 values (profit, loss, purchase amount, product sale price, raw, status) are copied into the legacy columns of t_sales – never recomputed.
 * - Loss_Sale lines: Confirmed + loss flag, with a SAL_ZERO line in their history. The Gen-2 change log (t_product_sale_change_log) goes into sys_history (R-G_CORE-122).
 * - Compare report per shop / month: count, qty, sale, old purchase / profit against the new rule (purchase = raw × qty + carrier + packing for every qty),
 *   each difference explained: "qty = 1 postage" (Gen-2 left carrier + packing out of a qty-1 purchase), loss lines, refunds; the rest is "not explained".
 */
class SalesLegacy
{
    /** After the Gen-2 sales are copied: SAL_ZERO history for loss lines and the change log into history (once each). */
    public static function afterImport(string $table): array
    {
        $done = DB::table('sys_audit_log')->where('table_name', 't_sales')->where('reason', 'like', 'Gen-2 Loss_Sale%')->pluck('record_id')->flip();
        $rows = [];
        foreach (DB::table('t_sales')->where('legacy_table', $table)->where('f_loss_line', 1)->get(['id', 'fk_id_ent', 'created_at']) as $s) {
            if (isset($done[$s->id])) continue;
            $rows[] = ['fk_id_ent' => $s->fk_id_ent, 'table_name' => 't_sales', 'record_id' => $s->id, 'action' => 'UPDATE', 'changes_json' => json_encode(['task' => 'SAL_ZERO', 'status' => ['Loss_Sale', 'Confirmed + loss flag']]), 'reason' => 'Gen-2 Loss_Sale (task type SAL_ZERO)', 'user_id' => null, 'created_at' => $s->created_at ?? now()];
        }
        foreach (array_chunk($rows, 500) as $c) DB::table('sys_audit_log')->insert($c);
        $log = (int) (History::cutover()['t_product_sale_change_log'] ?? 0);      // R-G_CORE-122 the Gen-2 change log goes into the one history (sys_history), once
        return ['sal_zero' => count($rows), 'change_log' => $log];
    }

    /** New-rule purchase of a Gen-2 line: raw × qty + carrier + packing (for every qty, also qty 1). Carrier + packing = product sale price − raw. */
    public static function newPurchase(object $l): ?float
    {
        if ($l->legacy_raw === null || $l->legacy_pur_rate === null) return $l->legacy_pur_amount !== null ? (float) $l->legacy_pur_amount : null;
        return round((float) $l->legacy_raw * (int) $l->qty + ((float) $l->legacy_pur_rate - (float) $l->legacy_raw), 2);
    }

    /** One line: old and new figures and why they differ. */
    public static function line(object $l): array
    {
        $oldProfit = round((float) $l->legacy_profit + ((float) $l->legacy_loss < 0 ? (float) $l->legacy_loss : 0), 2);
        $newPur = self::newPurchase($l); $total = (float) $l->total_amount;
        $newProfit = $l->status === 'Refunded' ? round(-abs((float) $l->refund_loss_amount), 2) : ($newPur !== null ? round($total - $newPur, 2) : $oldProfit);
        $why = null;
        if (round($newProfit - $oldProfit, 2) != 0.0 || ($newPur !== null && $l->legacy_pur_amount !== null && round($newPur - (float) $l->legacy_pur_amount, 2) != 0.0))
            $why = $l->status === 'Refunded' ? 'refund' : ((int) $l->f_loss_line || (float) $l->legacy_loss < 0 ? 'loss line' : ((int) $l->qty === 1 ? 'qty = 1 postage' : 'not explained'));
        return ['old_purchase' => $l->legacy_pur_amount !== null ? (float) $l->legacy_pur_amount : null, 'new_purchase' => $newPur, 'old_profit' => $oldProfit, 'new_profit' => $newProfit, 'why' => $why];
    }

    /** Compare report per shop / month. $f: shop, month (YYYY-MM). */
    public static function compare(int $ent, array $f = []): array
    {
        $q = DB::table('t_sales as s')->leftJoin('t_branches as b', 'b.id', '=', 's.fk_id_branch')->where('s.fk_id_ent', $ent)->whereNotNull('s.legacy_status')
            ->when(! empty($f['shop']), fn ($x) => $x->where('s.fk_id_branch', (int) $f['shop']))->when(! empty($f['month']), fn ($x) => $x->whereRaw("DATE_FORMAT(s.d_order, '%Y-%m') = ?", [$f['month']]))
            ->orderBy('b.shop_name')->orderBy('s.d_order');
        $groups = [];
        foreach ($q->get(['s.id', 's.fk_id_branch', 'b.shop_name', 's.d_order', 's.qty', 's.total_amount', 's.status', 's.refund_loss_amount', 's.legacy_profit', 's.legacy_loss', 's.legacy_pur_amount', 's.legacy_pur_rate', 's.legacy_raw', 's.legacy_status', 's.f_loss_line']) as $l) {
            $k = $l->fk_id_branch . '|' . substr((string) $l->d_order, 0, 7); $x = self::line($l);
            $g = &$groups[$k];
            $g ??= ['shop' => (int) $l->fk_id_branch, 'shop_name' => $l->shop_name ?: '#' . $l->fk_id_branch, 'month' => substr((string) $l->d_order, 0, 7), 'lines' => 0, 'qty' => 0, 'sale' => 0.0, 'old_purchase' => 0.0, 'new_purchase' => 0.0, 'old_profit' => 0.0, 'new_profit' => 0.0,
                'explained' => ['qty = 1 postage' => [0, 0.0], 'loss line' => [0, 0.0], 'refund' => [0, 0.0], 'not explained' => [0, 0.0]]];
            $g['lines']++; $g['qty'] += (int) $l->qty; $g['sale'] += (float) $l->total_amount; $g['old_purchase'] += (float) ($x['old_purchase'] ?? 0); $g['new_purchase'] += (float) ($x['new_purchase'] ?? 0);
            $g['old_profit'] += $x['old_profit']; $g['new_profit'] += $x['new_profit'];
            if ($x['why']) { $g['explained'][$x['why']][0]++; $g['explained'][$x['why']][1] += round($x['new_profit'] - $x['old_profit'], 2) ?: round((float) ($x['new_purchase'] ?? 0) - (float) ($x['old_purchase'] ?? 0), 2); }
            unset($g);
        }
        $rows = array_values(array_map(function ($g) {
            foreach (['sale', 'old_purchase', 'new_purchase', 'old_profit', 'new_profit'] as $k) $g[$k] = round($g[$k], 2);
            $g['difference'] = round($g['new_profit'] - $g['old_profit'], 2);
            $g['explained'] = array_map(fn ($e) => ['lines' => $e[0], 'amount' => round($e[1], 2)], $g['explained']);
            return $g;
        }, $groups));
        $shops = DB::table('t_sales as s')->join('t_branches as b', 'b.id', '=', 's.fk_id_branch')->where('s.fk_id_ent', $ent)->whereNotNull('s.legacy_status')->distinct()->orderBy('b.shop_name')->get(['b.id', 'b.shop_name as name']);
        return ['rows' => $rows, 'shops' => $shops, 'filters' => $f];
    }
}
