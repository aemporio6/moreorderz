<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * RULES_G_CORE R-G_CORE-28 comments and @mentions: text ≤ 2,000 (owner to confirm, MR-0497), one attachment (stored as evidence with the comment link; a voice note is allowed but is not proof),
 * @First, @First_Last or the full name of an active user → watcher when he could not see the task, and N9; a confidential task: silently skipped (+ a warning to the author – new).
 * A comment by the owner, a helper or the cover (new) updates last_update_at. Anyone who can see the task may comment.
 */
class TaskComments
{
    public const MAX = 2000;

    /** Active users that can be mentioned: id, name, first name (the @ picker). */
    public static function people(?int $ent = null): array
    {
        return DB::table('t_users')->where('f_active', 1)->when($ent, fn ($q) => $q->where('fk_id_ent', $ent))->orderBy('display_name')->get(['id', 'display_name'])
            ->filter(fn ($p) => trim((string) $p->display_name) !== '')->map(fn ($p) => ['id' => (int) $p->id, 'name' => (string) $p->display_name, 'tag' => '@' . str_replace(' ', '_', trim((string) $p->display_name))])->values()->all();
    }

    /**
     * The users a text mentions. Full name (longest first, spaces or underscores) wins; else @First when exactly one active user has that first name.
     * Returns [ids, warnings].
     */
    public static function parse(string $text, ?int $ent = null): array
    {
        $people = self::people($ent); usort($people, fn ($a, $b) => mb_strlen($b['name']) <=> mb_strlen($a['name']));
        $ids = []; $warn = [];
        if (! preg_match_all('/(?<![\w@])@(?=(\S[^\n]*))/u', $text, $m)) return [[], []];
        foreach ($m[1] as $rest) {
            $flat = str_replace('_', ' ', $rest); $hit = null;
            foreach ($people as $p) if (preg_match('/^' . preg_quote($p['name'], '/') . '(?![\p{L}\p{N}])/iu', $flat)) { $hit = $p; break; }
            if (! $hit && preg_match('/^([\p{L}\p{N}]+)/u', $rest, $w)) {
                $first = array_values(array_filter($people, fn ($p) => mb_strtolower(strtok($p['name'], ' ')) === mb_strtolower($w[1])));
                if (count($first) === 1) $hit = $first[0];
                elseif (count($first) > 1) $warn[] = "@{$w[1]} matches " . count($first) . ' people – use @First_Last.';
            }
            if ($hit) $ids[] = $hit['id'];
        }
        return [array_values(array_unique($ids)), $warn];
    }

    /** Add a comment (web or WhatsApp). Returns ['id', 'message', 'warnings']. */
    public static function add(int $taskId, object $u, string $text, ?UploadedFile $file = null, string $via = 'APP'): array
    {
        $t = DB::table('tasks')->where('id', $taskId)->first();
        if (! $t || ! TaskFlow::canSee($t, $u)) throw new \InvalidArgumentException('Task not found.');
        $text = trim($text);
        if ($text === '' && ! $file) throw new \InvalidArgumentException('Write the comment first.');
        if (mb_strlen($text) > self::MAX) throw new \InvalidArgumentException('Refused: a comment is at most 2,000 characters.');
        $fileId = $file ? TaskEvidence::add($taskId, $u, $file, null, null, $text !== '' ? mb_substr($text, 0, 255) : null, null, $via) : null;      // one attachment, stored as evidence (voice allowed, not proof)
        [$ids, $warn] = self::parse($text, (int) $t->fk_id_ent ?: null);
        $told = [];
        foreach (array_diff($ids, [(int) $u->id]) as $uid) {
            $who = DB::table('t_users')->where('id', $uid)->first(); if (! $who) continue;
            if (! TaskFlow::canSee($t, $who)) {
                if ((int) $t->is_confidential) { $warn[] = "$who->display_name cannot see a confidential task – not told."; continue; }      // silently skipped for him; a warning to the author (new)
                DB::table('task_watchers')->insertOrIgnore(['task_id' => $taskId, 'user_id' => $uid]);
            }
            $told[] = $uid;
        }
        $id = (int) DB::table('task_comments')->insertGetId(['task_id' => $taskId, 'at' => now(), 'user_id' => $u->id, 'text' => $text, 'file_id' => $fileId, 'mentions_json' => $told ? json_encode($told) : null, 'via' => $via]);
        $author = (string) DB::table('t_users')->where('id', $u->id)->value('display_name');
        foreach ($told as $uid) {
            $n = (string) DB::table('t_users')->where('id', $uid)->value('display_name');
            AlertRules::send('N9', $uid, ['first_name' => strtok($n, ' ') ?: $n, 'author' => $author, 'task_ref' => TaskFlow::ref($taskId), 'title' => $t->title, 'comment' => mb_substr($text, 0, 300),
                'link' => rtrim((string) config('app.url'), '/') . '/#/task/' . $taskId], $taskId, "N9:$id:$uid");
        }
        $updates = (int) $t->owner_user_id === (int) $u->id || DB::table('task_helpers')->where('task_id', $taskId)->where('user_id', $u->id)->exists()
            || ($t->owner_user_id && in_array((int) $t->owner_user_id, TaskVisibility::covered((int) $u->id), true));
        if ($updates) { DB::table('tasks')->where('id', $taskId)->update(['last_update_at' => now()]); TaskAlerts::fresh($taskId); }
        return ['id' => $id, 'message' => 'Comment added.' . ($told ? ' Told: ' . implode(', ', array_map(fn ($x) => (string) DB::table('t_users')->where('id', $x)->value('display_name'), $told)) . '.' : ''), 'warnings' => $warn];
    }

    /** The comments of the task page (with the attachment). */
    public static function rows(int $taskId): array
    {
        return DB::table('task_comments as c')->leftJoin('t_users as x', 'x.id', '=', 'c.user_id')->leftJoin('task_evidence as e', 'e.id', '=', 'c.file_id')->where('c.task_id', $taskId)->orderBy('c.id')
            ->get(['c.id', 'c.at', 'c.text', 'c.via', 'x.display_name as by', 'c.file_id', 'e.type as file_type', 'e.file_name', 'e.mime as file_mime'])
            ->map(fn ($c) => (array) $c + ['file' => $c->file_id ? '/api/task-evidence/' . $c->file_id . '/file' : null])->all();
    }
}
