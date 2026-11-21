<?php

namespace Tests\Feature;

use App\Services\DsMatch;
use App\Services\Parse;
use App\Services\RecordService;
use App\Services\SaleLines;
use App\Services\SalesImport;
use App\Services\SalesReports;
use App\Services\SchemaCheck;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Small parts that complete RULES_A_SALES rules 8, 13, 17, 21, 25. */
class SalesGapsTest extends TestCase
{
    private int $shop; private int $mp; private int $va; private array $files = [];

    private function fill(string $t, array $d): array { return RecordService::fillNotNull($t, $d); }

    private function world(): void
    {
        $this->actingAs($this->owner);
        $this->mp = DB::table('cat_marketplaces')->insertGetId($this->fill('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'Ebay', 'marketplace_descr' => 'eBay', 'f_active' => 1]));
        $this->va = DB::table('t_account')->insertGetId($this->fill('t_account', ['fk_id_ent' => 1, 'first_name' => 'Vera', 'sur_name' => 'Seller', 'login_id' => 'vera@demo.test', 'f_active' => 1]));
        $this->shop = DB::table('t_branches')->insertGetId($this->fill('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $this->mp, 'shop_no' => '101A', 'shop_name' => 'Pets', 'iso_branch' => 'P1', 'f_active' => 1, 'fulfilment_channel' => 'OTHER', 'fk_id_seller' => $this->va]));
        SalesImport::saveColumns($this->mp, 'SALE', ['order_no' => 'Order number', 'order_date' => 'Sale date', 'cust_name' => 'Buyer', 'cust_contacts' => 'Contacts', 'sku' => 'Custom label', 'product_info' => 'Item title', 'qty' => 'Quantity', 'sale_rate' => 'Sold for']);
    }

    private function import(array $lines, array $d = [], string $name = '101A_july26.csv', bool $apply = true): array
    {
        $p = tempnam(sys_get_temp_dir(), 'sg'); $this->files[] = $p; $h = fopen($p, 'w'); fputcsv($h, ['Order number', 'Sale date', 'Buyer', 'Contacts', 'Custom label', 'Item title', 'Quantity', 'Sold for'], ',', '"', '\\'); foreach ($lines as $l) fputcsv($h, $l, ',', '"', '\\'); fclose($h);
        return SalesImport::run($p, $name, filesize($p), $d + ['nature' => 'Normal', 'marketplace' => $this->mp, 'shop' => $this->shop, 'month' => 7, 'year' => 2026, 'fee' => '0'], $this->owner, $apply);
    }

    protected function tearDown(): void { foreach ($this->files as $f) @unlink($f); parent::tearDown(); }

    public function test_schema_check_refuses_and_names_the_migration_file(): void
    {
        // as if the column t_sales.sale_source and the table mo_sku_aliases were missing
        $probe = fn (string $t, ?string $c = null) => ! (($t === 't_sales' && $c === 'sale_source') || ($t === 'mo_sku_aliases' && $c === null));
        $this->assertSame(['column t_sales.sale_source', 'table mo_sku_aliases'], SalesImport::selfCheck($probe));
        $msg = SalesImport::selfCheckMessage($probe); $this->assertStringStartsWith('The database is not up to date for the sales module. Run these files, in this order', $msg);
        $this->assertStringContainsString('2026_11_12_000008_mo_sales_history.php (missing: column t_sales.sale_source)', $msg); $this->assertStringContainsString('2026_11_21_000023_sales_import.php (missing: table mo_sku_aliases)', $msg);
        $this->assertLessThan(strpos($msg, '000023'), strpos($msg, '000008'));      // in the order they must run
        $this->assertTrue(is_file(database_path('migrations/2026_11_12_000008_mo_sales_history.php'))); $this->assertNull(SalesImport::selfCheckMessage());
        // System › Health: per module, the migrations that did not run and what the module's check misses
        $m = SchemaCheck::missing(['2026_11_21_000023_sales_import.php', '2026_11_21_000019_permissions.php'], ['2026_11_21_000019_permissions'], $probe);
        $this->assertSame(['Sales'], array_keys($m)); $this->assertSame('2026_11_21_000023_sales_import.php', $m['Sales'][0]['file']); $this->assertContains('table mo_sku_aliases', $m['Sales'][0]['missing']); $this->assertCount(2, $m['Sales']);
        $this->assertSame([], SchemaCheck::missing()); $this->actingAs($this->owner); $this->getJson('/api/health')->assertOk()->assertJsonPath('schema', []);
    }

    public function test_the_date_format_is_shown_and_remembered_per_marketplace(): void
    {
        $this->assertSame(['day/month/yyyy', 'month/day/yyyy', 'year-month-day', 'day month-name year', 'Excel day number', 'weekday, day month-name year'], array_map([Parse::class, 'dateFormat'], ['05/07/2026', '07/25/2026', '2026-07-05 10:00', '5-Jul-26', '46208', 'Sat, 5th Jul 2026 14:02 BST']));
        $this->world(); $r = $this->import([['A-1', '05/07/2026', 'Ann', '', 'S1', 'T', '1', '9'], ['A-2', '06/07/2026', 'Bob', '', 'S2', 'T', '1', '9']]);
        $this->assertSame(['Order Date', 'day/month/yyyy', null, 'Order Date read as day/month/yyyy'], [$r['date_format']['field'], $r['date_format']['read_as'], $r['date_format']['last'], $r['date_format']['text']]);
        $r2 = $this->import([['B-1', '2026-08-05', 'Ann', '', 'S1', 'T', '1', '9']], ['month' => 8], '101A_aug26.csv', false);      // Check shows it before anything is saved
        $this->assertSame('day/month/yyyy', $r2['date_format']['last']); $this->assertContains('Order Date is read as year-month-day; the last file of this marketplace was day/month/yyyy – check the dates of a few lines', $r2['warnings']);
        $r3 = $this->import([['C-1', '05/08/2026', 'Ann', '', 'S1', 'T', '1', '9'], ['C-2', '2026-08-06', 'Bob', '', 'S2', 'T', '1', '9']], ['month' => 8], '101A_aug26.csv', false);
        $this->assertStringContainsString('Order Date is written in 2 ways in this file', implode('|', $r3['warnings'])); $this->assertSame('Order Date read as day/month/yyyy (as last time)', $this->import([['D-1', '05/08/2026', 'Ann', '', 'S1', 'T', '1', '9']], ['month' => 8], '101A_aug26.csv', false)['date_format']['text']);
    }

    public function test_daily_totals_are_shown_apart_in_the_list_and_the_annual_report(): void
    {
        $this->world(); $this->import([['A-1', '05/07/2026', 'Ann', '', 'S1', 'T', '1', '9']]);
        $p = tempnam(sys_get_temp_dir(), 'sg'); $this->files[] = $p; file_put_contents($p, "Date,Sale,Purchase,Qty\n05/07/2026,100.00,60.00,10\n");
        SalesImport::run($p, '101A_july26.csv', filesize($p), ['nature' => 'Totals', 'marketplace' => $this->mp, 'shop' => $this->shop, 'month' => 7, 'year' => 2026, 'fee' => '0'], $this->owner, true);
        $f = ['marketplace' => $this->mp, 'from' => '2026-07-01', 'to' => '2026-07-31'];
        $this->assertSame([1, 9.0], [SaleLines::list($f, $this->owner)['total'], SaleLines::list($f, $this->owner)['totals']['sale']]);      // the order lines never include the daily totals
        $s = SaleLines::list($f + ['type' => 'summary'], $this->owner); $this->assertSame([1, 100.0], [$s['total'], $s['totals']['sale']]);
        $a = SalesReports::annual(['year' => 2026], $this->owner); $this->assertSame(9.0, $a['rows'][6]['sale']); $this->assertSame([100.0, 60.0, 10, 40.0], [$a['summary']['rows'][6]['sale'], $a['summary']['rows'][6]['buy'], $a['summary']['rows'][6]['qty'], $a['summary']['rows'][6]['profit']]);
        $this->assertTrue($a['summary']['any']); $this->assertSame(100.0, $a['summary']['total']['sale']); $this->assertSame(9.0, $a['total']['sale']);
    }

    public function test_tied_supplier_orders_are_listed_for_a_manual_pick(): void
    {
        $this->world(); $plat = DB::table('drp_platforms')->insertGetId($this->fill('drp_platforms', ['platform_name' => 'Ali', 'f_active' => 1, 'created_at' => now()]));
        $item = fn (float $cost) => DB::table('drp_items')->insertGetId($this->fill('drp_items', ['fk_id_ent' => 1, 'fk_platform' => $plat, 'fk_id_marketplace' => $this->mp, 'fk_id_branch' => $this->shop, 'dup_key' => uniqid('k', true), 'order_no' => 'S-' . uniqid(), 'd_order' => '2026-07-04', 'item_title' => 'Thing', 'qty' => 1, 'total_cost' => $cost, 'currency' => 'GBP',
            'period_month' => 7, 'period_year' => 2026, 'ship_name' => 'Jane Buyer', 'ship_address' => '5 Oak Road', 'ship_city' => 'Derby', 'ship_postcode' => 'DE1 1AA', 'ship_phone' => '07700900555']));
        $a = $item(4.00); $b = $item(6.00);
        $r = $this->import([['J-1', '05/07/2026', 'Jane Buyer', '5 Oak Road, Derby, DE1 1AA', 'X', 'Other', '1', '10']]); $id = (int) DB::table('t_sales')->where('order_no', 'J-1')->value('id');
        $this->assertSame([$a, 1], [(int) DB::table('t_sales')->where('id', $id)->value('fk_drp_item'), $r['stats']['conflicts']]); $this->assertStringContainsString('same score', implode('|', $r['warnings']));
        $c = DsMatch::conflicts(['batch' => $r['batch']], $this->owner); $this->assertCount(1, $c['rows']); $this->assertSame([$id, $a, [$b]], [$c['rows'][0]['sale'], $c['rows'][0]['current']['id'], array_column($c['rows'][0]['others'], 'id')]); $this->assertSame('07700900555', $c['rows'][0]['others'][0]['phone']);
        try { DsMatch::pick($id, 999999, $this->owner); $this->fail('no error'); } catch (\InvalidArgumentException $e) { $this->assertSame('That supplier order is not one of the equal matches of this line.', $e->getMessage()); }
        DsMatch::pick($id, $b, $this->owner); $s = DB::table('t_sales')->where('id', $id)->first(); $this->assertSame([$b, 6.0, 4.0], [(int) $s->fk_drp_item, (float) $s->buy_amount, (float) $s->profit]);      // cost and profit follow the picked order
        $this->assertSame([], DsMatch::conflicts(['batch' => $r['batch']], $this->owner)['rows']); $this->assertContains('Pick drop shipping item', collect(SaleLines::history($id))->pluck('action')->all());
        $this->getJson('/api/sales/ds-conflicts?batch=' . $r['batch'])->assertOk()->assertJsonPath('can_pick', true);
    }

    public function test_every_morning_the_shop_va_gets_the_lines_still_not_matched_once(): void
    {
        $this->world(); $u = DB::table('t_users')->insertGetId($this->fill('t_users', ['fk_id_ent' => 1, 'login_id' => 'vera@demo.test', 'display_name' => 'Vera', 'role_code' => 'VA', 'f_active' => 1]));
        $this->import([['N-1', '05/07/2026', 'Ann', '', 'S1', 'T', '1', '9'], ['N-2', '06/07/2026', 'Bob', '', 'S2', 'T', '1', '9']]);
        $this->assertSame(1, DsMatch::digest()); $this->assertSame(0, DsMatch::digest());      // once a day
        $this->assertSame('2 sale line(s) still not matched – 101A: 2', DB::table('tm_notifications')->where('user_id', $u)->orderByDesc('id')->value('text'));
        DB::table('t_sales')->update(['sale_source' => 'active']); DB::table('tm_notifications')->delete(); $this->assertSame(0, DsMatch::digest());      // nothing left → nothing sent
    }

    public function test_supplier_address_and_phone_are_masked_on_the_drop_shipping_list_record_and_export(): void
    {
        $this->world(); $plat = DB::table('drp_platforms')->insertGetId($this->fill('drp_platforms', ['platform_name' => 'Ali', 'f_active' => 1, 'created_at' => now()]));
        DB::table('drp_items')->insert($this->fill('drp_items', ['fk_id_ent' => 1, 'fk_platform' => $plat, 'fk_id_marketplace' => $this->mp, 'fk_id_branch' => $this->shop, 'dup_key' => 'k1', 'order_no' => 'S-1', 'd_order' => '2026-07-04', 'item_title' => 'Thing', 'qty' => 1, 'total_cost' => 4, 'currency' => 'GBP', 'ship_name' => 'Jane Buyer', 'ship_address' => '5 Oak Road', 'ship_city' => 'Derby', 'ship_postcode' => 'DE1 1AA', 'ship_phone' => '07700900555']));
        $row = ['ship_name' => 'Jane Buyer', 'ship_address' => '5 Oak Road', 'ship_phone' => '07700900555'];
        $this->assertSame($row, \App\Services\Vault::maskRow('drp_items', $row));      // the Owner sees them
        $va = \App\Models\User::find(DB::table('t_users')->insertGetId($this->fill('t_users', ['fk_id_ent' => 1, 'login_id' => 'va2@demo.test', 'display_name' => 'V', 'role_code' => 'VA', 'f_active' => 1]))); $this->actingAs($va); \App\Services\Perm::flush();
        $this->assertSame(['ship_name' => 'Jane Buyer', 'ship_address' => '•••', 'ship_phone' => '••••••555'], \App\Services\Vault::maskRow('drp_items', $row));
        $this->assertTrue(\App\Services\Vault::isMasked('•••')); $this->assertSame(['x' => 1], \App\Services\Vault::maskRow('t_sales', ['x' => 1]));
    }

    public function test_the_out_of_period_question_returns_at_month_close_with_a_reason(): void
    {
        $this->world(); $r = $this->import([['P-1', '30/06/2026', 'Ann', '', 'S1', 'T', '1', '9'], ['P-2', '29/06/2026', 'Bob', '', 'S2', 'T', '1', '9'], ['P-3', '02/07/2026', 'Cy', '', 'S3', 'T', '1', '9']]);
        $o = SalesImport::outOfPeriod($this->shop, '2026-07'); $this->assertSame([2, '2 line(s) of the July 2026 import are dated outside July 2026'], [$o['count'], $o['text']]);
        $page = \App\Services\CloseService::page(1, $this->shop, '2026-07'); $this->assertSame(2, $page['out_of_period']['count']);      // the Month Close page asks
        try { SalesImport::answerOutOfPeriod($this->shop, '2026-07', 'keep', ' ', $this->owner); $this->fail('no error'); } catch (\InvalidArgumentException $e) { $this->assertSame('Write the reason.', $e->getMessage()); }
        // keep: stays in July, flagged as answered, not asked again, still found by the "Out of period" preset
        $id1 = (int) DB::table('t_sales')->where('order_no', 'P-1')->value('id'); DB::table('t_sales')->where('order_no', 'P-2')->update(['f_out_of_period' => 0]);
        $a = SalesImport::answerOutOfPeriod($this->shop, '2026-07', 'keep', 'marketplace reports by payment date', $this->owner); $this->assertSame(1, $a['kept']); $this->assertSame(2, (int) DB::table('t_sales')->where('id', $id1)->value('f_out_of_period'));
        $this->assertSame(0, SalesImport::outOfPeriod($this->shop, '2026-07')['count']); $this->assertSame(1, SaleLines::list(['batch' => $r['batch'], 'show' => 'out'], $this->owner)['total']);
        $this->assertStringContainsString('kept in July 2026: marketplace reports by payment date', collect(SaleLines::history($id1))->pluck('new_value')->implode('|'));
        // move: the line goes to the batch of its own month
        DB::table('t_sales')->where('order_no', 'P-2')->update(['f_out_of_period' => 1]); $m = SalesImport::answerOutOfPeriod($this->shop, '2026-07', 'move', 'belongs to June', $this->owner); $this->assertSame(1, $m['moved']);
        $june = SalesImport::batchOf($this->shop, 6, 2026, 'Normal'); $this->assertSame([(int) $june->id, 0], [(int) DB::table('t_sales')->where('order_no', 'P-2')->value('fk_id_import'), (int) DB::table('t_sales')->where('order_no', 'P-2')->value('f_out_of_period')]);
        $this->postJson('/api/sales/out-of-period', ['shop' => $this->shop, 'month' => '2026-07', 'mode' => 'keep', 'reason' => 'again'])->assertStatus(422);      // nothing waits any more
    }

    public function test_the_va_share_is_its_own_line_on_the_month_close_page(): void
    {
        $this->world(); DB::table('t_account_job_history')->insert($this->fill('t_account_job_history', ['fk_id_account' => $this->va, 'fk_id_marketplace' => $this->mp, 'd_job_start_date' => '2020-01-01', 'share_percentage' => 10]));
        $r = $this->import([['V-1', '05/07/2026', 'Ann', '', 'S1', 'T', '1', '9']]); DB::table('t_sales')->where('order_no', 'V-1')->update(['sale_source' => 'active', 'profit' => 900, 'buy_amount' => 100, 'total_amount' => 1000]);
        $l = \App\Services\CloseService::page(1, $this->shop, '2026-07')['va_share']; $this->assertSame(['Vera Seller', 10.0, 90.0, false, null], [$l['va'], $l['pct'], $l['amount'], $l['approved'], $l['payable']]); $this->assertStringContainsString('no VA statement yet', $l['source']);
        $s = \App\Services\VaStatements::generate($this->shop, '2026-07', 0, null, $this->owner); \App\Services\VaStatements::approve($s['id'], $this->owner);
        $l = \App\Services\VaStatements::closeLine($this->shop, '2026-07'); $this->assertSame([90.0, true, 'payable, not paid yet'], [$l['amount'], $l['approved'], $l['payable']]);
        \App\Services\VaStatements::bankPaid(1, 'VA_SHARE_PAYOUT', $this->va, 90.00, 77); $this->assertTrue(\App\Services\VaStatements::closeLine($this->shop, '2026-07')['paid']);
        @unlink(storage_path('app/' . $s['file'])); @rmdir(dirname(storage_path('app/' . $s['file']))); $this->assertNull(\App\Services\VaStatements::closeLine($this->shop, '2025-01'));
    }

    public function test_a_refund_after_confirm_is_an_adjustment_line_of_the_next_open_month_and_never_edits_the_locked_line(): void
    {
        $this->world(); $id = DB::table('t_sales')->insertGetId($this->fill('t_sales', ['fk_id_ent' => 1, 'fk_id_branch' => $this->shop, 'fk_id_marketplace' => $this->mp, 'order_no' => 'C-1', 'd_order' => '2026-07-05', 'sku' => 'S', 'qty' => 1, 'sale_rate' => 40, 'total_amount' => 40, 'total_amount_gbp' => 40, 'status' => 'Confirmed', 'f_locked' => 1, 'sale_source' => 'active']));
        $before = (array) DB::table('t_sales')->where('id', $id)->first();
        \App\Services\Settings::set('GL_LOCKED_UPTO', date('Y-m'), 1); \App\Services\Settings::flush();      // even this month is locked → the first day of the next open month
        $next = date('Y-m-01', strtotime(date('Y-m-01') . ' +1 month')); $this->assertSame($next, \App\Services\Ledger::openDate(1));
        $x = \App\Services\SettlementService::refund(1, $id, 15.0, 'Arrived late'); $this->assertSame(['PARTIAL', 25.0], [$x['kind'], $x['left']]);
        $after = (array) DB::table('t_sales')->where('id', $id)->first(); $this->assertSame($before, $after);      // the locked line is untouched
        $c = DB::table('t_sale_charges')->where('fk_id_sale', $id)->first(); $this->assertSame(['REFUND_ADJ', -15.0, $next], [$c->kind, (float) $c->amount_gbp, substr((string) $c->d_charge, 0, 10)]);
        $this->assertSame($next, substr((string) DB::table('gl_journals')->where('source_type', 'REFUND')->where('source_id', $id)->value('journal_date'), 0, 10));
        $this->assertContains('Refund after Confirm', collect(SaleLines::history($id))->pluck('action')->all());
        try { \App\Services\SettlementService::refund(1, $id, 30.0, 'too much'); $this->fail('no error'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('25.00 still left', $e->getMessage()); }
    }

    public function test_a_return_after_the_month_is_locked_is_a_negative_correction_line_of_the_next_open_month(): void
    {
        $this->world(); DB::table('t_branches')->where('id', $this->shop)->update(['fulfilment_channel' => 'SELF']);
        $pid = DB::table('t_product_vendors')->insertGetId($this->fill('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'S1', 'product_name_vendor' => 'Thing', 'f_active' => 1, 'raw_price' => 4]));
        $bin = DB::table('cat_inventory_locations')->where('fk_id_ent', 1)->where(fn ($w) => $w->whereNull('kind')->orWhere('kind', 'BIN'))->value('id') ?: DB::table('cat_inventory_locations')->insertGetId($this->fill('cat_inventory_locations', ['fk_id_ent' => 1, 'location_code' => 'A-01', 'location_name' => 'A-01', 'f_active' => 1, 'f_sellable' => 1]));
        \App\Services\StockService::receive(1, [['product' => $pid, 'qty' => 5, 'unit_cost' => 4.00, 'location' => $bin]], 'OPENING', null);
        $m = date('Y-m', strtotime('first day of last month')); [$y, $mo] = array_map('intval', explode('-', $m));
        $this->import([['L-1', date('d/m/Y', strtotime($m . '-05')), 'Ann', '', 'S1', 'T', '2', '9']], ['month' => $mo, 'year' => $y], 'x.csv'); $id = (int) DB::table('t_sales')->where('order_no', 'L-1')->value('id');
        \App\Services\SalesService::confirmMonth($this->shop, $m, true); $orig = DB::table('fin_lot_consumption')->where('fk_id_sale', $id)->first();
        \App\Services\Settings::set('GL_LOCKED_UPTO', date('Y-m'), 1); \App\Services\Settings::flush(); $next = date('Y-m-01', strtotime(date('Y-m-01') . ' +1 month'));
        \App\Services\StockService::returnIn(1, $id, 1); $c = DB::table('fin_lot_consumption')->where('fk_id_sale', $id)->whereNotNull('fk_id_reverses')->first();
        $this->assertSame([-1, (int) $orig->id, $next], [(int) $c->qty, (int) $c->fk_id_reverses, substr((string) $c->consumed_on, 0, 10)]);
        $this->assertSame($next, substr((string) DB::table('gl_journals')->where('source_type', 'RETURN')->where('source_id', $id)->value('journal_date'), 0, 10));
    }
}
