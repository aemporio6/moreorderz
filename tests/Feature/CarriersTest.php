<?php

namespace Tests\Feature;

use App\Services\Carriers;
use App\Services\RecordService;
use App\Services\SaleLines;
use App\Services\SalesService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_A_SALES R-A_SALES-69 (carrier cost model, rates with a start date) and 70 (preset matrix with gaps, cheapest service). */
class CarriersTest extends TestCase
{
    private int $rm; private int $pkg; private int $s48; private int $s24; private int $preset;

    private function fill(string $t, array $d): array { return RecordService::fillNotNull($t, $d); }

    private function world(): void
    {
        $this->actingAs($this->owner);
        $this->rm = Carriers::saveCarrier(['name' => 'RM'], null, $this->owner)['id'];
        $this->pkg = Carriers::savePackage(['carrier' => $this->rm, 'name' => 'Small parcel', 'weight' => 2000, 'length' => 45, 'width' => 35, 'height' => 16], null, $this->owner)['id'];
        $this->s48 = Carriers::saveService(['carrier' => $this->rm, 'name' => '48'], null, $this->owner)['id']; $this->s24 = Carriers::saveService(['carrier' => $this->rm, 'name' => '24'], null, $this->owner)['id'];
        $this->preset = Carriers::savePreset(['carrier' => $this->rm, 'package' => $this->pkg, 'service' => $this->s48, 'name' => 'RM 48', 'rate' => '2.00'], null, $this->owner)['id'];
    }

    public function test_model_checks(): void
    {
        $this->world(); $msg = function (callable $fn) { try { $fn(); return 'ok'; } catch (\InvalidArgumentException $e) { return $e->getMessage(); } };
        $this->assertSame('Carrier name is required.', $msg(fn () => Carriers::saveCarrier(['name' => ' '], null, $this->owner))); $this->assertSame('The carrier name can have up to 150 characters.', $msg(fn () => Carriers::saveCarrier(['name' => str_repeat('a', 151)], null, $this->owner)));
        $this->assertSame('This carrier, package and service already have a preset.', $msg(fn () => Carriers::savePreset(['carrier' => $this->rm, 'package' => $this->pkg, 'service' => $this->s48, 'name' => 'Again', 'rate' => 1], null, $this->owner)));
        $this->assertSame('Rate must be an amount of 0 or more with up to 2 decimals, for example 2 or 2.20.', $msg(fn () => Carriers::savePreset(['carrier' => $this->rm, 'package' => $this->pkg, 'service' => $this->s24, 'name' => 'RM 24', 'rate' => '-1'], null, $this->owner)));
        // after create only the name and the rate change
        $this->assertSame('Carrier, package and service of a preset cannot be changed. Make a new preset instead.', $msg(fn () => Carriers::savePreset(['service' => $this->s24, 'name' => 'RM 48'], $this->preset, $this->owner)));
        Carriers::savePreset(['name' => 'RM 48 Tracked', 'rate' => '2.00'], $this->preset, $this->owner); $this->assertSame('RM 48 Tracked', DB::table('t_carrier_pkg_svc')->where('id', $this->preset)->value('pkg_svc_name'));
        // packing: name up to 100, rate 0 or more, shown as name-£rate
        $this->assertSame('The packing name can have up to 100 characters.', $msg(fn () => Carriers::savePacking(['name' => str_repeat('p', 101), 'rate' => 1], null, $this->owner))); $this->assertSame('Packing rate is required.', $msg(fn () => Carriers::savePacking(['name' => 'Bag'], null, $this->owner)));
        $this->assertSame('Bag-£0.25', Carriers::savePacking(['name' => 'Bag', 'rate' => '0.25'], null, $this->owner)['label']);
        // a Warehouse person may look but not change
        $wh = \App\Models\User::find(DB::table('t_users')->insertGetId($this->fill('t_users', ['fk_id_ent' => 1, 'login_id' => 'wh@demo.test', 'display_name' => 'W', 'role_code' => 'WAREHOUSE', 'f_active' => 1])));
        try { Carriers::saveCarrier(['name' => 'X'], null, $wh); $this->fail('no error'); } catch (\Throwable $e) { $this->assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $e); }
    }

    public function test_a_july_line_keeps_the_rate_of_july_after_the_rate_changes_in_august(): void
    {
        $this->world(); $pack = Carriers::savePacking(['name' => 'Bag', 'rate' => '0.25'], null, $this->owner)['id'];
        $mp = DB::table('cat_marketplaces')->insertGetId($this->fill('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'Ebay', 'marketplace_descr' => 'eBay', 'f_active' => 1]));
        $shop = DB::table('t_branches')->insertGetId($this->fill('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => '101A', 'shop_name' => 'Pets', 'iso_branch' => 'P1', 'f_active' => 1, 'fulfilment_channel' => 'OTHER']));
        DB::table('t_product_vendors')->insert($this->fill('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'BOWL-01', 'product_name_vendor' => 'Dog bowl', 'f_active' => 1, 'fk_id_car_pkg_svc' => $this->preset, 'fk_id_packing' => $pack, 'raw_price' => 4, 'sale_price' => 9]));
        // RM 48: 2.00 → 2.20 from 01-08; packing 0.25 → 0.40 from 01-08
        $r = Carriers::setRate('CARRIER', $this->preset, '2.20', '2026-08-01', $this->owner); Carriers::setRate('PACKING', $pack, '0.40', '2026-08-01', $this->owner);
        $this->assertSame([2.0, 2.0, 2.2, 2.2], [Carriers::rateOn('CARRIER', $this->preset, '2026-07-31'), Carriers::rateOn('CARRIER', $this->preset, '2026-01-01'), Carriers::rateOn('CARRIER', $this->preset, '2026-08-01'), Carriers::rateOn('CARRIER', $this->preset, '2026-12-01')]);
        $this->assertSame(2.2, (float) DB::table('t_carrier_pkg_svc')->where('id', $this->preset)->value('rate'));      // the list shows today's rate (today is after 1 August 2026)
        $july = SalesService::intake($shop, [['order_no' => 'J-1', 'd_order' => '2026-07-10', 'sku' => 'BOWL-01', 'qty' => 1, 'sale_rate' => 12.99]])['ids'][0]; $aug = SalesService::intake($shop, [['order_no' => 'A-1', 'd_order' => '2026-08-10', 'sku' => 'BOWL-01', 'qty' => 1, 'sale_rate' => 12.99]])['ids'][0];
        $this->assertSame([2.0, 0.25], [(float) DB::table('t_sales')->where('id', $july)->value('carrier_rate'), (float) DB::table('t_sales')->where('id', $july)->value('packing_rate')]);
        $this->assertSame([2.2, 0.4], [(float) DB::table('t_sales')->where('id', $aug)->value('carrier_rate'), (float) DB::table('t_sales')->where('id', $aug)->value('packing_rate')]);
        // an edit of the July line still uses 2.00 (the options show the rates of the line's date)
        $opt = SaleLines::carrierOptions(DB::table('t_sales')->where('id', $july)->first()); $this->assertSame(2.0, collect($opt['services'])->firstWhere('id', $this->preset)['rate']); $this->assertSame('Bag-£0.25', collect($opt['packings'])->firstWhere('id', $pack)['label']);
        SaleLines::carrierPacking($july, ['fk_id_car_pkg_svc' => $this->preset, 'fk_id_packing' => 0], true); $this->assertSame(2.0, (float) DB::table('t_sales')->where('id', $july)->value('carrier_rate'));
        // history: newest first, with what is valid now; a rate that starts later waits for its day
        $h = Carriers::history('CARRIER', $this->preset); $this->assertSame([[2.2, 'now'], [2.0, 'before']], array_map(fn ($x) => [$x['rate'], $x['state']], $h)); $this->assertSame('the start', $h[1]['from']);
        $later = date('Y-m-d', strtotime('+10 days')); $m = Carriers::setRate('CARRIER', $this->preset, '2.50', $later, $this->owner); $this->assertStringContainsString('until then the old rate is used', $m['message']); $this->assertSame(2.2, $m['rate_today']);
        $this->assertSame('starts later', Carriers::history('CARRIER', $this->preset)[0]['state']); $this->assertSame(0, Carriers::applyDue());
    }

    public function test_matrix_shows_every_combination_and_the_gaps_and_the_cheapest_service(): void
    {
        $this->world(); $m = Carriers::matrix($this->owner, ['carrier' => $this->rm]);
        $this->assertSame([2, 1], [count($m['rows']), $m['gaps']]); $gap = collect($m['rows'])->firstWhere('preset', null); $this->assertSame(['24', 'Small parcel', 2000.0], [$gap['service_name'], $gap['package_name'], $gap['weight']]);
        // a new package without presets → one red row per service
        $big = Carriers::savePackage(['carrier' => $this->rm, 'name' => 'Medium parcel', 'weight' => 20000, 'length' => 61, 'width' => 46, 'height' => 46], null, $this->owner)['id'];
        $m = Carriers::matrix($this->owner, ['carrier' => $this->rm]); $this->assertSame(2, collect($m['rows'])->where('package', $big)->whereNull('preset')->count()); $this->assertSame(3, $m['gaps']);
        // "Add" on a gap, then cheapest for a weight / size
        Carriers::savePreset(['carrier' => $this->rm, 'package' => $big, 'service' => $this->s48, 'name' => 'RM 48 Medium', 'rate' => '5.00'], null, $this->owner); $p24 = Carriers::savePreset(['carrier' => $this->rm, 'package' => $this->pkg, 'service' => $this->s24, 'name' => 'RM 24', 'rate' => '3.10'], null, $this->owner)['id'];
        $this->assertSame($this->preset, Carriers::cheapest(500, 30, 20, 10)['id']); $this->assertSame('RM-RM 48 Medium', Carriers::cheapest(5000, 30, 20, 10)['label']); $this->assertSame('RM-RM 48 Medium', Carriers::cheapest(500, 60, 20, 10)['label']); $this->assertNull(Carriers::cheapest(30000));
        // a service offered only on one marketplace is not suggested for another
        Carriers::saveService(['name' => '48', 'offered_on' => [7]], $this->s48, $this->owner); $this->assertSame($p24, Carriers::cheapest(500, 30, 20, 10, 3)['id']); $this->assertSame($this->preset, Carriers::cheapest(500, 30, 20, 10, 7)['id']);
        // delete: unused → removed; used by a product → switched off and kept
        $this->assertFalse(Carriers::deletePreset($p24, $this->owner)['kept']);
        DB::table('t_product_vendors')->insert($this->fill('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'X-1', 'product_name_vendor' => 'X', 'f_active' => 1, 'fk_id_car_pkg_svc' => $this->preset])); $this->assertTrue(Carriers::deletePreset($this->preset, $this->owner)['kept']); $this->assertSame(0, (int) DB::table('t_carrier_pkg_svc')->where('id', $this->preset)->value('f_active'));
        $this->getJson('/api/carriers/matrix?carrier=' . $this->rm)->assertOk()->assertJsonPath('can_edit', true); $this->getJson('/api/carriers/rates/carrier/' . $this->preset)->assertOk()->assertJsonCount(1, 'rows');
        $this->postJson('/api/carriers/rate', ['kind' => 'carrier', 'id' => $this->preset, 'rate' => '2.10'])->assertOk(); $this->getJson('/api/carriers/cheapest?weight=5000')->assertOk()->assertJsonPath('best.label', 'RM-RM 48 Medium');
    }
}
