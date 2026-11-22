<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-G_CORE-45 daily digests (N13). Personal: "Good morning {first} – your tasks for Fri 06 Nov:" with the sections LATE, DUE TODAY (= N4), DUE SOON (≤ 3 days),
 * STUCK ON ME, WAITING MY APPROVAL, COVERING FOR ABSENT – heading "LATE (n)", at most 8 lines "- T-0012 title (due 06 Nov)" + "- ... and N more",
 * footer "Open My Tasks: <link>"; nothing → "Nothing due. Well done." (sent only when digest_send_empty = 1). Sent at the person's digest_time (default 08:00)
 * in his time zone, catch-up window 3 hours, key N13:{user}:{local date}. Team digest for bosses at digest_time + team_digest_offset_min: LATE by person,
 * STUCK, WAITING APPROVAL, OVER LIMIT. "Preview digest" shows the exact text (a boss for anyone, a person for himself).
 */
class TaskDigest
{
    public const MAX_LINES = 8;
    public const CATCH_UP_H = 3;
    private const OPEN = ['TODO', 'DOING', 'STUCK', 'REWORK'];

    private static function name(int $id): string { return (string) (DB::table('t_users')->where('id', $id)->value('display_name') ?: '#' . $id); }
    private static function line(object $t): string { return '- ' . TaskFlow::ref((int) $t->id) . ' ' . $t->title . ($t->due_at ? ' (due ' . date('d M', strtotime((string) $t->due_at)) . ')' : ''); }
    private static function link(): string { return rtrim((string) config('app.url'), '/') . '/#/tasks'; }

    /** section => rows of the person on his local date. */
    public static function sections(int $userId, string $date): array
    {
        $own = fn () => DB::table('tasks')->where('owner_user_id', $userId)->whereIn('status', self::OPEN)->whereNotNull('due_at')->orderBy('due_at')->orderBy('id');
        $soon = date('Y-m-d', strtotime("$date +3 days"));
        $u = DB::table('t_users')->where('id', $userId)->first();
        $approve = $u ? DB::table('tasks')->where('status', 'DONE')->where('source', '<>', 'RULE')->orderBy('due_at')->get()->filter(fn ($t) => (int) $t->owner_user_id !== $userId && TaskScore::refusal($t, $u) === null)->values() : collect();
        $cover = collect();
        foreach (DB::table('tasks')->whereIn('status', self::OPEN)->whereNotNull('owner_user_id')->where('owner_user_id', '<>', $userId)->orderBy('due_at')->get() as $t)
            if (TaskOwner::absent((int) $t->owner_user_id, $date) && TaskOwner::usable((int) $t->owner_user_id) === $userId) $cover->push($t);
        if ($u) { $approve = $approve->filter(fn ($t) => TaskVisibility::canSee($t, $u))->values(); $cover = $cover->filter(fn ($t) => TaskVisibility::canSee($t, $u))->values(); }      // R-G_CORE-19
        return [
            'LATE' => $own()->whereRaw('DATE(due_at) < ?', [$date])->get(),
            'DUE TODAY' => $own()->whereRaw('DATE(due_at) = ?', [$date])->get(),
            'DUE SOON' => $own()->whereRaw('DATE(due_at) > ? AND DATE(due_at) <= ?', [$date, $soon])->get(),
            'STUCK ON ME' => DB::table('tasks')->where('status', 'STUCK')->where('stuck_on_type', 'USER')->where('stuck_on_id', $userId)->orderBy('due_at')->get(),
            'WAITING MY APPROVAL' => $approve,
            'COVERING FOR ABSENT' => $cover,
        ];
    }

    /** The exact text of the personal digest; null when there is nothing and empty digests are off. */
    public static function text(int $userId, string $date, bool $evenEmpty = false): ?string
    {
        $first = strtok(self::name($userId), ' ') ?: self::name($userId);
        $out = []; $n = 0;
        foreach (self::sections($userId, $date) as $head => $rows) {
            if (! count($rows)) continue; $n += count($rows);
            $out[] = "$head (" . count($rows) . ')';
            foreach (collect($rows)->take(self::MAX_LINES) as $t) $out[] = self::line($t);
            if (count($rows) > self::MAX_LINES) $out[] = '- ... and ' . (count($rows) - self::MAX_LINES) . ' more';
        }
        if (! $n && ! $evenEmpty && ! (int) AlertRules::threshold('digest_send_empty')) return null;
        return "Good morning $first – your tasks for " . date('D d M', strtotime($date)) . ":\n" . ($n ? implode("\n", $out) : 'Nothing due. Well done.') . "\nOpen My Tasks: " . self::link();
    }

    /** The team digest of a boss: LATE by person, STUCK, WAITING APPROVAL, OVER LIMIT names. */
    public static function team(int $bossId, string $date): ?string
    {
        $first = strtok(self::name($bossId), ' ') ?: self::name($bossId);
        $boss = DB::table('t_users')->where('id', $bossId)->first(); $vis = fn () => $boss ? TaskVisibility::apply(DB::table('tasks as t'), $boss) : DB::table('tasks as t');      // R-G_CORE-19
        $late = $vis()->join('t_users as u', 'u.id', '=', 't.owner_user_id')->whereIn('t.status', self::OPEN)->whereRaw('DATE(t.due_at) < ?', [$date])
            ->groupBy('u.id', 'u.display_name')->orderByDesc(DB::raw('COUNT(*)'))->selectRaw('u.display_name n, COUNT(*) c')->get();
        $stuck = $vis()->where('t.status', 'STUCK')->count(); $wait = $vis()->where('t.status', 'DONE')->where('t.source', '<>', 'RULE')->count();
        $over = [];
        foreach (DB::table('t_users')->where('f_active', 1)->get(['id', 'display_name']) as $p) { $l = TaskCreate::load((int) $p->id); if ($l['open'] > $l['limit']) $over[] = $p->display_name . " ({$l['open']}/{$l['limit']})"; }
        if (! $late->count() && ! $stuck && ! $wait && ! $over && ! (int) AlertRules::threshold('digest_send_empty')) return null;
        return "Team today, $first – " . date('D d M', strtotime($date)) . ":\nLATE (" . $late->sum('c') . ')' . ($late->count() ? "\n" . $late->map(fn ($r) => "- {$r->n}: {$r->c}")->implode("\n") : '')
            . "\nSTUCK ($stuck)\nWAITING APPROVAL ($wait)\nOVER LIMIT (" . count($over) . ')' . ($over ? "\n- " . implode("\n- ", $over) : '') . "\nOpen tasks: " . self::link() . '?scope=all';
    }

    /**
     * R-G_CORE-45 the Owner exceptions digest on the same mechanism (N16): what the system found and nobody closed yet –
     * open system tasks by rule (late ones counted), approvals waiting, rights requests, messages that failed in the last 24 hours,
     * events that failed, Health warnings. Who gets it: Settings › Choices EXCEPTIONS_DIGEST_ROLES (default Owner).
     */
    public static function exceptions(int $userId, string $date): ?string
    {
        $first = strtok(self::name($userId), ' ') ?: self::name($userId); $out = []; $n = 0;
        $rule = DB::table('tasks')->where('source', 'RULE')->whereIn('status', self::OPEN)->groupBy('rule_code')->selectRaw("rule_code, COUNT(*) c, SUM(CASE WHEN DATE(due_at) < ? THEN 1 ELSE 0 END) l, MIN(title) t", [$date])->orderByDesc('c')->get();
        if ($rule->count()) { $n += $rule->sum('c'); $out[] = 'SYSTEM FOUND (' . $rule->sum('c') . ')';
            foreach ($rule->take(self::MAX_LINES) as $r) $out[] = '- ' . ($r->c > 1 ? $r->c . '× ' : '') . $r->t . ($r->l ? " ({$r->l} late)" : '');
            if ($rule->count() > self::MAX_LINES) $out[] = '- ... and ' . ($rule->count() - self::MAX_LINES) . ' more'; }
        $simple = [
            'APPROVALS WAITING' => \Illuminate\Support\Facades\Schema::hasTable('sys_approvals') ? DB::table('sys_approvals')->where('status', 'PENDING')->count() : 0,
            'RIGHTS REQUESTS' => \Illuminate\Support\Facades\Schema::hasTable('mo_access_requests') ? DB::table('mo_access_requests')->where('status', 'OPEN')->count() : 0,
            'MESSAGES FAILED (24 h)' => DB::table('tm_messages')->where('status', 'FAILED')->where('failed_at', '>=', now()->subDay())->count(),
            'EVENTS FAILED' => DB::table('sys_events')->where('status', 'FAILED')->count(),
        ];
        foreach ($simple as $h => $c) if ($c) { $n += $c; $out[] = "$h ($c)"; }
        $warn = array_merge((array) TaskWeekly::missed(), TaskTemplates::health());
        if ($warn) { $n += count($warn); $out[] = 'HEALTH (' . count($warn) . ')'; foreach (array_slice($warn, 0, self::MAX_LINES) as $w) $out[] = '- ' . (is_array($w) ? ($w['text'] ?? $w['message'] ?? json_encode($w)) : $w); }
        if (! $n && ! (int) AlertRules::threshold('digest_send_empty')) return null;
        return "Exceptions today, $first – " . date('D d M', strtotime($date)) . ":\n" . ($n ? implode("\n", $out) : 'No exceptions. All clear.') . "\nOpen Home: " . rtrim((string) config('app.url'), '/') . '/#/home';
    }

    private static function getsExceptions(object $u): bool { return Choices::has('EXCEPTIONS_DIGEST_ROLES', (int) ($u->fk_id_ent ?? 0) ?: ((int) Context::ent() ?: 1), (string) $u->role_code); }

    /** Preview: a boss for anyone, a person for himself. */
    public static function preview(object $viewer, int $userId): array
    {
        if ((int) $viewer->id !== $userId && ! in_array($viewer->role_code, TaskScore::BOSS, true)) throw new \InvalidArgumentException('Refused: you can preview only your own digest.');
        $acc = PersonChannels::account($userId); $date = (new \DateTimeImmutable('now', new \DateTimeZone(PersonChannels::tz($acc))))->format('Y-m-d');
        $u = DB::table('t_users')->where('id', $userId)->first(['id', 'role_code', 'fk_id_ent']);
        return ['user' => self::name($userId), 'date' => $date, 'at' => substr((string) ($acc->digest_time ?? '08:00') ?: '08:00', 0, 5) . ' ' . PersonChannels::tz($acc), 'text' => self::text($userId, $date, true),
            'team' => $u && in_array($u->role_code, TaskScore::BOSS, true) ? self::team($userId, $date) : null,
            'exceptions' => $u && self::getsExceptions($u) ? self::exceptions($userId, $date) : null];
    }

    /** The worker (every minute): send each digest that is due in the person's zone, once per local day, inside the 3-hour catch-up window. */
    public static function run(?\DateTimeInterface $nowUtc = null): int
    {
        $now = \DateTimeImmutable::createFromInterface($nowUtc ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'))); $n = 0; $off = (int) AlertRules::threshold('team_digest_offset_min');
        foreach (DB::table('t_users')->where('f_active', 1)->whereNotNull('fk_id_account')->get(['id', 'role_code', 'fk_id_ent']) as $u) {
            $acc = PersonChannels::account((int) $u->id); $local = $now->setTimezone(new \DateTimeZone(PersonChannels::tz($acc))); $date = $local->format('Y-m-d');
            $at = substr((string) ($acc->digest_time ?? ''), 0, 5) ?: '08:00';
            $due = fn (int $plus) => ($s = $local->setTime((int) substr($at, 0, 2), (int) substr($at, 3, 2))->modify("+$plus minutes")) <= $local && $local < $s->modify('+' . self::CATCH_UP_H . ' hours');
            if ($due(0) && ($txt = self::text((int) $u->id, $date)) !== null && AlertRules::send('N13', (int) $u->id, ['body' => $txt, 'date' => date('D d M', strtotime($date))], null, "N13:{$u->id}:$date")) $n++;
            if (in_array($u->role_code, TaskScore::BOSS, true) && $due($off) && ($tt = self::team((int) $u->id, $date)) !== null && AlertRules::send('N13', (int) $u->id, ['body' => $tt, 'date' => date('D d M', strtotime($date))], null, "N13T:{$u->id}:$date", 'tm_team_digest')) $n++;
            if (self::getsExceptions($u) && $due($off) && ($tx = self::exceptions((int) $u->id, $date)) !== null && AlertRules::send('N16', (int) $u->id, ['body' => $tx, 'date' => date('D d M', strtotime($date))], null, "N16:{$u->id}:$date")) $n++;
        }
        return $n;
    }
}
