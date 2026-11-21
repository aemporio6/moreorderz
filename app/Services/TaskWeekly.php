<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * R-G_CORE-44 weekly task report (N14). Period = the last full Monday–Sunday (company time zone). Tiles closed, late now, stuck now, average score; by person
 * (closed, avg score, late, stuck); "Due date moved more than twice" ("T-0012 title – 3× (owner)"); the leaderboard of the month of that week's Monday.
 * Sent at weekly_report (MON 09:00, company time, catch-up 3 h) to every Partner and Manager by e-mail (HTML) + A4 PDF (weekly_report_pdf = 1, only when the PDF maker
 * works) and in-app; key N14:{ISO year-week}, never twice. A run that comes later than the window does not send and writes "weekly report missed" for System › Health.
 * The screen Tasks › Weekly report shows any week with Print / PDF.
 */
class TaskWeekly
{
    public static function tz(): \DateTimeZone { return new \DateTimeZone((string) (Settings::get('COMPANY_TIMEZONE', (int) Context::ent(), 'Europe/London') ?: 'Europe/London')); }

    /** [monday, sunday] of the last full week before $now (company time). */
    public static function lastWeek(\DateTimeImmutable $now): array
    {
        $local = $now->setTimezone(self::tz()); $mon = $local->modify('monday this week')->modify('-7 days');
        return [$mon->format('Y-m-d'), $mon->modify('+6 days')->format('Y-m-d')];
    }

    public static function data(string $monday): array
    {
        $sun = date('Y-m-d', strtotime("$monday +6 days")); $today = date('Y-m-d');
        $closed = DB::table('tasks')->where('status', 'APPROVED')->whereBetween('approved_at', [$monday . ' 00:00:00', $sun . ' 23:59:59']);
        $open = fn () => DB::table('tasks')->whereIn('status', ['TODO', 'DOING', 'STUCK', 'REWORK']);
        $avg = DB::table('task_scores')->whereBetween('closed_at', [$monday . ' 00:00:00', $sun . ' 23:59:59'])->avg('score');
        $people = [];
        foreach (DB::table('t_users')->where('f_active', 1)->orderBy('display_name')->get(['id', 'display_name']) as $u) {
            $c = (clone $closed)->where('owner_user_id', $u->id)->count(); $l = $open()->where('owner_user_id', $u->id)->whereRaw('DATE(due_at) < ?', [$today])->count(); $s = $open()->where('owner_user_id', $u->id)->where('status', 'STUCK')->count();
            $sc = DB::table('task_scores')->where('user_id', $u->id)->whereBetween('closed_at', [$monday . ' 00:00:00', $sun . ' 23:59:59'])->avg('score');
            if ($c || $l || $s) $people[] = ['name' => $u->display_name, 'closed' => $c, 'score' => $sc !== null ? (int) round($sc) : null, 'late' => $l, 'stuck' => $s];
        }
        $moved = DB::table('tasks as t')->leftJoin('t_users as u', 'u.id', '=', 't.owner_user_id')->where('t.due_changes', '>', 2)->whereNotIn('t.status', ['APPROVED', 'CANCELLED'])->orderByDesc('t.due_changes')->get(['t.id', 't.title', 't.due_changes', 'u.display_name'])
            ->map(fn ($t) => TaskFlow::ref((int) $t->id) . ' ' . $t->title . ' – ' . (int) $t->due_changes . '× (' . ($t->display_name ?: '—') . ')')->all();
        return ['week' => $monday . ' – ' . $sun, 'iso' => date('o-\WW', strtotime($monday)), 'monday' => $monday,
            'tiles' => ['closed' => (clone $closed)->count(), 'late' => $open()->whereRaw('DATE(due_at) < ?', [$today])->count(), 'stuck' => $open()->where('status', 'STUCK')->count(), 'avg' => $avg !== null ? (int) round($avg) : null],
            'people' => $people, 'moved' => $moved, 'leaderboard' => TaskTeam::leaderboard(substr($monday, 0, 7))['rows']];
    }

    public static function html(array $d): string
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES); $t = $d['tiles'];
        $rows = implode('', array_map(fn ($p) => '<tr><td>' . $e($p['name']) . '</td><td>' . $p['closed'] . '</td><td>' . ($p['score'] ?? '—') . '</td><td>' . $p['late'] . '</td><td>' . $p['stuck'] . '</td></tr>', $d['people']));
        $lb = implode('', array_map(fn ($r) => '<tr><td>' . $r['rank'] . '</td><td>' . $e($r['name']) . '</td><td>' . $r['closed'] . '</td><td>' . $r['on_time'] . '%</td><td>' . $r['proof'] . '%</td><td>' . $r['rework'] . '</td><td>' . ($r['avg'] ?? '—') . '</td><td>' . $e($r['trend']) . '</td></tr>', $d['leaderboard']));
        return '<div style="font-family:Arial,sans-serif;font-size:12px"><h2>Weekly task report ' . $e($d['week']) . '</h2>'
            . '<p><b>Closed</b> ' . $t['closed'] . ' · <b>Late now</b> ' . $t['late'] . ' · <b>Stuck now</b> ' . $t['stuck'] . ' · <b>Average score</b> ' . ($t['avg'] ?? '—') . '</p>'
            . '<h3>By person</h3><table border="1" cellpadding="4" cellspacing="0"><tr><th>Person</th><th>Closed</th><th>Avg score</th><th>Late</th><th>Stuck</th></tr>' . $rows . '</table>'
            . '<h3>Due date moved more than twice</h3>' . ($d['moved'] ? '<ul><li>' . implode('</li><li>', array_map($e, $d['moved'])) . '</li></ul>' : '<p>None.</p>')
            . '<h3>Leaderboard ' . $e(substr($d['monday'], 0, 7)) . '</h3><table border="1" cellpadding="4" cellspacing="0"><tr><th>#</th><th>Name</th><th>Closed</th><th>On time</th><th>Proof</th><th>Rework</th><th>Avg score</th><th>Trend</th></tr>' . $lb . '</table></div>';
    }

    /** The job (every minute): sends once per ISO week inside weekly_report + 3 h; later than that → "weekly report missed" (System › Health). */
    public static function run(?\DateTimeImmutable $now = null): int
    {
        $now ??= new \DateTimeImmutable(); $local = $now->setTimezone(self::tz());
        [$day, $hm] = array_pad(explode(' ', strtoupper((string) AlertRules::threshold('weekly_report') ?: 'MON 09:00')), 2, '09:00');
        if (strtoupper($local->format('D')) !== $day) return 0;
        $at = $local->setTime((int) substr($hm, 0, 2), (int) substr($hm, 3, 2)); if ($local < $at) return 0;
        [$mon] = self::lastWeek($now); $key = 'N14:' . date('o-\WW', strtotime($mon));
        if (DB::table('tm_messages')->where('event_key', 'like', $key . '|%')->exists() || DB::table('sys_job_runs')->where('job_code', 'WEEKLY_REPORT')->where('error_text', $key)->exists()) return 0;
        if ($local >= $at->modify('+3 hours')) {      // too late: not sent, the Health page says so
            DB::table('sys_job_runs')->insert(['job_code' => 'WEEKLY_REPORT', 'fk_id_ent' => (int) Context::ent(), 'started_at' => now(), 'finished_at' => now(), 'run_status' => 'MISSED', 'items_done' => 0, 'messages_queued' => 0, 'error_text' => $key]);
            return 0;
        }
        $d = self::data($mon); $html = self::html($d); $pdf = null;
        if ((int) AlertRules::threshold('weekly_report_pdf') && class_exists(PrintService::$maker)) {
            try { $pdf = "weekly_reports/tasks_{$d['iso']}.pdf"; Storage::disk('local')->put($pdf, PrintService::pdf($html, 210, 297)); } catch (\Throwable) { $pdf = null; }      // no PDF maker → the e-mail goes without it
        }
        $n = 0;
        foreach (DB::table('t_users')->whereIn('role_code', ['OWNER', 'PARTNER', 'MANAGER'])->where('f_active', 1)->get(['id', 'display_name']) as $u) {
            $ids = AlertRules::send('N14', (int) $u->id, ['first_name' => strtok((string) $u->display_name, ' '), 'week' => $d['week'], 'done' => $d['tiles']['closed'], 'late' => $d['tiles']['late'], 'stuck' => $d['tiles']['stuck'],
                'link' => rtrim((string) config('app.url'), '/') . '/#/tasks?scope=weekly&week=' . $mon], null, $key);
            DB::table('tm_messages')->whereIn('id', $ids ?: [0])->where('channel', 'EMAIL')->update(['text' => $html, 'attachment' => $pdf]);
            if ($ids) $n++;
        }
        DB::table('sys_job_runs')->insert(['job_code' => 'WEEKLY_REPORT', 'fk_id_ent' => (int) Context::ent(), 'started_at' => now(), 'finished_at' => now(), 'run_status' => 'OK', 'items_done' => $n, 'messages_queued' => $n, 'error_text' => $key]);
        return $n;
    }

    /** Health: weekly reports missed in the last 8 weeks. */
    public static function missed(): array
    {
        return DB::table('sys_job_runs')->where('job_code', 'WEEKLY_REPORT')->where('run_status', 'MISSED')->where('started_at', '>=', now()->subWeeks(8))->orderByDesc('id')->pluck('error_text')->map(fn ($k) => "weekly report missed ($k)")->all();
    }
}
