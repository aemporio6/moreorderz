<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HttpTest extends TestCase
{
    public function test_guest_is_sent_to_login_and_api_says_401(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->getJson('/api/boot')->assertStatus(401);
    }

    public function test_login_checks_the_hashed_password_and_logs_it(): void
    {
        $this->post('/login', ['login' => 'owner@test', 'password' => 'wrong'])->assertSessionHasErrors('login');
        $this->assertSame(1, DB::table('sys_audit_log')->where('action', 'LOGIN_FAIL')->count());
        $this->post('/login', ['login' => 'owner@test', 'password' => 'secret-password'])->assertRedirect('/');
        $this->assertAuthenticatedAs($this->owner);
        $this->assertSame(1, DB::table('sys_audit_log')->where('action', 'LOGIN')->count());
    }

    public function test_old_plain_password_column_can_never_sign_in(): void
    {
        DB::table('t_users')->insert(['login_id' => 'old@test', 'pass' => 'oldplain', 'f_active' => 1, 'role_code' => 'VA']);
        $this->post('/login', ['login' => 'old@test', 'password' => 'oldplain'])->assertSessionHasErrors('login');
        $this->assertGuest();
    }

    public function test_login_locks_after_five_wrong_tries(): void
    {
        for ($i = 0; $i < 5; $i++) $this->post('/login', ['login' => 'owner@test', 'password' => 'bad' . $i]);
        $r = $this->post('/login', ['login' => 'owner@test', 'password' => 'secret-password']);
        $r->assertSessionHasErrors('login');
        $this->assertGuest();
    }

    public function test_boot_gives_menu_in_work_order_and_the_create_catalogue(): void
    {
        $b = $this->actingAs($this->owner)->getJson('/api/boot')->assertOk()->json();
        $this->assertSame('OWNER', $b['user']['role']);
        $names = array_column($b['menu'], 'name');
        $this->assertSame(['Home', 'Research', 'Buying'], array_slice($names, 0, 3));
        $this->assertSame('Setup', end($names));
        $this->assertCount(17, $b['menu']);
        $this->assertGreaterThanOrEqual(140, count($b['create']));
        $this->assertContains('src_research', array_column($b['create'], 'key'));
    }

    public function test_role_sees_only_its_menu_and_cannot_switch_company(): void
    {
        $wh = $this->makeUser('WAREHOUSE');
        $b = $this->actingAs($wh)->getJson('/api/boot')->json();
        $all = $this->actingAs($this->owner)->getJson('/api/boot')->json();
        $count = fn ($m) => array_sum(array_map(fn ($s) => count($s['items']), $m));
        $this->assertLessThan($count($all['menu']), $count($b['menu']));
        $this->assertCount(1, $b['companies']);
        DB::table('t_enterprise')->insert(['id' => 2, 'fk_id_module' => 0, 'iso_ent' => 'TS2', 'ent_name' => 'Second Ltd', 'ent_address' => '-', 'f_active' => 1, 'ent_short' => 'TS2']);
        $this->actingAs($wh)->postJson('/api/company', ['ent' => 2])->assertStatus(403);
        $this->actingAs($this->owner)->postJson('/api/company', ['ent' => 2])->assertOk();
    }

    public function test_form_list_save_round_trip_over_http(): void
    {
        $this->actingAs($this->owner);
        $def = $this->getJson('/api/form/src_research')->assertOk()->json();
        $this->assertSame('src_research', $def['table']);
        $bad = $this->postJson('/api/record/src_research', ['values' => [], 'quick' => true])->assertStatus(422)->json();
        $this->assertNotEmpty($bad['errors']);
        $values = [];
        foreach ($def['sections'] as $s) foreach ($s['fields'] as $f) {
            if (! $f['required'] || $f['readonly']) continue;
            $opts = $f['has_options'] ? $this->getJson("/api/options/src_research/{$f['name']}")->json('options') : [];
            $values[$f['name']] = $f['has_options'] ? ($opts[0][0] ?? '1') : match ($f['type']) { 'number', 'money' => 5, 'date' => '2026-08-01', 'link' => 'https://example.com/x', 'check' => 1, default => 'Silicone dog bowl' };
        }
        $r = $this->postJson('/api/record/src_research', ['values' => $values])->assertOk()->json();
        $this->assertTrue($r['created']);
        $list = $this->getJson('/api/list/src_research')->assertOk()->json();
        $this->assertSame(1, $list['total']);
        $this->getJson("/api/record/src_research/{$r['id']}")->assertOk();
    }

    public function test_every_create_entry_opens_its_form_definition(): void
    {
        $this->actingAs($this->owner);
        $bad = [];
        foreach ($this->getJson('/api/boot')->json('create') as $c) {
            $res = $this->getJson('/api/form/' . $c['key']);
            if ($res->status() !== 200 || ! count($res->json('sections'))) $bad[] = $c['key'] . ':' . $res->status();
        }
        $this->assertSame([], $bad);
    }

    public function test_every_data_form_lists_without_error(): void
    {
        $this->actingAs($this->owner);
        $bad = [];
        foreach (DB::table('sys_registry')->distinct()->pluck('form_key') as $k) {
            if (! \App\Services\Registry::table($k) || ! \App\Services\Registry::columns(\App\Services\Registry::table($k))) continue;
            $res = $this->getJson('/api/list/' . $k);
            if ($res->status() !== 200) $bad[] = $k . ':' . $res->status() . ':' . mb_substr((string) $res->json('message'), 0, 80);
        }
        $this->assertSame([], $bad);
    }

    public function test_saved_record_is_found_by_search_and_has_a_page_with_history_and_links(): void
    {
        $this->actingAs($this->owner);
        $mp = DB::table('cat_marketplaces')->insertGetId(['fk_id_ent' => 1] + $this->notNull('cat_marketplaces'));
        $id = \App\Services\RecordService::save('mp_profile', ['code' => 'TIKTOK UK', 'name' => 'TikTok Shop UK', 'fk_id_marketplace' => $mp] + $this->required('mp_profile'))['id'];
        \App\Services\RecordService::save('mp_profile', ['name' => 'TikTok Shop United Kingdom'], $id);
        $hits = $this->getJson('/api/search?q=tiktok')->assertOk()->json('results');
        $this->assertSame("#/rec/mp_profile/$id", $hits[0]['url']);
        $this->assertSame([], $this->getJson('/api/search?q=t')->json('results'), 'one letter searches nothing');
        $page = $this->getJson("/api/page/mp_profile/$id")->assertOk()->json();
        $this->assertSame('TikTok Shop United Kingdom', $page['title']);
        $this->assertSame(['UPDATE', 'INSERT'], array_column($page['history'], 'action'));
        $this->assertSame(['TikTok Shop UK', 'TikTok Shop United Kingdom'], $page['history'][0]['changes']['name']);
        $this->assertNotEmpty($page['parents'], 'marketplace is shown as a link');
    }

    public function test_search_never_crosses_companies(): void
    {
        DB::table('sys_search_index')->insert(['fk_id_ent' => 2, 'entity' => 'x', 'entity_id' => 1, 'title' => 'Secret supplier of company two', 'search_text' => 'secret', 'url' => '#/rec/x/1', 'updated_at' => now()] + $this->notNull('sys_search_index'));
        $this->assertSame([], $this->actingAs($this->owner)->getJson('/api/search?q=secret')->json('results'));
    }

    public function test_export_is_for_allowed_roles_is_logged_and_blocks_formula_cells(): void
    {
        $mp = DB::table('cat_marketplaces')->insertGetId(['fk_id_ent' => 1] + $this->notNull('cat_marketplaces'));
        \App\Services\RecordService::save('mp_profile', ['code' => 'X1', 'name' => '=HYPERLINK("http://bad")', 'fk_id_marketplace' => $mp] + $this->required('mp_profile'));
        $this->actingAs($this->makeUser('VA'))->get('/api/export/mp_profile')->assertStatus(403);
        $csv = $this->actingAs($this->owner)->get('/api/export/mp_profile')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertSame(1, DB::table('sys_audit_log')->where('action', 'EXPORT')->count());
    }

    public function test_tasks_screen_shows_mine_rule_tasks_cannot_be_ticked_by_hand_and_manual_ones_can(): void
    {
        $va = $this->makeUser('VA');
        $rule = \App\Services\TaskEngine::open('SAL_ZERO', [77], 'Sale line with zero price', ['owner_user_id' => $va->id]);
        $manual = DB::table('tasks')->insertGetId(['fk_id_ent' => 1, 'title' => 'Call the forwarder', 'category_id' => 1, 'owner_user_id' => $va->id, 'priority' => 'NORMAL', 'status' => 'TODO', 'created_at' => now(), 'due_at' => '2026-01-01', 'source' => 'MANUAL']);
        $d = $this->actingAs($va)->getJson('/api/tasks')->assertOk()->json();
        $this->assertSame(2, $d['counts']['open']);
        $this->assertSame(1, $d['counts']['late']);
        $this->postJson("/api/tasks/$rule/status", ['status' => 'DONE'])->assertStatus(422);
        $this->postJson("/api/tasks/$manual/status", ['status' => 'STUCK'])->assertStatus(422);
        $this->postJson("/api/tasks/$manual/status", ['status' => 'DONE'])->assertOk();
        $this->assertSame('DONE', DB::table('tasks')->where('id', $manual)->value('status'));
        $other = $this->makeUser('VA');
        $this->actingAs($other)->postJson("/api/tasks/$rule/status", ['status' => 'DOING'])->assertStatus(403);
    }

    private function notNull(string $table): array
    {
        $m = new \ReflectionMethod(\App\Services\RecordService::class, 'fillNotNull');
        return $m->invoke(null, $table, []);
    }

    private function required(string $form): array
    {
        $out = [];
        foreach (\App\Services\Registry::fields($form) as $f) {
            if (! $f['required'] || ! $f['column_name']) continue;
            $out[$f['field_name']] = match ($f['type']) { 'number', 'money' => 1, 'date' => '2026-08-01', 'check' => 1, 'email' => 'a@b.co', 'link' => 'https://example.com',
                'select', 'multiselect' => (\App\Services\Registry::options($f, 1)[0][0] ?? '1'), default => 'X' };
        }
        return $out;
    }
}
