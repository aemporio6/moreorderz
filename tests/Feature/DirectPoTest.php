<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\DirectPo;
use App\Services\Fx;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_B_SOURCING R-B_SOURCING-2: Direct PO in one transaction – repeat from the last PO, last price, rate stored, cycle DIRECT, cartons, terms, nothing saved on failure. */
class DirectPoTest extends TestCase
{
    private function f(string $t, array $d): int { return DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }

    public function test_the_rule_example_repeat_two_lines_total_2070(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id); Fx::set('USD', date('Y-m-d'), 0.80); Fx::set('USD', '2026-06-01', 0.79);
        $usd = \App\Services\BuyingService::currencyId('USD');
        $s = $this->f('t_src_suppliers', ['fk_id_ent' => 1, 'supplier_code' => 'S-DP' . random_int(100, 999), 'supplier_name' => 'Supplier S', 'fk_id_currency' => $usd, 'f_active' => 1]);
        $a = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'DP-A', 'product_name_vendor' => 'Item A', 'f_active' => 1]);
        $b = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'DP-B', 'product_name_vendor' => 'Item B', 'f_active' => 1]);
        // the supplier's last PO: A 500 × 2.10, B 300 × 3.40
        $old = \App\Services\BuyingService::createPo(['supplier' => $s, 'currency' => 'USD', 'date' => '2026-06-01', 'lines' => [['product' => $a, 'qty' => 500, 'unit_price' => 2.10], ['product' => $b, 'qty' => 300, 'unit_price' => 3.40]]]);

        $pre = $this->getJson("/api/direct-po/prefill?supplier=$s")->assertOk()->json();
        $this->assertSame([$old, 'USD', 0.8], [$pre['repeat_from'], $pre['currency'], (float) $pre['rate']], 'default = the last PO; supplier currency; today\'s rate');
        $this->assertEquals([[$a, 500, 2.10], [$b, 300, 3.40]], array_map(fn ($l) => [$l['product'], $l['qty'], $l['unit_price']], $pre['lines']), 'lines pre-filled at the last price');

        $this->postJson('/api/direct-po', ['lines' => $pre['lines']])->assertStatus(422)->assertJsonPath('message', 'Select the Supplier');
        $this->postJson('/api/direct-po', ['supplier' => $s, 'lines' => []])->assertStatus(422)->assertJsonPath('message', 'Add at least one product line (name + qty)');
        $this->postJson('/api/direct-po', ['supplier' => $s, 'lines' => [['qty' => 5, 'unit_price' => 1]]])->assertStatus(422);
        $before = DB::table('t_src_cycles')->count();

        $lines = $pre['lines']; $lines[0]['units_per_carton'] = 50;
        $r = $this->postJson('/api/direct-po', ['supplier' => $s, 'repeat_from' => $old, 'currency' => 'USD', 'rate' => 0.81, 'deposit_pct' => 30, 'notes' => 'PI 77', 'lines' => $lines])->assertOk()->json();
        $this->assertSame('Created. Opening Payments...', $r['message']);
        $po = DB::table('t_src_po')->where('id', $r['po'])->first();
        $this->assertEquals([2070.00, 2070.00, 'CONFIRMED', 0.81, $old], [(float) $po->po_total, (float) $po->balance_amount, $po->status, (float) $po->exchange_rate, (int) $po->fk_id_repeat_from_po], 'PO total 2,070.00, balance 2,070.00, rate kept');
        $this->assertSame(2, DB::table('t_src_po_lines')->where('fk_id_po', $po->id)->count());
        $c = DB::table('t_src_cycles')->where('id', $r['cycle'])->first();
        $this->assertSame(['DIRECT', 'APPROVED', 'rfq,samples,production,qc'], [$c->cycle_type, $c->approval_status, $c->skipped_steps]);
        $this->assertSame(2, DB::table('t_src_cycle_products')->where('fk_id_cycle', $c->id)->where('row_status', 'CONFIRMED')->count());
        $this->assertSame([10, 50], [(int) DB::table('t_src_cartons')->where('fk_id_po', $po->id)->value('carton_qty'), (int) DB::table('t_src_cartons')->where('fk_id_po', $po->id)->value('units_per_carton')]);
        $this->assertSame([621.00, 1449.00], DB::table('t_src_payment_terms')->where('fk_id_po', $po->id)->orderBy('term_no')->pluck('planned_amount')->map(fn ($x) => (float) $x)->all(), 'deposit 30 % + balance');
        $this->assertStringContainsString("PO {$po->po_number} · 2 lines · total 2,070.00", (string) DB::table('sys_audit_log')->where('table_name', 't_src_cycles')->where('record_id', $c->id)->value('changes_json'));
        $this->assertTrue(DB::table('sys_events')->where('event_code', 'PO_CONFIRMED')->where('entity_id', $po->id)->exists());
        // paid in full → one 100 % term
        $r2 = $this->postJson('/api/direct-po', ['supplier' => $s, 'paid_in_full' => true, 'lines' => [['product' => $a, 'qty' => 10]]])->assertOk()->json();
        $this->assertSame([[1, 100.0]], DB::table('t_src_payment_terms')->where('fk_id_po', $r2['po'])->get(['term_no', 'pct'])->map(fn ($t) => [(int) $t->term_no, (float) $t->pct])->all());
        $this->assertEquals(2.10, (float) DB::table('t_src_po_lines')->where('fk_id_po', $r2['po'])->value('unit_price'), 'no price typed → the last price paid to this supplier');
        $this->assertSame(['Full Payment', 'PENDING'], [(string) DB::table('t_src_payment_terms')->where('fk_id_po', $r2['po'])->value('milestone'), (string) DB::table('t_src_payment_terms')->where('fk_id_po', $r2['po'])->value('status')], 'R-B_SOURCING-3');
        // no deposit % and no tick → no terms; the Payments step shows the hint
        $r3 = $this->postJson('/api/direct-po', ['supplier' => $s, 'lines' => [['product' => $a, 'qty' => 10, 'unit_price' => 2]]])->assertOk()->json();
        $this->assertSame(0, DB::table('t_src_payment_terms')->where('fk_id_po', $r3['po'])->count());
        $pay = collect(\App\Services\CycleAlerts::pipeline(DB::table('t_src_cycles')->where('id', $r3['cycle'])->first())['steps'])->firstWhere('key', 'pay');
        $this->assertSame('Add the payment schedule', $pay['hint'] ?? null);
        // deposit due = PO date, balance due = Expected ready
        $r4 = $this->postJson('/api/direct-po', ['supplier' => $s, 'deposit_pct' => 25, 'expected_ready' => '2026-12-15', 'required_delivery' => '2027-01-20', 'lines' => [['product' => $a, 'qty' => 10, 'unit_price' => 3.33]]])->assertOk()->json();
        $this->assertSame([[8.33, date('Y-m-d')], [24.97, '2026-12-15']], DB::table('t_src_payment_terms')->where('fk_id_po', $r4['po'])->orderBy('term_no')->get()->map(fn ($t) => [(float) $t->planned_amount, $t->due_date])->all(), 'balance = total − deposit');
        $this->assertSame($before + 4, DB::table('t_src_cycles')->count());
        $this->assertSame(3.33, DirectPo::lastPrice(1, $s, $a), "the latest price paid");
    }
}
