<?php

namespace Tests\Feature;

use App\Services\AlertRules;
use App\Services\RecordService;
use App\Services\TaskActions;
use App\Services\TaskCreate;
use App\Services\TaskFlow;
use App\Services\TaskScore;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_G_CORE R-G_CORE-10 acknowledge, 11 dependencies, 12 subtasks, 13 due-date moves, 14 reassign, 15 cancel + undo + hide evidence – the spec examples. */
class TaskActionsTest extends TestCase
{
    private function task(array $x): int
    {
        return DB::table('tasks')->insertGetId(RecordService::fillNotNull('tasks', $x + ['fk_id_ent' => 1, 'title' => 'Sweep shelves', 'priority' => 'NORMAL', 'status' => 'TODO', 'created_at' => now(), 'due_at' => date('Y-m-d', strtotime('+3 days')) . ' 23:59:59', 'source' => 'MANUAL', 'due_changes' => 0, 'evidence_required' => 0]));
    }
    private function refused(callable $fn, string $text): void
    {
        try { $fn(); $this->fail("expected: $text"); } catch (\InvalidArgumentException $e) { $this->assertSame($text, $e->getMessage()); }
    }

    public function test_r10_acknowledge_owner_only_once_and_reassign_clears_it(): void
    {
        $va = $this->makeUser('VA'); $helper = $this->makeUser('VA'); $mgr = $this->makeUser('MANAGER');
        $t = $this->task(['owner_user_id' => $va->id, 'assigned_by_user_id' => $mgr->id, 'due_at' => '2030-11-08 23:59:59']);
        $ref = TaskFlow::ref($t);
        $this->refused(fn () => TaskActions::acknowledge($t, $helper, 'WHATSAPP'), "Only the owner acknowledges $ref.");
        $this->refused(fn () => TaskActions::acknowledge($t, $helper), 'Refused: only the owner acknowledges a task.');
        $this->assertSame("Thanks - $ref acknowledged. Due Fri 08 Nov.", TaskActions::acknowledge($t, $va, 'WHATSAPP'));
        $this->assertNotNull(DB::table('tasks')->where('id', $t)->value('acknowledged_at'));
        $this->refused(fn () => TaskActions::acknowledge($t, $va), 'Already acknowledged.');
        TaskActions::reassign($t, $mgr, $helper->id, 'Ali is on leave');
        $this->assertNull(DB::table('tasks')->where('id', $t)->value('acknowledged_at'), 'reassign clears it');
        $this->assertNull(TaskActions::panel($t, $helper)['ack']);
    }

    public function test_r11_waits_for_start_refused_unblocked_on_approve_and_no_loops(): void
    {
        $mgr = $this->makeUser('MANAGER'); $a = $this->makeUser('VA'); $b = $this->makeUser('VA');
        $t12 = $this->task(['owner_user_id' => $a->id, 'assigned_by_user_id' => $mgr->id, 'status' => 'DOING', 'title' => 'Book courier']);
        $t13 = $this->task(['owner_user_id' => $b->id, 'assigned_by_user_id' => $mgr->id, 'depends_on_task_id' => $t12]);
        $this->refused(fn () => TaskFlow::move($t13, $b, 'DOING'), "Refused: blocked until task #$t12 (\"Book courier\") is APPROVED.");
        $this->refused(fn () => TaskActions::setDependency($t12, $mgr, $t13), 'Refused: ' . TaskFlow::ref($t13) . ' already waits for this task – that would be a loop.');
        $this->refused(fn () => TaskActions::setDependency($t12, $mgr, $t12), 'Refused: a task cannot wait for itself.');
        DB::table('tasks')->where('id', $t12)->update(['status' => 'DONE', 'done_at' => now()]);
        TaskScore::approve($t12, $mgr, 'GOOD');
        $this->assertSame("task #$t12 approved", DB::table('task_history')->where('task_id', $t13)->where('field', 'unblocked')->value('reason'));
        $this->assertSame(1, DB::table('tm_messages')->where('task_id', $t13)->where('rule_code', 'N10')->where('user_id', $b->id)->where('channel', 'INAPP')->count(), 'owner of T-0013 gets tm_unblocked');
        $this->assertSame('DOING', TaskFlow::move($t13, $b, 'DOING')->status);
        // a cancelled dependency frees the waiting task too
        $c1 = $this->task(['owner_user_id' => $a->id, 'assigned_by_user_id' => $mgr->id, 'status' => 'DOING']); $c2 = $this->task(['owner_user_id' => $b->id, 'assigned_by_user_id' => $mgr->id, 'depends_on_task_id' => $c1]);
        TaskFlow::cancel($c1, $mgr, 'Not needed');
        $this->assertSame("task #$c1 cancelled", DB::table('task_history')->where('task_id', $c2)->where('field', 'unblocked')->value('reason'));
    }

    public function test_r12_subtasks_one_level_and_parent_done_when_all_approved(): void
    {
        $mgr = $this->makeUser('MANAGER'); $va = $this->makeUser('VA');
        $p = $this->task(['owner_user_id' => $va->id, 'assigned_by_user_id' => $mgr->id, 'status' => 'DOING', 'linked_module' => 'PRODUCT', 'linked_record_id' => 1]);
        $s1 = TaskCreate::create(1, ['title' => 'Photo', 'owner' => $va->id, 'due' => date('Y-m-d', strtotime('+2 days')), 'parent' => $p], $mgr)['id'];
        $s2 = TaskCreate::create(1, ['title' => 'Listing', 'owner' => $va->id, 'due' => date('Y-m-d', strtotime('+2 days')), 'parent' => $p], $mgr)['id'];
        $this->assertSame(['PRODUCT', 1, $p], [DB::table('tasks')->where('id', $s1)->value('linked_module'), (int) DB::table('tasks')->where('id', $s1)->value('linked_record_id'), (int) DB::table('tasks')->where('id', $s1)->value('parent_task_id')], 'the parent link is copied');
        $this->refused(fn () => TaskCreate::create(1, ['title' => 'x', 'owner' => $va->id, 'due' => date('Y-m-d', strtotime('+2 days')), 'parent' => $s1], $mgr), 'Refused: a subtask cannot have subtasks (one level only).');
        foreach ([$s1, $s2] as $i => $s) {
            DB::table('tasks')->where('id', $s)->update(['status' => 'DONE', 'done_at' => now()]); TaskScore::approve($s, $mgr, 'GOOD');
            $this->assertSame($i === 0 ? 'DOING' : 'DONE', DB::table('tasks')->where('id', $p)->value('status'));
        }
        $this->assertSame('all subtasks approved', DB::table('task_history')->where('task_id', $p)->where('new_value', 'DONE')->value('reason'));
        $this->assertSame(1, DB::table('tm_messages')->where('task_id', $p)->where('rule_code', 'N7')->where('user_id', $mgr->id)->where('channel', 'INAPP')->count(), 'N7 to the parent\'s assigner');
        $this->assertSame('2 of 2 approved', TaskActions::panel($p, $mgr)['progress']);
    }

    public function test_r13_due_moves_two_then_partner_only_and_owner_request(): void
    {
        $mgr = $this->makeUser('MANAGER'); $partner = $this->makeUser('PARTNER'); $va = $this->makeUser('VA');
        $t = $this->task(['owner_user_id' => $va->id, 'assigned_by_user_id' => $mgr->id]);
        $this->refused(fn () => TaskActions::moveDue($t, $va, date('Y-m-d', strtotime('+5 days')), 'late stock'), 'Refused: only the person who assigned it, a Partner or a Manager can move the due date.');
        $this->refused(fn () => TaskActions::moveDue($t, $mgr, date('Y-m-d', strtotime('+5 days')), ' '), 'Refused: a reason is required to move the due date.');
        $this->refused(fn () => TaskActions::moveDue($t, $mgr, date('Y-m-d', strtotime('+3 days')), 'same'), 'Refused: that is already the due date.');
        $this->assertStringContainsString('1 of 2 used', TaskActions::moveDue($t, $mgr, date('Y-m-d', strtotime('+5 days')), 'stock late'));
        $this->assertStringContainsString('2 of 2 used', TaskActions::moveDue($t, $mgr, date('Y-m-d', strtotime('+6 days')), 'still late'));
        $this->refused(fn () => TaskActions::moveDue($t, $mgr, date('Y-m-d', strtotime('+7 days')), 'again'), 'Refused: the due date has already moved 2 times - only a Partner can move it again.');
        TaskActions::moveDue($t, $partner, date('Y-m-d', strtotime('+7 days')), 'supplier strike');
        $row = DB::table('tasks')->where('id', $t)->first();
        $this->assertSame([3, 'DUE_MOVED'], [(int) $row->due_changes, $row->flags]);
        // the owner asks, the assigner approves = counted move by him
        $u = $this->task(['owner_user_id' => $va->id, 'assigned_by_user_id' => $mgr->id]);
        TaskActions::requestDue($u, $va, date('Y-m-d', strtotime('+9 days')), 'waiting for photos');
        $this->refused(fn () => TaskActions::requestDue($u, $va, date('Y-m-d', strtotime('+10 days')), 'again'), 'Refused: a new date is already requested – wait for the answer.');
        TaskActions::decideDue($u, $mgr, true);
        $this->assertSame([date('Y-m-d', strtotime('+9 days')), 1], [substr((string) DB::table('tasks')->where('id', $u)->value('due_at'), 0, 10), (int) DB::table('tasks')->where('id', $u)->value('due_changes')]);
        $this->assertNull(TaskActions::pendingRequest($u));
    }

    public function test_r14_reassign_helper_becomes_owner_and_gets_n11(): void
    {
        $mgr = $this->makeUser('MANAGER'); $ali = $this->makeUser('VA'); $sara = $this->makeUser('VA');
        $t = $this->task(['owner_user_id' => $ali->id, 'assigned_by_user_id' => $mgr->id, 'acknowledged_at' => now()]);
        DB::table('task_helpers')->insert(['task_id' => $t, 'user_id' => $sara->id]);
        $this->refused(fn () => TaskActions::reassign($t, $ali, $sara->id, 'x'), 'Refused: a VA cannot reassign tasks.');
        $this->refused(fn () => TaskActions::reassign($t, $mgr, $sara->id, ''), 'Refused: a reason is required to reassign.');
        $this->refused(fn () => TaskActions::reassign($t, $mgr, $ali->id, 'x'), 'Refused: that person already owns it.');
        $this->refused(fn () => TaskActions::reassign($t, $mgr, 99999999, 'x'), 'Refused: pick an active user.');
        TaskActions::reassign($t, $mgr, $sara->id, 'Ali is on leave');
        $row = DB::table('tasks')->where('id', $t)->first();
        $this->assertSame([$sara->id, null], [(int) $row->owner_user_id, $row->acknowledged_at]);
        $this->assertFalse(DB::table('task_helpers')->where('task_id', $t)->where('user_id', $sara->id)->exists(), 'not a helper any more');
        $this->assertSame('became owner', DB::table('task_history')->where('task_id', $t)->where('field', 'helper_removed')->value('reason'));
        $this->assertSame(1, DB::table('tm_messages')->where('task_id', $t)->where('rule_code', 'N11')->where('user_id', $sara->id)->where('channel', 'INAPP')->count(), 'Sara gets tm_reassigned');
    }

    public function test_r15_cancel_boss_only_undo_ten_minutes_hide_evidence(): void
    {
        $mgr = $this->makeUser('MANAGER'); $va = $this->makeUser('VA');
        $t = $this->task(['owner_user_id' => $va->id, 'assigned_by_user_id' => $mgr->id, 'status' => 'DOING']);
        $this->refused(fn () => TaskFlow::cancel($t, $va, 'not needed'), 'Refused: only a Partner or Manager can cancel a task.');
        $this->refused(fn () => TaskFlow::cancel($t, $mgr, ''), 'Refused: a reason is required to cancel.');
        TaskFlow::cancel($t, $mgr, 'Order was cancelled');
        $this->assertSame('CANCELLED', DB::table('tasks')->where('id', $t)->value('status'));
        $this->assertSame(TaskFlow::ref($t) . ' is back to In progress.', TaskActions::undoCancel($t, $mgr));
        TaskFlow::cancel($t, $mgr, 'Really cancelled'); DB::table('tasks')->where('id', $t)->update(['cancelled_at' => now()->subMinutes(11)]);
        $this->refused(fn () => TaskActions::undoCancel($t, $mgr), 'Refused: a cancel can be undone for 10 minutes only.');
        $this->assertTrue(DB::table('tasks')->where('id', $t)->exists(), 'still searchable, never deleted');
        // a wrong evidence file is hidden with a reason, kept, and no longer counts
        $e = $this->task(['owner_user_id' => $va->id, 'assigned_by_user_id' => $mgr->id, 'status' => 'DOING', 'evidence_required' => 1]);
        $ev = DB::table('task_evidence')->insertGetId(RecordService::fillNotNull('task_evidence', ['task_id' => $e, 'type' => 'PHOTO', 'file_name' => 'wrong.jpg', 'created_at' => now()]));
        $this->refused(fn () => TaskActions::hideEvidence($ev, $va, ''), 'Refused: a reason is required to hide evidence.');
        TaskActions::hideEvidence($ev, $va, 'photo of the wrong parcel');
        $this->assertTrue(DB::table('task_evidence')->where('id', $ev)->exists());
        $this->assertSame('EVIDENCE', TaskFlow::missing(DB::table('tasks')->where('id', $e)->first())[0]['code']);
    }

    public function test_panel_and_routes(): void
    {
        $mgr = $this->makeUser('MANAGER'); $va = $this->makeUser('VA');
        $t = $this->task(['owner_user_id' => $va->id, 'assigned_by_user_id' => $mgr->id]);
        $this->actingAs($va)->postJson("/api/tasks/$t/ack")->assertOk();
        $this->actingAs($va)->postJson("/api/tasks/$t/ack")->assertStatus(422)->assertJsonPath('message', 'Already acknowledged.');
        $p = $this->actingAs($mgr)->getJson("/api/tasks/$t/panel")->assertOk()->json();
        $this->assertTrue($p['can']['move_due']); $this->assertTrue($p['can']['reassign']); $this->assertFalse($p['can']['ack']);
        $this->actingAs($va)->postJson("/api/tasks/$t/due-request", ['date' => date('Y-m-d', strtotime('+8 days')), 'reason' => 'need photos'])->assertOk();
        $this->actingAs($mgr)->postJson("/api/tasks/$t/due-decide", ['approve' => false])->assertStatus(422)->assertJsonPath('message', 'Refused: give the reason for saying no.');
        $this->actingAs($mgr)->postJson("/api/tasks/$t/due-decide", ['approve' => false, 'reason' => 'customer waits'])->assertOk();
        $this->assertSame(0, (int) DB::table('tasks')->where('id', $t)->value('due_changes'));
    }
}
