<?php

namespace Tests\Feature;

use App\Services\PersonHeader;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_C_ACCOUNTS R-C-18: person header – one chip per sub-list (all rows), health red / amber / grey from the Account kit dates. */
class PersonHeaderTest extends TestCase
{
    private function f(string $t, array $d): int { return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }

    public function test_the_rule_example_two_mobiles_one_recharge_in_two_days_is_amber(): void
    {
        $this->actingAs($this->owner);
        $p = $this->f('t_account', ['fk_id_ent' => 1, 'account_code' => 'PH' . random_int(100, 999), 'first_name' => 'Hina', 'sur_name' => 'Test', 'f_active' => 1]);
        $this->f('t_account_mobiles', ['fk_id_account' => $p, 'mobile_no' => '07000000001', 'l_recharge_contract' => 'R', 'next_recharge_date' => date('Y-m-d', strtotime('+2 days')), 'f_active' => 1]);
        $this->f('t_account_mobiles', ['fk_id_account' => $p, 'mobile_no' => '07000000002', 'l_recharge_contract' => 'C', 'f_active' => 0]);
        $h = $this->getJson("/api/people/$p/header")->assertOk()->json();
        $m = collect($h['chips'])->firstWhere('key', 'mobiles');
        $this->assertSame(['Mobiles', 2, 'amber'], [$m['label'], $m['count'], $m['health']], 'chip "Mobiles 2" amber – inactive rows count too');
        $this->assertSame('amber', $h['health']);
        $this->assertSame(0, collect($h['chips'])->firstWhere('key', 'job_history')['count'], 'job history is a chip');
        $this->assertStringContainsString('f[fk_id_account]=' . $p, (string) $m['url']);

        // a past date → red; a seller marketplace account without a shop → grey
        $this->f('t_account_photo_ids', ['fk_id_account' => $p, 'photo_expiry_date' => date('Y-m-d', strtotime('-3 days')), 'f_active' => 1]);
        $this->f('t_account_marketplaces', ['fk_id_account' => $p, 'market_place_type' => 'Seller', 'f_active' => 1]);
        $h = PersonHeader::for($p);
        $c = collect($h['chips'])->keyBy('key');
        $this->assertSame(['red', 'grey', 1], [$c['photo_ids']['health'], $c['mp_seller']['health'], $c['mp_seller']['count']]);
        $this->assertSame(0, $c['mp_buyer']['count']);
        $this->assertSame('red', $h['health'], 'the worst colour wins');
        $this->assertNotEmpty($h['health_why']);
    }
}
