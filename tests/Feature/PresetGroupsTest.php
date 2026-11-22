<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\PresetGroups as G;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-D-62 preset group form and group actions. */
class PresetGroupsTest extends TestCase
{
    private object $u;

    protected function setUp(): void
    {
        parent::setUp();
        $this->u = $this->makeUser('OWNER'); Context::set(1, (int) $this->u->id);
    }

    private function err(callable $f): string { try { $f(); } catch (\InvalidArgumentException $e) { return $e->getMessage(); } return 'no error'; }

    public function test_form_rules_copy_names_sort_star_delete(): void
    {
        $this->assertSame('Group name is required.', $this->err(fn () => G::save(['name' => '   '], $this->u)));
        $this->assertSame('Group name: 100 characters maximum.', $this->err(fn () => G::save(['name' => str_repeat('x', 101)], $this->u)));
        $this->assertSame('Code: up to 20 letters, numbers, - or _ (e.g. RM-UNTR).', $this->err(fn () => G::save(['name' => 'Royal Mail', 'code' => 'rm untr'], $this->u)));
        $this->assertSame('Description: 255 characters maximum.', $this->err(fn () => G::save(['name' => 'Royal Mail', 'description' => str_repeat('d', 256)], $this->u)));
        $a = G::save(['name' => '  Royal   Mail ', 'code' => 'rm-untr', 'color' => '#bad000'], $this->u);
        $this->assertSame('Group saved.', $a['message']);
        $g = DB::table('car_preset_groups')->where('id', $a['id'])->first();
        $this->assertSame(['Royal Mail', 'RM-UNTR', '#17a2b8', 'ALL', 1], [$g->group_name, $g->group_code, $g->color, $g->scope_mode, (int) $g->f_active], 'spaces collapsed, code upper case, a wrong colour → default');
        $this->assertSame('A group called "royal mail" already exists.', $this->err(fn () => G::save(['name' => 'royal mail'], $this->u)));
        $b = G::save(['name' => 'Evri', 'color' => '#28a745'], $this->u);
        $this->assertSame((int) $g->sort_order + 10, (int) DB::table('car_preset_groups')->where('id', $b['id'])->value('sort_order'), 'sort = max + 10');
        G::save(['name' => 'Royal Mail', 'code' => 'RM-UNTR', 'color' => '#dc3545', 'description' => 'UK'], $this->u, $a['id']);
        $this->assertStringContainsString('Colour: #17a2b8 → #dc3545', (string) DB::table('car_preset_log')->where('fk_id_group', $a['id'])->where('action', 'EDIT')->value('detail'));

        $pre = DB::table('t_carrier_pkg_svc')->insertGetId(\App\Services\RecordService::fillNotNull('t_carrier_pkg_svc', ['pkg_svc_name' => 'RM 2nd class', 'rate' => 1.5, 'f_active' => 1]));
        DB::table('car_preset_group_items')->insert(['fk_id_group' => $a['id'], 'fk_id_pkg_svc' => $pre, 'f_pinned' => 1, 'sort_order' => 10, 'created_at' => now()]);
        DB::table('car_preset_group_scope')->insert(['fk_id_group' => $a['id'], 'fk_id_marketplace' => 1, 'fk_id_branch' => 0]);
        DB::table('car_preset_groups')->where('id', $a['id'])->update(['fk_id_default_preset' => $pre, 'scope_mode' => 'LIMITED']);
        $c1 = G::copy($a['id'], $this->u); $c2 = G::copy($a['id'], $this->u);
        $this->assertSame(['Copy created: Royal Mail (copy)', 'Copy created: Royal Mail (copy) 2'], [$c1['message'], $c2['message']]);
        $this->assertSame([1, 1, $pre, 'LIMITED'], [DB::table('car_preset_group_items')->where('fk_id_group', $c1['id'])->where('f_pinned', 1)->count(), DB::table('car_preset_group_scope')->where('fk_id_group', $c1['id'])->count(), (int) DB::table('car_preset_groups')->where('id', $c1['id'])->value('fk_id_default_preset'), DB::table('car_preset_groups')->where('id', $c1['id'])->value('scope_mode')]);
        $long = G::save(['name' => str_repeat('L', 100)], $this->u); $this->assertSame(str_repeat('L', 88) . ' (copy)', DB::table('car_preset_groups')->where('id', G::copy($long['id'], $this->u)['id'])->value('group_name'));

        $this->assertTrue(G::star($b['id'], $this->u)['star']);
        $this->assertSame($b['id'], G::list([], $this->u)['rows'][0]['id'], 'a starred group is shown first');
        $this->assertSame('Clear the search and the status filter to sort the groups.', $this->err(fn () => G::sort([$a['id']], true, $this->u)));
        $this->assertSame('Nothing to sort.', G::sort([], false, $this->u)['message']);
        G::sort([$b['id'], $a['id']], false, $this->u);
        $this->assertSame([10, 20], [(int) DB::table('car_preset_groups')->where('id', $b['id'])->value('sort_order'), (int) DB::table('car_preset_groups')->where('id', $a['id'])->value('sort_order')]);
        $this->assertSame(1, count(G::list(['q' => 'evri'], $this->u)['rows']));
        G::save(['name' => 'Evri', 'active' => 0], $this->u, $b['id']);
        $this->assertSame(['Evri'], array_column(G::list(['status' => 'inactive'], $this->u)['rows'], 'name'));

        $this->assertSame('Group deleted. The presets themselves were not touched.', G::delete($a['id'], $this->u)['message']);
        $this->assertSame([0, 0, 0, 1], [DB::table('car_preset_groups')->where('id', $a['id'])->count(), DB::table('car_preset_group_items')->where('fk_id_group', $a['id'])->count(), DB::table('car_preset_group_scope')->where('fk_id_group', $a['id'])->count(), DB::table('t_carrier_pkg_svc')->where('id', $pre)->count()]);
    }
}
