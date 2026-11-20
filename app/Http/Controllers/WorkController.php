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

    public function tasks(Request $r)
    {
        $u = $r->user();
        $q = DB::table('tasks as t')->leftJoin('t_users as u', 'u.id', '=', 't.owner_user_id')->leftJoin('task_categories as c', 'c.id', '=', 't.category_id')
            ->where(fn ($w) => $w->where('t.fk_id_ent', Context::ent())->orWhere('t.fk_id_ent', 0)->orWhereNull('t.fk_id_ent'));
        $scope = $r->query('scope', 'mine');
        if ($scope === 'mine' || ! \App\Services\Perm::has($u, 'work.tasks_may')) $q->where('t.owner_user_id', $u->id);
        if ($r->query('status', 'open') === 'open') $q->whereNotIn('t.status', ['DONE', 'APPROVED', 'CANCELLED']);
        if ($s = $r->query('q')) $q->where('t.title', 'like', "%$s%");
        $rows = $q->orderByRaw("FIELD(t.priority,'URGENT','HIGH','NORMAL','LOW')")->orderBy('t.due_at')->limit(300)
            ->get(['t.id', 't.title', 't.status', 't.priority', 't.due_at', 't.rule_code', 't.source', 't.fix_url', 't.linked_module', 't.linked_record_id', 't.amount_gbp', 'u.display_name as owner', 'c.name as category']);
        $today = date('Y-m-d');
        return ['tasks' => $rows, 'counts' => ['open' => $rows->count(), 'late' => $rows->filter(fn ($t) => $t->due_at < $today)->count(), 'urgent' => $rows->where('priority', 'URGENT')->count()]];
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
        DB::table('tasks')->where('id', $id)->update(['status' => $data['status'], 'last_update_at' => now(), 'done_at' => $data['status'] === 'DONE' ? now() : null,
            'stuck_since' => $data['status'] === 'STUCK' ? now() : null, 'stuck_on_text' => $data['status'] === 'STUCK' ? $data['note'] : null]);
        DB::table('task_history')->insert(['task_id' => $id, 'at' => now(), 'user_id' => $u->id, 'field' => 'status', 'old_value' => $t->status, 'new_value' => $data['status'], 'reason' => $data['note'] ?? null]);
        Audit::log('tasks', $id, 'UPDATE', ['status' => [$t->status, $data['status']]]);
        return ['ok' => true];
    }
}
