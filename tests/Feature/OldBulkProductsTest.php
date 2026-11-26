<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\OldBulkProducts as OB;
use App\Services\RecordService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-D-51 products bulk upload – the old 34-column layout, names instead of ids, OPENING lot, primary marketplace row, preview, undo. */
class OldBulkProductsTest extends TestCase
{
    private function f(string $t, array $d): int { return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }
    private function line(array $set): string { $r = array_fill(0, 34, ''); foreach ($set as $k => $v) $r[$k] = $v; return implode(',', array_map(fn ($v) => '"' . str_replace('"', '""', (string) $v) . '"', $r)); }
    private function up(string $csv) { return $this->post('/api/product-imports/old-bulk', ['file' => UploadedFile::fake()->createWithContent('bulk.csv', $csv)], ['Accept' => 'application/json']); }

    public function test_d51_names_instead_of_ids_opening_lot_primary_row_preview_undo(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $v = $this->f('t_vendors', ['fk_id_ent' => 1, 'vendor_name' => 'Bulk Vendor 51', 'f_active' => 1]); $type = $this->f('cat_product_type', ['fk_id_ent' => 1, 'product_type_name' => 'Kitchen', 'f_active' => 1]);
        $trend = $this->f('cat_product_trends', ['fk_id_ent' => 1, 'product_trend_name' => 'Evergreen 51', 'f_active' => 1]); $pack = $this->f('t_packings', ['fk_id_ent' => 1, 'packing_name' => 'Bubble bag 51', 'f_active' => 1]);
        $car = $this->f('t_carriers', ['fk_id_ent' => 1, 'carrier_name' => 'Carrier 51', 'f_active' => 1]); $pre = $this->f('t_carrier_pkg_svc', ['pkg_svc_name' => 'Tracked 51', 'fk_id_carrier' => $car, 'rate' => 2, 'f_active' => 1]);
        $tag = $this->f('cat_tags', ['fk_id_ent' => 1, 'tag_name' => 'Fragile 51', 'f_active' => 1]); $role = $this->f('cat_account_types', ['fk_id_ent' => 1, 'account_type_name' => 'Buyer 51', 'f_active' => 1]);
        $acc = $this->f('t_account', ['fk_id_ent' => 1, 'first_name' => 'Rafi', 'sur_name' => 'Fiftyone', 'f_active' => 1]);
        $mp = $this->f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'Prim51', 'f_active' => 1]); \App\Services\ProductPrimary::set(1, $mp, $this->owner);
        $this->f('cat_inventory_locations', ['fk_id_ent' => 1, 'location_name' => 'Bulk WH 51', 'kind' => 'WAREHOUSE', 'f_active' => 1, 'f_sellable' => 1]);
        $csv = "flag,vendor,SKU,name\n"
            . $this->line([1 => 'Bulk Vendor 51', 2 => 'OB-1', 3 => 'Pan', 4 => '31/12/2027', 9 => '0', 11 => 'tracked 51', 12 => 'Bubble bag 51', 13 => 'Kitchen', 14 => (string) $trend, 15 => '9.99', 16 => '12.50', 18 => '10', 19 => '2', 22 => '1.20', 25 => 'Fragile 51', 28 => 'Buyer 51', 29 => 'Rafi Fiftyone', 33 => 'A good pan']) . "\n"
            . $this->line([0 => '1', 1 => 'Bulk Vendor 51', 2 => 'OB-PARENT', 3 => 'Pan family', 13 => 'Kitchen']) . "\n"
            . $this->line([1 => 'Bulk Vendor 51', 2 => 'OB-3', 3 => 'Lid', 13 => 'Kichen']) . "\n"            // look-alike
            . $this->line([1 => 'Nobody at all', 2 => 'OB-4', 3 => 'Cup', 25 => 'Fragil 51']) . "\n"
            . $this->line([1 => 'Bulk Vendor 51', 2 => 'OB-1', 3 => 'Twice']) . "\n"
            . $this->line([1 => 'Bulk Vendor 51', 2 => 'OB-6', 3 => 'Bad', 15 => 'abc', 18 => '1.5']) . "\n";
        $before = DB::table('t_product_vendors')->count();
        $d = $this->up($csv)->assertOk()->json();
        $this->assertSame([2, 10, 'Prim51', OB::NAME, $before], [$d['ready'], $d['opening_units'], $d['primary_marketplace'], $d['mapping'], DB::table('t_product_vendors')->count()], 'the preview saves no product');
        $log = implode("\n", $d['log']);
        $this->assertStringContainsString('Row 3 (OB-3): Type "Kichen" not found – did you mean "Kitchen"? – not loaded.', $log); $this->assertStringContainsString('Row 4 (OB-4): Vendor "Nobody at all" not found. Tag 1 "Fragil 51" not found – did you mean "Fragile 51"? – not loaded.', $log);
        $this->assertStringContainsString('Row 5: SKU "OB-1" is in the file twice – skipped.', $log); $this->assertStringContainsString('Sale price "abc" is not a number. Stock "1.5" is not a whole number.', $log);
        $this->assertSame(['Kitchen', 'Tracked 51', 'product'], [$d['rows'][0]['type'], $d['rows'][0]['carrier'], $d['rows'][0]['kind']]);
        $va = $this->makeUser('VA'); $this->actingAs($va)->postJson("/api/product-imports/old-bulk/{$d['job']}/confirm")->assertStatus(403); $this->actingAs($this->owner);
        // the rule: row with col13 "Kitchen" (name) → type resolved by name; col18 = 10 → OPENING lot 10
        $r = $this->postJson("/api/product-imports/old-bulk/{$d['job']}/confirm")->assertOk()->json(); $this->assertSame(10, $r['opening_units']);
        $p = DB::table('t_product_vendors')->where('SKU_VENDOR', 'OB-1')->first(); $par = DB::table('t_product_vendors')->where('SKU_VENDOR', 'OB-PARENT')->first();
        $this->assertSame([$type, $v, $pre, $pack, $trend, 1, 1, 1, 'Carrier 51', '2027-12-31', 2, 1], [(int) $p->fk_id_product_type, (int) $p->fk_id_vendor, (int) $p->fk_id_car_pkg_svc, (int) $p->fk_id_packing, (int) $p->fk_id_product_trend, (int) $p->f_active, (int) $p->f_parent, (int) $p->f_tag1 === $tag ? 1 : 0, $p->carrier, $p->d_product_expiry_date, (int) $p->min_level, (int) $p->f_active], 'active is forced 1; the carrier is derived from the service');
        $this->assertSame(2, (int) $par->f_parent);
        $this->assertSame([(string) $tag, $role, $acc], [(string) $p->tag_ids, (int) $p->fk_id_role, (int) $p->fk_id_role_account], 'tag, role and role account by name (R-D-51)');
        $lot = DB::table('fin_lots')->where('fk_id_product', $p->id)->first(); $this->assertSame(['OPENING', 10, 10], [$lot->source, (int) $lot->qty, (int) $lot->qty_left]); $this->assertSame(0, DB::table('fin_lots')->where('fk_id_product', $par->id)->count());
        $pr = DB::table('t_product_marketplace_prices')->where('fk_id_product', $p->id)->where('fk_id_marketplace', $mp)->first(); $this->assertSame(['9.99', '12.50', '1.20'], [$pr->sale_price, $pr->list_price, $pr->marketplace_fee]);
        $this->postJson("/api/product-imports/old-bulk/{$d['job']}/confirm")->assertStatus(422);      // staged once
        $this->assertStringContainsString('This SKU already exists', implode(' ', $this->up($csv)->assertOk()->json('log')));
        // undo: the lot is voided, the price rows go, the SKU is free again
        $this->postJson('/api/product-imports/old-bulk/undo/' . $r['job'])->assertOk()->assertJsonPath('message', 'Undone: 1 product(s) removed, 1 with voided opening stock kept as deleted (their SKU is free again).');
        $this->assertSame(['VOID', 0], [DB::table('fin_lots')->where('id', $lot->id)->value('lot_status'), DB::table('t_product_marketplace_prices')->where('fk_id_product', $p->id)->count()]);
        $this->assertSame(0, DB::table('t_product_vendors')->whereIn('SKU_VENDOR', ['OB-1', 'OB-PARENT'])->count()); $this->assertSame('DELETED', DB::table('t_product_vendors')->where('id', $p->id)->value('row_state'));
        $this->postJson('/api/product-imports/old-bulk/undo/' . $r['job'])->assertStatus(422);
        $this->assertSame(2, $this->up($csv)->json('ready'), 'the same file can be loaded again');
        $this->post('/api/product-imports/old-bulk', [], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('message', 'Please select a file to upload.');
    }
}
