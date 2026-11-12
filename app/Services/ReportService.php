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
