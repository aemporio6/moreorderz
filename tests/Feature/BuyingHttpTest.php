<?php

namespace Tests\Feature;

use App\Services\Fx;
use App\Services\Ledger;
use App\Services\RecordService;
use App\Services\StockService as Stock;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BuyingHttpTest extends TestCase
{
    public function test_the_whole_buying_road_through_the_screens_api_with_role_checks(): void
    {
        $wh = DB::table('cat_inventory_locations')->insertGetId(RecordService::fillNotNull('cat_inventory_locations', ['location_name' => 'Main', 'kind' => 'WAREHOUSE', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1]));
        $bank = DB::table('fin_bank_accounts')->insertGetId(['company_id' => 1, 'bank_name' => 'Test Bank', 'account_label' => 'Main', 'currency' => 'GBP', 'is_active' => 1]);
        $rid = DB::table('src_research')->insertGetId(RecordService::fillNotNull('src_research', ['fk_id_ent' => 1, 'research_code' => 'RES-X-1', 'title' => 'Dog bowl', 'status' => 'IDEA', 'created_by' => $this->owner->id, 'created_at' => now()]));
        $src = $this->makeUser('SOURCING'); $fin = $this->makeUser('FINANCE'); $whu = $this->makeUser('WAREHOUSE'); $va = $this->makeUser('VA');

        $this->actingAs($va)->getJson('/api/buying')->assertStatus(403);
        $this->actingAs($src)->postJson("/api/research/$rid/numbers", ['sell' => 20, 'cost' => 4, 'freight' => 1.5, 'fee_pct' => 13, 'ad_pct' => 5, 'units' => 200])->assertOk()->assertJsonPath('margin', 54.5);
        $this->postJson("/api/research/$rid/competitor", ['listing_url' => 'not a link'])->assertStatus(422);
        $this->postJson("/api/research/$rid/competitor", ['listing_url' => 'https://example.com/item/1', 'price' => 19.99])->assertOk();
        $this->postJson("/api/research/$rid/supplier", ['unit_price' => 5, 'currency' => 'USD'])->assertStatus(422);       // no supplier, no name
        $this->postJson("/api/research/$rid/supplier", ['supplier_name_text' => 'Shenzhen Petware', 'unit_price' => 5, 'currency' => 'USD', 'moq' => 100])->assertOk();
        $this->postJson("/api/research/$rid/go", ['qty' => 200])->assertStatus(422)->assertJsonPath('message', 'GO needs a Manager, Partner or Owner.');
        $go = $this->actingAs($this->owner)->postJson("/api/research/$rid/go", ['qty' => 200])->assertOk()->json();

        $w = $this->actingAs($src)->getJson("/api/cycle/{$go['cycle']}")->assertOk()->json();
        $this->assertSame(['prog', 'pend', 'pend', 'pend', 'pend', 'pend'], array_column($w['steps'], 'state'));
        $rfq = $w['rfqs'][0]['id'];
        $this->postJson('/api/po', ['rfq' => $rfq, 'qty' => 200])->assertStatus(422)->assertJsonPath('message', 'Accept the quote first.');
        $this->postJson("/api/rfq/$rfq/accept")->assertOk();
        $po = $this->postJson('/api/po', ['rfq' => $rfq, 'qty' => 200, 'date' => '2026-08-01'])->assertOk()->json('id');
        $this->postJson("/api/po/$po/confirm")->assertStatus(422);                                                         // no USD rate yet
        $this->postJson('/api/fx', ['currency' => 'USD', 'date' => '2026-08-01', 'rate' => 0.8])->assertStatus(403);       // sourcing cannot set rates
        $this->actingAs($fin)->postJson('/api/fx', ['currency' => 'USD', 'date' => '2026-08-01', 'rate' => 0.8])->assertOk();
        $this->actingAs($src)->postJson("/api/po/$po/confirm")->assertOk();
        $term = $this->getJson("/api/po/$po")->assertOk()->json('terms.0');
        $this->assertSame(300.0, (float) $term['planned_amount']);
        $this->postJson('/api/pay', ['term' => $term['id'], 'amount' => 300, 'date' => '2026-08-01', 'bank' => $bank])->assertStatus(403);   // sourcing cannot pay
        $this->actingAs($fin)->postJson('/api/pay', ['term' => $term['id'], 'amount' => 300, 'date' => '2026-08-01', 'bank' => $bank])->assertOk();
        $this->assertSame(-240.00, Ledger::balance(1, '1000'));

        $ship = $this->actingAs($src)->postJson('/api/shipment', ['po' => $po, 'eta' => '2026-08-20'])->assertOk()->json('id');
        $line = $this->getJson("/api/shipment/$ship/lines")->json('lines.0.po_line');
        $this->actingAs($fin)->postJson('/api/grn', ['shipment' => $ship, 'location' => $wh, 'date' => '2026-08-20', 'lines' => [['po_line' => $line, 'received' => 200]]])->assertStatus(403);
        $view = $this->actingAs($whu)->getJson("/api/po/$po")->assertOk()->json();
        $this->assertArrayNotHasKey('terms', $view, 'warehouse sees no money');
        $this->assertArrayNotHasKey('unit_price', $view['lines'][0]);
        $grn = $this->postJson('/api/grn', ['shipment' => $ship, 'location' => $wh, 'date' => '2026-08-20', 'lines' => [['po_line' => $line, 'received' => 200, 'damaged' => 300]]])->assertStatus(422);
        $grn = $this->postJson('/api/grn', ['shipment' => $ship, 'location' => $wh, 'date' => '2026-08-20', 'lines' => [['po_line' => $line, 'received' => 200]]])->assertOk()->json('id');
        $this->postJson("/api/grn/$grn/post")->assertOk()->assertJsonPath('value', 800);
        $this->postJson("/api/grn/$grn/post")->assertStatus(422);
        $this->assertSame(200, Stock::balance($go['product']));
        $this->postJson("/api/po/$po/landed", ['freight' => 100, 'final' => true])->assertStatus(403);                     // warehouse cannot set costs
        $this->actingAs($this->owner)->postJson("/api/po/$po/landed", ['freight' => 100, 'final' => true])->assertOk()->assertJsonPath('unit_cost', 4.5);
        $this->assertSame(Stock::value(1), Ledger::balance(1, '1200'));
        $this->assertTrue(Ledger::trialBalance(1)['balanced']);

        $rep = $this->postJson('/api/po', ['repeat_from' => $po, 'qty' => 50])->assertOk()->json('id');
        $this->assertSame([250.0, $po], [(float) DB::table('t_src_po')->where('id', $rep)->value('po_total'), (int) DB::table('t_src_po')->where('id', $rep)->value('fk_id_repeat_from_po')]);
        $dash = $this->getJson('/api/buying')->assertOk()->json();
        $this->assertSame(1, $dash['kpi']['po_draft']);
        $this->assertSame('done', $dash['cycles'][0]['steps'][5]['state']);
    }
}
