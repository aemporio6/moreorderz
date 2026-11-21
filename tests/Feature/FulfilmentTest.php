<?php

namespace Tests\Feature;

use App\Services\Fulfilment;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** RULES_A_SALES R-A_SALES-75 / 76 / 77: daily fulfilment orders – add, who may set what, colours, scope, bulk tools, search + date presets, the 25-column carrier file. */
class FulfilmentTest extends TestCase
{
    private int $shop; private object $va; private object $va2; private object $tl;

    private function world(): void
    {
        $this->actingAs($this->owner); $f = fn (string $t, array $d) => RecordService::fillNotNull($t, $d);
        $mp = DB::table('cat_marketplaces')->insertGetId($f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'TikTok', 'marketplace_descr' => 'TikTok Shop', 'f_active' => 1]));
        $this->shop = DB::table('t_branches')->insertGetId($f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => '301T', 'shop_name' => 'Tik', 'iso_branch' => 'T1', 'f_active' => 1, 'fulfilment_channel' => 'SELF']));
        $mk = fn (string $l, string $role) => \App\Models\User::create(['login_id' => $l, 'password_hash' => 'x', 'role_code' => $role, 'display_name' => $l, 'fk_id_ent' => 1, 'f_active' => 1]);
        $this->va = $mk('va-f@test', 'VA'); $this->va2 = $mk('va-g@test', 'VA'); $this->tl = $mk('tl-f@test', 'TL');
    }

    private function order(array $d = [], ?object $u = null): array { $u ??= $this->va; $this->actingAs($u); return Fulfilment::save($d + ['shop' => $this->shop, 'order_no' => 'TT-1', 'd_order' => '2026-07-10', 'item_name' => 'Phone case', 'sku' => 'PC-1', 'qty' => '2', 'buyer_first_name' => 'Ann', 'buyer_last_name' => 'Lee', 'buyer_phone' => '+44 (7700) 900123', 'recipient_first_name' => 'Ann', 'recipient_last_name' => 'Lee', 'recipient_phone' => '+44 (7700) 900123', 'address_1' => '1 High St', 'city' => 'Leeds', 'postcode' => 'ls1 1aa', 'country_name' => 'United Kingdom'], null, $u); }

    public function test_add_edit_colours_and_scope(): void
    {
        $this->world(); $this->actingAs($this->va);
        try { Fulfilment::save(['shop' => '', 'order_no' => ' ', 'd_order' => ''], null, $this->va); $this->fail(); } catch (ValidationException $e) { $this->assertSame(['shop', 'order_no', 'd_order'], array_keys($e->errors())); }
        $r = $this->order(['tracking_no' => 'SNEAK', 'done' => 1, 'carrier_service' => 'X'])['row'];      // a seller cannot set tracking, service or status – and never on add
        $this->assertSame(['Pending', 'red', '', ''], [$r['status'], $r['colour'], $r['tracking_no'], $r['carrier_service']]); $this->assertSame(['25.00', '20.00', '2.00', 'GB', 'LS1 1AA'], [$r['length'], $r['width'], $r['height'], $r['country_code'], $r['postcode']]);
        $s = DB::table('t_sales')->where('id', $r['id'])->first(); $this->assertSame(['TT-1', 'Pending', 'Ann Lee', 2], [$s->order_no, $s->status, $s->cust_name, (int) $s->qty]); $this->assertSame(0, DB::table('tasks')->where('rule_code', 'SAL_ZERO')->where('linked_record_id', $r['id'])->count());
        try { $this->order(); $this->fail(); } catch (ValidationException $e) { $this->assertStringContainsString('already saved', $e->errors()['order_no'][0]); }
        Fulfilment::save(['d_order' => '2026-07-10', 'item_name' => 'Phone case v2', 'tracking_no' => 'SNEAK2', 'done' => 1], $r['id'], $this->va); $this->assertNull(DB::table('t_sales')->where('id', $r['id'])->value('tracking_no')); $this->assertSame(0, (int) DB::table('mo_sale_fulfilment')->where('fk_id_sale', $r['id'])->value('f_done'));
        $this->actingAs($this->tl); try { Fulfilment::save(['shop' => $this->shop, 'order_no' => 'TL-1', 'd_order' => '2026-07-10'], null, $this->tl); $this->fail(); } catch (\Throwable $e) { $this->assertFalse(DB::table('t_sales')->where('order_no', 'TL-1')->exists()); }      // only a seller adds
        $this->assertFalse(Fulfilment::list([], $this->tl)['can_add']); $this->assertSame('red', Fulfilment::list([], $this->tl)['rows'][0]['colour']);
        $t = Fulfilment::save(['d_order' => '2026-07-10', 'item_name' => 'Phone case v2', 'tracking_no' => 'RM123', 'carrier_service' => 'Royal Mail-Tracked 48', 'done' => 1], $r['id'], $this->tl)['row']; $this->assertSame(['Done', 'green', 'RM123'], [$t['status'], $t['colour'], $t['tracking_no']]);
        $this->actingAs($this->va); $mine = Fulfilment::list([], $this->va); $this->assertSame('green', $mine['rows'][0]['colour']); $this->assertTrue($mine['can_add']); $this->assertNull($mine['presets']); $this->assertArrayNotHasKey('tracking', $mine['search']);
        $this->actingAs($this->va2); $this->assertSame(0, Fulfilment::list([], $this->va2)['count']);      // a seller sees only the rows he created
        try { Fulfilment::save(['d_order' => '2026-07-10'], $r['id'], $this->va2); $this->fail(); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(403, $e->getStatusCode()); }
    }

    public function test_bulk_tools_search_and_dates(): void
    {
        $this->world(); $a = $this->order()['id']; $b = $this->order(['order_no' => 'TT-2', 'd_order' => '2026-07-09', 'buyer_first_name' => 'Bob', 'sku' => 'PC-2'])['id'];
        try { Fulfilment::bulk([$a], 'status', $this->va, 'done'); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('Only a manager or team leader', $e->getMessage()); }
        $this->actingAs($this->tl); $this->assertSame(2, Fulfilment::bulk([$a, $b], 'status', $this->tl, 'done')['changed']); $this->assertSame(2, Fulfilment::list(['status' => 'done'], $this->tl)['count']);
        try { Fulfilment::list(['q' => 'Bob'], $this->tl); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('Please select an option first', $e->getMessage()); }
        $this->assertSame(['TT-2'], array_column(Fulfilment::list(['q' => 'bo', 'by' => 'buyer_first'], $this->tl)['rows'], 'order_no')); $this->assertSame(['TT-1'], array_column(Fulfilment::list(['q' => 'PC-1', 'by' => 'sku'], $this->tl)['rows'], 'order_no'));
        $this->assertSame(['2026-07-10', '2026-07-10'], Fulfilment::range(['preset' => 'today'], true, '2026-07-10')); $this->assertSame(['2026-07-09', '2026-07-09'], Fulfilment::range(['preset' => 'yesterday'], true, '2026-07-10')); $this->assertSame(['2026-07-04', '2026-07-10'], Fulfilment::range(['preset' => 'last7'], true, '2026-07-10'));
        $this->assertSame(['2026-06-11', '2026-07-10'], Fulfilment::range(['preset' => 'last30'], true, '2026-07-10')); $this->assertSame(['2026-07-01', '2026-07-31'], Fulfilment::range(['preset' => 'this_month'], true, '2026-07-10')); $this->assertSame(['2026-06-01', '2026-06-30'], Fulfilment::range(['preset' => 'last_month'], true, '2026-07-10'));
        $this->assertSame(['2026-07-01', '2026-07-05'], Fulfilment::range(['range' => '2026-07-01[-]2026-07-05'], true)); $this->assertSame(['2026-07-09', '2026-07-09'], Fulfilment::range(['date' => '09-07-2026', 'preset' => 'last30'], false));
        $this->assertSame(['TT-2'], array_column(Fulfilment::list(['preset' => 'yesterday'], $this->tl, '2026-07-10')['rows'], 'order_no')); $this->actingAs($this->va); $this->assertSame(['TT-2'], array_column(Fulfilment::list(['date' => '2026-07-09'], $this->va)['rows'], 'order_no'));
        try { Fulfilment::list(['q' => 'RM', 'by' => 'tracking'], $this->va); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('Please select an option first', $e->getMessage()); }
        $this->actingAs($this->tl); $d = Fulfilment::bulk([$a], 'delete', $this->tl); $this->assertSame(1, $d['deleted']); $this->assertFalse(DB::table('t_sales')->where('id', $a)->exists()); $this->assertSame(1, DB::table('mo_recycle_bin')->where('row_id', $a)->where('table_name', 't_sales')->count());
    }

    public function test_carrier_file_phones_and_confirm_block(): void
    {
        $this->world(); $id = $this->order()['id']; $this->actingAs($this->tl); Fulfilment::save(['d_order' => '2026-07-10', 'item_name' => 'Phone case', 'buyer_first_name' => 'Ann', 'buyer_last_name' => 'Lee', 'buyer_phone' => '+44 (7700) 900123', 'recipient_phone' => '+44 (7700) 900123', 'address_1' => '1 High St', 'city' => 'Leeds', 'postcode' => 'LS1 1AA', 'country_name' => 'United Kingdom', 'weight' => '150', 'carrier_service' => 'Royal Mail-Tracked 48', 'qty' => 2], $id, $this->tl);
        $x = Fulfilment::export([], $this->tl, '2026-07-10 15:04:05'); $this->assertSame('TikTokSale_20260710150405.csv', $x['name']); $lines = array_map(fn ($l) => str_getcsv($l, ',', '"', ''), array_filter(explode("\n", $x['body'])));
        $this->assertSame(Fulfilment::EXPORT_HEAD, $lines[0]); $this->assertCount(25, $lines[1]); $this->assertSame(['TT-1', '10/07/2026', 'PC-1', 'Phone case', '2', 'Ann', 'Lee', '', '07700900123'], array_slice($lines[1], 0, 9)); $this->assertSame(['Leeds', '', 'LS1 1AA', 'United Kingdom', 'GB', '25', '20', '2', '150', 'Royal Mail-Tracked 48'], array_slice($lines[1], 15));
        $this->assertSame('0 7700 900123', Fulfilment::phone('+44 (7700) 900123', 'GB', 'OLD')); $this->assertSame('07700900123', Fulfilment::phone('+44 (7700) 900123', 'GB', 'NATIONAL')); $this->assertSame('+33612345678', Fulfilment::phone('+33 6 12 34 56 78', 'GB', 'NATIONAL')); $this->assertSame('07700900123', Fulfilment::phone('07700 900123', 'GB', 'NATIONAL'));
        $this->actingAs($this->owner); try { \App\Services\SalesService::confirmMonth($this->shop, '2026-07'); $this->fail(); } catch (\RuntimeException $e) { $this->assertStringContainsString('1 fulfilment order(s) of this month have no sale price yet', $e->getMessage()); }
        $this->actingAs($this->va); $this->assertSame(1, Fulfilment::export([], $this->va)['rows']); $this->actingAs($this->va2); $this->assertSame(0, Fulfilment::export([], $this->va2)['rows']);      // the export keeps the seller's scope
        $this->actingAs($this->va); $this->postJson('/api/fulfilment', ['shop' => $this->shop, 'order_no' => '', 'd_order' => ''])->assertStatus(422)->assertJsonValidationErrors(['order_no', 'd_order']); $this->getJson('/api/fulfilment?q=x')->assertStatus(422)->assertJsonPath('message', 'Please select an option first');
    }
}
