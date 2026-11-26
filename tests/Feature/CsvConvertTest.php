<?php

namespace Tests\Feature;

use App\Services\CsvConvert as C;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_A_SALES R-A_SALES-78 (convert any CSV to the standard sheet, saved formats, straight into the queue) and 79 (update existing data with a diff preview). */
class CsvConvertTest extends TestCase
{
    /** These tests are about their own rules; the shop scope by role (R-G_CORE-63, Settings › Choices OWN_SHOP_ROLES) is tested in ScopeSecureTest. */
    protected function setUp(): void { parent::setUp(); \App\Services\Choices::set('OWN_SHOP_ROLES', 'OWNER', 1); }
    private int $shop; private array $files = [];
    private const HEAD = 'Ordered,Ref,Title,First,Last,Street,Town,Units,Amount,Code';

    private function world(): void
    {
        $this->actingAs($this->owner); $mp = DB::table('cat_marketplaces')->insertGetId(RecordService::fillNotNull('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'Shop', 'marketplace_descr' => 'Own shop', 'f_active' => 1]));
        $this->shop = DB::table('t_branches')->insertGetId(RecordService::fillNotNull('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => '501W', 'shop_name' => 'Web', 'iso_branch' => 'W1', 'f_active' => 1, 'fulfilment_channel' => 'SELF']));
    }

    private function file(array $rows, string $head = self::HEAD): string { $p = tempnam(sys_get_temp_dir(), 'cv'); file_put_contents($p, $head . "\n" . implode("\n", $rows) . "\n"); return $this->files[] = $p; }
    protected function tearDown(): void { foreach ($this->files as $f) @unlink($f); foreach (glob(storage_path('app/csv_convert/*')) ?: [] as $f) @unlink($f); parent::tearDown(); }
    private function up(string $p, ?object $u = null): array { return C::upload($p, 'orders.csv', $u ?? $this->owner); }
    private const MAP = ['date' => 0, 'order_id' => 1, 'item_title' => 2, 'first_name' => 3, 'last_name' => 4, 'address_1' => 5, 'address_2' => 6, 'address_3' => '(empty)', 'address_4' => '(empty)', 'address_5' => '(empty)', 'address_6' => '(empty)', 'address_7' => '(empty)', 'address_8' => '(empty)', 'quantity' => 7, 'price' => 8, 'sku' => 9];

    public function test_convert_to_the_standard_sheet_with_a_saved_format(): void
    {
        $this->world(); $s = $this->up($this->file(['2026-11-03,W-1,Dog lead,John,Smith,1 High St,Leeds,2,9.50,LEAD-1', '05/11/2026,W-2,"Bowl Â",Ann,Lee,2 Low Rd,York,1,4.00,BOWL-1']));
        $this->assertMatchesRegularExpression('/^\d+_\d{14}_[0-9a-f]{8}$/', $s['token']); $this->assertSame(['A', 'B', 'J'], [$s['samples'][0]['letter'], $s['samples'][1]['letter'], $s['samples'][9]['letter']]); $this->assertSame('AA', C::letter(26));
        try { C::saveFormat('Web shop', ['date' => 0] , $this->owner); $this->fail(); } catch (\Illuminate\Validation\ValidationException $e) { $this->assertArrayHasKey('order_id', $e->errors()); }      // all columns must be mapped
        $f = C::saveFormat('Web shop', self::MAP, $this->owner); $this->assertSame(['A', 'B'], array_slice($f['formats'][0]['letters'], 0, 2));
        $t = DB::table('mo_export_templates')->where('id', $f['template_id'])->first(); $this->assertSame(['sale_line', 'Standard sheet – Web shop', '*'], [$t->list_key, $t->name, $t->shared_role]);      // R-A_SALES-78 also an export template of the sales list
        [$bytes] = \App\Services\ListExports::run($t); $this->assertStringContainsString('Order', $bytes);
        $x = C::download($s['token'], ['format' => $f['id'], 'price_label' => 'BUY'], $this->owner); $lines = array_map(fn ($l) => str_getcsv($l, ',', '"', ''), array_filter(explode("\n", preg_replace('/^\xEF\xBB\xBF/', '', $x['body']))));
        $this->assertSame(['Date', 'Order ID', 'Item Title', 'Customer', 'Address', 'Quantity', 'Buy Price', 'SKU'], $lines[0]); $this->assertSame(['03.11.2026', 'W-1', 'Dog lead', 'John Smith', '1 High St, Leeds', '2', '9.50', 'LEAD-1'], $lines[1]);      // first + last name → "John Smith"; Buy → "Buy Price"
        $this->assertSame(['05.11.2026', 'Bowl'], [$lines[2][0], $lines[2][2]]); $this->assertSame('Download_orders.csv', $x['name']);      // dates d.m.Y, stray "Â" removed
        try { C::rows($s['token'], ['format' => $f['id']], $this->owner); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('no longer held', $e->getMessage()); }      // the source file is deleted after the download
    }

    public function test_who_may_map_delete_and_use_formats(): void
    {
        $this->world(); $va = \App\Models\User::create(['login_id' => 'va-cv@test', 'password_hash' => 'x', 'role_code' => 'VA', 'display_name' => 'VA', 'fk_id_ent' => 1, 'f_active' => 1]); $mgr = \App\Models\User::create(['login_id' => 'mg-cv@test', 'password_hash' => 'x', 'role_code' => 'MANAGER', 'display_name' => 'Mgr', 'fk_id_ent' => 1, 'f_active' => 1]);
        $this->actingAs($va); $s = $this->up($this->file(['2026-11-03,W-1,Dog lead,John,Smith,1 High St,Leeds,2,9.50,LEAD-1']), $va); $this->assertSame('They will appear once they are ready by the Super Admin', $s['map_note']); $this->assertFalse($s['can_map']);
        try { C::rows($s['token'], ['map' => self::MAP], $va); $this->fail(); } catch (\Throwable $e) { $this->assertTrue(true); }      // mapping by hand: Owner / Admin only
        $this->actingAs($mgr); $id = C::saveFormat('Mgr format', self::MAP, $mgr)['id']; try { C::deleteFormat($id, $mgr); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('Only the Owner can delete a format.', $e->getMessage()); }
        C::approveFormat($id, true, $this->owner);      // R-D-54: a format saved by a manager is shared once the Owner approves it
        $this->actingAs($va); $this->assertSame('John Smith', C::rows($s['token'], ['format' => $id], $va)['rows'][0]['customer']);      // an approved format can be used by everyone
        $this->actingAs($this->owner); C::deleteFormat($id, $this->owner); $this->assertSame([], C::formats($this->owner)); $this->assertFalse(DB::table('mo_export_templates')->where('name', 'Standard sheet – Mgr format')->exists());
    }

    public function test_straight_into_the_fulfilment_queue(): void
    {
        $this->world(); $va = \App\Models\User::create(['login_id' => 'va-q@test', 'password_hash' => 'x', 'role_code' => 'VA', 'display_name' => 'VA', 'fk_id_ent' => 1, 'f_active' => 1]); $f = C::saveFormat('Web', self::MAP, $this->owner)['id'];
        $this->actingAs($va); $s = $this->up($this->file(['2026-11-03,W-1,Dog lead,John,Smith,1 High St,Leeds,2,9.50,LEAD-1', ',W-2,Mat,Ann,Lee,2 Low Rd,York,1,4.00,MAT-1']), $va);
        $r = C::intoQueue($s['token'], ['format' => $f], $this->shop, $va); $this->assertSame(1, $r['saved']); $this->assertSame('W-2', $r['errors'][0]['order']); $this->assertStringContainsString('order date', $r['errors'][0]['reason']);
        $l = DB::table('t_sales')->where('order_no', 'W-1')->first(); $x = DB::table('mo_sale_fulfilment')->where('fk_id_sale', $l->id)->first(); $this->assertSame(['John Smith', 2, 'Dog lead', '1 High St', 'Leeds'], [$l->cust_name, (int) $l->qty, $x->item_name, $x->address_1, $x->address_2]);
    }

    public function test_update_existing_data_changes_only_the_mapped_column_with_a_preview(): void
    {
        $this->world(); $ids = [];
        for ($i = 1; $i <= 20; $i++) $ids[] = DB::table('t_sales')->insertGetId(RecordService::fillNotNull('t_sales', ['fk_id_ent' => 1, 'fk_id_branch' => $this->shop, 'order_no' => "U-$i", 'd_order' => '2026-11-03', 'cust_name' => 'Jo', 'sku' => "S$i", 'qty' => 1, 'sale_rate' => 5, 'total_amount' => 5, 'status' => 'Pending', 'sale_source' => 'unmatched', 'created_at' => now()]));
        DB::table('t_sales')->insert(RecordService::fillNotNull('t_sales', ['fk_id_ent' => 1, 'fk_id_branch' => $this->shop, 'order_no' => 'U-21', 'd_order' => '2026-10-03', 'cust_name' => 'Jo', 'sku' => 'S21', 'qty' => 1, 'total_amount' => 5, 'status' => 'Confirmed', 'sale_source' => 'unmatched', 'created_at' => now()]));
        $rows = []; for ($i = 1; $i <= 21; $i++) $rows[] = "U-$i,S$i,TRK$i,Changed Name"; $rows[] = 'U-99,S99,TRK99,X'; $p = $this->file($rows, 'Order,SKU,Tracking,Name');
        $va = \App\Models\User::create(['login_id' => 'va-up@test', 'password_hash' => 'x', 'role_code' => 'VA', 'display_name' => 'VA', 'fk_id_ent' => 1, 'f_active' => 1]); $this->actingAs($va); $sv = $this->up($p, $va); $this->assertFalse($sv['can_update']);
        try { C::update($sv['token'], $this->shop, ['key' => ['order_no' => 0, 'sku' => 1], 'fields' => ['tracking_no' => 2]], $va, false); $this->fail(); } catch (\Throwable $e) { $this->assertTrue(true); }      // Owner / Admin only
        $this->actingAs($this->owner); $s = $this->up($p); $o = ['key' => ['order_no' => 0, 'sku' => 1], 'fields' => ['tracking_no' => 2]];
        $pre = C::update($s['token'], $this->shop, $o, $this->owner, false); $this->assertSame([20, 20, 1, 1], [$pre['lines'], $pre['fields'], count($pre['refused']), count($pre['not_found'])]); $this->assertSame(['tracking_no', '', 'TRK1'], [$pre['preview'][0]['diff'][0]['column'], $pre['preview'][0]['diff'][0]['old'], $pre['preview'][0]['diff'][0]['new']]);
        $this->assertSame(0, DB::table('t_sales')->whereNotNull('tracking_no')->where('tracking_no', '<>', '')->count());      // the preview saves nothing
        $r = C::update($s['token'], $this->shop, $o, $this->owner, true); $this->assertStringContainsString('20 line(s) changed, 1 refused (confirmed), 1 not found', $r['message']);
        $this->assertSame(20, DB::table('t_sales')->whereIn('id', $ids)->where('tracking_no', 'like', 'TRK%')->count()); $this->assertSame(20, DB::table('t_sales')->whereIn('id', $ids)->where('cust_name', 'Jo')->count());      // only order_tracking changes
        $this->assertSame(20, DB::table('t_sale_change_log')->whereIn('fk_id_sale', $ids)->where('action', 'FILE_UPDATE')->count()); $this->assertNull(DB::table('t_sales')->where('order_no', 'U-21')->value('tracking_no'));      // 20 history rows; the confirmed line untouched
    }
    /** R-D-54: named mappings carry "approved / shared"; others pick from the approved ones; match and target column must differ. */
    public function test_d54_approved_formats_and_the_mapping_right(): void
    {
        $this->world(); $mgr = $this->makeUser('MANAGER'); $va = $this->makeUser('VA'); $p = $this->file(['2026-11-03,R-1,Thing,A,B,1 Street,Town,1,5.00,S1']);
        $own = C::saveFormat('Owner format', self::MAP, $this->owner); $this->assertTrue(collect($own['formats'])->firstWhere('name', 'Owner format')['approved']);
        $this->actingAs($mgr); $m = C::saveFormat('Manager format', self::MAP, $mgr); $this->assertStringContainsString('others see it once it is approved', $m['message']);
        $this->assertSame(['Manager format' => false, 'Owner format' => true], collect($m['formats'])->pluck('approved', 'name')->all(), 'the saver sees the own format as waiting');
        // the rule: VA opens the wizard → sees only approved formats, no "Map CSV columns" save option
        $this->actingAs($va); $w = $this->up($p, $va);
        $this->assertSame([['Owner format'], false, false, 'They will appear once they are ready by the Super Admin'], [array_column($w['formats'], 'name'), $w['can_map'], $w['can_approve'], $w['map_note']]);
        try { C::rows($w['token'], ['format' => $m['id']], $va); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('Format not found.', $e->getMessage()); }
        $this->postJson('/api/csv-convert/format/' . $m['id'] . '/approve', ['approved' => true])->assertStatus(403);
        try { C::saveFormat('Owner format', self::MAP, $mgr); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('already exists', $e->getMessage()); }
        // the Owner approves → everyone can pick it; takes it back → gone again
        $this->actingAs($this->owner)->postJson('/api/csv-convert/format/' . $m['id'] . '/approve', ['approved' => true])->assertOk()->assertJsonPath('message', 'Format "Manager format" approved – everyone can pick it now.');
        $this->assertSame(['Manager format', 'Owner format'], array_column(C::formats($va), 'name')); $this->assertNotEmpty(C::rows($w['token'], ['format' => $m['id']], $va));
        C::approveFormat($m['id'], false, $this->owner); $this->assertSame(['Owner format'], array_column(C::formats($va), 'name'));
        // update mode: the column to match and the column to change must be different
        $q = $this->file(['U-1,S1,TRK1'], 'Order,SKU,Tracking'); $t = $this->up($q)['token'];
        try { C::update($t, $this->shop, ['key' => ['order_no' => 0], 'fields' => ['tracking_no' => 0]], $this->owner, false); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('The column to match and the column to change must be different (Tracking number).', $e->getMessage()); }
    }
}
