<?php

namespace Tests\Feature;

use App\Services\RecordService;
use App\Services\TaskEngine;
use App\Services\TrackingPush as TP;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_H R-H-33: Ready to dispatch and tracking back by the marketplace profile – API when connected, else its CSV (never API-only); ON_LABEL / ON_HANDOVER; cut-off tasks. */
class TrackingPushTest extends TestCase
{
    private function shop(string $when, ?string $layout = null): array
    {
        $f = fn ($t, $d) => (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
        $prof = $f('mp_profiles', ['fk_id_ent' => 1, 'code' => 'P' . uniqid(), 'name' => 'Prof', 'tracking_upload_when' => $when, 'tracking_upload_layout' => $layout, 'dispatch_cutoff_time' => '16:00:00', 'f_active' => 1, 'created_at' => now()]);
        $shop = $f('t_branches', ['fk_id_ent' => 1, 'fk_id_mp_profile' => $prof, 'shop_no' => 'S' . random_int(100, 999), 'shop_name' => $when . ' shop', 'iso_branch' => 'X' . random_int(10, 99), 'f_active' => 1]);
        return [$shop, $prof];
    }

    private function parcel(int $shop, string $status, string $track, string $label = 'READY', ?string $handed = null, ?int $carrier = null): int
    {
        return (int) DB::table('wh_parcels')->insertGetId(RecordService::fillNotNull('wh_parcels', ['fk_id_ent' => 1, 'parcel_code' => 'PCL-' . uniqid(), 'fk_id_branch' => $shop, 'order_no' => 'O-' . uniqid(), 'tracking_no' => $track, 'label_status' => $label,
            'status' => $status, 'handed_over_at' => $handed, 'packed_at' => now(), 'created_at' => now(), 'fk_id_carrier' => $carrier]));
    }

    public function test_the_rule_example(): void
    {
        $this->actingAs($this->owner);
        if (! DB::table('mo_tracking_layouts')->where('code', 'EBAY_LIKE')->exists()) DB::table('mo_tracking_layouts')->insert(['code' => 'EBAY_LIKE', 'name' => 'eBay-like', 'delimiter' => ',', 'columns_json' => json_encode([['Order', 'order_no'], ['Shipment Tracking Number', 'tracking_no'], ['Carrier', 'carrier']]), 'f_active' => 1]);
        [$ebay] = $this->shop('ON_HANDOVER', 'EBAY_LIKE');
        foreach (['T1', 'T2', 'T3'] as $t) $this->parcel($ebay, 'HANDED_OVER', $t, 'PRINTED', now()->toDateTimeString());
        $ready = $this->parcel($ebay, 'READY', 'T4');      // still on the desk: not told "shipped" yet
        $x = TP::export(1, $ebay);
        $this->assertSame(['Order', 'Shipment Tracking Number', 'Carrier'], $x['headers']); $this->assertSame(['T1', 'T2', 'T3'], array_column($x['rows'], 1)); $this->assertSame('EBAY_LIKE', $x['layout']);      // 3 handed-over parcels in its layout
        $this->assertSame(3, DB::table('wh_parcels')->where('tracking_upload_via', 'CSV')->where('tracking_upload_ref', $x['ref'])->count());
        try { TP::export(1, $ebay); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('No tracking is waiting', $e->getMessage()); }      // sent once
        [$tiktok] = $this->shop('ON_LABEL');
        $this->parcel($tiktok, 'READY', 'TT1', 'READY');      // ON_LABEL: at label time
        $this->assertSame(['TT1'], TP::due(1, $tiktok)->pluck('tracking_no')->all());
        $this->assertSame('CSV', TP::board(1)[0]['route']);
    }

    public function test_never_api_only_and_the_cut_off_tasks(): void
    {
        $this->actingAs($this->owner); [$shop, $prof] = $this->shop('ON_HANDOVER');
        $api = DB::table('api_connections')->insertGetId(RecordService::fillNotNull('api_connections', ['fk_id_ent' => 1, 'provider' => 'X', 'status' => 'NEW', 'fk_id_mp_profile' => $prof]));
        DB::table('mp_profiles')->where('id', $prof)->update(['fk_id_api_connection' => $api]);
        $p = $this->parcel($shop, 'HANDED_OVER', 'Z1', 'PRINTED', date('Y-m-d', strtotime('-1 day')) . ' 10:00:00');
        $b = collect(TP::board(1))->firstWhere('shop_id', $shop); $this->assertSame('CSV', $b['route']); $this->assertStringContainsString('never API-only', $b['note']);      // the API is not connected: the CSV carries it
        $car = DB::table('t_carriers')->insertGetId(RecordService::fillNotNull('t_carriers', ['carrier_name' => 'RM', 'collection_cutoff_time' => '15:00:00', 'f_active' => 1]));
        $r = $this->parcel($shop, 'READY', 'Z2', 'READY', null, $car); $code = DB::table('wh_parcels')->where('id', $r)->value('parcel_code');
        TP::tasks(1, date('Y-m-d') . ' 17:00:00');
        $this->assertTrue(TaskEngine::isOpen('LBL_TRACKING_UPLOAD', [$shop, date('Y-m-d', strtotime('-1 day'))])); $this->assertTrue(TaskEngine::isOpen('WH_NOT_HANDED_OVER', [$code]));
        TP::export(1, $shop); DB::table('wh_parcels')->where('id', $r)->update(['status' => 'HANDED_OVER', 'handed_over_at' => now()]); DB::table('wh_parcels')->where('id', $r)->update(['tracking_uploaded_at' => now()]);
        TP::tasks(1, date('Y-m-d') . ' 17:00:00');
        $this->assertFalse(TaskEngine::isOpen('LBL_TRACKING_UPLOAD', [$shop, date('Y-m-d', strtotime('-1 day'))])); $this->assertFalse(TaskEngine::isOpen('WH_NOT_HANDED_OVER', [$code]));
    }
}
