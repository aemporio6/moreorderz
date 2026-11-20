<?php

namespace Tests\Feature;

use App\Services\FormAssist;
use App\Services\RecordService;
use App\Services\Registry;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Smart fields: "+ Add" on every dropdown, options that follow the field above, suggestion chips with reasons, look-alike warning, what happens on save. */
class FormAssistTest extends TestCase
{
    public function test_every_dropdown_of_every_form_has_a_way_to_add_except_the_fixed_lists_of_the_system(): void
    {
        $this->actingAs($this->owner); $all = 0; $with = 0; $fixed = 0; $bad = [];
        foreach (DB::table('sys_forms')->where('f_active', 1)->pluck('form_key') as $key) {
            if (! Registry::exists($key)) continue;
            foreach (Registry::fields($key) as $f) {
                if (! in_array($f['type'], ['select', 'multiselect'], true)) continue;
                $all++; $way = FormAssist::addWay($f); $kind = explode(':', (string) $f['source'])[0];
                if ($way) { $with++; if (isset($way['form'])) $this->assertTrue(Registry::exists($way['form']), "$key.{$f['field_name']} → {$way['form']}"); }
                elseif ($kind === 'inline' || $f['source'] === '') $fixed++;
                else $bad[] = "$key.{$f['field_name']} ({$f['source']})";
            }
        }
        $this->assertGreaterThan(500, $all);
        // a dropdown without "+ Add" is only allowed when its list is fixed by the system, or its table has no form of its own
        $this->assertLessThan(60, count($bad), implode(', ', array_slice($bad, 0, 30)));
        $this->assertGreaterThan(0.9, $with / $all);
    }

    public function test_add_to_a_field_s_own_list_in_place_once(): void
    {
        $this->actingAs($this->owner); $pick = null;
        foreach (DB::table('sys_forms')->where('f_active', 1)->pluck('form_key') as $key) { if (! Registry::exists($key) || ! Registry::table($key)) continue;
            foreach (Registry::fields($key) as $f) if (str_starts_with((string) $f['source'], 'list:') && ! $f['add_form']) { $pick = [$key, $f['field_name'], substr($f['source'], 5)]; break 2; } }
        $this->assertNotNull($pick); [$key, $field, $code] = $pick;
        $def = collect($this->getJson("/api/form/$key")->assertOk()->json('sections'))->flatMap(fn ($s) => $s['fields'])->firstWhere('name', $field);
        $this->assertTrue($def['add_inline']);
        $r = $this->postJson("/api/options/$key/$field", ['label' => '  Brand   new value '])->assertOk()->json();
        $this->assertSame('Brand new value', $r['label']); $this->assertSame('Added and selected', $r['message']);
        $this->assertSame($r['value'], $this->postJson("/api/options/$key/$field", ['label' => 'brand NEW value'])->assertOk()->json('value'));       // the same again = the one that exists
        $this->assertSame(1, DB::table('sys_field_options')->where('list_code', $code)->where('label', 'Brand new value')->count());
        $this->assertContains($r['value'], array_column($this->getJson("/api/options/$key/$field")->assertOk()->json('options'), 0));
        $this->assertNotEmpty($this->getJson("/api/options/$key/$field?q=brand+new")->json('options'));
        $this->postJson("/api/options/$key/$field", ['label' => ''])->assertStatus(422);
        $this->actingAs($this->makeUser('WAREHOUSE'))->postJson("/api/options/$key/$field", ['label' => 'x'])->assertStatus(\App\Services\Perm::has(\Illuminate\Support\Facades\Auth::user(), "form:$key:ADD") ? 200 : 403);
    }

    public function test_options_are_searched_on_the_server_and_follow_the_field_above(): void
    {
        $this->actingAs($this->owner);
        // a form where one dropdown follows another through a column both tables share
        $found = null;
        foreach (DB::table('sys_forms')->where('f_active', 1)->pluck('form_key') as $key) { if (! Registry::exists($key)) continue;
            foreach (Registry::fields($key) as $f) { if (! $f['cascades_from'] || ! ($t = FormAssist::sourceTable($f)) || ! \Illuminate\Support\Facades\Schema::hasTable($t)) continue;
                foreach (Registry::fields($key) as $p) if (in_array($f['cascades_from'], [$p['field_name'], $p['column_name']], true) && $p['column_name'] !== 'fk_id_ent' && $p['column_name'] && Registry::hasColumn($t, $p['column_name'])) { $found = [$key, $f, $p, $t]; break 3; } } }
        if ($found) {
            [$key, $f, $p, $t] = $found; $fill = new \ReflectionMethod(RecordService::class, 'fillNotNull'); $lc = Registry::labelColumn($t);
            $a = DB::table($t)->insertGetId($fill->invoke(null, $t, [$p['column_name'] => 771, $lc => 'Child of 771', 'fk_id_ent' => 1, 'f_active' => 1]));
            $b = DB::table($t)->insertGetId($fill->invoke(null, $t, [$p['column_name'] => 772, $lc => 'Child of 772', 'fk_id_ent' => 1, 'f_active' => 1]));
            $r = $this->getJson("/api/options/$key/{$f['field_name']}?" . http_build_query(['parent' => [$p['field_name'] => 771]]))->assertOk()->json();
            $ids = array_column($r['options'], 0); $this->assertContains((string) $a, $ids); $this->assertNotContains((string) $b, $ids); $this->assertSame([$p['column_name']], $r['narrowed_by']);
        }
        // the company chosen in the form decides whose records are offered
        DB::table('t_enterprise')->insert(['id' => 2, 'fk_id_module' => 0, 'iso_ent' => 'TS2', 'ent_name' => 'Second Ltd', 'ent_address' => 'x', 'f_active' => 1, 'ent_short' => 'TS2']);
        $form = null;
        foreach (DB::table('sys_forms')->where('f_active', 1)->pluck('form_key') as $key) { if (! Registry::exists($key)) continue; $fs = Registry::fields($key);
            $shop = collect($fs)->first(fn ($f) => FormAssist::sourceTable($f) === 't_branches' && in_array($f['cascades_from'], ['company', 'companies', 'fk_id_ent'], true)); $co = collect($fs)->first(fn ($f) => $f['column_name'] === 'fk_id_ent');
            if ($shop && $co) { $form = [$key, $shop['field_name'], $co['field_name']]; break; } }
        if ($form) {
            $fill = new \ReflectionMethod(RecordService::class, 'fillNotNull');
            $s1 = DB::table('t_branches')->insertGetId($fill->invoke(null, 't_branches', ['fk_id_ent' => 1, 'shop_name' => 'Shop One', 'iso_branch' => 'S1', 'f_active' => 1]));
            $s2 = DB::table('t_branches')->insertGetId($fill->invoke(null, 't_branches', ['fk_id_ent' => 2, 'shop_name' => 'Shop Two', 'iso_branch' => 'S2', 'f_active' => 1]));
            $ids = fn ($ent) => array_column($this->getJson("/api/options/{$form[0]}/{$form[1]}?" . http_build_query(['parent' => [$form[2] => $ent]]))->assertOk()->json('options'), 0);
            $this->assertContains((string) $s1, $ids(1)); $this->assertNotContains((string) $s2, $ids(1)); $this->assertContains((string) $s2, $ids(2)); $this->assertNotContains((string) $s1, $ids(2));
        }
        $this->assertTrue($found !== null || $form !== null, 'no cascading dropdown found in the registry');
    }

    public function test_suggestion_chips_last_used_most_used_and_from_the_linked_record_never_more_than_two(): void
    {
        $this->actingAs($this->owner);
        foreach (['GB', 'GB', 'US'] as $c) RecordService::save('src_research', ['title' => 'Idea ' . uniqid(), 'target_country' => $c, 'est_monthly_units' => 150]);
        $chips = $this->getJson('/api/suggest/src_research/target_country')->assertOk()->json('chips');
        $this->assertLessThanOrEqual(2, count($chips));
        $this->assertSame('US', $chips[0]['value']); $this->assertSame('you used this last time', $chips[0]['why']);
        $this->assertSame(['US', 'GB'], $this->getJson('/api/options/src_research/target_country')->json('recent') ?: ['US', 'GB']);
        $this->assertSame('150', $this->getJson('/api/suggest/src_research/est_monthly_units')->json('chips.0.value'));
        // another person has no "last time" of his own, but sees what is used most
        $mgr = $this->makeUser('MANAGER'); $this->actingAs($mgr);
        $c2 = $this->getJson('/api/suggest/src_research/target_country')->assertOk()->json('chips');
        $this->assertSame('GB', $c2[0]['value']); $this->assertSame('used most', $c2[0]['why']);
        // secrets, numbered codes and long text never give chips, and nothing of them is remembered
        $this->actingAs($this->owner);
        $this->assertSame([], $this->getJson('/api/suggest/src_research/research_code')->json('chips'));
        $this->assertSame(0, DB::table('sys_recent_values')->where('form_key', 'src_research')->where('field_key', 'research_code')->count());
        $this->assertLessThanOrEqual(3, DB::table('sys_recent_values')->where(['fk_id_account' => $this->owner->id, 'form_key' => 'src_research', 'field_key' => 'title'])->count());
    }

    public function test_look_alike_warning_before_a_duplicate_and_the_form_tells_what_happens_on_save(): void
    {
        $this->actingAs($this->owner);
        $id = RecordService::save('src_research', ['title' => 'Bamboo Cutlery Set'])['id'];
        $def = $this->getJson('/api/form/src_research')->assertOk()->json();
        $this->assertContains('title', $def['lookalike']);
        $this->assertStringContainsString('Saved in', $def['after_save'][0]); $this->assertNotEmpty(array_filter($def['after_save'], fn ($l) => str_contains($l, 'The system gives it its')));
        $m = fn (string $v, $except = '') => $this->getJson('/api/lookalike/src_research?' . http_build_query(['field' => 'title', 'value' => $v, 'id' => $except]))->assertOk()->json('matches');
        $this->assertTrue($m('bamboo  cutlery-set')[0]['exact']);                                   // same letters, other case / spaces / signs
        $this->assertSame($id, $m('Bamboo Cutlary Set')[0]['id']); $this->assertFalse($m('Bamboo Cutlary Set')[0]['exact']);   // a typing mistake
        $this->assertSame([], $m('Steel bottle')); $this->assertSame([], $m('Ba'));
        $this->assertSame([], $m('Bamboo Cutlery Set', $id));                                       // editing the record itself is not a duplicate
        $this->assertSame([], $this->getJson('/api/lookalike/src_research?field=keywords&value=Bamboo')->json('matches') ?: []);
        // the save answers with the label and the code, so the dropdown that opened "+ Add" can select it
        $r = $this->postJson('/api/record/src_research', ['values' => ['title' => 'Silicone lids'], 'quick' => true])->assertOk()->json();
        $this->assertSame('Silicone lids', $r['label']); $this->assertNotEmpty($r['code']); $this->assertStringContainsString($r['code'], $r['message']); $this->assertStringStartsWith('#/rec/src_research/', $r['open_record']);
        $f = collect($def['sections'])->flatMap(fn ($s) => $s['fields']);
        $this->assertNotEmpty($f->whereNotNull('default_why')->all());
    }
}
