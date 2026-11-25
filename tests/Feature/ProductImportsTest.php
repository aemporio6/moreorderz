<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\RecordService;
use App\Services\StockService as Stock;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-D-6 variations panel + child SKU in search, R-D-52 family link import, R-D-53 parent + child staged import. */
class ProductImportsTest extends TestCase
{
    private function f(string $t, array $d): int { return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }
    private function prod(string $sku, array $x = []): int { return $this->f('t_product_vendors', $x + ['fk_id_ent' => 1, 'SKU_VENDOR' => $sku, 'product_name_vendor' => "Item $sku", 'stock' => 0, 'f_active' => 1, 'f_parent' => 1, 'e_product_type' => 'pool']); }
    private function file(string $name, string $csv): array { return ['file' => UploadedFile::fake()->createWithContent($name, $csv)]; }

    public function test_d6_variations_panel_summary_and_search_actions(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $u = uniqid(); $parent = $this->prod("VP$u", ['f_parent' => 2, 'created_by' => $this->owner->id, 'd_created' => '2026-11-01']);
        $this->getJson("/api/variations/$parent/panel")->assertOk()->assertJsonPath('summary', 'No variations found');
        $c5 = $this->prod("VP$u-RED", ['f_parent' => 3, 'f_id_parent' => $parent, 'sale_price' => 5, 'f_color' => 'Red']); $c7 = $this->prod("VP$u-BLU", ['f_parent' => 3, 'f_id_parent' => $parent, 'sale_price' => 7, 'f_size' => 'L']);
        $bin = $this->f('cat_inventory_locations', ['location_name' => "VB$u", 'kind' => 'BIN', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1]);
        Stock::receive(1, [['product' => $c7, 'qty' => 4, 'location' => $bin, 'unit_cost' => 1]], 'GRN', 1);
        $p = $this->getJson("/api/variations/$parent/panel")->assertOk()->json();
        // the rule: children at £5 (out of stock) and £7 → "2 variations · 1 available · 1 out of stock · £5.00–£7.00 · sum £12.00"
        $this->assertSame('2 variations · 1 available · 1 out of stock · £5.00–£7.00 · sum £12.00', $p['summary']);
        $rows = collect($p['rows'])->keyBy('id');
        $this->assertSame(['Out Of Stock', '#dd3f3f', 0], [$rows[$c5]['status'], $rows[$c5]['colour_hex'], $rows[$c5]['qty']]); $this->assertSame(['Available', '#249f24', 4], [$rows[$c7]['status'], $rows[$c7]['colour_hex'], $rows[$c7]['qty']]);
        $this->assertSame('November 1, 2026', $p['created_on']); $this->assertNotNull($p['created_by']);
        // the child SKU is found by the universal search with its real status and Edit / History / Hide
        \App\Services\SearchIndex::put((string) RecordService::formOfTable('t_product_vendors'), 't_product_vendors', $c5, 1);
        $hit = collect($this->getJson('/api/find?q=' . urlencode("VP$u-RED"))->assertOk()->json('groups'))->flatMap(fn ($g) => $g['items'])->first(fn ($i) => str_contains($i['title'] ?? '', "VP$u-RED"));
        $this->assertNotNull($hit); $labels = array_column($hit['actions'] ?? [], 'label');
        foreach (['Out Of Stock', 'Edit', 'History', 'Hide'] as $l) $this->assertContains($l, $labels);
    }

    public function test_d52_family_links_exact_match_parent_flag_and_undo(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $ab1 = $this->prod('AB-1'); $ab10 = $this->prod('AB-10'); $red = $this->prod('AB-10-RED'); $blu = $this->prod('AB-10-BLU');
        $csv = "parent,x,child\nAB-10,,AB-10-RED\nAB-10,,AB-10-BLU\nAB-1,,AB-10-GRN\nNOPE,,AB-10-RED\n";
        $c = $this->post('/api/product-imports/family/check', $this->file('fam.csv', $csv), ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertSame([2, 2, 'Check: 2 links, 2 not found. Nothing was saved.'], [$c['links'], $c['not_found'], $c['message']]);
        $this->assertSame(1, (int) DB::table('t_product_vendors')->where('id', $red)->value('f_parent'), 'the check writes nothing');
        $r = $this->post('/api/product-imports/family/import', $this->file('fam.csv', $csv), ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertSame('2 rows were updated, 2 not found.', $r['message']);
        $row = fn ($id) => array_map('intval', (array) DB::table('t_product_vendors')->where('id', $id)->first(['f_parent', 'f_id_parent']));
        $this->assertSame(['f_parent' => 3, 'f_id_parent' => $ab10], $row($red)); $this->assertSame(['f_parent' => 3, 'f_id_parent' => $ab10], $row($blu));
        $this->assertSame(2, $row($ab10)['f_parent'], 'a parent that was alone becomes a parent'); $this->assertSame(1, $row($ab1)['f_parent'], '"AB-1" does not match "AB-10"');
        $this->assertSame(0, DB::table('t_product_vendors')->where('f_id_parent', $ab1)->count());
        $this->postJson('/api/product-imports/family/undo/' . $r['job'])->assertOk();
        $this->assertSame([1, 1, 1], [$row($red)['f_parent'], $row($blu)['f_parent'], $row($ab10)['f_parent']]); $this->assertSame(0, $row($red)['f_id_parent']);
        $this->postJson('/api/product-imports/family/undo/' . $r['job'])->assertStatus(422);
        $this->post('/api/product-imports/family/check', $this->file('fam.csv', "parent,child\nAB-10,AB-10-RED\n"), ['Accept' => 'application/json'])->assertOk()->assertJsonPath('links', 1);
    }

    public function test_d53_staged_parent_child_import(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $v = $this->f('t_vendors', ['fk_id_ent' => 1, 'vendor_name' => 'Staged Vendor', 'iso_vendor' => 'SV' . random_int(10, 99), 'f_active' => 1]);
        $car = $this->f('t_carriers', ['fk_id_ent' => 1, 'carrier_name' => 'Stage Carrier', 'f_active' => 1]); $pre = $this->f('t_carrier_pkg_svc', ['pkg_svc_name' => 'Stage 48', 'fk_id_carrier' => $car, 'f_active' => 1]);
        $pack = $this->f('t_packings', ['packing_name' => 'Small box S', 'f_active' => 1]);
        $wh = $this->f('cat_inventory_locations', ['location_name' => 'Staged WH', 'kind' => 'WAREHOUSE', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1]); \App\Services\Settings::set('MAIN_WAREHOUSE', (string) $wh, 1);
        $csv = "P1,,Mug set,Stage 48,Small box S,,,,\n,P1-A,Mug red,Stage 48,Small box S,5.50,3,0.4,10x10x10\n,P1-B,Mug blue,,,6.00,,,\nP2,,Solo plate,No such preset,,9.99,2,,\n,,,,,,,,\n";
        $this->post('/api/product-imports/staged', $this->file('p.csv', $csv), ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('message', 'Please select Vendor.');
        $this->post('/api/product-imports/staged', ['vendor' => $v], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('message', 'Please select a file to upload.');
        $this->post('/api/product-imports/staged', ['vendor' => $v] + $this->file('p.xlsx', $csv), ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('message', 'Invalid file type. Please upload an Excel file (CSV).');
        $n = DB::table('t_product_vendors')->count();
        $s = $this->post('/api/product-imports/staged', ['vendor' => $v] + $this->file('p.csv', $csv), ['Accept' => 'application/json'])->assertOk()->json();
        // the rule: rows P1 / P1-A / P1-B / P2 / (blank) → parent P1 with 2 children and P2 as a single product
        $this->assertSame([1, 2, 1], [$s['parents'], $s['children'], $s['singles']]);
        $this->assertSame(['P1', 'parent', ['P1-A', 'P1-B']], [$s['tree'][0]['sku'], $s['tree'][0]['kind'], array_column($s['tree'][0]['children'], 'sku')]); $this->assertSame(['P2', 'single'], [$s['tree'][1]['sku'], $s['tree'][1]['kind']]);
        $this->assertSame(['Staged Vendor', true], [$s['vendor'], $s['staged_today']]); $this->assertContains('Row 4: carrier preset "No such preset" not found – left empty.', $s['log']);
        $this->assertSame($n, DB::table('t_product_vendors')->count(), 'the preview saves no product');
        $this->getJson('/api/product-imports/staged/' . $s['job'])->assertOk()->assertJsonPath('children', 2);
        $other = $this->makeUser('MANAGER'); $this->actingAs($other)->postJson('/api/product-imports/staged/' . $s['job'] . '/confirm')->assertStatus(422); $this->actingAs($this->owner);      // staging is per user
        $r = $this->postJson('/api/product-imports/staged/' . $s['job'] . '/confirm')->assertOk()->json();
        $this->assertSame([1, 2, 1, 6], [$r['parents'], $r['children'], $r['singles'], $r['opening_units']]);
        $g = fn ($sku) => DB::table('t_product_vendors')->where('fk_id_ent', 1)->where('SKU_VENDOR', $sku)->first();
        $p1 = $g('P1'); $a = $g('P1-A'); $b = $g('P1-B'); $p2 = $g('P2');
        $this->assertSame([2, 1, $v], [(int) $p1->f_parent, (int) $p1->f_out_of_stock, (int) $p1->fk_id_vendor], 'parents start out of stock');
        $this->assertSame([3, (int) $p1->id, 0, 5.5, $pre, $pack], [(int) $a->f_parent, (int) $a->f_id_parent, (int) $a->f_out_of_stock, (float) $a->sale_price, (int) $a->fk_id_car_pkg_svc, (int) $a->fk_id_packing]);
        $this->assertSame([1, 0.0, 0.0], [(int) $p2->f_parent, (float) $p2->list_price, (float) $p2->ebay_fee]);
        $lots = fn ($id) => DB::table('fin_lots')->where('fk_id_product', $id)->where('source', 'OPENING')->sum('qty');
        $this->assertSame([3, 1, 2, 0], [(int) $lots($a->id), (int) $lots($b->id), (int) $lots($p2->id), (int) $lots($p1->id)], 'qty enters as OPENING lots (default 1); never on the parent');
        $this->postJson('/api/product-imports/staged/' . $s['job'] . '/confirm')->assertStatus(422);
        // opening the screen clears the person's staging
        $s2 = $this->post('/api/product-imports/staged', ['vendor' => $v] + $this->file('q.csv', "Q1,,Thing,,,1,1,,\n"), ['Accept' => 'application/json'])->assertOk()->json();
        $this->postJson('/api/product-imports/staged/clear')->assertOk(); $this->getJson('/api/product-imports/staged/' . $s2['job'])->assertStatus(422);
    }
}
