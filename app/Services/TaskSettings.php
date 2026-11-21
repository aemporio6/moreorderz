<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The task settings forms: R-G_CORE-16 absence and cover (user_absence – persons), R-G_CORE-17 approval delegation (tm_delegations – logins),
 * R-G_CORE-23 categories = SLA table (task_categories), R-G_CORE-22 the hard limits box. Every refusal starts with "Refused:"; rows are never deleted ("End now").
 */
class TaskSettings
{
    private static function role(): string { return (string) (Auth::user()->role_code ?? Context::role() ?? ''); }
    private static function boss(): bool { return in_array(self::role(), ['OWNER', 'PARTNER', 'MANAGER'], true); }
    private static function partner(): bool { return in_array(self::role(), ['OWNER', 'PARTNER'], true); }
    private static function v(array $data, array $old, string $k) { return array_key_exists($k, $data) ? $data[$k] : ($old[$k] ?? null); }
    private static function name(int $acc): string { return (string) DB::table('t_account')->where('id', $acc)->selectRaw("TRIM(CONCAT_WS(' ', first_name, sur_name)) n")->value('n'); }

    /** R-G_CORE-16 form check of an absence (user_id / cover_user_id are persons). */
    public static function absence(array &$data, array $old, ?int $id): array
    {
        if (Auth::user() && ! self::boss()) return ['_form' => 'Refused: only a Partner or Manager can set absences.'];
        $who = (int) self::v($data, $old, 'user_id'); $cov = (int) self::v($data, $old, 'cover_user_id'); $from = (string) self::v($data, $old, 'from_date'); $to = (string) self::v($data, $old, 'to_date');
        if (! $from || ! $to || $to < $from) return ['to_date' => 'Refused: give a from-date and a to-date on or after it.'];
        if ($cov && $cov === $who) return ['cover_user_id' => 'Refused: someone cannot cover for themselves.'];
        $overlap = fn (int $acc) => DB::table('user_absence')->where('user_id', $acc)->when($id, fn ($q) => $q->where('id', '<>', $id))->where('from_date', '<=', $to)
            ->where(fn ($w) => $w->whereNull('to_date')->orWhere('to_date', '>=', $from))->whereRaw('(to_date IS NULL OR to_date >= from_date)')->first();
        if ($cov && $overlap($cov)) return ['cover_user_id' => 'Refused: the cover person is also absent in those dates.'];
        if ($o = $overlap($who)) return ['from_date' => 'Refused: that person already has an absence in those dates. ' . self::name($who) . ' is already away ' . date('d', strtotime($o->from_date)) . '–' . date('d M', strtotime((string) ($o->to_date ?: $o->from_date))) . '.'];
        return [];
    }

    /** "Back today" / "End now": to_date = yesterday; an absence that starts today or later is cancelled (to_date = from_date − 1). Never deleted. */
    public static function endAbsence(int $id, object $u): string
    {
        if (! in_array($u->role_code, ['OWNER', 'PARTNER', 'MANAGER'], true)) throw new \InvalidArgumentException('Refused: only a Partner or Manager can set absences.');
        $a = DB::table('user_absence')->where('id', $id)->first() ?? throw new \InvalidArgumentException('Absence not found.');
        $y = date('Y-m-d', strtotime('-1 day'));
        $to = $a->from_date >= date('Y-m-d') ? date('Y-m-d', strtotime($a->from_date . ' -1 day')) : $y;
        DB::table('user_absence')->where('id', $id)->update(['to_date' => $to]);
        Audit::log('user_absence', $id, 'UPDATE', ['to_date' => [$a->to_date, $to]], 'End now');
        return $a->from_date >= date('Y-m-d') ? 'Absence cancelled.' : 'Back today – the absence ended yesterday.';
    }

    /** Partners away today or later with no delegation covering those dates: the old message + a proposed delegation to another Partner (new). */
    public static function partnerGaps(): array
    {
        $out = []; $today = date('Y-m-d');
        foreach (DB::table('user_absence as a')->join('t_users as u', 'u.fk_id_account', '=', 'a.user_id')->whereIn('u.role_code', ['OWNER', 'PARTNER'])->where('u.f_active', 1)
            ->where(fn ($w) => $w->whereNull('a.to_date')->orWhere('a.to_date', '>=', $today))->whereRaw('(a.to_date IS NULL OR a.to_date >= a.from_date)')->get(['a.*', 'u.id as uid', 'u.display_name']) as $a) {
            $has = DB::table('tm_delegations')->where('from_user_id', $a->uid)->where('from_date', '<=', $a->from_date)->where(fn ($w) => $w->whereNull('to_date')->orWhere('to_date', '>=', $a->to_date ?? '9999-12-31'))->exists();
            if ($has) continue;
            $to = DB::table('t_users')->whereIn('role_code', ['OWNER', 'PARTNER'])->where('f_active', 1)->where('id', '<>', $a->uid)->orderBy('id')->first(['id', 'display_name']);
            $out[] = ['text' => 'Partner absent: add a delegation so a Manager can approve their tasks.', 'partner' => $a->display_name, 'from_user_id' => (int) $a->uid, 'from_date' => $a->from_date, 'to_date' => $a->to_date,
                'proposal' => $to ? ['to_user_id' => (int) $to->id, 'to' => $to->display_name] : null];
        }
        return $out;
    }

    /** R-G_CORE-17 form check of a delegation (logins). */
    public static function delegation(array &$data, array $old, ?int $id): array
    {
        if (Auth::user() && ! self::partner()) return ['_form' => 'Refused: only a Partner can set delegations.'];
        $f = (int) self::v($data, $old, 'from_user_id'); $t = (int) self::v($data, $old, 'to_user_id');
        $act = fn ($x) => $x && DB::table('t_users')->where('id', $x)->where('f_active', 1)->exists();
        if (! $f || ! $t || $f === $t || ! $act($f) || ! $act($t)) return ['to_user_id' => 'Refused: pick two different active people.'];
        $from = (string) self::v($data, $old, 'from_date'); $to = (string) self::v($data, $old, 'to_date');
        if (! $from || ! $to || $to < $from) return ['to_date' => 'Refused: give a from-date and a to-date on or after it.'];
        if (! array_key_exists('rights', $data) || ! $data['rights']) $data['rights'] = $old['rights'] ?? 'APPROVE';
        return [];
    }

    public static function endDelegation(int $id, object $u): string
    {
        if (! in_array($u->role_code, ['OWNER', 'PARTNER'], true)) throw new \InvalidArgumentException('Refused: only a Partner can set delegations.');
        $d = DB::table('tm_delegations')->where('id', $id)->first() ?? throw new \InvalidArgumentException('Delegation not found.');
        $y = date('Y-m-d', strtotime('-1 day')); DB::table('tm_delegations')->where('id', $id)->update(['to_date' => max($y, date('Y-m-d', strtotime($d->from_date . ' -1 day')))]);
        Audit::log('tm_delegations', $id, 'UPDATE', ['to_date' => [$d->to_date, $y]], 'End now');
        return 'Delegation ended.';
    }

    /** The delegation that lets $u act for the person who assigned / owns the money (today, APPROVE or ALL). */
    public static function delegationFor(int $fromUser, object $u, array $rights = ['APPROVE', 'ALL']): ?object
    {
        $today = date('Y-m-d');
        return DB::table('tm_delegations')->where('from_user_id', $fromUser)->where('to_user_id', $u->id)->whereIn('rights', $rights)
            ->where(fn ($w) => $w->whereNull('from_date')->orWhere('from_date', '<=', $today))->where(fn ($w) => $w->whereNull('to_date')->orWhere('to_date', '>=', $today))->first();
    }

    /** R-G_CORE-23 form check of a category (Partner only). */
    public static function category(array &$data, array $old, ?int $id): array
    {
        if (Auth::user() && ! self::partner()) return ['_form' => 'Refused: only a Partner can change task categories.'];
        $name = trim((string) self::v($data, $old, 'name'));
        if ($name === '') return ['name' => 'Refused: the category name is required.'];
        if (mb_strlen($name) > 100) return ['name' => 'Refused: the category name is longer than 100 characters.'];
        if (DB::table('task_categories')->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($name)])->when($id, fn ($q) => $q->where('id', '<>', $id))->exists()) return ['name' => 'Refused: a category with that name already exists.'];
        if (array_key_exists('name', $data)) $data['name'] = $name;
        $o = self::v($data, $old, 'default_owner_user_id'); if ($o && ! DB::table('t_users')->where('id', (int) $o)->where('f_active', 1)->exists()) return ['default_owner_user_id' => 'Refused: pick an active person, or leave it empty for "Fewest open".'];
        $due = self::v($data, $old, 'default_due_days'); $due = $due === null || $due === '' ? 3 : $due;
        if (! is_numeric($due) || (int) $due < 0 || (int) $due > 365 || (int) $due != $due) return ['default_due_days' => 'Refused: due days is a whole number from 0 to 365.'];
        $ack = self::v($data, $old, 'ack_hours'); $ack = $ack === null || $ack === '' ? 24 : $ack;
        if (! is_numeric($ack) || (int) $ack <= 0 || (int) $ack > 720) return ['ack_hours' => 'Refused: acknowledge hours is more than 0 and at most 720.'];
        $esc = strtoupper((string) (self::v($data, $old, 'escalate_to_role') ?: 'MANAGER')); if (! in_array($esc, ['MANAGER', 'PARTNER'], true)) return ['escalate_to_role' => 'Refused: escalate to a Manager or a Partner.'];
        $ev = strtoupper((string) (self::v($data, $old, 'evidence_type_default') ?: 'SCREENSHOT')); if (! in_array($ev, ['SCREENSHOT', 'PHOTO', 'FILE', 'LINK', 'REFERENCE'], true)) return ['evidence_type_default' => 'Refused: proof type is screenshot, photo, file, link or reference (a voice note is not proof).'];
        $sort = self::v($data, $old, 'sort');
        if ($sort === null || $sort === '') $sort = ((int) DB::table('task_categories')->max('sort') + 10) - ((int) DB::table('task_categories')->max('sort') + 10) % 10;      // steps of 10
        $data += []; $data['default_due_days'] = (int) $due; $data['ack_hours'] = (int) $ack; $data['escalate_to_role'] = $esc; $data['evidence_type_default'] = $ev; $data['sort'] = (int) $sort;
        return [];
    }

    /** R-G_CORE-22 the box on the Team page: the 8 server rules (numbers from the settings). */
    public static function hardLimits(): array
    {
        $lim = TaskActions::limit();
        return ["Due date: at most $lim moves by non-Partners, always with a reason.", 'Nobody approves their own task – not even a Partner.', 'A VA cannot reassign, cancel, move the due date or switch proof off.',
            'Done stays locked until the proof (when required), every checklist item and every subtask – a voice note is not proof.', 'Nothing is deleted – cancel with a reason.',
            'Amounts are removed by the server for everyone but Partners.', 'A confidential task is seen only by its owner and the Partners.', '"Needs my approval" is always on; only a Partner or Manager switches proof off.'];
    }
}
