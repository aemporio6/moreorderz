<?php

namespace Tests\Feature;

use App\Services\RecordService;
use App\Services\SourcingBoard;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_F_SETUP R-F_SETUP-22 Contact today and R-F_SETUP-23 Shipments board. */
class SourcingBoardTest extends TestCase
{
    private function f(string $t, array $d): int
    {
        foreach (['fk_id_ent' => 1, 'f_active' => 1, 'created_at' => now()] as $k => $v) if (! array_key_exists($k, $d) && \Illuminate\Support\Facades\Schema::hasColumn($t, $k)) $d[$k] = $v;
        return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
    }

    private function world(): array
    {
        DB::table('t_src_cycles')->update(['f_active' => 0]); DB::table('t_src_shipments')->update(['f_active' => 0]);
        $cy = $this->f('t_src_cycles', ['cycle_code' => 'CYC-B-' . uniqid(), 'product_name' => 'Lamp', 'status' => 'Open', 'cycle_type' => 'FULL', 'created_by' => $this->owner->id]);
        $s = $this->f('t_src_suppliers', ['supplier_code' => 'BS-' . uniqid(), 'supplier_name' => 'Ningbo Lamps', 'phone_whatsapp' => '+86 138 0000 1111']);
        $fw = $this->f('t_src_forwarders', ['forwarder_code' => 'FW-' . uniqid(), 'forwarder_name' => 'Fast Freight', 'phone' => '+44 7700 900222']);
        $po = $this->f('t_src_po', ['fk_id_cycle' => $cy, 'fk_id_supplier' => $s, 'po_number' => 'PO-B-1', 'status' => 'CONFIRMED']);
        return [$cy, $s, $fw, $po];
    }

    public function test_contact_today_rule_example_late_shipment_by_forwarder(): void
    {
        $this->actingAs($this->owner);
        [$cy, $s, $fw, $po] = $this->world();
        $this->f('t_src_shipments', ['fk_id_cycle' => $cy, 'fk_id_po' => $po, 'fk_id_forwarder' => $fw, 'shipment_code' => 'SHP-B-LATE', 'eta' => date('Y-m-d', strtotime('-3 days'))]);
        $this->f('t_src_qc', ['fk_id_cycle' => $cy, 'fk_id_po' => $po, 'qc_code' => 'QC-B-1', 'qty_inspected' => 50, 'qty_failed' => 4]);
        $x = $this->getJson('/api/buying/contacts')->assertOk()->json();
        $f = collect($x['people'])->firstWhere('name', 'Fast Freight');
        $this->assertSame(['Forwarder', 'FF', 'ETA chase'], [$f['label'], $f['initials'], $f['items'][0]['item']], 'role label Forwarder – not "Supplier" for everyone');
        $this->assertSame('Hello Fast Freight, shipment SHP-B-LATE ETA was ' . date('d M Y', strtotime('-3 days')) . ' – please send the latest tracking.', $f['message']);
        $sup = collect($x['people'])->firstWhere('name', 'Ningbo Lamps');
        $this->assertSame(['Supplier', 'Hello Ningbo Lamps, QC QC-B-1 found 4 defects – please send corrective action.'], [$sup['label'], $sup['message']]);
        $r = $this->postJson('/api/buying/contacts/whatsapp', ['key' => $f['key'], 'cycle' => $cy, 'item' => 'ETA chase', 'text' => $f['message']])->assertOk()->json();
        $this->assertStringStartsWith('https://wa.me/447700900222?text=Hello%20Fast%20Freight', $r['url']);
        $this->assertTrue(DB::table('sys_audit_log')->where('table_name', 't_src_cycles')->where('record_id', $cy)->where('reason', 'WhatsApp to Fast Freight · ETA chase')->exists());
        // nothing open → empty text
        DB::table('t_src_cycles')->where('id', $cy)->update(['f_active' => 0]);
        $this->assertSame([[], 'No one urgent today.'], array_values(SourcingBoard::contactToday(1)));
    }

    public function test_shipments_board_rule_example_chips_and_eta_history(): void
    {
        $this->actingAs($this->owner);
        [$cy, $s, $fw, $po] = $this->world();
        $cp = $this->f('t_src_cycle_products', ['fk_id_cycle' => $cy, 'product_name' => 'Desk lamp']); $this->f('t_src_po_lines', ['fk_id_cycle' => $cy, 'fk_id_po' => $po, 'fk_id_cycle_product' => $cp, 'qty_ordered' => 10]);
        $late = $this->f('t_src_shipments', ['fk_id_cycle' => $cy, 'fk_id_po' => $po, 'fk_id_forwarder' => $fw, 'shipment_code' => 'SHP-HELD', 'actual_departure' => date('Y-m-d', strtotime('-20 days')), 'eta' => date('Y-m-d', strtotime('-5 days')), 'planned_qty' => 100, 'actual_shipped_qty' => 120, 'tracking_url' => 'https://track.example.test/1']);
        $this->f('t_src_customs', ['fk_id_cycle' => $cy, 'fk_id_shipment' => $late, 'customs_code' => 'CU-B', 'status' => 'Held']);
        $this->f('t_src_shipments', ['fk_id_cycle' => $cy, 'fk_id_po' => $po, 'shipment_code' => 'SHP-NOETA']);
        $clear = $this->f('t_src_shipments', ['fk_id_cycle' => $cy, 'fk_id_po' => $po, 'shipment_code' => 'SHP-CLEAR', 'actual_departure' => date('Y-m-d', strtotime('-2 days')), 'eta' => date('Y-m-d', strtotime('+3 days'))]);
        $this->f('t_src_customs', ['fk_id_cycle' => $cy, 'fk_id_shipment' => $clear, 'customs_code' => 'CU-C', 'status' => 'Cleared']);
        $this->f('t_src_grn', ['fk_id_cycle' => $cy, 'fk_id_shipment' => $clear, 'grn_number' => 'GRN-C']);
        // one ETA change → history
        DB::table('t_src_shipments')->where('id', $late)->update(['eta' => date('Y-m-d', strtotime('-1 day'))]);
        $x = $this->getJson('/api/buying/shipments')->assertOk()->json();
        $this->assertSame(['SHP-HELD', 'SHP-CLEAR', 'SHP-NOETA'], array_column($x['rows'], 'shipment'), 'ETA ascending, no ETA last');
        $r = $x['rows'][0];
        $this->assertSame(['Held', 'red', 'Not received · late', 'red', 'PO-B-1', ['Desk lamp']], [$r['customs'], $r['customs_tone'], $r['grn'], $r['grn_tone'], $r['po'], $r['products']], 'the rule example');
        $this->assertEquals(120, $r['units']); $this->assertSame('https://track.example.test/1', $r['tracking_url']);
        $this->assertSame(1, $r['eta_changes']); $this->assertStringStartsWith('ETA changed 1 times: ', $r['eta_history']);
        $c = $x['rows'][1]; $this->assertSame(['Cleared', 'teal', 'Received', 'teal'], [$c['customs'], $c['customs_tone'], $c['grn'], $c['grn_tone']]);
        $n = $x['rows'][2]; $this->assertSame(['Not shipped', '—', '—', 'grey', '—', 'grey'], [$n['departed'], $n['eta'], $n['customs'], $n['customs_tone'], $n['grn'], $n['grn_tone']]);
        $this->assertNull($x['show_all']); $this->assertSame(1, count(SourcingBoard::shipments(1, 1)['rows'])); $this->assertSame('#/src-transit', SourcingBoard::shipments(1, 1)['show_all']);
    }
}
