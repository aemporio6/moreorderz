<?php

namespace Tests\Feature;

use App\Services\BuyingService as Buy;
use App\Services\Context;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_B_SOURCING R-B_SOURCING-6: line types PRODUCT / FEE / COST / SAMPLE – FEE / COST through their freight-cost row into landed cost by value, never stock; SAMPLE makes its sample row. */
class PoLineTypesTest extends TestCase
{
    private int $sup; private int $p; private int $wh; private int $cy;

    protected function setUp(): void
    {
        parent::setUp();
        $f = fn ($t, $d) => DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $this->wh = $f('cat_inventory_locations', ['location_name' => 'W1-L-1', 'kind' => 'BIN', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1, 'pick_seq' => 1]);
        $this->sup = $f('t_src_suppliers', ['fk_id_ent' => 1, 'supplier_name' => 'Line Co', 'f_active' => 1]);
        $this->p = $f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'LT-01', 'product_name_vendor' => 'Lamp', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']);
        $this->cy = $f('t_src_cycles', ['fk_id_ent' => 1, 'cycle_code' => 'SC-LT-1', 'sourcing_date' => '2026-11-01', 'product_name' => 'Lamp', 'status' => 'Open', 'f_active' => 1, 'cycle_type' => 'FULL']);
    }

    public function test_the_rule_example_fee_is_lump_sum_counts_in_total_no_lot_and_goes_into_landed_value(): void
    {
        $po = Buy::createPo(['cycle' => $this->cy, 'supplier' => $this->sup, 'currency' => 'GBP', 'date' => '2026-11-02', 'lines' => [
            ['product' => $this->p, 'qty' => 100, 'unit_price' => 5.00],
            ['line_type' => 'FEE', 'name' => 'Agent fee', 'qty' => null, 'unit_price' => 50.00]]]);
        $lines = DB::table('t_src_po_lines')->where('fk_id_po', $po)->orderBy('id')->get();
        $this->assertSame(['PRODUCT', 'FEE'], $lines->pluck('line_type')->all());
        $this->assertSame(50.0, (float) $lines[1]->line_total, 'FEE qty empty → amount = price');
        $this->assertSame(550.0, (float) DB::table('t_src_po')->where('id', $po)->value('po_total'), 'every line type counts in the PO total');
        $fc = DB::table('t_src_freight_costs')->where('source_po_line_id', $lines[1]->id)->first();
        $this->assertSame(['Agent Fee', 50.0, '2026-11-02', 'FEE: Agent fee', $this->cy], [$fc->cost_type, (float) $fc->base_amount, $fc->cost_date, $fc->notes, (int) $fc->fk_id_cycle]);
        $this->assertStringStartsWith('FRT', $fc->freight_code);

        Buy::confirmPo($po); $ship = Buy::createShipment(['po' => $po, 'eta' => '2026-11-10']);
        $this->assertSame(0, DB::table('t_src_shipment_po_link')->where('fk_id_shipment', $ship)->where('fk_id_po_line', $lines[1]->id)->count(), 'a FEE line is never shipped');
        $grn = Buy::createGrn(['shipment' => $ship, 'location' => $this->wh, 'date' => '2026-11-10']); Buy::postGrn($grn);
        $this->assertSame(1, DB::table('fin_lots')->where('po_id', $po)->where('source', 'GRN')->count(), 'one lot – none for the FEE');
        $this->assertSame('RECEIVED', DB::table('t_src_po')->where('id', $po)->value('status'), 'the FEE line does not keep the PO open');
        $r = Buy::landedCost($po, []);
        $this->assertSame(5.5, round((float) $r['unit_cost'], 4), '(500 + FEE 50) / 100 units → landed value includes 50.00');
        $this->assertSame(50.0, (float) DB::table('t_src_landed_cost')->where('id', $r['run'])->value('other_cost'));
    }

    public function test_cost_sample_lines_forms_and_errors(): void
    {
        $po = Buy::createPo(['cycle' => $this->cy, 'supplier' => $this->sup, 'currency' => 'GBP', 'date' => '2026-11-02', 'lines' => [['product' => $this->p, 'qty' => 10, 'unit_price' => 2.00],
            ['line_type' => 'COST', 'name' => 'Inspection', 'qty' => 1, 'unit_price' => 30], ['line_type' => 'SAMPLE', 'name' => 'Colour sample', 'qty' => 2, 'unit_price' => 4]]]);
        $l = DB::table('t_src_po_lines')->where('fk_id_po', $po)->orderBy('id')->get();
        $this->assertSame('Other', DB::table('t_src_freight_costs')->where('source_po_line_id', $l[1]->id)->value('cost_type'));
        $s = DB::table('t_src_samples')->where('id', $l[2]->fk_id_sample)->first();
        $this->assertNotNull($s); $this->assertSame([8.0, 8.0, '2026-11-02'], [(float) $s->sample_cost, (float) $s->base_amount, $s->sample_date]);
        $this->assertSame(0, DB::table('t_src_freight_costs')->where('source_po_line_id', $l[2]->id)->count(), 'a SAMPLE is booked by the sample rule, not again as a cost');
        $this->assertSame(58.0, (float) DB::table('t_src_po')->where('id', $po)->value('po_total'));

        $w = Buy::createPo(['supplier' => $this->sup, 'currency' => 'GBP', 'lines' => [['line_type' => 'weird', 'product' => $this->p, 'qty' => 1, 'unit_price' => 1]]]);
        $this->assertSame('PRODUCT', DB::table('t_src_po_lines')->where('fk_id_po', $w)->value('line_type'), 'unknown type → PRODUCT');
        $this->assertStringContainsString("WARN: line 'LT-01' unknown line_type 'weird' treated as PRODUCT", (string) DB::table('sys_audit_log')->where('table_name', 't_src_po')->where('record_id', $w)->where('action', 'UPDATE')->value('changes_json'));
        $fee = Buy::createPo(['supplier' => $this->sup, 'currency' => 'GBP', 'lines' => [['line_type' => 'FEE', 'name' => 'Agent fee', 'unit_price' => 5]]]);
        $this->assertStringContainsString('NOTE: no PRODUCT lines - nothing will go to stock', (string) DB::table('sys_audit_log')->where('table_name', 't_src_po')->where('record_id', $fee)->where('action', 'UPDATE')->value('changes_json'));
        try { Buy::createPo(['supplier' => $this->sup, 'currency' => 'GBP', 'lines' => [['line_type' => 'FEE', 'unit_price' => 5]]]); $this->fail('name'); } catch (\InvalidArgumentException $e) { $this->assertSame('A FEE line needs a name (what the amount is for).', $e->getMessage()); }
        try { Buy::createPo(['supplier' => $this->sup, 'currency' => 'GBP', 'lines' => [['line_type' => 'PRODUCT', 'qty' => 1, 'unit_price' => 5]]]); $this->fail('product'); } catch (\RuntimeException $e) { $this->assertSame('A PRODUCT line needs a product of this company.', $e->getMessage()); }

        // the PO line form: COST line edited to a new amount → its cost row follows; type changed to PRODUCT without a product → refused
        $this->postJson("/api/record/t_src_po_lines/{$l[1]->id}", ['values' => ['unit_price' => 45]])->assertOk();
        $this->assertSame([45.0, 45.0], [(float) DB::table('t_src_po_lines')->where('id', $l[1]->id)->value('line_total'), (float) DB::table('t_src_freight_costs')->where('source_po_line_id', $l[1]->id)->value('base_amount')]);
        $this->assertSame(73.0, (float) DB::table('t_src_po')->where('id', $po)->value('po_total'));
        $this->postJson("/api/record/t_src_po_lines/{$l[1]->id}", ['values' => ['line_type' => 'PRODUCT']])->assertStatus(422)->assertJsonPath('errors.fk_id_product.0', 'A PRODUCT line needs a product from the product master.');
    }

    public function test_add_line_screen_on_a_draft_po(): void
    {
        $po = Buy::createPo(['cycle' => $this->cy, 'supplier' => $this->sup, 'currency' => 'GBP', 'date' => '2026-11-02', 'lines' => [['product' => $this->p, 'qty' => 10, 'unit_price' => 2.00]]]);
        $this->postJson("/api/po/$po/line", ['line_type' => 'FEE', 'name' => 'Agent fee', 'unit_price' => 15])->assertOk()->assertJsonPath('message', 'Line added · PO total 35.00');
        $this->postJson("/api/po/$po/line", ['line_type' => 'COST', 'unit_price' => 5])->assertStatus(422);      // no name
        $d = $this->getJson("/api/po/$po")->assertOk()->json();
        $this->assertSame(['PRODUCT', 'FEE'], array_column($d['lines'], 'line_type')); $this->assertNull($d['stock_note']);
        $this->assertSame(1, DB::table('t_src_freight_costs')->where('fk_id_cycle', $this->cy)->where('cost_type', 'Agent Fee')->count());
        Buy::confirmPo($po);
        $this->postJson("/api/po/$po/line", ['line_type' => 'FEE', 'name' => 'Late fee', 'unit_price' => 1])->assertStatus(422)->assertJsonPath('message', 'Lines can only be added while the PO is a draft.');
    }
}
