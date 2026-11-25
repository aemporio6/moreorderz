<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\Fx;
use App\Services\HistoricalPurchase as H;
use App\Services\RecordService;
use App\Services\SrcNames;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-B_SOURCING-12 name matching (exact first, then contains), nothing created silently, the same WARN lines on form and import, "+ Add brand". */
class SrcNamesTest extends TestCase
{
    private function f(string $t, array $d): int { return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }

    public function test_b12_matching_log_lines_and_add_brand(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id); Fx::set('USD', '2025-03-01', 0.80);
        $long = $this->f('t_src_suppliers', ['fk_id_ent' => 1, 'supplier_code' => 'S-1', 'supplier_name' => 'Zentro Trading Company', 'f_active' => 1]);
        $exact = $this->f('t_src_suppliers', ['fk_id_ent' => 1, 'supplier_code' => 'S-2', 'supplier_name' => 'Zentro Trading', 'f_active' => 1]);
        $this->assertSame($exact, (int) SrcNames::supplier(1, ' zentro trading ')->id, 'the exact name wins over the lower id that only contains it');
        $this->assertSame($long, (int) SrcNames::supplier(1, 'Zentro')->id, 'then contains – the lowest id');
        $this->assertSame($long, (int) SrcNames::supplier(1, (string) $long)->id); $this->assertNull(SrcNames::supplier(1, 'Nobody Ltd'));
        $fw = $this->f('t_src_forwarders', ['fk_id_ent' => 1, 'forwarder_code' => 'F1', 'forwarder_name' => 'Sea Bridge Logistics', 'f_active' => 1]);
        $wh = $this->f('cat_inventory_locations', ['location_name' => 'Leeds Main Warehouse', 'kind' => 'WAREHOUSE', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1]);
        $own = $this->f('t_account', ['fk_id_ent' => 1, 'account_code' => 'OWN12', 'first_name' => 'Main', 'sur_name' => 'Owner', 'f_active' => 1]);
        $log = [];
        $this->assertSame($fw, SrcNames::forwarder(1, 'sea bridge', $log)); $this->assertNull(SrcNames::forwarder(1, 'Ghost Cargo', $log));
        $this->assertSame($own, SrcNames::owner(1, 'OWN12', $log)); $this->assertSame($own, SrcNames::owner(1, 'main owner', $log)); $this->assertNull(SrcNames::owner(1, 'Mr Nobody', $log));
        $this->assertNull(SrcNames::currency('XXZ', $log)); $this->assertSame($wh, SrcNames::warehouse(1, 'Leeds Main', $log)); $this->assertNull(SrcNames::warehouse(1, 'Moon base', $log, 'PI-1'));
        $this->assertSame(["WARN: forwarder 'Ghost Cargo' not found (Logistics role) - left empty", "WARN: owner entity 'Mr Nobody' not found in Setup > Investor Master - left empty, pick it on the cycle (never created silently)",
            "WARN: currency 'XXZ' not found in Setup > Currencies - stored without currency id", "[PI-1] WARN: warehouse 'Moon base' not found - lot location empty"], $log);

        // form / entry: an unknown supplier is an error, nothing is created
        $n = DB::table('t_src_suppliers')->count();
        try { H::entry(1, ['supplier_name' => 'Brand New Co', 'order_date' => '2025-03-01', 'order_ref' => 'PI-1', 'currency' => 'USD', 'owner' => $own, 'lines' => [['product_name' => 'Mug', 'qty' => 10, 'unit_price' => 2]]], $this->owner); $this->fail('supplier created silently'); }
        catch (\InvalidArgumentException $e) { $this->assertSame("Supplier 'Brand New Co' not found - add the supplier first (never created silently)", $e->getMessage()); }
        $this->assertSame($n, DB::table('t_src_suppliers')->count());

        // the rule: CSV row brand "Zentro" not in the list → the check step shows "+ Add brand Zentro"; apply without adding → saved with an empty brand + WARN
        $csv = "order_ref,supplier_name,order_date,currency,owner_entity,brand,product_name,qty,unit_price,forwarder,shipped_date\nPI-2,Zentro Trading,2025-03-01,USD,OWN12,Zentro,Mug,10,2.00,Ghost Cargo,2025-03-05\nPI-3,Nobody Ltd,2025-03-01,USD,OWN12,,Cup,5,1.00,,\n";
        $file = fn () => UploadedFile::fake()->createWithContent('old.csv', $csv); $brands = DB::table('cat_brands')->count();
        $c = collect($this->post('/api/old-entry/csv/check', ['file' => $file()], ['Accept' => 'application/json'])->assertOk()->json('entries'))->keyBy('ref');
        $this->assertSame(['Zentro', true, 'Zentro Trading'], [$c['PI-2']['add_brand'], $c['PI-2']['supplier_found'], $c['PI-2']['supplier']]);
        $this->assertContains("WARN: forwarder 'Ghost Cargo' not found (Logistics role) - left empty", $c['PI-2']['warn'], 'the same line on the check step');
        $this->assertSame(["Supplier 'Nobody Ltd' not found - add the supplier first (never created silently)"], $c['PI-3']['problems']);
        $this->assertSame($brands, DB::table('cat_brands')->count(), 'the check creates nothing');
        $r = $this->post('/api/old-entry/csv', ['file' => $file()], ['Accept' => 'application/json'])->assertOk()->json();
        $cy = DB::table('t_src_cycles')->where('order_ref', 'PI-2')->first(); $this->assertNotNull($cy); $this->assertNull($cy->brand);
        $this->assertSame($brands, DB::table('cat_brands')->count(), 'no brand auto-created');
        $all = implode("\n", $r['log']); $this->assertStringContainsString("[PI-2] WARN: brand 'Zentro' not found", $all); $this->assertStringContainsString("[PI-3] FAILED: Supplier 'Nobody Ltd' not found", $all);
        $this->assertSame(0, DB::table('t_src_cycles')->where('order_ref', 'PI-3')->count());
        // "+ Add brand" – added knowingly, once
        $a = $this->postJson('/api/src/brands/add', ['name' => 'Zentro'])->assertOk()->json(); $this->assertSame([true, "NEW: brand 'Zentro' created"], [$a['created'], $a['log']]);
        $this->postJson('/api/src/brands/add', ['name' => ' zentro '])->assertOk()->assertJsonPath('created', false);
        $this->assertSame($a['id'], SrcNames::brand(1, 'ZENTRO')); $this->assertSame($brands + 1, DB::table('cat_brands')->count());
        // the cycle CSV check offers the same button
        $cc = $this->post('/api/cycles/csv/check', ['file' => UploadedFile::fake()->createWithContent('c.csv', "cycle_ref,product_name,brand,supplier_name\nA,Mug,Novex,zentro trading\n")], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertSame(['Novex'], $cc['add_brands']);
    }
}
