<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\Icc;
use App\Services\Perm;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Batch 7e-221 Core / Platform – 07_ICC Inventory Cycle Calculator (MR-0031, IC-18): the owner's worked example, the pipeline stages, the stored result, the task and the screens. */
class CoreAreaTest extends TestCase
{
    private function f(string $t, array $d): int { return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }

    private function example(array $x = []): array
    {
        return $x + ['product_id' => 1, 'ent' => 1, 'sku' => 'X', 'name' => 'X', 'monthly' => 2000, 'season' => 1.0, 'growth' => 0, 'mode' => 'SEA', 'safety_days' => 14, 'fulfil' => 'FBM', 'units_per_carton' => 40, 'carton' => [60, 40, 34],
            'packed_g' => 0, 'carton_kg' => 12, 'unit_dims' => [0, 0, 0], 'divisor' => 5000, 'd' => ['order' => 2, 'production' => 30, 'to_forwarder' => 3, 'forwarder' => 5, 'transit' => 60, 'clearance' => 5, 'fba_inbound' => 0],
            'holiday_cal' => false, 'unit_cost' => 2.0, 'currency' => 'GBP', 'moq' => 500, 'deposit_pct' => 30, 'sale_price' => 0, 'margin' => 3.2, 'duty_pct' => 0, 'customs_fee' => 0, 'vat_pct' => 20,
            'available' => 3000, 'pipeline' => ['ordered' => 0, 'production' => 4000, 'transit' => 0, 'po_ids' => []], 'holidays' => [], 'today' => '2026-11-26', 'assumed' => [],
            'rates' => ['AIR' => ['per_kg' => 5.2, 'per_cbm' => null, 'min' => 60, 'days' => 14], 'SEA' => ['per_kg' => null, 'per_cbm' => 95, 'min' => 180, 'days' => 60], 'TRUCK' => ['per_kg' => null, 'per_cbm' => 140, 'min' => 150, 'days' => 33],
                'RAIL' => ['per_kg' => null, 'per_cbm' => 120, 'min' => 150, 'days' => 25], 'COURIER' => ['per_kg' => 7.5, 'per_cbm' => null, 'min' => 25, 'days' => 7]]];
    }

    public function test_owner_worked_example_07_icc_section_4(): void
    {
        $r = Icc::calc($this->example());
        $this->assertSame(105, $r['cycle_days']); $this->assertSame(934, $r['safety_units']); $this->assertSame(7934, $r['reorder_point']);
        $this->assertSame(7960, $r['order_qty']); $this->assertSame(199, $r['cartons']); $this->assertEqualsWithDelta(16.238, $r['cbm'], 0.001);
        $this->assertSame(105, $r['cover_days']); $this->assertSame('RED', $r['status']); $this->assertSame(14, $r['late_days']); $this->assertEqualsWithDelta(2986.67, $r['lost_profit'], 0.01);
        $this->assertSame('2026-11-12', $r['order_by']); $this->assertSame('RAIL', $r['best_mode'], 'Rail arrives before the stock-out with the cheapest freight');
        $air = collect($r['compare'])->firstWhere('mode', 'AIR'); $this->assertSame(59, $air['cycle_days']); $this->assertSame(0, $air['late_days']);
        $this->assertSame(['2026-11-26', '2026-12-28', '2027-03-06'], array_column($r['cash'], 'when'), 'deposit today, balance day 32, freight + duty + VAT day 100');
        $this->assertEqualsWithDelta(7960 * 2 * 0.3, $r['cash'][0]['amount'], 0.01);
        // a production window over a holiday adds its days; no sales → NONE, nothing to order
        $h = Icc::calc($this->example(['holiday_cal' => true, 'holidays' => [['name' => 'Golden Week', 'date_from' => '2026-12-10', 'date_to' => '2026-12-17', 'add_days' => 7]]]));
        $this->assertSame(112, $h['cycle_days']); $this->assertSame('Golden Week', $h['holiday_name']);
        $n = Icc::calc($this->example(['monthly' => 0])); $this->assertSame('NONE', $n['status']); $this->assertSame(0, $n['order_qty']); $this->assertNull($n['order_by']);
        $g = Icc::calc($this->example(['available' => 20000])); $this->assertSame('GREEN', $g['status']); $this->assertSame(0, $g['late_days']);
    }

    public function test_pipeline_store_task_and_screens(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id); Perm::flush();
        $wh = $this->f('cat_inventory_locations', ['location_name' => 'ICC WH', 'kind' => 'WAREHOUSE', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1]);
        $p = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'ICC-1', 'product_name_vendor' => 'Icc one', 'f_active' => 1, 'e_product_type' => 'pool', 'units_per_carton' => 10, 'carton_l' => 50, 'carton_w' => 40, 'carton_h' => 30, 'carton_gross_weight' => 8, 'landing_price' => 2, 'moq' => 100]);
        \App\Services\StockService::receive(1, [['product' => $p, 'qty' => 30, 'location' => $wh, 'unit_cost' => 2]], 'OPENING', null, ['lot_source' => 'OPENING']);
        for ($i = 0; $i < 6; $i++) $this->f('t_sales', ['fk_id_ent' => 1, 'fk_product_id' => $p, 'qty' => 50, 'order_no' => 'ICC-O' . $i, 'sku' => 'ICC-1', 'd_order' => now()->subDays(5 + $i * 14)->toDateString(), 'line_status' => 'OPEN', 'status' => 'Confirmed']);
        $this->assertSame(118, Icc::monthlyFromSales($p), '300 units over the 76 days really covered (first sale 75 days ago) → 118 a month');
        // an open PO of 200: 120 produced, 80 shipped, 50 received → ordered 80 · production 40 · transit 30 (= 200 − 50 received)
        $po = $this->f('t_src_po', ['fk_id_ent' => 1, 'po_code' => 'PO-ICC', 'status' => 'CONFIRMED', 'f_active' => 1]);
        $ln = $this->f('t_src_po_lines', ['fk_id_ent' => 1, 'fk_id_po' => $po, 'fk_id_product' => $p, 'qty_ordered' => 200, 'f_active' => 1, 'line_type' => 'PRODUCT']);
        $this->f('t_src_production', ['fk_id_ent' => 1, 'fk_id_po' => $po, 'produced_qty' => 120, 'f_active' => 1]);
        $sh = $this->f('t_src_shipments', ['fk_id_ent' => 1, 'fk_id_po' => $po, 'f_active' => 1]);
        $this->f('t_src_shipment_po_link', ['fk_id_shipment' => $sh, 'fk_id_po' => $po, 'fk_id_po_line' => $ln, 'fk_id_product' => $p, 'actual_qty' => 80, 'f_active' => 1]);
        $grn = $this->f('t_src_grn', ['fk_id_ent' => 1, 'grn_code' => 'G-ICC', 'f_active' => 1]);
        $this->f('t_src_grn_lines', ['fk_id_grn' => $grn, 'fk_id_po_line' => $ln, 'fk_id_product' => $p, 'accepted_qty' => 50]);
        $this->assertSame(['ordered' => 80, 'production' => 40, 'transit' => 30, 'po_ids' => [$po]], Icc::pipeline($p));
        // GET works it out without writing anything
        $before = DB::table('icc_results')->count();
        $g = $this->getJson("/api/icc/$p")->assertOk()->json(); $this->assertSame(30, $g['inputs']['available']); $this->assertSame(150, $g['result']['ordered'] + $g['result']['production'] + $g['result']['transit'], 'each PO unit once: 200 − 50 received');
        $this->assertSame($before, DB::table('icc_results')->count()); $this->assertNull(DB::table('t_product_vendors')->where('id', $p)->value('icc_run_at'));
        $this->assertSame('AIR', $this->getJson("/api/icc/$p?mode=AIR")->json('inputs.mode'));
        // POST saves the inputs, stores the result, the chip and the STK_REORDER task (RED → URGENT, one per product)
        $x = $this->postJson("/api/icc/$p", ['inputs' => ['monthly_sales' => 900, 'ship_mode' => 'SEA', 'safety_days' => 14]])->assertOk()->json();
        $this->assertSame('RED', $x['status']); $this->assertSame('RED', DB::table('t_product_vendors')->where('id', $p)->value('icc_status'));
        $this->assertSame(1, DB::table('icc_results')->where('fk_id_product', $p)->count()); $this->assertSame(900, (int) DB::table('icc_inputs')->where('fk_id_product', $p)->value('monthly_sales'));
        $t = DB::table('tasks')->where('dedupe_key', "STK_REORDER:$p")->first(); $this->assertSame('URGENT', $t->priority); $this->assertStringStartsWith('Order ', $t->title); $this->assertSame("#/icc/$p", $t->fix_url);
        $this->postJson("/api/icc/$p", ['inputs' => []])->assertOk(); $this->assertSame(1, DB::table('tasks')->where('dedupe_key', "STK_REORDER:$p")->count(), 'dedupe');
        $this->postJson("/api/icc/$p", ['inputs' => ['ship_mode' => 'BOAT']])->assertStatus(422);
        // enough stock → covered, the task closes by itself
        \App\Services\StockService::receive(1, [['product' => $p, 'qty' => 9000, 'location' => $wh, 'unit_cost' => 2]], 'OPENING', null, ['lot_source' => 'OPENING']);
        $this->assertSame('GREEN', Icc::run($p)['result']['status']); $this->assertSame('DONE', DB::table('tasks')->where('id', $t->id)->value('status'));
        // the board, the chart slice and the nightly run
        $b = $this->getJson('/api/icc')->assertOk()->json(); $this->assertContains($p, array_column($b['rows'], 'id')); $this->assertGreaterThanOrEqual(1, $b['counts']['covered']);
        $this->assertContains($p, array_column($this->getJson('/api/icc?status=GREEN')->json('rows'), 'id')); $this->assertNotContains($p, array_column($this->getJson('/api/icc?status=RED')->json('rows'), 'id'));
        $this->assertSame([$p], array_column($this->getJson('/api/products?per=50&q=ICC-1&icc=OK')->json('rows'), 'id'));
        $this->assertGreaterThanOrEqual(1, Icc::recalcAll(1)['run']);
        $va = $this->makeUser('VA'); $this->actingAs($va); Perm::flush(); $this->postJson("/api/icc/$p", ['inputs' => []])->assertStatus(403);
    }

    public function test_data_health_score_and_home_cards(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id); Perm::flush();
        $h0 = \App\Services\DataHealth::score(1); $this->assertCount(5, $h0['parts']);
        for ($i = 0; $i < 4; $i++) $this->f('t_sales', ['fk_id_ent' => 1, 'qty' => 1, 'order_no' => 'DH-' . $i, 'sku' => 'DH', 'd_order' => now()->subDays(3)->toDateString(), 'status' => 'Confirmed', 'cost_gbp' => $i ? 2 : null]);
        $h = \App\Services\DataHealth::score(1); $c = collect($h['parts'])->firstWhere('key', 'costs');
        $this->assertSame(1, $c['bad'] - collect($h0['parts'])->firstWhere('key', 'costs')['bad']); $this->assertLessThan(100, $c['score']);
        $this->assertSame((int) round(array_sum(array_column($h['parts'], 'score')) / 5), $h['score'], 'the score is the average of the five parts');
        $this->assertSame($h['parts'][0]['score'], min(array_column($h['parts'], 'score')), 'weakest part first');
        $cards = collect($this->getJson('/api/home')->assertOk()->json('cards') ?? []);
        if ($cards->isEmpty()) $cards = collect(\App\Services\HomeCards::for($this->owner, 1));
        $this->assertNotNull($cards->firstWhere('label', 'Data health')); $icc = $cards->firstWhere('label', 'Stock cover (ICC)'); $this->assertNotNull($icc); $this->assertStringStartsWith('#/icc', $icc['go']);
    }

    public function test_backup_prune_drill_and_health(): void
    {
        $dir = \App\Services\Backups::dir(); $made = [];
        try {
            $live = array_map(fn ($t) => (string) array_values((array) $t)[0], DB::select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"));
            $dump = implode("\n", array_map(fn ($t) => "CREATE TABLE `$t` (\n  `id` int\n);\nINSERT INTO `$t` VALUES (1);", $live)) . "\n-- Dump completed on 2026-11-26\n";
            // 20 daily dumps over two months: 14 newest + the first of each month stay
            for ($i = 0; $i < 20; $i++) { $d = date('Ymd', strtotime("2026-11-26 -" . ($i * 3) . ' days')); $f = "$dir/mo-$d-031500.sql.gz"; file_put_contents($f, gzencode($dump)); touch($f, strtotime($d)); $made[] = $f; }
            $removed = \App\Services\Backups::prune(); $left = array_column(\App\Services\Backups::files(), 'name');
            $this->assertSame(20 - count($left), $removed); $this->assertContains('mo-20261126-031500.sql.gz', $left); $this->assertCount(14 + 2, $left, '14 newest + the oldest dump of September and October');
            $r = \App\Services\Backups::drill(); $this->assertTrue($r['passed']); $this->assertSame(count($live), $r['tables']);
            file_put_contents("$dir/mo-20261127-031500.sql.gz", gzencode("CREATE TABLE `x` (id int);\n")); $made[] = "$dir/mo-20261127-031500.sql.gz";
            try { \App\Services\Backups::drill(); $this->fail('a cut-off dump passed'); } catch (\RuntimeException $e) { $this->assertStringContainsString('cut off', $e->getMessage()); }
            $st = \App\Services\Backups::status(); $this->assertNotEmpty($st['warnings'], 'no BACKUP run line yet → warning on System › Health');
            $this->actingAs($this->owner); $this->assertArrayHasKey('backup', $this->getJson('/api/health')->assertOk()->json());
            $this->assertContains('BACKUP', array_column(\App\Services\JobLog::overview(), 'code'));
        } finally { foreach (glob("$dir/mo-*.sql.gz") as $f) if (in_array($f, $made, true)) @unlink($f); }
    }

    public function test_sys_error_from_job_unknown_event_and_listener(): void
    {
        Context::set(1, $this->owner->id);
        $r = \App\Services\JobLog::run('BACKUP', 1, fn () => throw new \RuntimeException('disk full'));
        $this->assertSame('FAILED', $r['status']); $this->assertTrue(\App\Services\TaskEngine::isOpen('SYS_ERROR', ['job', 'BACKUP']));
        try { \App\Services\EventBus::fire('NO_SUCH_EVENT_X', 't', 1); $this->fail('unknown event accepted'); } catch (\InvalidArgumentException $e) {}
        $this->assertTrue(\App\Services\TaskEngine::isOpen('SYS_ERROR', ['unknown_event', 'NO_SUCH_EVENT_X']));
        \App\Services\EventBus::listen('ICC_OK', fn () => throw new \RuntimeException('listener broke'));
        $id = \App\Services\EventBus::fire('ICC_OK', 't_product_vendors', 1); DB::table('sys_events')->where('id', $id)->update(['attempts' => 4]);
        $this->assertFalse(\App\Services\EventBus::process($id)); $this->assertTrue(\App\Services\TaskEngine::isOpen('SYS_ERROR', ['listener', 'ICC_OK']));
    }
}
