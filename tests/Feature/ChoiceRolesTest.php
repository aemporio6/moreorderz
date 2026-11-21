<?php

namespace Tests\Feature;

use App\Services\Choices;
use App\Services\DashCards;
use App\Services\RecordService;
use App\Services\Registry;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Owner rule 22 Nov 03:47 / 03:48: my suggestions are the defaults but never locked – role lists are multiple choices (All, + Add, recommended) in Settings › Choices. */
class ChoiceRolesTest extends TestCase
{
    private function person(string $name, string $role): int
    {
        $type = DB::table('cat_account_types')->whereRaw('UPPER(role_code) = ?', [$role])->value('id')
            ?? DB::table('cat_account_types')->insertGetId(RecordService::fillNotNull('cat_account_types', ['account_type_name' => ucfirst(strtolower($role)), 'role_code' => $role, 'iso_account_type' => $role . random_int(10, 99), 'f_active' => 1, 'fk_id_ent' => 1]));
        $a = DB::table('t_account')->insertGetId(RecordService::fillNotNull('t_account', ['fk_id_ent' => 1, 'account_code' => 'CR' . random_int(10000, 99999), 'first_name' => $name, 'f_active' => 1]));
        DB::table('t_account_in_types')->insert(RecordService::fillNotNull('t_account_in_types', ['fk_id_account' => $a, 'fk_id_account_type' => $type, 'f_active' => 1]));
        return $a;
    }

    public function test_b3_director_roles_default_and_other_option(): void
    {
        $dir = $this->person('Dina Director', 'DIRECTOR'); $va = $this->person('Vic Va', 'VA');
        $f = (array) DB::table('sys_registry')->where('field_name', 'fk_director_1')->first(); $this->assertSame('party:@DIRECTOR_ROLES', $f['source']);
        $ids = fn () => array_map(fn ($o) => (int) $o[0], Registry::options($f, 1, null, 5000));
        $this->assertSame(['OWNER', 'DIRECTOR', 'PARTNER'], Choices::list('DIRECTOR_ROLES', 1), 'default = my suggestion');
        $this->assertContains($dir, $ids()); $this->assertNotContains($va, $ids(), 'a VA is no director by default');
        Choices::set('DIRECTOR_ROLES', 'OWNER,DIRECTOR,PARTNER,VA', 1);
        $this->assertContains($va, $ids(), 'the other option changes the picker');
        Choices::set('DIRECTOR_ROLES', 'ALL', 1); $this->assertSame('ALL', Choices::get('DIRECTOR_ROLES', 1)); $this->assertContains($va, $ids());
        try { Choices::set('DIRECTOR_ROLES', 'NOBODY_ROLE', 1); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('Choose roles from the list (NOBODY_ROLE is not one).', $e->getMessage()); }
        try { Choices::set('DIRECTOR_ROLES', ' , ', 1); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('Choose at least one role (or All).', $e->getMessage()); }
    }

    public function test_b5_hidden_margin_roles_default_owner_only(): void
    {
        DB::table('t_inv_periods')->insert(RecordService::fillNotNull('t_inv_periods', ['fk_id_ent' => 1, 'd_from' => '2026-11-01', 'd_to' => '2026-11-30', 'status' => 'CONFIRMED', 'capital' => 142, 'actual_cost' => 100]));
        $rows = fn (string $role) => array_column(DashCards::invoicing(1, '2026-11-01', '2026-11-30', $role)['rows'], 0);
        $this->assertContains('Hidden margin (period)', $rows('OWNER'));
        $this->assertNotContains('Hidden margin (period)', $rows('PARTNER'), 'default: partners do not see it');
        $owner = $this->makeUser('OWNER');
        $this->actingAs($owner)->postJson('/api/choices', ['key' => 'HIDDEN_MARGIN_ROLES', 'value' => 'OWNER,PARTNER'])->assertOk();
        $this->assertContains('Hidden margin (period)', $rows('PARTNER'), 'the other option: partners see it');
        $c = collect($this->actingAs($owner)->getJson('/api/choices')->assertOk()->json('choices'))->firstWhere('key', 'HIDDEN_MARGIN_ROLES');
        $this->assertSame([true, ['OWNER', 'PARTNER'], '#/rights'], [$c['multi'], $c['values'], $c['add_url']]);
        $this->assertSame(['ALL', true], [$c['options'][0]['value'], collect($c['options'])->firstWhere('value', 'OWNER')['recommended']], 'All first, Owner recommended');
        $this->assertStringStartsWith('Recommended: Owner only', $c['why']);
    }
}
