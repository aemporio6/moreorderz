<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * RULES_E_FINANCE accounting compliance dates (UK law as written in the rules):
 * R-ACC-1 year end + periods of account, R-ACC-2 ACCOUNTS due, R-ACC-3 Corporation Tax periods (CT_PAYMENT, CT600), R-ACC-5 confirmation statement,
 * R-ACC-8 track_from + window, R-ACC-9 idempotent schedule generation (gen_key, insert-ignore, rebuild only untouched rows).
 * Company data: cat_company_house (incorporate_date / creation_date, file_return_date, confirmation_statement_date); Hub settings: acc_company_settings.
 */
class AccCompliance
{
    /** Obligation types [name, area, authority, completion, reminder days before the due date]. */
    public const TYPES = [
        'ACCOUNTS' => ['Annual accounts', 'ACCOUNTS', 'CH', 'FILE', '30,14,7,1'], 'CT_PAYMENT' => ['Corporation Tax payment', 'CT', 'HMRC', 'PAY', '30,14,7,1'],
        'CT600' => ['Corporation Tax return (CT600)', 'CT', 'HMRC', 'FILE', '30,14,7,1'], 'CONF_STMT' => ['Confirmation statement', 'CS', 'CH', 'FILE', '14,7,1'],
        'VAT_RETURN' => ['VAT return', 'VAT', 'HMRC', 'FILE_PAY', '14,7,1'], 'PAYE_PAY' => ['PAYE payment', 'PAYROLL', 'HMRC', 'PAY', '7,1'], 'PAYE_EPS' => ['PAYE EPS', 'PAYROLL', 'HMRC', 'FILE', '7,1'],
        'PAYE_FPS' => ['PAYE FPS', 'PAYROLL', 'HMRC', 'FILE', '3,1'], 'SA_RETURN' => ['Self Assessment return', 'PERSONAL', 'HMRC', 'FILE_PAY', '30,14,7,1'], 'SA_POA1' => ['Self Assessment payment on account 1', 'PERSONAL', 'HMRC', 'PAY', '14,7,1'],
        'SA_POA2' => ['Self Assessment payment on account 2', 'PERSONAL', 'HMRC', 'PAY', '14,7,1'],
    ];

    /** add_months: the same day n months later, clamped to the month end (31 Jan + 1 → 28 / 29 Feb). */
    public static function addMonths(string $date, int $n): string
    {
        [$y, $m, $d] = array_map('intval', explode('-', substr($date, 0, 10)));
        $t = $m - 1 + $n; $y += intdiv($t, 12); $t %= 12; if ($t < 0) { $t += 12; $y--; }
        $last = (int) date('t', mktime(0, 0, 0, $t + 1, 1, $y));
        return sprintf('%04d-%02d-%02d', $y, $t + 1, min($d, $last));
    }

    private static function plusDays(string $d, int $n): string { return date('Y-m-d', strtotime("$d " . ($n >= 0 ? '+' : '') . "$n days")); }
    private static function yeDate(int $year, int $month, int $day): string { return sprintf('%04d-%02d-%02d', $year, $month, min($day, (int) date('t', mktime(0, 0, 0, $month, 1, $year)))); }

    /** R-ACC-1 [day, month] of the year end. */
    public static function yearEnd(object $co, ?object $s): array
    {
        if ($s && (int) $s->year_end_day >= 1 && (int) $s->year_end_day <= 31 && (int) $s->year_end_month >= 1 && (int) $s->year_end_month <= 12) return [(int) $s->year_end_day, (int) $s->year_end_month];
        if (! empty($co->file_return_date)) { $x = self::addMonths((string) $co->file_return_date, -9); return [(int) substr($x, 8, 2), (int) substr($x, 5, 2)]; }
        $inc = self::inc($co); if ($inc) return [31, (int) substr($inc, 5, 2)];
        return [31, 3];
    }

    public static function inc(object $co): ?string { $d = $co->incorporate_date ?: ($co->creation_date ?? null); return $d && $d > '1900-01-01' ? substr((string) $d, 0, 10) : null; }

    /** R-ACC-1 periods of account up to the window end: [start, end, first]. */
    public static function periods(object $co, ?object $s, string $windowEnd): array
    {
        [$day, $month] = self::yearEnd($co, $s); $inc = self::inc($co); $out = [];
        $firstEnd = null;
        if ($inc) {
            if ($s && $s->first_period_end) $firstEnd = substr((string) $s->first_period_end, 0, 10);
            else {
                $target = self::addMonths($inc, 12); $best = null;
                foreach ([0, 1, 2] as $k) { $c = self::yeDate((int) substr($inc, 0, 4) + $k, $month, $day); if ($c <= $inc || $c > self::addMonths($inc, 18)) continue;
                    if (! $best || abs(strtotime($c) - strtotime($target)) < abs(strtotime($best) - strtotime($target))) $best = $c; }
                $firstEnd = $best;
            }
        }
        if ($inc && $firstEnd) { $start = $inc; $end = $firstEnd; $first = true; }
        else { $end = self::yeDate((int) substr($windowEnd, 0, 4) - 6, $month, $day); $start = self::plusDays(self::yeDate((int) substr($end, 0, 4) - 1, $month, $day), 1); $first = false; }
        while (count($out) < 40) {
            $out[] = ['start' => $start, 'end' => $end, 'first' => $first];
            if ($end > $windowEnd) break;
            $start = self::plusDays($end, 1); $end = self::yeDate((int) substr($end, 0, 4) + 1, $month, $day); $first = false;
        }
        return $out;
    }

    /** R-ACC-2 ACCOUNTS due of a period (null = not generated: already filed by the File Return Date). */
    public static function accountsDue(array $p, object $co): ?string
    {
        $long = $p['end'] >= self::addMonths($p['start'], 12);
        $due = $p['first'] && $long ? max(self::addMonths((string) self::inc($co), 21), self::addMonths($p['end'], 3)) : self::addMonths($p['end'], 9);
        if (! empty($co->file_return_date)) {
            $frd = substr((string) $co->file_return_date, 0, 10); $gap = (strtotime($due) - strtotime($frd)) / 86400;
            if (abs($gap) <= 45) $due = $frd; elseif ($gap < -45) return null;
        }
        return $due;
    }

    /** R-ACC-3 CT periods of a period of account: [start, end, pay due, CT600 due]. */
    public static function ctPeriods(array $p): array
    {
        $ct1End = self::plusDays(self::addMonths($p['start'], 12), -1); $ct600 = self::addMonths($p['end'], 12); $out = [];
        if ($ct1End < $p['end']) { $out[] = [$p['start'], $ct1End]; $out[] = [self::plusDays($ct1End, 1), $p['end']]; } else $out[] = [$p['start'], $p['end']];
        return array_map(fn ($x) => ['start' => $x[0], 'end' => $x[1], 'pay' => self::plusDays(self::addMonths($x[1], 9), 1), 'ct600' => $ct600], $out);
    }

    /** R-ACC-5 confirmation statements: [start, end (review), due]. */
    public static function confirmationStatements(object $co, ?object $s, string $ws, string $we): array
    {
        $inc = self::inc($co); $fromCh = false;
        if ($s && $s->cs_review_date) $anchor = substr((string) $s->cs_review_date, 0, 10);
        elseif (! empty($co->confirmation_statement_date)) { $anchor = substr((string) $co->confirmation_statement_date, 0, 10); if ($s && strtoupper((string) $s->cs_date_is) === 'DUE') $anchor = self::plusDays($anchor, -14); $fromCh = true; }
        elseif ($inc) $anchor = self::addMonths($inc, 12);
        else return [];
        $firstReview = $inc ? self::addMonths($inc, 12) : null; $out = [];
        for ($k = -6; $k <= 3; $k++) {
            if ($fromCh && $k < 0) continue;      // that date is the NEXT statement
            $r = self::addMonths($anchor, 12 * $k); $due = self::plusDays($r, 14);
            if (($firstReview && $r < self::plusDays($firstReview, -40)) || $due < $ws || $r > $we) continue;
            $out[] = ['start' => self::plusDays(self::addMonths($r, -12), 1), 'end' => $r, 'due' => $due];
        }
        return $out;
    }

    /** R-ACC-8 the window: today − history_months (1–72, default 12) → today + horizon_months (3–36, default 15). */
    public static function window(int $ent, ?string $today = null): array
    {
        $today ??= date('Y-m-d'); $get = fn ($k, $d) => (int) (DB::table('acc_settings')->whereIn('fk_id_ent', [$ent, 0])->where('setting_key', $k)->orderByDesc('fk_id_ent')->value('setting_value') ?? $d);
        $h = max(1, min(72, $get('history_months', 12))); $f = max(3, min(36, $get('horizon_months', 15)));
        return [self::addMonths($today, -$h), self::addMonths($today, $f)];
    }

    /** The rows of one company (R-ACC-1 … 5, 8). */
    public static function companyRows(int $ent, object $co, ?object $s, ?string $today = null): array
    {
        [$ws, $we] = self::window($ent, $today); $rows = [];
        foreach (self::periods($co, $s, $we) as $p) {
            if ($due = self::accountsDue($p, $co)) $rows[] = ['ACCOUNTS', $p['start'], $p['end'], $due];
            foreach (self::ctPeriods($p) as $c) { $rows[] = ['CT_PAYMENT', $c['start'], $c['end'], $c['pay']]; }
            $rows[] = ['CT600', $p['start'], $p['end'], self::ctPeriods($p)[0]['ct600']];
        }
        foreach (self::confirmationStatements($co, $s, $ws, $we) as $c) $rows[] = ['CONF_STMT', $c['start'], $c['end'], $c['due']];
        $track = $s && $s->track_from ? substr((string) $s->track_from, 0, 10) : null;
        return array_values(array_filter($rows, fn ($r) => $r[3] >= $ws && ($r[2] === null || $r[2] <= $we) && (! $track || $r[3] >= $track)));
    }

    /** R-ACC-9 generate the schedule of a company (or of all companies). insert-ignore by gen_key: a re-run never duplicates or overwrites work. Returns new rows. */
    public static function generate(int $ent, ?int $company = null, ?string $today = null): int
    {
        self::seedTypes(); $types = DB::table('acc_obligation_types')->where('f_active', 1)->whereIn('fk_id_ent', [0, $ent])->get()->keyBy('type_code'); $n = 0;
        $cos = DB::table('cat_company_house')->where(fn ($w) => $w->where('fk_id_ent', $ent)->orWhere('fk_id_ent', 0)->orWhereNull('fk_id_ent'))->when($company, fn ($q) => $q->where('id', $company))->where(fn ($w) => $w->whereNull('f_active')->orWhere('f_active', 1))->get();
        foreach ($cos as $co) {
            $s = DB::table('acc_company_settings')->where('fk_id_company', $co->id)->whereIn('fk_id_ent', [$ent, 0])->orderByDesc('fk_id_ent')->first();
            if ($s && isset($s->f_tracked) && (int) $s->f_tracked === 0) continue;
            foreach (self::companyRows($ent, $co, $s, $today) as [$code, $start, $end, $due]) {
                $t = $types[$code] ?? null; if (! $t) continue;
                $n += DB::table('acc_obligations')->insertOrIgnore(['fk_id_ent' => $ent, 'entity_type' => 'COMPANY', 'entity_id' => $co->id, 'fk_type' => $t->id, 'type_code' => $code, 'area' => self::TYPES[$code][1], 'completion' => $t->completion,
                    'period_start' => $start, 'period_end' => $end, 'due_date' => $due, 'status' => 'PENDING', 'f_done' => 0, 'f_auto' => 1, 'gen_key' => 'C' . $co->id . '|' . $code . '|' . ($end ?: $due),
                    'title' => self::TYPES[$code][0] . ($end ? ' – period to ' . date('d M Y', strtotime($end)) : ''), 'fk_assigned' => (int) ($s->fk_accountant ?? 0), 'created_at' => now()]);
            }
        }
        DB::table('acc_settings')->updateOrInsert(['fk_id_ent' => $ent, 'setting_key' => 'last_generated_at'], ['setting_value' => now()->format('Y-m-d H:i:s'), 'updated_at' => now()]);
        return $n;
    }

    /** Refresh on page open when the last run is older than 6 hours. */
    public static function refreshIfOld(int $ent): ?int
    {
        $last = DB::table('acc_settings')->where('fk_id_ent', $ent)->where('setting_key', 'last_generated_at')->value('setting_value');
        return ! $last || strtotime((string) $last) < time() - 6 * 3600 ? self::generate($ent) : null;
    }

    /** Rebuild one company: only auto rows that are PENDING with no notes, no reference, no amounts and no documents go, then generate again. */
    public static function rebuild(int $ent, int $company, ?string $today = null): string
    {
        $docs = DB::table('acc_documents')->where('f_deleted', 0)->whereNotNull('fk_id_obligation')->pluck('fk_id_obligation')->all();
        $gone = DB::table('acc_obligations')->where('fk_id_ent', $ent)->where('entity_type', 'COMPANY')->where('entity_id', $company)->where('f_auto', 1)->where('status', 'PENDING')
            ->where(fn ($w) => $w->whereNull('notes')->orWhere('notes', ''))->where(fn ($w) => $w->whereNull('submission_ref')->orWhere('submission_ref', ''))
            ->where(fn ($w) => $w->whereNull('amount_due')->orWhere('amount_due', 0))->where(fn ($w) => $w->whereNull('amount_paid')->orWhere('amount_paid', 0))->whereNotIn('id', $docs ?: [0])->delete();
        $add = self::generate($ent, $company, $today);
        Audit::log('acc_obligations', $company, 'UPDATE', ['removed' => $gone, 'added' => $add], 'rebuild schedule', $ent);
        return "$gone removed, $add added";
    }

    public static function seedTypes(): void
    {
        $i = 0;
        foreach (self::TYPES as $code => [$name, $area, $auth, $comp, $rem]) {
            $i += 10;
            if (! DB::table('acc_obligation_types')->where('type_code', $code)->where('fk_id_ent', 0)->exists())
                DB::table('acc_obligation_types')->insert(['fk_id_ent' => 0, 'type_code' => $code, 'type_name' => $name, 'area' => $area, 'authority' => $auth, 'completion' => $comp, 'reminder_days' => $rem, 'sort_order' => $i, 'f_active' => 1]);
        }
    }

    /** The schedule list (shown status computed: done → its status, overdue, due soon, upcoming). */
    public static function page(int $ent, array $f = []): array
    {
        $last = DB::table('acc_settings')->where('fk_id_ent', $ent)->where('setting_key', 'last_generated_at')->value('setting_value');      /* the page asks for a run (POST) when stale – a GET never writes */
        $soon = (int) (DB::table('acc_settings')->whereIn('fk_id_ent', [$ent, 0])->where('setting_key', 'due_soon_days')->value('setting_value') ?? 14); $today = date('Y-m-d');
        $rows = DB::table('acc_obligations as o')->leftJoin('cat_company_house as c', fn ($j) => $j->on('c.id', '=', 'o.entity_id')->where('o.entity_type', 'COMPANY'))->where('o.fk_id_ent', $ent)
            ->when(! empty($f['company']), fn ($q) => $q->where('o.entity_id', (int) $f['company']))->orderBy('o.due_date')->limit(500)->get(['o.*', 'c.company_name'])
            ->map(fn ($o) => ['id' => (int) $o->id, 'company' => $o->company_name, 'type' => $o->type_code, 'title' => $o->title, 'period' => $o->period_start ? $o->period_start . ' → ' . $o->period_end : '', 'due' => $o->due_date,
                'status' => $o->status, 'shown' => (int) $o->f_done ? $o->status : ($o->due_date < $today ? 'OVERDUE' : ($o->due_date <= date('Y-m-d', strtotime("+$soon days")) ? 'DUE_SOON' : 'UPCOMING'))])->all();
        return ['stale' => ! $last || strtotime((string) $last) < time() - 6 * 3600, 'last_run' => $last, 'rows' => $rows, 'companies' => DB::table('cat_company_house')->orderBy('company_name')->limit(500)->get(['id', 'company_name'])];
    }
}
