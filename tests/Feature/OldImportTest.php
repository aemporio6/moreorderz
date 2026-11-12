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
        $this->assertSame([9, 5, 2, 1, 1], [$t['old_rows'], $t['to_copy'], $t['later'], $t['retired'], $t['tool']]);
        // again: nothing twice
        $y = OldImport::run('mo_old_test', ['mask' => true])['totals'];
        $this->assertSame([0, 4, 1, 0], [$y['imported'], $y['already_there'], $y['merged'], $y['failed']]);
        $this->assertSame(1, DB::table('cat_currency')->where('currency_code', 'ZZA')->count());
        $this->assertSame(2, DB::table('mo_import_report')->where('old_table', 't_users')->count(), 'each run leaves its report');
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
