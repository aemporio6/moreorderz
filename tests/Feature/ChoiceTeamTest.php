<?php

namespace Tests\Feature;

use App\Services\Approvals;
use App\Services\Choices;
use App\Services\Period;
use App\Services\TaskSettings;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Owner rule 22 Nov 03:47: B6 period From > To, B25 who sets absences / delegations / categories and "ALL" delegations for money – Settings › Choices (default = my suggestion). */
class ChoiceTeamTest extends TestCase
{
    public function test_b6_from_after_to_swapped_or_refused(): void
    {
        $owner = $this->makeUser('OWNER'); $this->actingAs($owner);
        $this->assertSame(['2026-11-05', '2026-11-20'], array_values(array_intersect_key(Period::resolve('CUSTOM', '2026-11-20', '2026-11-05'), ['from' => 1, 'to' => 1])), 'default: swapped');
        Choices::set('PERIOD_FROM_AFTER_TO', 'REFUSE', 1);
        try { Period::resolve('CUSTOM', '2026-11-20', '2026-11-05'); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('From is after To – change the dates.', $e->getMessage()); }
        $this->getJson('/api/buying/card/invoicing?period=CUSTOM&from=2026-11-20&to=2026-11-05')->assertStatus(422)->assertJsonPath('message', 'From is after To – change the dates.');
        $this->assertSame('2026-11-05', Period::resolve('CUSTOM', '2026-11-05', '2026-11-20')['from'], 'a good range still works');
    }

    public function test_b25_team_settings_roles_and_all_delegation_money(): void
    {
        $tl = $this->makeUser('TL'); $mgr = $this->makeUser('MANAGER'); $va = $this->makeUser('VA');
        Auth::login($tl); $d = ['user_id' => 1, 'from_date' => date('Y-m-d'), 'to_date' => date('Y-m-d', strtotime('+2 days'))];
        $this->assertSame('Refused: only a Partner or Manager can set absences.', TaskSettings::absence($d, [], null)['_form'] ?? null, 'default: a team lead may not');
        Choices::set('ABSENCE_ROLES', 'OWNER,PARTNER,MANAGER,TL', 1); $d = ['user_id' => 1, 'from_date' => date('Y-m-d'), 'to_date' => date('Y-m-d', strtotime('+2 days'))];
        $this->assertArrayNotHasKey('_form', TaskSettings::absence($d, [], null), 'the other option: a team lead may');
        Auth::login($mgr); $d = ['from_user_id' => $mgr->id, 'to_user_id' => $va->id, 'from_date' => date('Y-m-d'), 'rights' => 'APPROVE'];
        $this->assertSame('Refused: only a Partner can set delegations.', TaskSettings::delegation($d, [], null)['_form'] ?? null);
        Choices::set('DELEGATION_ROLES', 'OWNER,PARTNER,MANAGER', 1); $d = ['from_user_id' => $mgr->id, 'to_user_id' => $va->id, 'from_date' => date('Y-m-d'), 'rights' => 'APPROVE'];
        $this->assertArrayNotHasKey('_form', TaskSettings::delegation($d, [], null));
        $d = ['name' => 'Returns ' . uniqid()]; $this->assertSame('Refused: only a Partner can change task categories.', TaskSettings::category($d, [], null)['_form'] ?? null);
        Choices::set('CATEGORY_ROLES', 'ALL', 1); $d = ['name' => 'Returns ' . uniqid()]; $this->assertArrayNotHasKey('_form', TaskSettings::category($d, [], null));
        // an "ALL" delegation from a Manager lets the VA decide a money approval – unless the choice says no
        DB::table('tm_delegations')->insert(['from_user_id' => $mgr->id, 'to_user_id' => $va->id, 'from_date' => date('Y-m-d', strtotime('-1 day')), 'to_date' => date('Y-m-d', strtotime('+3 days')), 'rights' => 'ALL', 'created_by' => $mgr->id, 'created_at' => now()]);
        $ap = fn () => DB::table('sys_approvals')->insertGetId(['fk_id_ent' => 1, 'action_code' => 'EXPENSE', 'entity' => 'fin_expenses', 'entity_id' => 0, 'amount_gbp' => 50, 'requested_by' => $tl->id, 'requested_at' => now(), 'status' => 'PENDING']);
        $this->actingAs($va);
        Choices::set('DELEGATION_ALL_MONEY', '0', 1); $a1 = $ap();
        try { Approvals::decide($a1, false, 'not needed'); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('Only the Owner or a Manager can decide this.', $e->getMessage()); }
        Choices::set('DELEGATION_ALL_MONEY', '1', 1);
        $this->assertSame('REJECTED', Approvals::decide($ap(), false, 'not needed')->status, 'default: ALL covers money approvals');
    }
}
