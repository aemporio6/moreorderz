<?php

namespace Tests\Feature;

use App\Services\Fx;
use App\Services\FxLive;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FxLiveTest extends TestCase
{
    private const XML = "<?xml version='1.0'?><gesmes:Envelope><Cube><Cube time='2026-11-26'><Cube currency='USD' rate='1.0500'/><Cube currency='CNY' rate='7.6000'/><Cube currency='GBP' rate='0.8400'/><Cube currency='MYR' rate='4.9000'/></Cube></Cube></gesmes:Envelope>";

    public function test_rates_come_in_in_the_base_currency_and_a_typed_rate_is_kept(): void
    {
        DB::table('fin_fx_rates')->where('rate_date', '2026-11-26')->delete();
        Fx::set('USD', '2026-11-26', 0.81, 'MANUAL');
        Http::fake(['www.ecb.europa.eu/*' => Http::response(self::XML)]);
        $r = FxLive::pull();
        $this->assertTrue($r['ok']); $this->assertSame('2026-11-26', $r['date']); $this->assertSame(1, $r['kept']);
        $this->assertEqualsWithDelta(0.84 / 7.6, Fx::rate('CNY', '2026-11-26'), 0.000001);
        $this->assertEqualsWithDelta(0.84, Fx::rate('EUR', '2026-11-26'), 0.000001);
        $this->assertEqualsWithDelta(0.81, Fx::rate('USD', '2026-11-26'), 0.000001);      // typed by hand wins
        $this->assertSame('ECB', DB::table('fin_fx_rates')->where('rate_date', '2026-11-26')->where('currency', 'CNY')->value('source'));
        $s = FxLive::strip(); $this->assertSame('GBP', $s['base']); $this->assertContains('CNY', array_column($s['rates'], 'cur'));
    }

    public function test_a_failed_fetch_never_throws_and_writes_nothing(): void
    {
        Http::fake(['www.ecb.europa.eu/*' => Http::response('down', 503)]);
        $n = DB::table('fin_fx_rates')->count();
        $r = FxLive::pull(); $this->assertFalse($r['ok']); $this->assertSame($n, DB::table('fin_fx_rates')->count());
    }

    public function test_an_earlier_row_keeps_its_own_rate(): void
    {
        Fx::set('CNY', '2026-10-01', 0.10, 'ECB'); Fx::set('CNY', '2026-11-01', 0.12, 'ECB');
        $this->assertEqualsWithDelta(10.0, Fx::toBase(100, 'CNY', '2026-10-15'), 0.001);
        $this->assertEqualsWithDelta(12.0, Fx::toBase(100, 'CNY', '2026-11-15'), 0.001);
    }
}
