<?php

namespace Tests\Feature;

use App\Services\OldImport;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Import of old data, on a tiny made-up "old" database (no real rows). Needs: GRANT ALL ON mo_old_test.* to the test login. */
class OldImportTest extends TestCase
{
    private static bool $ready = false;

    private static ?string $why = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (! self::$ready) $this->markTestSkipped('Cannot create the test "old" database mo_old_test: ' . self::$why);
    }

    protected function beforeTransaction(): void
    {
        if (self::$ready || self::$why) return;
        try {   // a second connection, so the test's own transaction is not touched by CREATE TABLE
            $c = config('database.connections.' . config('database.default'));
            $pdo = new \PDO("mysql:host={$c['host']};port={$c['port']}", $c['username'], $c['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('DROP DATABASE IF EXISTS mo_old_test'); $pdo->exec('CREATE DATABASE mo_old_test CHARACTER SET utf8mb4'); $pdo->exec('USE mo_old_test');
            $pdo->exec("CREATE TABLE cat_currency (id INT PRIMARY KEY, currency_code VARCHAR(10), currency_name VARCHAR(50), f_active TINYINT, old_only_col VARCHAR(10))");
            $pdo->exec("INSERT INTO cat_currency VALUES (901,'ZZA','Test A',1,'x'),(902,'ZZB','Test B',1,'x'),(903,'ZZA','Test A again',1,'x')");
            $pdo->exec("CREATE TABLE t_users (id INT PRIMARY KEY, login_id VARCHAR(100), pass VARCHAR(100), f_active TINYINT, created_at DATETIME)");
            $pdo->exec("INSERT INTO t_users VALUES (9001,'old.user@example.test','PLAIN-OLD-PASSWORD',1,'0000-00-00 00:00:00')");
            $pdo->exec("CREATE TABLE t_account_banks (id INT PRIMARY KEY, fk_id_account INT, account_no VARCHAR(40), card_cvv VARCHAR(10), atm_pin VARCHAR(10), f_active TINYINT)");
            $pdo->exec("INSERT INTO t_account_banks VALUES (9101,1,'12345678','999','1111',1)");
            $pdo->exec("CREATE TABLE ebay (id INT PRIMARY KEY, Buyer_name VARCHAR(50))"); $pdo->exec("INSERT INTO ebay VALUES (1,'Made Up Buyer'),(2,'Other Buyer')");
            $pdo->exec("CREATE TABLE t_product_vendors_backup (id INT PRIMARY KEY)"); $pdo->exec("INSERT INTO t_product_vendors_backup VALUES (1)");
            $pdo->exec("CREATE TABLE sidebar_modules (id_module INT PRIMARY KEY)"); $pdo->exec("INSERT INTO sidebar_modules VALUES (1)");
            $pdo->exec("CREATE TABLE empty_table (id INT PRIMARY KEY)");
            // part 2: shops 9501 (kept) and 9599 (deleted in the old tool), sales in two tables, a product with stock and cost, one with stock and no cost
            $pdo->exec("CREATE TABLE t_sale_orders_new (id INT PRIMARY KEY, fk_id_vendor_inventory INT, fk_id_branch INT, ebay_order_no VARCHAR(50), d_ebay_order DATETIME NULL, cust_name VARCHAR(80), cust_phone VARCHAR(40), cust_address VARCHAR(200), product_info VARCHAR(100), qty_ordered INT, sale_rate DECIMAL(10,2), c_grand_total DECIMAL(10,2), pur_rate DECIMAL(10,2), pur_amount DECIMAL(10,2), d_purchase DATE NULL, sku_ebay VARCHAR(50), product_profit DECIMAL(10,2), product_loss DECIMAL(10,2), sale_order_status VARCHAR(20), refunded_at DATETIME NULL, refunded_reason VARCHAR(100))");
            $pdo->exec("INSERT INTO t_sale_orders_new VALUES (1,0,9501,'A-1','2025-05-01 10:00:00','Made Up Buyer','07000000001','1 Made Up Road','Bowl',2,10,20,3,6,'2025-05-02','SKU-A',14,0,'Success',NULL,NULL),(2,0,9501,'A-2','2025-05-02 10:00:00','Second Buyer','07000000002','2 Made Up Road','Bowl',1,10,10,3,3,NULL,'SKU-A',0,0,'Refunded','2025-05-09 09:00:00','Changed mind'),"
                . "(3,0,9501,'A-1','2025-05-01 10:00:00','Made Up Buyer','','','Bowl',2,10,20,3,6,NULL,'SKU-A',0,0,'Success',NULL,NULL),(4,0,9599,'B-1','2025-05-03 10:00:00','X','','','Bowl',1,5,5,0,0,NULL,'SKU-B',0,0,'Success',NULL,NULL),(5,0,9501,'A-9',NULL,'X','','','Bowl',1,5,5,0,0,NULL,'SKU-B',0,0,'Success',NULL,NULL)");
            $pdo->exec("CREATE TABLE t_sale_orders_1 (id INT PRIMARY KEY, fk_id_branch INT, ebay_order_no VARCHAR(50), d_ebay_order DATETIME NULL, cust_name VARCHAR(80), cust_phone VARCHAR(40), cust_address VARCHAR(200), qty_ordered INT, sale_rate DECIMAL(10,2), c_grand_total DECIMAL(10,2), d_purchase DATE NULL, qty_pur INT, pur_rate DECIMAL(10,2), sku_ebay VARCHAR(50))");
            $pdo->exec("INSERT INTO t_sale_orders_1 VALUES (1,9501,'A-1','2025-05-01 00:00:00','Made Up Buyer','','',2,10,20,NULL,0,0,'SKU-A'),(2,9501,'OLD-7','2022-06-01 00:00:00','Old Buyer','','',3,4,12,NULL,3,1,'SKU-C')");
            $pdo->exec("CREATE TABLE t_stock_movements (id INT PRIMARY KEY, fk_id_product INT, qty_change INT)"); $pdo->exec("INSERT INTO t_stock_movements VALUES (1,9701,40)");
            $pdo->exec("CREATE TABLE t_product_vendors (id INT PRIMARY KEY, fk_id_ent INT, SKU_VENDOR VARCHAR(50), product_name_vendor VARCHAR(100), stock INT, raw_price DECIMAL(10,2), landing_price DECIMAL(10,2), f_active TINYINT)");
            $pdo->exec("INSERT INTO t_product_vendors VALUES (9701,1,'OLD-SKU-1','Old product one',40,2.00,2.50,1),(9702,1,'OLD-SKU-2','Old product two',10,0,0,1),(9703,1,'OLD-SKU-3','Old product three',0,1,1,1)");
            self::$ready = true;
        } catch (\Throwable $e) { self::$why = $e->getMessage(); }
    }

    public function test_master_rows_come_over_once_secrets_never_duplicates_are_merged_and_every_row_is_accounted_for(): void
    {
        $x = OldImport::run('mo_old_test', ['mask' => true]); $r = collect($x['rows'])->keyBy('old_table');
        $this->assertSame(['COPY', 'COPY', 'COPY', 'LATER', 'RETIRED', 'TOOL'], [$r['cat_currency']['plan'], $r['t_users']['plan'], $r['t_account_banks']['plan'], $r['ebay']['plan'], $r['t_product_vendors_backup']['plan'], $r['sidebar_modules']['plan']]);
        $this->assertArrayNotHasKey('empty_table', $r->all());
        // currency: two new codes come over, the repeated code is merged into the row that was kept and the id map is written
        $this->assertSame([3, 2, 1, 0], [$r['cat_currency']['old_rows'], $r['cat_currency']['imported'], $r['cat_currency']['merged'], $r['cat_currency']['failed']]);
        $this->assertSame(['Test A', 901], [DB::table('cat_currency')->where('id', 901)->value('currency_name'), (int) OldImport::mapped('cat_currency', 903)]);
        $this->assertSame(902, (int) OldImport::mapped('cat_currency', 902));
        $this->assertStringContainsString('old_only_col (no such column in the new tool)', $r['cat_currency']['left_out_columns']);
        // user: comes over without any password, zero date repaired
        $u = DB::table('t_users')->where('id', 9001)->first();
        $this->assertSame(['old.user@example.test', null], [$u->login_id, $u->password_hash]);
        $this->assertTrue($u->pass === null || $u->pass === '', 'the old plain password is never read');
        $this->assertStringContainsString('pass (secret – never read)', $r['t_users']['left_out_columns']);
        $this->assertStringContainsString('every user sets a new one', $r['t_users']['note']);
        // bank row: security code and PIN never read; the account number is masked in a development copy
        $b = DB::table('t_account_banks')->where('id', 9101)->first();
        $this->assertSame('••••••78', $b->account_no);
        $this->assertTrue(($b->card_cvv === null || $b->card_cvv === '') && ($b->atm_pin === null || $b->atm_pin === ''));
        $this->assertStringContainsString('account_no', $r['t_account_banks']['masked_columns']);
        // nothing of a transaction table is written in part 1
        $this->assertSame(0, DB::table('ebay')->whereIn('id', [1, 2])->where('Buyer_name', 'like', '%Buyer%')->count());
        // every old row is in exactly one bucket
        $t = $x['totals'];
        $this->assertSame($t['old_rows'], $t['imported'] + $t['already_there'] + $t['merged'] + $t['failed'] + $t['later'] + $t['retired'] + $t['tool']);
        $this->assertSame([8, 1, 1], [$t['to_copy'], $t['retired'], $t['tool']]);   // 3 currencies + 1 user + 1 bank row + 3 products
        // again: nothing twice
        $y = OldImport::run('mo_old_test', ['mask' => true])['totals'];
        $this->assertSame([0, 7, 1, 0], [$y['imported'], $y['already_there'], $y['merged'], $y['failed']]);
        $this->assertSame(1, DB::table('cat_currency')->where('currency_code', 'ZZA')->count());
        $this->assertSame(2, DB::table('mo_import_report')->where('old_table', 't_users')->count(), 'each run leaves its report');
    }

    public function test_part_two_history_tables_one_sales_list_and_opening_stock_that_equals_the_books(): void
    {
        $f = fn ($t, $d) => DB::table($t)->insertGetId(\App\Services\RecordService::fillNotNull($t, $d));
        $f('t_branches', ['id' => 9501, 'fk_id_ent' => 1, 'fk_id_marketplace' => 1, 'shop_no' => 'OS1', 'iso_branch' => 'OS1', 'shop_name' => 'Old Shop', 'f_active' => 1]);
        OldImport::run('mo_old_test', ['mask' => true]);
        $this->assertSame(0, (int) DB::table('t_product_vendors')->where('id', 9701)->value('stock'), 'part 1 brings the product, not its stock');
        $x = OldImport::transactions('mo_old_test', ['mask' => true, 'opening_date' => '2026-09-18']); $r = collect($x['rows'])->keyBy('old_table');
        // history copies, masked
        $this->assertSame(['HISTORY', 5, 5], [$r['t_sale_orders_new']['plan'], $r['t_sale_orders_new']['old_rows'], $r['t_sale_orders_new']['imported']]);
        $this->assertSame('•••••••••••er', '•••' . mb_substr((string) DB::table('ebay')->where('id', 1)->value('Buyer_name'), -10));
        $this->assertStringNotContainsString('Made Up', (string) DB::table('t_sale_orders_new')->where('id', 1)->value('cust_name'));
        $this->assertSame(['REPLACED', 0], [$r['t_stock_movements']['plan'], $r['t_stock_movements']['imported']]);
        // one sales list: A-1 once (the more trusted table wins, its repeat and the copy in the older table are merged), refund kept, no shop / no date left out
        $s = $r['t_sale_orders_new → sales list'];
        $this->assertSame([5, 2, 1, 2], [$s['old_rows'], $s['imported'], $s['merged'], $s['failed']]);
        $this->assertSame([2, 1, 1, 0], [$r['t_sale_orders_1 → sales list']['old_rows'], $r['t_sale_orders_1 → sales list']['imported'], $r['t_sale_orders_1 → sales list']['merged'], $r['t_sale_orders_1 → sales list']['failed']]);
        $this->assertStringContainsString('does not have the columns', $r['ebay → sales list']['note']);
        $h = DB::table('t_sales')->where('sale_source', 'history')->orderBy('order_no')->get();
        $this->assertSame(['A-1', 'A-2', 'OLD-7'], $h->pluck('order_no')->all());
        $this->assertSame(['Confirmed', 't_sale_orders_new', 20.0, 14.0, 1, '2025-05-01'], [$h[0]->status, $h[0]->legacy_table, (float) $h[0]->total_amount, (float) $h[0]->profit, (int) $h[0]->f_locked, $h[0]->d_order]);
        $this->assertSame(['Refunded', 'Changed mind'], [$h[1]->status, $h[1]->refund_reason]);
        $this->assertStringNotContainsString('Buyer', $h[0]->cust_name);
        $this->assertSame(0.0, \App\Services\ProfitService::view(1, 'shop', '2022-01-01', '2026-12-31')['total']['sales'], 'history lines are not in the profit figures');
        // opening stock: lots = old stock, value = books; the product without a cost gets a zero-cost lot and the report says so
        $o = $r['t_product_vendors.stock → opening lots'];
        $this->assertSame([2, 2], [$o['old_rows'], $o['imported']]);
        $this->assertStringContainsString('1 product(s) had NO cost', $o['note']);
        $this->assertSame([40, 10, 0], [(int) DB::table('t_product_vendors')->where('id', 9701)->value('stock'), (int) DB::table('t_product_vendors')->where('id', 9702)->value('stock'), (int) DB::table('t_product_vendors')->where('id', 9703)->value('stock')]);
        $this->assertSame([100.0, 100.0], [\App\Services\StockService::value(1), \App\Services\Ledger::balance(1, '1200')]);
        $this->assertSame(['OPENING', '2026-09-18', 2.5], [DB::table('fin_lots')->where('fk_id_product', 9701)->value('source'), DB::table('fin_lots')->where('fk_id_product', 9701)->value('received_date'), (float) DB::table('fin_lots')->where('fk_id_product', 9701)->value('unit_cost_gbp')]);
        $chk = \App\Services\StockService::selfCheck(1); $this->assertSame([0, true], [$chk['mismatch'], $chk['value_matches_ledger']]);
        // again: nothing twice
        $y = collect(OldImport::transactions('mo_old_test', ['mask' => true])['rows'])->keyBy('old_table');
        $this->assertSame([0, 0, 2, 3], [$y['t_sale_orders_new → sales list']['imported'], $y['t_product_vendors.stock → opening lots']['imported'], $y['t_product_vendors.stock → opening lots']['already_there'], DB::table('t_sales')->where('sale_source', 'history')->count()]);
        $this->assertSame(50, (int) DB::table('fin_lots')->sum('qty'));
    }

    public function test_dry_run_writes_no_data_and_without_mask_the_owner_gets_his_real_values(): void
    {
        $d = OldImport::run('mo_old_test', ['dry' => true]);
        $this->assertSame([0, 0], [$d['totals']['imported'], DB::table('t_users')->where('id', 9001)->count()]);
        $this->assertStringContainsString('3 row(s) would be imported', collect($d['rows'])->firstWhere('old_table', 'cat_currency')['note']);
        OldImport::run('mo_old_test', ['only' => ['t_account_banks']]);
        $this->assertSame('12345678', DB::table('t_account_banks')->where('id', 9101)->value('account_no'), 'live import: real value, still no security code or PIN');
        $this->assertSame(0, DB::table('t_users')->where('id', 9001)->count(), '--only limits the tables');
        try { OldImport::run(DB::getDatabaseName()); $this->fail('same database'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('are the same', $e->getMessage()); }
        try { OldImport::run('no_such_db_here'); $this->fail('no db'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('has no tables', $e->getMessage()); }
        $this->assertSame(['COPY', 'LATER', 'RETIRED', 'TOOL'], [OldImport::plan('t_branches'), OldImport::plan('t_sale'), OldImport::plan('t_import_options'), OldImport::plan('cat_rights')]);
    }
}
