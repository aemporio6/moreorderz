<?php

namespace Tests\Feature;

use App\Services\TrackingLinks;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Owner 27-11: every tracking number a link – from the carrier, else from the number itself, else a page for every carrier; + Add carrier. */
class TrackingLinksTest extends TestCase
{
    public function test_links_by_carrier_by_number_and_fallback(): void
    {
        $this->assertSame(['https://www.royalmail.com/track-your-item#/tracking-results/AB123456789GB', 'Royal Mail'], array_slice(TrackingLinks::link(1, 'ab123456789gb'), 0, 2));
        $this->assertSame('Maersk', TrackingLinks::link(1, 'MSKU1234567')[1]);
        $this->assertSame('Any container / B/L (SeaRates)', TrackingLinks::link(1, 'ABCU1234567')[1]);
        $this->assertSame('UPS', TrackingLinks::link(1, '1Z999AA10123456784')[1]);
        $this->assertSame('Evri', TrackingLinks::link(1, 'ZZ99', 'Hermes / Evri 48')[1]);      // the record's carrier wins
        $this->assertSame('Any parcel (17TRACK)', TrackingLinks::link(1, 'QQ-UNKNOWN-1')[1]);
        DB::table('t_carriers')->insert(['fk_id_ent' => 1, 'carrier_name' => 'Local Van', 'f_active' => 1, 'tracking_url_pattern' => 'https://van.example/t/{tracking}']);
        $this->assertSame('https://van.example/t/LV1', TrackingLinks::link(1, 'LV1', 'Local Van')[0]);
        $this->actingAs($this->owner);
        $this->postJson('/api/tracking-carriers', ['name' => 'Speedy', 'url' => 'https://speedy.example/track'])->assertStatus(422);
        $r = $this->postJson('/api/tracking-carriers', ['name' => 'Speedy Freight', 'mode' => 'ROAD', 'url' => 'https://speedy.example/track?n={n}', 'starts' => 'SPF'])->assertOk()->json();
        $this->assertSame('Speedy Freight', $r['tracking'][0]['name']);
        $this->assertSame(['https://speedy.example/track?n=SPF00042', 'Speedy Freight'], array_slice(TrackingLinks::link(1, 'SPF00042'), 0, 2));
        $this->assertSame('Speedy Freight', $this->getJson('/api/tracking-link?no=SPF7')->json('carrier'));
        $this->assertNotEmpty($this->getJson('/api/boot')->json('tracking'));
    }
}
