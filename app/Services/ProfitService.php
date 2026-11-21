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
    /** "Profit by" (R-FIN-74): every view is cut from the same confirmed sale lines, costs and fees, so the grand totals are equal. Viewing never changes a saved figure. */
    public const VIEWS = ['shop' => 'By shop', 'product' => 'By SKU', 'month' => 'By month', 'lot' => 'By lot', 'batch' => 'By batch (PO / shipment)', 'investor' => 'By investor (lot owner)', 'share' => 'By partner / VA share'];
    public const HEADS = ['shop' => 'Shop', 'product' => 'SKU', 'month' => 'Month', 'lot' => 'Lot', 'batch' => 'Batch', 'investor' => 'Investor', 'share' => 'Share'];

    /** The lots a sale used: [sale id => [[lot id, qty, cost], …]]. */
    private static function lotsOf($saleIds): array
    {
        $out = [];
        foreach (DB::table('fin_lot_consumption')->whereIn('fk_id_sale', $saleIds ?: [0])->get(['fk_id_sale', 'lot_id', 'qty', 'unit_cost_gbp', 'fk_id_owner']) as $c) $out[$c->fk_id_sale][] = [(int) $c->lot_id, (float) $c->qty, (float) $c->qty * (float) $c->unit_cost_gbp, $c->fk_id_owner];
        return $out;
    }

    public static function view(int $ent, string $by, string $from, string $to, ?int $shop = null): array
    {
        if (! isset(self::VIEWS[$by])) $by = 'shop';
        if ($by === 'share') return self::share($ent, $from, $to, $shop);
        $lotView = in_array($by, ['lot', 'batch', 'investor'], true);
        $rows = []; $blank = fn (string $key, string $name) => ['key' => $key, 'name' => $name, 'units' => 0, 'sales' => 0.0, 'cost' => 0.0, 'mp_costs' => 0.0, 'expenses' => 0.0, 'mp_in' => false];
        $shops = DB::table('t_branches')->where('fk_id_ent', $ent)->pluck('shop_name', 'id');
        $keyOf = function ($shopId, $productId, $sku, $name, $date) use ($by, $shops) {
            return match ($by) {
                'shop' => ['S' . (int) $shopId, $shops[$shopId] ?? 'No shop'],
                'product' => $productId ? ['P' . (int) $productId, trim(($sku ?: '') . ' · ' . ($name ?: ''), ' ·')] : ['P0', 'Not linked to a product'],
                default => [substr((string) $date, 0, 7), date('M Y', strtotime(substr((string) $date, 0, 7) . '-01'))],
            };
        };
        $lotInfo = []; $lotKey = function (?int $lot, $owner) use ($by, &$lotInfo) {
            if (! $lot) return ['L0', 'No lot (no stock used)'];
            $l = $lotInfo[$lot] ??= DB::table('fin_lots as l')->leftJoin('t_src_po as p', 'p.id', '=', 'l.po_id')->where('l.id', $lot)->first(['l.id', 'l.lot_code', 'l.sku', 'l.po_id', 'l.source', 'l.fk_id_owner', 'p.po_number']);
            return match ($by) {
                'lot' => ['L' . $lot, trim(($l->lot_code ?: 'Lot #' . $lot) . ' · ' . ($l->sku ?? ''), ' ·')],
                'batch' => $l && $l->po_id ? ['B' . $l->po_id, $l->po_number ?: 'PO #' . $l->po_id] : ['B0-' . ($l->source ?? ''), 'No PO (' . strtolower((string) ($l->source ?? 'other')) . ')'],
                default => ($o = (int) ($owner ?: ($l->fk_id_owner ?? 0))) ? ['I' . $o, PartnerService::name($o)] : ['I0', 'Company (pool)'],
            };
        };
        $alloc = [];      // order → [row key => sales share] so the order's marketplace costs follow its sales
        // 1. confirmed sale lines and the cost of the lots they used
        $sales = \App\Services\Perm::mine(DB::table('t_sales as s'), 's.fk_id_branch')->leftJoin('t_product_vendors as p', 'p.id', '=', 's.fk_product_id')->where('s.fk_id_ent', $ent)->where('s.sale_source', 'active')->where('s.status', 'Confirmed')->whereBetween('s.d_order', [$from, $to])->when($shop, fn ($q) => $q->where('s.fk_id_branch', $shop))
            ->get(['s.id', 's.fk_id_branch', 's.fk_product_id', 'p.SKU_VENDOR as sku', 'p.product_name_vendor as name', 's.d_order', 's.qty', 's.total_amount_gbp', 's.order_no', 's.refunded_at', 's.refund_loss_amount']);
        $cost = DB::table('fin_lot_consumption')->whereIn('fk_id_sale', $sales->pluck('id'))->groupBy('fk_id_sale')->selectRaw('fk_id_sale, SUM(qty * unit_cost_gbp) c')->pluck('c', 'fk_id_sale');
        $orderKey = []; $refunds = 0.0; $adj = DB::table('t_sale_charges')->whereIn('fk_id_sale', $sales->pluck('id'))->where('kind', 'REFUND_ADJ')->groupBy('fk_id_sale')->selectRaw('fk_id_sale, -SUM(amount_gbp) a')->pluck('a', 'fk_id_sale');      // refunds after Confirm are adjustment lines
        $used = $lotView ? self::lotsOf($sales->pluck('id')->all()) : [];
        foreach ($sales as $s) {
            $rf = (float) ($adj[$s->id] ?? 0) ?: (float) $s->refund_loss_amount; if ($rf > 0) $refunds += $rf;
            // the parts of the line: one per lot it used (lot views), else the whole line
            $parts = [];
            if ($lotView && ! empty($used[$s->id])) { $q = array_sum(array_column($used[$s->id], 1)) ?: 1; foreach ($used[$s->id] as [$lot, $qty, $c, $own]) $parts[] = [$lotKey($lot, $own), $qty / $q, $c, (float) $s->qty * $qty / $q]; }
            else $parts[] = [$lotView ? $lotKey(null, null) : $keyOf($s->fk_id_branch, $s->fk_product_id, $s->sku, $s->name, $s->d_order), 1.0, (float) ($cost[$s->id] ?? 0), (int) $s->qty];
            foreach ($parts as [[$k, $n], $f, $c, $u]) {
                $rows[$k] ??= $blank($k, $n);
                $rows[$k]['units'] += $u; $rows[$k]['sales'] += (float) $s->total_amount_gbp * $f; $rows[$k]['cost'] += $c;
                if ($rf > 0) $rows[$k]['mp_costs'] += $rf * $f;
                $alloc[$s->fk_id_branch . '|' . $s->order_no][$k] = ($alloc[$s->fk_id_branch . '|' . $s->order_no][$k] ?? 0) + max(0.0001, (float) $s->total_amount_gbp * $f);
            }
            $orderKey[$s->fk_id_branch . '|' . $s->order_no] ??= [$s->fk_product_id, $s->sku, $s->name, $s->d_order];
        }
        // 2. marketplace costs from booked statements (everything in the money file except net sales; a cost is a positive number here)
        $lines = DB::table('fin_settlement_lines as l')->join('mo_settlements as m', fn ($j) => $j->on('m.fk_id_branch', '=', 'l.branch_id')->on('m.statement_id', '=', 'l.settlement_id'))->where('l.company_id', $ent)->whereIn('m.status', ['POSTED', 'PAID'])
            ->where('l.amount_type', '<>', 'NET_SALES')->whereBetween('m.statement_date', [$from, $to])->groupBy('l.branch_id', 'l.order_id', 'm.statement_date')->selectRaw('l.branch_id, l.order_id, m.statement_date d, SUM(l.amount) v')->get();
        $mpTotal = 0.0;
        foreach ($lines as $l) {
            if ($shop && (int) $l->branch_id !== $shop) continue;
            $o = $orderKey[$l->branch_id . '|' . $l->order_id] ?? null; $mpTotal += -1 * (float) $l->v;
            if (in_array($by, ['product', 'lot', 'batch', 'investor'], true) && ($a = $alloc[$l->branch_id . '|' . $l->order_id] ?? null)) {      // the order's costs follow its sales over the rows
                $sum = array_sum($a); foreach ($a as $k => $w) { $rows[$k]['mp_costs'] += -1 * (float) $l->v * $w / $sum; $rows[$k]['mp_in'] = true; } continue;
            }
            [$k, $n] = $lotView ? ['LX', 'Marketplace costs not matched to a sale line'] : ($by === 'product' ? $keyOf($l->branch_id, $o[0] ?? null, $o[1] ?? null, $o[2] ?? null, $l->d) : $keyOf($l->branch_id, null, null, null, $l->d));
            $rows[$k] ??= $blank($k, $n); $rows[$k]['mp_costs'] += -1 * (float) $l->v; $rows[$k]['mp_in'] = true;
        }
        // 3. other costs (expenses); those of one shop go to that shop, the rest is overhead
        $exp = DB::table('fin_expenses')->where('fk_id_ent', $ent)->where('f_active', 1)->whereBetween('d_expense', [$from, $to])->when($shop, fn ($q) => $q->where('fk_id_branch', $shop))->get(['fk_id_branch', 'd_expense', 'amount_gbp']);
        foreach ($exp as $e) {
            [$k, $n] = $by === 'month' ? $keyOf(null, null, null, null, $e->d_expense) : ($by === 'shop' && $e->fk_id_branch ? $keyOf($e->fk_id_branch, null, null, null, null) : ['ZZ', 'Overheads (not for one ' . (['shop' => 'shop', 'product' => 'SKU', 'lot' => 'lot', 'batch' => 'batch', 'investor' => 'investor'][$by] ?? 'row') . ')']);
            $rows[$k] ??= $blank($k, $n); $rows[$k]['expenses'] += (float) $e->amount_gbp;
        }
        ksort($rows); $tot = $blank('TOTAL', 'Total');
        foreach ($rows as &$r) {
            foreach (['sales', 'cost', 'mp_costs', 'expenses'] as $c) { $r[$c] = round($r[$c], 2); $tot[$c] += $r[$c]; } $tot['units'] += $r['units']; $r['units'] = (int) round($r['units']);
            $r['gross'] = round($r['sales'] - $r['cost'], 2); $r['profit'] = round($r['gross'] - $r['mp_costs'] - $r['expenses'], 2); $r['margin_pct'] = $r['sales'] > 0 ? round($r['profit'] / $r['sales'] * 100, 1) : null;
            $r['estimate'] = $r['sales'] > 0 && ! $r['mp_in'];   // sales but no marketplace costs loaded for this row
        }
        unset($r);
        foreach (['sales', 'cost', 'mp_costs', 'expenses'] as $c) $tot[$c] = round($tot[$c], 2); $tot['units'] = (int) round($tot['units']);
        $tot['gross'] = round($tot['sales'] - $tot['cost'], 2); $tot['profit'] = round($tot['gross'] - $tot['mp_costs'] - $tot['expenses'], 2); $tot['margin_pct'] = $tot['sales'] > 0 ? round($tot['profit'] / $tot['sales'] * 100, 1) : null;
        $pl = Ledger::profitAndLoss($ent, $from, $to);
        // sales lines of the period that are not in the figures yet
        $open = \App\Services\Perm::mine(DB::table('t_sales'), 'fk_id_branch')->where('fk_id_ent', $ent)->where('sale_source', 'active')->where('status', 'Pending')->whereBetween('d_order', [$from, $to])->selectRaw('COUNT(*) n, COALESCE(SUM(total_amount_gbp),0) v')->first();
        return ['by' => $by, 'from' => $from, 'to' => $to, 'shop' => $shop, 'head' => self::HEADS[$by], 'rows' => array_values($rows), 'total' => $tot, 'base' => Fx::base($ent), 'views' => self::VIEWS, 'shops' => Perm::mine(DB::table('t_branches'), 'id')->where('fk_id_ent', $ent)->where('f_active', 1)->orderBy('shop_name')->get(['id', 'shop_name as name']),
            'books' => ['profit' => $pl['profit'], 'income' => $pl['income'], 'expense' => $pl['expense'], 'difference' => round($tot['profit'] - $pl['profit'], 2)],
            'not_confirmed' => ['lines' => (int) $open->n, 'value' => round((float) $open->v, 2)], 'estimate_rows' => count(array_filter($rows, fn ($r) => $r['estimate']))];
    }

    /**
     * Partner / VA share: the same grand total, cut by who it goes to – each partner's share and fee (from the locked months of the period),
     * the VA shares of the approved VA statements, and the company's rest.
     */
    private static function share(int $ent, string $from, string $to, ?int $shop): array
    {
        $base = self::view($ent, 'shop', $from, $to, $shop); $T = $base['total'];
        $runs = DB::table('aud_runs')->where('fk_id_ent', $ent)->where('status', 'LOCKED')->whereBetween('period_month', [substr($from, 0, 7), substr($to, 0, 7)])->when($shop, fn ($q) => $q->where('fk_id_branch', $shop))->pluck('id');
        $rows = []; $given = 0.0;
        foreach (DB::table('fin_investor_ledger')->where('company_id', $ent)->whereIn('period_id', $runs->all() ?: [0])->whereIn('type', ['PROFIT', 'MANAGEMENT_FEE'])->groupBy('investor_id')->selectRaw('investor_id, SUM(amount) v')->get() as $p) {
            $rows[] = ['key' => 'P' . $p->investor_id, 'name' => 'Partner: ' . PartnerService::name((int) $p->investor_id), 'profit' => round((float) $p->v, 2)]; $given += (float) $p->v;
        }
        foreach (DB::table('va_statements as v')->leftJoin('t_account as a', 'a.id', '=', 'v.fk_id_va')->where('v.fk_id_ent', $ent)->whereIn('v.status', ['APPROVED', 'SENT'])->whereBetween('v.period_month', [substr($from, 0, 7), substr($to, 0, 7)])->when($shop, fn ($q) => $q->where('v.fk_id_branch', $shop))
            ->groupBy('v.fk_id_va', 'a.first_name', 'a.sur_name')->selectRaw("v.fk_id_va, TRIM(CONCAT_WS(' ', a.first_name, a.sur_name)) n, SUM(v.va_share_amount) s")->get() as $v) {
            $rows[] = ['key' => 'V' . $v->fk_id_va, 'name' => 'VA: ' . ($v->n ?: '#' . $v->fk_id_va), 'profit' => round((float) $v->s, 2)]; $given += (float) $v->s;
        }
        $rows[] = ['key' => 'CO', 'name' => 'Company (the rest)', 'profit' => round($T['profit'] - $given, 2)];
        foreach ($rows as &$r) { $r += ['units' => 0, 'sales' => 0.0, 'cost' => 0.0, 'mp_costs' => 0.0, 'expenses' => 0.0, 'gross' => 0.0, 'estimate' => false, 'mp_in' => true]; $r['margin_pct'] = $T['sales'] > 0 ? round($r['profit'] / $T['sales'] * 100, 1) : null; }
        unset($r);
        return ['by' => 'share', 'head' => self::HEADS['share'], 'rows' => $rows, 'note' => 'Partner shares come from locked months only; the company row is the rest, so the total equals the other views.'] + $base;
    }
}
