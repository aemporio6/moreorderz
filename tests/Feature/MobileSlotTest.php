<?php

namespace Tests\Feature;

use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-C-26 SIM slot rule, SMS forward, required set. */
class MobileSlotTest extends TestCase
{
    private function err(array $v, ?int $id = null): array
    {
        try { RecordService::save('t_account_mobile', $v, $id); return []; } catch (\Illuminate\Validation\ValidationException $e) { return $e->errors(); }
    }

    public function test_device_with_two_slots(): void
    {
        $this->actingAs($this->makeUser('OWNER')); \App\Services\Context::set(1, null);
        $f = fn ($t, $d) => (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
        $person = $f('t_account', ['fk_id_ent' => 1, 'first_name' => 'Sim', 'sur_name' => 'Holder', 'f_active' => 1]);
        $country = (int) (DB::table('cat_country')->value('id') ?: $f('cat_country', ['country_name' => 'United Kingdom']));
        $net = $f('cat_mobile_network', ['network_name' => 'Net One', 'fk_id_country' => $country, 'f_active' => 1]);
        $dev = $f('t_mobile_devices', ['fk_id_ent' => 1, 'mobile_name' => 'Phone A', 'mobile_code' => 'PA', 'tot_slots' => 2, 'f_active' => 1]);
        $base = ['fk_id_account' => $person, 'l_office_home' => 'O', 'fk_id_country' => $country, 'fk_id_mobile_network' => $net, 'mobile_capacity' => '2', 'fk_id_mobile_device' => $dev, 'f_active' => 1];
        $this->assertArrayHasKey('mobile_capacity', $this->err(['mobile_no' => '+447700900001', 'simno' => 'S1', 'mobile_capacity' => ''] + $base), 'capacity is required');
        $this->assertSame([], $this->err(['mobile_no' => '+447700900002', 'simno' => 'S2', 'slotno' => 2] + $base));
        $this->assertSame(['Slot 2 of Phone A is already used by +447700900002'], $this->err(['mobile_no' => '+447700900003', 'simno' => 'S3', 'slotno' => 2] + $base)['slotno'] ?? null);
        $this->assertSame(['Phone A has 2 slots.'], $this->err(['mobile_no' => '+447700900004', 'simno' => 'S4', 'slotno' => 3] + $base)['slotno'] ?? null);
        $id = (int) DB::table('t_account_mobiles')->where('simno', 'S2')->value('id');
        $this->assertSame(['SMS cannot be forwarded to the same mobile.'], $this->err(['sms_forward_to_mobile' => $id], $id)['sms_forward_to_mobile'] ?? null);
    }
}
