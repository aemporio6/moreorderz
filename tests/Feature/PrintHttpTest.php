<?php

namespace Tests\Feature;

use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PrintHttpTest extends TestCase
{
    public function test_printers_screen_test_page_route_print_button_queue_reprint_and_roles(): void
    {
        $f = fn ($t, $d) => DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
        $whLoc = $f('cat_inventory_locations', ['location_name' => 'Main', 'kind' => 'WAREHOUSE', 'fk_id_ent' => 1, 'f_active' => 1]);
        $mgr = $this->makeUser('MANAGER'); $wh = $this->makeUser('WAREHOUSE'); $va = $this->makeUser('VA');

        $this->actingAs($va)->getJson('/api/printers')->assertStatus(403);
        $s = $this->actingAs($mgr)->getJson('/api/printers')->assertOk()->json();
        $this->assertCount(2, $s['printers'], 'the two setup printers; example rows are hidden');
        $this->assertSame('ASK', $s['settings']['failover']);
        $a4 = collect($s['printers'])->firstWhere('code', 'SETUP_A4_1')['id']; $th = collect($s['printers'])->firstWhere('code', 'SETUP_THERMAL_1')['id'];
        $plain = collect($s['media'])->firstWhere('code', 'SETUP_A4_PLAIN')['id']; $roll = collect($s['media'])->firstWhere('code', 'SETUP_ROLL_100X150')['id'];
        $loc = collect($s['routes'])->firstWhere('kind', 'LOCATION');
        $this->assertSame([$a4, false], [$loc['printer'], $loc['tested']]);

        // bins made before the printer is tested: bins are created, auto-print is skipped quietly
        $r = $this->postJson('/api/stock/bins', ['parent' => $whLoc, 'zone' => 'A', 'levels' => 2, 'positions' => 3, 'create' => true])->assertOk()->json();
        $this->assertSame(6, $r['created']); $this->assertArrayNotHasKey('print', $r);
        $this->postJson('/api/print', ['kind' => 'LOCATION', 'ids' => $r['ids']])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'test page'));

        // test page → real PDF → pass
        $t = $this->actingAs($wh)->postJson("/api/printers/$a4/test", ['media' => $plain])->assertOk()->json();
        $pdf = $this->get($t['url'])->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', file_get_contents($pdf->baseResponse->getFile()->getPathname()));
        $this->postJson("/api/printers/$a4/test-result", ['media' => $plain, 'ok' => true, 'scale_pct' => 110])->assertStatus(422);
        $this->postJson("/api/printers/$a4/test-result", ['media' => $plain, 'ok' => true, 'offset_x_mm' => 0.5])->assertOk();

        // print button, second print needs a reason, queue, reprint
        $j = $this->postJson('/api/print', ['kind' => 'LOCATION', 'ids' => $r['ids']])->assertOk()->json();
        $this->assertSame(6, $j['labels']);
        $this->postJson('/api/print', ['kind' => 'LOCATION', 'ids' => [$r['ids'][0]]])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'reason'));
        $this->postJson('/api/print', ['kind' => 'PO', 'ids' => [1]])->assertStatus(403);                       // warehouse sees no purchase order
        $q = $this->getJson('/api/print-jobs')->assertOk()->json();
        $this->assertSame(['PRINTED', 'LOCATION', 6], [$q['rows'][0]['status'], $q['rows'][0]['kind'], $q['rows'][0]['count']]);
        $this->postJson("/api/print-jobs/{$j['job']}/reprint", [])->assertStatus(422);
        $this->postJson("/api/print-jobs/{$j['job']}/reprint", ['reason' => 'DAMAGED_LABEL'])->assertOk();
        $this->actingAs($va)->get("/api/print-jobs/{$j['job']}/pdf")->assertStatus(403);

        // new bins now print by themselves (setting WH_AUTOPRINT_SHELF = Y)
        $r2 = $this->actingAs($mgr)->postJson('/api/stock/bins', ['parent' => $whLoc, 'zone' => 'B', 'levels' => 1, 'positions' => 2, 'create' => true])->assertOk()->json();
        $this->assertArrayHasKey('print', $r2);

        // printer in repair → the app asks → backup; routes and printers are for Owner / Manager
        $this->actingAs($wh)->postJson('/api/print-routes', ['kind' => 'LOCATION', 'printer' => $a4, 'media' => $plain])->assertStatus(403);
        $new = $this->actingAs($mgr)->postJson('/api/printers', ['name' => 'Second A4', 'printer_kind' => 'LASER', 'connection' => 'NETWORK', 'max_print_width_mm' => 210, 'max_print_length_mm' => 297])->assertOk()->json('id');
        $this->postJson('/api/print-routes', ['kind' => 'LOCATION', 'printer' => $a4, 'backup' => $a4, 'media' => $plain])->assertStatus(422);
        $this->postJson('/api/print-routes', ['kind' => 'LOCATION', 'printer' => $th, 'backup' => $new, 'media' => $plain])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'larger than'));
        $this->postJson('/api/print-routes', ['kind' => 'LOCATION', 'printer' => $a4, 'backup' => $new, 'media' => $plain])->assertOk();
        $this->postJson("/api/printers/$new/test-result", ['media' => $plain, 'ok' => true])->assertOk();
        $this->actingAs($wh)->postJson("/api/printers/$a4/status", ['status' => 'REPAIR'])->assertOk();
        $this->postJson('/api/print', ['kind' => 'LOCATION', 'ids' => $r2['ids'], 'reason' => 'OTHER'])->assertStatus(409)->assertJsonPath('ask.backup_id', $new);
        $ok = $this->postJson('/api/print', ['kind' => 'LOCATION', 'ids' => $r2['ids'], 'reason' => 'OTHER', 'use_backup' => true])->assertOk()->json();
        $this->assertSame('Second A4', $ok['printer']);
        $this->postJson("/api/print-jobs/{$ok['job']}/problem", ['what' => 'Toner empty'])->assertOk();
        $this->assertSame(1, $this->getJson('/api/print-jobs')->json('failed'));

        // replace printer in one step
        $this->postJson("/api/printers/$a4/replace", ['new' => $new])->assertStatus(403);
        $n = $this->actingAs($mgr)->postJson("/api/printers/$a4/replace", ['new' => $new])->assertOk()->json('routes');
        $this->assertGreaterThanOrEqual(1, $n);
        $this->assertSame('RETIRED', DB::table('printer_profiles')->where('id', $a4)->value('printer_status'));
        $this->postJson("/api/printers/$a4/status", ['status' => 'ACTIVE'])->assertStatus(422);
    }
}
