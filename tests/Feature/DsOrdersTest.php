<?php

namespace Tests\Feature;

use App\Services\DsOrders;
use App\Services\DsUpload;
use App\Services\Perm;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_D_PRODUCTS R-D-85 (list, totals bar, item page, export) and R-D-79 (buyer privacy and rights). */
class DsOrdersTest extends TestCase
{
    private int $shop; private int $mp; private int $plat; private int $imp;

    private function world(): void
    {
        $this->actingAs($this->owner);
        $this->mp = DB::table('cat_marketplaces')->insertGetId(RecordService::fillNotNull('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'Ebay', 'marketplace_descr' => 'eBay', 'f_active' => 1]));
        $this->shop = DB::table('t_branches')->insertGetId(RecordService::fillNotNull('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $this->mp, 'shop_no' => '101A', 'shop_name' => 'Pets', 'iso_branch' => 'P1', 'f_active' => 1, 'fulfilment_channel' => 'OTHER']));
        $this->plat = DB::table('drp_platforms')->insertGetId(['platform_name' => 'AliExpress', 'f_active' => 1, 'created_at' => now()]);
        $this->imp = DB::table('drp_imports')->insertGetId(RecordService::fillNotNull('drp_imports', ['fk_id_ent' => 1, 'fk_platform' => $this->plat, 'fk_id_branch' => $this->shop, 'file_name' => 'one.csv', 'dup_mode' => 'skip', 'uploaded_by_name' => 'Owner', 'uploaded_at' => now(), 'period_month' => 7, 'period_year' => 2026]));
    }

    private function item(array $d): int
    {
        $id = DB::table('drp_items')->insertGetId(RecordService::fillNotNull('drp_items', $d + ['fk_id_ent' => 1, 'fk_platform' => $this->plat, 'fk_id_marketplace' => $this->mp, 'fk_id_branch' => $this->shop, 'fk_import' => $this->imp, 'fk_import_last' => $this->imp, 'dup_key' => sha1(uniqid('', true)), 'order_no' => 'S-' . uniqid(), 'd_order' => '2026-07-04', 'item_title' => 'Thing', 'qty' => 1,
            'total_cost' => 5, 'currency' => 'GBP', 'period_month' => 7, 'period_year' => 2026, 'created_at' => now()]));
        DB::table('drp_item_imports')->insert(['fk_item' => $id, 'fk_import' => $this->imp, 'action' => 'added']); return $id;
    }

    private function va(array $shops = []): object
    {
        $va = \App\Models\User::create(['login_id' => 'va-' . uniqid() . '@test', 'password_hash' => 'x', 'role_code' => 'VA', 'display_name' => 'VA', 'fk_id_ent' => 1, 'f_active' => 1]);
        if ($shops) Perm::setScope((int) $va->id, 'SHOP', $shops, (int) $this->owner->id); return $va;
    }

    public function test_totals_bar_filters_and_sorts(): void
    {
        $this->world(); $a = $this->item(['order_no' => 'AA-1', 'total_cost' => 10, 'd_order' => '2026-07-01']); $this->item(['order_no' => 'BB-2', 'total_cost' => 5, 'd_order' => '2026-07-09', 'ship_postcode' => 'ZZ9 9ZZ']); $this->item(['order_no' => 'CC-3', 'total_cost' => 8, 'currency' => 'USD', 'd_order' => '2026-07-05', 'item_title' => 'Blue mat']);
        $l = DsOrders::list([], $this->owner); $this->assertSame('3 rows · 3 items · £15.00 · $8.00', $l['totals']['text']); $this->assertSame(['BB-2', 'CC-3', 'AA-1'], array_column($l['rows'], 'order_no'));
        $this->assertSame(['AA-1', 'CC-3', 'BB-2'], array_column(DsOrders::list(['sort' => 'oldest'], $this->owner)['rows'], 'order_no')); $this->assertSame('AA-1', DsOrders::list(['sort' => 'cost'], $this->owner)['rows'][0]['order_no']);
        $this->assertSame(['BB-2'], array_column(DsOrders::list(['q' => 'zz9'], $this->owner)['rows'], 'order_no')); $this->assertSame(['CC-3'], array_column(DsOrders::list(['q' => 'blue'], $this->owner)['rows'], 'order_no'));
        $this->assertSame('2 rows · 2 items · £10.00 · $8.00', DsOrders::list(['from' => '01-07-2026', 'to' => '05-07-2026'], $this->owner)['totals']['text']); $this->assertSame(0, DsOrders::list(['range' => '7'], $this->owner)['totals']['rows']);
        $two = DB::table('drp_imports')->insertGetId(RecordService::fillNotNull('drp_imports', ['fk_id_ent' => 1, 'fk_platform' => $this->plat, 'fk_id_branch' => $this->shop, 'file_name' => 'two.csv', 'dup_mode' => 'replace', 'uploaded_by_name' => 'Owner', 'uploaded_at' => now()]));
        DB::table('drp_item_imports')->insert(['fk_item' => $a, 'fk_import' => $two, 'action' => 'replaced']); DB::table('drp_items')->where('id', $a)->update(['fk_import_last' => $two]);
        $l = DsOrders::list(['upload' => $two], $this->owner); $this->assertSame(['AA-1'], array_column($l['rows'], 'order_no')); $this->assertTrue($l['rows'][0]['replaced']); $this->assertSame("Upload #$two", $l['upload']['label']);
        $this->assertSame(0, DsOrders::list(['linked' => 'yes'], $this->owner)['totals']['rows']);
    }

    public function test_buyer_privacy_on_list_item_export_and_errors(): void
    {
        $this->world(); $id = $this->item(['order_no' => 'PP-1', 'ship_name' => 'John Smith', 'ship_address' => '1 High Street', 'ship_phone' => '07700900123', 'raw_json' => json_encode(['Phone' => '07700900123'])]);
        $va = $this->va([$this->shop]); $this->actingAs($va);
        $r = DsOrders::list([], $va)['rows'][0]; $this->assertSame('••••••123', $r['phone']); $this->assertSame('•••', $r['address']); $this->assertSame('John Smith', $r['buyer']);
        $it = DsOrders::item($id, $va); $this->assertSame('••••••123', $it['cards']['Shipped to']['Phone']); $this->assertSame('•••', $it['cards']['Shipped to']['Address']); $this->assertNull($it['raw']); $this->assertStringNotContainsString('07700900123', json_encode($it));
        DB::table('drp_import_errors')->insert(['fk_import' => $this->imp, 'row_no' => 3, 'reason' => 'Order number is empty.', 'raw_json' => json_encode(['Phone' => '07700900123'])]); $e = DsUpload::errors($this->imp, $va); $this->assertTrue($e['raw_hidden']); $this->assertNull($e['rows'][0]['raw']);
        $this->actingAs($this->owner); Perm::setUser((int) $va->id, 'orders.saleExport', 'GRANTED', 'test', null, (int) $this->owner->id); $this->actingAs($va); $csv = DsOrders::csv([], $va)['body']; $this->assertStringContainsString('••••••123', $csv); $this->assertStringNotContainsString('07700900123', $csv); $this->assertStringNotContainsString('1 High Street', $csv);
        try { DsOrders::delete($id, $va); $this->fail(); } catch (\Throwable $x) { $this->assertTrue(DB::table('drp_items')->where('id', $id)->exists()); }
        $this->actingAs($this->owner); $it = DsOrders::item($id, $this->owner); $this->assertSame('07700900123', $it['cards']['Shipped to']['Phone']); $this->assertSame(['Phone' => '07700900123'], $it['raw']);
        $other = $this->va([999999]); $this->actingAs($other); $this->assertSame(0, DsOrders::list([], $other)['totals']['rows']);      // shop scope on list and item
        try { DsOrders::item($id, $other); $this->fail(); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $x) { $this->assertSame(403, $x->getStatusCode()); }
    }

    public function test_item_page_linked_lines_and_delete(): void
    {
        $this->world(); $id = $this->item(['order_no' => 'LL-1', 'qty' => 3, 'unit_price' => 4, 'shipping_cost' => 1, 'total_cost' => 13, 'raw_json' => json_encode(['a' => 1, '_merged_lines' => [['a' => 2]]])]);
        $it = DsOrders::item($id, $this->owner); $this->assertSame(['Qty' => '3', 'Unit price' => '£4.00', 'Shipping' => '£1.00', 'Total cost (what we paid)' => '£13.00'], $it['kpis']); $this->assertSame('joins 2 lines of the file', $it['cards']['Upload']['Joins']); $this->assertSame('July 2026', $it['cards']['Upload']['Month']);
        $this->assertSame([], $it['lines']); $this->assertStringContainsString('matches 3 of the 5 buyer columns (name, address, city, postcode, phone)', $it['empty_lines']);
        $sale = DB::table('t_sales')->insertGetId(RecordService::fillNotNull('t_sales', ['fk_id_ent' => 1, 'fk_id_branch' => $this->shop, 'fk_id_marketplace' => $this->mp, 'order_no' => 'E-77', 'd_order' => '2026-07-05', 'cust_name' => 'Jo', 'sku' => 'X', 'qty' => 3, 'total_amount' => 10, 'buy_amount' => 13, 'profit' => -3, 'status' => 'Pending', 'sale_source' => 'ds', 'fk_drp_item' => $id, 'ds_match_count' => 4, 'created_at' => now()]));
        $it = DsOrders::item($id, $this->owner); $this->assertSame(['E-77', '-3.00', 'red', '4 / 5'], [$it['lines'][0]['order_no'], $it['lines'][0]['profit'], $it['lines'][0]['profit_colour'], $it['lines'][0]['matched']]);
        $this->assertSame('E-77', DsOrders::list([], $this->owner)['rows'][0]['sale']['order_no']); $this->assertSame(1, DsOrders::list(['linked' => 'yes'], $this->owner)['totals']['rows']);
        try { DsOrders::delete($id, $this->owner); $this->fail(); } catch (\InvalidArgumentException $x) { $this->assertStringContainsString('linked to sale line E-77', $x->getMessage()); }
        DB::table('t_sales')->where('id', $sale)->update(['fk_drp_item' => null, 'sale_source' => 'unmatched']); $this->assertSame('E-77', explode(' · ', DsOrders::saleChoices($id, 'E-7', $this->owner)['rows'][0]['label'])[0]);
        $this->assertStringContainsString('deleted', DsOrders::delete($id, $this->owner)['message']); $this->assertFalse(DB::table('drp_items')->where('id', $id)->exists()); $this->assertSame(1, DB::table('mo_recycle_bin')->where('table_name', 'drp_items')->where('row_id', $id)->count());
    }

    public function test_the_list_over_http(): void
    {
        $this->world(); $this->item(['order_no' => 'HH-1', 'total_cost' => 10, 'ship_phone' => '07700900123']);
        $this->getJson('/api/ds-orders?q=HH')->assertOk()->assertJsonPath('totals.text', '1 row · 1 item · £10.00')->assertJsonPath('rows.0.phone', '07700900123');
        $r = $this->get('/api/ds-orders?format=csv'); $r->assertOk(); $this->assertStringContainsString('HH-1', $r->getContent());
    }
}
