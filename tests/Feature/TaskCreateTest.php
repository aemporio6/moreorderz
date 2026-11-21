<?php

namespace Tests\Feature;

use App\Services\RecordService;
use App\Services\TaskCreate;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_G_CORE R-G_CORE-2: new task form – category SLA preset, pick-only record link, dependency, absence cover, weekly limit warning, "Refused:" texts. */
class TaskCreateTest extends TestCase
{
    private function f(string $t, array $d): int { return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }
    private function person(string $name, int $limit = 6): array
    {
        $acc = $this->f('t_account', ['fk_id_ent' => 1, 'account_code' => 'TC' . random_int(100, 999), 'first_name' => $name, 'f_active' => 1, 'weekly_task_limit' => $limit]);
        $u = $this->makeUser('VA'); DB::table('t_users')->where('id', $u->id)->update(['fk_id_account' => $acc, 'display_name' => $name]);
        return [$u->id, $acc];
    }

    public function test_the_rule_example_and_refusals(): void
    {
        $this->actingAs($this->owner);
        $cat = $this->f('task_categories', ['name' => 'Listing', 'default_due_days' => 3, 'default_owner_user_id' => null, 'is_active' => 1, 'sort' => 1]);
        $this->assertSame(['due' => '2026-11-09', 'owner' => null], TaskCreate::prefill($cat, '2026-11-06'), 'Listing picked on 06 Nov → due 09 Nov, owner box stays empty');
        $this->postJson('/api/tasks/new', ['title' => 'List the lamp', 'category' => $cat])->assertStatus(422)->assertJsonPath('message', 'Refused: owner is required.');
        [$sara] = $this->person('Sara');
        $this->postJson('/api/tasks/new', ['title' => 'List the lamp', 'category' => $cat, 'owner' => $sara, 'link' => 'PO', 'record_text' => 'PO 123'])->assertStatus(422)
            ->assertJsonPath('message', 'Refused: pick the purchase order from the search list, or choose "No record".');
        $this->postJson('/api/tasks/new', ['title' => 'X', 'owner' => $sara, 'due' => date('Y-m-d'), 'link' => 'PO', 'record' => 999999])->assertStatus(422)->assertJsonPath('message', 'Refused: Purchase order #999999 does not exist. Search and pick the record from the list.');
        $this->postJson('/api/tasks/new', ['title' => 'X', 'owner' => $sara, 'due' => date('Y-m-d'), 'priority' => 'ASAP'])->assertStatus(422)->assertJsonPath('message', 'Refused: priority must be one of URGENT, HIGH, NORMAL, LOW.');
        $this->postJson('/api/tasks/new', ['title' => str_repeat('a', 256), 'owner' => $sara, 'due' => date('Y-m-d')])->assertStatus(422)->assertJsonPath('message', 'Refused: title is longer than 255 characters.');
        $this->postJson('/api/tasks/new', ['title' => 'X', 'owner' => $sara, 'due' => date('Y-m-d'), 'depends_on' => 999999])->assertStatus(422)->assertJsonPath('message', 'Refused: the task it depends on does not exist.');

        // a picked record, the category's due date, server fields; a typed created date is ignored
        $p = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'TC-LAMP', 'product_name_vendor' => 'Lamp', 'f_active' => 1]);
        $hit = TaskCreate::records(1, 'PRODUCT', 'tc-la'); $this->assertSame([$p], array_column($hit, 'id'));
        $r = $this->postJson('/api/tasks/new', ['title' => 'List the lamp', 'category' => $cat, 'owner' => $sara, 'link' => 'PRODUCT', 'record' => $p, 'created_at' => '2020-01-01', 'checklist' => "Photos\nTitle"])->assertOk()->json();
        $t = DB::table('tasks')->find($r['id']);
        $this->assertSame([$sara, $this->owner->id, 'MANUAL', 'PRODUCT', $p, date('Y-m-d', strtotime('+3 days'))], [(int) $t->owner_user_id, (int) $t->assigned_by_user_id, $t->source, $t->linked_module, (int) $t->linked_record_id, substr($t->due_at, 0, 10)]);
        $this->assertNotSame('2020-01-01', substr($t->created_at, 0, 10));
        $this->assertSame(2, DB::table('task_checklist')->where('task_id', $r['id'])->count());
        $this->assertSame([$r['id']], array_column(TaskCreate::tasks(1, 'List the'), 'id'), 'dependency picked by name');
        $dep = $this->postJson('/api/tasks/new', ['title' => 'Then ship it', 'owner' => $sara, 'due' => date('Y-m-d'), 'depends_on' => $r['id']])->assertOk()->json('id');
        $this->assertSame($r['id'], (int) DB::table('tasks')->where('id', $dep)->value('depends_on_task_id'));
    }

    public function test_absence_cover_and_weekly_limit_warning(): void
    {
        $this->actingAs($this->owner);
        [$ali, $aliAcc] = $this->person('Ali'); [$sara, $saraAcc] = $this->person('Sara', 6);
        $this->f('user_absence', ['user_id' => $aliAcc, 'cover_user_id' => $saraAcc, 'from_date' => date('Y-m-d', strtotime('-1 day')), 'to_date' => date('Y-m-d', strtotime('+3 days')), 'created_at' => now()]);
        for ($i = 0; $i < 5; $i++) $this->f('tasks', ['fk_id_ent' => 1, 'title' => "Open $i", 'owner_user_id' => $sara, 'status' => 'TODO', 'priority' => 'NORMAL', 'source' => 'MANUAL', 'created_at' => now(), 'due_at' => now()]);
        $r = $this->postJson('/api/tasks/new', ['title' => 'For Ali', 'owner' => $ali, 'due' => date('Y-m-d')])->assertOk()->json();
        $this->assertSame($sara, (int) DB::table('tasks')->where('id', $r['id'])->value('owner_user_id'), 'the cover gets it');
        $this->assertContains('Owner is absent - task given to Sara.', $r['warnings']);
        $this->assertStringStartsWith('created during absence of Ali', (string) DB::table('task_history')->where('task_id', $r['id'])->where('field', 'absence_cover')->value('reason'));
        $this->assertSame(['open' => 6, 'limit' => 6, 'state' => 'Full'], TaskCreate::load($sara));
        $w = $this->postJson('/api/tasks/new', ['title' => 'One more', 'owner' => $sara, 'due' => date('Y-m-d')])->assertOk()->json('warnings');
        $this->assertSame(['Sara now has 7 open tasks (limit 6).'], $w, 'a warning only, never a block');
        $f = $this->getJson('/api/tasks/new')->assertOk()->json('owners');
        $this->assertSame('Overloaded', collect($f)->firstWhere('id', $sara)['state']);
        $this->assertTrue(collect($f)->firstWhere('id', $ali)['absent']);
    }
}
