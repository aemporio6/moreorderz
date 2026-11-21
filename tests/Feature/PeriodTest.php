<?php

namespace Tests\Feature;

use App\Services\DashCards;
use App\Services\Period;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_F_SETUP R-F_SETUP-27 shared period component, R-F_SETUP-29 Invoicing / Profit card, R-F_SETUP-30 Investors card. */
class PeriodTest extends TestCase
{
    private function f(string $t, array $d): int
    {
        foreach (['fk_id_ent' => 1, 'f_active' => 1, 'created_at' => now()] as $k => $v) if (! array_key_exists($k, $d) && \Illuminate\Support\Facades\Schema::hasColumn($t, $k)) $d[$k] = $v;
        return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
    }

    public function test_period_options_and_the_rule_example(): void
    {
        $r = fn ($k, $f = null, $t = null) => array_values(array_intersect_key(Period::resolve($k, $f, $t, '2026-11-21'), array_flip(['from', 'to'])));
        $this->assertSame(['2026-11-01', '2026-11-06'], $r('CUSTOM', 'abc', '2026-11-06'), 'the rule example');
        $this->assertSame(['2026-11-21', '2026-11-21'], $r('TODAY'));
        $this->assertSame(['2026-11-14', '2026-11-21'], $r('THIS_WEEK'));
        $this->assertSame(['2026-11-01', '2026-11-21'], $r('THIS_MONTH'));
        $this->assertSame(['2026-10-01', '2026-10-31'], $r('LAST_MONTH'));
        $this->assertSame(['2026-08-21', '2026-11-21'], $r('LAST_3_MONTHS'));
        $this->assertSame(['2026-05-21', '2026-11-21'], $r('LAST_6_MONTHS'));
        $this->assertSame(['2026-01-01', '2026-11-21'], $r('THIS_YEAR'));
        $this->assertSame(['2026-11-01', '2026-11-21'], $r('nonsense'), 'unknown = This Month');
        $this->assertSame(['2026-11-03', '2026-11-09'], $r('CUSTOM', '2026-11-09', '2026-11-03'), 'From > To swapped');
        $this->assertSame(['2026-11-01', '2026-11-21'], $r('CUSTOM', '2026-11-01', 'x'), 'a bad To = today');
    }

    public function test_defaults_and_last_choice_per_user_per_card(): void
    {
        $this->actingAs($this->owner);
        $this->assertSame('THIS_YEAR', $this->getJson('/api/period/investors')->assertOk()->json('key'), 'Investors default = This Year');
        $this->assertSame('TODAY', Period::forCard($this->owner->id, 'team')['key']);
        $this->getJson('/api/period/investors?period=LAST_MONTH')->assertOk();
        $this->assertSame('LAST_MONTH', $this->getJson('/api/period/investors')->json('key'), 'remembered');
        $this->assertSame('THIS_MONTH', Period::forCard($this->owner->id, 'invoicing')['key'], 'per card');
        $this->assertSame('THIS_YEAR', Period::forCard($this->owner->id + 999, 'investors')['key'], 'per user');
    }

    public function test_investors_and_invoicing_cards(): void
    {
        $this->actingAs($this->owner);
        $a = $this->f('t_account', ['account_code' => 'IA' . substr(uniqid(), -5), 'first_name' => 'Anna', 'sur_name' => 'Inv']);
        $cy = $this->f('t_src_cycles', ['cycle_code' => 'CYC-PC-' . uniqid(), 'product_name' => 'Lamp', 'status' => 'Open', 'cycle_type' => 'FULL', 'created_by' => $this->owner->id]);
        $this->f('t_src_investments', ['fk_id_cycle' => $cy, 'fk_id_investor' => $a, 'invest_code' => 'PC-1', 'invest_amount' => 2000, 'base_amount' => 0, 'invest_date' => '2026-06-01']);
        $this->f('fin_lots', ['company_id' => 1, 'fk_id_owner' => $a, 'qty' => 100, 'qty_left' => 40, 'unit_cost_gbp' => 2.5, 'received_date' => '2026-06-10', 'lot_code' => 'L-PC']);
        $x = DashCards::investors(1, '2026-01-01', '2026-12-31');
        $this->assertEquals([['name' => 'Anna Inv', 'amount' => 2000, 'cycles' => 1]], $x['invested'], 'base 0 → amount 2,000.00 counted');
        $this->assertEquals(100, collect($x['goods'])->firstWhere('name', 'Anna Inv')['amount'], 'qty on hand × cost');
        $this->assertNull($x['empty']);
        $this->assertSame([], DashCards::investors(1, '2020-01-01', '2020-01-31')['invested'], 'nothing invested in that period');

        $this->f('t_inv_periods', ['fk_id_ent' => 1, 'status' => 'CONFIRMED', 'd_from' => '2026-11-01', 'd_to' => '2026-11-30', 'capital' => 10000, 'actual_cost' => 9400, 'gross_profit' => 3000, 'final_net_profit' => 2000]);
        $this->f('t_inv_profit_splits', ['fk_id_ent' => 1, 'd_effective_from' => '2026-01-01', 'investor_pct' => 50, 'owner_pct' => 30, 'va_pct' => 15, 'handler_pct' => 5]);
        $own = collect(DashCards::invoicing(1, '2026-11-01', '2026-11-30', 'OWNER')['rows'])->pluck(1, 0);
        $this->assertSame(['600.00', '3,000.00', '2,000.00', '50% / 30% / 15% / 5%'], [$own['Hidden margin (period)'], $own['Gross profit (period)'], $own['Net profit (period)'], $own['Profit split (Inv/Own/VA/Hnd)']]);
        $this->assertArrayNotHasKey('Hidden margin (period)', collect(DashCards::invoicing(1, '2026-11-01', '2026-11-30', 'PARTNER')['rows'])->pluck(1, 0)->all(), 'absent for PARTNER');
        $c = $this->getJson('/api/buying/card/invoicing?period=CUSTOM&from=2026-11-01&to=2026-11-30')->assertOk()->json('data');
        $this->assertSame(['2026-11-01', '2026-11-30'], [$c['period']['from'], $c['period']['to']]);
    }
}
