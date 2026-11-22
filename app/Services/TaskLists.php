<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * The task lists: R-G_CORE-36 My tasks (tiles, Mine in the rule's order, Waiting on me: to approve / stuck on me / covering / helper on, score card),
 * R-G_CORE-37 All tasks (chips with live counts, filters, 25 a page, the rule's columns and order, CSV export of 17 columns + amount for Partners),
 * R-G_CORE-38 Tasks dashboard (tiles, per person, by module, data-quality rule tasks) and R-G_CORE-39 "Needs attention" ranking with the why texts.
 */
class TaskLists
{
    public const OPEN = ['TODO', 'DOING', 'STUCK', 'REWORK'];
    public const CHIPS = ['open' => 'Open', 'late' => 'Late', 'stuck' => 'Stuck', 'today' => 'Due today', 'waiting' => 'Waiting approval', 'nodue' => 'No due date', 'closed' => 'Closed', 'all' => 'All'];
    public const SOURCES = ['MANUAL' => 'By a person', 'RULE' => 'Rule', 'REPEAT' => 'Repeat', 'TEMPLATE' => 'Template'];
    private const COLS = ['t.id', 't.title', 't.status', 't.priority', 't.due_at', 't.depends_on_task_id', 't.due_changes', 't.flags', 't.parent_task_id', 't.rule_code', 't.source', 't.fix_url', 't.linked_module', 't.linked_record_id',
        't.reference_id', 't.amount_gbp', 't.stuck_on_type', 't.stuck_on_id', 't.stuck_on_text', 't.stuck_since', 'u.display_name as owner', 'c.name as category', 't.category_id', 't.owner_user_id', 't.assigned_by_user_id', 't.rework_count', 't.score',
        't.evidence_required', 't.acknowledged_at', 't.created_at', 't.last_update_at', 't.done_at'];

    public static function base()
    {
        return DB::table('tasks as t')->leftJoin('t_users as u', 'u.id', '=', 't.owner_user_id')->leftJoin('task_categories as c', 'c.id', '=', 't.category_id')
            ->where(fn ($w) => $w->where('t.fk_id_ent', Context::ent())->orWhere('t.fk_id_ent', 0)->orWhereNull('t.fk_id_ent'));
    }

    public static function boss(object $u): bool { return in_array($u->role_code, TaskScore::BOSS, true); }
    public static function partner(object $u): bool { return in_array($u->role_code, ['OWNER', 'PARTNER'], true); }

    /** Only the tasks this person may see: everything with "see the tasks of everyone", else his own, assigned, helper, watcher or stuck on him. */
    public static function visible($q, object $u) { return TaskVisibility::apply($q, $u); }      // R-G_CORE-19

    /** The row as every list shows it (health, the person's buttons, missing items, proof). */
    public static function decorate($rows, object $u): array
    {
        $rows = collect($rows); $ids = $rows->pluck('id')->all() ?: [0]; $today = TaskHealth::today();
        $why = DB::table('task_history')->whereIn('task_id', $ids)->where('new_value', 'CREATED')->where('reason', 'like', '%owner by%')->pluck('reason', 'task_id');      // why this owner (R-G_CORE-94)
        $ev = DB::table('task_evidence')->whereIn('task_id', $ids)->whereNull('hidden_at')->whereIn('type', TaskFlow::COUNTING)->groupBy('task_id')->selectRaw('task_id, COUNT(*) n')->pluck('n', 'task_id');
        $help = DB::table('task_helpers as h')->join('t_users as x', 'x.id', '=', 'h.user_id')->whereIn('h.task_id', $ids)->get(['h.task_id', 'x.display_name'])->groupBy('task_id');
        $partner = self::partner($u);
        return $rows->map(function ($t) use ($u, $why, $ev, $help, $today, $partner) {
            $n = (int) ($ev[$t->id] ?? 0);
            $a = (array) $t + ['health' => TaskHealth::of($t, $today), 'actions' => TaskFlow::actionsFor($t, $u), 'missing' => in_array($t->status, ['DOING', 'STUCK'], true) ? array_column(TaskFlow::missing($t), 'text') : [],
                'label' => TaskFlow::LABELS[$t->status] ?? $t->status, 'ref' => TaskFlow::ref((int) $t->id), 'owner_why' => isset($why[$t->id]) ? ucfirst(trim(substr($why[$t->id], strpos($why[$t->id], 'owner by') + 9))) : null,
                'may_approve' => $t->status === 'DONE' && $t->source !== 'RULE' && TaskScore::refusal($t, $u) === null, 'preview' => $t->status === 'DONE' ? TaskScore::parts($t, 'GOOD') : null,
                'band' => $t->score !== null ? TaskScore::band((float) $t->score) : null, 'proof' => $n ? "✓ $n file" . ($n === 1 ? '' : 's') : ((int) $t->evidence_required ? 'required' : 'not needed'), 'evidence_count' => $n,
                'helpers' => collect($help[$t->id] ?? [])->map(fn ($h) => strtok((string) $h->display_name, ' '))->values()->all(), 'source_label' => self::SOURCES[$t->source] ?? $t->source,
                'linked' => $t->linked_module ? $t->linked_module . ($t->linked_record_id ? ' #' . $t->linked_record_id : '') : null,
                'absent' => $t->owner_user_id && in_array($t->status, self::OPEN, true) && (bool) TaskOwner::absent((int) $t->owner_user_id)];      // R-G_CORE-16 "Absent" chip
            if (! $partner) unset($a['amount_gbp']);      // the amount column is for Partners only
            return $a;
        })->all();
    }

    /* ---------- R-G_CORE-36 My tasks ---------- */

    public static function mine(object $u): array
    {
        $today = TaskHealth::today();
        $mine = self::base()->where('t.owner_user_id', $u->id)->whereIn('t.status', self::OPEN)
            ->orderByRaw('CASE WHEN t.due_at IS NOT NULL AND DATE(t.due_at) < ? THEN 0 WHEN DATE(t.due_at) = ? THEN 1 ELSE 2 END', [$today, $today])->orderByRaw('t.due_at IS NULL')->orderBy('t.due_at')
            ->orderByRaw("FIELD(t.priority,'URGENT','HIGH','NORMAL','LOW')")->orderBy('t.id')->limit(300)->get(self::COLS);
        $approve = self::visible(self::base(), $u)->where('t.status', 'DONE')->where('t.source', '<>', 'RULE')->where('t.owner_user_id', '<>', $u->id)->orderBy('t.due_at')->get(self::COLS)->filter(fn ($t) => TaskScore::refusal($t, $u) === null)->values();
        $stuck = self::base()->where('t.status', 'STUCK')->where('t.stuck_on_type', 'USER')->where('t.stuck_on_id', $u->id)->orderBy('t.due_at')->get(self::COLS);
        $cover = self::visible(self::base(), $u)->whereIn('t.status', self::OPEN)->whereNotNull('t.owner_user_id')->where('t.owner_user_id', '<>', $u->id)->orderBy('t.due_at')->get(self::COLS)
            ->filter(fn ($t) => TaskOwner::absent((int) $t->owner_user_id) && (self::boss($u) || TaskOwner::usable((int) $t->owner_user_id) === (int) $u->id))->values();
        $helper = self::visible(self::base(), $u)->whereIn('t.status', self::OPEN)->whereIn('t.id', DB::table('task_helpers')->where('user_id', $u->id)->select('task_id'))->orderBy('t.due_at')->get(self::COLS);
        $late = $mine->filter(fn ($t) => $t->due_at && substr((string) $t->due_at, 0, 10) < $today)->count();
        $dueToday = $mine->filter(fn ($t) => substr((string) $t->due_at, 0, 10) === $today)->count();
        return ['tasks' => self::decorate($mine, $u), 'tiles' => ['open' => $mine->count(), 'late' => $late, 'due_today' => $dueToday, 'waiting_on_me' => $approve->count() + $stuck->count()],
            'waiting' => ['to_approve' => self::decorate($approve, $u), 'stuck_on_me' => self::decorate($stuck, $u), 'covering' => self::decorate($cover, $u), 'helper' => self::decorate($helper, $u)],
            'empty' => ['mine' => 'Nothing open. Good.', 'waiting' => 'Nobody is waiting on you.']];
    }

    /* ---------- R-G_CORE-37 All tasks ---------- */

    /** The query of one chip with the filters (person, category, status, source, marketplace, product, search, shop). */
    public static function query(object $u, string $chip, array $f)
    {
        $today = TaskHealth::today(); $q = self::visible(self::base(), $u);
        match ($chip) {
            'open' => $q->whereIn('t.status', self::OPEN),
            'late' => $q->whereIn('t.status', self::OPEN)->whereNotNull('t.due_at')->whereRaw('DATE(t.due_at) < ?', [$today]),
            'stuck' => $q->where('t.status', 'STUCK'),
            'today' => $q->whereIn('t.status', self::OPEN)->whereRaw('DATE(t.due_at) = ?', [$today]),
            'waiting' => $q->where('t.status', 'DONE'),
            'nodue' => $q->whereIn('t.status', self::OPEN)->whereNull('t.due_at'),
            'closed' => $q->whereIn('t.status', ['APPROVED', 'CANCELLED']),
            default => $q,
        };
        if (! empty($f['person'])) $q->where('t.owner_user_id', (int) $f['person']);
        if (! empty($f['category'])) $q->where('t.category_id', (int) $f['category']);
        if (! empty($f['status'])) $q->where('t.status', strtoupper((string) $f['status']));
        if (! empty($f['source'])) $q->where('t.source', strtoupper((string) $f['source']));
        if (! empty($f['marketplace'])) $q->where('t.marketplace', (string) $f['marketplace']);
        if (! empty($f['product'])) $q->where('t.product_id', (int) $f['product']);
        if (! empty($f['shop'])) $q->whereIn('t.linked_module', ['t_branches', 'SHOP'])->where('t.linked_record_id', (int) $f['shop']);
        if (($s = trim((string) ($f['q'] ?? ''))) !== '') {
            $id = preg_match('/^T-?0*(\d+)$/i', $s, $m) ? (int) $m[1] : null;
            $q->where(fn ($w) => $w->where('t.title', 'like', "%$s%")->orWhere('t.reference_id', 'like', "%$s%")->when($id, fn ($x) => $x->orWhere('t.id', $id)));
        }
        return $q->orderByRaw("CASE WHEN t.status IN ('TODO','DOING','STUCK','REWORK','DONE') THEN 0 ELSE 1 END")->orderByRaw('t.due_at IS NULL')->orderBy('t.due_at')
            ->orderByRaw("FIELD(t.priority,'URGENT','HIGH','NORMAL','LOW')")->orderByDesc('t.id');
    }

    public static function all(object $u, string $chip, array $f, int $page = 1, int $per = 25): array
    {
        $chip = isset(self::CHIPS[$chip]) ? $chip : 'open'; $page = max(1, $page);
        $counts = []; foreach (array_keys(self::CHIPS) as $c) $counts[$c] = (clone self::query($u, $c, $f))->reorder()->count('t.id');
        $rows = self::query($u, $chip, $f)->offset(($page - 1) * $per)->limit($per)->get(self::COLS);
        return ['tasks' => self::decorate($rows, $u), 'chip' => $chip, 'chips' => collect(self::CHIPS)->map(fn ($l, $k) => ['key' => $k, 'label' => $l, 'count' => $counts[$k]])->values()->all(),
            'total' => $counts[$chip], 'page' => $page, 'per' => $per, 'amount' => self::partner($u), 'sources' => self::SOURCES,
            'categories' => DB::table('task_categories')->where('is_active', 1)->orderBy('sort')->get(['id', 'name']), 'import' => self::boss($u)];
    }

    public const EXPORT_COLS = ['id', 'title', 'status', 'health', 'priority', 'category', 'owner_name', 'due_at', 'source', 'rule_code', 'linked_module', 'linked_record_id', 'reference_id', 'evidence_count', 'due_changes', 'rework_count', 'score'];

    /** [file name, CSV text]: UTF-8 with BOM, the 17 columns (+ amount_gbp for Partners), at most 5,000 rows, the chip and filters of the screen. */
    public static function export(object $u, string $chip, array $f, ?string $now = null): array
    {
        $chip = isset(self::CHIPS[$chip]) ? $chip : 'open';
        $rows = self::decorate(self::query($u, $chip, $f)->limit(5000)->get(self::COLS), $u); $p = self::partner($u);
        $h = fopen('php://temp', 'r+'); fwrite($h, "\xEF\xBB\xBF"); fputcsv($h, $p ? [...self::EXPORT_COLS, 'amount_gbp'] : self::EXPORT_COLS);
        foreach ($rows as $r) fputcsv($h, [...[$r['id'], $r['title'], $r['status'], $r['health']['code'], $r['priority'], $r['category'], $r['owner'], $r['due_at'], $r['source'], $r['rule_code'], $r['linked_module'], $r['linked_record_id'], $r['reference_id'],
            $r['evidence_count'], $r['due_changes'], $r['rework_count'], $r['score']], ...($p ? [$r['amount_gbp']] : [])]);
        rewind($h);
        return ['tasks_' . $chip . '_' . date('Ymd_His', strtotime($now ?? 'now')) . '.csv', stream_get_contents($h)];
    }

    /* ---------- R-G_CORE-39 Needs attention ---------- */

    public static function attention(object $u, int $page = 1, int $per = 10, array $f = []): array
    {
        if (! self::boss($u)) throw new \InvalidArgumentException('Refused: "Needs attention" is for a Partner or Manager.');
        $today = TaskHealth::today(); $rows = [];
        $q = self::visible(self::base(), $u)->whereIn('t.status', [...self::OPEN, 'DONE'])->when(! empty($f['person']), fn ($w) => $w->where('t.owner_user_id', (int) $f['person']))
            ->when(! empty($f['shop']), fn ($w) => $w->whereIn('t.linked_module', ['t_branches', 'SHOP'])->where('t.linked_record_id', (int) $f['shop']));
        $ack = DB::table('task_categories')->pluck('ack_hours', 'id');
        foreach ($q->get(self::COLS) as $t) {
            $due = $t->due_at ? substr((string) $t->due_at, 0, 10) : null; $late = $due && $due < $today && $t->status !== 'DONE';
            $days = $late ? (int) round((strtotime($today) - strtotime($due)) / 86400) : 0; $owner = $t->owner ?: 'the owner';
            $ackH = (int) ($ack[$t->category_id] ?? 0) ?: 24; $notAck = ! $t->acknowledged_at && in_array($t->status, self::OPEN, true) && strtotime((string) $t->created_at) < time() - $ackH * 3600;
            [$rank, $why] = match (true) {
                $t->status === 'STUCK' => [1, trim(($late ? "Late $days day" . ($days === 1 ? '' : 's') . ' and ' : '') . 'stuck on ' . self::stuckOn($t) . ' since ' . substr((string) $t->stuck_since, 0, 10) . " — ask $owner why")],
                $late => [2, "Late $days day" . ($days === 1 ? '' : 's') . ', last update ' . substr((string) ($t->last_update_at ?: $t->created_at), 0, 10) . " — ask $owner why"],
                $t->status === 'DONE' => [3, 'Done' . (DB::table('task_evidence')->where('task_id', $t->id)->whereNull('hidden_at')->whereIn('type', TaskFlow::COUNTING)->exists() ? ' with proof' : '') . ' · waiting for ' . ($t->assigned_by_user_id ? (string) DB::table('t_users')->where('id', $t->assigned_by_user_id)->value('display_name') : 'a Partner or Manager')],
                $due === $today => [4, 'Due today'],
                $due === null => [5, 'Created without due date — set one'],
                $notAck => [6, 'Not acknowledged since ' . date('d M H:i', strtotime((string) $t->created_at))],
                (int) $t->due_changes > 2 => [7, 'Due date moved ' . (int) $t->due_changes . ' times'],
                default => [0, ''],
            };
            if (! $rank) continue;
            $sub = $t->source === 'RULE' && in_array($rank, [2, 4], true) ? array_search($t->priority, ['URGENT', 'HIGH', 'NORMAL', 'LOW'], true) : 9;      // rule tasks by their own priority inside rank 2 / 4
            $rows[] = ['rank' => $rank, 'sub' => $sub, 'due' => $due ?? '9999-12-31', 'id' => (int) $t->id, 'ref' => TaskFlow::ref((int) $t->id), 'title' => $t->title, 'owner' => $t->owner, 'why' => $why, 'status' => $t->status];
        }
        usort($rows, fn ($a, $b) => [$a['rank'], $a['sub'], $a['due'], $a['id']] <=> [$b['rank'], $b['sub'], $b['due'], $b['id']]);
        return ['rows' => array_slice($rows, ($page - 1) * $per, $per), 'total' => count($rows), 'page' => $page, 'per' => $per];
    }

    private static function stuckOn(object $t): string
    {
        if ($t->stuck_on_type === 'USER' && $t->stuck_on_id) return (string) DB::table('t_users')->where('id', $t->stuck_on_id)->value('display_name');
        return trim((string) $t->stuck_on_text) ?: strtolower((string) (Guide::STUCK_ON[$t->stuck_on_type] ?? 'something'));
    }

    /* ---------- R-G_CORE-38 dashboard ---------- */

    public static function dashboard(object $u, array $f = []): array
    {
        TaskRoles::need($u, 'TASK_DASH');      // R-G_CORE-20
        $count = fn (string $chip) => (clone self::query($u, $chip, $f))->reorder()->count('t.id');
        $tz = new \DateTimeZone((string) (Settings::get('COMPANY_TIMEZONE', (int) Context::ent(), 'Europe/London') ?: 'Europe/London'));
        $monday = (new \DateTimeImmutable('monday this week', $tz))->setTime(0, 0)->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
        $sf = fn ($q) => $q->when(! empty($f['shop']), fn ($w) => $w->whereIn('t.linked_module', ['t_branches', 'SHOP'])->where('t.linked_record_id', (int) $f['shop']))->when(! empty($f['person']), fn ($w) => $w->where('t.owner_user_id', (int) $f['person']));
        $closed = $sf(self::visible(self::base(), $u))->where('t.status', 'APPROVED')->where('t.approved_at', '>=', $monday)->pluck('t.id');
        $noProof = $closed->filter(fn ($id) => ! DB::table('task_evidence')->where('task_id', $id)->whereNull('hidden_at')->whereIn('type', TaskFlow::COUNTING)->exists())->count();
        $avg = DB::table('task_scores')->where('closed_at', '>=', now()->subDays(30))->avg('score');
        $today = TaskHealth::today();
        $people = [];
        foreach ($sf(self::visible(self::base(), $u))->whereNotNull('t.owner_user_id')->whereIn('t.status', [...self::OPEN, 'DONE'])->get(['t.owner_user_id', 't.status', 't.due_at', 'u.display_name'])->groupBy('owner_user_id') as $uid => $ts) {
            $sc = DB::table('task_scores')->where('user_id', $uid)->where('closed_at', '>=', now()->subDays(30))->avg('score');
            $people[] = ['id' => (int) $uid, 'name' => $ts->first()->display_name, 'open' => $ts->whereIn('status', self::OPEN)->count(),
                'late' => $ts->filter(fn ($t) => in_array($t->status, self::OPEN, true) && $t->due_at && substr((string) $t->due_at, 0, 10) < $today)->count(),
                'waiting' => $ts->where('status', 'DONE')->count(), 'score' => $sc !== null ? (int) round($sc) : null, 'away' => (bool) TaskOwner::absent((int) $uid)];
        }
        usort($people, fn ($a, $b) => [$b['late'], $b['open'], $a['name']] <=> [$a['late'], $a['open'], $b['name']]);
        $mods = $sf(self::visible(self::base(), $u))->whereIn('t.status', self::OPEN)->selectRaw("COALESCE(t.linked_module, 'NOT LINKED') m, COUNT(*) open, SUM(t.due_at IS NOT NULL AND DATE(t.due_at) < ?) late", [$today])->groupBy('m')->orderByDesc('open')->get()
            ->map(fn ($r) => ['module' => $r->m, 'open' => (int) $r->open, 'late' => (int) $r->late])->all();
        $dq = DB::table('tasks')->whereIn('rule_code', ['TASK_NO_DUE', 'TASK_NOT_LINKED'])->whereIn('status', self::OPEN)->pluck('title')->all();      // made by the nightly job
        return ['tiles' => ['open' => $count('open'), 'late' => $count('late'), 'stuck' => $count('stuck'), 'today' => $count('today'), 'waiting' => $count('waiting'), 'nodue' => $count('nodue'),
                'closed_week' => $closed->count(), 'closed_week_line' => $closed->count() ? ($noProof ? "of which $noProof no proof" : 'with proof') : '', 'avg_score' => $avg !== null ? (int) round($avg) : null],
            'people' => $people, 'modules' => $mods, 'attention' => self::attention($u, 1, 10, $f), 'data_quality' => $dq];
    }

    /** New (nightly): a rule task per day while open tasks have no due date / are not linked (dedupe per day); closed when none are left. */
    public static function dataQuality(int $ent): array
    {
        $q = fn () => DB::table('tasks')->where(fn ($w) => $w->where('fk_id_ent', $ent)->orWhere('fk_id_ent', 0)->orWhereNull('fk_id_ent'))->whereIn('status', self::OPEN)->where(fn ($w) => $w->whereNull('rule_code')->orWhereNotIn('rule_code', ['TASK_NO_DUE', 'TASK_NOT_LINKED']));
        $noDue = $q()->whereNull('due_at')->count(); $notLinked = $q()->whereNull('linked_module')->where(fn ($w) => $w->whereNull('category_id')->orWhere('category_id', '<>', 1))->count();      // R-G_CORE-23: every task is linked except category General
        $d = date('Y-m-d'); $out = [];
        foreach (['TASK_NO_DUE' => [$noDue, "$noDue open task(s) have no due date"], 'TASK_NOT_LINKED' => [$notLinked, "$notLinked open task(s) are not linked to a record"]] as $code => [$n, $title]) {
            if ($n > 0) { if (! TaskEngine::isOpen($code, [$ent, $d]) && TaskEngine::open($code, [$ent, $d], $title, ['ent' => $ent, 'module' => 'tasks', 'fix_url' => '#/tasks?scope=all&chip=' . ($code === 'TASK_NO_DUE' ? 'nodue' : 'open')])) $out[] = $code; }
            else foreach (DB::table('tasks')->where('rule_code', $code)->whereIn('status', self::OPEN)->pluck('dedupe_key') as $k) TaskEngine::close($code, array_slice(explode(':', (string) $k), 1), 'No such tasks any more');
        }
        return $out;
    }
}
