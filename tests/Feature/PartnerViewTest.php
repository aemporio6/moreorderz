<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Owner 26-11 23:19: each partner's whole account – his products and their stock (his share), settled months, invoices, ledger; a partner sees only himself. */
class PartnerViewTest extends TestCase
{
    private function f(string $t, array $d): int { return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }

    public function test_each_partner_account(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $a = $this->f('t_account', ['fk_id_ent' => 1, 'account_code' => 'PA' . random_int(100, 999), 'first_name' => 'Asim', 'sur_name' => 'Investor', 'f_active' => 1]);
        $b = $this->f('t_account', ['fk_id_ent' => 1, 'account_code' => 'PB' . random_int(100, 999), 'first_name' => 'Bilal', 'sur_name' => 'Investor', 'f_active' => 1]);
        $p = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'PV-1', 'product_name_vendor' => 'Shared lamp', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'private', 'raw_price' => 4, 'landing_price' => 5]);
        $q = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'PV-2', 'product_name_vendor' => 'Asim only', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'private', 'raw_price' => 2, 'landing_price' => 3]);
        DB::table('t_product_investors')->insert([['fk_id_product' => $p, 'fk_id_account' => $a], ['fk_id_product' => $p, 'fk_id_account' => $b], ['fk_id_product' => $q, 'fk_id_account' => $a]]);
        $wh = $this->f('cat_inventory_locations', ['fk_id_ent' => 1, 'location_name' => 'PV WH', 'kind' => 'WAREHOUSE', 'f_active' => 1]);
        \App\Services\StockService::adjust(1, $p, $wh, 40, 'opening', ['unit_cost' => 5]); \App\Services\StockService::adjust(1, $q, $wh, 10, 'opening', ['unit_cost' => 3]);
        $per = $this->f('t_inv_periods', ['fk_id_ent' => 1, 'd_from' => '2026-10-01', 'd_to' => '2026-10-31', 'status' => 'CONFIRMED']);
        $this->f('t_inv_period_investors', ['fk_id_period' => $per, 'fk_id_investor_account' => $a, 'units' => 12, 'revenue' => 300, 'profit_before_split' => 120, 'share_amount' => 60, 'weight_pct' => 50]);
        $this->f('t_invoices', ['fk_id_ent' => 1, 'invoice_no' => 'INV-PV-1', 'invoice_type' => 'INVESTOR', 'd_invoice' => '2026-11-02', 'fk_id_investor_account' => $a, 'total' => 60, 'amount_paid' => 20, 'status' => 'PART_PAID', 'currency' => 'GBP']);
        $list = $this->getJson('/api/partner-view')->assertOk()->json('rows'); $ra = collect($list)->firstWhere('account', $a);
        $this->assertSame(2, $ra['products']); $this->assertEquals(30, $ra['units'], '½ of 40 + all of 10');
        $this->assertEquals(100, $ra['stock_value'], '20 × 4 + 10 × 2 at raw price'); $this->assertEquals(130, $ra['stock_cost'], '20 × 5 + 10 × 3 at cost (Owner sees it)');
        $this->assertEquals([120, 60, 60, 20, 40], [$ra['profit'], $ra['share'], $ra['billed'], $ra['paid'], $ra['owed']]);
        $one = $this->getJson("/api/partner-view/$a")->assertOk()->json(); $this->assertCount(2, $one['products']); $this->assertCount(1, $one['periods']); $this->assertSame('INV-PV-1', $one['documents'][0]['invoice_no']);
        $this->assertEqualsCanonicalizing([$p, $q], array_column($this->getJson("/api/inventory?investor=$a")->json('rows'), 'id'), 'his stock in Inventory');
        // a partner login sees only his own account
        $pu = $this->makeUser('PARTNER'); DB::table('t_users')->where('id', $pu->id)->update(['fk_id_account' => $b]); DB::table('t_inv_parties')->insert(RecordService::fillNotNull('t_inv_parties', ['fk_id_ent' => 1, 'party_type' => 'ACCOUNT', 'fk_id_account' => $b, 'display_name' => 'Bilal', 'f_active' => 1]));
        $this->actingAs($pu); \App\Services\Perm::flush();
        if (! \App\Services\Perm::has($pu, 'partners.SEE')) {
            $this->assertSame([$b], array_column($this->getJson('/api/partner-view')->assertOk()->json('rows'), 'account'));
            $this->getJson("/api/partner-view/$a")->assertStatus(403);
            $this->assertArrayNotHasKey('stock_cost', $this->getJson("/api/partner-view/$b")->assertOk()->json('summary'), 'never the cost to a partner');
        } else $this->getJson("/api/partner-view/$a")->assertOk();
    }
}
