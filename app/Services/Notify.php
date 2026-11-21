<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** The bell: short in-app messages for one person (tm_notifications). Types: TASK, APPROVAL, FINANCE, IMPORT, ACCESS, SYSTEM. */
class Notify
{
    public const TYPES = ['TASK' => 'Tasks', 'APPROVAL' => 'Approvals', 'FINANCE' => 'Finance', 'IMPORT' => 'Imports', 'ACCESS' => 'Rights', 'DUE' => 'Due dates', 'SYSTEM' => 'System'];

    /** The bell. $log: also one INAPP row of the one message queue (R-G_CORE-46) – AlertRules writes its own row and passes false. */
    public static function push(int $userId, string $type, string $text, ?string $url = null, ?int $taskId = null, bool $log = true): void
    {
        if ($userId <= 0 || trim($text) === '') return;
        if ($log) DB::table('tm_messages')->insertOrIgnore(['user_id' => $userId, 'task_id' => $taskId, 'rule_code' => 'BELL_' . (isset(self::TYPES[$type]) ? $type : 'SYSTEM'), 'channel' => 'INAPP', 'direction' => 'OUT',
            'text' => mb_substr($text, 0, 500), 'event_key' => 'BELL:' . bin2hex(random_bytes(10)), 'status' => 'SENT', 'sent_at' => now(), 'retry_count' => 0, 'created_at' => now(),
            'fk_id_ent' => (int) (DB::table('t_users')->where('id', $userId)->value('fk_id_ent') ?? 0)]);
        DB::table('tm_notifications')->insert(['user_id' => $userId, 'task_id' => $taskId, 'type' => isset(self::TYPES[$type]) ? $type : 'SYSTEM', 'text' => mb_substr($text, 0, 500), 'url' => $url ? mb_substr($url, 0, 255) : null, 'created_at' => now()]);
    }

    /** To everyone who holds a right (for example: who may approve). Never to the person who caused it. */
    public static function toRight(string $right, string $type, string $text, ?string $url = null, ?int $exceptUser = null): int
    {
        $n = 0;
        foreach (\App\Models\User::where('f_active', 1)->get() as $u) { if ((int) $u->id === (int) $exceptUser || ! Perm::has($u, $right)) continue; self::push((int) $u->id, $type, $text, $url); $n++; }
        return $n;
    }

    public static function bell(object $u): array
    {
        $unread = DB::table('tm_notifications')->where('user_id', $u->id)->whereNull('read_at')->count();
        $tasks = DB::table('tasks')->where('owner_user_id', $u->id)->whereNotIn('status', ['DONE', 'APPROVED', 'CANCELLED'])->count();
        $items = DB::table('tm_notifications')->where('user_id', $u->id)->orderByDesc('id')->limit(10)->get()->map(fn ($n) => ['id' => $n->id, 'type' => $n->type, 'group' => self::TYPES[$n->type] ?? 'System', 'text' => $n->text, 'url' => $n->url ?: '#/tasks', 'unread' => $n->read_at === null, 'at' => (string) $n->created_at])->all();
        $total = $unread + $tasks;
        return ['badge' => $total > 99 ? '99+' : (string) $total, 'count' => $total, 'unread' => $unread, 'open_tasks' => $tasks, 'items' => $items];
    }

    public static function read(object $u, ?int $id): array
    {
        DB::table('tm_notifications')->where('user_id', $u->id)->whereNull('read_at')->when($id, fn ($q) => $q->where('id', $id))->update(['read_at' => now()]);
        return self::bell($u);
    }
}
