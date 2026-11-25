<?php

namespace Tests\Feature;

use App\Services\DashCards;
use App\Services\Fx;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_F_SETUP R-F_SETUP-15 widget isolation, R-F_SETUP-20 Needs action, R-F_SETUP-21 supplier payments box. */
class DashCardsTest extends TestCase
{
    private function f(string $t, array $d): int
    {
        foreach (['fk_id_ent' => 1, 'f_active' => 1, 'created_at' => now()] as $k => $v) if (! array_key_exists($k, $d) && \Illuminate\Support\Facades\Schema::hasColumn($t, $k)) $d[$k] = $v;
        return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
    }

    protected function tearDown(): void { DashCards::$break = []; parent::tearDown(); }

    public function test_a_failing_card_does_not_stop_the_page_and_alerts_once_a_day(): void
    {
        $this->actingAs($this->owner);
        DashCards::$break = ['shipments'];
        for ($i = 0; $i < 5; $i++) {
            $x = $this->getJson('/api/buying/card/shipments')->assertOk()->json();
            $this->assertSame([false, 'Shipments on the way', DashCards::MSG], [$x['ok'], $x['label'], $x['error']]);
            $this->assertStringContainsString('Shipments on the way: SQLSTATE', $x['admin'], 'R-B_SOURCING-41 the admin sees the error line');
        }
        $mgr = $this->makeUser('MANAGER'); $y = $this->actingAs($mgr)->getJson('/api/buying/card/shipments')->assertOk()->json(); $this->assertSame('Could not load – retry', $y['error']);
        $this->assertStringNotContainsString('SQLSTATE', json_encode($y), 'other users never see SQL text'); $this->assertArrayNotHasKey('admin', $y); $this->actingAs($this->owner);
        $this->assertTrue($this->getJson('/api/buying/card/contacts')->assertOk()->json('ok'), 'the other cards render');
        $this->assertTrue($this->getJson('/api/buying/card/needs')->json('ok'));
        $this->assertNotNull($this->getJson('/api/buying')->assertOk()->json('kpi'));
        $err = DB::table('sys_error_log')->where('url', 'card:shipments')->get();
        $this->assertCount(1, $err); $this->assertSame(6, (int) $err[0]->times_seen); $this->assertSame($this->owner->id, (int) $err[0]->user_id);
        $this->assertSame('shipments', json_decode($err[0]->context_json, true)['card']);
        $this->assertSame(1, DB::table('tasks')->where('rule_code', 'SYS_ERROR')->where('dedupe_key', 'like', '%dash:shipments:' . date('Y-m-d') . '%')->count(), 'one admin alert even after 5 reloads the same day');
        DashCards::$break = ['stages'];
        $d = $this->getJson('/api/buying')->assertOk()->json();
        $this->assertSame(['Open cycles'], $d['failed']); $this->assertNull($d['stages']); $this->assertNotNull($d['kpi']);
    }

    public function test_payments_box_rule_example(): void
    {
        $this->actingAs($this->owner);
        DB::table('t_src_cycles')->update(['f_active' => 0]);
        Fx::set('USD', '2026-11-01', 0.79); Fx::set('USD', date('Y-m-d'), 0.79);
        $usd = DB::table('cat_currency')->where('currency_code', 'USD')->value('id') ?: $this->f('cat_currency', ['currency_code' => 'USD', 'currency_name' => 'Dollar']);
        $gbp = DB::table('cat_currency')->where('currency_code', 'GBP')->value('id') ?: $this->f('cat_currency', ['currency_code' => 'GBP', 'currency_name' => 'Pound']);
        $cy = $this->f('t_src_cycles', ['cycle_code' => 'CYC-PB-' . uniqid(), 'product_name' => 'Lamp', 'status' => 'Open', 'cycle_type' => 'FULL', 'created_by' => $this->owner->id]);
        $p1 = $this->f('t_src_po', ['fk_id_cycle' => $cy, 'po_number' => 'PO-PB-1', 'status' => 'CONFIRMED', 'po_total' => 10000, 'fk_id_currency' => $usd, 'po_date' => '2026-11-01']);
        $p2 = $this->f('t_src_po', ['fk_id_cycle' => $cy, 'po_number' => 'PO-PB-2', 'status' => 'CONFIRMED', 'po_total' => 5000, 'fk_id_currency' => $gbp, 'po_date' => '2026-11-01']);
        $this->f('t_src_payments', ['fk_id_cycle' => $cy, 'fk_id_po' => $p2, 'payment_code' => 'PB-PAY', 'payment_amount' => 1000, 'base_amount' => 1000]);
        $this->f('t_src_payment_terms', ['fk_id_po' => $p1, 'term_no' => 1, 'milestone' => 'Balance', 'planned_amount' => 10000, 'remaining_amount' => 10000, 'status' => 'DUE', 'due_date' => date('Y-m-d', strtotime('+3 days'))]);
        $this->f('t_src_payment_terms', ['fk_id_po' => $p2, 'term_no' => 1, 'milestone' => 'Balance', 'planned_amount' => 4000, 'remaining_amount' => 4000, 'status' => 'DUE', 'due_date' => date('Y-m-d', strtotime('+30 days'))]);
        $x = $this->getJson('/api/buying/card/payments')->assertOk()->json('data');
        $this->assertEquals([12900, 1000, 11900, 7900, '#/src-outstanding'], [$x['open_po_value'], $x['paid'], $x['terms_due'], $x['due_7_days'], $x['link']]);
    }

    public function test_needs_action_rule_example_and_sort(): void
    {
        $this->actingAs($this->owner);
        DB::table('t_src_cycles')->update(['f_active' => 0]);
        $cy = $this->f('t_src_cycles', ['cycle_code' => 'CYC-NA-' . uniqid(), 'product_name' => 'Lamp', 'status' => 'Open', 'cycle_type' => 'FULL', 'created_by' => $this->owner->id, 'skipped_steps' => 'rfq,samples,po,pay,production,qc,allocation,shipment,freight,customs,landed_cost']);
        $g = $this->f('t_src_grn', ['fk_id_cycle' => $cy, 'grn_number' => 'GRN-NA-1', 'units_received' => 90, 'missing_units' => 10, 'damaged_units' => 0]);
        $po = $this->f('t_src_po', ['fk_id_cycle' => $cy, 'po_number' => 'PO-NA', 'status' => 'CONFIRMED']);
        $this->f('t_src_qc', ['fk_id_cycle' => $cy, 'fk_id_po' => $po, 'qc_code' => 'QC-NA', 'qty_inspected' => 10, 'qty_failed' => 1]);
        $x = $this->getJson('/api/buying/card/needs')->assertOk()->json('data');
        $grn = collect($x['rows'])->firstWhere('code', 'SRC_GRN_SHORT');
        $this->assertSame(['P2', 'Claim short delivery · GRN-NA-1', 'Received 90 · Accepted 90 · Damaged 0 · Missing 10'], [$grn['p'], $grn['title'], $grn['detail']]);
        $this->assertSame('P1', $x['rows'][0]['p'], 'P1 first');
        $this->f('t_src_payment_terms', ['fk_id_po' => $po, 'fk_id_cycle' => $cy, 'term_no' => 1, 'milestone' => 'Deposit', 'planned_amount' => 300, 'remaining_amount' => 300, 'status' => 'DUE']);
        DB::table('t_src_po')->where('id', $po)->update(['fk_id_currency' => DB::table('cat_currency')->where('currency_code', 'GBP')->value('id') ?: $this->f('cat_currency', ['currency_code' => 'GBP', 'currency_name' => 'Pound'])]);
        $pay = collect(DashCards::needs(1)['rows'])->firstWhere('code', 'SRC_PAY_DUE');
        $this->assertSame(['Pay Term 1 · Deposit', 'Remaining 300.00 of 300.00 GBP (PO-NA)'], [$pay['title'], $pay['detail']]);
        $this->assertSame($x['count'], count($x['rows'])); $this->assertFalse($x['more']);
        DB::table('t_src_cycles')->where('id', $cy)->update(['f_active' => 0]);
        $this->assertSame([0, 'Nothing pending — all clear.'], [DashCards::needs(1)['count'], DashCards::needs(1)['empty']]);
    }
}
