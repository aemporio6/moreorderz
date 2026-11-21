<?php

namespace Tests\Feature;

use App\Services\EventBus;
use App\Services\FormRules;
use App\Services\RecordService;
use App\Services\TaskTemplates;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_G_CORE R-G_CORE-24 task templates and R-G_CORE-25 templates fired by events – the spec examples. */
class TaskTemplatesTest extends TestCase
{
    private function po(string $no, string $status = 'CONFIRMED', ?int $cycle = null): int
    {
        return DB::table('t_src_po')->insertGetId(RecordService::fillNotNull('t_src_po', ['fk_id_ent' => 1, 'po_code' => $no, 'po_number' => $no, 'po_date' => '2026-11-06', 'status' => $status, 'f_active' => 1, 'fk_id_cycle' => $cycle]));
    }
    private function template(string $name = 'PO confirmed', string $trigger = 'PO_CONFIRMED'): int
    {
        $t = DB::table('task_templates')->insertGetId(['name' => $name . ' ' . uniqid(), 'linked_module' => 'PO', 'trigger_event' => $trigger, 'is_active' => 1]);
        $a = DB::table('task_template_items')->insertGetId(['template_id' => $t, 'title' => 'Pay the deposit', 'owner_role' => 'VA', 'offset_days' => 0, 'sort' => 10, 'is_active' => 1]);
        $b = DB::table('task_template_items')->insertGetId(['template_id' => $t, 'title' => 'Book the QC', 'owner_role' => 'MANAGER', 'offset_days' => 3, 'sort' => 20, 'is_active' => 1]);
        DB::table('task_template_items')->insert(['template_id' => $t, 'title' => 'Send the QC report to the buyer', 'owner_role' => 'VA', 'offset_days' => 10, 'depends_on_item_id' => $b, 'sort' => 30, 'is_active' => 1]);
        return $t;
    }
    private function refused(callable $f, string $txt): void { try { $f(); $this->fail("expected: $txt"); } catch (\InvalidArgumentException $e) { $this->assertSame($txt, $e->getMessage()); } }

    public function test_r24_create_from_template_spec_example(): void
    {
        $mgr = $this->makeUser('MANAGER'); $va = $this->makeUser('VA'); $partner = $this->makeUser('PARTNER');
        $tpl = $this->template(); $name = DB::table('task_templates')->where('id', $tpl)->value('name'); $po = $this->po('PO-TT1');
        $this->refused(fn () => TaskTemplates::create($tpl, $va, ['record' => $po]), 'Refused: only a Partner or Manager can create tasks from a template.');
        $r = TaskTemplates::create($tpl, $mgr, ['record' => $po, 'reference' => 'PI-77', 'start' => '2026-11-06']);
        $t = DB::table('tasks')->whereIn('id', $r['ids'])->orderBy('id')->get();
        $this->assertSame(['2026-11-06', '2026-11-09', '2026-11-16'], $t->map(fn ($x) => substr((string) $x->due_at, 0, 10))->all(), 'due = start + offset');
        $this->assertSame([null, null, (int) $t[1]->id], $t->map(fn ($x) => $x->depends_on_task_id ? (int) $x->depends_on_task_id : null)->all(), 'item 3 waits for item 2');
        $this->assertTrue(\App\Services\AlertRules::isBlocked((int) $t[2]->id), 'third BLOCKED');
        $this->assertSame([['TEMPLATE', 'PO', $po, 'PI-77', 'NORMAL']], $t->take(1)->map(fn ($x) => [$x->source, $x->linked_module, (int) $x->linked_record_id, $x->reference_id, $x->priority])->values()->all());
        $this->assertSame('MANAGER', DB::table('t_users')->where('id', $t[1]->owner_user_id)->value('role_code'), 'the Manager item → a Manager');
        $this->assertSame(3, DB::table('tm_messages')->whereIn('task_id', $r['ids'])->where('rule_code', 'N1')->where('channel', 'INAPP')->count(), 'each person is told');
        $this->refused(fn () => TaskTemplates::create($tpl, $mgr, ['record' => $po]), "Refused: \"$name\" tasks already exist for PO #$po.");
        $this->assertSame(1, TaskTemplates::usage($tpl));
        // switched off / no active items / nobody for a role → nothing written (owners first, then one transaction)
        DB::table('task_templates')->where('id', $tpl)->update(['is_active' => 0]);
        $this->refused(fn () => TaskTemplates::create($tpl, $mgr, ['record' => $this->po('PO-TT2')]), 'Refused: this template is switched off.');
        $empty = DB::table('task_templates')->insertGetId(['name' => 'Empty ' . uniqid(), 'linked_module' => 'PO', 'trigger_event' => 'MANUAL', 'is_active' => 1]);
        $this->refused(fn () => TaskTemplates::create($empty, $mgr, ['record' => $po]), 'Refused: this template has no active items.');
        $wh = DB::table('task_templates')->insertGetId(['name' => 'Stock ' . uniqid(), 'linked_module' => 'PO', 'trigger_event' => 'MANUAL', 'is_active' => 1]);
        DB::table('task_template_items')->insert([['template_id' => $wh, 'title' => 'Count the cartons', 'owner_role' => 'VA', 'offset_days' => 0, 'sort' => 10, 'is_active' => 1], ['template_id' => $wh, 'title' => 'Put away', 'owner_role' => 'STOCK', 'offset_days' => 1, 'sort' => 20, 'is_active' => 1]]);
        DB::table('t_users')->whereIn('role_code', ['STOCK', 'WAREHOUSE'])->update(['f_active' => 0]); DB::table('mo_user_roles')->whereIn('role_code', ['STOCK', 'WAREHOUSE'])->delete();
        $before = DB::table('tasks')->count(); $po3 = $this->po('PO-TT3');
        $this->refused(fn () => TaskTemplates::create($wh, $mgr, ['record' => $po3]), 'Refused: nobody found for role STOCK (item "Put away"). Add the role in Setup > Sourcing LOV or set a category default owner.');
        $this->assertSame($before, DB::table('tasks')->count(), 'the first item was not written either');
        // the forms: Partner only, known triggers, old GRN_SAVED → GRN_POSTED
        Auth::login($mgr); $d = ['name' => 'X', 'trigger_event' => 'MANUAL'];
        $this->assertSame('Refused: only a Partner manages templates.', FormRules::check('task_template', $d, [], null)['_form'] ?? null);
        Auth::login($partner); $d = ['name' => 'X', 'trigger_event' => 'PO_PAINTED'];
        $this->assertSame('Refused: unknown trigger.', FormRules::check('task_template', $d, [], null)['trigger_event'] ?? null);
        $d = ['name' => 'X', 'trigger_event' => 'GRN_SAVED']; $this->assertSame([], array_intersect_key(FormRules::check('task_template', $d, [], null), ['trigger_event' => 1, '_form' => 1])); $this->assertSame('GRN_POSTED', $d['trigger_event']);
        $items = DB::table('task_template_items')->where('template_id', $tpl)->orderBy('sort')->pluck('id')->all();
        $d = ['template_id' => $tpl, 'title' => 'Late item', 'owner_role' => 'VA', 'offset_days' => -1]; $this->assertSame('Refused: offset days is a whole number from 0 to 365.', FormRules::check('task_template_item', $d, [], null)['offset_days'] ?? null);
        $d = ['template_id' => $tpl, 'title' => 'Wait', 'owner_role' => 'VA', 'offset_days' => 1, 'depends_on_item_id' => DB::table('task_template_items')->where('template_id', $wh)->value('id')];
        $this->assertSame('Refused: an item can only wait for another item of the same template.', FormRules::check('task_template_item', $d, [], null)['depends_on_item_id'] ?? null);
        $d = ['template_id' => $tpl, 'title' => 'Next', 'owner_role' => 'VA', 'offset_days' => 1]; FormRules::check('task_template_item', $d, [], null); $this->assertSame(40, $d['sort'], 'sort +10');
        // the page
        $p = $this->actingAs($mgr)->getJson('/api/task-templates')->assertOk()->json();
        $row = collect($p['rows'])->firstWhere('id', $tpl); $this->assertSame([1, 3, false], [$row['used'], count($row['items']), $p['can']['manage']]);
    }

    public function test_r25_fired_by_events_baseline_historical_once(): void
    {
        $this->makeUser('VA'); $this->makeUser('MANAGER');
        DB::table('tm_trigger_seen')->where('event', 'PO_CONFIRMED')->delete();
        $old = [$this->po('PO-OLD1'), $this->po('PO-OLD2'), $this->po('PO-OLD3')]; $this->po('PO-DRAFT', 'DRAFT');
        $tpl = $this->template('On confirm');
        $n = DB::table('tasks')->count();
        $b = TaskTemplates::baseline();
        $this->assertGreaterThanOrEqual(3, $b['PO_CONFIRMED']); $this->assertSame($n, DB::table('tasks')->count(), 'go-live: 0 tasks created');
        $this->assertSame('existed before Task Management – not fired', DB::table('tm_trigger_seen')->where('event', 'PO_CONFIRMED')->where('record_id', $old[0])->value('result'));
        $this->assertStringStartsWith('first look: ', (string) DB::table('tm_trigger_seen')->where('event', 'PO_CONFIRMED')->where('record_id', 0)->value('result'));
        $this->assertFalse(DB::table('tm_trigger_seen')->where('event', 'PO_CONFIRMED')->where('record_id', DB::table('t_src_po')->where('po_number', 'PO-DRAFT')->value('id'))->exists(), 'a draft is no confirmed PO');
        $this->assertNull(TaskTemplates::fire('PO_CONFIRMED', 't_src_po', $old[0], 1), 'an old PO never fires');
        // a new PO confirmed → the template fires once, through the event bus
        $new = $this->po('PO-NEW1');
        $e = EventBus::fire('PO_CONFIRMED', 't_src_po', $new, [], 1); EventBus::process($e);
        $this->assertSame(3, DB::table('tasks')->where('template_id', $tpl)->where('linked_record_id', $new)->count());
        $this->assertSame(0, (int) DB::table('tasks')->where('template_id', $tpl)->where('linked_record_id', $new)->value('assigned_by_user_id'), 'actor System');
        $this->assertStringContainsString('created 3 tasks', (string) DB::table('tm_trigger_seen')->where('event', 'PO_CONFIRMED')->where('record_id', $new)->value('result'));
        $e2 = EventBus::fire('PO_CONFIRMED', 't_src_po', $new, [], 1); EventBus::process($e2);      // replaying the event
        $this->assertSame(3, DB::table('tasks')->where('template_id', $tpl)->where('linked_record_id', $new)->count(), 'no second set');
        // HISTORICAL never fires
        $cy = DB::table('t_src_cycles')->insertGetId(RecordService::fillNotNull('t_src_cycles', ['fk_id_ent' => 1, 'cycle_code' => 'SC-H-' . uniqid(), 'sourcing_date' => '2026-01-02', 'product_name' => 'old', 'status' => 'Open', 'f_active' => 1, 'cycle_type' => 'HISTORICAL']));
        $h = $this->po('PO-HIST', 'CONFIRMED', $cy);
        $this->assertSame('HISTORICAL – never fires', TaskTemplates::fire('PO_CONFIRMED', 't_src_po', $h, 1));
        $this->assertSame(0, DB::table('tasks')->where('linked_record_id', $h)->where('template_id', $tpl)->count());
        // a GRN_POSTED of a lot load (no GRN) does not fire a GRN template; a refusal is stored as the result
        $g = $this->template('On GRN', 'GRN_POSTED');
        $this->assertNull(TaskTemplates::fire('GRN_POSTED', 'fin_lots', 5, 1));
        DB::table('task_templates')->where('id', $g)->update(['linked_module' => 'GRN']); DB::table('task_template_items')->where('template_id', $g)->update(['owner_role' => 'STOCK']); DB::table('t_users')->whereIn('role_code', ['STOCK', 'WAREHOUSE'])->update(['f_active' => 0]); DB::table('mo_user_roles')->whereIn('role_code', ['STOCK', 'WAREHOUSE'])->delete();
        $grn = DB::table('t_src_grn')->insertGetId(RecordService::fillNotNull('t_src_grn', ['fk_id_ent' => 1, 'grn_number' => 'GRN-T1', 'f_active' => 1]));
        $this->assertStringContainsString('refused: Refused: nobody found for role STOCK', (string) TaskTemplates::fire('GRN_POSTED', 't_src_grn', $grn, 1));
        $this->assertSame('GRN_SAVED_GRN', DB::table('tm_trigger_seen')->where('record_id', $grn)->value('event'), 'old memory key');
        $this->assertNotEmpty(array_filter(TaskTemplates::health(), fn ($w) => str_contains($w, "GRN_SAVED_GRN #$grn made no tasks")), 'a refusal shows on System › Health');
    }
}
