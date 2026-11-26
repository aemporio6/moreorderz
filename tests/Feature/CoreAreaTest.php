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

    public function test_demand_forecast_season_and_trend(): void
    {
        Context::set(1, $this->owner->id);
        $p = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'FC-1', 'product_name_vendor' => 'Forecast', 'f_active' => 1, 'e_product_type' => 'pool']);
        $sale = function (string $d, int $q) use ($p) { static $n = 0; $n++; $this->f('t_sales', ['fk_id_ent' => 1, 'fk_product_id' => $p, 'qty' => $q, 'order_no' => 'FC-' . $n, 'sku' => 'FC-1', 'd_order' => $d, 'status' => 'Confirmed']); };
        // a year of 30 units a month, last November 90 (a Christmas month); the last 90 days 40 a month against 30 before
        for ($m = 1; $m <= 15; $m++) $sale(date('Y-m-15', strtotime("2026-11-26 -$m months")), date('m', strtotime("2026-11-26 -$m months")) === '11' ? 90 : 30);
        $this->assertNull(Icc::forecast($p, '2026-01-10'), 'less than 300 days of sales → no forecast');
        $f = Icc::forecast($p, '2026-11-26');
        $this->assertNotNull($f); $this->assertGreaterThan(2.0, $f['season'], 'November sold 3 × the usual month last year');
        $in = Icc::inputs($p, [], '2026-11-26'); $this->assertSame($f['season'], $in['season']); $this->assertStringContainsString('Demand forecast', implode(' ', $in['assumed']));
        \App\Services\Choices::set('ICC_FORECAST', 'CALENDAR', 1); $this->assertSame(1.5, Icc::inputs($p, [], '2026-11-26')['season'], 'calendar choice → Oct–Jan ×1.5');
    }

    public function test_deadline_calendar_and_ics(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id); Perm::flush();
        $ym = date('Y-m');
        $this->f('acc_obligations', ['fk_id_ent' => 1, 'entity_type' => 'COMPANY', 'entity_id' => 1, 'type_code' => 'VAT', 'area' => 'VAT', 'due_date' => "$ym-20", 'status' => 'PENDING', 'f_done' => 0, 'title' => 'VAT return Q3', 'gen_key' => uniqid()]);
        $d = $this->getJson("/api/deadlines?ym=$ym")->assertOk()->json();
        $this->assertSame('VAT return Q3', $d['days']["$ym-20"][0]['title']); $this->assertSame('#/compliance', $d['days']["$ym-20"][0]['go']);
        $ics = $this->get('/api/deadlines/ics')->assertOk(); $this->assertStringContainsString('text/calendar', $ics->headers->get('Content-Type'));
        $this->assertStringContainsString('SUMMARY:VAT return Q3', $ics->getContent()); $this->assertStringContainsString('DTSTART;VALUE=DATE:' . str_replace('-', '', $ym) . '20', $ics->getContent());
        $va = $this->makeUser('VA'); $this->actingAs($va); Perm::flush(); $this->assertArrayNotHasKey("$ym-20", $this->getJson("/api/deadlines?ym=$ym")->json('days'), 'filings only with money.SEE');
    }

    public function test_return_abuse_list_task_and_warning(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id); Perm::flush();
        $ids = [];
        foreach ([1, 2, 3, 4] as $i) $ids[] = $this->f('t_sales', ['fk_id_ent' => 1, 'qty' => 1, 'order_no' => "RA-$i", 'sku' => 'RA', 'd_order' => now()->subDays(10 * $i)->toDateString(), 'status' => 'Confirmed', 'total_amount' => 20,
            'ship_to_name' => 'Jane  Doe', 'ship_to_postcode' => 'AB1 2CD', 'ship_to_address' => '1 High St, Town']);
        foreach (array_slice($ids, 0, 3) as $sid) $this->f('t_sale_returns', ['fk_id_ent' => 1, 'fk_id_sale' => $sid, 'qty' => 1, 'created_at' => now()]);
        $d = \App\Services\ReturnAbuse::scan(1); $buyer = collect($d['rows'])->firstWhere('kind', 'Buyer');
        $this->assertSame(3, $buyer['claims']); $this->assertSame(4, $buyer['orders']); $this->assertSame(75.0, (float) $buyer['rate']);
        $this->assertSame(2, \App\Services\ReturnAbuse::tasks(1), 'one task for the buyer, one for the address'); $this->assertSame(0, \App\Services\ReturnAbuse::tasks(1), 'once');
        $this->assertStringContainsString('3 returns', \App\Services\ReturnAbuse::warning(1, $ids[3])['text']);
        $this->assertNotNull($this->getJson("/api/sales/{$ids[3]}/details")->json('buyer_warning'));
        $tl = $this->makeUser('TL'); $this->actingAs($tl); Perm::flush(); $row = $this->getJson('/api/return-abuse')->assertOk()->json('rows.0');
        if (! Perm::has($tl, 'orders.dsSeeContact')) $this->assertSame('J•••', $row['name']);
    }

    public function test_fee_check_overcharge_task_and_proof(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id); Perm::flush();
        $mp = $this->f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'FEEMP', 'marketplace_descr' => 'Fee MP', 'f_active' => 1, 'fee_pct' => 10, 'fee_value' => 0.30]);
        $shop = $this->f('t_branches', ['fk_id_ent' => 1, 'shop_no' => 'FEE1', 'fk_id_marketplace' => $mp, 'f_active' => 1]);
        $ym = '2026-10'; $n = 0;
        $line = function (string $order, string $bucket, float $amt) use ($shop, &$n) { $n++; $this->f('fin_settlement_lines', ['batch_id' => 1, 'company_id' => 1, 'branch_id' => $shop, 'settlement_id' => 'S1', 'transaction_type' => 'Order', 'order_id' => $order, 'sku' => 'X', 'quantity' => 1,
            'amount_type' => $bucket, 'amount_description' => $bucket, 'amount' => $amt, 'posted_date' => '2026-10-05', 'bucket' => $bucket, 'row_hash' => sha1('fee' . $n . uniqid())]); };
        $line('O-OK', 'SALE', 50); $line('O-OK', 'FEE', -5.30);      // 10 % + 0.30 = 5.30 expected
        $line('O-OVER', 'SALE', 50); $line('O-OVER', 'FEE', -9.00);   // 3.70 over
        $d = \App\Services\FeeCheck::month(1, $ym, $shop);
        $this->assertSame(2, $d['orders']); $this->assertSame(1, $d['overcharged']); $this->assertSame(3.7, $d['over_total']); $this->assertSame('O-OVER', $d['rows'][0]['order_id']);
        $this->assertSame(1, \App\Services\FeeCheck::tasks(1, $ym)); $t = DB::table('tasks')->where('dedupe_key', "FEE_OVERCHARGE:$shop:$ym")->first(); $this->assertStringContainsString('£3.7', $t->title);
        $csv = $this->get("/api/fee-check/proof?ym=$ym&shop=$shop")->assertOk()->getContent(); $this->assertStringContainsString('O-OVER', $csv); $this->assertStringNotContainsString('O-OK', $csv);
        $this->assertSame(1, $this->getJson("/api/fee-check?ym=$ym&shop=$shop")->assertOk()->json('overcharged'));
        $va = $this->makeUser('VA'); $this->actingAs($va); Perm::flush(); $this->getJson("/api/fee-check?ym=$ym")->assertStatus(403);
    }

    public function test_feature_flags_and_release_notes(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id); Perm::flush(); \App\Services\Features::forget();
        $this->assertSame(config('releases.0.version'), $this->getJson('/api/boot')->json('whats_new'));
        $p = $this->getJson('/api/whats-new')->assertOk()->json(); $this->assertTrue($p['was_new']); $this->assertNull($this->getJson('/api/boot')->json('whats_new'), 'seen once the page is opened');
        $this->postJson('/api/whats-new/flag', ['code' => 'DATA_HEALTH', 'on' => false])->assertOk(); \App\Services\Features::forget();
        $this->assertFalse(\App\Services\Features::on('DATA_HEALTH', 1)); $this->assertNull(collect(\App\Services\HomeCards::for($this->owner, 1))->firstWhere('label', 'Data health'));
        $this->assertTrue(\App\Services\Features::on('DATA_HEALTH', 2), 'per company');
        $m = $this->makeUser('MANAGER'); $this->actingAs($m); Perm::flush(); $this->postJson('/api/whats-new/flag', ['code' => 'ICC', 'on' => false])->assertStatus(422);
    }

    public function test_importer_recommends_where_a_file_goes(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $file = function (string $name, string $body) { $p = tempnam(sys_get_temp_dir(), 'rec'); file_put_contents($p, $body); return new \Illuminate\Http\UploadedFile($p, $name, null, null, true); };
        $bank = $this->post('/api/import/recommend', ['file' => $file('statement.csv', "Date,Description,Amount,Balance\n01/10/2026,CARD PAYMENT,-12.50,100.00\n")])->assertOk()->json();
        $this->assertSame('BANK', $bank['best']['target']); $this->assertStringContainsString('date', $bank['best']['why']);
        $ebay = $this->post('/api/import/recommend', ['file' => $file('orders.csv', "\n\"Sales record number\",\"Order number\",\"Buyer username\",\"Custom label\",\"Quantity\",\"Sold for\"\n1,12-34,bob,SKU1,1,9.99\n")])->assertOk()->json();
        $this->assertSame('ORDERS', $ebay['best']['target']); $this->assertSame(95, $ebay['best']['score']);
        $this->assertContains('SALES', array_column($ebay['others'], 'target'), 'it could also be a monthly sales file');
        $this->assertSame('LABELS', $this->post('/api/import/recommend', ['file' => $file('labels.pdf', '%PDF-1.4')])->json('best.target'));
        $this->post('/api/import/recommend', ['file' => $file('x.csv', "a,b,c\n1,2,3\n")])->assertStatus(422);
        $this->post('/api/import/recommend', ['file' => $file('x.doc', 'x')])->assertStatus(422);
    }

    public function test_practice_company_is_apart(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id); Perm::flush();
        $x = $this->postJson('/api/practice-company')->assertOk()->json(); $ent = $x['id']; $this->assertTrue($x['created']);
        $this->assertFalse($this->postJson('/api/practice-company')->json('created'), 'only one');
        $this->assertSame(3, DB::table('t_product_vendors')->where('fk_id_ent', $ent)->count()); $this->assertSame(73, (int) DB::table('fin_lots as l')->join('fin_lot_balances as b', 'b.fk_id_lot', '=', 'l.id')->where('l.company_id', $ent)->sum('b.qty'));
        $this->assertNotContains($ent, \App\Services\AllCompanies::companies($this->owner)->pluck('id')->map(fn ($v) => (int) $v)->all(), 'never added up');
        $this->assertTrue(collect($this->getJson('/api/boot')->json('companies'))->firstWhere('id', $ent)['practice'] == 1);
        $id = \App\Services\Connect\Messenger::queueWaNow($ent, null, '+447700900000', 'LOGIN_CODE', ['code' => '123456']);
        $this->assertSame('IGNORED', DB::table('tm_messages')->where('id', $id)->value('status'), 'nothing leaves the tool');
        $m = $this->makeUser('MANAGER'); $this->actingAs($m); $this->postJson('/api/practice-company')->assertStatus(422);
    }

    public function test_documents_module_on_any_record_and_order(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id); Perm::flush();
        $key = \App\Services\RecordService::formOfTable('t_sales'); $this->assertNotNull($key);
        $a = $this->f('t_sales', ['fk_id_ent' => 1, 'fk_id_branch' => 0, 'qty' => 1, 'order_no' => 'DOC-ORD', 'sku' => 'A', 'd_order' => now()->toDateString(), 'status' => 'Pending']);
        $b = $this->f('t_sales', ['fk_id_ent' => 1, 'fk_id_branch' => 0, 'qty' => 1, 'order_no' => 'DOC-ORD', 'sku' => 'B', 'd_order' => now()->toDateString(), 'status' => 'Pending']);
        $this->postJson("/api/docs/$key/$a", ['title' => 'Invoice 55', 'type' => 'Invoice', 'drive_url' => 'ftp://x'])->assertStatus(422);
        $x = $this->postJson("/api/docs/$key/$a", ['title' => 'Invoice 55', 'type' => 'Invoice', 'drive_url' => 'https://drive.google.com/file/d/ABCDEFGHIJKLMN/view', 'ref_no' => 'INV-55', 'amount' => '12.50'])->assertOk()->json();
        $this->assertStringStartsWith('DOC-', $x['doc_no']);
        $d = $this->getJson("/api/docs/$key/$b")->assertOk()->json(); $this->assertSame('Order DOC-ORD', $d['title']);
        $this->assertSame('Invoice 55', $d['rows'][0]['title'], 'a document of one line shows on every line of the order'); $this->assertSame('https://drive.google.com/file/d/ABCDEFGHIJKLMN/preview', $d['rows'][0]['preview']);
        $this->postJson("/api/doc/{$x['id']}", ['title' => 'Invoice 55 (paid)'])->assertOk();
        $v = $this->postJson("/api/doc/{$x['id']}/version", ['drive_url' => 'https://drive.google.com/file/d/ZZZZZZZZZZZZZZ/view'])->assertOk()->json();
        $rows = $this->getJson("/api/docs/$key/$a")->json('rows'); $this->assertCount(1, $rows); $this->assertSame(2, (int) $rows[0]['version']); $this->assertSame('Invoice 55 (paid)', $rows[0]['title']);
        $this->assertSame('REPLACED', DB::table('sys_documents')->where('id', $x['id'])->value('status'), 'the old version is kept');
        $this->postJson("/api/doc/{$v['id']}/remove")->assertOk(); $this->assertSame([], $this->getJson("/api/docs/$key/$a")->json('rows'));
        $this->assertNotNull($this->getJson("/api/sales/$a/details")->json('doc_form'));
        $wh = $this->makeUser('WAREHOUSE'); $this->actingAs($wh); Perm::flush(); if (! Perm::has($wh, "form:$key:EDIT")) $this->postJson("/api/docs/$key/$a", ['title' => 'x', 'drive_url' => 'https://a.b'])->assertStatus(403);
    }

    public function test_lineage_file_row_stock_and_journal(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id); Perm::flush();
        $imp = $this->f('t_sale_imports', ['fk_id_ent' => 1, 'file_name' => '101A_oct26.csv', 'imported_by' => $this->owner->id, 'imported_at' => now(), 'status' => 'Pending', 'period_month' => 10, 'period_year' => 2026]);
        $sale = $this->f('t_sales', ['fk_id_ent' => 1, 'qty' => 1, 'order_no' => 'LIN-1', 'sku' => 'L1', 'd_order' => '2026-10-05', 'status' => 'Pending', 'fk_id_import' => $imp, 'batch_row_no' => 7, 'raw_csv' => json_encode(['Order' => 'LIN-1', 'SKU' => 'L1'])]);
        $key = \App\Services\RecordService::formOfTable('t_sales');
        $x = $this->getJson("/api/lineage/$key/$sale")->assertOk()->json('steps');
        $this->assertSame(['Record', 'File', 'Row'], array_column($x, 'what')); $this->assertStringContainsString('101A_oct26.csv', $x[1]['text']); $this->assertStringContainsString('row 7', $x[2]['text']); $this->assertSame("#/sales?import=$imp", $x[1]['url']);
        $wh = $this->f('cat_inventory_locations', ['location_name' => 'Lin WH', 'kind' => 'WAREHOUSE', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1]);
        $p = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'LIN-P', 'product_name_vendor' => 'Lin', 'f_active' => 1, 'e_product_type' => 'pool']);
        \App\Services\StockService::receive(1, [['product' => $p, 'qty' => 5, 'location' => $wh, 'unit_cost' => 2]], 'OPENING', null, ['lot_source' => 'OPENING']);
        $lot = (int) DB::table('fin_lots')->where('company_id', 1)->where('sku', 'LIN-P')->value('id');
        $this->assertContains('Opening', array_column(\App\Services\Lineage::of('fin_lots', $lot, 1)['steps'], 'what'));
    }

    public function test_security_self_check(): void
    {
        $r = \App\Services\SecurityCheck::run(1);
        $this->assertSame(count($r['checks']), $r['total']); $this->assertTrue(collect($r['checks'])->firstWhere('check', 'No .env inside the web folder')['ok']);
        $this->assertContains('Large tables have indexes on their link columns', array_column($r['checks'], 'check'));
        $this->actingAs($this->owner); $this->assertArrayHasKey('security', $this->getJson('/api/health')->assertOk()->json());
        $this->assertFileExists(base_path('tools/loadtest.mjs'));
    }

    public function test_mytool_purchase_history_import(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id); Perm::flush();
        $json = json_encode(['format' => 'mytool', 'data' => [
            'parties' => ['SUP-901' => ['code' => 'SUP-901', 'type' => 'supplier', 'name' => 'Test Mytool Supplier'], 'FWD-901' => ['code' => 'FWD-901', 'type' => 'forwarder', 'name' => 'Test Mytool Forwarder']],
            'purchases' => ['PO-MT-1' => ['no' => 'PO-MT-1', 'oldRef' => 'OLD-1', 'date' => '2024-03-01', 'party' => 'SUP-901', 'cur' => 'USD', 'status' => 'closed', 'lines' => [['sku' => 'MT-SKU-1', 'p' => 'Brush', 'q' => 100, 'u' => 0.95, 'a' => 95]], 'extras' => []],
                'PO-MT-2' => ['no' => 'PO-MT-2', 'date' => '2024-04-01', 'party' => 'SUP-901', 'cur' => 'USD', 'desc' => 'Soap order', 'lines' => [], 'extras' => []]],
            'shipments' => ['SH-MT-1' => ['no' => 'SH-MT-1', 'date' => '2024-03-20', 'party' => 'FWD-901', 'mode' => 'air', 'goods' => 'Brushes', 'purchases' => ['PO-MT-1']], 'SH-MT-2' => ['no' => 'SH-MT-2', 'date' => '2024-05-01', 'party' => 'FWD-901', 'mode' => 'sea', 'purchases' => []]],
            'payments' => [
                'P1' => ['no' => 'P1', 'date' => '2024-03-02', 'type' => 'deposit', 'cur' => 'USD', 'amount' => 30, 'fxRate' => 0.79, 'direction' => 'out', 'allocations' => [['ref' => 'PO-MT-1', 'amount' => 30]]],
                'P2' => ['no' => 'P2', 'date' => '2024-03-15', 'type' => 'balance', 'cur' => 'USD', 'amount' => 65, 'direction' => 'out', 'forRef' => 'PO-MT-1'],
                'P3' => ['no' => 'P3', 'date' => '2024-04-02', 'type' => 'full', 'cur' => 'USD', 'amount' => 50, 'direction' => 'out', 'allocations' => [['ref' => 'PO-MT-2', 'amount' => 50]]],
                'P4' => ['no' => 'P4', 'date' => '2024-03-21', 'type' => 'freight', 'cur' => 'USD', 'amount' => 20, 'direction' => 'out', 'forRef' => 'SH-MT-1'],
                'P5' => ['no' => 'P5', 'date' => '2024-05-02', 'type' => 'freight', 'cur' => 'USD', 'amount' => 40, 'direction' => 'out', 'forRef' => 'SH-MT-2'],
                'P6' => ['no' => 'P6', 'date' => '2024-05-03', 'type' => 'legacy', 'cur' => 'USD', 'amount' => 156, 'direction' => 'out', 'allocations' => [['amount' => 156, 'belongsTo' => 'other']]]]]]);
        $c = \App\Services\MyToolImport::check(1, $json);
        $this->assertSame(3, $c['purchases']); $this->assertSame(1, $c['freight_only']); $this->assertSame(1, $c['not_purchases']); $this->assertSame(1, $c['with_freight']);
        $this->assertSame(0, DB::table('t_src_cycles')->where('order_ref', 'PO-MT-1')->count(), 'check writes nothing');
        $f = function () use ($json) { $p = tempnam(sys_get_temp_dir(), 'mt'); file_put_contents($p, $json); return new \Illuminate\Http\UploadedFile($p, 'MYTOOL-DATA.json', null, null, true); };
        $r = $this->post('/api/mytool-import', ['file' => $f(), 'apply' => 1])->assertOk()->json(); $this->assertSame(3, $r['imported'], json_encode($r['failed']));
        $cy = DB::table('t_src_cycles')->where('order_ref', 'PO-MT-1')->first(); $this->assertSame('HISTORICAL', $cy->cycle_type);
        $po = DB::table('t_src_po')->where('fk_id_cycle', $cy->id)->first(); $this->assertEqualsWithDelta(95.0, (float) $po->po_total, 0.01);
        $this->assertEqualsWithDelta(95.0, (float) DB::table('t_src_payments')->where('fk_id_po', $po->id)->sum('payment_amount'), 0.01, 'deposit + balance');
        $this->assertSame(0, (int) DB::table('fin_lots')->where('po_id', $po->id)->count(), 'nothing received – no stock counted twice');
        $this->assertSame('FORWARDER', DB::table('t_src_suppliers')->where('supplier_code', 'MT-FWD-901')->value('supplier_type'));
        $this->assertSame(0, $this->post('/api/mytool-import', ['file' => $f(), 'apply' => 1])->json('imported'), 'a second run adds nothing');
        $m = $this->makeUser('MANAGER'); $this->actingAs($m); $this->post('/api/mytool-import', ['file' => $f()])->assertStatus(403);
    }

    public function test_record_inspector_masks_secrets_and_nightly_schema_check(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id); Perm::flush();
        $bk = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'INSP-1', 'product_name_vendor' => 'Inspector test', 'f_active' => 1, 'e_product_type' => 'pool']);
        $x = $this->getJson('/api/inspect/t_product_vendor/' . $bk)->assertOk()->json();
        $cols = array_column($x['columns'], 'value', 'column'); $this->assertSame('INSP-1', $cols['SKU_VENDOR']);
        foreach ($cols as $k => $v) if (preg_match('/pass/i', $k) && $v) $this->assertSame('••••••', $v, "$k masked");
        $this->getJson('/api/inspect/no_such_form/1')->assertStatus(404);
        $m = $this->makeUser('MANAGER'); $this->actingAs($m); $this->getJson('/api/inspect/t_product_vendor/' . $bk)->assertStatus(403);
        $this->assertStringContainsString('SchemaCheck::missing()', file_get_contents(app_path('Console/Commands/MoNightly.php')));
        $js = file_get_contents(public_path('app/app.js')); foreach (['camScan', 'BarcodeDetector', 'rRaw', 'MR-1540'] as $k) $this->assertStringContainsString($k, $js);
    }

    public function test_label_reader_royal_mail_spaced_evri_dashed_return_address_and_list(): void
    {
        Context::set(1, $this->owner->id);
        $rm = "  Tracked\n  A99 A13\n                 XX 1234 5678 9GB\nJane Test\n1 Sample Street                     Return Address\nTesttown\nAB1 2CD                                M Sender\n                                       London\n                                       E1 1AA\nSKU: B268-TEST-OIL-118ml*1\n";
        $r = \App\Services\CarrierLabels::read($rm, null, ['Tracked'], []);
        $this->assertSame('XX123456789GB', $r['tracking'], 'Royal Mail prints the number in groups');
        $this->assertSame(['E1 1AA', 'AB1 2CD'], $r['postcodes'], 'both postcodes kept – the import keeps the one an open order has');
        $this->assertSame(['B268-TEST-OIL-118ML'], $r['skus']);
        $ev = "P2G Smart Send SD\n Destination        COU-PNET\n 1 Test Road\n Testcity\n B1 1AA\n Reference 1    P2G100000001\n                     H-00AB-C-012345678-9\n";
        $this->assertSame('H00ABC0123456789', \App\Services\CarrierLabels::read($ev, null, [], [])['tracking'], 'Evri / Parcel2Go prints the number with dashes');
        $list = "P2G1  H00ABC0123456781   1 x [ K1-AAA ]\nP2G2  H00ABC0123456782   1 x [ K2-BBB ]\n";
        $this->assertCount(2, \App\Services\CarrierLabels::read($list, null, [], [])['trackings'], 'a picking list shows several numbers on one page');
    }

    public function test_json_compressed_and_home_task_card_light(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id); Perm::flush();
        $plain = $this->getJson('/api/boot')->assertOk();
        $gz = $this->get('/api/boot', ['Accept' => 'application/json', 'Accept-Encoding' => 'gzip, br'])->assertOk();
        $this->assertSame('gzip', $gz->headers->get('Content-Encoding'));
        $this->assertSame($plain->getContent(), gzdecode($gz->getContent()), 'same data, smaller');
        $this->assertLessThan(strlen($plain->getContent()) / 3, strlen($gz->getContent()));
        $t = \App\Services\TaskLists::tiles($this->owner); $m = \App\Services\TaskLists::mine($this->owner)['tiles'];
        $this->assertSame($m, $t, 'the Home card counts the same as My tasks');
    }

    public function test_my_name_shown_at_the_top(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $this->postJson('/api/me/name', ['name' => '  Mo   Sheikh '])->assertOk();
        $this->assertSame('Mo Sheikh', $this->getJson('/api/boot')->json('user.name'));
        $this->postJson('/api/me/name', ['name' => ''])->assertStatus(422);
    }

    public function test_mytool_replaces_the_same_old_moreorderz_order_and_keeps_the_others(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id); Perm::flush();
        $old1 = $this->f('t_src_cycles', ['fk_id_ent' => 1, 'cycle_code' => 'OLD-SAME', 'order_ref' => 'WAP-UK-JW20240301001', 'cycle_type' => 'HISTORICAL', 'sourcing_date' => '2024-03-01', 'f_active' => 1]);
        $po1 = $this->f('t_src_po', ['fk_id_ent' => 1, 'fk_id_cycle' => $old1, 'po_code' => 'OLDPO-1', 'po_date' => '2024-03-01', 'po_total' => 500, 'f_active' => 1]);
        $this->f('t_src_po_lines', ['fk_id_ent' => 1, 'fk_id_po' => $po1, 'fk_id_cycle' => $old1, 'qty_ordered' => 10, 'f_active' => 1]);
        $old2 = $this->f('t_src_cycles', ['fk_id_ent' => 1, 'cycle_code' => 'OLD-SAMEDAY', 'order_ref' => 'X-1', 'cycle_type' => 'HISTORICAL', 'sourcing_date' => '2024-05-02', 'f_active' => 1]);
        $this->f('t_src_po', ['fk_id_ent' => 1, 'fk_id_cycle' => $old2, 'po_code' => 'OLDPO-2', 'po_date' => '2024-05-02', 'po_total' => 199, 'f_active' => 1]);
        $old3 = $this->f('t_src_cycles', ['fk_id_ent' => 1, 'cycle_code' => 'OLD-ONLY', 'order_ref' => 'WAP-UK-JW20240909009', 'cycle_type' => 'HISTORICAL', 'sourcing_date' => '2024-09-09', 'f_active' => 1]);
        $json = json_encode(['format' => 'mytool', 'data' => [
            'parties' => ['SUP-951' => ['code' => 'SUP-951', 'type' => 'supplier', 'name' => 'Test Same Supplier']],
            'purchases' => ['PO-MT-51' => ['no' => 'PO-MT-51', 'date' => '2024-03-01', 'party' => 'SUP-951', 'cur' => 'USD', 'piNo' => 'WAP-UK-JW20240301001 / WAP-UK-JW20240301002', 'lines' => [['sku' => 'S-1', 'p' => 'Same', 'q' => 10, 'u' => 50, 'a' => 500]], 'extras' => []],
                'PO-MT-52' => ['no' => 'PO-MT-52', 'date' => '2024-05-03', 'party' => 'SUP-951', 'cur' => 'USD', 'lines' => [['sku' => 'S-2', 'p' => 'Day', 'q' => 2, 'u' => 100, 'a' => 200]], 'extras' => []]],
            'shipments' => [], 'payments' => ['P51' => ['no' => 'P51', 'date' => '2024-03-01', 'type' => 'full', 'cur' => 'USD', 'amount' => 500, 'fxRate' => 0.79, 'direction' => 'out', 'allocations' => [['ref' => 'PO-MT-51', 'amount' => 500]]],
                'P52' => ['no' => 'P52', 'date' => '2024-05-03', 'type' => 'full', 'cur' => 'USD', 'amount' => 200, 'fxRate' => 0.8, 'direction' => 'out', 'allocations' => [['ref' => 'PO-MT-52', 'amount' => 200]]]]]]);
        $c = \App\Services\MyToolImport::check(1, $json);
        $this->assertSame(['PO-MT-51', 'PO-MT-52'], array_column($c['old_same'], 'mytool'), 'by the supplier order number, and by day ±2 + total ±2 %');
        $this->assertSame(2, DB::table('t_src_cycles')->whereIn('id', [$old1, $old2])->count(), 'the check removes nothing');
        $r = \App\Services\MyToolImport::import(1, $json, $this->owner);
        $this->assertSame(2, $r['removed_old']); $this->assertSame(2, $r['imported'], json_encode($r['failed']));
        $this->assertSame(0, DB::table('t_src_cycles')->whereIn('id', [$old1, $old2])->count()); $this->assertSame(0, DB::table('t_src_po')->where('id', $po1)->count()); $this->assertSame(0, DB::table('t_src_po_lines')->where('fk_id_po', $po1)->count());
        $this->assertSame(1, DB::table('t_src_cycles')->where('id', $old3)->count(), 'an old order MY TOOL does not have stays');
        $this->assertSame(1, DB::table('t_src_cycles')->where('order_ref', 'PO-MT-51')->count(), 'the MY TOOL copy is kept');
        $this->assertNotEmpty(glob(storage_path('app/mytool-replaced-*.json')), 'a copy of the removed rows is kept');
        foreach (glob(storage_path('app/mytool-replaced-*.json')) as $f) @unlink($f);
    }
}
