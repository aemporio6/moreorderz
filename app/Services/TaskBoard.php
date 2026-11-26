<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-G_CORE-104 Calendar and Kanban views of the tasks (and the due items on the calendar).
 * Kanban: columns To do / In progress / Done / Approved – a drag is the same move as the buttons (TaskFlow::move, TaskScore::approve), with the same rights and checks.
 * Calendar: tasks by due date, due items by date; dragging a task to another day = the same "move the due date" as the button (reason, rights, history);
 * a due item cannot be dragged – it moves only when its source date moves. The view choice is kept per person.
 */
class TaskBoard
{
    public const COLUMNS = ['TODO' => ['To do', ['TODO', 'REWORK']], 'IN_PROGRESS' => ['In progress', ['DOING', 'STUCK']], 'DONE' => ['Done – waiting approval', ['DONE']], 'APPROVED' => ['Approved', ['APPROVED']]];
    private const PREF = 'tasks.board';

    public static function view(object $u): string { $v = json_decode((string) DB::table('t_user_column_prefs')->where('fk_id_user', $u->id)->where('page_key', self::PREF)->value('prefs_json'), true)['view'] ?? 'kanban'; return in_array($v, ['kanban', 'calendar'], true) ? $v : 'kanban'; }

    public static function setView(object $u, string $view): array
    {
        if (! in_array($view, ['kanban', 'calendar'], true)) throw new \InvalidArgumentException('Unknown view.');
        DB::table('t_user_column_prefs')->updateOrInsert(['fk_id_user' => $u->id, 'page_key' => self::PREF], ['prefs_json' => json_encode(['view' => $view]), 'hidden_columns' => '', 'updated_at' => now()]);
        return ['view' => $view];
    }

    public static function data(object $u, ?string $ym = null): array
    {
        $ym = $ym && preg_match('/^\d{4}-\d{2}$/', $ym) ? $ym : substr(TaskHealth::today(), 0, 7); $from = "$ym-01"; $to = date('Y-m-t', strtotime($from));
        $L = TaskLists::class; $cols = ['t.*', 'u.display_name as owner', 'c.name as category'];
        $open = $L::visible($L::base(), $u)->whereIn('t.status', ['TODO', 'REWORK', 'DOING', 'STUCK', 'DONE'])->orderByRaw("FIELD(t.priority,'URGENT','HIGH','NORMAL','LOW')")->orderBy('t.due_at')->limit(400)->get($cols);
        $appr = $L::visible($L::base(), $u)->where('t.status', 'APPROVED')->where('t.last_update_at', '>=', date('Y-m-d', strtotime('-14 days')))->orderByDesc('t.last_update_at')->limit(60)->get($cols);
        $card = fn ($t) => ['id' => (int) $t['id'], 'title' => $t['title'], 'status' => $t['status'], 'priority' => $t['priority'], 'owner' => $t['owner'] ?? null, 'due' => substr((string) $t['due_at'], 0, 10), 'health' => $t['health'] ?? null, 'rule' => $t['rule_code'] ?? null, 'actions' => $t['actions'] ?? []];
        $all = array_map(fn ($t) => $card((array) $t), $L::decorate($open->concat($appr), $u)); $kan = [];
        foreach (self::COLUMNS as $k => [$label, $st]) $kan[] = ['key' => $k, 'label' => $label, 'cards' => array_values(array_filter($all, fn ($c) => in_array($c['status'], $st, true)))];
        $inMonth = $L::decorate($L::visible($L::base(), $u)->whereNotIn('t.status', ['CANCELLED'])->whereBetween(DB::raw('DATE(t.due_at)'), [$from, $to])->orderBy('t.due_at')->limit(600)->get($cols), $u);
        $due = array_values(array_filter(DueEngine::list((int) Context::ent(), $u)['rows'], fn ($d) => $d->due_date >= $from && $d->due_date <= $to));
        return ['view' => self::view($u), 'ym' => $ym, 'today' => TaskHealth::today(), 'kanban' => $kan, 'limited' => $open->count() >= 400,
            'calendar' => ['tasks' => array_map(fn ($t) => $card((array) $t), $inMonth), 'due_items' => array_map(fn ($d) => ['id' => (int) $d->id, 'title' => $d->title, 'date' => $d->due_date, 'chip' => $d->chip, 'status' => $d->status], $due)]];
    }

    /** A card dropped on a column: the same move as the buttons. */
    public static function drop(int $id, string $column, object $u, ?string $note = null, ?string $quality = null): array
    {
        if (! isset(self::COLUMNS[$column])) throw new \InvalidArgumentException('Unknown column.');
        $t = DB::table('tasks')->where('id', $id)->first() ?? throw new \InvalidArgumentException('Task not found.');
        if (in_array($t->status, self::COLUMNS[$column][1], true)) return ['status' => $t->status, 'message' => 'Already there.'];
        if ($column === 'APPROVED') { TaskScore::approve($id, $u, $quality); return ['status' => 'APPROVED', 'message' => 'Approved.']; }
        $m = TaskFlow::move($id, $u, ['TODO' => 'TODO', 'IN_PROGRESS' => 'DOING', 'DONE' => 'DONE'][$column], $note);
        return ['status' => $m->status, 'message' => TaskFlow::LABELS[$m->status] ?? $m->status];
    }
}
