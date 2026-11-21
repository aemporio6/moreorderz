<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Who may approve a task (R-G_CORE-7) and the task score (R-G_CORE-8).
 * - Approve only from DONE, by the assigner, any Partner / Manager (Owner counts as Partner) or a delegate (tm_delegations from = assigner, APPROVE / ALL, today within from–to).
 *   Nobody approves a task he owns – not even a Partner. System tasks (assigned_by = 0) any boss. A RULE task is refused while the problem is still there.
 * - Quality required: EXCELLENT / GOOD / OK / POOR. Approve sets APPROVED, approved_at / by, quality, freezes the score, unblocks waiting tasks (N10) and tells the owner (APPROVED).
 * - Rework (same approvers, a reason): status REWORK, rework_count + 1, owner told (N8).
 * - Score at approval, stored in task_scores + tasks.score, never recalculated:
 *   on_time = max(0, W − per_day × late_days), proof = P when ≥ 1 counting evidence (a voice note does not count), quality = 40/30/20/10, rework = −R × rework_count, clamp 0..100.
 *   Weights and colour bands are settings (tm_settings score_*); a change applies only to tasks approved after it.
 */
class TaskScore
{
    public const QUALITY = ['EXCELLENT' => 'score_q_excellent', 'GOOD' => 'score_q_good', 'OK' => 'score_q_ok', 'POOR' => 'score_q_poor'];
    public const QUALITY_NUM = [1 => 'POOR', 2 => 'OK', 3 => 'GOOD', 4 => 'EXCELLENT'];
    public const BOSS = ['OWNER', 'PARTNER', 'MANAGER'];

    private static function w(string $k): int { return (int) AlertRules::threshold($k); }

    /** The score parts of a task as it stands now (or at $closeDay). */
    public static function parts(object $t, string $quality, ?string $closeDay = null): array
    {
        $close = $closeDay ?? date('Y-m-d'); $due = $t->due_at ? substr((string) $t->due_at, 0, 10) : $close;
        $late = max(0, (int) ((strtotime($close) - strtotime($due)) / 86400));
        $onTime = max(0, self::w('score_on_time') - self::w('score_late_per_day') * $late);
        $proof = DB::table('task_evidence')->where('task_id', $t->id)->where(fn ($w) => $w->whereNull('type')->orWhere('type', '<>', 'VOICE'))->exists() ? self::w('score_proof') : 0;
        $q = self::w(self::QUALITY[$quality] ?? 'score_q_good');
        $rw = -self::w('score_rework') * (int) $t->rework_count;
        $score = max(0, min(100, $onTime + $proof + $q + $rw));
        return ['late_days' => $late, 'on_time' => $onTime, 'proof' => $proof, 'quality' => $q, 'rework' => $rw, 'score' => $score,
            'text' => "on time $onTime + evidence $proof + quality $q" . ($rw ? ' - rework ' . abs($rw) : ''), 'band' => self::band($score)];
    }

    /** good ≥ score_band_good (teal on green), mid ≥ score_band_mid (ochre on cream), else bad (rust on peach). */
    public static function band(float $s): array
    {
        if ($s >= self::w('score_band_good')) return ['band' => 'good', 'fg' => '#0F5E63', 'bg' => '#DDEFE7'];
        if ($s >= self::w('score_band_mid')) return ['band' => 'mid', 'fg' => '#6B4E00', 'bg' => '#FFF4D6'];
        return ['band' => 'bad', 'fg' => '#7C2D12', 'bg' => '#FDEBDD'];
    }

    /** Why this person may not approve / send back this task, or null when he may. */
    public static function refusal(object $t, object $u): ?string
    {
        if ((int) $t->owner_user_id === (int) $u->id) return 'Refused: nobody can approve their own task - not even a Partner.';
        if ($t->status !== 'DONE') return "Refused: only a DONE task can be approved (this one is $t->status).";
        $boss = in_array($u->role_code, self::BOSS, true);
        if ((int) $t->assigned_by_user_id === 0) return $boss ? null : 'Refused: only the person who assigned it, a Partner or a Manager can approve.';
        if ((int) $t->assigned_by_user_id === (int) $u->id || $boss) return null;
        $today = date('Y-m-d');
        $del = DB::table('tm_delegations')->where('from_user_id', $t->assigned_by_user_id)->where('to_user_id', $u->id)->whereIn('rights', ['APPROVE', 'ALL'])
            ->where(fn ($w) => $w->whereNull('from_date')->orWhere('from_date', '<=', $today))->where(fn ($w) => $w->whereNull('to_date')->orWhere('to_date', '>=', $today))->exists();
        return $del ? null : 'Refused: only the person who assigned it, a Partner or a Manager can approve.';
    }

    public static function approve(int $taskId, object $u, $quality, ?string $closeDay = null, bool $system = false): array
    {
        $quality = is_numeric($quality) ? (self::QUALITY_NUM[(int) $quality] ?? null) : strtoupper(trim((string) $quality));
        if (! isset(self::QUALITY[$quality])) throw new \InvalidArgumentException('Choose the quality: Excellent, Good, OK or Poor.');
        return DB::transaction(function () use ($taskId, $u, $quality, $closeDay, $system) {
            $t = DB::table('tasks')->where('id', $taskId)->lockForUpdate()->first() ?? throw new \InvalidArgumentException('Task not found.');
            if (! $system) {
                if ($t->source === 'RULE' && ! in_array($t->status, ['DONE', 'APPROVED', 'CANCELLED'], true)) throw new \InvalidArgumentException('Refused: problem still present: ' . $t->title);
                if ($why = self::refusal($t, $u)) throw new \InvalidArgumentException($why);
            }
            $p = self::parts($t, $quality, $closeDay);
            DB::table('tasks')->where('id', $taskId)->update(['status' => 'APPROVED', 'approved_at' => now(), 'approved_by_user_id' => $system ? null : $u->id, 'quality' => $quality, 'score' => $p['score'], 'last_update_at' => now()]);
            DB::table('task_scores')->updateOrInsert(['task_id' => $taskId], ['user_id' => (int) $t->owner_user_id, 'score' => $p['score'], 'on_time_pts' => $p['on_time'], 'evidence_pts' => $p['proof'], 'quality_pts' => $p['quality'], 'rework_pts' => $p['rework'], 'closed_at' => now()]);
            DB::table('task_history')->insert([['task_id' => $taskId, 'at' => now(), 'user_id' => $system ? null : $u->id, 'field' => 'status', 'old_value' => $t->status, 'new_value' => 'APPROVED', 'reason' => 'Quality ' . $quality],
                ['task_id' => $taskId, 'at' => now(), 'user_id' => $system ? null : $u->id, 'field' => 'score', 'old_value' => null, 'new_value' => (string) $p['score'], 'reason' => $p['text']]]);
            Audit::log('tasks', $taskId, 'APPROVE', ['quality' => $quality, 'score' => $p['score']]);
            $ref = 'T-' . str_pad((string) $taskId, 4, '0', STR_PAD_LEFT);
            if ($t->owner_user_id) AlertRules::send('APPROVED', (int) $t->owner_user_id, ['first_name' => self::first((int) $t->owner_user_id), 'task_ref' => $ref, 'title' => $t->title, 'quality' => $quality, 'score' => $p['score'], 'link' => self::link()], $taskId, "AP:$taskId");
            foreach (DB::table('tasks')->where('depends_on_task_id', $taskId)->whereNotIn('status', ['APPROVED', 'CANCELLED'])->get() as $w)      // waiting tasks can start (N10)
                if ($w->owner_user_id) AlertRules::send('N10', (int) $w->owner_user_id, ['first_name' => self::first((int) $w->owner_user_id), 'task_ref' => 'T-' . str_pad((string) $w->id, 4, '0', STR_PAD_LEFT), 'title' => $w->title, 'cleared_ref' => $ref, 'link' => self::link()], (int) $w->id, "N10:$w->id:$taskId");
            return $p + ['message' => "$ref approved – score {$p['score']} ({$p['text']})."];
        });
    }

    public static function rework(int $taskId, object $u, string $reason): array
    {
        if (mb_strlen(trim($reason)) < 3) throw new \InvalidArgumentException('Say what needs to be redone.');
        $t = DB::table('tasks')->where('id', $taskId)->first() ?? throw new \InvalidArgumentException('Task not found.');
        if ($why = self::refusal($t, $u)) throw new \InvalidArgumentException(str_replace('approve', 'send back', $why));
        DB::table('tasks')->where('id', $taskId)->update(['status' => 'REWORK', 'rework_count' => (int) $t->rework_count + 1, 'done_at' => null, 'last_update_at' => now()]);
        DB::table('task_history')->insert(['task_id' => $taskId, 'at' => now(), 'user_id' => $u->id, 'field' => 'status', 'old_value' => 'DONE', 'new_value' => 'REWORK', 'reason' => trim($reason)]);
        $ref = 'T-' . str_pad((string) $taskId, 4, '0', STR_PAD_LEFT);
        if ($t->owner_user_id) AlertRules::send('N8', (int) $t->owner_user_id, ['first_name' => self::first((int) $t->owner_user_id), 'task_ref' => $ref, 'title' => $t->title, 'reason' => trim($reason), 'link' => self::link()], $taskId, "N8:$taskId:" . ((int) $t->rework_count + 1));
        return ['message' => "$ref sent back for rework."];
    }

    /** A rule task closed by the system when its problem is gone: scored once with quality GOOD (status stays DONE). */
    public static function system(int $taskId): void
    {
        $t = DB::table('tasks')->where('id', $taskId)->first(); if (! $t || ! $t->owner_user_id || DB::table('task_scores')->where('task_id', $taskId)->exists()) return;
        $p = self::parts($t, 'GOOD');
        DB::table('task_scores')->insert(['task_id' => $taskId, 'user_id' => (int) $t->owner_user_id, 'score' => $p['score'], 'on_time_pts' => $p['on_time'], 'evidence_pts' => $p['proof'], 'quality_pts' => $p['quality'], 'rework_pts' => $p['rework'], 'closed_at' => now()]);
        DB::table('tasks')->where('id', $taskId)->update(['score' => $p['score'], 'quality' => 'GOOD']);
        DB::table('task_history')->insert(['task_id' => $taskId, 'at' => now(), 'user_id' => null, 'field' => 'score', 'old_value' => null, 'new_value' => (string) $p['score'], 'reason' => $p['text'] . ' (closed by the system)']);
    }

    /** Person score: rounded average of the last 30 × 24 h, with the parts. */
    public static function person(int $userId): array
    {
        $rows = DB::table('task_scores')->where('user_id', $userId)->where('closed_at', '>=', now()->subHours(30 * 24))->get(); $n = $rows->count();
        $avg = $n ? (int) round($rows->avg('score')) : null;
        return ['score' => $avg, 'n' => $n, 'band' => $avg !== null ? self::band($avg) : null,
            'line' => $n ? 'On time ' . $rows->where('on_time_pts', self::w('score_on_time'))->count() . " of $n · Proof " . $rows->where('evidence_pts', '>', 0)->count() . " of $n · Rework " . $rows->where('rework_pts', '<', 0)->count() : 'No approved task in the last 30 days.'];
    }

    private static function first(int $userId): string { $n = (string) DB::table('t_users')->where('id', $userId)->value('display_name'); return strtok($n, ' ') ?: $n; }
    private static function link(): string { return rtrim((string) config('app.url'), '/') . '/#/tasks'; }
}
