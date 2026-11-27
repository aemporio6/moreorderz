<?php

namespace Tests\Feature;

use App\Services\RecordService;
use App\Services\SourcingProducts;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** MR-1408 / 0287: a sourcing product is linked to its product by SKU and the two share their empty fields – never overwriting. */
class SourcingProductsTest extends TestCase
{
    public function test_sourcing_product_joins_the_product_master(): void
    {
        $sku = 'SP-' . uniqid();
        $p = (int) DB::table('t_product_vendors')->insertGetId(RecordService::fillNotNull('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => $sku, 'product_name_vendor' => 'Lead', 'hs_code' => '42010000', 'carton_l' => 0, 'units_per_carton' => 0]));
        $s = (int) DB::table('t_src_products')->insertGetId(RecordService::fillNotNull('t_src_products', ['fk_id_ent' => 1, 'sku' => strtolower($sku) . ' ', 'product_name' => 'Lead', 'hs_code' => '99999999', 'carton_l' => 40, 'units_per_carton' => 24, 'f_active' => 1, 'created_at' => now()]));
        $r = SourcingProducts::sync($s);
        $this->assertSame($p, $r['linked']);
        $this->assertEqualsCanonicalizing(['carton_l', 'units_per_carton'], $r['to_product']);
        $pr = DB::table('t_product_vendors')->find($p); $sr = DB::table('t_src_products')->find($s);
        $this->assertSame('42010000', $pr->hs_code); $this->assertSame('99999999', $sr->hs_code);      // a typed value is never overwritten
        $this->assertEquals(40, $pr->carton_l); $this->assertEquals(24, $pr->units_per_carton); $this->assertSame($p, (int) $sr->fk_id_product);
        DB::table('t_product_vendors')->where('id', $p)->update(['material' => 'Nylon']);
        $this->assertSame(1, SourcingProducts::forProduct($p));
        $this->assertSame('Nylon', DB::table('t_src_products')->where('id', $s)->value('material'));
        $o = (int) DB::table('t_src_products')->insertGetId(RecordService::fillNotNull('t_src_products', ['fk_id_ent' => 1, 'sku' => 'NO-SUCH-' . uniqid(), 'product_name' => 'X', 'f_active' => 1, 'created_at' => now()]));
        $this->assertNull(SourcingProducts::sync($o)['linked']);
    }
}
