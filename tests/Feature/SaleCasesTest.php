<?php

namespace Tests\Feature;

use App\Services\RecordService;
use App\Services\SaleCases;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** RULES_A_SALES R-A_SALES-71: cases on a sale – raise with type + reason, dedupe, quick add, remarks, resolve / revert / remove, lists, REM-GEN hidden, task + reply-by. */
class SaleCasesTest extends TestCase
{
    /** These tests are about their own rules; the shop scope by role (R-G_CORE-63, Settings › Choices OWN_SHOP_ROLES) is tested in ScopeSecureTest. */
    protected function setUp(): void { parent::setUp(); \App\Services\Choices::set('OWN_SHOP_ROLES', 'OWNER', 1); }
    private int $shop; private int $mp; private int $late; private object $va;

    private function world(): void
    {
        $this->actingAs($this->owner); $f = fn (string $t, array $d) => RecordService::fillNotNull($t, $d);
        $this->mp = DB::table('cat_marketplaces')->insertGetId($f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'Ebay', 'marketplace_descr' => 'eBay', 'f_active' => 1]));
        $this->shop = DB::table('t_branches')->insertGetId($f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $this->mp, 'shop_no' => '101A', 'shop_name' => 'Pets', 'iso_branch' => 'P1', 'f_active' => 1]));
        $ref = DB::table('cat_references')->where('ref_code', 'REMINDER_TYPE')->value('id'); $this->late = DB::table('reference_items')->insertGetId(['fk_reference_id' => $ref, 'reference_name' => 'Late delivery', 'fk_id_ent' => 0, 'sort_no' => 1, 'f_active' => 1, 'f_pending' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->va = \App\Models\User::create(['login_id' => 'va-c@test', 'password_hash' => 'x', 'role_code' => 'VA', 'display_name' => 'Seller', 'fk_id_ent' => 1, 'f_active' => 1]);
    }

    private function sale(string $no = '12-345', string $sku = 'SKU1'): int
    {
        return DB::table('t_sales')->insertGetId(RecordService::fillNotNull('t_sales', ['fk_id_ent' => 1, 'fk_id_branch' => $this->shop, 'fk_id_marketplace' => $this->mp, 'order_no' => $no, 'd_order' => '2026-07-05', 'cust_name' => 'Jo', 'sku' => $sku, 'product_info' => 'Lead', 'qty' => 1, 'sale_rate' => 10, 'total_amount' => 10, 'buy_amount' => 6, 'profit' => 4, 'status' => 'Pending', 'sale_source' => 'active', 'created_at' => now()]));
    }

    public function test_raise_dedupe_and_relink(): void
    {
        $this->world(); $s = $this->sale(); $this->actingAs($this->va);
        try { SaleCases::raise($s, null, ' ', $this->va); $this->fail(); } catch (ValidationException $e) { $this->assertSame(['type', 'reason'], array_keys($e->errors())); }
        try { SaleCases::raise($s, SaleCases::generalId(), 'x', $this->va); $this->fail(); } catch (ValidationException $e) { $this->assertArrayHasKey('type', $e->errors()); }      // a seller cannot use the general type
        $this->assertNotContains('General', array_column(SaleCases::types(), 'name'));
        $r = SaleCases::raise($s, $this->late, 'Buyer says the parcel is late', $this->va); $c = DB::table('t_sale_order_reminders')->where('id', $r['id'])->first();
        $this->assertSame(['PENDING', (int) $this->va->id, date('Y-m-d', strtotime('+1 day'))], [$c->e_status, (int) $c->fk_id_t_users, $c->d_reply_by]);
        $t = DB::table('tasks')->where('rule_code', 'SALE_QUERY')->where('linked_record_id', $s)->first(); $this->assertSame('TODO', $t->status); $this->assertSame($c->d_reply_by, substr((string) $t->due_at, 0, 10)); $this->assertStringContainsString('Case on order 12-345 (101A (Pets)): Late delivery', $t->title);
        try { SaleCases::raise($s, $this->late, 'again', $this->va); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('Order already added in reminders', $e->getMessage()); }
        DB::table('t_sales')->where('id', $s)->delete(); $s2 = $this->sale(); $r2 = SaleCases::raise($s2, $this->late, 'again', $this->va); $this->assertSame('Order Exist , but Sale id updated in reminders', $r2['message']); $this->assertSame($s2, (int) DB::table('t_sale_order_reminders')->where('id', $r['id'])->value('fk_id_t_sale_orders')); $this->assertSame(1, DB::table('t_sale_order_reminders')->count());
        try { SaleCases::quickAdd($s2, $this->va); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('user has no rights', $e->getMessage()); }
    }

    public function test_thread_remarks_resolve_revert_remove_and_lists(): void
    {
        $this->world(); $a = $this->sale('B-2'); $b = $this->sale('A-1', 'SKU2'); $this->actingAs($this->va); $ca = SaleCases::raise($a, $this->late, 'Late', $this->va)['id']; $cb = SaleCases::raise($b, $this->late, 'Also late', $this->va)['id'];
        $this->actingAs($this->owner); try { SaleCases::quickAdd($a, $this->owner); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('Order already added in reminders', $e->getMessage()); }      // without the type in the key the seller's case on the same line counts
        $c3 = $this->sale('C-3', 'SKU3'); $g = SaleCases::quickAdd($c3, $this->owner)['id']; $this->assertSame(SaleCases::generalId(), (int) DB::table('t_sale_order_reminders')->where('id', $g)->value('fk_id_rem_type'));
        $l = SaleCases::list([], $this->owner); $this->assertSame(['A-1', 'B-2', 'C-3'], array_column($l['rows'], 'order_no')); $this->assertSame('4.00', $l['rows'][0]['profit']);
        $this->actingAs($this->va); $l = SaleCases::list([], $this->va); $this->assertSame(['A-1', 'B-2'], array_column($l['rows'], 'order_no')); $this->assertSame($l['money'] ? '4.00' : null, $l['rows'][0]['profit']); $this->assertNotContains('General', array_column($l['types'], 'name'));
        try { SaleCases::thread($g, $this->va); $this->fail(); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(403, $e->getStatusCode()); }
        try { SaleCases::remark($ca, 'x', $this->va); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('user has no rights', $e->getMessage()); }
        $this->actingAs($this->owner); try { SaleCases::remark($ca, ' ', $this->owner); $this->fail(); } catch (ValidationException $e) { $this->assertArrayHasKey('admin_remarks', $e->errors()); }
        SaleCases::remark($ca, 'Refund the postage', $this->owner); $this->assertSame(1, DB::table('tm_notifications')->where('user_id', $this->va->id)->where('text', 'like', 'Reply on your case for order B-2%')->count());
        $this->actingAs($this->va); SaleCases::reply($ca, 'Done, buyer happy', $this->va); SaleCases::resolve($ca, $this->va);
        $this->assertSame(['VA explanation', 'Admin remark', 'Message', 'Resolved'], array_column(SaleCases::thread($ca, $this->va)['notes'], 'what')); $this->assertNotSame('TODO', DB::table('tasks')->where('rule_code', 'SALE_QUERY')->where('dedupe_key', 'like', "%$ca")->value('status'));
        $this->assertSame(['B-2'], array_column(SaleCases::list(['status' => 'RESOLVED'], $this->va)['rows'], 'order_no')); $this->assertSame(1, SaleCases::list([], $this->va)['counts']['PENDING']);
        SaleCases::revert($ca, $this->va); $this->assertSame('PENDING', DB::table('t_sale_order_reminders')->where('id', $ca)->value('e_status')); $this->assertSame('TODO', DB::table('tasks')->where('rule_code', 'SALE_QUERY')->where('dedupe_key', 'like', "%$ca")->value('status'));
        DB::table('t_sale_order_reminders')->where('id', $cb)->update(['d_reply_by' => '2026-01-01']); $o = SaleCases::list(['overdue' => 1], $this->va); $this->assertSame(['A-1'], array_column($o['rows'], 'order_no')); $this->assertTrue($o['rows'][0]['overdue']);
        SaleCases::remove($cb, $this->va); $this->assertFalse(DB::table('t_sale_order_reminders')->where('id', $cb)->exists()); $this->assertSame(1, DB::table('mo_recycle_bin')->where('table_name', 't_sale_order_reminders')->count());
    }

    public function test_over_http_with_field_errors(): void
    {
        $this->world(); $s = $this->sale(); $this->actingAs($this->va);
        $this->postJson("/api/sales/$s/case", ['type' => '', 'reason' => ''])->assertStatus(422)->assertJsonValidationErrors(['type', 'reason']);
        $this->postJson("/api/sales/$s/case", ['type' => $this->late, 'reason' => 'Late delivery'])->assertOk(); $this->postJson("/api/sales/$s/case", ['type' => $this->late, 'reason' => 'Late delivery'])->assertStatus(422)->assertJsonPath('message', 'Order already added in reminders');
        $this->postJson("/api/sales/$s/case", ['quick' => 1])->assertStatus(422)->assertJsonPath('message', 'user has no rights'); $this->getJson('/api/sale-cases')->assertOk()->assertJsonPath('rows.0.type', 'Late delivery')->assertJsonPath('counts.PENDING', 1);
    }
}
