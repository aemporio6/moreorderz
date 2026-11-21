<?php

namespace Tests\Feature;

use App\Services\ExternalContact;
use App\Services\RecordService;
use App\Services\TaskFlow;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 7e-112 stale-verdict sweep: the parts of rules that were already built but marked "not built".
 * R-G_CORE-15 cancel (boss only, reason, never deleted), R-G_CORE-12 parent Done gate on open subtasks, R-C-15 IBAN mod-97 (one digit changed fails).
 */
class StaleSweepTest extends TestCase
{
    private function task(array $x): int
    {
        return DB::table('tasks')->insertGetId(RecordService::fillNotNull('tasks', $x + ['fk_id_ent' => 1, 'title' => 'Sweep task', 'priority' => 'NORMAL', 'status' => 'DOING', 'created_at' => now(), 'due_at' => '2026-11-30', 'source' => 'MANUAL']));
    }

    public function test_g15_cancel_boss_only_with_reason_and_never_deleted(): void
    {
        $mgr = $this->makeUser('MANAGER'); $va = $this->makeUser('VA');
        $t = $this->task(['owner_user_id' => $va->id, 'assigned_by_user_id' => $mgr->id]);
        try { TaskFlow::cancel($t, $va, 'not needed'); $this->fail('VA cancel must be refused'); } catch (\InvalidArgumentException $e) { $this->assertStringStartsWith('Refused: only', $e->getMessage()); }
        try { TaskFlow::cancel($t, $mgr, ' '); $this->fail('reason required'); } catch (\InvalidArgumentException $e) { $this->assertSame('Refused: a reason is required to cancel.', $e->getMessage()); }
        $row = TaskFlow::cancel($t, $mgr, 'Order was cancelled');
        $this->assertSame(['CANCELLED', $mgr->id], [$row->status, (int) $row->cancelled_by_user_id]);
        $this->assertSame('Order was cancelled', DB::table('task_history')->where('task_id', $t)->where('new_value', 'CANCELLED')->value('reason'));
        $this->assertTrue(DB::table('tasks')->where('id', $t)->exists(), 'never deleted');
    }

    public function test_g12_parent_cannot_be_done_while_a_subtask_is_open(): void
    {
        $va = $this->makeUser('VA');
        $p = $this->task(['owner_user_id' => $va->id]);
        $this->task(['owner_user_id' => $va->id, 'parent_task_id' => $p, 'status' => 'DOING']);
        try { TaskFlow::move($p, $va, 'DONE'); $this->fail('open subtask must block Done'); } catch (\InvalidArgumentException $e) { $this->assertStringStartsWith('Refused: 1 subtask is not approved yet.', $e->getMessage()); }
    }

    public function test_c15_iban_mod97_spec_example(): void
    {
        $this->assertTrue(ExternalContact::ibanOk('GB82WEST12345698765432'), 'public example IBAN passes');
        $this->assertFalse(ExternalContact::ibanOk('GB82WEST12345698765433'), 'one digit changed fails mod-97');
    }
}
