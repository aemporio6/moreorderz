<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * R-G_CORE-48 channels per person (t_account, Person › Alerts & messages and Tasks › My alerts and messages) and R-G_CORE-47 quiet hours.
 * - WhatsApp number stored in international form "+" and 8–15 digits (everything else removed), shown masked to others "+44 7•• ••• 123".
 * - Time zone = the person's, else COMPANY_TIMEZONE (no zone fixed in code). Quiet from / to HH:MM, both or neither, overnight spans allowed.
 * - Digest time HH:MM (default 08:00), weekly task limit 1–99 (default 6), WhatsApp / e-mail alerts On / Off (default On).
 * - Who edits: a Partner or Manager for anyone; a person his own times and channels (OWNER_QUESTIONS B22); only a Partner switches a channel for someone else.
 */
class PersonChannels
{
    public const FIELDS = ['whatsapp_number', 'timezone', 'quiet_from', 'quiet_to', 'digest_time', 'weekly_task_limit', 'wa_enabled', 'email_enabled'];

    public static function account(int $userId): ?object
    {
        $acc = (int) DB::table('t_users')->where('id', $userId)->value('fk_id_account');
        return $acc ? DB::table('t_account')->where('id', $acc)->first(['id', 'fk_id_ent', 'whatsapp_number', 'timezone', 'quiet_from', 'quiet_to', 'digest_time', 'wa_enabled', 'email_enabled', 'weekly_task_limit']) : null;
    }

    public static function tz(?object $acc): string
    {
        $z = trim((string) ($acc->timezone ?? '')) ?: (string) (Settings::get('COMPANY_TIMEZONE', isset($acc->fk_id_ent) ? (int) $acc->fk_id_ent : null, 'Europe/London') ?: 'Europe/London');
        return in_array($z, \DateTimeZone::listIdentifiers(), true) ? $z : 'Europe/London';
    }

    private static function hm(?string $v): ?string { $v = trim((string) $v); return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', substr($v, 0, 5)) ? substr($v, 0, 5) : null; }

    /** The end of the quiet span (UTC 'Y-m-d H:i:s') when $nowUtc falls inside it, else null. */
    public static function quietUntil(?object $acc, ?\DateTimeInterface $nowUtc = null): ?array
    {
        $from = self::hm($acc->quiet_from ?? null); $to = self::hm($acc->quiet_to ?? null);
        if (! $from || ! $to || $from === $to) return null;
        $tz = new \DateTimeZone(self::tz($acc)); $now = \DateTimeImmutable::createFromInterface($nowUtc ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTimezone($tz);
        $t = $now->format('H:i'); $in = $from < $to ? ($t >= $from && $t < $to) : ($t >= $from || $t < $to);
        if (! $in) return null;
        $end = $now->setTime((int) substr($to, 0, 2), (int) substr($to, 3, 2)); if ($end <= $now) $end = $end->modify('+1 day');
        return [$end->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), "after $to (quiet hours)"];
    }

    /** Improvement of R-G_CORE-47: a rule marked "INFO waits for the next working day" sent on a Saturday / Sunday goes on Monday at the end of quiet hours (else 08:00). */
    public static function workdayFrom(?object $acc, ?\DateTimeInterface $nowUtc = null): ?array
    {
        $tz = new \DateTimeZone(self::tz($acc)); $now = \DateTimeImmutable::createFromInterface($nowUtc ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTimezone($tz);
        if ((int) $now->format('N') < 6) return null;
        $at = self::hm($acc->quiet_to ?? null) ?: '08:00'; $mon = $now->modify('next monday')->setTime((int) substr($at, 0, 2), (int) substr($at, 3, 2));
        return [$mon->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), "after Monday $at (next working day)"];
    }

    public static function mask(?string $n): string
    {
        $d = preg_replace('/\D/', '', (string) $n); if (strlen($d) < 8) return '';
        $cc = strlen($d) > 10 ? substr($d, 0, strlen($d) - 10) : substr($d, 0, 2); $rest = substr($d, strlen($cc));
        return '+' . $cc . ' ' . substr($rest, 0, 1) . '•• ••• ' . substr($rest, -3);
    }

    /** Form check of the channel fields (cleans $data in place). Only fields that change are checked against the rights. */
    public static function check(array &$data, array $old, ?int $id): array
    {
        $e = []; $chg = fn (string $f) => array_key_exists($f, $data) && (string) ($data[$f] ?? '') !== (string) ($old[$f] ?? '');
        $changed = array_values(array_filter(self::FIELDS, $chg)); if (! $changed) return [];
        $u = Auth::user(); $role = $u->role_code ?? Context::role(); $self = $u && $id && (int) ($u->fk_id_account ?? 0) === $id;
        $boss = in_array($role, ['OWNER', 'PARTNER', 'MANAGER'], true); $ent = Context::ent() ?: ($u->fk_id_ent ?? null);
        $partner = Choices::has('CHANNEL_SWITCH_ROLES', $ent ? (int) $ent : null, $role); $selfOk = Choices::get('CHANNELS_SELF_EDIT', $ent ? (int) $ent : null) === '1';      /* Settings › Choices */
        if ($u && ! $boss && ! ($self && $selfOk && ! in_array('weekly_task_limit', $changed, true))) return ['_form' => 'Refused: only a Partner or Manager can edit channels.'];
        if ($u && ! $self && ! $partner && (in_array('wa_enabled', $changed, true) || in_array('email_enabled', $changed, true))) return ['wa_enabled' => 'Refused: only a Partner can switch a channel on or off for someone.'];
        if ($chg('whatsapp_number') && trim((string) $data['whatsapp_number']) !== '') {
            $raw = trim((string) $data['whatsapp_number']); $n = preg_replace('/[^\d+]/', '', $raw);
            if (! preg_match('/^\+\d{8,15}$/', $n)) $e['whatsapp_number'] = 'Refused: WhatsApp number must be in international form, e.g. +447700900123 or +923001234567.';
            else $data['whatsapp_number'] = $n;
        }
        foreach (['quiet_from', 'quiet_to', 'digest_time'] as $f) if ($chg($f) && trim((string) $data[$f]) !== '') {
            if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d(:00)?$/', trim((string) $data[$f]))) $e[$f] = 'Refused: times must look like 22:00.'; else $data[$f] = substr(trim((string) $data[$f]), 0, 5);
        }
        $qf = trim((string) (array_key_exists('quiet_from', $data) ? $data['quiet_from'] : ($old['quiet_from'] ?? ''))); $qt = trim((string) (array_key_exists('quiet_to', $data) ? $data['quiet_to'] : ($old['quiet_to'] ?? '')));
        if (! isset($e['quiet_from']) && ! isset($e['quiet_to']) && ($qf === '') !== ($qt === '')) $e['quiet_to'] = 'Refused: give both quiet-hours times, or neither.';
        if ($chg('timezone') && trim((string) $data['timezone']) !== '' && ! in_array(trim((string) $data['timezone']), \DateTimeZone::listIdentifiers(), true)) $e['timezone'] = 'Choose a time zone from the list.';
        if ($chg('weekly_task_limit') && $data['weekly_task_limit'] !== '' && $data['weekly_task_limit'] !== null && (! ctype_digit((string) $data['weekly_task_limit']) || (int) $data['weekly_task_limit'] < 1 || (int) $data['weekly_task_limit'] > 99)) $e['weekly_task_limit'] = 'Weekly task limit is a whole number from 1 to 99.';
        return $e;
    }
}
