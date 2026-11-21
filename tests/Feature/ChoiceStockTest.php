<?php

namespace Tests\Feature;

use App\Services\Choices;
use App\Services\CycleRepeat;
use App\Services\RecordService;
use App\Services\StockSnapshot;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Owner rule 22 Nov 03:47: B15 FBA location kinds and B8 the price of a repeated cycle are Settings › Choices (default = my suggestion, the other options change the behaviour). */
class ChoiceStockTest extends TestCase
{
    public function test_b15_fba_location_kinds(): void
    {
        $loc = fn (string $kind) => DB::table('cat_inventory_locations')->insertGetId(RecordService::fillNotNull('cat_inventory_locations', ['fk_id_ent' => 1, 'kind' => $kind, 'location_code' => 'L' . uniqid(), 'location_name' => $kind . ' test', 'f_active' => 1]));
        $mfc = $loc('MARKETPLACE_FULFILMENT'); $tpl = $loc('THREE_PL'); $wh = $loc('WAREHOUSE');
        $b = StockSnapshot::buckets(1);
        $this->assertSame(['amazon', 'amazon', 'warehouse'], [$b[$mfc], $b[$tpl], $b[$wh]], 'default: marketplace fulfilment and third-party warehouse count as at Amazon');
        Choices::set('FBA_LOCATION_KINDS', 'FBA', 1);
        $b = StockSnapshot::buckets(1); $this->assertSame(['warehouse', 'warehouse'], [$b[$mfc], $b[$tpl]], 'only FBA → the others are warehouse stock');
        Choices::set('FBA_LOCATION_KINDS', 'ALL', 1); $this->assertSame('amazon', StockSnapshot::buckets(1)[$wh]);
        $c = collect(Choices::all(1))->firstWhere('key', 'FBA_LOCATION_KINDS');
        $this->assertSame([true, null, 'All'], [$c['multi'], $c['add_url'], $c['options'][0]['label']], 'a fixed list of the program: multiple + All, no + Add');
        try { Choices::set('FBA_LOCATION_KINDS', 'MOON', 1); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('Choose values from the list (MOON is not one).', $e->getMessage()); }
    }

    public function test_b8_repeated_cycle_price(): void
    {
        $cy = DB::table('t_src_cycles')->insertGetId(RecordService::fillNotNull('t_src_cycles', ['fk_id_ent' => 1, 'cycle_code' => 'SC-RP-' . uniqid(), 'sourcing_date' => '2026-10-01', 'product_name' => 'Mug', 'status' => 'Open', 'f_active' => 1, 'cycle_type' => 'FULL']));
        $sup = DB::table('t_src_suppliers')->insertGetId(RecordService::fillNotNull('t_src_suppliers', ['fk_id_ent' => 1, 'supplier_name' => 'Sup ' . uniqid(), 'f_active' => 1]));
        $cp = (object) ['id' => 987654, 'fk_id_cycle' => $cy, 'fk_id_product' => null, 'fk_id_supplier' => $sup, 'target_unit_cost' => 5.0];
        $po = DB::table('t_src_po')->insertGetId(RecordService::fillNotNull('t_src_po', ['fk_id_ent' => 1, 'po_code' => 'PO-RP', 'po_number' => 'PO-RP', 'po_date' => '2026-10-05', 'f_active' => 1]));
        DB::table('t_src_po_lines')->insert(RecordService::fillNotNull('t_src_po_lines', ['fk_id_po' => $po, 'fk_id_cycle_product' => 987654, 'unit_price' => 7.0, 'f_active' => 1]));
        DB::table('t_src_rfq')->insert(RecordService::fillNotNull('t_src_rfq', ['fk_id_ent' => 1, 'fk_id_cycle' => $cy, 'fk_id_supplier' => $sup, 'rfq_code' => 'RFQ-RP', 'rfq_date' => '2026-10-02', 'final_unit_price' => 6.0, 'f_active' => 1]));
        $this->assertSame([7.0, 'last PO line (PO-RP)'], CycleRepeat::lastPrice($cp, 1), 'default: the last agreed price');
        Choices::set('CYCLE_REPEAT_PRICE', 'RFQ_FIRST', 1); $this->assertSame([6.0, 'RFQ final price (RFQ-RP)'], CycleRepeat::lastPrice($cp, 1));
        Choices::set('CYCLE_REPEAT_PRICE', 'TARGET', 1); $this->assertSame([5.0, 'target of the old cycle'], CycleRepeat::lastPrice($cp, 1));
    }
}
