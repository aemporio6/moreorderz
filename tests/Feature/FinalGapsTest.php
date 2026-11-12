<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\Merges;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Round 2 part 7: the last two screens (tracking-upload layouts, merging look-alikes by hand) and a sign-in before any company exists. */
class FinalGapsTest extends TestCase
{
    private function person(string $first, string $last, ?string $wa = null): int
    {
        return (int) DB::table('t_account')->insertGetId(RecordService::fillNotNull('t_account', ['fk_id_ent' => 1, 'account_code' => 'M' . random_int(10000, 99999), 'first_name' => $first, 'sur_name' => $last, 'f_active' => 1, 'whatsapp_number' => $wa]));
    }

    public function test_look_alike_people_are_found_merged_with_their_links_and_the_merge_can_be_undone(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $a = $this->person('Ali', 'Khan', '+92 300 1112223'); $b = $this->person('ali ', 'KHAN'); $c = $this->person('Someone', 'Else', '0092-300-1112223');
        $bank = DB::table('t_account_banks')->insertGetId(RecordService::fillNotNull('t_account_banks', ['fk_id_account' => $b, 'f_active' => 1]));
        $g = collect(Merges::suggestions('PARTY'));
        $this->assertTrue($g->contains(fn ($x) => collect($x['records'])->pluck('id')->sort()->values()->all() === [$a, $b] && str_contains($x['why'], 'same name')));
        $this->assertFalse($g->contains(fn ($x) => collect($x['records'])->pluck('id')->contains($c)), 'different digits after cleaning (0092… vs 92…) are not matched');
        $r = $this->postJson('/api/merges', ['entity' => 'PARTY', 'keep' => $a, 'fold' => $b, 'reason' => 'same man typed twice'])->assertOk()->json();
        $this->assertGreaterThanOrEqual(1, $r['moved']);
        $this->assertSame($a, (int) DB::table('t_account_banks')->where('id', $bank)->value('fk_id_account'), 'the bank account moved to the kept person');
        $this->assertSame(0, (int) DB::table('t_account')->where('id', $b)->value('f_active'));
        $this->assertSame('MERGE', DB::table('sys_audit_log')->where(['table_name' => 't_account', 'record_id' => $b])->orderByDesc('id')->value('action'));
        $this->postJson("/api/merges/{$r['merge']}/undo")->assertOk();
        $this->assertSame($b, (int) DB::table('t_account_banks')->where('id', $bank)->value('fk_id_account'), 'Undo puts the row back');
        $this->assertSame(1, (int) DB::table('t_account')->where('id', $b)->value('f_active'));
        $this->assertFalse(collect(Merges::suggestions('PARTY'))->contains(fn ($x) => collect($x['records'])->pluck('id')->sort()->values()->all() === [$a, $b]), 'an undone pair is not suggested again');
        $this->actingAs($this->makeUser('VA'))->postJson('/api/merges', ['entity' => 'PARTY', 'keep' => $a, 'fold' => $c, 'reason' => 'x'])->assertStatus(403);
    }

    public function test_a_product_with_stock_cannot_be_folded_in(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $f = fn ($d) => DB::table('t_product_vendors')->insertGetId(RecordService::fillNotNull('t_product_vendors', $d + ['fk_id_ent' => 1, 'f_active' => 1, 'stock' => 0, 'e_product_type' => 'pool']));
        $p1 = $f(['SKU_VENDOR' => 'AB-100', 'product_name_vendor' => 'Thing']); $p2 = $f(['SKU_VENDOR' => 'ab100', 'product_name_vendor' => 'Thing']);
        $this->assertTrue(collect(Merges::suggestions('PRODUCT'))->contains(fn ($x) => collect($x['records'])->pluck('id')->sort()->values()->all() === [$p1, $p2]));
        $bin = DB::table('cat_inventory_locations')->insertGetId(RecordService::fillNotNull('cat_inventory_locations', ['location_name' => 'M-1', 'kind' => 'BIN', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1]));
        \App\Services\StockService::receive(1, [['product' => $p2, 'qty' => 3, 'location' => $bin, 'unit_cost' => 1]], 'GRN', 1);
        $this->postJson('/api/merges', ['entity' => 'PRODUCT', 'keep' => $p1, 'fold' => $p2, 'reason' => 'same SKU'])->assertStatus(422)->assertJsonFragment(['message' => 'The product to fold in still has stock on its lots. Move or sell it first (or give the units to the kept product with a stock adjustment): stock and the books must stay exact.']);
    }

    public function test_tracking_upload_follows_the_layout_of_the_shops_marketplace_profile_and_layouts_are_edited_as_data(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $prof = DB::table('mp_profiles')->insertGetId(RecordService::fillNotNull('mp_profiles', ['code' => 'TST' . random_int(100, 999), 'name' => 'Test profile', 'tracking_upload_layout' => 'AMAZON_SHIP_CONFIRM']));
        $shop = DB::table('t_branches')->insertGetId(RecordService::fillNotNull('t_branches', ['fk_id_ent' => 1, 'shop_name' => 'Tracking shop', 'f_active' => 1, 'fk_id_mp_profile' => $prof]));
        DB::table('t_sales')->insert(RecordService::fillNotNull('t_sales', ['fk_id_ent' => 1, 'fk_id_branch' => $shop, 'order_no' => '202-555', 'qty' => 2, 'sale_source' => 'active', 'status' => 'Pending', 'd_order' => date('Y-m-d'), 'tracking_no' => 'TRK1', 'carrier_service_text' => 'Tracked 48']));
        $d = $this->getJson("/api/tracking-upload?shop=$shop")->assertOk()->json('layout');
        $this->assertSame(['AMAZON_SHIP_CONFIRM', true], [$d['code'], $d['from_profile']]);
        $csv = $this->get("/api/tracking-upload?shop=$shop&csv=1")->streamedContent();
        $this->assertStringStartsWith("order-id\torder-item-id\tquantity\tship-date", $csv);
        $this->assertStringContainsString("202-555\t\t2\t", $csv);
        $this->postJson('/api/tracking-layouts', ['code' => 'MY_LAYOUT', 'name' => 'Mine', 'columns' => "Order = order_no\nTracking = tracking_no\nCarrier = text:Royal Mail"])->assertOk();
        $this->assertSame("Order,Tracking,Carrier\n202-555,TRK1,\"Royal Mail\"\n", $this->get("/api/tracking-upload?shop=$shop&csv=1&layout=MY_LAYOUT")->streamedContent());
        $this->postJson('/api/tracking-layouts', ['code' => 'BAD', 'name' => 'Bad', 'columns' => "Order = order_no"])->assertStatus(422);
    }

    public function test_first_sign_in_works_before_any_company_exists(): void
    {
        $this->assertNotSame('', \App\Services\Choices::get('MENU_NAMES', null));
        $this->assertSame(\App\Services\Choices::LIST['STATEMENT_SEND'][2], \App\Services\Choices::get('STATEMENT_SEND', null));
    }
}
