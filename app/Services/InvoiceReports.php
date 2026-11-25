<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Invoicing reports (menu group Invoicing, R-G_CORE-86):
 * - R-INV-12 Outstanding "Who owes whom": one row per recipient × issuer over UNPAID / PART_PAID documents – invoices, total, paid, balance, overdue;
 *   filters marketplace, shop, overdue only; totals; plus the per-document list (number, type, issuer, recipient, shop, period, total, paid, balance, due, days overdue).
 * - R-INV-3 Hidden margin: per investor × shop × settled month – units, raw cost (paid to investor), landing cost, hidden margin = raw − landing, profit share,
 *   total earned = margin + share; flag "no landing price"; default range first day 11 months back … end of this month; filters marketplace, shop, investor;
 *   CSV hidden_margin_<from>_<to>.csv (9 columns, UTF-8 BOM). Only roles in Settings › Choices HIDDEN_MARGIN_ROLES (default Owner), else "This report is restricted."
 */
class InvoiceReports
{
    public const MARGIN_CSV = ['Investor', 'Shop', 'Month', 'Units', 'Raw cost (paid to investor)', 'Landing cost', 'Hidden margin', 'Profit share', 'Total earned'];

    public static function outstanding(int $ent, array $f = []): array
    {
        $today = date('Y-m-d');
        $docs = DB::table('t_invoices as i')->leftJoin('t_inv_periods as p', 'p.id', '=', 'i.fk_id_period')->leftJoin('t_branches as b', 'b.id', '=', 'p.fk_id_branch')
            ->leftJoin('t_inv_parties as fi', 'fi.id', '=', 'i.fk_issuer_party')->leftJoin('t_inv_parties as fr', 'fr.id', '=', 'i.fk_recipient_party')
            ->where('i.fk_id_ent', $ent)->whereIn('i.status', ['UNPAID', 'PART_PAID'])
            ->when(! empty($f['marketplace']), fn ($w) => $w->where('p.fk_id_marketplace', (int) $f['marketplace']))->when(! empty($f['shop']), fn ($w) => $w->where('p.fk_id_branch', (int) $f['shop']))
            ->when(! empty($f['overdue']), fn ($w) => $w->where('i.d_due', '<', $today))
            ->orderBy('i.d_due')->orderBy('i.id')
            ->get(['i.id', 'i.invoice_no', 'i.invoice_type', 'i.fk_issuer_party', 'i.fk_recipient_party', 'fi.display_name as issuer', 'fr.display_name as recipient', 'b.shop_name', 'p.d_from', 'i.total', 'i.amount_paid', 'i.d_due'])
            ->map(function ($d) use ($today) {
                $bal = round((float) $d->total - (float) $d->amount_paid, 2); $late = $d->d_due && $d->d_due < $today ? (int) ((strtotime($today) - strtotime($d->d_due)) / 86400) : 0;
                return ['id' => (int) $d->id, 'no' => $d->invoice_no, 'type' => InvestorDocs::TYPES[$d->invoice_type][0] ?? $d->invoice_type, 'issuer_id' => (int) $d->fk_issuer_party, 'recipient_id' => (int) $d->fk_recipient_party,
                    'issuer' => $d->issuer ?: '—', 'recipient' => $d->recipient ?: '—', 'shop' => $d->shop_name, 'period' => $d->d_from ? date('M Y', strtotime($d->d_from)) : null,
                    'total' => round((float) $d->total, 2), 'paid' => round((float) $d->amount_paid, 2), 'balance' => $bal, 'due' => $d->d_due, 'days_overdue' => $late, 'overdue' => $late > 0 ? $bal : 0.0];
            });
        $rows = $docs->groupBy(fn ($d) => $d['recipient_id'] . '-' . $d['issuer_id'])->map(fn ($g) => ['recipient' => $g[0]['recipient'], 'issuer' => $g[0]['issuer'], 'invoices' => $g->count(),
            'total' => round($g->sum('total'), 2), 'paid' => round($g->sum('paid'), 2), 'balance' => round($g->sum('balance'), 2), 'overdue' => round($g->sum('overdue'), 2)])->sortByDesc('balance')->values()->all();
        return ['rows' => $rows, 'docs' => $docs->all(), 'totals' => ['invoices' => $docs->count(), 'total' => round($docs->sum('total'), 2), 'paid' => round($docs->sum('paid'), 2), 'balance' => round($docs->sum('balance'), 2), 'overdue' => round($docs->sum('overdue'), 2)],
            'shops' => DB::table('t_branches')->where('fk_id_ent', $ent)->where('f_active', 1)->orderBy('shop_name')->get(['id', 'shop_name as name'])->all()];
    }

    public static function canMargin(int $ent, ?object $u): bool { return $u && Choices::has('HIDDEN_MARGIN_ROLES', $ent, (string) ($u->role_code ?? Context::role())); }

    public static function margin(int $ent, array $f, ?object $u): array
    {
        if (! self::canMargin($ent, $u)) abort(403, 'This report is restricted.');
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($f['from'] ?? '')) ? $f['from'] : date('Y-m-01', strtotime('first day of -11 months'));
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($f['to'] ?? '')) ? $f['to'] : date('Y-m-t');
        $rows = DB::table('t_inv_period_investors as x')->join('t_inv_periods as p', 'p.id', '=', 'x.fk_id_period')->leftJoin('t_branches as b', 'b.id', '=', 'p.fk_id_branch')->leftJoin('t_account as a', 'a.id', '=', 'x.fk_id_investor_account')
            ->where('p.fk_id_ent', $ent)->where('p.status', 'CONFIRMED')->where('p.d_from', '>=', $from)->where('p.d_from', '<=', $to)
            ->when(! empty($f['marketplace']), fn ($w) => $w->where('p.fk_id_marketplace', (int) $f['marketplace']))->when(! empty($f['shop']), fn ($w) => $w->where('p.fk_id_branch', (int) $f['shop']))
            ->when(! empty($f['investor']), fn ($w) => $w->where('x.fk_id_investor_account', (int) $f['investor']))
            ->orderBy('p.d_from')->orderBy('b.shop_name')->orderBy('x.id')
            ->get(['x.fk_id_investor_account', 'a.first_name', 'a.sur_name', 'a.account_code', 'b.shop_name', 'p.d_from', 'x.units', 'x.capital', 'x.actual_cost', 'x.share_amount'])
            ->map(function ($r) {
                $raw = round((float) $r->capital, 2); $land = round((float) $r->actual_cost, 2); $m = round($raw - $land, 2); $sh = round((float) $r->share_amount, 2);
                $name = trim(($r->first_name ?? '') . ' ' . ($r->sur_name ?? '')) ?: ($r->account_code ?: 'No investor');
                return ['investor_id' => (int) $r->fk_id_investor_account, 'investor' => $name, 'shop' => $r->shop_name, 'month' => date('M Y', strtotime($r->d_from)), 'units' => (int) $r->units,
                    'raw' => $raw, 'landing' => $land, 'margin' => $m, 'share' => $sh, 'earned' => round($m + $sh, 2), 'no_landing' => $land == 0.0 && $raw > 0];
            });
        $sum = fn ($k) => round($rows->sum($k), 2);
        return ['from' => $from, 'to' => $to, 'rows' => $rows->all(), 'totals' => ['units' => (int) $rows->sum('units'), 'raw' => $sum('raw'), 'landing' => $sum('landing'), 'margin' => $sum('margin'), 'share' => $sum('share'), 'earned' => $sum('earned')],
            'file' => "hidden_margin_{$from}_{$to}.csv"];
    }

    public static function marginCsv(array $r): string
    {
        $o = fopen('php://temp', 'w+'); fwrite($o, "\xEF\xBB\xBF"); fputcsv($o, self::MARGIN_CSV);
        foreach ($r['rows'] as $x) fputcsv($o, [$x['investor'], $x['shop'], $x['month'], $x['units'], number_format($x['raw'], 2, '.', ''), number_format($x['landing'], 2, '.', ''), number_format($x['margin'], 2, '.', ''), number_format($x['share'], 2, '.', ''), number_format($x['earned'], 2, '.', '')]);
        rewind($o); return stream_get_contents($o);
    }
}
