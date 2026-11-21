<?php

namespace Tests\Feature;

use App\Services\Guide;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Guidance: list strip, next step / why blocked on a record, playbooks, recent pages and favourites, "I am stuck". */
class GuideTest extends TestCase
{
    public function test_every_list_has_a_guidance_strip_that_says_what_it_is_how_to_add_and_where_else_it_is_created(): void
    {
        $this->actingAs($this->owner); $n = 0;
        foreach (DB::table('sys_forms')->where('f_active', 1)->pluck('form_key') as $key) {
            if (! \App\Services\Registry::exists($key) || ! \App\Services\Registry::table($key)) continue;
            $g = Guide::list($key, $this->owner); $n++;
            $this->assertNotSame('', $g['what'], $key); $this->assertNotSame('', $g['add'], $key); $this->assertNotEmpty($g['tips'], $key);
        }
        $this->assertGreaterThan(100, $n);
        $g = $this->getJson('/api/guide/list/src_research')->assertOk()->json();
        $this->assertStringContainsString('Press New', $g['add']); $this->assertStringContainsString('Needed:', $g['add']); $this->assertStringContainsString('given by the system', $g['add']); $this->assertTrue($g['can_add']);
        // a master that other forms add into names those forms
        $ref = $this->getJson('/api/guide/list/reference_value')->assertOk()->json();
        $this->assertNotEmpty($ref['also']);
        // a role that cannot add is told so, and how to ask
        $wh = $this->makeUser('WAREHOUSE'); $form = collect(DB::table('sys_forms')->where('f_active', 1)->pluck('form_key'))->first(fn ($k) => \App\Services\Registry::table($k) && \App\Services\Perm::has($wh, "form:$k:VIEW") && ! \App\Services\Perm::has($wh, "form:$k:ADD"));
        if ($form) { $x = $this->actingAs($wh)->getJson("/api/guide/list/$form")->assertOk()->json(); $this->assertFalse($x['can_add']); $this->assertStringContainsString('Ask the Owner', $x['add']); }
        $this->actingAs($this->makeUser('VA'))->getJson('/api/guide/list/t_account_bank')->assertStatus(403);
    }

    public function test_a_record_says_its_next_step_and_what_stops_it(): void
    {
        $this->actingAs($this->owner);
        $id = RecordService::save('src_research', ['title' => 'Bamboo cutlery'], null, ['quick' => true])['id'];
        $g = $this->getJson("/api/guide/record/src_research/$id")->assertOk()->json();
        $this->assertContains($g['state'], ['TODO', 'OK']); $this->assertSame([], $g['blocked']);
        $this->assertNotEmpty(array_filter($g['next'], fn ($n) => ($n['url'] ?? '') === "#/research/$id"));                        // its own work screen
        // an open task on the record is the next step; late shows as late; a stuck one is "why can't I continue"
        $t1 = DB::table('tasks')->insertGetId(['fk_id_ent' => 1, 'title' => 'Ask for a sample', 'owner_user_id' => $this->owner->id, 'category_id' => 1, 'priority' => 'HIGH', 'status' => 'TODO', 'created_at' => now(), 'due_at' => date('Y-m-d', strtotime('-2 day')), 'source' => 'MANUAL', 'linked_module' => 'src_research', 'linked_record_id' => $id]);
        $g = $this->getJson("/api/guide/record/src_research/$id")->json();
        $late = collect($g['next'])->first(fn ($n) => str_contains($n['text'], 'Ask for a sample')); $this->assertTrue($late['late']); $this->assertStringStartsWith('Late:', $late['text']); $this->assertSame('TODO', $g['state']);
        $mgr = $this->makeUser('MANAGER');
        $this->postJson("/api/tasks/$t1/stuck", ['type' => 'USER', 'on_id' => $mgr->id, 'text' => 'needs your price list'])->assertOk();
        $g = $this->getJson("/api/guide/record/src_research/$id")->json();
        $this->assertSame('BLOCKED', $g['state']); $this->assertStringContainsString('needs your price list', $g['blocked'][0]['why']);
        // waiting for approval blocks too
        DB::table('sys_approvals')->insert(['fk_id_ent' => 1, 'action_code' => 'EXPENSE', 'entity' => 'src_research', 'entity_id' => $id, 'entity_table' => 'src_research', 'amount_gbp' => 10, 'requested_by' => $this->owner->id, 'requested_at' => now(), 'status' => 'PENDING']);
        $this->assertCount(2, $this->getJson("/api/guide/record/src_research/$id")->json('blocked'));
        // a role that may only look is told so, with the right to ask for
        $wh = $this->makeUser('SOURCING'); $this->actingAs($wh);
        if (\App\Services\Perm::has($wh, 'form:src_research:VIEW') && ! \App\Services\Perm::has($wh, 'form:src_research:EDIT')) $this->assertSame('form:src_research:EDIT', collect($this->getJson("/api/guide/record/src_research/$id")->json('blocked'))->last()['ask']);
        // a switched-off record says so
        $this->actingAs($this->owner); $fill = new \ReflectionMethod(RecordService::class, 'fillNotNull');
        $shop = DB::table('t_branches')->insertGetId($fill->invoke(null, 't_branches', ['fk_id_ent' => 1, 'shop_name' => 'Old shop', 'iso_branch' => 'OS', 'f_active' => 0]));
        $form = RecordService::formOfTable('t_branches');
        $this->assertStringContainsString('switched off', $this->getJson("/api/guide/record/$form/$shop")->assertOk()->json('blocked.0.why'));
    }

    public function test_i_am_stuck_names_what_the_task_waits_on_and_the_person_sees_it(): void
    {
        $va = $this->makeUser('VA'); $mgr = $this->makeUser('MANAGER'); $this->actingAs($va);
        $t = DB::table('tasks')->insertGetId(['fk_id_ent' => 1, 'title' => 'List the product', 'owner_user_id' => $va->id, 'category_id' => 1, 'priority' => 'NORMAL', 'status' => 'DOING', 'created_at' => now(), 'due_at' => date('Y-m-d'), 'source' => 'MANUAL']);
        $this->postJson("/api/tasks/$t/stuck", ['type' => 'USER', 'on_id' => $va->id])->assertStatus(422);                                  // not the owner himself
        $this->postJson("/api/tasks/$t/stuck", ['type' => 'SUPPLIER'])->assertStatus(422)->assertJsonPath('message', 'Refused: name the supplier it is stuck on.');
        $this->postJson("/api/tasks/$t/stuck", ['type' => 'NOPE', 'text' => 'x'])->assertStatus(422);
        $this->postJson("/api/tasks/$t/stuck", ['type' => 'USER', 'on_id' => $mgr->id, 'text' => 'photos missing'])->assertOk();
        $row = DB::table('tasks')->where('id', $t)->first();
        $this->assertSame('STUCK', $row->status); $this->assertSame('USER', $row->stuck_on_type); $this->assertSame($mgr->id, (int) $row->stuck_on_id); $this->assertNotNull($row->stuck_since);
        $this->assertStringStartsWith('STUCK:', DB::table('task_comments')->where('task_id', $t)->value('text'));                           // a comment on the web too
        // the manager sees it under "Stuck on me"
        $this->actingAs($mgr); $d = $this->getJson('/api/tasks?scope=stuck_on_me')->assertOk()->json();
        $this->assertSame(1, $d['stuck_on_me']); $this->assertSame([$t], array_column($d['tasks'], 'id'));
        // another person cannot mark someone's task; starting again clears the four stuck fields
        $this->actingAs($this->makeUser('WAREHOUSE'))->postJson("/api/tasks/$t/stuck", ['type' => 'OTHER', 'text' => 'x'])->assertStatus(403);
        $this->actingAs($va)->postJson("/api/tasks/$t/status", ['status' => 'DOING'])->assertOk();
        $row = DB::table('tasks')->where('id', $t)->first();
        $this->assertNull($row->stuck_on_type); $this->assertNull($row->stuck_on_id); $this->assertNull($row->stuck_on_text); $this->assertNull($row->stuck_since);
        $this->postJson("/api/tasks/$t/stuck", ['type' => 'OTHER', 'text' => 'Waiting for the courier'])->assertOk();
        DB::table('task_evidence')->insert(['task_id' => $t, 'type' => 'PHOTO', 'uploaded_by' => $va->id, 'via' => 'APP', 'created_at' => now()]);      // the Done gate needs proof (R-G_CORE-5)
        $this->postJson("/api/tasks/$t/status", ['status' => 'DONE'])->assertOk();
        $this->assertNull(DB::table('tasks')->where('id', $t)->value('stuck_on_type'));                                                      // cleared on Done too
        $this->postJson("/api/tasks/$t/stuck", ['type' => 'OTHER', 'text' => 'x'])->assertStatus(422);                                      // a closed task
    }

    public function test_step_playbooks_show_where_written_and_the_admin_sees_what_is_missing(): void
    {
        $this->actingAs($this->owner);
        $this->assertSame([], $this->getJson('/api/playbooks/SOURCING_CYCLE')->assertOk()->json('playbooks'));
        RecordService::save('playbook_step', ['workflow_code' => 'SOURCING_CYCLE', 'step_code' => 'qc', 'title' => 'Quality check', 'checklist_text' => "Open the carton\n\nCount 10 units\nPhoto of every fault", 'video_url' => 'https://drive.google.com/file/d/abc', 'f_active' => 1]);
        $p = $this->getJson('/api/playbooks/sourcing_cycle')->assertOk()->json('playbooks');
        $this->assertSame(['qc'], array_keys($p)); $this->assertSame(['Open the carton', 'Count 10 units', 'Photo of every fault'], $p['qc']['checklist']); $this->assertSame('https://drive.google.com/file/d/abc', $p['qc']['video']);
        $rows = $this->getJson('/api/playbook-coverage')->assertOk()->json('rows');
        $this->assertGreaterThan(30, count($rows));
        $this->assertNotNull(collect($rows)->first(fn ($r) => $r['workflow'] === 'SOURCING_CYCLE' && $r['step'] === 'qc')['playbook']);
        $this->assertNull(collect($rows)->first(fn ($r) => $r['workflow'] === 'SOURCING_CYCLE' && $r['step'] === 'rfq')['playbook']);
        $this->assertSame(count($rows) - 1, count(array_filter($rows, fn ($r) => ! $r['playbook'])));
        $va = $this->makeUser('VA'); $this->actingAs($va);
        $this->assertArrayHasKey('qc', $this->getJson('/api/playbooks/SOURCING_CYCLE')->assertOk()->json('playbooks'));                       // everyone reads a playbook
        if (! \App\Services\Perm::has($va, 'form:playbook_step:VIEW')) $this->getJson('/api/playbook-coverage')->assertStatus(403);
    }

    public function test_recent_pages_keep_the_last_eight_and_favourites_are_per_person(): void
    {
        $this->actingAs($this->owner);
        for ($i = 1; $i <= 10; $i++) $this->postJson('/api/nav', ['title' => "Page $i", 'url' => "#/list/form_$i"])->assertOk();
        $this->postJson('/api/nav', ['title' => 'Page 3', 'url' => '#/list/form_3'])->assertOk();                                             // opened again: moves to the top, not twice
        $n = $this->getJson('/api/nav')->assertOk()->json();
        $this->assertCount(8, $n['recent']); $this->assertSame('Page 3', $n['recent'][0]['title']); $this->assertSame(1, count(array_filter($n['recent'], fn ($r) => $r['title'] === 'Page 3')));
        $this->postJson('/api/nav', ['title' => 'Bank', 'url' => '#/bank', 'favourite' => true])->assertOk();
        $this->assertSame([['title' => 'Bank', 'url' => '#/bank']], $this->getJson('/api/nav')->json('favourites'));
        $this->postJson('/api/nav', ['title' => 'x', 'url' => 'javascript:alert(1)', 'favourite' => true])->assertStatus(422);
        $this->postJson('/api/nav', ['title' => 'Bank', 'url' => '#/bank', 'favourite' => false])->assertOk();
        $this->assertSame([], $this->getJson('/api/nav')->json('favourites'));
        $this->actingAs($this->makeUser('MANAGER')); $this->assertSame([], $this->getJson('/api/nav')->json('recent'));
    }
}
