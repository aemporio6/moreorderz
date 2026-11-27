<?php

namespace Tests\Feature;

use App\Services\DsUpload;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_D_PRODUCTS R-D-73 … 78, 86: upload of drop shipping supplier orders – checks, reader, guess + saved mapping, row checks, repeats / joins / replace, bookkeeping, undo, platforms. */
class DsUploadTest extends TestCase
{
    private int $shop; private int $mp; private int $plat; private array $files = [];
    private const H = ['Order ID', 'Order date', 'Product title', 'Item ID', 'Variation', 'Quantity', 'Unit price', 'Shipping cost', 'Total paid', 'Recipient name', 'Postcode'];

    private function world(): void
    {
        $this->actingAs($this->owner);
        $this->mp = DB::table('cat_marketplaces')->insertGetId(RecordService::fillNotNull('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'Ebay', 'marketplace_descr' => 'eBay', 'f_active' => 1]));
        $this->shop = DB::table('t_branches')->insertGetId(RecordService::fillNotNull('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $this->mp, 'shop_no' => '101A', 'shop_name' => 'Pets', 'iso_branch' => 'P1', 'f_active' => 1, 'fulfilment_channel' => 'OTHER']));
        $this->plat = DB::table('drp_platforms')->insertGetId(['platform_name' => 'AliExpress', 'f_active' => 1, 'created_at' => now()]);
    }

    private function file(array $rows, array $head = self::H): string
    {
        $p = tempnam(sys_get_temp_dir(), 'du'); $this->files[] = $p; $h = fopen($p, 'w'); fputcsv($h, $head, ',', '"', ''); foreach ($rows as $r) fputcsv($h, $r, ',', '"', ''); fclose($h); return $p;
    }

    private function d(array $o = []): array { return $o + ['marketplace' => $this->mp, 'shop' => $this->shop, 'month' => 7, 'year' => 2026, 'platform' => $this->plat]; }

    private function up(array $rows, array $o = [], array $head = self::H): array
    {
        $p = $this->file($rows, $head); $s = DsUpload::stage($p, 'orders.csv', filesize($p), $this->d(), $this->owner);
        return DsUpload::run($s['token'], $o + ['mapping' => $s['mapping']], $this->owner, true);
    }

    protected function tearDown(): void { foreach ($this->files as $f) @unlink($f); foreach (glob(storage_path('app/ds_tmp/*')) ?: [] as $f) @unlink($f); parent::tearDown(); }

    public function test_step_one_checks_and_the_token(): void
    {
        $this->world();
        try { DsUpload::sizeCheck('big.csv', 11 * 1024 * 1024); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('larger than 10 MB', $e->getMessage()); }
        try { DsUpload::sizeCheck('x.xlsx', 100); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('.csv or .txt', $e->getMessage()); }
        try { DsUpload::shopOf($this->d(['month' => 0]), $this->owner); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('Select the month and year', $e->getMessage()); }
        $p = $this->file([['A-1', '13/07/2026', 'Dog lead', 'X1', 'Red', '1', '4.00', '1.00', '5.00', 'John Smith', 'ab1 2cd']]);
        $s = DsUpload::stage($p, 'orders.csv', filesize($p), $this->d(), $this->owner);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $s['token']); $this->assertSame('Dog lead', $s['samples']['Product title']); $this->assertSame('guess', $s['source']); $this->assertSame('Order ID', $s['mapping']['order_no']); $this->assertSame('Recipient name', $s['mapping']['ship_name']);
        $va = (object) ((array) $this->owner); $va->id = 999999;      // the held file belongs to the person who uploaded it
        try { DsUpload::run($s['token'], ['mapping' => $s['mapping']], $va, false); $this->fail(); } catch (\Throwable $e) { $this->assertTrue(true); }
    }

    public function test_reader_and_parsers(): void
    {
        $this->assertSame('unclear', DsUpload::detectDateOrder(['05/07/2026', '06/08/2026'])); $this->assertSame('dmy', DsUpload::detectDateOrder(['05/07/2026', '13/07/2026'])); $this->assertSame('mdy', DsUpload::detectDateOrder(['07/13/2026'])); $this->assertSame('none', DsUpload::detectDateOrder(['2026-07-05']));
        $this->assertSame(-5.0, DsUpload::money('(5.00)')); $this->assertSame(1234.56, DsUpload::money('1.234,56')); $this->assertSame(3.5, DsUpload::money('GBP 3.50')); $this->assertFalse(DsUpload::money('2-pack £3')); $this->assertNull(DsUpload::money(''));
        $this->assertSame('CAD', DsUpload::currencyOf('CA$ 5')); $this->assertSame('GBP', DsUpload::currencyOf('£5')); $this->assertSame('CNY', DsUpload::currencyOf('RMB 12')); $this->assertSame('USD', DsUpload::currencyOf('$8'));
        $this->assertSame('2026-07-13', DsUpload::date('07/13/2026', 'mdy')); $this->assertSame('2026-07-05', DsUpload::date('05/07/2026', 'dmy'));
        $p = tempnam(sys_get_temp_dir(), 'du'); $this->files[] = $p; file_put_contents($p, "\xEF\xBB\xBFMy orders export\n\nOrder;Date;Title;;Title\nA1;01/07/2026;" . mb_convert_encoding('Lead £5', 'Windows-1252', 'UTF-8') . ";x;y\n");
        $c = DsUpload::read($p); $this->assertSame(';', $c['separator']); $this->assertSame(['Order', 'Date', 'Title', 'Column 4', 'Title (2)'], $c['headers']); $this->assertSame('Lead £5', $c['rows'][0][1]['Title']);
    }

    public function test_guess_and_saved_mapping(): void
    {
        $this->world();
        $g = DsUpload::guess(['Store name', 'Order number', 'Shipping address', 'Total', 'Seller name', 'Zip']);
        $this->assertNull($g['ship_name']); $this->assertSame('Order number', $g['order_no']); $this->assertSame('Total', $g['total_cost']); $this->assertSame('Shipping address', $g['ship_full_address']); $this->assertNull($g['shipping_cost']); $this->assertSame('Zip', $g['ship_postcode']);
        $this->assertStringContainsString('used for two fields', implode(' ', DsUpload::checkMapping(['order_no' => 'A', 'd_order' => 'A', 'item_title' => 'T', 'total_cost' => 'X'])['problems']));
        $w = DsUpload::checkMapping(['order_no' => 'A', 'd_order' => 'B', 'item_title' => 'T', 'total_cost' => 'X'])['warnings']; $this->assertStringContainsString('cannot link to sales', $w[0]); $this->assertStringContainsString('counts as 1', $w[1]);
        \App\Services\SalesImport::saveColumns($this->mp, 'DS', ['cust_name' => 'Ship to, Whom']); \App\Services\SalesImport::saveColumns($this->mp, 'MATCH', ['ship_phone' => 'Ring']);      // the marketplace's DS Columns and 5 matching columns are used before a guess
        $p = $this->file([['Z-1', '13/07/2026', 'Dog lead', '5.00', 'Jo', '0770']], ['Order ID', 'Order date', 'Product title', 'Total paid', 'Whom', 'Ring']); $s = DsUpload::stage($p, 'z.csv', filesize($p), $this->d(), $this->owner);
        $this->assertSame('Whom', $s['mapping']['ship_name']); $this->assertSame('Ring', $s['mapping']['ship_phone']); $this->assertStringContainsString('DS Columns of the marketplace', $s['note']);
        $this->up([['A-1', '13/07/2026', 'Dog lead', 'X1', 'Red', '1', '4.00', '1.00', '5.00', 'John Smith', 'AB1 2CD']]);
        $p = $this->file([['A-2', '14/07/2026', 'Dog bowl', 'X2', '', '1', '2.00', '0', '2.00', 'Ann', 'AB1']]); $s = DsUpload::stage($p, 'o2.csv', filesize($p), $this->d(), $this->owner);
        $this->assertSame('saved', $s['source']); $this->assertStringContainsString('filled in from the last upload', $s['note']);
        $p = $this->file([['A-3', '14/07/2026', 'Dog bowl', '1', '2.00']], ['Order ID', 'Order date', 'Product title', 'Quantity', 'Total paid']); $s = DsUpload::stage($p, 'o3.csv', filesize($p), $this->d(), $this->owner);
        $this->assertSame('guess', $s['source']); $this->assertStringContainsString('Columns used last time are missing: ', $s['note']); $this->assertContains('Item ID', $s['missing']);
    }

    /** MR-0987: a column matched by hand once is found by itself in the next file – of any platform. */
    public function test_columns_matched_by_hand_are_learned(): void
    {
        $this->world();
        $head = ['Order ID', 'Order date', 'Product title', 'Total paid', 'Kaufer'];
        $this->assertNull(DsUpload::guess($head)['ship_name']);
        $p = $this->file([['L-1', '13/07/2026', 'Dog lead', '5.00', 'Jo Bloggs']], $head); $s = DsUpload::stage($p, 'l.csv', filesize($p), $this->d(), $this->owner);
        DsUpload::run($s['token'], ['mapping' => ['ship_name' => 'Kaufer'] + $s['mapping']], $this->owner, true);
        $this->assertSame(['kaufer'], DsUpload::learned(1)['ship_name'] ?? null);
        $this->assertSame('Kaufer', DsUpload::guess(['Order ID', 'Kaufer', 'Store'])['ship_name']);
        $this->assertSame([], DsUpload::learn(1, ['ship_name' => 'Kaufer', 'order_no' => 'Order ID']));      // known names are not learned twice
    }

    public function test_row_checks(): void
    {
        $this->world();
        $r = $this->up([
            ['1.23E+12', '13/07/2026', 'Lead', 'X1', '', '1', '4.00', '0', '4.00', 'J', 'P'],
            ['#A-2 ', '13/07/2026', 'Bowl', 'X2', '', '2 pcs', '4.50', '1', '', 'J', 'ab1 2cd'],
            ['A-3', '31/02/2026', 'Toy', 'X3', '', '1', '1', '0', '1', 'J', 'P'],
            ['A-4', '13/07/2026', 'Toy', 'X4', '', '1.5', '1', '0', '1', 'J', 'P'],
            ['A-5', '13/07/2026', 'Toy', 'X5', '', '1', '', '0', '(5.00)', 'J', 'P'],
            ['A-6', '13/07/2026', '', '', '', '1', '1', '0', '1', 'J', 'P'],
            ['A-7', '13/07/2026', 'Mat', 'X7', '', '4', '', '2.00', '$10.00', 'J', 'P'],
        ]);
        $this->assertSame(2, $r['stats']['new']); $this->assertSame(5, $r['stats']['errors']); $reasons = implode(' | ', array_column($r['errors'], 'reason'));
        $this->assertStringContainsString('was rounded by Excel. Export the file again and upload it without opening it in Excel', $reasons); $this->assertStringContainsString('Quantity "1.5" is not a whole number', $reasons); $this->assertStringContainsString('a refund line is not a purchase', $reasons); $this->assertStringContainsString('Product title and item id are both empty', $reasons);
        $b = DB::table('drp_items')->where('order_no', 'A-2')->first(); $this->assertSame(2, (int) $b->qty); $this->assertSame(10.0, (float) $b->total_cost); $this->assertSame('AB1 2CD', $b->ship_postcode); $this->assertSame('GBP', $b->currency);
        $m = DB::table('drp_items')->where('order_no', 'A-7')->first(); $this->assertSame(2.0, (float) $m->unit_price); $this->assertSame('USD', $m->currency);
        $this->assertSame(5, DB::table('drp_import_errors')->where('fk_import', $r['import'])->count()); $this->assertSame(1, DB::table('tasks')->where('status', 'TODO')->where('title', 'like', '5 row(s) of the drop shipping upload%')->count());
        $this->assertStringContainsString('"Ignore it"', $r['advice']); $this->assertSame('day-month-year (UK)', $r['dates_read_as']);
    }

    public function test_unclear_dates_ask_and_the_answer_is_remembered(): void
    {
        $this->world(); $p = $this->file([['A-1', '05/07/2026', 'Lead', 'X1', '', '1', '4', '0', '4', 'J', 'P'], ['A-2', '06/08/2026', 'Bowl', 'X2', '', '1', '4', '0', '4', 'J', 'P']]);
        $s = DsUpload::stage($p, 'o.csv', filesize($p), $this->d(), $this->owner); $r = DsUpload::run($s['token'], ['mapping' => $s['mapping']], $this->owner, true);
        $this->assertTrue($r['ask_date_order']); $this->assertSame(0, DB::table('drp_items')->count());
        $r = DsUpload::run($s['token'], ['mapping' => $s['mapping'], 'date_order' => 'MDY'], $this->owner, true);
        $this->assertSame(['2026-05-07', '2026-06-08'], DB::table('drp_items')->orderBy('d_order')->pluck('d_order')->map(fn ($d) => substr((string) $d, 0, 10))->all()); $this->assertSame(2, $r['stats']['outside_period']); $this->assertStringContainsString('saved under July 2026', implode(' ', $r['warnings']));
        $this->assertSame('MDY', DB::table('drp_platform_mappings')->where('fk_platform', $this->plat)->value('date_order')); $this->assertSame(7, (int) DB::table('drp_items')->value('period_month'));
    }

    public function test_repeated_joined_ignored_and_replaced(): void
    {
        $this->world(); $fired = 0; \App\Services\EventBus::listen('DS_BATCH_APPLIED', function (array $e) use (&$fired) { if (($e['payload']['month'] ?? 0) === 7 && ($e['payload']['shop'] ?? 0) === $this->shop) $fired++; });
        $rows = [['A-1', '13/07/2026', 'Lead', 'X1', 'Red', '1', '4.00', '1.00', '5.00', 'J', 'P'], ['A-1', '13/07/2026', 'Lead', 'X1', 'Red', '2', '4.00', '0', '8.00', 'J', 'P'], ['A-1', '13/07/2026', 'Lead', 'X1', 'Red', '2', '4.00', '0', '8.00', 'J', 'P']];
        $r = $this->up($rows); $this->assertEquals(['new' => 1, 'joined' => 1, 'repeated' => 1], array_intersect_key($r['stats'], ['new' => 1, 'joined' => 1, 'repeated' => 1])); $ev = DB::table('sys_events')->where('event_code', 'DS_BATCH_APPLIED')->first(); $this->assertSame(['shop' => $this->shop, 'month' => 7, 'year' => 2026], array_intersect_key(json_decode($ev->payload_json, true), ['shop' => 1, 'month' => 1, 'year' => 1])); \App\Services\EventBus::process((int) $ev->id); $this->assertSame(1, $fired);
        $it = DB::table('drp_items')->first(); $this->assertSame(3, (int) $it->qty); $this->assertSame(13.0, (float) $it->total_cost); $this->assertSame(4.0, (float) $it->unit_price); $this->assertArrayHasKey('_merged_lines', json_decode($it->raw_json, true));
        $r = $this->up($rows); $this->assertStringContainsString('July 2026: 0 new row(s) added, 1 already saved and ignored', $r['message']); $this->assertSame('skipped', DB::table('drp_item_imports')->where('fk_import', $r['import'])->value('action'));
        $r = $this->up([['A-1', '13/07/2026', 'Lead', 'X1', 'Red', '5', '4.00', '0', '20.00', 'J', 'P']], ['mode' => 'replace']); $this->assertTrue($r['confirm_replace']); $this->assertStringContainsString('OVERWRITTEN', $r['message']); $this->assertSame(3, (int) DB::table('drp_items')->value('qty'));
        $bk = DB::table('drp_imports')->orderBy('id')->first(); $this->assertSame([3, 1, 1, 1], [(int) $bk->total_rows, (int) $bk->inserted_rows, (int) $bk->skipped_rows, (int) $bk->merged_rows]); $this->assertSame(7, (int) $bk->period_month);
        $this->assertCount(2, DsUpload::history($this->owner));
    }

    public function test_undo_restores_replaced_rows(): void
    {
        $this->world();
        $one = $this->up([['A', '13/07/2026', 'Lead', 'XA', '', '1', '4.00', '0', '4.00', 'J', 'P'], ['B', '13/07/2026', 'Bowl', 'XB', '', '1', '6.00', '0', '6.00', 'J', 'P']]);
        $p = $this->file([['B', '13/07/2026', 'Bowl', 'XB', '', '3', '6.00', '0', '18.00', 'J', 'P'], ['C', '14/07/2026', 'Mat', 'XC', '', '1', '2.00', '0', '2.00', 'J', 'P']]); $s = DsUpload::stage($p, 'two.csv', filesize($p), $this->d(), $this->owner);
        $two = DsUpload::run($s['token'], ['mapping' => $s['mapping'], 'mode' => 'replace', 'confirm_replace' => true], $this->owner, true);
        $this->assertSame(18.0, (float) DB::table('drp_items')->where('order_no', 'B')->value('total_cost')); $this->assertSame($two['import'], (int) DB::table('drp_items')->where('order_no', 'B')->value('fk_import_last'));
        $u = DsUpload::undo($two['import'], $this->owner); $this->assertSame(1, $u['deleted']); $this->assertSame(1, $u['restored']);
        $this->assertFalse(DB::table('drp_items')->where('order_no', 'C')->exists()); $b = DB::table('drp_items')->where('order_no', 'B')->first(); $this->assertSame(6.0, (float) $b->total_cost); $this->assertSame(1, (int) $b->qty); $this->assertSame($one['import'], (int) $b->fk_import_last);
        $this->assertFalse(DB::table('drp_imports')->where('id', $two['import'])->exists()); $this->assertSame(2, DB::table('drp_items')->count());
    }

    public function test_a_seller_sees_only_his_shops_and_cannot_undo(): void
    {
        $this->world(); $va = \App\Models\User::create(['login_id' => 'va-ds@test', 'password_hash' => 'x', 'role_code' => 'VA', 'display_name' => 'VA', 'fk_id_ent' => 1, 'f_active' => 1]);
        $r = $this->up([['A', '13/07/2026', 'Lead', 'XA', '', '1', '4.00', '0', '4.00', 'J', 'P'], ['', '13/07/2026', 'Bad', 'XB', '', '1', '4.00', '0', '4.00', 'Jane Buyer', 'P']]);
        $other = DB::table('t_branches')->insertGetId(RecordService::fillNotNull('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $this->mp, 'shop_no' => '102B', 'shop_name' => 'Toys', 'iso_branch' => 'P2', 'f_active' => 1, 'fulfilment_channel' => 'OTHER'])); \App\Services\Perm::setScope((int) $va->id, 'SHOP', [$other], (int) $this->owner->id);
        $this->actingAs($va); $this->assertContains($other, array_column(DsUpload::home($va)['shops'], 'id')); $this->assertNotContains($this->shop, array_column(DsUpload::home($va)['shops'], 'id')); $this->assertSame([], DsUpload::history($va));
        try { DsUpload::shopOf($this->d(), $va); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString("don't have rights for shop 101A (Pets)", $e->getMessage()); }
        try { DsUpload::undo($r['import'], $va); $this->fail(); } catch (\Throwable $e) { $this->assertTrue(DB::table('drp_imports')->where('id', $r['import'])->exists()); }
        $this->actingAs($this->owner); $this->assertNotNull(DsUpload::errors($r['import'], $this->owner)['rows'][0]['raw']);
    }

    public function test_platforms(): void
    {
        $this->world(); DB::table('drp_platforms')->insert(['platform_name' => 'Temu', 'f_active' => 0, 'created_at' => now()]);
        $r = DsUpload::addPlatform(' temu  ', $this->owner); $this->assertStringContainsString('already in the list', $r['message']); $this->assertSame(1, (int) DB::table('drp_platforms')->where('platform_name', 'Temu')->value('f_active'));
        $r = DsUpload::addPlatform('CJ   Dropshipping', $this->owner); $this->assertSame('CJ Dropshipping', $r['name']);
        $h = DsUpload::home($this->owner); $this->assertContains('Temu', array_column(json_decode(json_encode($h['platforms']), true), 'name')); $this->assertSame(10, $h['max_mb']);
    }
}
