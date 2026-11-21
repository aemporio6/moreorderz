<?php

namespace Tests\Feature;

use App\Services\RecordService;
use App\Services\SaleLines;
use App\Services\SalesImport;
use App\Services\SalesService;
use App\Services\SaleView;
use App\Services\StockService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_A_SALES R-A_SALES-34 (lock + Owner unlock), 35 (one-batch view), 42 (filters), 44 (columns per person), 45 (extra columns), 46 (export). */
class SaleViewTest extends TestCase
{
    private int $shop; private int $mp; private int $product; private array $files = [];

    private function fill(string $t, array $d): array { return RecordService::fillNotNull($t, $d); }

    private function world(): void
    {
        $this->actingAs($this->owner);
        $this->mp = DB::table('cat_marketplaces')->insertGetId($this->fill('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'Ebay', 'marketplace_descr' => 'eBay', 'f_active' => 1]));
        $this->shop = DB::table('t_branches')->insertGetId($this->fill('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $this->mp, 'shop_no' => '101A', 'shop_name' => 'Pets', 'iso_branch' => 'P1', 'f_active' => 1, 'fulfilment_channel' => 'OTHER']));
        $this->product = DB::table('t_product_vendors')->insertGetId($this->fill('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'BOWL-01', 'product_name_vendor' => 'Dog bowl', 'f_active' => 1, 'raw_price' => 4, 'sale_price' => 9]));
        SalesImport::saveColumns($this->mp, 'SALE', ['order_no' => 'Order number', 'order_date' => 'Sale date', 'cust_name' => 'Buyer', 'sku' => 'Custom label', 'product_info' => 'Item title', 'qty' => 'Quantity', 'sale_rate' => 'Sold for']);
    }

    private function import(array $lines, array $d = [], string $name = '101A_july26.csv'): array
    {
        $p = tempnam(sys_get_temp_dir(), 'sv'); $this->files[] = $p; file_put_contents($p, "Order number,Sale date,Buyer,Custom label,Item title,Quantity,Sold for,Delivery service\n" . implode("\n", $lines) . "\n");
        return SalesImport::run($p, $name, filesize($p), $d + ['nature' => 'Normal', 'marketplace' => $this->mp, 'shop' => $this->shop, 'month' => 7, 'year' => 2026, 'fee' => '10'], $this->owner, true);
    }

    protected function tearDown(): void { foreach ($this->files as $f) @unlink($f); parent::tearDown(); }

    private function f(array $x = []): array { return $x + ['marketplace' => $this->mp, 'from' => '2026-07-01', 'to' => '2026-07-31']; }

    public function test_extra_columns_setup_checks_and_one_column_for_the_same_label_on_two_marketplaces(): void
    {
        $this->world(); $mp2 = DB::table('cat_marketplaces')->insertGetId($this->fill('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'Amazon', 'marketplace_descr' => 'Amazon', 'f_active' => 1]));
        $msg = function (array $rows) { try { SaleView::saveExtra($this->mp, $rows); return 'ok'; } catch (\InvalidArgumentException $e) { return $e->getMessage(); } };
        $this->assertSame('Extra column 1: enter a column name.', $msg([['label' => '', 'csv' => 'X']]));
        $this->assertSame('Extra column "Delivery service": enter the CSV column name(s).', $msg([['label' => 'Delivery service', 'csv' => ' , ']]));
        $this->assertSame('Extra column "' . str_repeat('a', 61) . '": the name can have up to 60 characters.', $msg([['label' => str_repeat('a', 61), 'csv' => 'X']]));
        $this->assertSame('Extra column "Order No": this name is already a Sale Column, choose another name.', $msg([['label' => 'Order No', 'csv' => 'X']]));
        $this->assertSame('Extra column "Note" is entered twice.', $msg([['label' => 'Note', 'csv' => 'A'], ['label' => 'note', 'csv' => 'B']]));
        $this->assertSame('ok', $msg([['label' => '', 'csv' => ''], ['label' => 'Delivery service', 'csv' => "Delivery service\nPostage service, delivery SERVICE"], ['label' => 'Note', 'csv' => 'Buyer note']]));
        $rows = SaleView::extraSetup($this->mp); $this->assertSame(['Delivery service', 'Note'], array_column($rows, 'label')); $this->assertSame('Delivery service, Postage service', $rows[0]['csv']);
        SaleView::saveExtra($mp2, [['label' => 'delivery service', 'csv' => 'ship-service-level']]);
        $ex = SaleView::extras(); $this->assertCount(2, $ex); $k = SaleView::key('Delivery service'); $this->assertSame(['Ebay', 'Amazon'], $ex[$k]['marketplaces']); $this->assertMatchesRegularExpression('/^xc_[0-9a-f]{10}$/', $k);
        // save = kept rows updated, removed rows deleted, order = row order
        SaleView::saveExtra($this->mp, [['id' => $rows[1]['id'], 'label' => 'Note', 'csv' => 'Buyer note']]); $this->assertSame(['Note'], array_column(SaleView::extraSetup($this->mp), 'label'));
    }

    public function test_extra_column_values_come_from_the_stored_csv_row_also_for_months_imported_before(): void
    {
        $this->world(); $this->import(['A-1,05/07/2026,Ann,BOWL-01,Dog bowl,1,9.00,Royal Mail 48']);
        $this->assertSame([], SaleLines::list($this->f(), $this->owner)['rows'][0]['xc']);
        SaleView::saveExtra($this->mp, [['label' => 'Delivery service', 'csv' => 'Delivery service']]);
        $d = SaleLines::list($this->f(), $this->owner); $k = SaleView::key('Delivery service');
        $this->assertSame('Royal Mail 48', $d['rows'][0]['xc'][$k]); $this->assertSame([$k], array_column($d['columns']['extra'], 'key'));
    }

    public function test_columns_per_person_drive_the_export(): void
    {
        $this->world(); $this->import(['A-1,05/07/2026,Ann,BOWL-01,Dog bowl,1,9.00,RM']);
        $c = SaleView::columns($this->owner); $this->assertSame(9, count($c['fixed'])); $this->assertSame(11, count($c['optional'])); $this->assertSame('', $c['count']);
        $r = SaleView::saveHidden($this->owner, ['raw_price', 'order_no', 'no_such_key']); $this->assertSame(['raw_price'], $r['hidden']); $this->assertSame('Saved for your account', $r['message']);      // fixed and unknown keys are dropped
        $this->assertSame('(10/11)', SaleView::columns($this->owner)['count']);
        $labels = array_column(SaleView::visible($this->owner), 'label'); $this->assertNotContains('Raw Price', $labels); $this->assertContains('Buy Rate', $labels); $this->assertSame('Order No', $labels[0]);
        $pdf = SaleView::export($this->f(), 'pdf', $this->owner, '2026-08-02 10:15:00');
        $this->assertStringStartsWith('%PDF', $pdf['body']); $this->assertStringNotContainsString('Raw Price', $pdf['html']); $this->assertStringContainsString('Buy Rate', $pdf['html']); $this->assertSame('sales_2026-08-02_101500.pdf', $pdf['name']);
        // a person without the cost right gets no cost columns at all
        $va = \App\Models\User::find(DB::table('t_users')->insertGetId($this->fill('t_users', ['fk_id_ent' => 1, 'login_id' => 'wh@demo.test', 'display_name' => 'W', 'role_code' => 'WAREHOUSE', 'f_active' => 1])));
        $this->assertNotContains('Profit', array_column(SaleView::visible($va), 'label'));
        $this->postJson('/api/sales/view-columns', ['hidden' => ['profit']])->assertOk()->assertJsonPath('hidden.0', 'profit');
    }

    public function test_export_limits_messages_subtitle_and_summary(): void
    {
        $this->world(); $this->import(['A-1,05/07/2026,Ann,BOWL-01,Dog bowl,2,9.00,RM', 'A-2,06/07/2026,Bob,BOWL-01,Dog bowl,1,9.00,RM']);
        $msg = function (callable $fn) { try { $fn(); return 'ok'; } catch (\InvalidArgumentException $e) { return $e->getMessage(); } };
        $this->assertSame('Choose a marketplace first.', $msg(fn () => SaleView::exportCheck([], 'pdf', $this->owner)));
        $this->assertSame('There are no sales to export with these filters.', $msg(fn () => SaleView::exportCheck($this->f(['status' => 'Refunded']), 'pdf', $this->owner)));
        $this->assertSame('Preparing your PDF (2 lines). The download starts in a moment.', SaleView::exportCheck($this->f(), 'pdf', $this->owner)['message']);
        $this->assertSame('Ebay | Shop 101A (Pets) | 01-07-2026 to 31-07-2026 | Refunded | 0 line(s) | Exported 02-08-2026 10:15', SaleView::subtitle($this->f(['shop' => $this->shop, 'status' => 'Refunded']), 0, '2026-08-02 10:15:00'));
        $this->assertStringStartsWith('All sales | 5 line(s) | Exported ', SaleView::subtitle([], 5));
        // 1,600 lines: PDF refused with the rule's message, Excel is fine
        $one = (array) DB::table('t_sales')->first(); unset($one['id']); $bulk = [];
        for ($i = 0; $i < 1598; $i++) $bulk[] = ['order_no' => "B-$i", 'raw_csv' => null] + $one; foreach (array_chunk($bulk, 400) as $ch) DB::table('t_sales')->insert($ch);
        $this->assertSame('The PDF export takes up to 1500 lines; these filters match 1600. Narrow the filters (e.g. dates or shop) and try again.', $msg(fn () => SaleView::exportCheck($this->f(), 'pdf', $this->owner)));
        $this->assertSame('Too many lines to export (1600). The PDF export takes up to 1500 lines: narrow the filters and try again.', $msg(fn () => SaleView::export($this->f(), 'pdf', $this->owner)));
        $x = SaleView::export($this->f(), 'xlsx', $this->owner); $this->assertSame(1600, $x['lines']); $this->assertStringStartsWith('PK', $x['body']);
        DB::table('t_sales')->where('order_no', 'like', 'B-%')->delete();
        // Word and CSV carry the same title, subtitle, summary and columns
        $w = SaleView::export($this->f(), 'docx', $this->owner, '2026-08-02 10:15:00'); $this->assertSame('sales_2026-08-02_101500.docx', $w['name']);
        $p = tempnam(sys_get_temp_dir(), 'dz'); $this->files[] = $p; file_put_contents($p, $w['body']); $z = new \ZipArchive(); $this->assertTrue($z->open($p)); $doc = $z->getFromName('word/document.xml'); $z->close();
        foreach (['Sales', 'Ebay | 01-07-2026 to 31-07-2026 | 2 line(s)', 'Total qty: 3', 'Marketplace fee: 10.00', 'VA share (0%)', 'Net profit', 'Total loss', 'Order No', 'A-1'] as $t) $this->assertStringContainsString($t, $doc);
        $this->assertNotFalse(simplexml_load_string($doc));
        $csv = SaleView::export($this->f(), 'csv', $this->owner)['body']; $this->assertStringContainsString('"Order No","Order Date"', $csv); $this->assertStringContainsString('"Total qty",3', $csv);
        $this->get('/api/sales/export?format=csv&marketplace=' . $this->mp . '&from=2026-07-01&to=2026-07-31')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->getJson('/api/sales/export/check?format=pdf')->assertStatus(422)->assertJsonPath('message', 'Choose a marketplace first.');
    }

    public function test_strip_shows_fee_va_share_and_net_once_per_batch(): void
    {
        $this->world(); $r = $this->import(['A-1,05/07/2026,Ann,BOWL-01,Dog bowl,2,9.00,RM', 'A-2,06/07/2026,Bob,BOWL-01,Dog bowl,1,9.00,RM']);
        DB::table('t_sale_imports')->where('id', $r['batch'])->update(['va_share_percentage' => 40]); DB::table('t_sales')->where('order_no', 'A-2')->update(['profit' => -2]);
        $t = SaleLines::list($this->f(), $this->owner)['totals']; $profit = (float) DB::table('t_sales')->sum('profit');
        $this->assertSame([2, 3, 27.0, 10.0, 'VA share (40%)'], [$t['records'], $t['qty'], $t['sale'], $t['fee'], $t['va_label']]);
        $this->assertSame(round(($profit - 10) * 0.4, 2), $t['va_share']); $this->assertSame(round($profit - 10 - $t['va_share'], 2), $t['net']); $this->assertSame(2.0, $t['loss']);      // loss = Σ(−profit) of loss lines
    }

    public function test_va_percent_is_frozen_from_the_job_history_row_valid_on_the_period_end_and_the_strip_example_of_the_rule(): void
    {
        $this->world(); $va = DB::table('t_account')->insertGetId($this->fill('t_account', ['fk_id_ent' => 1, 'first_name' => 'Vera', 'sur_name' => 'VA', 'f_active' => 1])); DB::table('t_branches')->where('id', $this->shop)->update(['fk_id_seller' => $va]);
        $h = fn (string $from, ?string $to, float $pct) => DB::table('t_account_job_history')->insert($this->fill('t_account_job_history', ['fk_id_account' => $va, 'fk_id_marketplace' => $this->mp, 'd_job_start_date' => $from, 'share_percentage' => $pct]) + ['d_end_date' => $to]);
        $h('2026-01-01', '2026-07-31', 10); $h('2026-08-01', null, 15);      // the latest row by id is 15 %, but July's period end falls in the 10 % row
        $b1 = $this->import(['A-1,05/07/2026,Ann,BOWL-01,Dog bowl,1,9.00,RM'], ['fee' => '40']); $b2 = $this->import(['A-2,05/08/2026,Bob,BOWL-01,Dog bowl,1,9.00,RM'], ['month' => 8, 'fee' => '0'], '101A_aug26.csv');
        $this->assertSame([10.0, 15.0, $va], [(float) DB::table('t_sale_imports')->where('id', $b1['batch'])->value('va_share_percentage'), (float) DB::table('t_sale_imports')->where('id', $b2['batch'])->value('va_share_percentage'), (int) DB::table('t_sale_imports')->where('id', $b1['batch'])->value('fk_id_va_account')]);
        // rule example: batch 1 profit 500, fee 40, 10 % → VA 46, net 414; batch 2 profit 300, fee 0, 15 % → 45, 255; strip: profit 800, fee 40, "mixed %" 91, net 669
        DB::table('t_sales')->where('order_no', 'A-1')->update(['profit' => 500]); DB::table('t_sales')->where('order_no', 'A-2')->update(['profit' => 300]);
        $t = SaleLines::list(['marketplace' => $this->mp, 'from' => '2026-07-01', 'to' => '2026-08-31'], $this->owner)['totals'];
        $this->assertSame([800.0, 40.0, 'VA share (mixed %)', 91.0, 669.0], [$t['profit'], $t['fee'], $t['va_label'], $t['va_share'], $t['net']]);
    }

    public function test_filters_carrier_group_company_batch_and_the_lists_for_the_controls(): void
    {
        $this->world(); $r = $this->import(['A-1,05/07/2026,Ann,BOWL-01,Dog bowl,1,9.00,RM', 'A-2,06/07/2026,Bob,BOWL-01,Dog bowl,1,9.00,RM', 'A-3,07/07/2026,Cy,BOWL-01,Dog bowl,1,9.00,RM']);
        $rm = DB::table('t_carriers')->insertGetId($this->fill('t_carriers', ['fk_id_ent' => 1, 'carrier_name' => 'RM', 'f_active' => 1])); $dpd = DB::table('t_carriers')->insertGetId($this->fill('t_carriers', ['fk_id_ent' => 1, 'carrier_name' => 'DPD', 'f_active' => 1]));
        $v = fn (int $car, string $n) => DB::table('t_carrier_pkg_svc')->insertGetId(['pkg_svc_name' => $n, 'fk_id_carrier' => $car, 'fk_id_car_package' => 0, 'fk_id_car_service' => 0, 'rate' => 2]);
        $a = $v($rm, 'RM 48'); $b = $v($rm, 'RM 24'); $c = $v($dpd, 'DPD Next');
        foreach (['A-1' => $a, 'A-2' => $b, 'A-3' => $c] as $no => $svc) DB::table('t_sales')->where('order_no', $no)->update(['fk_id_car_pkg_svc' => $svc]);
        $nos = fn (array $x) => collect(SaleLines::list($this->f($x), $this->owner)['rows'])->pluck('order_no')->sort()->values()->all();
        $this->assertSame(['A-1', 'A-2'], $nos(['carrier' => "car:$rm"]));      // every service of the carrier
        $this->assertSame(['A-2'], $nos(['carrier' => "svc:$b"]));
        $inv = DB::table('t_account')->insertGetId($this->fill('t_account', ['fk_id_ent' => 1, 'first_name' => 'Ivy', 'sur_name' => 'Investor', 'f_active' => 1])); DB::table('t_product_investors')->insert(['fk_id_product' => $this->product, 'fk_id_account' => $inv]);
        $this->assertCount(3, $nos(['investor' => $inv])); $this->assertSame([], $nos(['investor' => $inv + 99]));
        $d = SaleLines::list($this->f(), $this->owner); $this->assertSame('Ivy Investor', $d['rows'][0]['investor']);
        $l = $d['lists']; $this->assertSame('101A - July 2026', $l['batches'][0]['name']); $this->assertSame(["car:$dpd", "car:$rm"], array_column($l['carriers'], 'id')); $this->assertSame(['Central (Pool)', 'Private'], array_column($l['visibility'], 'name')); $this->assertSame('Ivy Investor', $l['investors'][0]->name);
        // one-batch view: only the lines of that import, no marketplace needed, dates not applied
        $one = SaleLines::list(['batch' => $r['batch']], $this->owner); $this->assertSame(3, $one['total']); $this->assertArrayNotHasKey('need', $one);
        $this->assertSame('Select a Marketplace (and a Shop, or all shops) to show the sales.', SaleLines::list([], $this->owner)['need']);
    }

    public function test_confirm_locks_the_batch_and_the_owner_unlock_brings_the_stock_back(): void
    {
        $this->world(); DB::table('t_branches')->where('id', $this->shop)->update(['fulfilment_channel' => 'SELF']);
        $loc = StockService::systemLocation(1, 'SHIPPED'); $bin = DB::table('cat_inventory_locations')->where('fk_id_ent', 1)->where(fn ($w) => $w->whereNull('kind')->orWhere('kind', 'BIN'))->value('id');
        if (! $bin) $bin = DB::table('cat_inventory_locations')->insertGetId($this->fill('cat_inventory_locations', ['fk_id_ent' => 1, 'location_code' => 'A-01', 'location_name' => 'A-01', 'f_active' => 1, 'f_sellable' => 1]));
        StockService::receive(1, [['product' => $this->product, 'qty' => 10, 'unit_cost' => 4.00, 'location' => $bin]], 'OPENING', null);
        $m = date('Y-m', strtotime('first day of last month')); [$y, $mo] = array_map('intval', explode('-', $m)); $d = date('d/m/Y', strtotime($m . '-05'));
        $r = $this->import(["U-1,$d,Ann,BOWL-01,Dog bowl,3,9.00,RM"], ['month' => $mo, 'year' => $y], 'x.csv'); $id = (int) DB::table('t_sales')->where('order_no', 'U-1')->value('id');
        $before = StockService::balance($this->product);
        SalesService::confirmMonth($this->shop, $m, true);
        $this->assertSame('Confirmed', DB::table('t_sale_imports')->where('id', $r['batch'])->value('status')); $this->assertSame([], SalesImport::register($this->owner)['rows']);      // leaves the Imports list
        $this->assertSame($before - 3, StockService::balance($this->product));
        // a confirmed line cannot be edited, deleted or imported again
        $row = SaleLines::list(['batch' => $r['batch']], $this->owner)['rows'][0]; $this->assertFalse($row['actions']['edit']['ok']); $this->assertFalse($row['actions']['delete']['ok']);
        try { SaleLines::deleteLine($id); $this->fail('no error'); } catch (\Throwable $e) { $this->assertStringContainsString("This line is confirmed and saved permanently, so it can't be deleted.", $e->getMessage()); }
        try { SalesImport::deleteBatch($r['batch'], $this->owner); $this->fail('no error'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('confirmed and saved permanently', $e->getMessage()); }
        // unlock: Owner only, reason needed
        $mgr = \App\Models\User::find(DB::table('t_users')->insertGetId($this->fill('t_users', ['fk_id_ent' => 1, 'login_id' => 'mgr@demo.test', 'display_name' => 'M', 'role_code' => 'MANAGER', 'f_active' => 1])));
        try { SalesService::unlockMonth($this->shop, $m, 'wrong SKU', $mgr); $this->fail('no error'); } catch (\Throwable $e) { $this->assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $e); }
        try { SalesService::unlockMonth($this->shop, $m, ' ', $this->owner); $this->fail('no error'); } catch (\InvalidArgumentException $e) { $this->assertSame('Write the reason for the unlock.', $e->getMessage()); }
        $tb = fn () => round((float) DB::table('gl_lines')->sum('debit') - (float) DB::table('gl_lines')->sum('credit'), 2);
        $u = SalesService::unlockMonth($this->shop, $m, 'wrong SKU', $this->owner);
        $this->assertSame([1, 3], [$u['lines'], $u['units']]); $this->assertSame($before, StockService::balance($this->product));      // stock comes back
        $s = DB::table('t_sales')->where('id', $id)->first(); $this->assertSame(['Pending', 0, 0], [$s->status, (int) $s->f_locked, (int) $s->f_stock_deducted]);
        $this->assertSame('Pending', DB::table('t_sale_imports')->where('id', $r['batch'])->value('status'));
        $h = collect(SaleLines::history($id))->pluck('action')->all(); $this->assertContains('Unlock', $h);      // the history shows the unlock
        $this->assertSame(1, DB::table('imp_batch_effects')->where('table_name', 't_sales')->where('record_id', $id)->count());      // the version before the unlock is kept
        $this->assertSame(0.0, $tb()); $this->assertSame(0.0, round((float) DB::table('gl_lines as l')->join('gl_accounts as a', 'a.id', '=', 'l.account_id')->where('a.code', '4000')->sum(DB::raw('l.credit - l.debit')), 2));      // revenue reversed
        $this->assertSame([], StockService::selfCheck(1)['mismatches'] ?? []);
        // it can be confirmed again; a month closed in Month Close is refused
        SalesService::confirmMonth($this->shop, $m, true); $this->assertSame($before - 3, StockService::balance($this->product));
        DB::table('aud_runs')->insert($this->fill('aud_runs', ['run_code' => 'T-1', 'fk_id_ent' => 1, 'fk_id_branch' => $this->shop, 'period_month' => $m, 'version' => 1, 'status' => 'LOCKED']));
        try { SalesService::unlockMonth($this->shop, $m, 'again', $this->owner); $this->fail('no error'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('closed in Month Close', $e->getMessage()); }
        // the right can never be handed to a role
        try { \App\Services\Perm::setRole('MANAGER', 'orders.saleUnlock', true, (int) $this->owner->id); $this->fail('no error'); } catch (\Throwable $e) { $this->assertStringContainsString('stays with the Owner', $e->getMessage()); }
    }

    public function test_a_large_export_runs_in_the_background_and_is_sent_when_ready(): void
    {
        $this->world(); $this->import(['A-1,05/07/2026,Ann,BOWL-01,Dog bowl,1,9.00,RM', 'A-2,06/07/2026,Bob,BOWL-01,Dog bowl,1,9.00,RM', 'A-3,07/07/2026,Cy,BOWL-01,Dog bowl,1,9.00,RM']);
        $this->assertFalse(SaleView::exportCheck($this->f(), 'xlsx', $this->owner)['background']);
        \App\Services\Settings::set('SALES_EXPORT_BACKGROUND_LINES', 100, 1); \App\Services\Settings::flush(); DB::table('t_sales')->update(['raw_csv' => null]); $one = (array) DB::table('t_sales')->first(); unset($one['id']); $bulk = [];
        for ($i = 0; $i < 120; $i++) $bulk[] = ['order_no' => "B-$i"] + $one; DB::table('t_sales')->insert($bulk);
        $c = SaleView::exportCheck($this->f(), 'xlsx', $this->owner); $this->assertTrue($c['background']); $this->assertStringContainsString('prepared in the background', $c['message']);
        $q = $this->postJson('/api/sales/export/queue', $this->f(['format' => 'xlsx']))->assertOk()->json(); $this->get('/api/sales/export/job/' . $q['job'])->assertStatus(404);      // not ready yet
        $this->assertSame(1, SaleView::runJobs()); $j = DB::table('mo_export_jobs')->where('id', $q['job'])->first(); $this->assertSame(['DONE', 123], [$j->status, (int) $j->lines]); $this->assertSame(0, SaleView::runJobs());
        $this->assertStringContainsString('Your sales export is ready', (string) DB::table('tm_notifications')->where('user_id', $this->owner->id)->orderByDesc('id')->value('text'));
        if (filter_var($this->owner->login_id, FILTER_VALIDATE_EMAIL)) $this->assertSame(1, DB::table('email_outbound')->where('link_table', 'mo_export_jobs')->where('link_id', $q['job'])->count());
        $this->actingAs($this->owner); $this->get('/api/sales/export/job/' . $q['job'])->assertOk();
        $other = \App\Models\User::find(DB::table('t_users')->insertGetId($this->fill('t_users', ['fk_id_ent' => 1, 'login_id' => 'o2@demo.test', 'display_name' => 'O2', 'role_code' => 'OWNER', 'f_active' => 1]))); $this->actingAs($other); $this->get('/api/sales/export/job/' . $q['job'])->assertStatus(404);      // only the person who asked
        @unlink(storage_path('app/' . $j->file_path)); @rmdir(dirname(storage_path('app/' . $j->file_path)));
    }
}
