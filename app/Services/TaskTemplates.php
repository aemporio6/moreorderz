<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RULES_G_CORE R-G_CORE-24 task templates and R-G_CORE-25 templates fired by events.
 * "Create these tasks": pick the record + reference + start date → one task per active item, due = start + offset, item dependencies kept,
 * owners resolved for ALL items before any insert (one function, R-G_CORE-94), a real rollback (Improved), once per record.
 * Events (PO_CONFIRMED, GRN_POSTED (old GRN_SAVED), PERIOD_OPENED and any catalogue event) fire their active templates once per record:
 * the go-live baseline, HISTORICAL records never fire, the record is claimed in tm_trigger_seen (unique event + record) before firing, the result is stored.
 */
class TaskTemplates
{
    /** event → [table, link module of the task, the memory key of tm_trigger_seen]. */
    public const EVENTS = ['PO_CONFIRMED' => ['t_src_po', 'PO', 'PO_CONFIRMED'], 'GRN_POSTED' => ['t_src_grn', 'GRN', 'GRN_SAVED_GRN'], 'PERIOD_OPENED' => ['aud_runs', null, 'PERIOD_OPENED']      /* a shop-month: its SHOP link comes from fk_id_branch (recordFor) */];
    public const ROLES = ['VA' => 'role:VA', 'CATEGORY_DEFAULT' => 'role:VA', 'MANAGER' => 'role:MANAGER|role:PARTNER', 'PARTNER' => 'role:PARTNER', 'WAREHOUSE' => 'role:WAREHOUSE', 'STOCK' => 'role:STOCK'];

    private static function role(?object $u): string { return strtoupper((string) ($u->role_code ?? '')); }
    private static function partner(?object $u): bool { return in_array(self::role($u), ['OWNER', 'PARTNER'], true); }
    private static function v(array $d, array $o, string $k) { return array_key_exists($k, $d) ? $d[$k] : ($o[$k] ?? null); }

    /* ---------- the forms (FormRules hooks) ---------- */

    public static function checkTemplate(array &$data, array $old, ?int $id): array
    {
        if (Auth::user() && ! self::partner(Auth::user())) return ['_form' => 'Refused: only a Partner manages templates.'];
        $name = trim((string) self::v($data, $old, 'name')); if ($name === '') return ['name' => 'Refused: the template name is required.'];
        $tr = strtoupper(trim((string) (self::v($data, $old, 'trigger_event') ?: 'MANUAL')));
        $tr = EventCatalogue::canonical($tr) ?? $tr;      // R-G_CORE-95: a retired name is stored as its canonical name
        if ($tr !== 'MANUAL' && ! DB::table('sys_event_types')->where('event_code', $tr)->exists()) return ['trigger_event' => 'Refused: unknown trigger.'];
        if (array_key_exists('trigger_event', $data) || ! $id) $data['trigger_event'] = $tr;
        $mod = strtoupper(trim((string) self::v($data, $old, 'linked_module')));
        if ($mod !== '' && $mod !== 'NONE' && ! isset(TaskCreate::MODULES[$mod])) return ['linked_module' => 'Refused: choose what the tasks are about from the list.'];
        return [];
    }

    public static function checkItem(array &$data, array $old, ?int $id): array
    {
        if (Auth::user() && ! self::partner(Auth::user())) return ['_form' => 'Refused: only a Partner manages templates.'];
        $tpl = (int) self::v($data, $old, 'template_id'); if (! $tpl || ! DB::table('task_templates')->where('id', $tpl)->exists()) return ['template_id' => 'Refused: pick the template.'];
        if (trim((string) self::v($data, $old, 'title')) === '') return ['title' => 'Refused: the item title is required.'];
        $off = self::v($data, $old, 'offset_days'); $off = $off === null || $off === '' ? 0 : $off;
        if (! is_numeric($off) || (int) $off < 0 || (int) $off != $off || (int) $off > 365) return ['offset_days' => 'Refused: offset days is a whole number from 0 to 365.'];
        $role = strtoupper((string) (self::v($data, $old, 'owner_role') ?: 'VA')); if (! isset(self::ROLES[$role])) return ['owner_role' => 'Refused: choose the owner role from the list.'];
        $dep = (int) self::v($data, $old, 'depends_on_item_id');
        if ($dep && ($dep === (int) $id || ! DB::table('task_template_items')->where('id', $dep)->where('template_id', $tpl)->exists())) return ['depends_on_item_id' => 'Refused: an item can only wait for another item of the same template.'];
        if (! $id && (self::v($data, $old, 'sort') === null || self::v($data, $old, 'sort') === '')) $data['sort'] = (int) DB::table('task_template_items')->where('template_id', $tpl)->max('sort') + 10;      // sort +10
        return [];
    }

    /* ---------- create from a template ---------- */

    private static function items(int $tpl) { return DB::table('task_template_items')->where('template_id', $tpl)->where('is_active', 1)->orderBy('sort')->orderBy('id')->get(); }

    /** The record's label for a refusal ("PO #12"). */
    private static function label(?string $mod, ?int $rec): string { return $mod && $rec ? ($mod === 'PO' ? 'PO' : (TaskCreate::MODULES[$mod][4] ?? $mod)) . " #$rec" : 'this record'; }

    /**
     * Create the tasks of a template. $d: record (picked id), reference, start (Y-m-d, default today). $system: fired by an event.
     * Returns ['ids' => [...], 'message' => …]. Refusals throw before anything is written; a failure on a later item rolls everything back.
     */
    public static function create(int $tplId, object $u, array $d, bool $system = false, ?int $ent = null): array
    {
        if (! $system && ! in_array(self::role($u), ['OWNER', 'PARTNER', 'MANAGER'], true)) throw new \InvalidArgumentException('Refused: only a Partner or Manager can create tasks from a template.');
        $tpl = DB::table('task_templates')->where('id', $tplId)->first() ?? throw new \InvalidArgumentException('Template not found.');
        if (! (int) $tpl->is_active) throw new \InvalidArgumentException('Refused: this template is switched off.');
        $items = self::items($tplId); if ($items->isEmpty()) throw new \InvalidArgumentException('Refused: this template has no active items.');
        $ent ??= (int) (Context::ent() ?: ($u->fk_id_ent ?? 1));
        $mod = strtoupper(trim((string) $tpl->linked_module)); $mod = $mod === '' || $mod === 'NONE' ? null : $mod;
        $rec = ! empty($d['record']) ? (int) $d['record'] : null;
        if ($mod && ! $rec) throw new \InvalidArgumentException('Refused: pick the ' . (TaskCreate::MODULES[$mod][4] ?? 'record') . ' from the search list.');
        if ($mod && ($m = TaskCreate::MODULES[$mod] ?? null) && ! DB::table($m[0])->where('id', $rec)->exists()) throw new \InvalidArgumentException('Refused: ' . ucfirst($m[4]) . " #$rec does not exist.");
        $period = trim((string) ($d['period'] ?? ''));      // a monthly template (PERIOD_OPENED): once per shop AND month
        if ($rec && DB::table('tasks')->where('template_id', $tplId)->where('linked_record_id', $rec)->where('status', '<>', 'CANCELLED')->when($period !== '', fn ($q) => $q->where('reference_id', $period))->exists())
            throw new \InvalidArgumentException("Refused: \"$tpl->name\" tasks already exist for " . self::label($mod, $rec) . ($period !== '' ? " ($period)" : '') . '.');
        $start = trim((string) ($d['start'] ?? '')) ?: date('Y-m-d');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) throw new \InvalidArgumentException('Refused: the start date must look like 2026-11-06.');
        $table = $mod ? (TaskCreate::MODULES[$mod][0] ?? null) : null;
        // owners for ALL items first (R-G_CORE-94: record user → cycle → category default → role fewest open)
        $owners = [];
        foreach ($items as $it) {
            $role = strtoupper((string) $it->owner_role ?: 'VA');
            [$who, $note, $step] = TaskOwner::resolve((object) ['assign_to' => self::ROLES[$role] ?? 'role:VA', 'category_id' => $it->category_id], $ent, ['module' => $table, 'record_id' => $rec]);
            if (! $who || ($step === 'owner' && $note)) throw new \InvalidArgumentException("Refused: nobody found for role $role (item \"$it->title\"). Add the role in Setup > Sourcing LOV or set a category default owner.");
            $owners[$it->id] = [$who, $step];
        }
        $ids = DB::transaction(function () use ($items, $owners, $tpl, $u, $ent, $mod, $rec, $d, $start, $system, $period) {
            $map = [];
            foreach ($items as $it) {
                [$who, $step] = $owners[$it->id];
                $dep = $it->depends_on_item_id ? ($map[$it->depends_on_item_id] ?? null) : null;
                $id = (int) DB::table('tasks')->insertGetId(RecordService::fillNotNull('tasks', ['fk_id_ent' => $ent, 'title' => mb_substr((string) $it->title, 0, 255), 'category_id' => $it->category_id,
                    'owner_user_id' => $who, 'assigned_by_user_id' => $system ? 0 : (int) $u->id /* 0 = System (bosses approve) */, 'priority' => 'NORMAL', 'status' => 'TODO', 'created_at' => now(), 'last_update_at' => now(),
                    'due_at' => date('Y-m-d', strtotime("$start +" . (int) $it->offset_days . ' days')) . ' 23:59:59', 'source' => 'TEMPLATE', 'template_id' => $tpl->id, 'linked_module' => $mod, 'linked_record_id' => $rec,
                    'reference_id' => $period !== '' ? $period : (trim((string) ($d['reference'] ?? '')) ?: null), 'depends_on_task_id' => $dep, 'due_changes' => 0, 'evidence_required' => 1, 'flags' => '', 'is_confidential' => 0]));
                $map[$it->id] = $id;
                DB::table('task_history')->insert(['task_id' => $id, 'at' => now(), 'user_id' => $system ? null : (int) $u->id, 'field' => 'status', 'old_value' => null, 'new_value' => 'TODO',
                    'reason' => mb_substr("From template \"$tpl->name\"" . ($system ? ' (by event)' : '') . " – owner by $step", 0, 255)]);
            }
            return array_values($map);
        });
        foreach ($ids as $id) { TaskFlow::announce($id); EventBus::fire('TASK_CREATED', 'tasks', $id, ['template' => $tpl->id], $ent); }      // each person is told (N1)
        return ['ids' => $ids, 'message' => count($ids) . " task(s) created from \"$tpl->name\"" . ($rec ? ' for ' . self::label($mod, $rec) : '') . '. Each person is told on WhatsApp.'];
    }

    /** Usage count = distinct record × day. */
    public static function usage(int $tplId): int
    {
        return (int) DB::table('tasks')->where('template_id', $tplId)->selectRaw("COUNT(DISTINCT CONCAT(COALESCE(linked_record_id, 0), '|', DATE(created_at))) n")->value('n');
    }

    /** The Templates page. */
    public static function page(?object $u): array
    {
        $cats = DB::table('task_categories')->pluck('name', 'id');
        return ['rows' => DB::table('task_templates')->orderBy('name')->get()->map(fn ($t) => ['id' => (int) $t->id, 'name' => $t->name, 'module' => $t->linked_module ?: null,
            'module_label' => $t->linked_module && isset(TaskCreate::MODULES[$t->linked_module]) ? TaskCreate::MODULES[$t->linked_module][4] : null, 'trigger' => $t->trigger_event ?: 'MANUAL', 'active' => (bool) $t->is_active, 'used' => self::usage((int) $t->id),
            'items' => DB::table('task_template_items')->where('template_id', $t->id)->orderBy('sort')->get()->map(fn ($i) => ['id' => (int) $i->id, 'title' => $i->title, 'role' => $i->owner_role, 'offset' => (int) $i->offset_days,
                'category' => $cats[$i->category_id] ?? null, 'waits_for' => $i->depends_on_item_id ? (int) $i->depends_on_item_id : null, 'active' => (bool) $i->is_active])->all()])->all(),
            'can' => ['manage' => self::partner($u), 'create' => in_array(self::role($u), ['OWNER', 'PARTNER', 'MANAGER'], true)], 'health' => self::health()];
    }

    /* ---------- R-G_CORE-25 fired by events ---------- */

    /** (b) HISTORICAL never fires: IFNULL(cycle_type, 'FULL') of the record's cycle. */
    public static function historical(?string $table, ?object $row): bool
    {
        if (! $row || ! in_array($table, ['t_src_po', 't_src_grn'], true)) return false;
        $cy = (int) ($row->fk_id_cycle ?? 0);
        if (! $cy && $table === 't_src_grn' && ! empty($row->fk_id_po)) $cy = (int) DB::table('t_src_po')->where('id', $row->fk_id_po)->value('fk_id_cycle');
        return $cy && strtoupper((string) (DB::table('t_src_cycles')->where('id', $cy)->value('cycle_type') ?? 'FULL')) === 'HISTORICAL';
    }

    /** An event arrived: fire its active templates once per record. Returns the stored result text (null when nothing to do). */
    public static function fire(string $event, string $entity, int $recordId, int $ent): ?string
    {
        $tpls = DB::table('task_templates')->where('is_active', 1)->where('trigger_event', $event)->orderBy('id')->get();
        if ($tpls->isEmpty() || ! $recordId) return null;
        [$table, , $key] = self::EVENTS[$event] ?? [$entity, null, $event];
        if ($table !== $entity) return null;      // a GRN_POSTED of a lot load is no GRN
        if (! Schema::hasTable($table)) return null;      // switched off; System › Health shows the warning (health())
        $row = DB::table($table)->where('id', $recordId)->first(); if (! $row) return null;
        if (isset($row->f_active) && ! (int) $row->f_active) return null;
        if (self::historical($table, $row)) { DB::table('tm_trigger_seen')->insertOrIgnore(['event' => $key, 'record_id' => $recordId, 'result' => 'HISTORICAL – never fires', 'created_at' => now()]); return 'HISTORICAL – never fires'; }
        if (! DB::table('tm_trigger_seen')->insertOrIgnore(['event' => $key, 'record_id' => $recordId, 'result' => 'firing…', 'created_at' => now()])) return null;      // (c) claimed before firing: once per record
        $sys = (object) ['id' => 0, 'role_code' => 'SYSTEM', 'fk_id_ent' => $ent]; $out = []; $n = 0;
        try { foreach ($tpls as $t) {
            try { $rec = self::recordFor((string) $t->linked_module, $event, $table, $row); if ($rec === false) throw new \InvalidArgumentException('Refused: no ' . strtoupper((string) $t->linked_module) . ' on this record.');
                $r = self::create((int) $t->id, $sys, ['record' => $rec, 'reference' => (string) ($row->po_number ?? $row->grn_number ?? ''), 'period' => (string) ($row->period_month ?? ''), 'start' => $event === 'PERIOD_OPENED' && ! empty($row->period_month) ? max(date('Y-m-d'), $row->period_month . '-01') : null], true, $ent); $n += count($r['ids']); $out[] = "$t->name: created " . count($r['ids']) . ' tasks'; }
            catch (\InvalidArgumentException $e) { $out[] = "$t->name: refused: " . $e->getMessage(); }
        } } catch (\Throwable $e) { DB::table('tm_trigger_seen')->where('event', $key)->where('record_id', $recordId)->delete(); throw $e; }      // a crash frees the claim, so the event retry fires again
        $txt = mb_substr(implode(' · ', $out), 0, 255);
        DB::table('tm_trigger_seen')->where('event', $key)->where('record_id', $recordId)->update(['result' => $txt, 'template_tasks' => $n]);
        return $txt;
    }

    /** The record a template links to when an event fires it: the event's own record, the PO of a GRN, the cycle of a PO / GRN; false when there is none. */
    private static function recordFor(string $mod, string $event, string $table, object $row): int|false|null
    {
        $mod = strtoupper(trim($mod)); if ($mod === '' || $mod === 'NONE') return null;
        if ($mod === (self::EVENTS[$event][1] ?? null)) return (int) $row->id;
        if ($mod === 'PO' && ! empty($row->fk_id_po)) return (int) $row->fk_id_po;
        if ($mod === 'SHOP' && ! empty($row->fk_id_branch)) return (int) $row->fk_id_branch;      // a shop-month (PERIOD_OPENED) → its shop
        if ($mod === 'CYCLE') { $cy = (int) ($row->fk_id_cycle ?? 0) ?: (! empty($row->fk_id_po) ? (int) DB::table('t_src_po')->where('id', $row->fk_id_po)->value('fk_id_cycle') : 0); return $cy ?: false; }
        return false;
    }

    /** (a) go-live baseline: every existing record is remembered as "existed before Task Management – not fired"; one "first look" row per event. */
    public static function baseline(): array
    {
        $out = [];
        foreach (self::EVENTS as $event => [$table, , $key]) {
            if (! Schema::hasTable($table) || DB::table('tm_trigger_seen')->where('event', $key)->where('record_id', 0)->exists()) continue;
            $q = DB::table($table);
            if ($table === 't_src_po') $q->where('status', '<>', 'DRAFT')->where('status', '<>', 'CANCELLED');
            if (Schema::hasColumn($table, 'f_active')) $q->where('f_active', 1);
            $ids = $q->pluck('id');
            foreach ($ids->chunk(500) as $c) DB::table('tm_trigger_seen')->insertOrIgnore($c->map(fn ($id) => ['event' => $key, 'record_id' => (int) $id, 'result' => 'existed before Task Management – not fired', 'created_at' => now()])->values()->all());
            DB::table('tm_trigger_seen')->insertOrIgnore(['event' => $key, 'record_id' => 0, 'result' => 'first look: ' . $ids->count() . ' existing records remembered', 'created_at' => now()]);
            $out[$event] = $ids->count();
        }
        return $out;
    }

    /** (d) System › Health: an event template whose table is missing is switched off with a warning (new). */
    public static function health(): array
    {
        $w = [];
        foreach (DB::table('task_templates')->where('is_active', 1)->whereNotIn('trigger_event', ['MANUAL', ''])->get() as $t) {
            $table = self::EVENTS[$t->trigger_event][0] ?? null;
            if ($table && ! Schema::hasTable($table)) $w[] = "Template \"$t->name\" ($t->trigger_event) is switched off: table $table is missing.";
            if (! $table && ! DB::table('sys_event_types')->where('event_code', $t->trigger_event)->exists()) $w[] = "Template \"$t->name\": event $t->trigger_event is not in the event list.";
        }
        foreach (DB::table('tm_trigger_seen')->where('result', 'like', '%refused:%')->where('created_at', '>=', now()->subDays(7))->orderByDesc('id')->limit(10)->get() as $r)      // an event template that made no tasks is shown, not silent
            $w[] = "Template on $r->event #$r->record_id made no tasks – " . mb_substr((string) $r->result, 0, 200);
        return $w;
    }
}
