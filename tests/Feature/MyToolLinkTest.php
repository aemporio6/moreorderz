<?php

namespace Tests\Feature;

use App\Services\Context;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Owner 26-11 20:08: MY TOOL connects directly (MY TOOL docs/moreorderz-link.md). */
class MyToolLinkTest extends TestCase
{
    private function pkg(array $items): array { return ['format' => 'mytool-link-1', 'from' => 'MY TOOL', 'company' => 'Test', 'sentAt' => '2026-11-26T15:00:00Z', 'sentBy' => 'owner@test', 'items' => $items]; }

    public function test_key_hello_permissions_inbox_apply_and_cors(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $this->getJson('/api/mytool/hello', ['Authorization' => 'Bearer mol_wrong'])->assertStatus(401);
        $key = $this->postJson('/api/mytool-link/key')->assertOk()->json('key');
        $this->assertStringStartsWith('mol_', $key); $this->assertFalse(DB::table('mo_link_keys')->where('key_hash', $key)->exists(), 'only the hash is kept');
        $this->getJson('/api/mytool/hello', ['Authorization' => "Bearer $key"])->assertOk()->assertJsonPath('ok', true)->assertHeader('Access-Control-Allow-Origin', '*');
        $this->call('OPTIONS', '/api/mytool/receive')->assertStatus(204)->assertHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type');
        $items = [['kind' => 'parties', 'id' => 'SUP-771', 'op' => 'upsert', 'data' => ['code' => 'SUP-771', 'type' => 'supplier', 'name' => 'Link Test Supplier', 'iban' => 'GB00SECRET']],
            ['kind' => 'purchases', 'id' => 'PO-LK-1', 'op' => 'upsert', 'data' => ['no' => 'PO-LK-1', 'date' => '2024-02-01', 'party' => 'SUP-771', 'cur' => 'GBP', 'lines' => [['sku' => 'LK-1', 'p' => 'Link item', 'q' => 5, 'u' => 2, 'a' => 10]], 'extras' => []]],
            ['kind' => 'purchases', 'id' => 'PO-OLD', 'op' => 'delete', 'data' => []], ['kind' => 'tasks', 'id' => 'T-1', 'op' => 'upsert', 'data' => ['title' => 'x']]];
        $this->postJson('/api/mytool/receive', $this->pkg($items), ['Authorization' => "Bearer $key"])->assertStatus(423);      // link off: nothing accepted
        $this->postJson('/api/mytool-link', ['on' => true, 'recv' => ['tasks' => 'NEVER']])->assertOk();
        $r = $this->postJson('/api/mytool/receive', $this->pkg($items), ['Authorization' => "Bearer $key"])->assertOk()->json();
        $this->assertSame([0, 3, 1], [$r['applied'], $r['queued'], $r['refused']], 'Ask: waits; Never: refused');
        $this->assertStringNotContainsString('GB00SECRET', (string) DB::table('mo_link_inbox')->where('item_id', 'SUP-771')->value('data_json'), 'bank details only when allowed');
        $this->assertSame(0, DB::table('t_src_cycles')->where('order_ref', 'PO-LK-1')->count(), 'nothing applied before the yes');
        $ids = DB::table('mo_link_inbox')->where('status', 'QUEUED')->pluck('id')->all();
        $x = $this->postJson('/api/mytool-link/apply', ['ids' => $ids])->assertOk()->json();
        $this->assertSame(1, $x['imported'], json_encode($x['failed'] ?? [])); $this->assertSame(1, $x['not_used'], 'the deletion is only listed');
        $this->assertSame(1, DB::table('t_src_cycles')->where('order_ref', 'PO-LK-1')->count());
        $this->assertSame('QUEUED', DB::table('mo_link_inbox')->where('item_id', 'PO-OLD')->value('status'));
        // ALWAYS applies at once; send gives a moreorderz-link-1 package; revoke stops the key
        $this->postJson('/api/mytool-link', ['recv' => ['purchases' => 'ALWAYS'], 'send' => ['purchases' => 'ALWAYS']])->assertOk();
        $items2 = [['kind' => 'purchases', 'id' => 'PO-LK-2', 'op' => 'upsert', 'data' => ['no' => 'PO-LK-2', 'date' => '2024-02-03', 'party' => 'SUP-771', 'cur' => 'GBP', 'lines' => [['sku' => 'LK-2', 'p' => 'Link two', 'q' => 1, 'u' => 3, 'a' => 3]], 'extras' => []]]];
        $this->assertSame(1, $this->postJson('/api/mytool/receive', $this->pkg($items2), ['Authorization' => "Bearer $key"])->json('applied'));
        $this->assertSame(1, DB::table('t_src_cycles')->where('order_ref', 'PO-LK-2')->count());
        $this->assertSame('moreorderz-link-1', $this->getJson('/api/mytool/send?since=2020-01-01', ['Authorization' => "Bearer $key"])->assertOk()->json('format'));
        $this->postJson('/api/mytool-link/revoke')->assertOk();
        $this->getJson('/api/mytool/hello', ['Authorization' => "Bearer $key"])->assertStatus(401);
        $va = $this->makeUser('MANAGER'); $this->actingAs($va); $this->getJson('/api/mytool-link')->assertStatus(403);
    }
}
