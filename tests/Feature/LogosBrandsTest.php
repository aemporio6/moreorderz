<?php

namespace Tests\Feature;

use App\Services\Logos;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Owner 27-11 09:26 / 09:30: a logo beside every marketplace, bank, carrier, app and own brand; My brands with each brand's products and sales. */
class LogosBrandsTest extends TestCase
{
    private function png(int $w = 64): string
    {
        $im = imagecreatetruecolor($w, $w); imagefill($im, 0, 0, imagecolorallocate($im, 200, 30, 30)); ob_start(); imagepng($im); return (string) ob_get_clean();
    }

    public function test_services_get_their_website_logo_and_the_owner_can_change_any(): void
    {
        $this->actingAs($this->owner);
        Http::fake(['https://www.ebay.co.uk/' => Http::response('<html><head><link rel="apple-touch-icon" sizes="180x180" href="/icons/touch.png"></head></html>'),
            'https://www.ebay.co.uk/icons/touch.png' => Http::response($this->png(180), 200, ['Content-Type' => 'image/png']), '*' => Http::response('', 404)]);
        Logos::sync(1);
        $ebay = DB::table('sys_logos')->where('kind', 'MARKETPLACE')->where('name', 'eBay')->first(); DB::table('sys_logos')->where('id', $ebay->id)->update(['domain' => 'www.ebay.co.uk']);
        $this->assertTrue(Logos::fetch((int) $ebay->id));
        $row = DB::table('sys_logos')->find($ebay->id); $this->assertSame('image/png', $row->mime); $this->assertStringContainsString('touch.png', $row->fetch_note); $this->assertSame(0, (int) $row->f_own);
        $boot = $this->getJson('/api/boot')->assertOk()->json('logos'); $this->assertContains('eBay', array_column($boot, 'n'));
        $this->get('/api/logo/' . $ebay->id)->assertOk()->assertHeader('Content-Type', 'image/png');
        // carriers of the tracking list have their row by themselves, with the domain of their tracking page
        $this->assertNotNull(DB::table('sys_logos')->where('kind', 'CARRIER')->whereNotNull('domain')->first());

        // own picture wins and is never fetched over; back to the website logo when asked
        $data = 'data:image/png;base64,' . base64_encode($this->png());
        $this->postJson('/api/logos/' . $ebay->id, ['image' => $data])->assertOk();
        $this->assertSame(1, (int) DB::table('sys_logos')->where('id', $ebay->id)->value('f_own')); $this->assertFalse(Logos::fetch((int) $ebay->id));
        $this->postJson('/api/logos/' . $ebay->id, ['site_icon' => 1])->assertOk(); $this->assertSame(0, (int) DB::table('sys_logos')->where('id', $ebay->id)->value('f_own'));

        // + Add, checks
        $this->postJson('/api/logos', ['name' => 'Local Courier Co', 'kind' => 'CARRIER', 'domain' => 'not a domain'])->assertStatus(422);
        $this->postJson('/api/logos', ['name' => 'Local Courier Co', 'kind' => 'CARRIER', 'domain' => 'https://localcourier.example/track', 'aliases' => 'lcc'])->assertOk();
        $this->assertSame('localcourier.example', DB::table('sys_logos')->where('name', 'Local Courier Co')->value('domain'));
        $this->postJson('/api/logos', ['name' => 'Local Courier Co', 'kind' => 'CARRIER'])->assertStatus(422);
        $this->postJson('/api/logos/' . $ebay->id, ['image' => 'data:image/svg+xml;base64,' . base64_encode('<svg onload="x()"></svg>')])->assertStatus(422);
        $this->postJson('/api/logos/' . $ebay->id, ['image' => 'data:image/png;base64,' . base64_encode('not a picture')])->assertStatus(422);
        $this->getJson('/api/logos')->assertOk()->assertJsonStructure(['kinds', 'rows', 'missing']);
        $this->actingAs($this->makeUser('MANAGER'))->getJson('/api/logos')->assertStatus(403);
        $this->actingAs($this->makeUser('MANAGER'))->postJson('/api/logos/' . $ebay->id, ['name' => 'x'])->assertStatus(403);
    }

    public function test_my_brands_shows_each_own_brand_with_its_products_and_sales(): void
    {
        $this->actingAs($this->owner);
        $co = (int) DB::table('cat_company_house')->insertGetId(RecordService::fillNotNull('cat_company_house', ['fk_id_ent' => 1, 'company_name' => 'Brand Co Ltd', 'f_active' => 1]));
        $mine = (int) DB::table('cat_brands')->insertGetId(RecordService::fillNotNull('cat_brands', ['fk_id_ent' => 1, 'brand_name' => 'TESTBRANDX', 'f_active' => 1, 'f_own' => 1, 'fk_id_company_house' => $co]));
        $theirs = (int) DB::table('cat_brands')->insertGetId(RecordService::fillNotNull('cat_brands', ['fk_id_ent' => 1, 'brand_name' => 'Someone Else', 'f_active' => 1, 'f_own' => 0]));
        $p = (int) DB::table('t_product_vendors')->insertGetId(RecordService::fillNotNull('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'TBX-' . uniqid(), 'product_name_vendor' => 'Brand mug', 'fk_id_brand' => $mine, 'stock' => 10, 'landing_price' => 2.5, 'f_active' => 1]));
        DB::table('t_sales')->insert(RecordService::fillNotNull('t_sales', ['fk_id_ent' => 1, 'order_no' => 'MB-1', 'd_order' => now()->subDays(3)->toDateString(), 'cust_name' => 'Jo', 'sku' => 'x', 'qty' => 3, 'total_amount' => 30, 'total_amount_gbp' => 30, 'profit' => 9, 'fk_product_id' => $p, 'status' => 'Confirmed']));
        $l = $this->getJson('/api/my-brands')->assertOk();
        $b = collect($l->json('brands'))->firstWhere('id', $mine); $this->assertTrue($b['own']); $this->assertSame([1, 10, 3], [$b['products'], $b['units'], $b['sold_30']]); $this->assertSame('Brand Co Ltd', $b['company']);
        $this->assertNotNull($b['logo_row'], 'an own brand gets its logo row by itself'); $this->assertNull($b['logo']);
        $o = $this->getJson('/api/my-brands/' . $mine)->assertOk(); $this->assertEquals(25.0, $o->json('stock_value')); $this->assertEquals(9.0, $o->json('profit_30')); $this->assertSame('Brand mug', $o->json('products.0.name'));
        $this->postJson('/api/logos/' . $b['logo_row'], ['image' => 'data:image/png;base64,' . base64_encode($this->png())])->assertOk();
        $this->assertNotNull(collect($this->getJson('/api/my-brands')->json('brands'))->firstWhere('id', $mine)['logo']);
        $this->assertContains('TESTBRANDX', array_column($this->getJson('/api/boot')->json('logos'), 'n'));
        // which brands are ours is the owner's choice
        $this->postJson("/api/my-brands/$theirs/own", ['own' => true])->assertOk(); $this->assertSame(1, (int) DB::table('cat_brands')->where('id', $theirs)->value('f_own'));
        $this->getJson('/api/my-brands/999999')->assertStatus(404);
        $this->actingAs($this->makeUser('MANAGER'))->postJson("/api/my-brands/$theirs/own", ['own' => false])->assertStatus(403);
    }
}
