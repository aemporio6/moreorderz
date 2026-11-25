<?php

namespace Tests\Feature;

use App\Services\CycleAlerts;
use App\Services\DashCards;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_F_SETUP R-F_SETUP-18 open-cycle filters and R-F_SETUP-33 team activity card. */
class CycleFiltersTeamTest extends TestCase
{
    private function f(string $t, array $d): int
    {
        foreach (['fk_id_ent' => 1, 'f_active' => 1, 'created_at' => now()] as $k => $v) if (! array_key_exists($k, $d) && \Illuminate\Support\Facades\Schema::hasColumn($t, $k)) $d[$k] = $v;
        return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
    }

    public function test_open_cycle_filters_rule_example(): void
    {
        $this->actingAs($this->owner);
        DB::table('t_src_cycles')->update(['f_active' => 0]);
        $cy = fn ($code, $st = 'Open') => $this->f('t_src_cycles', ['cycle_code' => $code, 'product_name' => 'X', 'status' => $st, 'cycle_type' => 'FULL', 'created_by' => $this->owner->id]);
        $a = $cy('SC-AAA-1'); $b = $cy('SC-BBB-2'); $cy('SC-DONE-3', 'Completed'); $e = $cy('SC-EMPTY-4', '');
        $s1 = $this->f('t_src_suppliers', ['supplier_code' => 'F1-' . uniqid(), 'supplier_name' => 'Alpha Co']); $s2 = $this->f('t_src_suppliers', ['supplier_code' => 'F2-' . uniqid(), 'supplier_name' => 'Beta Co']);
        $p = $this->f('t_product_vendors', ['SKU_VENDOR' => 'LS-' . uniqid(), 'product_name_vendor' => 'LED strip']);
        $po = $this->f('t_src_po', ['fk_id_cycle' => $a, 'fk_id_supplier' => $s1, 'po_number' => 'PO-F-1', 'status' => 'DRAFT']);
        $this->f('t_src_po_lines', ['fk_id_cycle' => $a, 'fk_id_po' => $po, 'fk_id_product' => $p, 'qty_ordered' => 5]);
        $this->f('t_src_cycle_products', ['fk_id_cycle' => $b, 'fk_id_supplier' => $s2, 'product_name' => 'Fan']);
        $this->f('t_src_production', ['fk_id_cycle' => $b, 'prod_code' => 'PR-F']);
        DB::table('t_src_cycles')->where('id', $b)->update(['skipped_steps' => 'rfq,samples,po,pay']);
        $codes = fn ($f) => array_column(CycleAlerts::dashboard(1, 1, 60, $f)['rows'], 'code');
        $this->assertSame(['SC-EMPTY-4', 'SC-BBB-2', 'SC-AAA-1'], $codes([]), 'open = not Completed (empty status counts), newest first');
        $this->assertSame(['SC-AAA-1'], $codes(['q' => 'led']), 'q matches the PO line product, not the code');
        $this->assertSame(['SC-BBB-2'], $codes(['supplier' => $s2]), 'supplier of a cycle product');
        $this->assertSame(['SC-AAA-1'], $codes(['supplier' => $s1]), 'supplier of a PO');
        $this->assertSame(['SC-BBB-2'], $codes(['stage' => 'qc']), 'stage tile: current = QC after Production');
        $d = CycleAlerts::dashboard(1, 1, 60, ['stage' => 'qc']);
        $this->assertSame(['QC / Inspection', 'No open cycles match the filter.'], [$d['filters']['stage_name'], $d['empty']]);
        $this->assertSame(1, collect($d['tiles'])->firstWhere('key', 'qc')['open']);
        $this->assertSame(['Alpha Co' => 1, 'Beta Co' => 1], collect($d['suppliers'])->pluck('count', 'name')->all(), 'count next to each supplier');
        $this->assertSame([], $codes(['q' => 'nothing-like-this']));
        // R-B_SOURCING-50 deep filters: a payment's bank reference, the supplier order reference, the investor, the sourcing date
        $this->f('t_src_payments', ['fk_id_cycle' => $b, 'payment_code' => 'PAY-F', 'txn_ref' => 'WISE-88412', 'f_active' => 1]);
        DB::table('t_src_cycles')->where('id', $a)->update(['order_ref' => 'PI-7731', 'sourcing_date' => '2026-03-01']); DB::table('t_src_cycles')->whereIn('id', [$b, $e])->update(['sourcing_date' => '2026-09-01']);
        $this->assertSame([['SC-BBB-2'], ['SC-AAA-1']], [$codes(['q' => 'wise-88412']), $codes(['q' => 'pi-7731'])], 'the test of the rule: a payment ref finds its cycle');
        $inv = $this->f('t_account', ['fk_id_ent' => 1, 'account_code' => 'INV-F', 'first_name' => 'Iris', 'f_active' => 1]);
        $this->f('t_src_investments', ['fk_id_cycle' => $a, 'invest_code' => 'INV-F1', 'fk_id_investor' => $inv, 'invest_amount' => 100, 'f_active' => 1]);
        $this->assertSame(['SC-AAA-1'], $codes(['investor' => $inv]));
        $this->assertSame(['SC-AAA-1'], $codes(['to' => '2026-06-30']));
        $this->assertSame(['SC-EMPTY-4', 'SC-BBB-2'], $codes(['from' => '2026-06-01']));
        // URL filters are the user's saved view; Reset clears it
        $this->getJson('/api/buying?q=led')->assertOk();
        $this->assertSame(['SC-AAA-1'], array_column($this->getJson('/api/buying')->json('cycles'), 'code'), 'saved view');
        $this->assertCount(3, $this->getJson('/api/buying?reset=1')->json('cycles'));
        $this->assertCount(3, $this->getJson('/api/buying')->json('cycles'));
    }

    public function test_team_activity_rule_example(): void
    {
        $this->actingAs($this->owner);
        $va = $this->f('t_users', ['login_id' => 'va-' . uniqid() . '@demo.test', 'display_name' => 'VA', 'role_code' => 'VA', 'fk_id_ent' => 1, 'f_active' => 1]);
        $br = (int) (DB::table('t_branches')->value('id') ?: $this->f('t_branches', ['shop_name' => 'Shop T', 'shop_no' => 'T1']));
        for ($i = 0; $i < 4; $i++) $this->f('t_sales', ['fk_id_branch' => $br, 'order_no' => "T-$i", 'd_order' => date('Y-m-d'), 'sku' => 'X', 'qty' => 1, 'created_by' => $va]);
        $this->f('t_sale_imports', ['fk_id_branch' => $br, 'imported_by' => $va, 'imported_at' => now()]);
        $x = DashCards::team(1, date('Y-m-d'), date('Y-m-d'));
        $r = collect($x['rows'])->firstWhere('user_id', $va);
        $this->assertSame('VA — 4 sales entered, 1 imports', $r['line']);
        $this->assertSame('#/audit?user_id=' . $va . '&from=' . date('Y-m-d') . '&to=' . date('Y-m-d'), $r['drill']);
        $this->assertSame('No activity in period', DashCards::team(1, '2001-01-01', '2001-01-02')['empty']);
        $this->assertSame([], DashCards::team(1, '2001-01-01', '2001-01-02')['rows']);
        $this->assertSame('TODAY', $this->getJson('/api/buying/card/team')->assertOk()->json('data.period.key'), 'Team default = Today');
    }
}
