<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Context;
use App\Services\RecordService;
use App\Services\TaskEngine;
use App\Services\TaskOwner;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_G_CORE R-G_CORE-94: task priority mapping (P1/P2/P3, URGENT by money / ICC_RED) and the owner order (record user → cycle → category default → role fewest open → Owner "no ROLE set"). */
class TaskOwnerTest extends TestCase
{
    private function f(string $t, array $d): int { return DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }
    private function task(string $code, array $key) { return DB::table('tasks')->where('dedupe_key', $code . ':' . implode(':', $key))->first(); }

    public function test_the_rule_example_pay_due_urgent_to_requested_by_then_finance_fewest_open_and_icc_red(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        DB::table('sys_rules')->where('rule_code', 'SRC_PAY_DUE')->update(['category_id' => 10]); DB::table('task_categories')->where('id', 10)->update(['default_owner_user_id' => null]);
        $buyer = $this->makeUser('MANAGER');
        $fin1 = $this->makeUser('FINANCE'); $fin2 = $this->makeUser('FINANCE');
        // fin1 already has 2 open tasks, fin2 one → fin2 has the fewest
        foreach ([$fin1->id, $fin1->id, $fin2->id] as $i => $u) TaskEngine::open('SRC_PAY_DUE', ['BUSY', $i], 'busy', ['ent' => 1, 'owner_user_id' => $u]);
        $po = $this->f('t_src_po', ['fk_id_ent' => 1, 'po_number' => 'PO-TO-1', 'requested_by' => $buyer->id, 'status' => 'CONFIRMED', 'f_active' => 1]);
        TaskEngine::open('SRC_PAY_DUE', ['PO-TO-1', 'T1'], 'Pay Term 1', ['ent' => 1, 'module' => 't_src_po', 'record_id' => $po, 'amount' => 1500]);
        $t = $this->task('SRC_PAY_DUE', ['PO-TO-1', 'T1']);
        $this->assertSame(['URGENT', $buyer->id], [$t->priority, (int) $t->owner_user_id], 'a term of £1,500 > TASK_URGENT_MONEY → URGENT, owner = the PO requested_by');
        $this->assertStringContainsString('owner by the record: requested_by', (string) DB::table('task_history')->where('task_id', $t->id)->value('reason'));

        // requested_by inactive, no cycle, no category default → the FINANCE person with the fewest open tasks
        User::where('id', $buyer->id)->update(['f_active' => 0]);
        TaskEngine::open('SRC_PAY_DUE', ['PO-TO-1', 'T2'], 'Pay Term 2', ['ent' => 1, 'module' => 't_src_po', 'record_id' => $po, 'amount' => 200]);
        $t2 = $this->task('SRC_PAY_DUE', ['PO-TO-1', 'T2']);
        $this->assertSame([$fin2->id, 'HIGH'], [(int) $t2->owner_user_id, $t2->priority], 'fewest open FINANCE; P1 → HIGH under the money limit');

        // fin2 is away today (absence by person) with fin1 as cover → fin2 is skipped by the role step; as record user he hands over to his cover
        $acc = fn (User $u) => $u->fk_id_account ?: tap($this->f('t_account', ['fk_id_ent' => 1, 'first_name' => 'Acc' . $u->id, 'f_active' => 1, 'f_login' => 1]), fn ($a) => User::where('id', $u->id)->update(['fk_id_account' => $a]));
        $a2 = $acc($fin2); $a1 = $acc($fin1);
        $this->f('user_absence', ['user_id' => $a2, 'cover_user_id' => $a1, 'from_date' => date('Y-m-d', strtotime('-1 day')), 'to_date' => date('Y-m-d', strtotime('+3 days')), 'created_at' => now()]);
        $this->assertSame($fin1->id, TaskOwner::usable($fin2->id), 'absent → his cover');
        $this->assertSame($fin1->id, TaskOwner::fewestOpen('FINANCE', 1), 'an absent person is not picked by the role step');

        // the category default owner comes before the role
        $boss = $this->makeUser('MANAGER'); DB::table('task_categories')->where('id', 10)->update(['default_owner_user_id' => $boss->id]);
        TaskEngine::open('SRC_PAY_DUE', ['PO-TO-1', 'T3'], 'Pay Term 3', ['ent' => 1, 'module' => 't_src_po', 'record_id' => $po]);
        $this->assertSame($boss->id, (int) $this->task('SRC_PAY_DUE', ['PO-TO-1', 'T3'])->owner_user_id);
        DB::table('task_categories')->where('id', 10)->update(['default_owner_user_id' => null]);

        // ICC_RED → URGENT; P3 → LOW; old role name SOURCING maps to MANAGER
        DB::table('sys_rules')->where('rule_code', 'STK_LOW')->update(['priority' => 'P3']);
        TaskEngine::open('STK_LOW', ['ICC-1'], 'Low', ['ent' => 1, 'icc_red' => true]); TaskEngine::open('STK_LOW', ['ICC-2'], 'Low', ['ent' => 1]);
        $this->assertSame(['URGENT', 'LOW'], [$this->task('STK_LOW', ['ICC-1'])->priority, $this->task('STK_LOW', ['ICC-2'])->priority]);
        $this->assertSame('MANAGER', TaskOwner::role('SOURCING'));
        $this->assertSame(0, DB::table('sys_rules')->where('assign_to', 'like', '%role:SOURCING%')->count(), 'old role names mapped on rule rows');

        // nobody with the role → the Owner with the note "no <ROLE> set"
        User::where('role_code', 'ADMIN')->update(['f_active' => 0]);
        $code = DB::table('sys_rules')->where('assign_to', 'role:ADMIN')->where('f_active', 1)->value('rule_code');
        TaskEngine::open($code, ['NOBODY'], 'Nobody', ['ent' => 1]);
        $t4 = $this->task($code, ['NOBODY']);
        $this->assertSame($this->owner->id, (int) $t4->owner_user_id); $this->assertStringContainsString('no ADMIN set', (string) $t4->description);
    }
}
