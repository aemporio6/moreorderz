<?php

namespace Tests\Feature;

use App\Services\BuyingService as Buy;
use App\Services\Choices;
use App\Services\Fx;
use App\Services\LandedEngine as LE;
use App\Services\Ledger;
use App\Services\RecordService;
use App\Services\StockService as Stock;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_B_SOURCING R-B_SOURCING-44: how landed costs are spread – method per cost, carton fallback, remainder rounding, per-product accepted, ABSORB / WRITE_OFF. */
class LandedEngineTest extends TestCase
{
    private int $wh; private int $po; private int $a; private int $b; private int $cy;

    private function p(string $sku, float $value, int $acc, int $dmg = 0, int $miss = 0, float $gross = 0): array { return ['name' => $sku, 'value' => $value, 'ordered' => 100, 'gross' => $gross, 'cbm' => 0, 'accepted' => $acc, 'damaged' => $dmg, 'missing' => $miss, 'has_grn' => true]; }

    public function test_the_rule_example_in_the_engine(): void
    {
        $pr = ['A' => $this->p('A', 800, 98, 2), 'B' => $this->p('B', 2400, 100)];
        $r = LE::split($pr, [['Freight', 400, 'QUANTITY'], ['Duty', 160, 'VALUE']]);
        $this->assertSame([200.0, 200.0, 40.0, 120.0], [$r['products']['A']['shares'][0]['amount'], $r['products']['B']['shares'][0]['amount'], $r['products']['A']['shares'][1]['amount'], $r['products']['B']['shares'][1]['amount']]);
        $this->assertSame([10.6122, 27.2], [$r['products']['A']['unit'], $r['products']['B']['unit']]);      // ABSORB
        $r = LE::split($pr, [['Freight', 400, 'VALUE'], ['Duty', 160, 'VALUE']]); $this->assertSame([9.5918, 28.2], [$r['products']['A']['unit'], $r['products']['B']['unit']]);
        $r = LE::split($pr, [['Freight', 400, 'VALUE'], ['Duty', 160, 'VALUE']], 'WRITE_OFF'); $this->assertSame([9.4, 18.8], [$r['products']['A']['unit'], $r['products']['A']['loss']]);
    }

    public function test_remainder_rounding_carton_fallback_and_defaults(): void
    {
        $pr = ['A' => $this->p('A', 100, 100), 'B' => $this->p('B', 100, 100), 'C' => $this->p('C', 100, 100)];
        $r = LE::split($pr, [['Freight', 100, 'VALUE']]); $this->assertSame([33.33, 33.33, 33.34], array_map(fn ($k) => $r['products'][$k]['shares'][0]['amount'], ['A', 'B', 'C']));      // the last takes the remainder: Σ = the cost
        $pr['A']['gross'] = 10; $pr['B']['gross'] = 30;
        $r = LE::split($pr, [['Sea freight', 90, 'WEIGHT']]); $this->assertSame(['No carton data for C – Sea freight split by quantity'], $r['warnings']); $this->assertSame('QUANTITY', $r['products']['A']['shares'][0]['method']);
        $pr['C']['gross'] = 60; $r = LE::split($pr, [['Sea freight', 100, 'WEIGHT']]); $this->assertSame([10.0, 30.0, 60.0], array_map(fn ($k) => $r['products'][$k]['shares'][0]['amount'], ['A', 'B', 'C']));
        $this->assertSame(['QUANTITY', 'VALUE', 'VALUE', 'VALUE'], [LE::defaultMethod('FREIGHT', 1), LE::defaultMethod('DUTY', 1), LE::defaultMethod('OTHER', 1), LE::defaultMethod('FREIGHT', 1, true)]);
        Choices::set('LC_FREIGHT_METHOD', 'CBM', 1); $this->assertSame('CBM', LE::defaultMethod('FREIGHT', 1));
        $this->assertSame(['FREIGHT', 'OTHER', 'OTHER'], [LE::kindOfCostType('Main freight'), LE::kindOfCostType('Insurance'), LE::kindOfCostType('Agent fee')]);
    }

    private function cycle(): void
    {
        $this->actingAs($this->owner); Fx::set('USD', '2026-08-01', 0.80); $f = fn ($t, $d) => (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
        $this->wh = $f('cat_inventory_locations', ['location_name' => 'Main', 'kind' => 'WAREHOUSE', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1]);
        $sup = $f('t_src_suppliers', ['fk_id_ent' => 1, 'supplier_code' => 'S-L', 'supplier_name' => 'Lamps Co', 'f_active' => 1]);
        $this->a = $f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'A', 'product_name_vendor' => 'A', 'f_active' => 1]); $this->b = $f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'B', 'product_name_vendor' => 'B', 'f_active' => 1]);
        $this->cy = $f('t_src_cycles', ['fk_id_ent' => 1, 'cycle_code' => 'CYC-L', 'product_name' => 'Lamps', 'status' => 'Open', 'cycle_type' => 'FULL', 'f_active' => 1, 'created_at' => now()]);
        foreach ([$this->a, $this->b] as $p) $f('t_src_cycle_products', ['fk_id_cycle' => $this->cy, 'fk_id_product' => $p, 'product_name' => 'x']);
        $this->po = Buy::createPo(['cycle' => $this->cy, 'supplier' => $sup, 'currency' => 'USD', 'date' => '2026-08-01', 'lines' => [['product' => $this->a, 'qty' => 100, 'unit_price' => 10], ['product' => $this->b, 'qty' => 100, 'unit_price' => 30]]]);
        Buy::confirmPo($this->po); $ship = Buy::createShipment(['po' => $this->po, 'eta' => '2026-08-18']);
        $l = DB::table('t_src_po_lines')->where('fk_id_po', $this->po)->orderBy('id')->pluck('id');
        Buy::postGrn(Buy::createGrn(['shipment' => $ship, 'location' => $this->wh, 'date' => '2026-08-20', 'lines' => [['po_line' => $l[0], 'received' => 100, 'damaged' => 2, 'location' => $this->wh], ['po_line' => $l[1], 'received' => 100, 'damaged' => 0, 'location' => $this->wh]]]));
    }

    public function test_the_rule_example_through_the_cycle_with_the_ledger(): void
    {
        $this->cycle();
        $r = Buy::landedCost($this->po, ['freight' => 400, 'duty' => 160]);      // provisional, ABSORB, freight by quantity, duty by value
        $this->assertSame([10.6122, 27.2], [$r['products'][$this->a]['unit'], $r['products'][$this->b]['unit']]);
        $this->assertSame(Stock::value(1), Ledger::balance(1, '1200'), 'stock value = stock account'); $this->assertTrue(Ledger::trialBalance(1)['balanced']);
        $this->assertSame(round(98 * 10.6122 + 100 * 27.2, 2), Stock::value(1));
        $this->assertSame(0.0, Stock::value(1, $this->a) - round(98 * 10.6122, 2));      // the 2 damaged units sit at no cost
        $r = Buy::landedCost($this->po, ['freight' => 400, 'duty' => 160, 'freight_method' => 'VALUE']);      // a re-run books only the difference
        $this->assertSame([9.5918, 28.2], [$r['products'][$this->a]['unit'], $r['products'][$this->b]['unit']]); $this->assertSame(Stock::value(1), Ledger::balance(1, '1200'));
        $r = Buy::landedCost($this->po, ['freight' => 400, 'duty' => 160, 'freight_method' => 'VALUE', 'loss' => 'WRITE_OFF', 'final' => true]);
        $this->assertSame([9.4, 18.8], [$r['products'][$this->a]['unit'], $r['loss']]); $this->assertSame(18.8, Ledger::balance(1, '5500'));      // the damaged cost goes to stock loss …
        $this->assertSame(18.8, (float) DB::table('t_src_grn')->where('fk_id_cycle', $this->cy)->value('claim_base_amount'));      // … and onto the supplier claim
        $this->assertSame(Stock::value(1), Ledger::balance(1, '1200')); $this->assertTrue(Ledger::trialBalance(1)['balanced']);
        $this->assertSame(-560.0, round(Ledger::balance(1, '1210'), 2));      // the 560 of costs came off the costs on the way (paid into it when the bills are paid)
        $this->assertSame(3, DB::table('t_src_landed_cost')->where('fk_id_cycle', $this->cy)->count()); $this->assertSame(2, DB::table('t_src_landed_lines')->where('fk_id_landed', $r['run'])->count());
        $this->assertStringContainsString('Freight (typed) 100.00 (VALUE 25%)', (string) DB::table('fin_lots')->where('fk_id_product', $this->a)->where('source', 'GRN')->value('cost_formula'));
        $v = Buy::landedCost($this->po, ['freight' => 400, 'duty' => 160, 'freight_method' => 'VALUE', 'loss' => 'WRITE_OFF', 'other' => 40]);      // R-B_SOURCING-60: a later version, FINAL by itself
        $this->assertSame('FINAL', $v['status']); $this->assertSame('SUPERSEDED', DB::table('t_src_landed_cost')->where('id', $r['run'])->value('landed_status'));
        $this->assertSame(Stock::value(1), Ledger::balance(1, '1200')); $this->assertTrue(Ledger::trialBalance(1)['balanced']);
    }

    public function test_cost_rows_paid_amount_fee_lines_and_no_import_vat(): void
    {
        $this->cycle(); $f = fn ($t, $d) => (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d + ['fk_id_ent' => 1, 'fk_id_cycle' => $this->cy, 'f_active' => 1, 'created_at' => now()]));
        $f('t_src_freight_costs', ['freight_code' => 'FC-1', 'cost_type' => 'Main freight', 'amount' => 500, 'base_amount' => 400, 'pay_status' => 'PAID', 'paid_base_amount' => 380]);      // paid: what was paid
        $f('t_src_freight_costs', ['freight_code' => 'FC-2', 'cost_type' => 'Insurance', 'amount' => 40, 'base_amount' => 40]);
        $f('t_src_customs', ['customs_code' => 'CU-1', 'duty_amount' => 200, 'exchange_rate' => 0.8, 'base_amount' => 160, 'import_vat' => 900]);
        $r = Buy::landedCost($this->po, []);
        $sh = collect(DB::table('t_src_landed_lines')->where('fk_id_landed', $r['run'])->where('fk_id_product', $this->a)->value('shares_json') ? json_decode(DB::table('t_src_landed_lines')->where('fk_id_landed', $r['run'])->where('fk_id_product', $this->a)->value('shares_json'), true) : []);
        $this->assertSame([190.0, 'QUANTITY'], [(float) $sh[0]['amount'], $sh[0]['method']]); $this->assertSame([10.0, 'VALUE'], [(float) $sh[1]['amount'], $sh[1]['method']]); $this->assertSame([40.0, 'VALUE'], [(float) $sh[2]['amount'], $sh[2]['method']]);      // 380 by qty, insurance 40 by value, duty 160 by value; import VAT never
        $this->assertSame(580.0, (float) DB::table('t_src_landed_cost')->where('id', $r['run'])->value('shares_total'));
    }
}
