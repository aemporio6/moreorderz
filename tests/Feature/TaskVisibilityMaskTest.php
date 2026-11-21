<?php

namespace Tests\Feature;

use App\Services\HomeCards;
use App\Services\Mask;
use App\Services\RecordService;
use App\Services\TaskLists;
use App\Services\TaskVisibility;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_G_CORE R-G_CORE-19 who sees a task, R-G_CORE-62 one Mask service, R-G_CORE-36 Home "Needs action", R-G_CORE-34 Import Center type TASKS – spec examples. */
class TaskVisibilityMaskTest extends TestCase
{
    private function task(array $x): int
    {
        return DB::table('tasks')->insertGetId(RecordService::fillNotNull('tasks', $x + ['fk_id_ent' => 1, 'title' => 'Job', 'priority' => 'NORMAL', 'status' => 'DOING', 'created_at' => now(), 'last_update_at' => now(),
            'due_at' => date('Y-m-d', strtotime('+3 days')), 'source' => 'MANUAL', 'due_changes' => 0, 'evidence_required' => 0, 'flags' => '', 'is_confidential' => 0]));
    }

    public function test_r19_confidential_task_hidden_from_a_manager_and_a_helper_va_sees_only_his(): void
    {
        $partner = $this->makeUser('PARTNER'); $mgr = $this->makeUser('MANAGER'); $ali = $this->makeUser('VA'); $helper = $this->makeUser('VA');
        $conf = $this->task(['owner_user_id' => $ali->id, 'is_confidential' => 1, 'title' => 'Salary review']);
        $open = $this->task(['owner_user_id' => $ali->id, 'title' => 'Count A1']);
        $other = $this->task(['owner_user_id' => $mgr->id, 'title' => 'Not his']);
        DB::table('task_helpers')->insert([['task_id' => $conf, 'user_id' => $helper->id], ['task_id' => $open, 'user_id' => $helper->id]]);
        $this->actingAs($mgr)->getJson("/api/tasks/$conf/page")->assertStatus(404);
        $this->assertNotContains($conf, array_column(TaskLists::all($mgr, 'all', [])['tasks'], 'id'), 'not in the Manager\'s list');
        $this->assertSame(0, collect(TaskLists::all($mgr, 'all', ['q' => 'Salary review'])['chips'])->firstWhere('key', 'all')['count'], 'the count excludes it');
        $this->assertTrue(TaskVisibility::canSee(DB::table('tasks')->find($conf), $partner)); $this->assertTrue(TaskVisibility::canSee(DB::table('tasks')->find($conf), $ali));
        $this->assertFalse(TaskVisibility::canSee(DB::table('tasks')->find($conf), $helper), 'not even a helper');
        $seen = array_column(TaskLists::all($helper, 'all', [])['tasks'], 'id');
        $this->assertContains($open, $seen); $this->assertNotContains($conf, $seen); $this->assertNotContains($other, $seen, 'a VA helper sees only tasks he is linked to');
        [, $csv] = TaskLists::export($mgr, 'all', []); $this->assertStringNotContainsString('Salary review', $csv, 'export follows the rule');
        // the cover of an absent owner sees his tasks (new)
        $cover = $this->makeUser('VA'); $accA = DB::table('t_account')->insertGetId(RecordService::fillNotNull('t_account', ['fk_id_ent' => 1, 'account_code' => 'VZ' . random_int(1000, 9999), 'first_name' => 'Ali', 'f_active' => 1]));
        $accC = DB::table('t_account')->insertGetId(RecordService::fillNotNull('t_account', ['fk_id_ent' => 1, 'account_code' => 'VZ' . random_int(1000, 9999), 'first_name' => 'Cov', 'f_active' => 1]));
        DB::table('t_users')->where('id', $ali->id)->update(['fk_id_account' => $accA]); DB::table('t_users')->where('id', $cover->id)->update(['fk_id_account' => $accC]);
        DB::table('user_absence')->insert(['user_id' => $accA, 'from_date' => date('Y-m-d', strtotime('-1 day')), 'to_date' => date('Y-m-d', strtotime('+2 days')), 'cover_user_id' => $accC, 'created_at' => now()]);
        $c = array_column(TaskLists::all($cover, 'all', [])['tasks'], 'id');
        $this->assertContains($open, $c); $this->assertNotContains($conf, $c, 'never a confidential one');
        $this->assertTrue(\App\Services\TaskFlow::mayWork(DB::table('tasks')->find($open), $cover), 'the cover may work the task');
        $this->assertFalse(\App\Services\TaskFlow::mayWork(DB::table('tasks')->find($conf), $mgr), 'a Manager cannot work a confidential task');
    }

    public function test_r62_one_mask_service(): void
    {
        $va = $this->makeUser('VA'); $p = $this->makeUser('PARTNER');
        $this->assertSame('Pay £••• today', Mask::text('Pay £123.45 today', $va));
        $this->assertSame('Pay £123.45 today', Mask::text('Pay £123.45 today', $p));
        // a registry field the person may not see: not in the form answer, "(hidden)" in history
        $f = DB::table('sys_registry')->where('visible_to', 'OWNER')->where('secret', 0)->whereNotNull('column_name')->first();
        $this->assertNotNull($f);
        $this->actingAs($va);
        $this->assertContains($f->column_name, Mask::hidden($f->form_key, $va));
        $this->assertArrayNotHasKey($f->column_name, Mask::row($f->form_key, [$f->column_name => 'x', 'id' => 1], $va));
        $this->assertSame([$f->column_name => ['(hidden)', '(hidden)']], Mask::history($f->form_key, [$f->column_name => ['a', 'b']], $va));
        // task answers: no amount key for a VA; CSV 17 columns, a Partner 18
        $t = $this->task(['owner_user_id' => $va->id, 'amount_gbp' => 99]);
        $this->assertArrayNotHasKey('amount_gbp', $this->actingAs($va)->getJson("/api/tasks/$t/page")->json('task'));
        $this->assertSame(99.0, (float) $this->actingAs($p)->getJson("/api/tasks/$t/page")->json('task.amount_gbp'));
        $this->assertCount(17, str_getcsv(explode("\n", substr(TaskLists::export($va, 'all', [])[1], 3))[0]));
    }

    public function test_r36_home_needs_action_and_r34_import_center_type(): void
    {
        $va = $this->makeUser('VA');
        $this->task(['owner_user_id' => $va->id, 'due_at' => date('Y-m-d', strtotime('-2 days'))]);
        $this->task(['owner_user_id' => $va->id, 'due_at' => date('Y-m-d')]);
        $this->actingAs($va);
        $card = collect(HomeCards::for($va, 1))->firstWhere('label', 'My tasks');
        $this->assertSame([2, 'needs action: 1 late · 1 due today · 0 waiting on me'], [$card['value'], $card['foot']]);
        $this->assertSame('TaskImport::run', DB::table('imp_report_types')->where('code', 'TASKS')->value('handler'));
    }
}
