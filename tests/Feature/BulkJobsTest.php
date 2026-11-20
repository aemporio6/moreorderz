<?php

namespace Tests\Feature;

use App\Services\Notify;
use App\Services\Perm;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Bulk actions (preview → apply → Undo), delete that refuses when used, inline edit through the same save, and the bell. */
class BulkJobsTest extends TestCase
{
    private function ideas(): array
    {
        $this->actingAs($this->owner); $ids = [];
        foreach (['A', 'B', 'C', 'D', 'E'] as $x) $ids[] = RecordService::save('src_research', ['title' => "Idea $x", 'target_country' => $x === 'E' ? 'US' : 'GB'])['id'];
        return $ids;
    }

    public function test_bulk_change_shows_old_and_new_then_applies_and_undo_puts_everything_back(): void
    {
        $ids = $this->ideas();
        $this->postJson('/api/bulk/src_research', ['action' => 'SET', 'ids' => [], 'field' => 'target_country', 'value' => 'US'])->assertStatus(422)->assertJsonPath('message', 'Please select at least one checkbox.');
        $p = $this->postJson('/api/bulk/src_research', ['action' => 'SET', 'ids' => $ids, 'field' => 'target_country', 'value' => 'US'])->assertOk()->json();
        $this->assertSame('Update selected row(s)', $p['title']); $this->assertSame(4, $p['will_change']); $this->assertSame(1, $p['skipped']);
        $this->assertSame('System will change 4 selected row(s), 1 skipped.', $p['message']);
        $this->assertSame(['GB', 'US'], [$p['rows'][0]['old'], $p['rows'][0]['new']]); $this->assertSame('Already has this value.', $p['rows'][4]['skip']);
        $this->assertSame(4, DB::table('src_research')->whereIn('id', $ids)->where('target_country', 'GB')->count());                         // a preview changes nothing
        $r = $this->postJson('/api/bulk/src_research', ['action' => 'SET', 'ids' => $ids, 'field' => 'target_country', 'value' => 'US', 'apply' => true])->assertOk()->json();
        $this->assertSame(4, $r['changed']); $this->assertSame('Updated 4 rows · 1 skipped', $r['message']);
        $this->assertSame(5, DB::table('src_research')->whereIn('id', $ids)->where('target_country', 'US')->count());
        $job = DB::table('bulk_jobs')->where('id', $r['job'])->first();
        $this->assertSame('APPLIED', $job->status); $this->assertSame(4, (int) $job->row_count); $this->assertSame('src_research', $job->list_key);
        $this->assertGreaterThanOrEqual(4, DB::table('sys_audit_log')->where('table_name', 'src_research')->where('action', 'UPDATE')->whereIn('record_id', $ids)->count());   // same history as the form
        // someone changes one row again → Undo leaves that one alone
        RecordService::save('src_research', ['target_country' => 'DE'], $ids[1]);
        $u = $this->postJson("/api/bulk-jobs/{$r['job']}/undo")->assertOk()->json();
        $this->assertSame(3, $u['back']); $this->assertSame(1, $u['kept']);
        $this->assertSame(['GB', 'DE', 'GB', 'GB', 'US'], DB::table('src_research')->whereIn('id', $ids)->orderBy('id')->pluck('target_country')->all());
        $this->postJson("/api/bulk-jobs/{$r['job']}/undo")->assertStatus(422)->assertJsonPath('message', 'This was already undone.');
        $this->assertSame('UNDONE', $this->getJson('/api/bulk-jobs')->assertOk()->json('jobs.0.status'));
    }

    public function test_bulk_checks_the_value_the_field_and_the_rights(): void
    {
        $ids = $this->ideas();
        $this->postJson('/api/bulk/src_research', ['action' => 'SET', 'ids' => $ids, 'field' => 'research_code', 'value' => 'X'])->assertStatus(422);        // numbered by the system
        $this->postJson('/api/bulk/src_research', ['action' => 'SET', 'ids' => $ids, 'field' => 'title', 'value' => ''])->assertStatus(422);                 // compulsory
        $this->postJson('/api/bulk/src_research', ['action' => 'SET', 'ids' => $ids, 'field' => 'status', 'value' => 'NOT_A_STATUS'])->assertStatus(422);
        $this->postJson('/api/bulk/src_research', ['action' => 'SET', 'ids' => $ids, 'field' => 'est_monthly_units', 'value' => 'abc'])->assertStatus(422);
        $this->postJson('/api/bulk/src_research', ['action' => 'SET', 'ids' => range(1, 501), 'field' => 'target_country', 'value' => 'US'])->assertStatus(422);
        $a = $this->getJson('/api/bulk/src_research')->assertOk()->json();
        $names = array_column($a['fields'], 'name'); $this->assertContains('target_country', $names); $this->assertNotContains('research_code', $names); $this->assertTrue($a['can_edit']);
        // a field made "see only" for a role cannot be changed in bulk by that role; a role without the right cannot at all
        $mgr = $this->makeUser('MANAGER'); Perm::setField('MANAGER', null, 'src_research', 'target_country', 'READONLY', $this->owner->id);
        $this->actingAs($mgr); $this->assertNotContains('target_country', array_column($this->getJson('/api/bulk/src_research')->json('fields'), 'name'));
        $this->postJson('/api/bulk/src_research', ['action' => 'SET', 'ids' => $ids, 'field' => 'target_country', 'value' => 'US', 'apply' => true])->assertStatus(422);
        $va = $this->makeUser('WAREHOUSE'); $this->actingAs($va);
        if (! Perm::has($va, 'form:src_research:EDIT')) $this->postJson('/api/bulk/src_research', ['action' => 'SET', 'ids' => $ids, 'field' => 'keywords', 'value' => 'x', 'apply' => true])->assertStatus(403);
        // another person cannot undo someone's job (the Owner and a Manager can)
        $r = $this->actingAs($this->owner)->postJson('/api/bulk/src_research', ['action' => 'SET', 'ids' => $ids, 'field' => 'keywords', 'value' => 'bulk', 'apply' => true])->json();
        $this->actingAs($va)->postJson("/api/bulk-jobs/{$r['job']}/undo")->assertStatus(403);
        $this->actingAs($mgr)->postJson("/api/bulk-jobs/{$r['job']}/undo")->assertOk();
    }

    public function test_delete_switches_off_refuses_what_is_used_and_can_be_undone(): void
    {
        $this->actingAs($this->owner); $fill = new \ReflectionMethod(RecordService::class, 'fillNotNull');
        $form = RecordService::formOfTable('t_carriers'); $this->assertNotNull($form);
        $used = DB::table('t_carriers')->insertGetId($fill->invoke(null, 't_carriers', ['fk_id_ent' => 1, 'carrier_name' => 'Used Mail', 'f_active' => 1]));
        $free = DB::table('t_carriers')->insertGetId($fill->invoke(null, 't_carriers', ['fk_id_ent' => 1, 'carrier_name' => 'Free Mail', 'f_active' => 1]));
        // something that points at the carrier through a registry dropdown
        $ref = DB::table('sys_registry')->where('source', 'like', 'table:t_carriers%')->whereNotNull('column_name')->get()->first(fn ($r) => ($t = \App\Services\Registry::baseTable($r->table_name)) && $t !== 't_carriers' && \App\Services\Registry::hasColumn($t, $r->column_name));
        $this->assertNotNull($ref); $ct = \App\Services\Registry::baseTable($ref->table_name);
        DB::table($ct)->insert($fill->invoke(null, $ct, [$ref->column_name => $used] + (\App\Services\Registry::hasColumn($ct, 'fk_id_ent') ? ['fk_id_ent' => 1] : [])));
        $p = $this->postJson("/api/bulk/$form", ['action' => 'DELETE', 'ids' => [$used, $free]])->assertOk()->json();
        $this->assertSame('Delete selected row(s)', $p['title']); $this->assertSame(1, $p['will_change']);
        $this->assertStringStartsWith('Not deleted – used by 1 ', $p['rows'][0]['skip']); $this->assertNull($p['rows'][1]['skip']);
        $r = $this->postJson("/api/bulk/$form", ['action' => 'DELETE', 'ids' => [$used, $free], 'apply' => true])->assertOk()->json();
        $this->assertSame('Deleted 1 row · 1 skipped', $r['message']);
        $this->assertSame([1, 0], [(int) DB::table('t_carriers')->where('id', $used)->value('f_active'), (int) DB::table('t_carriers')->where('id', $free)->value('f_active')]);
        $this->assertSame(1, DB::table('t_carriers')->where('id', $free)->count());                                                         // nothing is erased
        $this->assertSame(1, DB::table('sys_audit_log')->where('table_name', 't_carriers')->where('record_id', $free)->where('action', 'DELETE')->count());
        $this->postJson("/api/bulk-jobs/{$r['job']}/undo")->assertOk()->assertJsonPath('back', 1);
        $this->assertSame(1, (int) DB::table('t_carriers')->where('id', $free)->value('f_active'));
        // delete again, then Restore from the list
        $this->postJson("/api/bulk/$form", ['action' => 'DELETE', 'ids' => [$free], 'apply' => true])->assertOk();
        $this->assertSame('Is not deleted.', $this->postJson("/api/bulk/$form", ['action' => 'RESTORE', 'ids' => [$used]])->json('rows.0.skip'));
        $this->postJson("/api/bulk/$form", ['action' => 'RESTORE', 'ids' => [$free], 'apply' => true])->assertOk()->assertJsonPath('changed', 1);
        $this->assertSame(1, (int) DB::table('t_carriers')->where('id', $free)->value('f_active'));
        // only a role with the delete right
        $sv = $this->makeUser('SOURCING'); $this->actingAs($sv);
        if (! Perm::has($sv, "form:$form:DELETE")) $this->postJson("/api/bulk/$form", ['action' => 'DELETE', 'ids' => [$free], 'apply' => true])->assertStatus(403);
    }

    public function test_inline_edit_of_one_cell_goes_through_the_same_save_as_the_form(): void
    {
        $ids = $this->ideas();
        $this->postJson("/api/record/src_research/{$ids[0]}", ['values' => ['est_monthly_units' => 250]])->assertOk();
        $this->assertSame(250, (int) DB::table('src_research')->where('id', $ids[0])->value('est_monthly_units'));
        $this->assertSame('Idea A', DB::table('src_research')->where('id', $ids[0])->value('title'));                                       // the other fields stay
        $this->postJson("/api/record/src_research/{$ids[0]}", ['values' => ['title' => '']])->assertStatus(422);                             // same validation
        $h = DB::table('sys_audit_log')->where('table_name', 'src_research')->where('record_id', $ids[0])->where('action', 'UPDATE')->orderByDesc('id')->value('changes_json');
        $this->assertStringContainsString('est_monthly_units', (string) $h);
    }

    public function test_the_bell_counts_unread_and_open_tasks_groups_messages_and_marks_them_read(): void
    {
        $va = $this->makeUser('VA'); $mgr = $this->makeUser('MANAGER');
        // a refused person asks for a right → the Owner's bell; the Owner grants → the asker's bell
        $this->actingAs($va); $req = $this->postJson('/api/permissions/request', ['code' => 'stock.SEE'])->assertOk()->json('id');
        $this->assertSame([], $this->getJson('/api/bell')->assertOk()->json('items'));
        $this->actingAs($this->owner); $b = $this->getJson('/api/bell')->json();
        $this->assertSame(1, $b['unread']); $this->assertSame('Rights', $b['items'][0]['group']); $this->assertStringContainsString('asks for', $b['items'][0]['text']);
        $this->postJson("/api/permissions/requests/$req", ['grant' => true])->assertOk();
        $this->assertStringStartsWith('Granted:', $this->actingAs($va)->getJson('/api/bell')->json('items.0.text'));
        // a task stuck on a person rings his bell; his open tasks count too
        $t = DB::table('tasks')->insertGetId(['fk_id_ent' => 1, 'title' => 'List the product', 'category_id' => 1, 'owner_user_id' => $va->id, 'priority' => 'NORMAL', 'status' => 'DOING', 'created_at' => now(), 'due_at' => date('Y-m-d'), 'source' => 'MANUAL']);
        $this->postJson("/api/tasks/$t/stuck", ['type' => 'USER', 'on_id' => $mgr->id, 'text' => 'photos'])->assertOk();
        DB::table('tasks')->insert(['fk_id_ent' => 1, 'title' => 'Own task', 'category_id' => 1, 'owner_user_id' => $mgr->id, 'priority' => 'NORMAL', 'status' => 'TODO', 'created_at' => now(), 'due_at' => date('Y-m-d'), 'source' => 'MANUAL']);
        $this->actingAs($mgr); $b = $this->getJson('/api/bell')->json();
        $this->assertSame(1, $b['unread']); $this->assertSame(1, $b['open_tasks']); $this->assertSame('2', $b['badge']); $this->assertSame('Tasks', $b['items'][0]['group']); $this->assertSame('#/tasks?scope=stuck_on_me', $b['items'][0]['url']);
        $b = $this->postJson('/api/bell/read', ['id' => $b['items'][0]['id']])->assertOk()->json();
        $this->assertSame(0, $b['unread']); $this->assertSame('1', $b['badge']); $this->assertFalse($b['items'][0]['unread']);
        // 99+ cap, latest 10, mark all read; nobody reads another person's bell
        for ($i = 0; $i < 120; $i++) Notify::push($mgr->id, 'FINANCE', "Message $i", '#/money');
        $b = $this->getJson('/api/bell')->json(); $this->assertSame('99+', $b['badge']); $this->assertCount(10, $b['items']); $this->assertSame('Message 119', $b['items'][0]['text']);
        $this->assertSame(0, $this->postJson('/api/bell/read', [])->json('unread'));
        $this->assertSame(0, $this->actingAs($this->makeUser('FINANCE'))->getJson('/api/bell')->json('unread'));
    }
}
