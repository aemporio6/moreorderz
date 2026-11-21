<?php

namespace Tests\Feature;

use App\Services\Choices;
use App\Services\PersonChannels;
use App\Services\RecordService;
use App\Services\TaskActions;
use App\Services\TaskCreate;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Owner rule 22 Nov 03:47: B24 proof default + who switches it off + import "tell" box, B22 own channels + who switches channels off – Settings › Choices (default = my suggestion). */
class ChoiceTaskTest extends TestCase
{
    private function due(): string { return date('Y-m-d', strtotime('+3 days')); }

    public function test_b24_proof_default_and_who_may_switch_it_off(): void
    {
        $mgr = $this->makeUser('MANAGER'); $tl = $this->makeUser('TL'); $va = $this->makeUser('VA');
        $t = TaskCreate::create(1, ['title' => 'Check listing', 'owner' => $va->id, 'due' => $this->due()], $mgr)['id'];
        $this->assertSame(1, (int) DB::table('tasks')->where('id', $t)->value('evidence_required'), 'default: proof needed');
        try { TaskCreate::create(1, ['title' => 'x', 'owner' => $va->id, 'due' => $this->due(), 'evidence' => 0, 'evidence_reason' => 'call only'], $tl); $this->fail(); }
        catch (\InvalidArgumentException $e) { $this->assertSame('Refused: only a Partner or Manager may switch "evidence required" off.', $e->getMessage()); }
        Choices::set('TASK_PROOF_OFF_ROLES', 'OWNER,PARTNER,MANAGER,TL', 1);
        $x = TaskCreate::create(1, ['title' => 'x', 'owner' => $va->id, 'due' => $this->due(), 'evidence' => 0, 'evidence_reason' => 'call only'], $tl)['id'];
        $this->assertSame(0, (int) DB::table('tasks')->where('id', $x)->value('evidence_required'), 'the other option: a team lead may switch it off');
        $this->assertSame('Proof is required again.', TaskActions::proofOn($x, $mgr));
        $this->assertStringContainsString('switched off', TaskActions::proofOff($x, $tl, 'customer called'));
        Choices::set('TASK_PROOF_DEFAULT', '0', 1);
        $y = TaskCreate::create(1, ['title' => 'Quick call', 'owner' => $va->id, 'due' => $this->due()], $tl)['id'];
        $this->assertSame(0, (int) DB::table('tasks')->where('id', $y)->value('evidence_required'), 'proof off by default: no reason needed');
        $f = $this->actingAs($va)->getJson('/api/tasks/new')->json(); $this->assertSame([false, false], [$f['proof_default'] ?? null, $f['proof_off'] ?? null]);
        $this->assertFalse($this->actingAs($va)->getJson('/api/tasks?scope=mine')->assertOk()->json('import_tell'), 'import box not ticked by default');
        Choices::set('TASK_IMPORT_TELL', '1', 1); $this->assertTrue($this->actingAs($va)->getJson('/api/tasks?scope=mine')->json('import_tell'));
    }

    public function test_b22_own_channels_and_who_switches_them_off(): void
    {
        $mgr = $this->makeUser('MANAGER'); $va = $this->makeUser('VA');
        $acc = DB::table('t_account')->insertGetId(RecordService::fillNotNull('t_account', ['fk_id_ent' => 1, 'account_code' => 'CT' . random_int(10000, 99999), 'first_name' => 'Va', 'f_active' => 1, 'wa_enabled' => 1]));
        DB::table('t_users')->where('id', $va->id)->update(['fk_id_account' => $acc]); $va->fk_id_account = $acc; $old = (array) DB::table('t_account')->find($acc);
        $this->actingAs($va); $d = ['quiet_from' => '22:00', 'quiet_to' => '08:00'];
        $this->assertSame([], PersonChannels::check($d, $old, $acc), 'default: a person sets his own quiet hours');
        Choices::set('CHANNELS_SELF_EDIT', '0', 1); $d = ['quiet_from' => '22:00', 'quiet_to' => '08:00'];
        $this->assertSame('Refused: only a Partner or Manager can edit channels.', PersonChannels::check($d, $old, $acc)['_form'] ?? null);
        $this->actingAs($mgr); $d = ['wa_enabled' => 0];
        $this->assertSame('Refused: only a Partner can switch a channel on or off for someone.', PersonChannels::check($d, $old, $acc)['wa_enabled'] ?? null, 'default: Owner and Partner only');
        Choices::set('CHANNEL_SWITCH_ROLES', 'OWNER,PARTNER,MANAGER', 1); $d = ['wa_enabled' => 0];
        $this->assertSame([], PersonChannels::check($d, $old, $acc), 'the other option: a Manager may too');
    }
}
