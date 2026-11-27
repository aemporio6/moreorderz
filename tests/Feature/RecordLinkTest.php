<?php

namespace Tests\Feature;

use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Owner 27-11 11:55: a SKU / PO / supplier / order / task written on a screen opens its record; nothing found = a clear message. */
class RecordLinkTest extends TestCase
{
    public function test_names_on_screens_open_their_records(): void
    {
        $this->actingAs($this->owner);
        $sku = 'RL-' . uniqid();
        $p = (int) DB::table('t_product_vendors')->insertGetId(RecordService::fillNotNull('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => $sku, 'product_name_vendor' => 'Link mug', 'f_active' => 1]));
        $this->getJson('/api/record-link?k=sku&v=' . urlencode($sku))->assertOk()->assertJson(['url' => "#/inventory/$p"]);
        $this->getJson('/api/record-link?k=sku&v=' . urlencode("$sku · Link mug"))->assertOk()->assertJson(['url' => "#/inventory/$p"]);
        $sup = (int) DB::table('t_src_suppliers')->insertGetId(RecordService::fillNotNull('t_src_suppliers', ['fk_id_ent' => 1, 'supplier_name' => 'Ningbo Link Factory', 'supplier_code' => 'NLF']));
        $this->assertStringContainsString("/$sup", $this->getJson('/api/record-link?k=supplier&v=' . urlencode('Ningbo Link Factory'))->assertOk()->json('url'));
        $po = (int) DB::table('t_src_po')->insertGetId(RecordService::fillNotNull('t_src_po', ['fk_id_ent' => 1, 'po_code' => 'PO-RL-77', 'fk_id_supplier' => $sup]));
        $this->getJson('/api/record-link?k=po&v=PO-RL-77')->assertOk()->assertJson(['url' => "#/po/$po"]);
        $t = (int) DB::table('tasks')->insertGetId(RecordService::fillNotNull('tasks', ['fk_id_ent' => 1, 'title' => 'Check link task', 'status' => 'TODO', 'owner_user_id' => $this->owner->id]));
        $this->getJson('/api/record-link?k=task&v=' . urlencode('Check link task'))->assertOk()->assertJson(['url' => "#/task/$t"]);
        $this->getJson('/api/record-link?k=sku&v=NOPE-' . uniqid())->assertStatus(404);
        $this->getJson('/api/record-link?k=bogus&v=x')->assertStatus(422);
    }

    public function test_every_connector_is_in_one_list_with_its_state(): void
    {
        $this->actingAs($this->owner);
        $c = collect($this->getJson('/api/connections')->assertOk()->json('connectors'))->keyBy('code');
        foreach (['gdrive', 'gsheets', 'gmail', 'amazon', 'ebay', 'wise', 'whatsapp', 'ecb'] as $k) $this->assertTrue($c->has($k), $k);
        $this->assertSame('READY', $c['amazon']['state']); $this->assertSame(['AMAZON_SPAPI'], $c['amazon']['adapters']);
        $this->assertSame('CONNECTED', $c['ecb']['state']); $this->assertSame('BY_FILE', $c['wise']['state']); $this->assertSame('TO_BUILD', $c['gcal']['state']);
    }
}
