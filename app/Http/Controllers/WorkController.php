<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use App\Services\Context;
use App\Services\RecordService;
use App\Services\Rights;
use App\Services\SearchIndex;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Search, record page, export, tasks. */
class WorkController extends Controller
{
    private const CAN_EXPORT = ['OWNER', 'MANAGER', 'FINANCE', 'ACCOUNTANT'];

    public function search(Request $r)
    {
        return ['results' => SearchIndex::find((string) $r->query('q'), Context::ent())];
    }

    /** Search everything: screens, actions, create, reports, settings, fields, rights, records – only what this person may open. */
    public function find(Request $r)
    {
        $q = (string) $r->query('q', '');
        // a barcode scan (fast keys + Enter) asks for the one exact record
        if ($r->boolean('scan')) return ['q' => $q, 'exact' => \App\Services\Finder::scan($q, $r->user())];
        return \App\Services\Finder::find($q, $r->user(), 6, (string) $r->query('scope', 'all'));
    }

    public function findOpened(Request $r)
    {
        $d = $r->validate(['kind' => 'required|string|max:20', 'title' => 'required|string|max:255', 'sub' => 'nullable|string|max:255', 'url' => 'required|string|max:255', 'pin' => 'nullable|boolean', 'q' => 'nullable|string|max:200', 'count' => 'nullable|integer']);
        if ($d['kind'] === 'search') { \App\Services\Finder::pinSearch($r->user(), $d['title'], (bool) ($d['pin'] ?? false)); return ['ok' => true]; }
        if (! empty($d['q'])) { $m = []; preg_match('~^#/rec/([\w-]+)/(\d+)~', $d['url'], $m); \App\Services\Finder::remember($r->user(), $d['q'], max(1, (int) ($d['count'] ?? 1)), $m[1] ?? null, isset($m[2]) ? (int) $m[2] : null); }
        if (array_key_exists('pin', $d) && $d['pin'] !== null) \App\Services\Finder::pin($r->user(), $d['url'], (bool) $d['pin'], $d['kind'], $d['title'], $d['sub'] ?? null);
        else \App\Services\Finder::opened($r->user(), $d['kind'], $d['title'], $d['sub'] ?? null, $d['url']);
        return ['ok' => true];
    }

    public function page(Request $r, string $key, int $id)
    {
        Rights::check('VIEW', $key);
        $page = RecordService::page($key, $id);
        // remembered for "Opened lately" in search
        $ix = DB::table('sys_search_index')->where('url', "#/rec/$key/$id")->first(['title', 'subtitle']);
        \App\Services\Finder::opened($r->user(), 'record', $ix->title ?? (\App\Services\Registry::fields($key)[0]['form_title'] . " #$id"), $ix->subtitle ?? null, "#/rec/$key/$id");
        return $page;
    }

    /** Export what the user sees (columns, order, filters) as CSV, Excel or PDF; "json" gives the rows for Copy. */
    public function export(Request $r, string $key)
    {
        \App\Services\Perm::need($r->user(), "form:$key:EXPORT");
        $fmt = strtoupper((string) $r->query('format', 'CSV'));
        if ($fmt === 'JSON') { [$head, $rows] = RecordService::export($key, $r->query()); return ['head' => $head, 'rows' => array_slice($rows, 0, 5000)]; }
        [$bytes, $ext, $mime] = \App\Services\ListExports::build($key, $r->query(), $fmt, \App\Services\Registry::fields($key)[0]['form_title']);
        return response($bytes, 200, ['Content-Type' => $mime, 'Content-Disposition' => 'attachment; filename="' . $key . '_' . date('Ymd_Hi') . '.' . $ext . '"']);
    }

    // ---- saved export templates (every list) ----
    public function exportTemplates(Request $r, string $key)
    {
        \App\Services\Perm::need($r->user(), "form:$key:EXPORT");
        return ['templates' => \App\Services\ListExports::templates($r->user(), $key), 'can_share' => \App\Services\Perm::has($r->user(), 'admin.saveView_may'),
            'roles' => array_values(array_map(fn ($x) => ['code' => $x['code'], 'label' => $x['label']], array_filter(\App\Services\Perm::roles(), fn ($x) => $x['active'])))];
    }

    public function saveExportTemplate(Request $r, string $key)
    {
        \App\Services\Perm::need($r->user(), "form:$key:EXPORT");
        try { return ['id' => \App\Services\ListExports::save($r->user(), $key, $r->all()), 'message' => 'Export saved']; }
        catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); }
    }

    public function runExportTemplate(Request $r, int $id)
    {
        $t = \App\Services\ListExports::find($r->user(), $id);
        \App\Services\Perm::need($r->user(), "form:{$t->list_key}:EXPORT");
        [$bytes, $name, $mime, $rows] = \App\Services\ListExports::run($t);
        \App\Services\Audit::log(\App\Services\Registry::table($t->list_key), 0, 'EXPORT', ['rows' => $rows, 'template' => $t->name]);
        return response($bytes, 200, ['Content-Type' => $mime, 'Content-Disposition' => 'attachment; filename="' . $name . '"']);
    }

    public function removeExportTemplate(Request $r, int $id) { \App\Services\ListExports::remove($r->user(), $id); return ['message' => 'Export removed']; }

    // ---- list preferences of this person ----
    public function prefs(Request $r, string $page) { return \App\Services\ListExports::prefs($r->user(), $page); }
    public function savePrefs(Request $r, string $page) { \App\Services\ListExports::savePrefs($r->user(), $page, $r->all()); return ['ok' => true]; }

    public function due(Request $r) { return \App\Services\DueEngine::list((int) Context::ent(), $r->user(), $r->only(['chip', 'status'])); }
    public function dueDone(Request $r, int $id) { try { return \App\Services\DueEngine::done((int) Context::ent(), $id, $r->user()); } catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); } }

    public function tasks(Request $r)
    {
        $u = $r->user();
        $q = DB::table('tasks as t')->leftJoin('t_users as u', 'u.id', '=', 't.owner_user_id')->leftJoin('task_categories as c', 'c.id', '=', 't.category_id')
            ->where(fn ($w) => $w->where('t.fk_id_ent', Context::ent())->orWhere('t.fk_id_ent', 0)->orWhereNull('t.fk_id_ent'));
        $scope = $r->query('scope', 'mine');
        if ($scope === 'stuck_on_me') $q->where('t.status', 'STUCK')->where('t.stuck_on_type', 'USER')->where('t.stuck_on_id', $u->id);   // tasks of others that wait on this person
        elseif ($scope === 'mine' || ! \App\Services\Perm::has($u, 'work.tasks_may')) $q->where('t.owner_user_id', $u->id);
        if ($r->query('status', 'open') === 'open') $q->whereNotIn('t.status', ['DONE', 'APPROVED', 'CANCELLED']);
        if ($s = $r->query('q')) $q->where('t.title', 'like', "%$s%");
        $rows = $q->orderByRaw("FIELD(t.priority,'URGENT','HIGH','NORMAL','LOW')")->orderBy('t.due_at')->limit(300)
            ->get(['t.id', 't.title', 't.status', 't.priority', 't.due_at', 't.rule_code', 't.source', 't.fix_url', 't.linked_module', 't.linked_record_id', 't.amount_gbp', 't.stuck_on_type', 't.stuck_on_text', 't.stuck_since', 'u.display_name as owner', 'c.name as category']);
        $today = date('Y-m-d');
        return ['tasks' => $rows, 'stuck_on_me' => DB::table('tasks')->where('status', 'STUCK')->where('stuck_on_type', 'USER')->where('stuck_on_id', $u->id)->count(),
            'stuck_on' => \App\Services\Guide::STUCK_ON, 'people' => DB::table('t_users')->where('f_active', 1)->where('id', '<>', $u->id)->orderBy('display_name')->get(['id', DB::raw('COALESCE(display_name, login_id) as name')]),
            'counts' => ['open' => $rows->count(), 'late' => $rows->filter(fn ($t) => $t->due_at < $today)->count(), 'urgent' => $rows->where('priority', 'URGENT')->count()]];
    }

    public function taskStatus(Request $r, int $id)
    {
        $data = $r->validate(['status' => 'required|in:TODO,DOING,STUCK,DONE', 'note' => 'nullable|string|max:500']);
        $t = DB::table('tasks')->where('id', $id)->first() ?? abort(404);
        $u = $r->user();
        abort_unless((int) $t->owner_user_id === $u->id || \App\Services\Perm::has($u, 'work.taskStatus_may'), 403, 'This task belongs to someone else.');
        if ($data['status'] === 'DONE' && $t->source === 'RULE') {
            return response()->json(['message' => 'This task was opened by a rule. It closes by itself when the problem is fixed' . ($t->fix_url ? ' – use the Fix link.' : '.')], 422);
        }
        if ($data['status'] === 'STUCK' && empty($data['note'])) return response()->json(['message' => 'Say what you are stuck on.', 'errors' => ['note' => ['Say what you are stuck on.']]], 422);
        if ($t->status === 'STUCK' && $data['status'] !== 'STUCK') \App\Services\Guide::unstuck($id);   // start again or done: the stuck fields are cleared
        DB::table('tasks')->where('id', $id)->update(['status' => $data['status'], 'last_update_at' => now(), 'done_at' => $data['status'] === 'DONE' ? now() : null,
            'stuck_since' => $data['status'] === 'STUCK' ? now() : null, 'stuck_on_text' => $data['status'] === 'STUCK' ? $data['note'] : null]);
        DB::table('task_history')->insert(['task_id' => $id, 'at' => now(), 'user_id' => $u->id, 'field' => 'status', 'old_value' => $t->status, 'new_value' => $data['status'], 'reason' => $data['note'] ?? null]);
        Audit::log('tasks', $id, 'UPDATE', ['status' => [$t->status, $data['status']]]);
        return ['ok' => true];
    }

    /** "I am stuck": what the task waits on (a person, a supplier, a marketplace, a forwarder, something else). The person named sees it under "Stuck on me". */
    public function taskStuck(Request $r, int $id)
    {
        $d = $r->validate(['type' => 'required|string', 'on_id' => 'nullable|integer', 'text' => 'nullable|string|max:255']);
        try { \App\Services\Guide::stuck($id, $r->user(), $d['type'], $d['on_id'] ?? null, $d['text'] ?? null); return ['ok' => true, 'message' => 'Marked as stuck']; }
        catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); }
    }

    // ---- guidance ----
    public function guideList(Request $r, string $key) { Rights::check('VIEW', $key); return \App\Services\Guide::list($key, $r->user()); }
    public function guideRecord(Request $r, string $key, int $id) { Rights::check('VIEW', $key); return \App\Services\Guide::record($key, $id, $r->user()); }
    public function playbooks(Request $r, string $workflow) { return ['playbooks' => \App\Services\Guide::playbooks(strtoupper($workflow)), 'can_edit' => \App\Services\Perm::has($r->user(), 'form:playbook_step:EDIT')]; }
    public function playbookCoverage(Request $r) { \App\Services\Perm::need($r->user(), 'form:playbook_step:VIEW'); return ['rows' => \App\Services\Guide::playbookCoverage()]; }
    public function nav(Request $r) { return \App\Services\Guide::nav($r->user()); }
    public function navVisit(Request $r)
    {
        $d = $r->validate(['title' => 'required|string|max:150', 'url' => 'required|string|max:255', 'favourite' => 'nullable|boolean']);
        try { if (array_key_exists('favourite', $d) && $d['favourite'] !== null) \App\Services\Guide::favourite($r->user(), $d['title'], $d['url'], (bool) $d['favourite']); else \App\Services\Guide::visited($r->user(), $d['title'], $d['url']); }
        catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); }
        return \App\Services\Guide::nav($r->user());
    }

    // ---- bulk actions: preview → apply → Undo ----
    public function bulkActions(Request $r, string $key) { Rights::check('VIEW', $key); return \App\Services\BulkJobs::actions($key, $r->user()); }

    public function bulk(Request $r, string $key)
    {
        $d = $r->validate(['action' => 'required|in:SET,DELETE,RESTORE', 'ids' => 'present|array', 'ids.*' => 'integer', 'field' => 'nullable|string|max:80', 'value' => 'nullable', 'apply' => 'nullable|boolean']);
        try {
            return ! empty($d['apply']) ? \App\Services\BulkJobs::apply($key, $r->user(), $d['action'], $d['ids'], $d['field'] ?? null, $d['value'] ?? null)
                : \App\Services\BulkJobs::preview($key, $r->user(), $d['action'], $d['ids'], $d['field'] ?? null, $d['value'] ?? null);
        } catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); }
    }

    public function bulkUndo(Request $r, int $id)
    {
        try { return \App\Services\BulkJobs::undo($id, $r->user()); } catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); }
    }

    public function bulkHistory(Request $r) { return ['jobs' => \App\Services\BulkJobs::history($r->user())]; }

    // ---- the bell ----
    public function bell(Request $r) { return \App\Services\Notify::bell($r->user()); }
    public function bellRead(Request $r) { return \App\Services\Notify::read($r->user(), $r->input('id') ? (int) $r->input('id') : null); }
}
