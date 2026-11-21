<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * RULES_G_CORE R-G_CORE-50 timed alerts (run every minute by mo:events) and R-G_CORE-27 the STALE flag.
 * Every alert goes through AlertRules::send (rule on/off, channels, blocked tasks skipped for N2/N3/N5/N12, quiet hours, one queue, never twice).
 *  N2  not acknowledged after the category ack_hours → helpers + assigner (bosses for a system task); once at ack_hours × ack_escalate_multiplier → the category escalate_to role, "ESCALATED: …"
 *  N3  due tomorrow (company day) → owner, when the owner's local time ≥ due_tomorrow_time; once per due date
 *  N5  late (due < company today) → owner + assigner, when the receiver's local time ≥ late_time; once per local day per receiver; Managers added from day late_manager_day
 *  N6  stuck → AlertRules::stuckSweep
 *  N7  done, waiting approval → assigner (system task: Partners + Managers) at done and again every approval_repeat_hours; after approval_manager_hours also Managers ("you may approve")
 *  N12 stale → owner once per stale spell (key N12:{task}:{last update})
 */
class TaskAlerts
{
    public const ACTIVE = ['TODO', 'DOING', 'STUCK', 'REWORK'];      // open and not yet done

    private static function name(int $id): string { return (string) (DB::table('t_users')->where('id', $id)->value('display_name') ?: '#' . $id); }
    private static function first(int $id): string { $n = self::name($id); return strtok($n, ' ') ?: $n; }
    private static function link(): string { return rtrim((string) config('app.url'), '/') . '/#/tasks'; }

    /** The local time of a person (his time zone, else the company zone). */
    public static function local(int $userId, CarbonImmutable $now): CarbonImmutable { return $now->setTimezone(PersonChannels::tz(PersonChannels::account($userId))); }

    /** Company today (COMPANY_TIMEZONE). */
    public static function companyDay(CarbonImmutable $now, ?int $ent = null): string
    {
        return $now->setTimezone((string) (Settings::get('COMPANY_TIMEZONE', $ent, 'Europe/London') ?: 'Europe/London'))->format('Y-m-d');
    }

    /** Active Managers of the company. */
    private static function managers(?int $ent): array { return DB::table('t_users')->where('f_active', 1)->where('role_code', 'MANAGER')->when($ent, fn ($q) => $q->where('fk_id_ent', $ent))->pluck('id')->map(fn ($x) => (int) $x)->all(); }
    private static function partners(?int $ent): array { return DB::table('t_users')->where('f_active', 1)->whereIn('role_code', ['OWNER', 'PARTNER'])->when($ent, fn ($q) => $q->where('fk_id_ent', $ent))->pluck('id')->map(fn ($x) => (int) $x)->all(); }
    /** A system task (opened by a rule, or nobody assigned it): the bosses instead of the assigner. */
    private static function assigners(object $t): array { return $t->assigned_by_user_id && $t->source !== 'RULE' ? [(int) $t->assigned_by_user_id] : array_values(array_unique(array_merge(self::partners((int) $t->fk_id_ent), self::managers((int) $t->fk_id_ent)))); }

    private static function vars(object $t, int $to, array $x = []): array
    {
        return $x + ['first_name' => self::first($to), 'task_ref' => TaskFlow::ref((int) $t->id), 'title' => $t->title, 'owner_name' => $t->owner_user_id ? self::name((int) $t->owner_user_id) : 'the team',
            'due' => $t->due_at ? date('D d M', strtotime((string) $t->due_at)) : 'no date', 'link' => self::link()];
    }

    /** One run (every minute): N2, N3, N5, N6, N7, N12. Returns the number of messages written per rule. */
    public static function run(?\DateTimeInterface $now = null): array
    {
        $now = $now ? CarbonImmutable::instance($now)->utc() : CarbonImmutable::now('UTC');
        return ['N2' => self::notAck($now), 'N3' => self::dueTomorrow($now), 'N5' => self::late($now), 'N6' => AlertRules::stuckSweep($now), 'N7' => self::waiting($now), 'N12' => self::stale($now)];
    }

    /** N2: counted from reassigned_at when the task was reassigned (new), else from created_at; the category ack_hours (24 without a category). */
    public static function notAck(CarbonImmutable $now): int
    {
        $sent = 0; $mult = max(1, (float) AlertRules::threshold('ack_escalate_multiplier'));
        $cats = DB::table('task_categories')->get()->keyBy('id');
        foreach (DB::table('tasks')->whereIn('status', self::ACTIVE)->whereNull('acknowledged_at')->whereNotNull('owner_user_id')->get() as $t) {
            $from = CarbonImmutable::parse((string) ($t->reassigned_at ?? $t->created_at), 'UTC');
            $c = $cats[$t->category_id] ?? null; $ack = (float) ($c->ack_hours ?? 24); if ($ack <= 0) continue;
            $hours = $from->diffInMinutes($now, false) / 60; if ($hours < $ack) continue;
            $since = $from->format('YmdHi');
            $to = array_merge(DB::table('task_helpers')->where('task_id', $t->id)->pluck('user_id')->map(fn ($x) => (int) $x)->all(), self::assigners($t));
            foreach (array_diff(array_unique($to), [(int) $t->owner_user_id]) as $uid)
                $sent += count(AlertRules::send('N2', $uid, self::vars($t, $uid, ['hours' => self::num($ack)]), (int) $t->id, "N2:$t->id:$since:1"));
            if ($hours >= $ack * $mult) {      // escalation once
                $role = $c->escalate_to_role ?? 'MANAGER';
                $up = $role === 'PARTNER' ? self::partners((int) $t->fk_id_ent) : (self::managers((int) $t->fk_id_ent) ?: self::partners((int) $t->fk_id_ent));
                foreach (array_diff($up, [(int) $t->owner_user_id]) as $uid)
                    $sent += count(AlertRules::send('N2', $uid, self::vars($t, $uid, ['hours' => self::num($ack * $mult)]), (int) $t->id, "N2:$t->id:$since:2", null, 'en', 'ESCALATED: '));
            }
        }
        return $sent;
    }

    private static function num(float $h): string { return rtrim(rtrim(number_format($h, 2, '.', ''), '0'), '.'); }

    /** N3: due = tomorrow (company day) and the owner's local time ≥ due_tomorrow_time; once per due date. */
    public static function dueTomorrow(CarbonImmutable $now): int
    {
        $sent = 0; $at = AlertRules::threshold('due_tomorrow_time') ?: '09:00';
        foreach (DB::table('tasks')->whereIn('status', self::ACTIVE)->whereNotNull('owner_user_id')->whereNotNull('due_at')->get() as $t) {
            $tomorrow = CarbonImmutable::parse(self::companyDay($now, (int) $t->fk_id_ent))->addDay()->format('Y-m-d');
            $due = substr((string) $t->due_at, 0, 10); if ($due !== $tomorrow) continue;
            if (self::local((int) $t->owner_user_id, $now)->format('H:i') < $at) continue;
            $sent += count(AlertRules::send('N3', (int) $t->owner_user_id, self::vars($t, (int) $t->owner_user_id), (int) $t->id, "N3:$t->id:$due"));
        }
        return $sent;
    }

    /** N5: due < company today; daily at the receiver's local late_time; owner + assigner, Managers from day late_manager_day. */
    public static function late(CarbonImmutable $now): int
    {
        $sent = 0; $at = AlertRules::threshold('late_time') ?: '09:00'; $mgrDay = max(1, (int) AlertRules::threshold('late_manager_day'));
        foreach (DB::table('tasks')->whereIn('status', self::ACTIVE)->whereNotNull('owner_user_id')->whereNotNull('due_at')->get() as $t) {
            $today = self::companyDay($now, (int) $t->fk_id_ent); $due = substr((string) $t->due_at, 0, 10); if ($due >= $today) continue;
            $days = (int) CarbonImmutable::parse($due)->diffInDays(CarbonImmutable::parse($today));
            $to = array_merge([(int) $t->owner_user_id], $t->assigned_by_user_id ? [(int) $t->assigned_by_user_id] : [], $days >= $mgrDay ? self::managers((int) $t->fk_id_ent) : []);
            foreach (array_unique($to) as $uid) {
                $loc = self::local($uid, $now); if ($loc->format('H:i') < $at) continue;
                $sent += count(AlertRules::send('N5', $uid, self::vars($t, $uid, ['days_late' => $days, 'days' => $days]), (int) $t->id, "N5:$t->id:" . $loc->format('Ymd')));
            }
        }
        return $sent;
    }

    /** N7: at done (n = 0) and every approval_repeat_hours after; once after approval_manager_hours also the Managers. */
    public static function waiting(CarbonImmutable $now): int
    {
        $sent = 0; $every = max(1, (int) AlertRules::threshold('approval_repeat_hours')); $mgrH = max(1, (int) AlertRules::threshold('approval_manager_hours'));
        foreach (DB::table('tasks')->where('status', 'DONE')->whereNotNull('done_at')->get() as $t) {
            $done = CarbonImmutable::parse((string) $t->done_at, 'UTC'); $h = $done->diffInMinutes($now, false) / 60; if ($h < 0) continue;
            $n = intdiv((int) floor($h), $every); $key = $done->format('YmdHi');
            $to = self::assigners($t);
            foreach (array_diff($to, [(int) $t->owner_user_id]) as $uid)
                $sent += count(AlertRules::send('N7', $uid, self::vars($t, $uid), (int) $t->id, "N7:$t->id:$key:$n"));
            if ($h >= $mgrH) foreach (array_diff(self::managers((int) $t->fk_id_ent), $to, [(int) $t->owner_user_id]) as $uid)
                $sent += count(AlertRules::send('N7', $uid, self::vars($t, $uid), (int) $t->id, "N7M:$t->id:$key", null, 'en', '', ' You may approve it.'));
        }
        return $sent;
    }

    /** R-G_CORE-27: the STALE flag on open TODO / DOING / STUCK / REWORK tasks without an update for stale_days; N12 once per spell; flag removed after the next update. */
    public static function stale(CarbonImmutable $now): int
    {
        $sent = 0; $days = max(1, (int) AlertRules::threshold('stale_days'));
        foreach (DB::table('tasks')->whereIn('status', TaskFlow::OPEN)->get(['id', 'status', 'flags', 'last_update_at', 'created_at', 'owner_user_id', 'title', 'due_at', 'fk_id_ent', 'assigned_by_user_id', 'source']) as $t) {
            $last = CarbonImmutable::parse((string) ($t->last_update_at ?: $t->created_at), 'UTC');
            $f = array_values(array_filter(explode(',', (string) $t->flags))); $is = in_array('STALE', $f, true);
            $should = in_array($t->status, self::ACTIVE, true) && $last->lt($now->subDays($days));
            if ($should && ! $is) DB::table('tasks')->where('id', $t->id)->update(['flags' => implode(',', array_merge($f, ['STALE']))]);
            if (! $should && $is) { DB::table('tasks')->where('id', $t->id)->update(['flags' => implode(',', array_diff($f, ['STALE']))]); continue; }
            if ($should && $t->owner_user_id) {
                $d = (int) $last->startOfDay()->diffInDays($now->startOfDay());
                $sent += count(AlertRules::send('N12', (int) $t->owner_user_id, self::vars($t, (int) $t->owner_user_id, ['days' => $d]), (int) $t->id, "N12:$t->id:" . $last->format('YmdHis')));
            }
        }
        return $sent;
    }

    /** An update (comment by owner / helper, checklist, proof, status move): the STALE flag goes at once. */
    public static function fresh(int $taskId): void
    {
        $f = array_values(array_filter(explode(',', (string) DB::table('tasks')->where('id', $taskId)->value('flags'))));
        if (in_array('STALE', $f, true)) DB::table('tasks')->where('id', $taskId)->update(['flags' => implode(',', array_diff($f, ['STALE']))]);
    }
}
