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

    public function page(string $key, int $id)
    {
        Rights::check('VIEW', $key);
        return RecordService::page($key, $id);
    }

    public function export(Request $r, string $key)
    {
        abort_unless(Rights::can(Context::role(), 'EXPORT', $key), 403, 'Your role cannot export this list.');
        [$head, $rows] = RecordService::export($key, $r->only(['q', 'sort', 'dir', 'filter', 'cols']));
        return response()->streamDownload(function () use ($head, $rows) {
            $o = fopen('php://output', 'w');
            fwrite($o, "\xEF\xBB\xBF");
            fputcsv($o, $head);
            foreach ($rows as $row) fputcsv($o, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v, $row));   // no formula injection in spreadsheets
            fclose($o);
        }, $key . '_' . date('Ymd_Hi') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function tasks(Request $r)
    {
        $u = $r->user();
        $q = DB::table('tasks as t')->leftJoin('t_users as u', 'u.id', '=', 't.owner_user_id')->leftJoin('task_categories as c', 'c.id', '=', 't.category_id')
            ->where(fn ($w) => $w->where('t.fk_id_ent', Context::ent())->orWhere('t.fk_id_ent', 0)->orWhereNull('t.fk_id_ent'));
        $scope = $r->query('scope', 'mine');
        if ($scope === 'mine' || ! in_array($u->role_code, ['OWNER', 'MANAGER', 'TL'], true)) $q->where('t.owner_user_id', $u->id);
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
        abort_unless((int) $t->owner_user_id === $u->id || in_array($u->role_code, ['OWNER', 'MANAGER', 'TL'], true), 403, 'This task belongs to someone else.');
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
