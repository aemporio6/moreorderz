<?php

namespace Tests\Feature;

use App\Services\PrintService as Prints;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-G_CORE-114 label media – what is loaded; one tap on the Work screen; jobs that do not fit wait with the message. */
class MediaSwitchTest extends TestCase
{
    public function test_g114_switched_media_makes_jobs_wait_and_switching_back_prints_them(): void
    {
        $this->actingAs($this->owner);
        $th = (int) DB::table('printer_profiles')->where('code', 'SETUP_THERMAL_1')->value('id'); $m = DB::table('label_media')->pluck('id', 'code');
        [$roll, $roll96] = [(int) $m['SETUP_ROLL_100X150'], (int) $m['SETUP_ROLL_96X136']];
        Prints::calibrate(1, $th, $roll, true); Prints::calibrate(1, $th, $roll96, true);
        Prints::setRoute(1, 'LOCATION', $th, null, $roll96);
        $bin = (int) DB::table('cat_inventory_locations')->insertGetId(RecordService::fillNotNull('cat_inventory_locations', ['location_name' => 'M-1', 'location_code' => 'M-1', 'kind' => 'BIN', 'fk_id_ent' => 1, 'f_active' => 1]));
        $name = DB::table('printer_profiles')->where('id', $th)->value('name');
        // staff load 100 × 150 labels: one tap on the Work screen
        $board = $this->postJson("/api/printers/$th/media", ['media' => $roll])->assertOk()->json();
        $this->assertSame($roll, collect($board['printers'])->firstWhere('id', $th)['loaded']);
        // a 96 × 136 job now waits with the message
        $r = $this->postJson('/api/print', ['kind' => 'LOCATION', 'ids' => [$bin]])->assertStatus(202)->json();
        $this->assertSame('WAITING', $r['status']); $this->assertSame("Load 96 × 136 labels in $name", $r['note']);
        $this->assertSame("Load 96 × 136 labels in $name", DB::table('print_jobs')->where('id', $r['job'])->value('error_text'));
        $w = collect($this->getJson('/api/printers/media')->assertOk()->json('printers'))->firstWhere('id', $th)['waiting'];
        $this->assertSame([['media' => $roll96, 'count' => 1, 'text' => "Load 96 × 136 labels in $name"]], $w);
        // switched back → the waiting job prints
        $x = $this->postJson("/api/printers/$th/media", ['media' => $roll96])->assertOk()->json();
        $this->assertCount(1, $x['printed']); $this->assertSame(0, $x['still_waiting']);
        $this->assertSame('RELEASED', DB::table('print_jobs')->where('id', $r['job'])->value('status'));
        $this->assertSame('PRINTED', DB::table('print_jobs')->where('id', $x['printed'][0]['job'])->value('status'));
        $this->assertSame(1, DB::table('label_print_log')->where('fk_id_print_job', $x['printed'][0]['job'])->count());
        // with the right labels loaded a job prints at once
        $this->assertSame('PRINTED', Prints::print(1, 'LOCATION', [$bin], ['reason' => 'OTHER'])['status']);
        // labels that do not fit the printer are refused
        $a4 = (int) $m['SETUP_A4_PLAIN'];
        $this->postJson("/api/printers/$th/media", ['media' => $a4])->assertStatus(422);
    }
}
