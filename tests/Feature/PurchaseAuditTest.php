<?php

namespace Tests\Feature;

use App\Services\PurchaseAudit as A;
use App\Services\RecordService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-A_SALES-60 eBay audit import, 61 item-note parser, 62 file vs sales, 63 Amazon audit, 64 GD audit – the spec tests. */
class PurchaseAuditTest extends TestCase
{
    private int $shop; private int $amz; private int $gdv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = (int) DB::table('t_branches')->insertGetId(RecordService::fillNotNull('t_branches', ['fk_id_ent' => 1, 'shop_no' => '105', 'shop_name' => 'Audit shop', 'f_active' => 1]));
        $this->amz = (int) DB::table('t_vendors')->insertGetId(RecordService::fillNotNull('t_vendors', ['fk_id_ent' => 1, 'vendor_name' => 'Amazon UK', 'iso_vendor' => 'AMZ', 'f_active' => 1, 'audit_profile' => 'AMAZON']));
        $this->gdv = (int) DB::table('t_vendors')->insertGetId(RecordService::fillNotNull('t_vendors', ['fk_id_ent' => 1, 'vendor_name' => 'GD Wholesale', 'iso_vendor' => 'GD', 'f_active' => 1, 'audit_profile' => 'GD']));
    }

    /** One eBay row: [col => value] over the 69 columns. */
    private function row(array $v): string
    {
        $r = array_fill_keys(A::EBAY, ''); foreach ($v as $k => $x) $r[$k] = $x;
        return implode(',', array_map(fn ($x) => '"' . str_replace('"', '""', (string) $x) . '"', $r));
    }
    private function file(array $rows): string { return implode("\n", array_merge([implode(',', array_map(fn ($c) => str_replace('_', ' ', $c), A::EBAY))], array_map(fn ($r) => $this->row($r), $rows))) . "\n"; }
    private function line(string $srn, string $order, string $sku, int $qty, string $sold, string $note = '', string $tx = 'T1', array $x = []): array
    {
        return $x + ['Sales_record_number' => $srn, 'Order_number' => $order, 'Custom_label' => $sku, 'Item_title' => "Item $sku", 'Quantity' => (string) $qty, 'Sold_for' => $sold, 'Sale_date' => '05-Jul-26', 'My_item_note' => $note, 'Transaction_ID' => $tx];
    }

    public function test_r60_r61_import_and_note_parser(): void
    {
        $run = A::createRun($this->shop, '2026-07', $this->owner);
        $csv = $this->file([
            ['Sales_record_number' => '501', 'Order_number' => '11-0001', 'Buyer_name' => 'Jane Buyer', 'Post_to_name' => 'Jane Buyer', 'Post_to_postcode' => 'AB1 2CD', 'Transaction_ID' => ''],      /* master */
            $this->line('501', '11-0001', 'MUG1', 2, '£4.50', 'AMZ : 204-555 : 05-07-26 : 2 : 4.50 {late}', 'T1'),
            $this->line('501', '11-0001', 'MUG2', 1, '£6.00', '[MUG9] GD : PB-77=HO-1001 : 05-07-26 : 1 : x', 'T2'),
            $this->line('502', '11-0002', 'B-LOCAL', 3, '£2.00', '', 'T3', ['Buyer_name' => 'Local Buyer']),
        ]);
        try { A::importEbay($run, '999_jul-2026.csv', $csv, $this->owner); $this->fail('wrong shop prefix'); } catch (\InvalidArgumentException $e) { $this->assertSame('[105] / [999] not matched', $e->getMessage()); }
        try { A::importEbay($run, '105_jul.csv', "a,b,c\n1,2,3\n", $this->owner); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('No. of Columns not matched , see template', $e->getMessage()); }
        $r = A::importEbay($run, '105_jul-2026.csv', $csv, $this->owner);
        $this->assertSame(3, $r['imported']); $this->assertSame('SALE-LOADED', DB::table('t_audit_main')->where('id', $run)->value('e_audit_status'));
        $rows = DB::table('ebay_audit')->where('fk_id_audit_main', $run)->orderBy('id')->get();
        $this->assertSame(['Jane Buyer', 'Jane Buyer'], [$rows[0]->Buyer_name, $rows[1]->Buyer_name], 'a 2-item order → 2 rows, both with the buyer name from the master');
        $this->assertSame([(int) $this->amz, '204-555', '2026-07-05', 2, '4.50', '9.00', 'late', '9.00'], [(int) $rows[0]->fk_id_vendor, $rows[0]->pur_ref_no, $rows[0]->d_purchase, (int) $rows[0]->qty_pur, $rows[0]->pur_rate, $rows[0]->tot_pur_amount, $rows[0]->reason_by_va, $rows[0]->Total_price]);
        $this->assertSame(['MUG9', 'PB-77', 'HO-1001'], [$rows[1]->sku_ebay, $rows[1]->pur_ref_no, $rows[1]->pur_ref_no_gd_hold], '[SKU] overrides; GD buyRef=holdRef');
        $this->assertStringContainsString('rate "x" is not a number', (string) $rows[1]->parse_error, 'a bad rate is an error on the row, the import goes on');
        $this->assertSame([1, null, 3], [(int) $rows[2]->f_local, $rows[2]->fk_id_vendor, (int) $rows[2]->qty_pur], 'B… SKU = local stock, no buy rate');
        $this->assertSame([$rows[1]->id], array_map(fn ($x) => $x->id, A::invalidNotes($run, $this->owner)), 'notes that did not parse');
        $bad = $this->file([$this->line('600', '11-0009', 'X', 1, 'abc')]);
        try { A::importEbay($run, '105_x.csv', $bad, $this->owner); $this->fail(); } catch (\InvalidArgumentException) { $this->assertSame(3, DB::table('ebay_audit')->where('fk_id_audit_main', $run)->count(), 'a non-numeric sold for stops the import – the old rows stay'); }
    }

    public function test_r62_missing_extra_different(): void
    {
        $this->actingAs($this->owner); $run = A::createRun($this->shop, '2026-07', $this->owner);
        A::importEbay($run, '105_jul.csv', $this->file([$this->line('1', 'O-1', 'A', 1, '5.00'), $this->line('2', 'O-2', 'B', 2, '3.00'), $this->line('3', 'O-3', 'C', 1, '7.00')]), $this->owner);
        $sale = fn ($o, $sku, $q, $r) => DB::table('t_sales')->insertGetId(RecordService::fillNotNull('t_sales', ['fk_id_ent' => 1, 'fk_id_branch' => $this->shop, 'order_no' => $o, 'sku' => $sku, 'qty' => $q, 'sale_rate' => $r, 'total_amount' => $q * $r, 'd_order' => '2026-07-05', 'status' => 'Pending']));
        $sale('O-1', 'A', 1, 5.00); $s2 = $sale('O-2', 'B', 1, 3.00); $sale('O-X', 'Z', 1, 9.00);
        $c = A::compare($run, $this->owner);
        $this->assertSame(['missing' => 1, 'extra' => 1, 'different' => 1], $c['counts']);
        $this->assertSame(['O-3', 'O-X'], [$c['missing'][0]['order'], $c['extra'][0]['order']]);
        $this->assertSame([2, 1], $c['different'][0]['diff']['qty'], 'shown as file over current');
        $this->postJson("/api/purchase-audit/$run/accept/$s2")->assertOk();
        $this->assertSame(0, A::compare($run, $this->owner)['counts']['different']);
        // the Month Close check, then the two actions close the gap
        $chk = fn () => collect(\App\Services\CloseService::evaluate(1, $this->shop, '2026-07')['checks'])->firstWhere('code', 'ORDERS_FILE_VS_SALES');
        $this->assertSame(['RED', 2], [$chk()['status'], $chk()['open_items']], 'one missing, one extra');
        $this->postJson("/api/purchase-audit/$run/create-missing", ['order' => 'O-3', 'sku' => 'C'])->assertOk();
        $this->assertSame([1, 7.0, 'Pending'], [DB::table('t_sales')->where('order_no', 'O-3')->count(), (float) DB::table('t_sales')->where('order_no', 'O-3')->value('total_amount'), DB::table('t_sales')->where('order_no', 'O-3')->value('status')]);
        $this->postJson("/api/purchase-audit/$run/create-missing", ['order' => 'O-3', 'sku' => 'C'])->assertStatus(422);
        $extra = (int) DB::table('t_sales')->where('order_no', 'O-X')->value('id');
        $this->postJson("/api/purchase-audit/$run/delete-extra/$extra", [])->assertStatus(422)->assertJsonPath('message', 'Give the reason for deleting the sale.');
        $this->postJson("/api/purchase-audit/$run/delete-extra/$extra", ['reason' => 'test order'])->assertOk();
        $this->assertSame(['missing' => 0, 'extra' => 0, 'different' => 0], A::compare($run, $this->owner)['counts']);
        $this->assertSame('GREEN', $chk()['status']);
    }

    public function test_r63_amazon_price_and_customer(): void
    {
        $this->actingAs($this->owner); $run = A::createRun($this->shop, '2026-07', $this->owner);
        A::importEbay($run, '105_jul.csv', $this->file([
            $this->line('1', 'O-1', 'A', 1, '8.00', 'AMZ : 204-1 : 05-07-26 : 1 : 4.50', 'T1', ['Post_to_name' => 'Jane Buyer']),
            $this->line('2', 'O-2', 'K', 1, '8.00', 'AMZ : 204-1 : 05-07-26 : 1 : 4.50', 'T2', ['Post_to_name' => 'Jane Buyer']),
        ]), $this->owner);
        $r = A::uploadAmazon($run, $this->amz, "order_no,date,amount,name,address\n204-1,05-Jul-26,£9.00,jane buyer,1 Road\n,,,,\n", $this->owner);
        $this->assertSame([1, []], [$r['rows'], $r['diff']], 'eBay lines noted 4.50 + 4.50 against Amazon order 9.00 → no issue');
        $r = A::uploadAmazon($run, $this->amz, "order_no,date,amount,name,address\n204-1,05-Jul-26,£9.50,Someone Else,1 Road\n", $this->owner);
        $this->assertSame(['[Price]', '[Customer]'], $r['diff'][0]['tags'], 'against 9.50 → [Price]; another customer → [Customer]');
        $this->assertSame([9.0, 9.5], $r['diff'][0]['buy_amount']);
        try { A::uploadAmazon($run, $this->gdv, "x\n", $this->owner); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('no AMAZON audit profile', $e->getMessage()); }
    }

    public function test_r64_gd_rows_checks_and_hold_task(): void
    {
        $this->actingAs($this->owner); $run = A::createRun($this->shop, '2026-07', $this->owner);
        A::importEbay($run, '105_jul.csv', $this->file([
            $this->line('1', 'O-1', 'A', 2, '8.00', 'GD : PB-10 : 05-07-26 : 2 : 3.00', 'T1', ['Post_to_name' => 'Jane Buyer', 'Post_to_postcode' => 'AB1 2CD']),
            $this->line('2', 'O-2', 'K', 1, '8.00', 'GD : PB-11=HO-2002 : 05-07-26 : 1 : 3.00', 'T2', ['Post_to_name' => 'Tom', 'Post_to_postcode' => 'ZZ1 1ZZ']),
        ]), $this->owner);
        $g = fn ($order, $sku, $qty, $price, $rec = 'Jane Buyer', $pc = 'AB1 2CD', $date = '2026-07-01 10:00') => implode(',', [$order, $date, 'GBP', '9', $sku, 'T', 'N', $qty, $price, 'TR', 'TRK', 'p@x', 'a1', '', '', 'City', 'C', '', 'UK', $rec, $pc, 'e@x', '0']);
        $csv = implode("\n", [$g('HO-1001', 'S1', 1, '3.00', 'H', 'H', '2026-05-01 10:00'), $g('', 'S1b', 1, '3.00', 'H', 'H', ''), $g('PB-10', 'SA', 2, '3.00'), $g('PB-99', 'SZ', 1, '3.00')]) . "\n";
        try { A::uploadGd($run, $this->gdv, "a,b\n", $this->owner); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('No. of Columns not matched , see template', $e->getMessage()); }
        $r = A::uploadGd($run, $this->gdv, $csv, $this->owner);
        $rows = DB::table('pur_audit_gd')->where('fk_id_audit_main', $run)->orderBy('id')->get();
        $this->assertSame(['HO-1001', 'HOLD'], [$rows[1]->order_no, $rows[1]->e_hold_buy], 'an HO-1001 row with a blank row below → the second row has order HO-1001 (and is HOLD)');
        $c = collect($r['checks'])->keyBy('n');
        $this->assertSame(['HO-1001'], $c[1]['rows'], 'check 1: HO-1001 not in any note → count 1');
        $this->assertSame(['HO-2002'], $c[2]['rows']); $this->assertSame(['PB-99'], $c[3]['rows']); $this->assertSame(['PB-11'], $c[4]['rows']);
        $this->assertSame([], $c[5]['rows'], 'PB-10 matches: qty, rate, customer, postcode, total');
        $this->assertTrue(\App\Services\TaskEngine::isOpen('DS_HOLD_UNUSED', ['gd_hold', $this->gdv, 'HO-1001']), 'hold ageing: not used after 14 days → cancel-hold task');
        DB::table('ebay_audit')->where('fk_id_audit_main', $run)->where('pur_ref_no', 'PB-10')->update(['pur_rate' => 3.50, 'tot_pur_amount' => 7.00]);
        $this->assertSame(['[Rate]', '[Total Pur]'], A::gd($run, $this->gdv, $this->owner)['checks'][4]['rows'][0]['tags']);
    }
    /** R-A_SALES-59 the rule test: a run with supplier Amazon shows the Amazon tiles, not the GD ones; chips; status by hand; delete removes the loaded lines only. */
    public function test_r59_run_supplier_chips_status_delete(): void
    {
        $this->actingAs($this->owner);
        $run = $this->postJson('/api/purchase-audit', ['shop' => $this->shop, 'month' => date('Y-m'), 'vendor' => $this->amz])->assertOk()->json('id');
        $old = A::createRun($this->shop, date('Y-m', strtotime('first day of -3 months')), $this->owner);
        $row = collect($this->getJson('/api/purchase-audit')->json('rows'))->firstWhere('id', $run);
        $this->assertSame(['Amazon UK', true, false], [$row['supplier'], $row['tiles']['amazon'], $row['tiles']['gd']]);
        $this->assertSame([$run], array_column($this->getJson('/api/purchase-audit?period=current')->json('rows'), 'id'));
        $this->assertSame([$old], array_column($this->getJson('/api/purchase-audit?period=start')->json('rows'), 'id'));
        $this->assertSame([], $this->getJson('/api/purchase-audit?status=SALE-LOADED')->json('rows'));
        $this->postJson("/api/purchase-audit/$run/status", ['status' => 'SALE-LOADED'])->assertStatus(422)->assertJsonPath('message', 'No eBay file is loaded for this run yet.');
        $this->postJson("/api/purchase-audit/$old/status", ['status' => 'SKIP'])->assertOk();
        $this->assertSame('SKIP', DB::table('t_audit_main')->where('id', $old)->value('e_audit_status'));
        $this->assertSame([(int) $this->amz], array_map(fn ($v) => (int) $v['id'], $this->getJson("/api/purchase-audit/$run")->json('vendors')), 'only the run\'s supplier');
        A::importEbay($run, '105_x.csv', $this->file([$this->line('1', 'O-1', 'A', 1, '5.00')]), $this->owner);
        $this->postJson("/api/purchase-audit/$run/delete")->assertOk();
        $this->assertSame([0, 0], [DB::table('t_audit_main')->where('id', $run)->count(), DB::table('ebay_audit')->where('fk_id_audit_main', $run)->count()]);
    }
    /** R-G_CORE-81: fix "Copy tracking and address from BUY to HOLD" – preview old → new, history row per HOLD row, Undo restores. */
    public function test_g81_copy_tracking_from_buy_to_hold_with_undo(): void
    {
        $this->actingAs($this->owner); $run = A::createRun($this->shop, '2026-08', $this->owner); $lines = []; $gd = [];
        $g = fn ($order, $sku, $trk, $rec, $pc) => implode(',', [$order, '2026-08-01 10:00', 'GBP', '9', $sku, 'T', 'N', 1, '3.00', 'TR', $trk, 'p@x', $rec . ' street', '', '', 'City', 'C', '', 'UK', $rec, $pc, 'e@x', '0']);
        for ($i = 1; $i <= 4; $i++) { $lines[] = $this->line((string) $i, "G-$i", "K$i", 1, '8.00', "GD : PB-5$i=HO-55$i : 05-08-26 : 1 : 3.00", "T$i"); $gd[] = $g("PB-5$i", "SB$i", "TRK-BUY-$i", "Buyer $i", "PC$i"); $gd[] = $g("HO-55$i", "SH$i", '', 'Warehouse', 'WH1'); }
        $lines[] = $this->line('9', 'G-9', 'K9', 1, '8.00', 'GD : PB-59=HO-55 : 05-08-26 : 1 : 3.00', 'T9');      // HOLD ref H-55 with no GD HOLD row
        A::importEbay($run, '105_aug.csv', $this->file($lines), $this->owner); $r = A::uploadGd($run, $this->gdv, implode("\n", $gd) . "\n", $this->owner);
        $this->assertContains('HO-55', $r['checks'][1]['rows'], 'a HOLD ref of a sale line that is not on the supplier file is listed');
        $audit = DB::table('sys_audit_log')->where('table_name', 'pur_audit_gd')->count();
        $pv = $this->getJson("/api/purchase-audit/$run/gd-copy/{$this->gdv}")->assertOk()->json(); $this->assertSame([false, 4], [$pv['applied'], count($pv['rows'])]);
        $this->assertSame('', (string) DB::table('pur_audit_gd')->where('order_no', 'HO-551')->value('tracking_number'), 'the preview writes nothing'); $this->assertSame($audit, DB::table('sys_audit_log')->where('table_name', 'pur_audit_gd')->count());
        $this->assertContains('tracking_number', $pv['rows'][0]['fields']);
        // the rule: copy-tracking fix on 4 HOLD rows → 4 history rows, Undo restores
        $ok = $this->postJson("/api/purchase-audit/$run/gd-copy/{$this->gdv}")->assertOk()->json(); $this->assertSame('4 HOLD row(s) updated from their BUY order.', $ok['message']);
        $h = DB::table('pur_audit_gd')->where('order_no', 'HO-552')->first(); $this->assertSame(['TRK-BUY-2', 'Buyer 2', 'PC2', 'Buyer 2 street'], [$h->tracking_number, $h->recipient, $h->buyer_post_code, $h->address1]);
        $this->assertSame($audit + 4, DB::table('sys_audit_log')->where('table_name', 'pur_audit_gd')->count());
        $this->assertSame(0, count($this->getJson("/api/purchase-audit/$run/gd-copy/{$this->gdv}")->json('rows')), 'nothing left to copy');
        $this->postJson('/api/purchase-audit/gd-copy/undo/' . $ok['job'])->assertOk()->assertJsonPath('message', 'Undone: 4 HOLD row(s) put back as they were.');
        $h = DB::table('pur_audit_gd')->where('order_no', 'HO-552')->first(); $this->assertSame(['', 'Warehouse', 'WH1'], [(string) $h->tracking_number, $h->recipient, $h->buyer_post_code]);
        $this->postJson('/api/purchase-audit/gd-copy/undo/' . $ok['job'])->assertStatus(422);
    }
}
