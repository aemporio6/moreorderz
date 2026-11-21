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

    public function alerts(Request $r) { return \App\Services\AlertRules::page($r->user()); }
    public function alertRule(Request $r, string $code) { return $this->alertTry(fn () => \App\Services\AlertRules::save($r->user(), $code, $r->only(['is_active', 'channels']))); }
    public function alertText(Request $r, string $key, string $lang) { return $this->alertTry(fn () => \App\Services\AlertRules::saveText($r->user(), $key, $lang, (string) $r->input('body'), $r->input('subject'))); }
    public function alertThreshold(Request $r, string $key) { return $this->alertTry(fn () => \App\Services\AlertRules::setThreshold($r->user(), $key, (string) $r->input('value'))); }
    private function alertTry(\Closure $f) { try { return $f(); } catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); } }
    public function due(Request $r) { return \App\Services\DueEngine::list((int) Context::ent(), $r->user(), $r->only(['chip', 'status'])); }
    public function dueDone(Request $r, int $id) { try { return \App\Services\DueEngine::done((int) Context::ent(), $id, $r->user()); } catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); } }

    public function tasks(Request $r)
    {
        $u = $r->user();
        $q = DB::table('tasks as t')->leftJoin('t_users as u', 'u.id', '=', 't.owner_user_id')->leftJoin('task_categories as c', 'c.id', '=', 't.category_id')
            ->where(fn ($w) => $w->where('t.fk_id_ent', Context::ent())->orWhere('t.fk_id_ent', 0)->orWhereNull('t.fk_id_ent'));
        $scope = $r->query('scope', 'mine');
        if ($scope === 'to_approve') $q->where('t.status', 'DONE');      // waiting approval (R-G_CORE-7): filtered below to the ones this person may approve
        elseif ($scope === 'stuck_on_me') $q->where('t.status', 'STUCK')->where('t.stuck_on_type', 'USER')->where('t.stuck_on_id', $u->id);   // tasks of others that wait on this person
        elseif ($scope === 'mine' || ! \App\Services\Perm::has($u, 'work.tasks_may')) $q->where('t.owner_user_id', $u->id);
        if ($r->query('status', 'open') === 'open' && $scope !== 'to_approve') $q->whereNotIn('t.status', ['DONE', 'APPROVED', 'CANCELLED']);
        if ($s = $r->query('q')) $q->where('t.title', 'like', "%$s%");
        $rows = $q->orderByRaw("FIELD(t.priority,'URGENT','HIGH','NORMAL','LOW')")->orderBy('t.due_at')->limit(300)
            ->get(['t.id', 't.title', 't.status', 't.priority', 't.due_at', 't.rule_code', 't.source', 't.fix_url', 't.linked_module', 't.linked_record_id', 't.amount_gbp', 't.stuck_on_type', 't.stuck_on_text', 't.stuck_since', 'u.display_name as owner', 'c.name as category', 't.owner_user_id', 't.assigned_by_user_id', 't.rework_count', 't.score', 't.evidence_required']);
        if ($scope === 'to_approve') $rows = $rows->filter(fn ($t) => $t->source !== 'RULE' && \App\Services\TaskScore::refusal($t, $u) === null)->values();
        $why = DB::table('task_history')->whereIn('task_id', $rows->pluck('id')->all() ?: [0])->where('new_value', 'CREATED')->where('reason', 'like', '%owner by%')->pluck('reason', 'task_id');      // why this owner (R-G_CORE-94)
        $rows = $rows->map(fn ($t) => (array) $t + ['actions' => \App\Services\TaskFlow::actionsFor($t, $u), 'missing' => in_array($t->status, ['DOING', 'STUCK'], true) ? array_column(\App\Services\TaskFlow::missing($t), 'text') : [], 'label' => \App\Services\TaskFlow::LABELS[$t->status] ?? $t->status, 'ref' => \App\Services\TaskFlow::ref((int) $t->id), 'owner_why' => isset($why[$t->id]) ? ucfirst(trim(substr($why[$t->id], strpos($why[$t->id], 'owner by') + 9))) : null, 'may_approve' => $t->status === 'DONE' && $t->source !== 'RULE' && \App\Services\TaskScore::refusal($t, $u) === null, 'preview' => $t->status === 'DONE' ? \App\Services\TaskScore::parts($t, 'GOOD') : null, 'band' => $t->score !== null ? \App\Services\TaskScore::band((float) $t->score) : null]);
        $today = date('Y-m-d');
        $toApprove = DB::table('tasks as t')->where('t.status', 'DONE')->where('t.source', '<>', 'RULE')->where(fn ($w) => $w->where('t.fk_id_ent', Context::ent())->orWhereNull('t.fk_id_ent'))->get(['t.id', 't.status', 't.owner_user_id', 't.assigned_by_user_id'])->filter(fn ($t) => \App\Services\TaskScore::refusal($t, $u) === null)->count();
        return ['tasks' => $rows, 'to_approve' => $toApprove, 'my_score' => \App\Services\TaskScore::person((int) $u->id), 'qualities' => array_keys(\App\Services\TaskScore::QUALITY), 'stuck_on_me' => DB::table('tasks')->where('status', 'STUCK')->where('stuck_on_type', 'USER')->where('stuck_on_id', $u->id)->count(),
            'stuck_on' => \App\Services\Guide::STUCK_ON, 'people' => DB::table('t_users')->where('f_active', 1)->where('id', '<>', $u->id)->orderBy('display_name')->get(['id', DB::raw('COALESCE(display_name, login_id) as name')]),
            'counts' => ['open' => $rows->count(), 'late' => $rows->filter(fn ($t) => $t['due_at'] < $today)->count(), 'urgent' => $rows->where('priority', 'URGENT')->count()]];
    }

    public function taskStatus(Request $r, int $id)
    {
        $data = $r->validate(['status' => 'required|in:TODO,DOING,STUCK,DONE,CANCELLED', 'note' => 'nullable|string|max:500']);
        try {
            $t = $data['status'] === 'CANCELLED' ? \App\Services\TaskFlow::cancel($id, $r->user(), (string) ($data['note'] ?? '')) : \App\Services\TaskFlow::move($id, $r->user(), $data['status'], $data['note'] ?? null);      // one state table (R-G_CORE-1, done gate R-G_CORE-5)
            return ['ok' => true, 'status' => $t->status, 'label' => \App\Services\TaskFlow::LABELS[$t->status]];
        } catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage(), 'errors' => ['status' => [$e->getMessage()]]], 422); }
    }

    /** New task form (R-G_CORE-2): SLA prefill, pick-only record link, dependency by name, absence cover, weekly-limit warning. */
    public function taskForm(Request $r) { Rights::check('ADD', 'task'); return \App\Services\TaskCreate::form((int) Context::ent()); }
    public function taskPrefill(Request $r) { Rights::check('ADD', 'task'); try { return \App\Services\TaskCreate::prefill($r->integer('category')); } catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); } }
    public function taskRecords(Request $r) { Rights::check('ADD', 'task'); try { return ['rows' => \App\Services\TaskCreate::records((int) Context::ent(), (string) $r->query('module', ''), (string) $r->query('q', ''))]; } catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); } }
    public function taskFind(Request $r) { Rights::check('ADD', 'task'); return ['rows' => \App\Services\TaskCreate::tasks((int) Context::ent(), (string) $r->query('q', ''))]; }
    public function taskCreate(Request $r) { Rights::check('ADD', 'task'); try { return \App\Services\TaskCreate::create((int) Context::ent(), $r->all(), $r->user()); } catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage(), 'errors' => ['_form' => [$e->getMessage()]]], 422); } }

    /** Approve / send back (R-G_CORE-7) and the score preview (R-G_CORE-8). */
    public function taskApprove(Request $r, int $id) { try { return \App\Services\TaskScore::approve($id, $r->user(), $r->input('quality')); } catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); } }
    public function taskRework(Request $r, int $id) { try { return \App\Services\TaskScore::rework($id, $r->user(), (string) $r->input('reason')); } catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); } }
    public function taskScore(Request $r, int $id) { $t = DB::table('tasks')->where('id', $id)->first() ?? abort(404); return ['preview' => \App\Services\TaskScore::parts($t, strtoupper((string) $r->query('quality', 'GOOD'))), 'frozen' => $t->score]; }

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

    /** R-D-68: move the records that use a list item to another item (then it can be deleted). GET = the other items. */
    public function bulkReassign(Request $r, string $key)
    {
        try {
            if ($r->isMethod('get')) return ['targets' => \App\Services\ListDefaults::targets($key, (int) $r->query('from'))];
            $d = $r->validate(['from' => 'required|integer', 'to' => 'required|integer']);
            return \App\Services\ListDefaults::reassign($key, (int) $d['from'], (int) $d['to'], $r->user());
        } catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); }
    }

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
