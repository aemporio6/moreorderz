<?php

namespace Tests\Feature;

use App\Services\Fx;
use App\Services\RecordService;
use App\Services\SourcingReports;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_F_SETUP R-F_SETUP-3 landed cost, R-F_SETUP-4 outstanding supplier payments, R-F_SETUP-9 PO payment position. */
class SourcingReportsTest extends TestCase
{
    private function f(string $t, array $d): int
    {
        foreach (['fk_id_ent' => 1, 'f_active' => 1, 'created_at' => now()] as $k => $v) if (! array_key_exists($k, $d) && \Illuminate\Support\Facades\Schema::hasColumn($t, $k)) $d[$k] = $v;
        return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
    }

    private function cycle(): int { return $this->f('t_src_cycles', ['cycle_code' => 'CYC-R-' . uniqid(), 'product_name' => 'Lamp', 'status' => 'Open', 'cycle_type' => 'FULL', 'created_by' => $this->owner->id]); }

    public function test_landed_cost_rule_example(): void
    {
        $this->actingAs($this->owner);
        $cy = $this->cycle(); $cp = $this->f('t_src_cycle_products', ['fk_id_cycle' => $cy, 'product_name' => 'Desk lamp', 'target_unit_cost' => 4.5]);
        $po = $this->f('t_src_po', ['fk_id_cycle' => $cy, 'po_number' => 'PO-R-1', 'status' => 'CONFIRMED']); $this->f('t_src_po_lines', ['fk_id_cycle' => $cy, 'fk_id_po' => $po, 'fk_id_cycle_product' => $cp, 'qty_ordered' => 2000]);
        $this->f('t_src_landed_cost', ['fk_id_cycle' => $cy, 'fk_id_po' => $po, 'lc_code' => 'LC-R-1', 'product_cost' => 8000, 'freight_cost' => 1200, 'customs_duty' => 400, 'other_cost' => 0, 'accepted_qty' => 2000, 'freight_method' => 'QUANTITY', 'duty_method' => 'VALUE']);
        $this->f('t_src_landed_cost', ['fk_id_cycle' => $cy, 'fk_id_po' => $po, 'lc_code' => 'LC-R-0', 'product_cost' => 5, 'accepted_qty' => 0]);
        $x = $this->getJson("/api/src-report/landed/$cy")->assertOk()->json();
        $r = $x['rows'][0];
        $this->assertEquals([9600, 4.8, 4.5, 0.3, 6.7, true], [$r['total'], $r['per_unit'], $r['target'], $r['variance'], $r['variance_pct'], $r['over_target']]);
        $this->assertSame(['QUANTITY', 'VALUE'], [$r['freight_method'], $r['duty_method']]);
        $this->assertSame('No accepted qty', $x['rows'][1]['chip']); $this->assertEquals(0, $x['rows'][1]['per_unit']);
        $this->assertEquals([9605, 2000, 4.8025], [$x['totals']['total'], $x['totals']['accepted'], $x['totals']['per_unit']]);
        $this->assertCount(10, $x['buckets']); $this->assertArrayNotHasKey('import_vat', $x['buckets']);
    }

    public function test_outstanding_rule_example_and_ageing(): void
    {
        $this->actingAs($this->owner);
        Fx::set('USD', date('Y-m-d'), 0.79);
        $usd = DB::table('cat_currency')->where('currency_code', 'USD')->value('id') ?: $this->f('cat_currency', ['currency_code' => 'USD', 'currency_name' => 'Dollar']);
        $cy = $this->cycle(); $s = $this->f('t_src_suppliers', ['supplier_code' => 'RS-' . uniqid(), 'supplier_name' => 'Acme Lamps']);
        $po = $this->f('t_src_po', ['fk_id_cycle' => $cy, 'fk_id_supplier' => $s, 'po_number' => 'PO-OUT-1', 'status' => 'CONFIRMED', 'po_total' => 10000, 'fk_id_currency' => $usd]);
        $this->f('t_src_payments', ['fk_id_po' => $po, 'fk_id_cycle' => $cy, 'payment_code' => 'P-OUT-1', 'payment_amount' => 3000, 'fk_id_currency' => $usd, 'base_amount' => 2370, 'payment_date' => date('Y-m-d')]);
        $t = $this->f('t_src_payment_terms', ['fk_id_po' => $po, 'term_no' => 2, 'milestone' => 'Balance', 'pct' => 70, 'planned_amount' => 7000, 'remaining_amount' => 7000, 'status' => 'DUE', 'due_date' => date('Y-m-d', strtotime('-2 days'))]);
        $paid = $this->f('t_src_po', ['fk_id_cycle' => $cy, 'fk_id_supplier' => $s, 'po_number' => 'PO-OUT-PAID', 'status' => 'CONFIRMED', 'po_total' => 500, 'fk_id_currency' => $usd]);
        $this->f('t_src_payments', ['fk_id_po' => $paid, 'payment_code' => 'P-OUT-2', 'payment_amount' => 500, 'fk_id_currency' => $usd, 'payment_date' => date('Y-m-d')]);
        $x = $this->getJson('/api/src-report/outstanding?cycle=' . $cy)->assertOk()->json();
        $rows = collect($x['groups'])->flatMap(fn ($g) => $g['rows']);
        $this->assertSame(['PO-OUT-1'], $rows->pluck('po')->all(), 'a fully paid PO does not appear');
        $r = $rows->first();
        $this->assertEquals([7000, 5530, 'USD', 'Overdue', 'red'], [$r['outstanding'], $r['outstanding_base'], $r['currency'], $r['ageing'], $r['tone']]);
        $this->assertSame($t, $r['pay']['fk_id_term']);
        $this->assertEquals(5530, $x['total_base']); $this->assertSame('TOTAL OUTSTANDING', $x['total_label']);
        DB::table('t_src_payment_terms')->where('id', $t)->update(['due_date' => date('Y-m-d', strtotime('+3 days'))]);
        $this->assertSame('Due ≤ 7 days', SourcingReports::outstanding(1, ['cycle' => $cy])['groups'][0]['rows'][0]['ageing']);
        DB::table('t_src_payment_terms')->where('id', $t)->update(['due_date' => null]);
        $this->assertSame('No due date', SourcingReports::outstanding(1, ['cycle' => $cy])['groups'][0]['rows'][0]['ageing']);
        $this->assertSame([], SourcingReports::outstanding(1, ['cycle' => $cy, 'ageing' => 'Overdue'])['groups']);
    }

    public function test_po_payment_position_warnings_and_link_to_term(): void
    {
        $this->actingAs($this->owner);
        $cy = $this->cycle(); $s = $this->f('t_src_suppliers', ['supplier_code' => 'PS-' . uniqid(), 'supplier_name' => 'Acme']);
        $po = $this->f('t_src_po', ['fk_id_cycle' => $cy, 'fk_id_supplier' => $s, 'po_number' => 'PO-POS-1', 'status' => 'CONFIRMED', 'po_total' => 1000]);
        $t1 = $this->f('t_src_payment_terms', ['fk_id_po' => $po, 'term_no' => 1, 'milestone' => 'Deposit', 'pct' => 30, 'planned_amount' => 300, 'paid_amount' => 300, 'remaining_amount' => 0, 'status' => 'PAID']);
        $t2 = $this->f('t_src_payment_terms', ['fk_id_po' => $po, 'term_no' => 2, 'milestone' => 'Balance', 'pct' => 60, 'planned_amount' => 600, 'remaining_amount' => 600, 'status' => 'DUE']);
        $this->f('t_src_payments', ['fk_id_po' => $po, 'fk_id_term' => $t1, 'payment_code' => 'PAY-T1', 'payment_amount' => 300, 'payment_date' => '2026-11-01']);
        $loose = $this->f('t_src_payments', ['fk_id_po' => $po, 'payment_code' => 'PAY-LOOSE', 'payment_amount' => 200, 'payment_date' => '2026-11-10']);
        $x = $this->getJson("/api/po/$po/position")->assertOk()->json();
        $this->assertSame(['Terms add up to 90 %, not 100 %', 'Terms total differs from PO total by 100.00'], $x['warnings']);
        $this->assertSame('Term 1: Deposit — 30% = 300.00 · Paid 300.00 · Remaining 0.00 · Paid (100%)', $x['cards'][0]['text']);
        $this->assertSame(['green', 'grey'], array_column($x['cards'], 'tone'));
        $this->assertSame(['PAY-T1'], array_column($x['cards'][0]['payments'], 'code'));
        $this->assertSame(['PAY-LOOSE'], array_column($x['not_linked'], 'code'));
        $this->assertSame([['id' => $t2, 'label' => 'Term 2']], $x['unpaid_terms']);
        $this->postJson("/api/src-payments/$loose/link-term", ['term' => $t2])->assertOk()->assertJsonPath('message', 'Payment PAY-LOOSE linked to Term 2.');
        $x = SourcingReports::poPosition(1, $po);
        $this->assertSame([], $x['not_linked']);
        $this->assertSame(['Partially Paid', 'amber'], [$x['cards'][1]['status'], $x['cards'][1]['tone']]);
        $this->assertEquals(400, $x['cards'][1]['remaining']);
        $this->postJson("/api/src-payments/$loose/link-term", ['term' => 999999])->assertStatus(422);
    }
}
