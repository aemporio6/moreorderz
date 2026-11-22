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
        'SA_POA2' => ['Self Assessment payment on account 2', 'PERSONAL', 'HMRC', 'PAY', '14,7,1'], 'OTHER' => ['Other filing', 'OTHER', 'OTHER', 'FILE', '30,14,7,1'],
    ];

    /** add_months (Oracle / old tool): the same day n months later, clamped to the month end; a month-end date stays a month end (30 Apr + 1 → 31 May). */
    public static function addMonths(string $date, int $n): string
    {
        [$y, $m, $d] = array_map('intval', explode('-', substr($date, 0, 10)));
        $endOfMonth = $d === (int) date('t', mktime(0, 0, 0, $m, 1, $y));
        $t = $m - 1 + $n; $y += intdiv($t, 12); $t %= 12; if ($t < 0) { $t += 12; $y--; }
        $last = (int) date('t', mktime(0, 0, 0, $t + 1, 1, $y));
        return sprintf('%04d-%02d-%02d', $y, $t + 1, $endOfMonth ? $last : min($d, $last));
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

    /** R-ACC-4 VAT returns (only when VAT registered): [start, end, due]. */
    public static function vatRows(object $co, ?object $s, string $ws, string $we): array
    {
        if (! $s || ! (int) $s->vat_registered) return [];
        $reg = $s->vat_reg_date ? substr((string) $s->vat_reg_date, 0, 10) : self::inc($co); $freq = strtoupper((string) ($s->vat_frequency ?: 'Q')); $out = [];
        if ($freq === 'A') {
            if (! ((int) $s->year_end_day && (int) $s->year_end_month) && empty($co->file_return_date)) return [];      // only when a year end is known
            foreach (self::periods($co, $s, $we) as $p) { $st = self::plusDays(self::addMonths($p['end'], -12), 1); $out[] = [$st, $p['end'], self::addMonths($p['end'], 2)]; }
        } else {
            $stagger = (int) $s->vat_stagger; if ($stagger < 1 || $stagger > 3) $stagger = 1;
            $y = (int) substr($ws, 0, 4) - 1; $m = 1;
            while (true) {
                $end = date('Y-m-t', mktime(0, 0, 0, $m, 1, $y)); if ($end > $we) break;
                if ($freq === 'M' || ((($m - $stagger - 2) % 3) + 3) % 3 === 0) {
                    $st = $freq === 'M' ? sprintf('%04d-%02d-01', $y, $m) : self::plusDays(self::addMonths($end, -3), 1);
                    $out[] = [$st, $end, self::plusDays(self::addMonths($end, 1), 7)];
                }
                if (++$m > 12) { $m = 1; $y++; }
            }
        }
        return array_values(array_filter(array_map(fn ($r) => $reg && $r[0] < $reg ? [$reg, $r[1], $r[2]] : $r, $out), fn ($r) => ! $reg || $r[1] >= $reg));      // before registration skipped, a start before it clipped
    }

    /** R-ACC-6 PAYE (only when PAYE registered): [type, start, end, due]. */
    public static function payeRows(object $co, ?object $s, string $ws, string $we): array
    {
        if (! $s || ! (int) $s->paye_registered) return [];
        $start = $s->paye_start_date ? substr((string) $s->paye_start_date, 0, 10) : (self::inc($co) ?: $ws); $lo = max($start, $ws); $out = [];
        $y = (int) substr($lo, 0, 4); $m = (int) substr($lo, 5, 2) - 1; if ($m < 1) { $m = 12; $y--; }
        for ($i = 0; $i < 60; $i++) {
            $end = sprintf('%04d-%02d-05', $y, $m);
            if ($end > $we) break;
            if ($end >= $start) {
                $due22 = sprintf('%04d-%02d-22', $y, $m); $prev6 = self::plusDays(self::addMonths($end, -1), 1);
                if ((int) $s->paye_quarterly) { if (in_array($m, [1, 4, 7, 10], true)) $out[] = ['PAYE_PAY', self::plusDays(self::addMonths($end, -3), 1), $end, $due22]; }
                else $out[] = ['PAYE_PAY', $prev6, $end, $due22];
                if ((int) $s->eps_required) $out[] = ['PAYE_EPS', $prev6, $end, sprintf('%04d-%02d-19', $y, $m)];
            }
            if (++$m > 12) { $m = 1; $y++; }
        }
        $freq = strtoupper((string) ($s->pay_frequency ?: 'M'));
        if ($freq === 'M') {
            $day = (int) $s->payday_day ?: 28; $day = max(1, min(31, $day)); $y = (int) substr($lo, 0, 4); $m = (int) substr($lo, 5, 2);
            for ($i = 0; $i < 60; $i++) { $d = self::yeDate($y, $m, $day); if ($d > $we) break; if ($d >= $lo) $out[] = ['PAYE_FPS', null, null, $d]; if (++$m > 12) { $m = 1; $y++; } }
        } elseif ($s->first_payday) {
            $step = $freq === 'W' ? 7 : 28; $d = substr((string) $s->first_payday, 0, 10);
            for ($i = 0; $i < 400 && $d <= $we; $i++, $d = self::plusDays($d, $step)) if ($d >= $lo) $out[] = ['PAYE_FPS', null, null, $d];
        }
        return $out;
    }

    /** R-ACC-7 Self Assessment of a person: [type, start, end, due] for tax years y = 6 Apr y → 5 Apr y+1. */
    public static function saRows(?object $ps, string $ws, string $we): array
    {
        if (! $ps || ! (int) $ps->sa_registered) return [];
        $from = (int) substr($ws, 0, 4) - 2; if ((int) $ps->first_tax_year > 1990) $from = max($from, (int) $ps->first_tax_year); $out = [];
        for ($y = $from; $y <= (int) substr($we, 0, 4); $y++) {
            $st = "$y-04-06"; $en = ($y + 1) . '-04-05';
            $out[] = ['SA_RETURN', $st, $en, ($y + 2) . '-01-31'];
            if ((int) $ps->poa_required) { $out[] = ['SA_POA1', $st, $en, ($y + 1) . '-01-31']; $out[] = ['SA_POA2', $st, $en, ($y + 1) . '-07-31']; }
        }
        return array_values(array_filter($out, fn ($r) => $r[3] >= $ws && $r[1] <= $we));      // person rows: due ≥ window start, start ≤ window end
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
        foreach (self::vatRows($co, $s, $ws, $we) as [$a, $b, $d]) $rows[] = ['VAT_RETURN', $a, $b, $d];      // R-ACC-4
        foreach (self::payeRows($co, $s, $ws, $we) as $r) $rows[] = $r;      // R-ACC-6
        $track = $s && $s->track_from ? substr((string) $s->track_from, 0, 10) : null;
        return array_values(array_filter($rows, fn ($r) => $r[3] >= $ws && ($r[2] === null || $r[2] <= $we) && (! $track || $r[3] >= $track)));
    }

    /** R-ACC-9 generate the schedule of a company (or of all companies). insert-ignore by gen_key: a re-run never duplicates or overwrites work. Returns new rows. */
    public static function generate(int $ent, ?int $company = null, ?string $today = null): int
    {
        $types = array_filter(AccFilings::types($ent), fn ($t) => (int) $t->f_active); $n = 0;      /* R-ACC-11 the enterprise's own copy of a type wins */
        $cos = DB::table('cat_company_house')->where(fn ($w) => $w->where('fk_id_ent', $ent)->orWhere('fk_id_ent', 0)->orWhereNull('fk_id_ent'))->when($company, fn ($q) => $q->where('id', $company))->where(fn ($w) => $w->whereNull('f_active')->orWhere('f_active', 1))->get();
        foreach ($cos as $co) {
            $s = DB::table('acc_company_settings')->where('fk_id_company', $co->id)->whereIn('fk_id_ent', [$ent, 0])->orderByDesc('fk_id_ent')->first();
            if ($s && isset($s->f_tracked) && (int) $s->f_tracked === 0) continue;
            foreach (self::companyRows($ent, $co, $s, $today) as [$code, $start, $end, $due]) {
                $t = $types[$code] ?? null; if (! $t) continue;
                $n += DB::table('acc_obligations')->insertOrIgnore(['fk_id_ent' => $ent, 'entity_type' => 'COMPANY', 'entity_id' => $co->id, 'fk_type' => $t->id, 'type_code' => $code, 'area' => $t->area, 'completion' => $t->completion,
                    'period_start' => $start, 'period_end' => $end, 'due_date' => $due, 'status' => 'PENDING', 'f_done' => 0, 'f_auto' => 1, 'gen_key' => 'C' . $co->id . '|' . $code . '|' . ($end ?: $due),
                    'title' => self::TYPES[$code][0] . ($end ? ' – period to ' . date('d M Y', strtotime($end)) : ''), 'fk_assigned' => (int) ($s->fk_accountant ?? 0), 'created_at' => now()]);
            }
        }
        if (! $company) foreach (DB::table('acc_person_settings')->whereIn('fk_id_ent', [$ent, 0])->where('sa_registered', 1)->get() as $ps) {      // R-ACC-7 people (not filtered by track_from)
            [$ws, $we] = self::window($ent, $today);
            foreach (self::saRows($ps, $ws, $we) as [$code, $start, $end, $due]) {
                $t = $types[$code] ?? null; if (! $t) continue;
                $n += DB::table('acc_obligations')->insertOrIgnore(['fk_id_ent' => $ent, 'entity_type' => 'PERSON', 'entity_id' => $ps->fk_id_account, 'fk_type' => $t->id, 'type_code' => $code, 'area' => $t->area, 'completion' => $t->completion,
                    'period_start' => $start, 'period_end' => $end, 'due_date' => $due, 'status' => 'PENDING', 'f_done' => 0, 'f_auto' => 1, 'gen_key' => 'P' . $ps->fk_id_account . '|' . $code . '|' . $end,
                    'title' => self::TYPES[$code][0] . ' ' . substr($start, 0, 4) . '-' . substr($end, 2, 2), 'fk_assigned' => (int) ($ps->fk_accountant ?? 0), 'created_at' => now()]);
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

    public const STATUSES = ['PENDING' => 'Not started', 'IN_PROGRESS' => 'In progress', 'FILED' => 'Filed', 'PAID' => 'Paid', 'NOT_REQUIRED' => 'Not required'];

    /** R-ACC-10 done: NOT_REQUIRED always; FILE → FILED or PAID; PAY / FILE_PAY → PAID. */
    public static function isDone(string $completion, string $status): bool
    {
        if ($status === 'NOT_REQUIRED') return true;
        return $completion === 'FILE' ? in_array($status, ['FILED', 'PAID'], true) : $status === 'PAID';
    }

    /**
     * R-ACC-10 change an item. Status; FILED / PAID need a proof document first (improved). filed_date today when empty (not for PAY types), PAID → paid_date today when empty,
     * filed_by set once. Due date and title only on manual items. Every change logged (field old → new).
     */
    public static function update(int $ent, int $id, array $d, object $u): array
    {
        return DB::transaction(function () use ($ent, $id, $d, $u) {
            $o = DB::table('acc_obligations')->where('id', $id)->where('fk_id_ent', $ent)->lockForUpdate()->first() ?? throw new \InvalidArgumentException('Item not found.');
            $ch = []; $today = date('Y-m-d');
            if (isset($d['status'])) {
                $st = strtoupper((string) $d['status']); if (! isset(self::STATUSES[$st])) throw new \InvalidArgumentException('Choose a status from the list.');
                if (in_array($st, ['FILED', 'PAID'], true) && ! DB::table('acc_documents')->where('fk_id_obligation', $id)->where('f_deleted', 0)->exists()) throw new \InvalidArgumentException('Add the proof document first (the filing receipt or the payment proof).');
                $reason = trim((string) ($d['reason'] ?? ''));
                if ($st === 'NOT_REQUIRED' && (int) $o->f_auto && $o->status !== 'NOT_REQUIRED' && $reason === '') throw new \InvalidArgumentException('Write the reason why this filing is not required.');      /* R-ACC-14 */
                if ($reason !== '') $ch['notes'] = mb_substr(trim(($o->notes ? $o->notes . "\n" : '') . 'Not required: ' . $reason), 0, 2000);
                $ch['status'] = $st; $ch['f_done'] = self::isDone((string) $o->completion, $st) ? 1 : 0;
                if (in_array($st, ['FILED', 'PAID'], true)) {
                    if (! $o->filed_date && $o->completion !== 'PAY') $ch['filed_date'] = $today;
                    if (! (int) $o->filed_by) $ch['filed_by'] = $u->id;
                    if ($st === 'PAID' && ! $o->paid_date) $ch['paid_date'] = $today;
                }
            }
            foreach (['submission_ref' => 120, 'notes' => 2000] as $k => $max) if (array_key_exists($k, $d)) $ch[$k] = mb_substr(trim((string) $d[$k]), 0, $max);
            foreach (['amount_due', 'amount_paid'] as $k) if (array_key_exists($k, $d) && $d[$k] !== '' && $d[$k] !== null) $ch[$k] = round((float) $d[$k], 2);
            if (array_key_exists('fk_assigned', $d)) { $a = (int) $d['fk_assigned']; if ($a && ! in_array($a, array_column(AccFilings::accountants($ent), 'id'), true)) throw new \InvalidArgumentException('Choose an accountant of the Hub (a login that may change filings).'); $ch['fk_assigned'] = $a; }
            foreach (['due_date', 'title'] as $k) if (array_key_exists($k, $d)) { if ((int) $o->f_auto) throw new \InvalidArgumentException('The due date and the title are worked out by the rules – they can be changed only on items added by hand.'); $ch[$k] = $k === 'title' ? mb_substr(trim((string) $d[$k]), 0, 200) : $d[$k]; }
            $ch = array_filter($ch, fn ($v, $k) => (string) ($o->$k ?? '') !== (string) $v, ARRAY_FILTER_USE_BOTH);
            if (! $ch) return ['message' => 'Nothing changed.', 'done' => (bool) $o->f_done];
            DB::table('acc_obligations')->where('id', $id)->update($ch + ['updated_by' => $u->id, 'updated_at' => now()]);
            foreach ($ch as $k => $v) DB::table('acc_obligation_log')->insert(['fk_id_obligation' => $id, 'action' => 'UPDATE', 'details' => "$k: " . ($o->$k ?? '') . ' → ' . $v, 'fk_id_account' => $u->id, 'created_at' => now()]);
            return ['message' => 'Saved.', 'done' => (bool) ($ch['f_done'] ?? $o->f_done)];
        });
    }

    /** A proof document on an item (R-ACC-15 rules in AccFilings::upload). */
    public static function addDocument(int $ent, int $id, \Illuminate\Http\UploadedFile $f, object $u): int
    {
        $o = DB::table('acc_obligations')->where('id', $id)->where('fk_id_ent', $ent)->first() ?? throw new \InvalidArgumentException('Item not found.');
        return AccFilings::upload($ent, (string) $o->entity_type, (int) $o->entity_id, $id, $f, $u);
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

    /** The Tax & filings page: R-ACC-13 overview (tabs, filters, summary, calendar) + the last run (a GET never writes – the page asks for a run when stale). */
    public static function page(int $ent, array $f = []): array
    {
        $last = DB::table('acc_settings')->where('fk_id_ent', $ent)->where('setting_key', 'last_generated_at')->value('setting_value');
        return ['stale' => ! $last || strtotime((string) $last) < time() - 6 * 3600, 'last_run' => $last, 'statuses' => self::STATUSES,
            'companies' => DB::table('cat_company_house')->orderBy('company_name')->limit(500)->get(['id', 'company_name']),
            'people' => DB::table('t_account as a')->join('acc_person_settings as s', 's.fk_id_account', '=', 'a.id')->orderBy('a.first_name')->limit(500)->get(['a.id', DB::raw("TRIM(CONCAT_WS(' ', a.first_name, a.sur_name)) as name")])] + AccFilings::overview($ent, $f);
    }
}
