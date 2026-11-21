<?php

namespace Tests\Feature;

use App\Services\PriceService;
use App\Services\RecordService;
use App\Services\SalesService as Sales;
use App\Services\SalesWatch;
use App\Services\StockService as Stock;
use App\Services\TaskEngine;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_A_SALES R-A_SALES-88: the sales task table – owner order and priority (IC-9), raise points, closed by the system when the problem is gone. */
class SalesTasksTest extends TestCase
{
    private int $shop; private int $mp; private int $p; private int $bin;

    protected function setUp(): void
    {
        parent::setUp(); $this->actingAs($this->owner); $f = fn ($t, $d) => DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
        $this->mp = $f('cat_marketplaces', ['fk_id_ent' => 1]); $this->p = $f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'A', 'product_name_vendor' => 'Item A', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']);
        $this->bin = $f('cat_inventory_locations', ['location_name' => 'A-A-01', 'kind' => 'BIN', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1, 'pick_seq' => 1]);
        $this->shop = $f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $this->mp, 'shop_no' => 'S1', 'iso_branch' => 'S1', 'shop_name' => 'Shop A', 'f_active' => 1, 'fk_id_main_location' => $this->bin, 'fulfilment_channel' => 'SELF']);
        PriceService::set($this->p, $this->mp, 4, 10.00, '2026-08-01');
    }

    private function user(string $login, string $role): object { return \App\Models\User::create(['login_id' => $login, 'password_hash' => 'x', 'role_code' => $role, 'display_name' => $login, 'fk_id_ent' => 1, 'f_active' => 1]); }

    public function test_shortage_task_goes_to_the_stock_person_with_the_fewest_open_tasks_and_closes_on_confirm(): void
    {
        $w1 = $this->user('wh1@test', 'WAREHOUSE'); $w2 = $this->user('wh2@test', 'WAREHOUSE');      // nobody holds STOCK: the next role is WAREHOUSE
        foreach ([1, 2, 3] as $i) DB::table('tasks')->insert(['fk_id_ent' => 1, 'title' => "busy $i", 'category_id' => 1, 'owner_user_id' => $w1->id, 'priority' => 'NORMAL', 'status' => 'TODO', 'created_at' => now(), 'due_at' => now()->toDateString(), 'source' => 'MANUAL', 'last_update_at' => now()]);
        Sales::intake($this->shop, [['order_no' => 'O-1', 'd_order' => '2026-09-05', 'sku' => 'A', 'qty' => 2, 'sale_rate' => 10]]);
        try { Sales::confirmMonth($this->shop, '2026-09', true); $this->fail(); } catch (\RuntimeException $e) { $this->assertStringContainsString('Not enough stock', $e->getMessage()); }
        $t = DB::table('tasks')->where('rule_code', 'SAL_STOCK_SHORT')->get(); $this->assertCount(1, $t); $this->assertSame([(int) $w2->id, 'HIGH', 'TODO'], [(int) $t[0]->owner_user_id, $t[0]->priority, $t[0]->status]);
        Stock::receive(1, [['product' => $this->p, 'qty' => 5, 'location' => $this->bin, 'unit_cost' => 4.00, 'received_date' => '2026-08-01']], 'GRN', 1);
        Sales::confirmMonth($this->shop, '2026-09', true); $this->assertSame('DONE', DB::table('tasks')->where('id', $t[0]->id)->value('status'));      // closed by the system
        $this->assertSame('AUTO_CLOSED', DB::table('task_history')->where('task_id', $t[0]->id)->orderByDesc('id')->value('new_value'));
    }

    public function test_priority_mapping_and_money_at_risk(): void
    {
        $this->assertSame(['HIGH', 'NORMAL', 'LOW', 'URGENT', 'NORMAL'], array_map([TaskEngine::class, 'priorityOf'], ['P1', 'P2', 'P3', 'URGENT', 'x']));
        $id = TaskEngine::open('SAL_REFUND_OPEN', [$this->shop, '2026-09'], 'big', ['ent' => 1, 'amount' => 1500]); $this->assertSame('URGENT', DB::table('tasks')->where('id', $id)->value('priority'));
        $id = TaskEngine::open('SAL_DAILY_OPEN', [$this->shop, '2026-09'], 'small', ['ent' => 1, 'amount' => 20]); $this->assertSame('HIGH', DB::table('tasks')->where('id', $id)->value('priority'));
    }

    public function test_the_sweep_raises_and_closes_the_shop_month_tasks(): void
    {
        $va = $this->user('va-t@test', 'VA'); $a = Sales::intake($this->shop, [['order_no' => 'Z-1', 'd_order' => '2026-09-05', 'sku' => 'A', 'qty' => 1, 'sale_rate' => 0]])['ids'][0];
        $this->assertSame('TODO', DB::table('tasks')->where('rule_code', 'SAL_ZERO')->where('linked_record_id', $a)->value('status'));
        DB::table('t_sales')->where('id', $a)->update(['total_amount' => 10, 'sale_rate' => 10]); SalesWatch::tasks(1); $this->assertSame('DONE', DB::table('tasks')->where('rule_code', 'SAL_ZERO')->where('linked_record_id', $a)->value('status'));      // the line has a value now
        $p = DB::table('t_sale_refund_proposals')->insertGetId(['fk_id_sale' => $a, 'refund_id' => 'R1', 'refund_kind' => 'FULL', 'amount' => 10, 'status' => 'PROPOSED', 'created_at' => now()]);
        SalesWatch::tasks(1); $t = DB::table('tasks')->where('rule_code', 'SAL_REFUND_OPEN')->first(); $this->assertSame(["SAL_REFUND_OPEN:{$this->shop}:2026-09", 'TODO', (int) $va->id], [$t->dedupe_key, $t->status, (int) $t->owner_user_id]);      // no VA of the shop known: the VA with the fewest open tasks
        \App\Services\SettlementService::decideRefund(1, $p, false, 'kept'); $this->assertSame('DONE', DB::table('tasks')->where('id', $t->id)->value('status'));
        // DS_UNMATCHED: a drop shipping row of a confirmed month without its sale line
        $plat = DB::table('drp_platforms')->insertGetId(['platform_name' => 'P', 'f_active' => 1, 'created_at' => now()]); $imp = DB::table('drp_imports')->insertGetId(RecordService::fillNotNull('drp_imports', ['fk_id_ent' => 1, 'fk_platform' => $plat, 'file_name' => 'x', 'dup_mode' => 'skip']));
        $item = DB::table('drp_items')->insertGetId(RecordService::fillNotNull('drp_items', ['fk_id_ent' => 1, 'fk_platform' => $plat, 'fk_id_branch' => $this->shop, 'fk_import' => $imp, 'fk_import_last' => $imp, 'dup_key' => sha1('d1'), 'order_no' => 'D-1', 'd_order' => '2026-09-03', 'qty' => 1, 'total_cost' => 3, 'period_month' => 9, 'period_year' => 2026]));
        SalesWatch::tasks(1); $this->assertSame(0, DB::table('tasks')->where('rule_code', 'DS_UNMATCHED')->count());      // the month is not confirmed yet
        DB::table('t_sale_imports')->insert(RecordService::fillNotNull('t_sale_imports', ['fk_id_ent' => 1, 'fk_id_branch' => $this->shop, 'fk_id_marketplace' => $this->mp, 'import_nature' => 'Normal', 'file_name' => 'f', 'period_month' => 9, 'period_year' => 2026, 'status' => 'Confirmed']));
        SalesWatch::tasks(1); $this->assertSame('TODO', DB::table('tasks')->where('rule_code', 'DS_UNMATCHED')->value('status'));
        DB::table('t_sales')->where('id', $a)->update(['fk_drp_item' => $item]); SalesWatch::tasks(1); $this->assertSame('DONE', DB::table('tasks')->where('rule_code', 'DS_UNMATCHED')->value('status'));
    }

    public function test_every_code_of_the_sales_table_has_its_rule(): void
    {
        foreach (['SAL_MONTH_MISSING', 'SAL_STOCK_SHORT', 'SAL_NO_COST', 'SAL_RETURN_EXPECTED', 'SAL_ZERO', 'SAL_PRICE_MISMATCH', 'PRICE_HISTORY_MISSING', 'SAL_HUB_MISMATCH', 'SAL_DS_MARG', 'DS_UNMATCHED', 'SALE_QUERY', 'HEALTH_ALERT', 'LST_STATUS', 'IMP_WAITING', 'SAL_REFUND_OPEN', 'SAL_DAILY_OPEN'] as $c) $this->assertTrue(DB::table('sys_rules')->where('rule_code', $c)->where('f_active', 1)->exists(), $c);
    }

    public function test_a_fee_bucket_that_differs_from_the_hub_opens_a_task_and_closes_within_the_tolerance(): void
    {
        $this->actingAs($this->owner); DB::table('mo_settlements')->insert(RecordService::fillNotNull('mo_settlements', ['fk_id_ent' => 1, 'fk_id_branch' => $this->shop, 'report_type' => 'X', 'statement_id' => 'ST-9', 'statement_date' => '2026-09-20', 'currency' => 'GBP']));
        DB::table('fin_settlement_lines')->insert(RecordService::fillNotNull('fin_settlement_lines', ['batch_id' => 1, 'company_id' => 1, 'branch_id' => $this->shop, 'settlement_id' => 'ST-9', 'order_id' => 'O-9', 'amount_type' => 'fee', 'amount' => -12.50, 'bucket' => 'FEE', 'row_hash' => sha1('hub')]));
        $r = SalesWatch::saveHub($this->shop, '2026-09', ['FEE' => '-10.00'], $this->owner); $fee = collect($r['rows'])->firstWhere('bucket', 'FEE'); $this->assertSame([-12.5, -10.0, -2.5, false], [$fee['files'], $fee['hub'], $fee['diff'], $fee['ok']]);
        $t = DB::table('tasks')->where('rule_code', 'SAL_HUB_MISMATCH')->first(); $this->assertSame(["SAL_HUB_MISMATCH:{$this->shop}:2026-09:FEE", 'TODO'], [$t->dedupe_key, $t->status]); $this->assertStringContainsString('FEE is -12.50 in the money files, -10.00 in the hub', $t->title);
        SalesWatch::saveHub($this->shop, '2026-09', ['FEE' => '-12.00'], $this->owner); $this->assertSame('DONE', DB::table('tasks')->where('id', $t->id)->value('status'));      // within £1: closed by the system
    }
}
