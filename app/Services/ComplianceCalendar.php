<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * MR-1545 compliance calendar: every filing, renewal and deadline in one month calendar –
 * the Tax & filings obligations (accounts, CT, VAT, PAYE, confirmation statement, Self Assessment …, money.SEE) and every due item of the due engine
 * (card expiry, subscriptions, trademarks, contracts, salaries … – the same role filter as Due dates).
 * Download as an .ics file that Google Calendar / Outlook import (one event per date; no live sync – a re-import replaces by UID).
 */
class ComplianceCalendar
{
    public static function items(int $ent, object $u, string $from, string $to): array
    {
        $out = [];
        if (Perm::has($u, 'money.SEE'))
            foreach (DB::table('acc_obligations')->where('fk_id_ent', $ent)->whereBetween('due_date', [$from, $to])->orderBy('due_date')->get(['id', 'title', 'type_code', 'area', 'due_date', 'status', 'f_done']) as $o)
                $out[] = ['uid' => 'acc-' . $o->id, 'date' => (string) $o->due_date, 'title' => (string) ($o->title ?: $o->type_code), 'kind' => 'Filing · ' . ($o->area ?: $o->type_code), 'done' => (bool) $o->f_done || in_array($o->status, ['FILED', 'PAID', 'NOT_REQUIRED'], true),
                    'status' => (string) $o->status, 'go' => '#/compliance'];
        $due = DueEngine::list($ent, $u, []);
        foreach ($due['rows'] as $d) if ($d->due_date >= $from && $d->due_date <= $to)
            $out[] = ['uid' => 'due-' . $d->id, 'date' => (string) $d->due_date, 'title' => (string) $d->title, 'kind' => (string) ($d->chip ?: 'Due'), 'done' => false, 'status' => (string) $d->status, 'go' => '#/due'];
        usort($out, fn ($a, $b) => strcmp($a['date'], $b['date']) ?: strcmp($a['title'], $b['title']));
        return $out;
    }

    /** One month: days with their items, counts per kind, overdue count. */
    public static function month(int $ent, object $u, ?string $ym = null): array
    {
        $ym = preg_match('/^\d{4}-\d{2}$/', (string) $ym) ? $ym : date('Y-m');
        $from = "$ym-01"; $to = date('Y-m-t', strtotime($from)); $today = date('Y-m-d');
        $items = self::items($ent, $u, $from, $to); $days = [];
        foreach ($items as $i) { $i['late'] = ! $i['done'] && $i['date'] < $today; $days[$i['date']][] = $i; }
        $kinds = []; foreach ($items as $i) $kinds[$i['kind']] = ($kinds[$i['kind']] ?? 0) + 1;
        return ['ym' => $ym, 'from' => $from, 'to' => $to, 'first_weekday' => (int) date('N', strtotime($from)), 'days_in_month' => (int) date('t', strtotime($from)), 'today' => $today,
            'days' => $days, 'kinds' => $kinds, 'total' => count($items), 'late' => count(array_filter($items, fn ($i) => ! $i['done'] && $i['date'] < $today)),
            'prev' => date('Y-m', strtotime("$from -1 month")), 'next' => date('Y-m', strtotime("$from +1 month"))];
    }

    /** iCalendar text for the next $months months (all-day events, stable UIDs). */
    public static function ics(int $ent, object $u, int $months = 12): string
    {
        $from = date('Y-m-01'); $to = date('Y-m-t', strtotime("$from +" . max(0, $months - 1) . ' months'));
        $esc = fn (string $s) => str_replace(["\\", ';', ',', "\n", "\r"], ['\\\\', '\;', '\,', '\n', ''], $s);
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'moreorderz';
        $l = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//MoreOrderz//Compliance calendar//EN', 'CALSCALE:GREGORIAN', 'X-WR-CALNAME:MoreOrderz deadlines'];
        foreach (self::items($ent, $u, $from, $to) as $i) {
            if ($i['done']) continue;
            $d = str_replace('-', '', $i['date']); $n = date('Ymd', strtotime($i['date'] . ' +1 day'));
            array_push($l, 'BEGIN:VEVENT', 'UID:' . $i['uid'] . '-' . $ent . '@' . $host, 'DTSTAMP:' . gmdate('Ymd\THis\Z'), "DTSTART;VALUE=DATE:$d", "DTEND;VALUE=DATE:$n",
                'SUMMARY:' . $esc($i['title']), 'CATEGORIES:' . $esc($i['kind']), 'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:' . $esc($i['title']), 'TRIGGER:-P7D', 'END:VALARM', 'END:VEVENT');
        }
        $l[] = 'END:VCALENDAR';
        return implode("\r\n", array_map(fn ($x) => mb_strlen($x) > 74 ? implode("\r\n ", mb_str_split($x, 73)) : $x, $l)) . "\r\n";
    }
}
