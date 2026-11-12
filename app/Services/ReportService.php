<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Reports. Each report is a table for a date range with a totals row, shown on screen and exported as a spreadsheet file.
 * They read the same sources as the working screens (confirmed sale lines, lots, ledger), so their totals agree with those screens.
 * Column types: text, int, money, pct, date. A column marked 'money' is left out for roles that must not see money.
 */
class ReportService
{
    private const FIN = ['OWNER', 'FINANCE', 'ACCOUNTANT'];
    /** key → [group, title, what it answers, roles, uses dates] */
    public const LIST = [
        'sales_shop' => ['Sales', 'Sales by shop', 'Units and value of orders per shop, confirmed and not yet confirmed.', ['OWNER', 'MANAGER', 'TL', 'FINANCE', 'ACCOUNTANT'], true],
        'sales_product' => ['Sales', 'Sales by product', 'What sold, how many, for how much, at what cost.', ['OWNER', 'MANAGER', 'TL', 'FINANCE', 'ACCOUNTANT'], true],
        'sales_day' => ['Sales', 'Sales by day', 'Orders, units and value per order date.', ['OWNER', 'MANAGER', 'TL', 'FINANCE', 'ACCOUNTANT'], true],
        'stock' => ['Stock', 'Stock on hand and days of cover', 'Units and value per product today, sold in the last 30 days, and how long the stock lasts at that pace.', ['OWNER', 'MANAGER', 'WAREHOUSE', 'SOURCING', 'FINANCE', 'ACCOUNTANT'], false],
        'stock_location' => ['Stock', 'Stock by location', 'Units and value per location today.', ['OWNER', 'MANAGER', 'WAREHOUSE', 'FINANCE', 'ACCOUNTANT'], false],
        'purchases' => ['Buying', 'Purchase orders', 'Each purchase order of the period: ordered, received, paid and still open.', ['OWNER', 'MANAGER', 'SOURCING', 'FINANCE', 'ACCOUNTANT'], true],
        'suppliers' => ['Buying', 'Supplier balances', 'Per supplier today: paid in advance for goods not received, and owed for goods received.', ['OWNER', 'MANAGER', 'FINANCE', 'ACCOUNTANT'], false],
        'cash' => ['Money', 'Cash in and out by kind', 'Every movement on the bank accounts in the period, grouped by what it was for, per month.', self::FIN, true],
        'partners' => ['Money', 'Partner summary', 'Per partner in the period: capital in, profit share, fees, withdrawn; and the balance today.', self::FIN, true],
        'vat' => ['Money', 'VAT to claim back', 'VAT inside marketplace fees and expenses per month (for a VAT-registered company).', self::FIN, true],
        'research' => ['Research', 'Research pipeline and margin: estimate vs real', 'Every idea of the period: its status, the estimated margin and – once it sells – the real margin of its product.', ['OWNER', 'MANAGER', 'SOURCING', 'PARTNER'], true],
        'landed' => ['Buying', 'Landed cost per lot', 'Each lot received in the period: product cost, its share of freight, duty and other costs, cost per unit, provisional or final.', self::FIN + [3 => 'MANAGER', 4 => 'SOURCING'], true],
        'in_transit' => ['Buying', 'Goods on the way', 'Shipments not arrived yet: PO, supplier, units, ETA and how late.', ['OWNER', 'MANAGER', 'SOURCING', 'WAREHOUSE', 'FINANCE'], false],
        'supplier_perf' => ['Buying', 'Supplier performance', 'Per supplier in the period: POs, units ordered and received, missing and damaged %, days late on average.', ['OWNER', 'MANAGER', 'SOURCING'], true],
        'po_payments' => ['Buying', 'PO payment position', 'Every payment term still open or paid in the period: planned, paid, left, due date.', self::FIN + [3 => 'MANAGER', 4 => 'SOURCING'], true],
        'valuation' => ['Stock', 'Stock valuation at a date', 'Units and value per product as at the end date: lots received by then less units that left by then.', self::FIN + [3 => 'MANAGER'], true],
        'slow_dead' => ['Stock', 'Slow and dead stock', 'Products with stock and no sale for 90 days (slow) or 180 days (dead), with the value tied up.', ['OWNER', 'MANAGER', 'FINANCE', 'SOURCING'], false],
        'sales_months' => ['Sales', 'Sales per shop and month', 'Confirmed and not confirmed sales of each shop, month by month, in the period.', ['OWNER', 'MANAGER', 'FINANCE', 'ACCOUNTANT', 'PARTNER'], true],
        'price_log' => ['Sales', 'Price changes', 'Every selling price change of the period: product, marketplace, old and new price, by whom.', ['OWNER', 'MANAGER', 'TL'], true],
        'expenses' => ['Money', 'Expenses by category', 'Booked expenses of the period per category and shop (cancelled ones left out).', self::FIN + [3 => 'MANAGER'], true],
        'ledger' => ['Money', 'Ledger lines (export for the accountant)', 'Every journal line of the period: date, number, account, debit, credit, memo.', self::FIN, true],
        'tasks_week' => ['Tasks', 'Tasks per person', 'Per person in the period: tasks closed, late now, stuck now, average score.', ['OWNER', 'MANAGER', 'TL'], true],
        'users' => ['People', 'Logins and roles', 'Every login: role, company, active, last sign-in, open sign-ins.', ['OWNER', 'MANAGER'], false],
        'menu' => ['System', 'Menu and who sees it', 'Every screen of the menu with its number and the roles that see it.', ['OWNER', 'MANAGER'], false],
        'close' => ['Money', 'Month close status', 'Every shop × month of the period: open, checked or locked, with the locked profit.', ['OWNER', 'MANAGER', 'FINANCE', 'ACCOUNTANT'], true],
    ];
    private const NO_MONEY = ['WAREHOUSE'];

    public static function allowed(string $key, ?string $role): bool { return isset(self::LIST[$key]) && in_array($role, self::LIST[$key][3], true); }

    public static function catalogue(?string $role): array
    {
        $out = [];
        foreach (self::LIST as $k => [$group, $title, $what, $roles, $dates]) if (in_array($role, $roles, true)) $out[] = ['key' => $k, 'group' => $group, 'title' => $title, 'what' => $what, 'dates' => $dates];
        return $out;
    }

    public static function run(int $ent, string $key, string $from, string $to, ?string $role = null): array
    {
        if (! isset(self::LIST[$key])) throw new \InvalidArgumentException('Unknown report.');
        if ($to < $from) throw new \InvalidArgumentException('The end date is before the start date.');
        [$cols, $rows, $note] = self::{'r_' . $key}($ent, $from, $to);
        if (in_array($role, self::NO_MONEY, true)) $cols = array_values(array_filter($cols, fn ($c) => $c[2] !== 'money'));
        $tot = [];
        foreach ($cols as [$k, , $type]) if (in_array($type, ['int', 'money'], true)) $tot[$k] = round(array_sum(array_map(fn ($r) => (float) ($r[$k] ?? 0), $rows)), $type === 'int' ? 0 : 2);
        $keep = array_column($cols, 0); $rows = array_map(fn ($r) => array_intersect_key($r, array_flip($keep)), $rows);
        return ['key' => $key, 'title' => self::LIST[$key][1], 'what' => self::LIST[$key][2], 'dates' => self::LIST[$key][4], 'from' => $from, 'to' => $to, 'base' => Fx::base($ent),
            'columns' => array_map(fn ($c) => ['key' => $c[0], 'label' => $c[1], 'type' => $c[2]], $cols), 'rows' => array_values($rows), 'totals' => $tot, 'note' => $note];
    }

    // ───────────── sales ─────────────

    private static function sales(int $ent, string $from, string $to)
    {
        return DB::table('t_sales as s')->where('s.fk_id_ent', $ent)->where('s.sale_source', 'active')->whereIn('s.status', ['Pending', 'Confirmed'])->whereBetween('s.d_order', [$from, $to]);
    }

    private const SALE_SUMS = "COUNT(DISTINCT CONCAT(s.fk_id_branch, '|', s.order_no)) orders, SUM(s.qty) units, SUM(IF(s.status = 'Confirmed', s.total_amount_gbp, 0)) confirmed, SUM(IF(s.status = 'Pending', s.total_amount_gbp, 0)) pending, SUM(s.total_amount_gbp) total";
    private const SALE_COLS = [['orders', 'Orders', 'int'], ['units', 'Units', 'int'], ['confirmed', 'Confirmed sales', 'money'], ['pending', 'Not confirmed yet', 'money'], ['total', 'All sales', 'money']];
    private const SALE_NOTE = 'By order date. Cancelled lines are left out. "Confirmed sales" is the figure the profit views and the books use.';

    private static function saleRow($r, array $first): array { return $first + ['orders' => (int) $r->orders, 'units' => (int) $r->units, 'confirmed' => round((float) $r->confirmed, 2), 'pending' => round((float) $r->pending, 2), 'total' => round((float) $r->total, 2)]; }

    private static function r_sales_shop(int $ent, string $from, string $to): array
    {
        $rows = self::sales($ent, $from, $to)->leftJoin('t_branches as b', 'b.id', '=', 's.fk_id_branch')->groupBy('s.fk_id_branch', 'b.shop_name')->orderBy('b.shop_name')->selectRaw('b.shop_name shop, ' . self::SALE_SUMS)->get();
        return [array_merge([['shop', 'Shop', 'text']], self::SALE_COLS), $rows->map(fn ($r) => self::saleRow($r, ['shop' => $r->shop ?: 'No shop']))->all(), self::SALE_NOTE];
    }

    private static function r_sales_day(int $ent, string $from, string $to): array
    {
        $rows = self::sales($ent, $from, $to)->groupBy('s.d_order')->orderBy('s.d_order')->selectRaw('s.d_order d, ' . self::SALE_SUMS)->get();
        return [array_merge([['day', 'Day', 'date']], self::SALE_COLS), $rows->map(fn ($r) => self::saleRow($r, ['day' => $r->d]))->all(), self::SALE_NOTE];
    }

    private static function r_sales_product(int $ent, string $from, string $to): array
    {
        $rows = self::sales($ent, $from, $to)->leftJoin('t_product_vendors as p', 'p.id', '=', 's.fk_product_id')->groupBy('s.fk_product_id', 'p.SKU_VENDOR', 'p.product_name_vendor')->orderByRaw('SUM(s.total_amount_gbp) DESC')
            ->selectRaw('s.fk_product_id pid, p.SKU_VENDOR sku, p.product_name_vendor name, ' . self::SALE_SUMS)->get();
        $cost = DB::table('fin_lot_consumption as c')->join('t_sales as s', 's.id', '=', 'c.fk_id_sale')->where('s.fk_id_ent', $ent)->where('s.sale_source', 'active')->where('s.status', 'Confirmed')->whereBetween('s.d_order', [$from, $to])
            ->groupBy('s.fk_product_id')->selectRaw('s.fk_product_id pid, SUM(c.qty * c.unit_cost_gbp) c')->pluck('c', 'pid');
        $cols = array_merge([['sku', 'SKU', 'text'], ['name', 'Product', 'text']], self::SALE_COLS, [['cost', 'Cost of goods (confirmed)', 'money'], ['gross', 'Confirmed sales − cost', 'money']]);
        return [$cols, $rows->map(function ($r) use ($cost) { $c = round((float) ($cost[$r->pid] ?? 0), 2); return self::saleRow($r, ['sku' => $r->sku ?: '—', 'name' => $r->name ?: 'Not linked to a product']) + ['cost' => $c, 'gross' => round((float) $r->confirmed - $c, 2)]; })->all(),
            self::SALE_NOTE . ' Cost is known only for confirmed lines (it is taken from the lots at Month confirm).'];
    }

    // ───────────── stock ─────────────

    private static function r_stock(int $ent, string $from, string $to): array
    {
        $since = date('Y-m-d', strtotime('-30 day'));
        $sold = DB::table('t_sales')->where('fk_id_ent', $ent)->where('sale_source', 'active')->whereIn('status', ['Pending', 'Confirmed'])->where('d_order', '>=', $since)->groupBy('fk_product_id')->selectRaw('fk_product_id pid, SUM(qty) q')->pluck('q', 'pid');
        $rows = DB::table('t_product_vendors as p')->leftJoin('fin_lots as l', fn ($j) => $j->on('l.fk_id_product', '=', 'p.id')->where('l.company_id', $ent))->leftJoin('fin_lot_balances as b', 'b.fk_id_lot', '=', 'l.id')->where('p.fk_id_ent', $ent)
            ->groupBy('p.id', 'p.SKU_VENDOR', 'p.product_name_vendor', 'p.min_level')->havingRaw('COALESCE(SUM(b.qty),0) <> 0 OR p.min_level > 0')->orderBy('p.SKU_VENDOR')
            ->selectRaw('p.id, p.SKU_VENDOR sku, p.product_name_vendor name, p.min_level, COALESCE(SUM(b.qty),0) units, COALESCE(SUM(b.qty * l.unit_cost_gbp),0) value')->get();
        $out = $rows->map(function ($r) use ($sold) { $s = (int) ($sold[$r->id] ?? 0); return ['sku' => $r->sku, 'name' => $r->name, 'units' => (int) $r->units, 'value' => round((float) $r->value, 2), 'sold_30' => $s,
            'cover' => $s > 0 ? (string) (int) round((int) $r->units / ($s / 30)) : ((int) $r->units > 0 ? 'no sales' : ''), 'min_level' => (string) (int) $r->min_level, 'state' => (int) $r->units <= (int) $r->min_level && (int) $r->min_level > 0 ? 'LOW' : '']; })->all();
        return [[['sku', 'SKU', 'text'], ['name', 'Product', 'text'], ['units', 'Units on hand', 'int'], ['value', 'Value at cost', 'money'], ['sold_30', 'Sold in the last 30 days', 'int'], ['cover', 'Days of cover', 'text'], ['min_level', 'Minimum', 'text'], ['state', 'State', 'text']], $out,
            'As it stands now (the dates do not apply). Value is the landed cost of the lots on the shelf and equals the stock account in the books. Days of cover = units ÷ (units sold in the last 30 days ÷ 30).'];
    }

    private static function r_stock_location(int $ent, string $from, string $to): array
    {
        $rows = DB::table('fin_lot_balances as b')->join('fin_lots as l', 'l.id', '=', 'b.fk_id_lot')->join('cat_inventory_locations as c', 'c.id', '=', 'b.fk_id_location')->where('l.company_id', $ent)->where('b.qty', '<>', 0)
            ->groupBy('c.id', 'c.location_name', 'c.kind')->orderBy('c.location_name')->selectRaw('c.location_name loc, c.kind, COUNT(DISTINCT l.fk_id_product) products, SUM(b.qty) units, SUM(b.qty * l.unit_cost_gbp) value')->get();
        return [[['location', 'Location', 'text'], ['kind', 'Kind', 'text'], ['products', 'Products', 'int'], ['units', 'Units', 'int'], ['value', 'Value at cost', 'money']],
            $rows->map(fn ($r) => ['location' => $r->loc, 'kind' => ucfirst(strtolower((string) $r->kind)), 'products' => (int) $r->products, 'units' => (int) $r->units, 'value' => round((float) $r->value, 2)])->all(), 'As it stands now (the dates do not apply).'];
    }

    // ───────────── buying ─────────────

    private static function r_purchases(int $ent, string $from, string $to): array
    {
        $rows = DB::table('t_src_po as p')->leftJoin('t_src_suppliers as s', 's.id', '=', 'p.fk_id_supplier')->leftJoin('cat_currency as c', 'c.id', '=', 'p.fk_id_currency')->where('p.fk_id_ent', $ent)->where('p.f_active', 1)->whereBetween('p.po_date', [$from, $to])->orderBy('p.po_date')->orderBy('p.id')
            ->get(['p.id', 'p.po_number', 'p.po_date', 'p.status', 'p.po_total', 'p.exchange_rate', 's.supplier_name', 'c.currency_code']);
        $ids = $rows->pluck('id');
        $qty = DB::table('t_src_po_lines')->whereIn('fk_id_po', $ids)->where('f_active', 1)->groupBy('fk_id_po')->selectRaw('fk_id_po, SUM(COALESCE(NULLIF(qty_confirmed, 0), qty_ordered)) q')->pluck('q', 'fk_id_po');
        $rec = DB::table('t_src_grn_lines as g')->join('t_src_grn as h', 'h.id', '=', 'g.fk_id_grn')->join('t_src_po_lines as l', 'l.id', '=', 'g.fk_id_po_line')->whereIn('l.fk_id_po', $ids)->where('h.posting_status', 'POSTED')->groupBy('l.fk_id_po')->selectRaw('l.fk_id_po, SUM(g.received_qty) q')->pluck('q', 'fk_id_po');
        $paid = DB::table('t_src_payments')->whereIn('fk_id_po', $ids)->where('f_active', 1)->groupBy('fk_id_po')->selectRaw('fk_id_po, SUM(payment_amount) a, SUM(base_amount) b')->get()->keyBy('fk_id_po');
        $out = $rows->map(function ($r) use ($qty, $rec, $paid) { $pa = (float) ($paid[$r->id]->a ?? 0); $rate = (float) ($r->exchange_rate ?: 1);
            return ['po' => $r->po_number, 'date' => $r->po_date, 'supplier' => $r->supplier_name, 'status' => ucfirst(strtolower((string) $r->status)), 'currency' => $r->currency_code, 'ordered' => (int) ($qty[$r->id] ?? 0), 'received' => (int) ($rec[$r->id] ?? 0),
                'total_cur' => number_format((float) $r->po_total, 2), 'paid_cur' => number_format($pa, 2), 'open_cur' => number_format(max(0, (float) $r->po_total - $pa), 2), 'total' => round((float) $r->po_total * $rate, 2), 'paid' => round((float) ($paid[$r->id]->b ?? 0), 2)]; })->all();
        return [[['po', 'PO', 'text'], ['date', 'Date', 'date'], ['supplier', 'Supplier', 'text'], ['status', 'Status', 'text'], ['ordered', 'Units ordered', 'int'], ['received', 'Units received', 'int'], ['currency', 'Currency', 'text'], ['total_cur', 'PO total (its currency)', 'text'],
            ['paid_cur', 'Paid (its currency)', 'text'], ['open_cur', 'Still to pay (its currency)', 'text'], ['total', 'PO total (base, at the PO rate)', 'money'], ['paid', 'Paid (base, at the day rates)', 'money']], $out,
            'By PO date. Amounts in the PO\'s own currency are text (they cannot be added up across currencies); the two base-currency columns have totals.'];
    }

    private static function r_suppliers(int $ent, string $from, string $to): array
    {
        $rows = DB::table('gl_lines as l')->join('gl_journals as j', 'j.id', '=', 'l.journal_id')->join('gl_accounts as a', 'a.id', '=', 'l.account_id')->where('j.fk_id_ent', $ent)->whereIn('a.code', ['1300', '2000'])->whereNotNull('l.ref_id')
            ->groupBy('l.ref_id', 'a.code')->selectRaw('l.ref_id sup, a.code, SUM(l.debit - l.credit) v')->get();
        $names = DB::table('t_src_suppliers')->where('fk_id_ent', $ent)->pluck('supplier_name', 'id'); $out = [];
        foreach ($rows as $r) { $out[$r->sup] ??= ['supplier' => $names[$r->sup] ?? ('Supplier ' . $r->sup), 'advance' => 0.0, 'payable' => 0.0]; if ($r->code === '1300') $out[$r->sup]['advance'] = round((float) $r->v, 2); else $out[$r->sup]['payable'] = round(-1 * (float) $r->v, 2); }
        $out = array_values(array_filter($out, fn ($r) => abs($r['advance']) > 0.004 || abs($r['payable']) > 0.004)); usort($out, fn ($a, $b) => strcmp($a['supplier'], $b['supplier']));
        return [[['supplier', 'Supplier', 'text'], ['advance', 'Paid in advance (goods not received yet)', 'money'], ['payable', 'Owed (goods received, not paid)', 'money']], $out, 'As it stands now, from the books (accounts 1300 and 2000).'];
    }

    // ───────────── money ─────────────

    private const KINDS = ['OPENING_BANK' => 'Opening balance', 'PAYMENT' => 'Supplier payments', 'PAYOUT' => 'Marketplace payouts', 'TRANSFER' => 'Transfers between own accounts', 'PARTNER_CAPITAL' => 'Partner capital in', 'WITHDRAWAL' => 'Partner withdrawals', 'BANK_LINE' => 'Bank lines booked by category'];

    private static function r_cash(int $ent, string $from, string $to): array
    {
        $rows = DB::table('gl_lines as l')->join('gl_journals as j', 'j.id', '=', 'l.journal_id')->join('gl_accounts as a', 'a.id', '=', 'l.account_id')->where('j.fk_id_ent', $ent)->where('a.code', '1000')->whereBetween('j.journal_date', [$from, $to])
            ->leftJoin('fin_expenses as e', fn ($x) => $x->on('e.id', '=', 'j.source_id')->where('j.source_type', 'EXPENSE'))->leftJoin('fin_bank_transactions as t', fn ($x) => $x->on('t.id', '=', 'j.source_id')->where('j.source_type', 'BANK_LINE'))
            ->groupBy(DB::raw("DATE_FORMAT(j.journal_date, '%Y-%m')"), 'j.source_type', 'e.category_code', 't.category_code')->selectRaw("DATE_FORMAT(j.journal_date, '%Y-%m') m, j.source_type st, COALESCE(e.category_code, t.category_code) cat, SUM(l.debit) i, SUM(l.credit) o")->get();
        $cats = DB::table('fin_categories')->pluck('name', 'code'); $out = [];
        foreach ($rows as $r) {
            $kind = $r->st === 'EXPENSE' ? 'Expense · ' . ($cats[$r->cat] ?? $r->cat ?? 'other') : ($r->st === 'BANK_LINE' ? ($cats[$r->cat] ?? 'Bank line') : (self::KINDS[$r->st] ?? ucfirst(strtolower(str_replace('_', ' ', (string) $r->st)))));
            $k = $r->m . '|' . $kind; $out[$k] ??= ['month' => $r->m, 'kind' => $kind, 'in' => 0.0, 'out' => 0.0, 'net' => 0.0];
            $out[$k]['in'] = round($out[$k]['in'] + (float) $r->i, 2); $out[$k]['out'] = round($out[$k]['out'] + (float) $r->o, 2); $out[$k]['net'] = round($out[$k]['in'] - $out[$k]['out'], 2);
        }
        ksort($out);
        return [[['month', 'Month', 'text'], ['kind', 'What for', 'text'], ['in', 'Money in', 'money'], ['out', 'Money out', 'money'], ['net', 'Net', 'money']], array_values($out),
            'From the books, by booking date, all bank accounts together in the base currency. The total "Net" is the change of the cash balance in the period. A reversed entry shows as in and out of the same kind. Transfers between own accounts net to zero.'];
    }

    private static function r_vat(int $ent, string $from, string $to): array
    {
        $rows = DB::table('gl_lines as l')->join('gl_journals as j', 'j.id', '=', 'l.journal_id')->join('gl_accounts as a', 'a.id', '=', 'l.account_id')->where('j.fk_id_ent', $ent)->where('a.code', '1400')->whereBetween('j.journal_date', [$from, $to])
            ->groupBy(DB::raw("DATE_FORMAT(j.journal_date, '%Y-%m')"), 'j.source_type')->selectRaw("DATE_FORMAT(j.journal_date, '%Y-%m') m, j.source_type st, SUM(l.debit - l.credit) v")->get(); $out = [];
        foreach ($rows as $r) { $out[$r->m] ??= ['month' => $r->m, 'fees' => 0.0, 'expenses' => 0.0, 'other' => 0.0, 'total' => 0.0]; $k = $r->st === 'SETTLEMENT' ? 'fees' : ($r->st === 'EXPENSE' ? 'expenses' : 'other'); $out[$r->m][$k] = round($out[$r->m][$k] + (float) $r->v, 2); $out[$r->m]['total'] = round($out[$r->m]['total'] + (float) $r->v, 2); }
        ksort($out);
        return [[['month', 'Month', 'text'], ['fees', 'VAT on marketplace fees', 'money'], ['expenses', 'VAT on expenses', 'money'], ['other', 'Other', 'money'], ['total', 'VAT to claim back', 'money']], array_values($out),
            (Choices::get('VAT_REGISTERED', $ent) === '1' ? '' : 'The company is set as NOT registered for VAT (Settings › Choices), so VAT stays inside the costs and this report is empty. ') . 'This is the VAT you paid (input VAT) as booked on account 1400. The VAT on your own sales (output VAT) is NOT worked out by the tool yet – this is not a VAT return.'];
    }

    // ───────────── research, buying, stock, sales, money, tasks, people, system (added in Round 2 part 5) ─────────────

    private static function r_research(int $ent, string $from, string $to): array
    {
        $rows = DB::table('src_research as r')->leftJoin('t_product_vendors as p', 'p.id', '=', 'r.fk_id_product')->where('r.fk_id_ent', $ent)->where('r.f_deleted', 0)->whereBetween(DB::raw('DATE(r.created_at)'), [$from, $to])->orderByDesc('r.id')
            ->get(['r.research_code', 'r.title', 'r.status', 'r.est_margin_pct', 'r.fk_id_product', 'p.SKU_VENDOR as sku']);
        $real = $rows->pluck('fk_id_product')->filter()->isEmpty() ? collect() : DB::table('t_sales as s')->leftJoin('fin_lot_consumption as c', 'c.fk_id_sale', '=', 's.id')->whereIn('s.fk_product_id', $rows->pluck('fk_id_product')->filter())->where('s.status', 'Confirmed')->where('s.sale_source', 'active')
            ->groupBy('s.fk_product_id')->selectRaw('s.fk_product_id p, SUM(s.total_amount_gbp) sales, SUM(COALESCE(c.qty * c.unit_cost_gbp, 0)) cost, SUM(s.qty) units')->get()->keyBy('p');
        return [[['code', 'Research', 'text'], ['title', 'Idea', 'text'], ['status', 'Status', 'text'], ['sku', 'SKU', 'text'], ['est', 'Estimated margin %', 'pct'], ['units', 'Units sold', 'int'], ['sales', 'Sales', 'money'], ['real', 'Real gross margin %', 'pct']],
            $rows->map(function ($r) use ($real) { $x = $real[$r->fk_id_product] ?? null; $sales = (float) ($x->sales ?? 0);
                return ['code' => $r->research_code, 'title' => $r->title, 'status' => $r->status, 'sku' => $r->sku ?? '', 'est' => $r->est_margin_pct !== null ? (float) $r->est_margin_pct : null, 'units' => (int) ($x->units ?? 0), 'sales' => round($sales, 2), 'real' => $sales > 0 ? round(($sales - (float) $x->cost) / $sales * 100, 1) : null]; })->all(),
            'The real margin is sales less the cost of the units sold (before marketplace fees). Empty until the product has confirmed sales.'];
    }

    private static function r_landed(int $ent, string $from, string $to): array
    {
        $rows = DB::table('fin_lots as l')->leftJoin('t_src_po as p', 'p.id', '=', 'l.po_id')->leftJoin('t_src_landed_cost as c', fn ($j) => $j->on('c.fk_id_po', '=', 'l.po_id')->where('c.f_active', 1)->whereNull('c.superseded_by'))
            ->where('l.company_id', $ent)->where('l.source', 'GRN')->whereBetween('l.received_date', [$from, $to])->orderBy('l.received_date')->get(['l.lot_code', 'l.sku', 'p.po_number', 'l.received_date', 'l.qty', 'l.unit_cost_gbp', 'l.landed_status', 'c.freight_cost', 'c.customs_duty', 'c.other_cost', 'c.product_cost']);
        return [[['lot', 'Lot', 'text'], ['sku', 'SKU', 'text'], ['po', 'PO', 'text'], ['date', 'Received', 'date'], ['qty', 'Units', 'int'], ['unit', 'Cost per unit', 'money'], ['value', 'Lot value', 'money'], ['po_freight', 'PO freight', 'money'], ['po_duty', 'PO duty', 'money'], ['po_other', 'PO other', 'money'], ['status', 'Cost', 'text']],
            $rows->map(fn ($r) => ['lot' => $r->lot_code, 'sku' => $r->sku, 'po' => $r->po_number ?? '', 'date' => $r->received_date, 'qty' => (int) $r->qty, 'unit' => round((float) $r->unit_cost_gbp, 4), 'value' => round((float) $r->unit_cost_gbp * $r->qty, 2),
                'po_freight' => round((float) $r->freight_cost, 2), 'po_duty' => round((float) $r->customs_duty, 2), 'po_other' => round((float) $r->other_cost, 2), 'status' => $r->landed_status])->all(),
            'The cost per unit already holds the lot\'s share of freight, duty and other costs once the landed-cost run is done. PO costs are repeated on each lot of the PO – do not add them up across lots.'];
    }

    private static function r_in_transit(int $ent, string $from, string $to): array
    {
        $rows = DB::table('t_src_shipments as s')->leftJoin('t_src_po as p', 'p.id', '=', 's.fk_id_po')->leftJoin('t_src_suppliers as u', 'u.id', '=', 's.fk_id_supplier')->where('s.fk_id_ent', $ent)->where('s.f_active', 1)->whereNull('s.actual_arrival')->orderBy('s.eta')
            ->get(['s.shipment_code', 'p.po_number', 'u.supplier_name', 's.planned_qty', 's.etd', 's.eta', 's.shipping_mode', 's.status']);
        $today = date('Y-m-d');
        return [[['ship', 'Shipment', 'text'], ['po', 'PO', 'text'], ['supplier', 'Supplier', 'text'], ['units', 'Units', 'int'], ['mode', 'Mode', 'text'], ['etd', 'Left', 'date'], ['eta', 'Expected', 'date'], ['late', 'Days late', 'int']],
            $rows->map(fn ($r) => ['ship' => $r->shipment_code, 'po' => $r->po_number ?? '', 'supplier' => $r->supplier_name ?? '', 'units' => (int) $r->planned_qty, 'mode' => $r->shipping_mode ?? '', 'etd' => $r->etd, 'eta' => $r->eta, 'late' => $r->eta && $r->eta < $today ? (int) ((strtotime($today) - strtotime($r->eta)) / 86400) : 0])->all(),
            'Today\'s position: every shipment with no arrival date yet. The dates of the report are not used.'];
    }

    private static function r_supplier_perf(int $ent, string $from, string $to): array
    {
        $po = DB::table('t_src_po as p')->join('t_src_suppliers as u', 'u.id', '=', 'p.fk_id_supplier')->where('p.fk_id_ent', $ent)->where('p.f_active', 1)->whereBetween('p.po_date', [$from, $to])->whereNotIn('p.status', ['DRAFT', 'CANCELLED'])
            ->groupBy('u.id', 'u.supplier_name')->selectRaw('u.id, u.supplier_name, COUNT(*) pos, SUM(p.po_total) total')->get()->keyBy('id');
        $g = DB::table('t_src_grn as g')->join('t_src_po as p', 'p.id', '=', DB::raw('(SELECT fk_id_po FROM t_src_shipments WHERE id = g.fk_id_shipment)'))->where('g.fk_id_ent', $ent)->where('g.posting_status', 'POSTED')->whereBetween('p.po_date', [$from, $to])
            ->groupBy('p.fk_id_supplier')->selectRaw('p.fk_id_supplier s, SUM(g.expected_units) exp, SUM(g.units_received) rec, SUM(g.damaged_units) dmg, SUM(g.missing_units) mis')->get()->keyBy('s');
        $late = DB::table('t_src_shipments as s')->join('t_src_po as p', 'p.id', '=', 's.fk_id_po')->where('s.fk_id_ent', $ent)->whereNotNull('s.actual_arrival')->whereNotNull('s.eta')->whereBetween('p.po_date', [$from, $to])
            ->groupBy('p.fk_id_supplier')->selectRaw('p.fk_id_supplier s, AVG(GREATEST(DATEDIFF(s.actual_arrival, s.eta), 0)) late, COUNT(*) n')->get()->keyBy('s');
        return [[['supplier', 'Supplier', 'text'], ['pos', 'POs', 'int'], ['total', 'Ordered (PO currency, added up)', 'money'], ['expected', 'Units expected', 'int'], ['received', 'Units received', 'int'], ['missing_pct', 'Missing %', 'pct'], ['damaged_pct', 'Damaged %', 'pct'], ['late', 'Days late on average', 'pct']],
            $po->map(function ($r) use ($g, $late) { $x = $g[$r->id] ?? null; $exp = (float) ($x->exp ?? 0); $rec = (float) ($x->rec ?? 0);
                return ['supplier' => $r->supplier_name, 'pos' => (int) $r->pos, 'total' => round((float) $r->total, 2), 'expected' => (int) $exp, 'received' => (int) $rec, 'missing_pct' => $exp > 0 ? round((float) $x->mis / $exp * 100, 1) : null, 'damaged_pct' => $rec > 0 ? round((float) $x->dmg / $rec * 100, 1) : null, 'late' => isset($late[$r->id]) ? round((float) $late[$r->id]->late, 1) : null]; })->values()->all(),
            'POs dated in the period (drafts and cancelled left out). "Ordered" adds PO totals in their own currencies – compare suppliers of the same currency.'];
    }

    private static function r_po_payments(int $ent, string $from, string $to): array
    {
        $rows = DB::table('t_src_payment_terms as t')->join('t_src_po as p', 'p.id', '=', 't.fk_id_po')->leftJoin('t_src_suppliers as u', 'u.id', '=', 'p.fk_id_supplier')->leftJoin('cat_currency as c', 'c.id', '=', 'p.fk_id_currency')->where('t.fk_id_ent', $ent)->where('t.f_active', 1)
            ->where(fn ($w) => $w->where('t.remaining_amount', '>', 0)->orWhereBetween('t.due_date', [$from, $to]))->orderBy('t.due_date')->get(['p.po_number', 'u.supplier_name', 't.milestone', 'c.currency_code', 't.planned_amount', 't.paid_amount', 't.remaining_amount', 't.due_date', 't.status']);
        return [[['po', 'PO', 'text'], ['supplier', 'Supplier', 'text'], ['term', 'Term', 'text'], ['currency', 'Currency', 'text'], ['planned', 'Planned', 'money'], ['paid', 'Paid', 'money'], ['left', 'Left', 'money'], ['due', 'Due', 'date'], ['status', 'Status', 'text']],
            $rows->map(fn ($r) => ['po' => $r->po_number, 'supplier' => $r->supplier_name ?? '', 'term' => $r->milestone, 'currency' => $r->currency_code ?? '', 'planned' => round((float) $r->planned_amount, 2), 'paid' => round((float) $r->paid_amount, 2), 'left' => round((float) $r->remaining_amount, 2), 'due' => $r->due_date, 'status' => $r->status])->all(),
            'Amounts in the currency of each PO. Every term still open, plus the terms due in the period.'];
    }

    private static function r_valuation(int $ent, string $from, string $to): array
    {
        $in = DB::table('fin_lots')->where('company_id', $ent)->where('received_date', '<=', $to)->where('source', '<>', 'SPLIT')->groupBy('fk_id_product')->selectRaw('fk_id_product p, SUM(qty) q, SUM(qty * unit_cost_gbp) v')->get()->keyBy('p');
        $split = DB::table('fin_lots')->where('company_id', $ent)->where('received_date', '<=', $to)->where('source', 'SPLIT')->groupBy('fk_id_product')->selectRaw('fk_id_product p, SUM(qty) q, SUM(qty * unit_cost_gbp) v')->get()->keyBy('p');
        $out = DB::table('fin_lot_consumption as c')->join('fin_lots as l', 'l.id', '=', 'c.lot_id')->where('l.company_id', $ent)->where('c.consumed_on', '<=', $to)->groupBy('l.fk_id_product')->selectRaw('l.fk_id_product p, SUM(c.qty) q, SUM(c.qty * c.unit_cost_gbp) v')->get()->keyBy('p');
        $names = DB::table('t_product_vendors')->whereIn('id', $in->keys())->get(['id', 'SKU_VENDOR', 'product_name_vendor'])->keyBy('id'); $rows = [];
        foreach ($in as $p => $x) { $q = (int) $x->q + (int) ($split[$p]->q ?? 0) - (int) ($out[$p]->q ?? 0); $v = (float) $x->v + (float) ($split[$p]->v ?? 0) - (float) ($out[$p]->v ?? 0); if ($q === 0 && abs($v) < 0.005) continue;
            $rows[] = ['sku' => $names[$p]->SKU_VENDOR ?? '', 'name' => $names[$p]->product_name_vendor ?? '', 'units' => $q, 'value' => round($v, 2), 'avg' => $q ? round($v / $q, 4) : null]; }
        usort($rows, fn ($a, $b) => $b['value'] <=> $a['value']);
        return [[['sku', 'SKU', 'text'], ['name', 'Product', 'text'], ['units', 'Units', 'int'], ['value', 'Value at cost', 'money'], ['avg', 'Average cost per unit', 'money']], $rows,
            'As at the end date. Units that left by sale, loss or write-off up to that date are taken out at the cost of their own lot (FIFO), so today\'s figure equals the stock value in the books. Later cost corrections of a lot show at the date the lot was received.'];
    }

    private static function r_slow_dead(int $ent, string $from, string $to): array
    {
        $stock = DB::table('fin_lots')->where('company_id', $ent)->where('qty_left', '>', 0)->groupBy('fk_id_product')->selectRaw('fk_id_product p, SUM(qty_left) q, SUM(qty_left * unit_cost_gbp) v')->get()->keyBy('p');
        $last = DB::table('t_sales')->where('fk_id_ent', $ent)->where('sale_source', 'active')->whereIn('status', ['Pending', 'Confirmed'])->whereIn('fk_product_id', $stock->keys())->groupBy('fk_product_id')->selectRaw('fk_product_id p, MAX(d_order) d')->pluck('d', 'p');
        $names = DB::table('t_product_vendors')->whereIn('id', $stock->keys())->get(['id', 'SKU_VENDOR', 'product_name_vendor'])->keyBy('id');
        $slow = (int) Settings::get('slow_days', $ent, 90); $dead = (int) Settings::get('dead_days', $ent, 180); $rows = [];
        foreach ($stock as $p => $x) { $d = $last[$p] ?? null; $days = $d ? (int) ((time() - strtotime($d)) / 86400) : null; if ($days !== null && $days < $slow) continue;
            $rows[] = ['sku' => $names[$p]->SKU_VENDOR ?? '', 'name' => $names[$p]->product_name_vendor ?? '', 'units' => (int) $x->q, 'value' => round((float) $x->v, 2), 'last' => $d, 'days' => $days, 'kind' => $days === null || $days >= $dead ? 'Dead' : 'Slow']; }
        usort($rows, fn ($a, $b) => $b['value'] <=> $a['value']);
        return [[['sku', 'SKU', 'text'], ['name', 'Product', 'text'], ['units', 'Units in stock', 'int'], ['value', 'Value at cost', 'money'], ['last', 'Last sale', 'date'], ['days', 'Days since', 'int'], ['kind', 'Slow / dead', 'text']], $rows,
            "Today's position. Slow = no sale for $slow days, dead = $dead days or never sold (settings slow_days, dead_days)."];
    }

    private static function r_sales_months(int $ent, string $from, string $to): array
    {
        $rows = self::sales($ent, $from, $to)->leftJoin('t_branches as b', 'b.id', '=', 's.fk_id_branch')->groupBy('b.shop_name', DB::raw("DATE_FORMAT(s.d_order, '%Y-%m')"))->orderBy('b.shop_name')->orderBy(DB::raw("DATE_FORMAT(s.d_order, '%Y-%m')"))
            ->selectRaw("b.shop_name shop, DATE_FORMAT(s.d_order, '%Y-%m') m, " . self::SALE_SUMS)->get();
        return [array_merge([['shop', 'Shop', 'text'], ['month', 'Month', 'text']], self::SALE_COLS), $rows->map(fn ($r) => self::saleRow($r, ['shop' => $r->shop ?: 'No shop', 'month' => $r->m]))->all(), self::SALE_NOTE];
    }

    private static function r_price_log(int $ent, string $from, string $to): array
    {
        $rows = DB::table('t_product_inventory_change_log as c')->join('t_product_vendors as p', 'p.id', '=', 'c.fk_product_id')->leftJoin('cat_marketplaces as m', 'm.id', '=', 'c.fk_id_marketplace')->leftJoin('t_users as u', 'u.id', '=', 'c.changed_by')
            ->where('p.fk_id_ent', $ent)->whereBetween(DB::raw('DATE(c.created_at)'), [$from, $to])->orderByDesc('c.id')->limit(5000)->get(['c.created_at', 'p.SKU_VENDOR', 'm.marketplace_short', 'c.old_price', 'c.new_price', 'c.from_date', 'u.login_id']);
        return [[['when', 'Changed on', 'date'], ['sku', 'SKU', 'text'], ['mp', 'Marketplace', 'text'], ['old', 'Old price', 'money'], ['new', 'New price', 'money'], ['from', 'Valid from', 'date'], ['by', 'By', 'text']],
            $rows->map(fn ($r) => ['when' => substr((string) $r->created_at, 0, 10), 'sku' => $r->SKU_VENDOR, 'mp' => $r->marketplace_short ?? '', 'old' => $r->old_price !== null ? round((float) $r->old_price, 2) : null, 'new' => round((float) $r->new_price, 2), 'from' => $r->from_date, 'by' => $r->login_id ?? ''])->all(), 'Up to 5,000 changes, newest first.'];
    }

    private static function r_expenses(int $ent, string $from, string $to): array
    {
        $rows = DB::table('fin_expenses as e')->leftJoin('fin_categories as c', 'c.code', '=', 'e.category_code')->leftJoin('t_branches as b', 'b.id', '=', 'e.fk_id_branch')->where('e.fk_id_ent', $ent)->where('e.f_active', 1)->whereBetween('e.d_expense', [$from, $to])
            ->groupBy('c.name', 'b.shop_name')->orderBy('c.name')->selectRaw('c.name cat, b.shop_name shop, COUNT(*) n, SUM(e.amount_gbp) v, SUM(COALESCE(e.vat_amount,0) * COALESCE(e.exchange_rate,1)) vat')->get();
        return [[['category', 'Category', 'text'], ['shop', 'Shop', 'text'], ['count', 'Expenses', 'int'], ['amount', 'Amount', 'money'], ['vat', 'VAT inside', 'money']],
            $rows->map(fn ($r) => ['category' => $r->cat ?? '', 'shop' => $r->shop ?: 'Shared (no shop)', 'count' => (int) $r->n, 'amount' => round((float) $r->v, 2), 'vat' => round((float) $r->vat, 2)])->all(), 'Expenses waiting for approval are counted only once approved.'];
    }

    private static function r_ledger(int $ent, string $from, string $to): array
    {
        $rows = DB::table('gl_lines as l')->join('gl_journals as j', 'j.id', '=', 'l.journal_id')->join('gl_accounts as a', 'a.id', '=', 'l.account_id')->where('j.fk_id_ent', $ent)->whereBetween('j.journal_date', [$from, $to])->orderBy('j.journal_date')->orderBy('j.id')->orderBy('l.id')->limit(20000)
            ->get(['j.journal_date', 'j.journal_no', 'j.source_type', 'a.code', 'a.name', 'l.ref_id', 'l.debit', 'l.credit', 'j.memo']);
        return [[['date', 'Date', 'date'], ['no', 'Journal', 'text'], ['source', 'From', 'text'], ['account', 'Account', 'text'], ['name', 'Account name', 'text'], ['ref', 'Ref', 'text'], ['debit', 'Debit', 'money'], ['credit', 'Credit', 'money'], ['memo', 'Memo', 'text']],
            $rows->map(fn ($r) => ['date' => $r->journal_date, 'no' => $r->journal_no, 'source' => $r->source_type, 'account' => $r->code, 'name' => $r->name, 'ref' => (string) ($r->ref_id ?? ''), 'debit' => round((float) $r->debit, 2), 'credit' => round((float) $r->credit, 2), 'memo' => mb_substr((string) $r->memo, 0, 120)])->all(),
            'Debit total = credit total (the books always balance). Up to 20,000 lines per run – use a shorter period for more.'];
    }

    private static function r_tasks_week(int $ent, string $from, string $to): array
    {
        $users = DB::table('t_users')->where('fk_id_ent', $ent)->where('f_active', 1)->pluck('login_id', 'id'); $now = now();
        $closed = DB::table('tasks')->where('fk_id_ent', $ent)->whereIn('status', ['DONE', 'APPROVED'])->whereBetween(DB::raw('DATE(done_at)'), [$from, $to])->groupBy('owner_user_id')->selectRaw('owner_user_id u, COUNT(*) n, AVG(score) s')->get()->keyBy('u');
        $open = DB::table('tasks')->where('fk_id_ent', $ent)->whereNotIn('status', ['DONE', 'APPROVED', 'CANCELLED'])->groupBy('owner_user_id')->selectRaw("owner_user_id u, SUM(due_at < ?) late, SUM(stuck_since IS NOT NULL) stuck, COUNT(*) n", [$now->toDateString()])->get()->keyBy('u');
        $rows = [];
        foreach ($users as $id => $login) { $c = $closed[$id] ?? null; $o = $open[$id] ?? null; if (! $c && ! $o) continue;
            $rows[] = ['who' => $login, 'closed' => (int) ($c->n ?? 0), 'score' => $c && $c->s !== null ? round((float) $c->s, 1) : null, 'open' => (int) ($o->n ?? 0), 'late' => (int) ($o->late ?? 0), 'stuck' => (int) ($o->stuck ?? 0)]; }
        usort($rows, fn ($a, $b) => [$b['closed'], $a['late']] <=> [$a['closed'], $b['late']]);
        return [[['who', 'Person', 'text'], ['closed', 'Closed in the period', 'int'], ['score', 'Average score', 'pct'], ['open', 'Open now', 'int'], ['late', 'Late now', 'int'], ['stuck', 'Stuck now', 'int']], $rows, 'Closed = done or approved in the period; open, late and stuck are today\'s figures. The list is the leaderboard: most closed first.'];
    }

    private static function r_users(int $ent, string $from, string $to): array
    {
        $open = DB::table('sys_user_sessions')->whereNull('ended_at')->groupBy('user_id')->selectRaw('user_id u, COUNT(*) n')->pluck('n', 'u');
        $rows = DB::table('t_users as u')->leftJoin('t_enterprise as e', 'e.id', '=', 'u.fk_id_ent')->where('u.fk_id_ent', $ent)->orderBy('u.role_code')->orderBy('u.login_id')->get(['u.id', 'u.login_id', 'u.display_name', 'u.role_code', 'e.ent_name', 'u.f_active', 'u.last_login_at', 'u.totp_secret']);
        return [[['login', 'Login', 'text'], ['name', 'Name', 'text'], ['role', 'Role', 'text'], ['company', 'Company', 'text'], ['active', 'Active', 'text'], ['two_step', '2-step sign-in', 'text'], ['last', 'Last sign-in', 'date'], ['open', 'Signed in now', 'int']],
            $rows->map(fn ($r) => ['login' => $r->login_id, 'name' => $r->display_name ?? '', 'role' => $r->role_code, 'company' => $r->ent_name ?? '', 'active' => $r->f_active ? 'yes' : 'no', 'two_step' => $r->totp_secret ? 'on' : 'off', 'last' => $r->last_login_at ? substr((string) $r->last_login_at, 0, 10) : null, 'open' => (int) ($open[$r->id] ?? 0)])->all(), 'No password or secret is shown.'];
    }

    private static function r_menu(int $ent, string $from, string $to): array
    {
        $rows = DB::table('sidebar_modules as m')->leftJoin('sidebar_modules as s', 's.id_module', '=', 'm.parent_id')->whereNotNull('m.parent_id')->where('m.e_status', 'ACTIVE')->orderBy('s.sort_order')->orderBy('m.sort_order')->get(['s.module_name as section', 'm.screen_no', 'm.module_name', 'm.roles']);
        return [[['section', 'Section', 'text'], ['no', 'No.', 'text'], ['screen', 'Screen', 'text'], ['roles', 'Who sees it', 'text']], $rows->map(fn ($r) => ['section' => $r->section ?? '', 'no' => $r->screen_no ?? '', 'screen' => $r->module_name, 'roles' => $r->roles ?: 'everyone'])->all(), ''];
    }

    private static function r_partners(int $ent, string $from, string $to): array
    {
        $rows = DB::table('fin_investor_ledger')->where('company_id', $ent)->whereBetween('entry_date', [$from, $to])->groupBy('investor_id', 'type')->selectRaw('investor_id, type, SUM(amount) v')->get(); $out = [];
        $ids = DB::table('fin_investor_agreements')->where('company_id', $ent)->pluck('investor_id')->merge($rows->pluck('investor_id'))->unique();
        foreach ($ids as $id) $out[$id] = ['partner' => PartnerService::name((int) $id), 'capital' => 0.0, 'profit' => 0.0, 'fee' => 0.0, 'withdrawn' => 0.0, 'balance' => PartnerService::account($ent, (int) $id)['balance']];
        foreach ($rows as $r) { $c = ['CAPITAL_IN' => 'capital', 'PROFIT' => 'profit', 'MANAGEMENT_FEE' => 'fee', 'WITHDRAWAL' => 'withdrawn'][$r->type] ?? null; if ($c) $out[$r->investor_id][$c] = round(abs((float) $r->v), 2); }
        return [[['partner', 'Partner', 'text'], ['capital', 'Capital in', 'money'], ['profit', 'Profit share', 'money'], ['fee', 'Management fee', 'money'], ['withdrawn', 'Withdrawn', 'money'], ['balance', 'Owed to the partner today', 'money']], array_values($out),
            'Capital, share, fee and withdrawals are those dated in the period; the last column is the balance today.'];
    }

    private static function r_close(int $ent, string $from, string $to): array
    {
        $shops = DB::table('t_branches')->where('fk_id_ent', $ent)->orderBy('shop_name')->get(['id', 'shop_name']); $out = [];
        for ($m = substr($from, 0, 7); $m <= substr($to, 0, 7); $m = date('Y-m', strtotime($m . '-01 +1 month'))) {
            $a = $m . '-01'; $b = date('Y-m-t', strtotime($a));
            foreach ($shops as $s) {
                $run = CloseService::run($ent, (int) $s->id, $m); if (! $run && ! CloseService::hasActivity($ent, (int) $s->id, $a, $b)) continue;
                $st = $run->status ?? 'OPEN';
                $out[] = ['month' => $m, 'shop' => $s->shop_name, 'status' => ['OPEN' => 'Open', 'CHECKED' => 'Checked', 'LOCKED' => 'Locked'][$st] ?? $st, 'score' => $run && $run->score !== null ? $run->score . '%' : '', 'sales' => $st === 'LOCKED' ? round((float) $run->gross_sales, 2) : 0.0,
                    'profit' => $st === 'LOCKED' ? round((float) $run->net_profit, 2) : 0.0, 'locked' => $run && $run->locked_at ? substr($run->locked_at, 0, 10) : '', 'note' => $run->owner_note ?? ''];
            }
        }
        return [[['month', 'Month', 'text'], ['shop', 'Shop', 'text'], ['status', 'Status', 'text'], ['score', 'Checks passing', 'text'], ['sales', 'Sales (locked months)', 'money'], ['profit', 'Profit of the shop (locked months)', 'money'], ['locked', 'Locked on', 'date'], ['note', 'Owner\'s note', 'text']], $out,
            'Sales and profit are shown only for locked months – they are the frozen figures. Open months are in the profit views.'];
    }
}
