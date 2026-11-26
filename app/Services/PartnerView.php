<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Owner 26-11 23:19 – every partner's whole account in one place, as in the old tool, and connected to everything:
 *   share     – his products (t_product_investors: product ↔ investor; share = 1 ÷ investors on the product, the old rule) and his agreements (fin_investor_agreements %)
 *   inventory – his share of the units of his products: on hand, FBA, on the way, inbound on POs, value at raw price and at landed cost (InventoryHub, the ledger)
 *   P&L       – the settled months (t_inv_period_investors: revenue, cost, profit before split, his share) + sales of the last 30 days
 *   money     – invoices / statements both ways and their payments (t_invoices, t_invoice_payments), capital in, profit, withdrawals (fin_investor_ledger)
 * A partner login sees only his own account; the Owner / roles with partners.SEE see every partner.
 */
class PartnerView
{
    /** The partner account of the person, or null when he may see every partner. */
    private static function only(object $u): ?int
    {
        if (Perm::has($u, 'partners.SEE')) return null;
        $acc = PartnerService::partnerOfUser((int) $u->id) ?: (int) DB::table('t_inv_parties')->where('fk_id_account', DB::table('t_users')->where('id', $u->id)->value('fk_id_account'))->value('fk_id_account');
        if (! $acc) throw new \InvalidArgumentException('Your login is not linked to a partner account – ask the Owner.');
        return (int) $acc;
    }

    /** Every account that is a partner / investor in any part of the tool. */
    public static function accounts(int $ent): array
    {
        return DB::table('t_product_investors')->pluck('fk_id_account')
            ->merge(DB::table('t_inv_period_investors')->pluck('fk_id_investor_account'))
            ->merge(DB::table('fin_investor_agreements')->where('company_id', $ent)->pluck('investor_id'))
            ->map(fn ($x) => (int) $x)->filter()->unique()->values()->all();
    }

    public static function list(int $ent, object $u): array
    {
        $only = self::only($u); $rows = [];
        foreach (self::accounts($ent) as $acc) { if ($only && $acc !== $only) continue; $rows[] = self::summary($ent, $acc); }
        usort($rows, fn ($a, $b) => $b['stock_value'] <=> $a['stock_value']);
        return ['rows' => $rows, 'base' => Fx::base($ent), 'own' => $only !== null];
    }

    public static function summary(int $ent, int $acc): array
    {
        $mine = InvestorPortal::products($ent, $acc);
        $inv = self::inventory($ent, $mine);
        $per = DB::table('t_inv_period_investors as x')->join('t_inv_periods as p', 'p.id', '=', 'x.fk_id_period')->where('p.fk_id_ent', $ent)->where('x.fk_id_investor_account', $acc)->where('p.status', 'CONFIRMED')
            ->selectRaw('COALESCE(SUM(x.revenue),0) rev, COALESCE(SUM(x.profit_before_split),0) pbs, COALESCE(SUM(x.share_amount),0) sh, COALESCE(SUM(x.units),0) un, COALESCE(SUM(x.capital),0) cap')->first();
        $doc = DB::table('t_invoices')->where('fk_id_ent', $ent)->where('fk_id_investor_account', $acc)->whereNotIn('status', ['CANCELLED'])->selectRaw('COALESCE(SUM(total),0) t, COALESCE(SUM(amount_paid),0) p')->first();
        $led = PartnerService::account($ent, $acc);
        $ag = DB::table('fin_investor_agreements')->where('company_id', $ent)->where('investor_id', $acc)->where('status', 'ACTIVE')->get(['scope', 'profit_share_pct', 'capital_amount']);
        $from = now()->subDays(30)->toDateString(); $sold = 0.0; $soldVal = 0.0;
        if ($mine) foreach (DB::table('t_sales')->whereIn('fk_product_id', array_keys($mine))->where('d_order', '>=', $from)->whereIn('status', ['Confirmed', 'Pending'])->groupBy('fk_product_id')->selectRaw('fk_product_id p, SUM(qty) q, SUM(COALESCE(total_amount_gbp,total_amount)) v')->get() as $s) { $sold += (float) $s->q * $mine[(int) $s->p]; $soldVal += (float) $s->v * $mine[(int) $s->p]; }
        $nm = PartnerService::name($acc); if (str_starts_with($nm, 'Partner ')) $nm = (string) (DB::table('t_inv_parties')->where('fk_id_account', $acc)->value('display_name') ?: $nm);      // the investor's name is on his party when the person record is missing
        return ['account' => $acc, 'name' => $nm, 'products' => count($mine), 'units' => $inv['units'], 'fba' => $inv['fba'], 'on_the_way' => $inv['on_the_way'], 'inbound' => $inv['inbound'],
            'stock_value' => $inv['value_raw'], 'stock_cost' => $inv['value_cost'], 'sold30' => round($sold, 1), 'sales30' => round($soldVal, 2),
            'revenue' => round((float) $per->rev, 2), 'profit' => round((float) $per->pbs, 2), 'share' => round((float) $per->sh, 2), 'units_sold' => (int) $per->un, 'capital_recovered' => round((float) $per->cap, 2),
            'billed' => round((float) $doc->t, 2), 'paid' => round((float) $doc->p, 2), 'owed' => round((float) $doc->t - (float) $doc->p, 2),
            'capital' => $led['capital'] ?? 0, 'withdrawn' => $led['withdrawn'] ?? 0, 'balance' => $led['balance'] ?? null,
            'agreements' => $ag->map(fn ($a) => ['scope' => $a->scope, 'pct' => (float) $a->profit_share_pct, 'capital' => (float) $a->capital_amount])->all()];
    }

    /** His share of the stock of his products, from the same figures as the Inventory screen. */
    private static function inventory(int $ent, array $mine): array
    {
        $o = ['units' => 0.0, 'fba' => 0.0, 'on_the_way' => 0.0, 'inbound' => 0.0, 'value_raw' => 0.0, 'value_cost' => 0.0];
        if (! $mine) return $o;
        foreach (array_chunk(array_keys($mine), 300) as $ids) {
            $rows = DB::table('t_product_vendors')->whereIn('id', $ids)->get(['id', 'SKU_VENDOR as sku', 'product_name_vendor as name', 'product_img', 'f_parent', 'f_id_parent', 'e_product_type as mode', 'stock', 'min_level', 'sale_price', 'landing_price', 'raw_price', 'icc_status', 'icc_stock_out'])->all();
            foreach (InventoryHub::fill($rows, $ent, null) as $x) { $sh = $mine[$x['id']] ?? 0; $raw = (float) (DB::table('t_product_vendors')->where('id', $x['id'])->value('raw_price') ?? 0);
                $o['units'] += ($x['stock']['warehouse'] + $x['stock']['fba']) * $sh; $o['fba'] += $x['stock']['fba'] * $sh; $o['on_the_way'] += ($x['stock']['transit'] + $x['stock']['fba_inbound']) * $sh; $o['inbound'] += $x['stock']['inbound'] * $sh;
                $o['value_raw'] += ($x['stock']['warehouse'] + $x['stock']['fba'] + $x['stock']['transit']) * $sh * $raw; $o['value_cost'] += $x['value'] * $sh; }
        }
        return array_map(fn ($v) => round($v, 2), $o);
    }

    public static function one(int $ent, object $u, int $acc): array
    {
        $only = self::only($u); if ($only && $only !== $acc) throw new \InvalidArgumentException('You can see only your own account.');
        $sum = self::summary($ent, $acc); $mine = InvestorPortal::products($ent, $acc);
        $products = []; foreach (array_chunk(array_keys($mine), 200) as $ids) foreach (InventoryHub::fill(DB::table('t_product_vendors')->whereIn('id', $ids)->get(['id', 'SKU_VENDOR as sku', 'product_name_vendor as name', 'product_img', 'f_parent', 'f_id_parent', 'e_product_type as mode', 'stock', 'min_level', 'sale_price', 'landing_price', 'raw_price', 'icc_status', 'icc_stock_out'])->all(), $ent, null) as $x)
            $products[] = $x + ['share' => round($mine[$x['id']] ?? 0, 4)];
        usort($products, fn ($a, $b) => ($b['stock']['total'] <=> $a['stock']['total']));
        $periods = DB::table('t_inv_period_investors as x')->join('t_inv_periods as p', 'p.id', '=', 'x.fk_id_period')->leftJoin('t_branches as b', 'b.id', '=', 'p.fk_id_branch')->where('p.fk_id_ent', $ent)->where('x.fk_id_investor_account', $acc)
            ->orderByDesc('p.d_from')->get(['p.id', 'p.d_from', 'p.d_to', 'p.status', 'b.shop_name', 'x.units', 'x.revenue', 'x.actual_cost', 'x.direct_costs', 'x.allocated_costs', 'x.profit_before_split', 'x.weight_pct', 'x.share_amount'])->all();
        $docs = DB::table('t_invoices')->where('fk_id_ent', $ent)->where('fk_id_investor_account', $acc)->orderByDesc('d_invoice')->get(['id', 'invoice_no', 'invoice_type', 'd_invoice', 'd_due', 'total', 'amount_paid', 'status'])->all();
        $pays = DB::table('t_invoice_payments as y')->join('t_invoices as i', 'i.id', '=', 'y.fk_id_invoice')->where('i.fk_id_investor_account', $acc)->where('y.f_deleted', 0)->orderByDesc('y.d_payment')->get(['y.d_payment', 'y.amount', 'y.method', 'y.reference', 'i.invoice_no'])->all();
        $ledger = DB::table('fin_investor_ledger')->where('company_id', $ent)->where('investor_id', $acc)->orderByDesc('entry_date')->limit(100)->get()->all();
        return ['summary' => $sum, 'products' => array_slice($products, 0, 300), 'periods' => $periods, 'documents' => $docs, 'payments' => $pays, 'ledger' => $ledger, 'base' => Fx::base($ent), 'margin' => InvoiceReports::canMargin($ent, $u)];
    }
}
