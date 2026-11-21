<?php

namespace Tests\Feature;

use App\Services\Choices;
use App\Services\DashCards;
use App\Services\RecordService;
use App\Services\TaskActions;
use App\Services\TaskComments;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Owner rule 22 Nov 03:47 – part C numbers as Settings › Choices: MR-0496 undo minutes, MR-0497 comment length, MR-0476 sourcing "due soon" window. */
class ChoiceNumbersTest extends TestCase
{
    private function task(array $x): int
    {
        return DB::table('tasks')->insertGetId(RecordService::fillNotNull('tasks', $x + ['fk_id_ent' => 1, 'title' => 'Pack order', 'priority' => 'NORMAL', 'status' => 'TODO', 'created_at' => now(), 'due_at' => date('Y-m-d', strtotime('+3 days')), 'source' => 'MANUAL', 'due_changes' => 0, 'evidence_required' => 0, 'flags' => '', 'is_confidential' => 0]));
    }

    public function test_mr0496_undo_minutes_and_mr0497_comment_length(): void
    {
        $mgr = $this->makeUser('MANAGER'); $va = $this->makeUser('VA'); $this->actingAs($mgr);
        $t = $this->task(['owner_user_id' => $va->id, 'assigned_by_user_id' => $mgr->id, 'status' => 'CANCELLED', 'cancelled_at' => now()->subMinutes(20), 'cancelled_by_user_id' => $mgr->id]);
        DB::table('task_history')->insert(['task_id' => $t, 'at' => now()->subMinutes(20), 'user_id' => $mgr->id, 'field' => 'status', 'old_value' => 'TODO', 'new_value' => 'CANCELLED']);
        try { TaskActions::undoCancel($t, $mgr); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('Refused: a cancel can be undone for 10 minutes only.', $e->getMessage(), 'default 10 minutes'); }
        Choices::set('TASK_UNDO_MINUTES', '30', 1);
        TaskActions::undoCancel($t, $mgr); $this->assertSame('TODO', DB::table('tasks')->where('id', $t)->value('status'), '30 minutes → a 20-minute-old cancel comes back');
        $c = $this->task(['owner_user_id' => $va->id, 'assigned_by_user_id' => $mgr->id]);
        TaskComments::add($c, $va, str_repeat('x', 2000));
        Choices::set('TASK_COMMENT_MAX', '1000', 1);
        try { TaskComments::add($c, $va, str_repeat('x', 1001)); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('Refused: a comment is at most 1,000 characters.', $e->getMessage()); }
    }

    public function test_mr0476_sourcing_due_soon_window(): void
    {
        $po = DB::table('t_src_po')->insertGetId(RecordService::fillNotNull('t_src_po', ['fk_id_ent' => 1, 'po_code' => 'PO-DS', 'po_number' => 'PO-DS', 'po_date' => '2026-11-01', 'status' => 'CONFIRMED', 'f_active' => 1]));
        $cy = DB::table('t_src_cycles')->insertGetId(RecordService::fillNotNull('t_src_cycles', ['fk_id_ent' => 1, 'cycle_code' => 'SC-DS-' . uniqid(), 'sourcing_date' => '2026-11-01', 'product_name' => 'x', 'status' => 'Open', 'f_active' => 1, 'cycle_type' => 'FULL']));
        DB::table('t_src_po')->where('id', $po)->update(['fk_id_cycle' => $cy]);
        DB::table('t_src_payment_terms')->insert(RecordService::fillNotNull('t_src_payment_terms', ['fk_id_ent' => 1, 'fk_id_cycle' => $cy, 'fk_id_po' => $po, 'term_no' => 1, 'milestone' => 'Balance', 'planned_amount' => 500, 'paid_amount' => 0, 'remaining_amount' => 500, 'status' => 'DUE', 'f_active' => 1, 'due_date' => '2026-11-16']));
        $base = DashCards::payments(1, '2026-11-06');
        $this->assertSame(7, $base['due_days']); $w7 = $base['due_7_days'];
        Choices::set('SOURCING_DUE_DAYS', '14', 1);
        $d = DashCards::payments(1, '2026-11-06');
        $this->assertSame(14, $d['due_days']); $this->assertEqualsWithDelta($w7 + 500, $d['due_7_days'], 0.01, 'a payment due in 10 days counts within 14 days');
    }
}
