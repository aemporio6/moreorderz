<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-G_CORE-54 message log: screen Alerts & messages › Log (bosses; filters person, channel, task, status, direction; 25 a page; CSV export) and a Messages
 * tab on every task / record (anyone who can see it; 10 a page). Rows: time, channel, person (or "unknown number"), text with links removed and £ amounts
 * shown as "£•••" for anyone who is not a Partner, "open" link to the task, status (QUEUED "after 08:00 (quiet hours)", SENT, DELIVERED, READ, FAILED + error),
 * retry count; inbound replies "Replied DONE", "refused …", IGNORED, ACTED.
 */
class MessageLog
{
    public const CHANNELS = ['WHATSAPP' => 'WhatsApp', 'EMAIL' => 'Email', 'INAPP' => 'In-app'];

    public static function clean(?string $text, bool $partner): string
    {
        $t = trim(preg_replace('/\s{2,}/', ' ', preg_replace('~https?://\S+~i', '', (string) $text)));
        return $partner ? $t : preg_replace('/£\s?-?[\d,]+(?:\.\d+)?/u', '£•••', $t);
    }

    private static function partner(object $u): bool { return in_array($u->role_code, ['OWNER', 'PARTNER'], true); }
    public static function boss(object $u): bool { return in_array($u->role_code, TaskScore::BOSS, true); }

    /** Filters: person (user id), channel, task, status, direction (OUT / IN), tasks (list of task ids for a record). */
    public static function rows(object $viewer, array $f, int $per = 25, int $page = 1): array
    {
        $per = max(1, min(100, $per)); $page = max(1, $page); $take = $per * $page; $dir = strtoupper((string) ($f['direction'] ?? ''));
        $taskIds = isset($f['tasks']) ? (array) $f['tasks'] : (! empty($f['task']) ? [(int) $f['task']] : null);
        $out = collect(); $in = collect(); $nOut = 0; $nIn = 0;
        if ($dir !== 'IN') {
            $q = DB::table('tm_messages as m')->leftJoin('t_users as u', 'u.id', '=', 'm.user_id')->where('m.direction', 'OUT')
                ->when(! empty($f['person']), fn ($w) => $w->where('m.user_id', (int) $f['person']))->when(! empty($f['channel']), fn ($w) => $w->where('m.channel', strtoupper($f['channel'])))
                ->when(! empty($f['status']), fn ($w) => $w->where('m.status', strtoupper($f['status'])))->when($taskIds !== null, fn ($w) => $w->whereIn('m.task_id', $taskIds ?: [0]));
            $nOut = (clone $q)->count();
            $out = $q->orderByDesc('m.created_at')->orderByDesc('m.id')->limit($take)->get(['m.id', 'm.created_at as at', 'm.channel', 'u.display_name as who', 'm.text', 'm.task_id', 'm.status', 'm.note', 'm.send_after', 'm.error', 'm.retry_count'])
                ->map(fn ($m) => ['kind' => 'OUT', 'id' => (int) $m->id, 'at' => (string) $m->at, 'channel' => self::CHANNELS[$m->channel] ?? $m->channel, 'who' => $m->who ?: 'unknown', 'text' => self::clean($m->text, self::partner($viewer)),
                    'task' => $m->task_id ? ['id' => (int) $m->task_id, 'ref' => TaskFlow::ref((int) $m->task_id), 'url' => '#/tasks?scope=all'] : null,
                    'status' => $m->status, 'status_text' => $m->status === 'QUEUED' && $m->note ? 'QUEUED · ' . $m->note : ($m->status === 'FAILED' ? 'FAILED · ' . $m->error : $m->status), 'retries' => (int) $m->retry_count]);
        }
        if ($dir !== 'OUT' && (empty($f['channel']) || strtoupper($f['channel']) === 'WHATSAPP') && empty($f['status'])) {
            $acc = ! empty($f['person']) ? (int) DB::table('t_users')->where('id', (int) $f['person'])->value('fk_id_account') : null;
            $q = DB::table('wa_inbound as i')->leftJoin('t_account as a', 'a.id', '=', 'i.fk_id_account')->when(! empty($f['person']), fn ($w) => $w->where('i.fk_id_account', $acc ?: -1))
                ->when($taskIds !== null, fn ($w) => $w->whereIn('i.fk_id_task', $taskIds ?: [0]));
            $nIn = (clone $q)->count();
            $in = $q->orderByDesc('i.received_at')->orderByDesc('i.id')->limit($take)->get(['i.id', 'i.received_at as at', DB::raw("TRIM(CONCAT_WS(' ', a.first_name, a.sur_name)) as who"), 'i.body', 'i.fk_id_task', 'i.status', 'i.action', 'i.note'])
                ->map(fn ($m) => ['kind' => 'IN', 'id' => (int) $m->id, 'at' => (string) $m->at, 'channel' => 'WhatsApp', 'who' => $m->who ?: 'unknown number', 'text' => self::clean($m->body, self::partner($viewer)),
                    'task' => $m->fk_id_task ? ['id' => (int) $m->fk_id_task, 'ref' => TaskFlow::ref((int) $m->fk_id_task), 'url' => '#/tasks?scope=all'] : null,
                    'status' => $m->status, 'status_text' => $m->status === 'REFUSED' ? 'refused ' . ($m->note ?: '') : ($m->action && ! in_array($m->status, ['IGNORED'], true) ? 'Replied ' . $m->action : $m->status), 'retries' => 0]);
        }
        $all = $out->concat($in)->sortByDesc('at')->values();
        return ['rows' => $all->slice(($page - 1) * $per, $per)->values()->all(), 'total' => $nOut + $nIn, 'page' => $page, 'per' => $per, 'channels' => self::CHANNELS];
    }

    /** The Messages tab of a task (anyone who can see it). */
    public static function forTask(int $taskId, object $u, int $page = 1): array
    {
        $t = DB::table('tasks')->where('id', $taskId)->first() ?? abort(404);
        if (! TaskFlow::canSee($t, $u)) abort(404, 'Task not found.');
        return self::rows($u, ['tasks' => [$taskId]], 10, $page);
    }

    /** The Messages tab of a record = the messages of the tasks linked to it (10 a page). */
    public static function forRecord(string $table, int $id, object $u, int $page = 1): array
    {
        $ids = DB::table('tasks')->where('linked_module', $table)->where('linked_record_id', $id)->pluck('id')->all();
        $ids = array_values(array_filter($ids, fn ($tid) => TaskFlow::canSee(DB::table('tasks')->where('id', $tid)->first(), $u)));
        return self::rows($u, ['tasks' => $ids], 10, $page);
    }

    public static function csv(object $u, array $f): string
    {
        if (! self::boss($u)) throw new \InvalidArgumentException('Refused: only a Partner or Manager can export the message log.');
        $h = fopen('php://temp', 'r+');
        fputcsv($h, ['Time', 'Direction', 'Channel', 'Person', 'Text', 'Task', 'Status', 'Retries']);
        $all = self::rows($u, $f, 100, 1); $p = 1;
        do { foreach ($all['rows'] as $x) fputcsv($h, [$x['at'], $x['kind'], $x['channel'], $x['who'], $x['text'], $x['task']['ref'] ?? '', $x['status_text'], $x['retries']]); $p++; $all = self::rows($u, $f, 100, $p); } while ($all['rows'] && $p <= 50);
        rewind($h); return stream_get_contents($h);
    }
}
