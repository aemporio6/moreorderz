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
    public function alertRule(Request $r, string $code) { return $this->alertTry(fn () => \App\Services\AlertRules::save($r->user(), $code, $r->only(['is_active', 'channels', 'wait_workday']))); }
    public function alertText(Request $r, string $key, string $lang) { return $this->alertTry(fn () => \App\Services\AlertRules::saveText($r->user(), $key, $lang, (string) $r->input('body'), $r->input('subject'))); }
    public function alertThreshold(Request $r, string $key) { return $this->alertTry(fn () => \App\Services\AlertRules::setThreshold($r->user(), $key, (string) $r->input('value'))); }
    private function alertTry(\Closure $f) { try { return $f(); } catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); } }
    public function due(Request $r) { return \App\Services\DueEngine::list((int) Context::ent(), $r->user(), $r->only(['chip', 'status'])); }
    public function dueDone(Request $r, int $id) { try { return \App\Services\DueEngine::done((int) Context::ent(), $id, $r->user()); } catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); } }

    /** Tasks: scope mine (R-G_CORE-36), all (R-G_CORE-37 chips / filters / pages), stuck_on_me, to_approve. */
    public function tasks(Request $r)
    {
        $u = $r->user(); $scope = $r->query('scope', 'mine'); $L = \App\Services\TaskLists::class;
        $f = $r->only(['person', 'category', 'status', 'source', 'marketplace', 'product', 'shop', 'q']);
        if ($scope === 'mine') $d = $L::mine($u);
        elseif ($scope === 'all') $d = $L::all($u, (string) $r->query('chip', 'open'), $f, $r->integer('page') ?: 1);
        else {
            $q = $L::visible($L::base(), $u);      // R-G_CORE-19
            if ($scope === 'to_approve') $q->where('t.status', 'DONE');      // waiting approval (R-G_CORE-7): filtered below to the ones this person may approve
            else $q->where('t.status', 'STUCK')->where('t.stuck_on_type', 'USER')->where('t.stuck_on_id', $u->id);      // stuck_on_me: tasks of others that wait on this person
            $rows = $q->orderByRaw("FIELD(t.priority,'URGENT','HIGH','NORMAL','LOW')")->orderBy('t.due_at')->limit(300)->get(['t.*', 'u.display_name as owner', 'c.name as category']);
            if ($scope === 'to_approve') $rows = $rows->filter(fn ($t) => $t->source !== 'RULE' && \App\Services\TaskScore::refusal($t, $u) === null)->values();
            $d = ['tasks' => $L::decorate($rows, $u)];
        }
        $toApprove = DB::table('tasks as t')->where('t.status', 'DONE')->where('t.source', '<>', 'RULE')->where(fn ($w) => $w->where('t.fk_id_ent', Context::ent())->orWhereNull('t.fk_id_ent'))->get(['t.id', 't.status', 't.owner_user_id', 't.assigned_by_user_id'])->filter(fn ($t) => \App\Services\TaskScore::refusal($t, $u) === null)->count();
        return $d + ['scope' => $scope, 'to_approve' => $toApprove, 'my_score' => \App\Services\TaskScore::person((int) $u->id), 'qualities' => array_keys(\App\Services\TaskScore::QUALITY), 'stuck_on_me' => DB::table('tasks')->where('status', 'STUCK')->where('stuck_on_type', 'USER')->where('stuck_on_id', $u->id)->count(),
            'stuck_on' => \App\Services\Guide::STUCK_ON, 'people' => DB::table('t_users')->where('f_active', 1)->where('id', '<>', $u->id)->orderBy('display_name')->get(['id', DB::raw('COALESCE(display_name, login_id) as name')]),
            'snoozes' => in_array($u->role_code, ['OWNER', 'PARTNER', 'MANAGER'], true) ? \App\Services\TaskActions::snoozes((int) Context::ent()) : [], 'bulk' => in_array($u->role_code, \App\Services\TaskScore::BOSS, true) ? \App\Services\TaskBulk::ACTIONS : null, 'can_end_snooze' => in_array($u->role_code, ['OWNER', 'PARTNER'], true),
            'shops' => DB::table('t_branches')->where('fk_id_ent', Context::ent())->where('f_active', 1)->orderBy('shop_name')->get(['id', DB::raw("TRIM(CONCAT_WS(' ', shop_no, shop_name)) as name")]),
            'boss' => $L::boss($u), 'import_tell' => \App\Services\Choices::get('TASK_IMPORT_TELL', (int) Context::ent()) === '1', 'all_people' => DB::table('t_users')->where('f_active', 1)->orderBy('display_name')->get(['id', DB::raw('COALESCE(display_name, login_id) as name')])];
    }

    /** R-G_CORE-38 dashboard, R-G_CORE-39 needs attention, R-G_CORE-37 export. */
    public function tasksDashboard(Request $r) { return $this->act(fn () => \App\Services\TaskLists::dashboard($r->user(), $r->only(['person', 'shop']))); }
    public function tasksAttention(Request $r) { return $this->act(fn () => \App\Services\TaskLists::attention($r->user(), $r->integer('page') ?: 1, 10, $r->only(['person', 'shop']))); }
    public function tasksExport(Request $r)
    {
        [$name, $csv] = \App\Services\TaskLists::export($r->user(), (string) $r->query('chip', 'open'), $r->only(['person', 'category', 'status', 'source', 'marketplace', 'product', 'shop', 'q']));
        return response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="' . $name . '"']);
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
    public function taskForm(Request $r) { Rights::check('ADD', 'task'); return \App\Services\TaskCreate::form((int) Context::ent(), $r->user()); }
    public function taskPrefill(Request $r) { Rights::check('ADD', 'task'); try { return \App\Services\TaskCreate::prefill($r->integer('category')); } catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); } }
    public function taskRecords(Request $r) { Rights::check('ADD', 'task'); try { return ['rows' => \App\Services\TaskCreate::records((int) Context::ent(), (string) $r->query('module', ''), (string) $r->query('q', ''))]; } catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); } }
    public function taskFind(Request $r) { Rights::check('ADD', 'task'); return ['rows' => \App\Services\TaskCreate::tasks((int) Context::ent(), (string) $r->query('q', ''))]; }
    public function taskCreate(Request $r) { Rights::check('ADD', 'task'); try { return \App\Services\TaskCreate::create((int) Context::ent(), $r->all(), $r->user()); } catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage(), 'errors' => ['_form' => [$e->getMessage()]]], 422); } }

    /** Task actions (R-G_CORE-10 … 15): acknowledge, waits for, subtasks, due date, reassign, undo cancel, hide evidence. */
    private function act(callable $fn) { try { $r = $fn(); return is_array($r) ? ['ok' => true] + $r : ['ok' => true, 'message' => $r]; } catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage(), 'errors' => ['task' => [$e->getMessage()]]], 422); } }
    public function taskPanel(Request $r, int $id) { return \App\Services\TaskActions::panel($id, $r->user()); }
    public function taskAck(Request $r, int $id) { return $this->act(fn () => \App\Services\TaskActions::acknowledge($id, $r->user(), $r->input('via') === 'PHONE' ? 'PHONE' : 'APP')); }
    public function taskWaits(Request $r, int $id) { return $this->act(fn () => \App\Services\TaskActions::setDependency($id, $r->user(), $r->integer('depends_on') ?: null)); }
    public function taskDue(Request $r, int $id) { return $this->act(fn () => \App\Services\TaskActions::moveDue($id, $r->user(), (string) $r->input('date'), (string) $r->input('reason'))); }
    public function taskDueRequest(Request $r, int $id) { return $this->act(fn () => \App\Services\TaskActions::requestDue($id, $r->user(), (string) $r->input('date'), (string) $r->input('reason'))); }
    public function taskDueDecide(Request $r, int $id) { return $this->act(fn () => \App\Services\TaskActions::decideDue($id, $r->user(), (bool) $r->boolean('approve'), $r->input('reason'))); }
    public function taskReassign(Request $r, int $id) { return $this->act(fn () => \App\Services\TaskActions::reassign($id, $r->user(), $r->integer('owner'), (string) $r->input('reason'))); }
    public function taskUndo(Request $r, int $id) { return $this->act(fn () => \App\Services\TaskActions::undoCancel($id, $r->user())); }
    public function taskHideEvidence(Request $r, int $id) { return $this->act(fn () => \App\Services\TaskActions::hideEvidence($id, $r->user(), (string) $r->input('reason'))); }

    /** R-G_CORE-32 reminder, R-G_CORE-97 close a rule task with a reason / end snooze, R-G_CORE-33 bulk. */
    public function taskRemind(Request $r, int $id) { return $this->act(fn () => \App\Services\TaskActions::remind($id, $r->user())); }
    public function taskCloseRule(Request $r, int $id) { return $this->act(fn () => \App\Services\TaskActions::closeRule($id, $r->user(), (string) $r->input('reason'))); }
    public function taskEndSnooze(Request $r, int $id) { return $this->act(fn () => \App\Services\TaskActions::endSnooze($id, $r->user())); }
    public function taskBulk(Request $r) { $in = $r->only(['owner', 'date', 'reason']); return $this->act(fn () => $r->boolean('apply') ? \App\Services\TaskBulk::apply($r->user(), (string) $r->input('action'), (array) $r->input('ids', []), $in) : \App\Services\TaskBulk::preview($r->user(), (string) $r->input('action'), (array) $r->input('ids', []), $in)); }
    public function taskDigestPreview(Request $r) { return $this->act(fn () => \App\Services\TaskDigest::preview($r->user(), $r->integer('user') ?: (int) $r->user()->id)); }      // R-G_CORE-45
    /** R-G_CORE-54 message log (bosses), CSV, and the Messages tab of a task / record. */
    public function messageLog(Request $r) { if (! \App\Services\MessageLog::boss($r->user())) abort(403, 'Only a Partner or Manager sees the whole message log.'); return \App\Services\MessageLog::rows($r->user(), $r->only(['person', 'channel', 'task', 'status', 'direction']), 25, $r->integer('page') ?: 1) + ['people' => DB::table('t_users')->where('f_active', 1)->orderBy('display_name')->get(['id', 'display_name as name'])]; }
    public function messageLogCsv(Request $r) { try { $csv = \App\Services\MessageLog::csv($r->user(), $r->only(['person', 'channel', 'task', 'status', 'direction'])); } catch (\InvalidArgumentException $e) { abort(403, $e->getMessage()); }
        return response($csv, 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="message_log_' . date('Ymd_Hi') . '.csv"']); }
    public function taskMessages(Request $r, int $id) { return \App\Services\MessageLog::forTask($id, $r->user(), $r->integer('page') ?: 1); }
    public function recordMessages(Request $r, string $key, int $id) { Rights::check('VIEW', $key); return \App\Services\MessageLog::forRecord((string) \App\Services\Registry::table($key), $id, $r->user(), $r->integer('page') ?: 1); }
    /** R-G_CORE-41 task page, R-G_CORE-6 proof switch, checklist / proof / comments on the web, R-G_CORE-34 task CSV import. */
    public function taskPage(Request $r, int $id) { return \App\Services\TaskActions::page($id, $r->user()); }
    public function taskProofOff(Request $r, int $id) { return $this->act(fn () => \App\Services\TaskActions::proofOff($id, $r->user(), (string) $r->input('reason'))); }
    public function taskProofOn(Request $r, int $id) { return $this->act(fn () => \App\Services\TaskActions::proofOn($id, $r->user())); }
    public function taskComment(Request $r, int $id) { return $this->act(fn () => \App\Services\TaskComments::add($id, $r->user(), (string) $r->input('text'), $r->file('file'))); }
    public function taskEvidenceFile(Request $r, int $id) { return \App\Services\TaskEvidence::stream($id, $r->user()); }
    public function taskProof(Request $r, int $id) { return $this->act(fn () => \App\Services\TaskActions::addProof($id, $r->user(), $r->file('file'), $r->input('link'), $r->input('reference'), $r->input('note'), $r->input('kind'))); }
    public function taskTemplates(Request $r) { return \App\Services\TaskTemplates::page($r->user()); }
    public function taskTemplateCreate(Request $r, int $id) { return $this->act(fn () => \App\Services\TaskTemplates::create($id, $r->user(), ['record' => $r->input('record'), 'reference' => $r->input('reference'), 'start' => $r->input('start')])); }
    public function taskHistory(Request $r, int $id)
    {
        $t = DB::table('tasks')->where('id', $id)->first(); if (! $t || ! \App\Services\TaskFlow::canSee($t, $r->user())) return response()->json(['message' => 'Task not found.'], 404);
        return \App\Services\TaskHistory::rows($id, $r->user(), true);      // R-G_CORE-30 "Show all n changes"
    }
    public function tasksGuide() { return \App\Services\TaskGuide::page(); }      // R-G_CORE-42
    public function taskItem(Request $r, int $id) { return $this->act(fn () => \App\Services\TaskActions::addItem($id, $r->user(), (string) $r->input('text'))); }
    public function taskTick(Request $r, int $id) { return $this->act(fn () => \App\Services\TaskActions::tick($id, $r->user(), $r->boolean('done'))); }
    public function taskImportTemplate() { return response(\App\Services\TaskImport::template(), 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="tasks_import_template.csv"']); }
    public function taskImport(Request $r)
    {
        $f = $r->file('file'); if (! $f) return response()->json(['message' => 'Choose the CSV file first.'], 422);
        return $this->act(fn () => \App\Services\TaskImport::run($r->user(), $f->getClientOriginalName(), (string) file_get_contents($f->getRealPath()), $r->boolean('apply'), $r->boolean('notify')));
    }
    public function taskImportUndo(Request $r, int $id) { return $this->act(fn () => \App\Services\TaskImport::undo($id, $r->user())); }
    /** R-G_CORE-40 Team page + leaderboard, R-G_CORE-44 weekly report screen, R-G_CORE-26 a suggested recurring task. */
    public function tasksTeam(Request $r) { return $this->act(fn () => \App\Services\TaskTeam::page($r->user(), $r->query('month'))); }
    public function tasksWeekly(Request $r)
    {
        if (! \App\Services\TaskLists::boss($r->user())) abort(403, 'The weekly report is for a Partner or Manager.');
        $mon = (string) $r->query('week', '') ?: \App\Services\TaskWeekly::lastWeek(new \DateTimeImmutable())[0];
        $mon = date('Y-m-d', strtotime('monday this week', strtotime($mon)));
        $d = \App\Services\TaskWeekly::data($mon); return $d + ['html' => \App\Services\TaskWeekly::html($d)];
    }
    /** R-G_CORE-16 / 17 "End now", the proposed delegation for an absent Partner. */
    public function absenceEnd(Request $r, int $id) { return $this->act(fn () => \App\Services\TaskSettings::endAbsence($id, $r->user())); }
    public function delegationEnd(Request $r, int $id) { return $this->act(fn () => \App\Services\TaskSettings::endDelegation($id, $r->user())); }
    public function delegationPropose(Request $r)
    {
        return $this->act(function () use ($r) {
            $d = ['from_user_id' => $r->integer('from_user_id'), 'to_user_id' => $r->integer('to_user_id'), 'from_date' => (string) $r->input('from_date'), 'to_date' => (string) $r->input('to_date'), 'rights' => 'APPROVE'];
            if ($e = \App\Services\TaskSettings::delegation($d, [], null)) throw new \InvalidArgumentException(reset($e));
            $id = DB::table('tm_delegations')->insertGetId($d + ['created_by' => $r->user()->id, 'created_at' => now()]); \App\Services\Audit::log('tm_delegations', $id, 'INSERT', $d);
            return 'Delegation added.';
        });
    }
    public function taskBulkUndo(Request $r, int $id) { return $this->act(fn () => \App\Services\TaskBulk::undo($id, $r->user())); }

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
