<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Accountancy Hub values of a company (IC-17): year end, VAT scheme / quarters, accountant, compliance owner and the confirmation statement date
 * live only here; the company form shows them read-only. The due dates of R-F_SETUP-35 / R-ACC-2 / R-ACC-3 are worked out from them.
 */
class AccHub
{
    public const FIELDS = ['f_tracked', 'track_from', 'year_end_day', 'year_end_month', 'first_period_end', 'cs_review_date', 'cs_date_is', 'vat_registered', 'vat_frequency', 'vat_stagger', 'vat_scheme', 'vat_reg_date', 'paye_registered', 'paye_ref',
        'accounts_office_ref', 'pay_frequency', 'payday_day', 'first_payday', 'paye_quarterly', 'eps_required', 'paye_start_date', 'fk_accountant', 'fk_compliance_owner', 'notes'];
    private const STAGGER = [1 => 'Mar / Jun / Sep / Dec', 2 => 'Apr / Jul / Oct / Jan', 3 => 'May / Aug / Nov / Feb'];

    public static function row(int $companyId): ?object { return DB::table('acc_company_settings')->where('fk_id_ent', Context::ent())->where('fk_id_company', $companyId)->first(); }

    private static function plus(string $d, int $months = 0, int $days = 0): string
    {
        $t = new \DateTimeImmutable($d); if ($months) { $day = (int) $t->format('d'); $t = $t->modify('first day of this month')->modify("+$months months"); $t = $t->setDate((int) $t->format('Y'), (int) $t->format('m'), min($day, (int) $t->format('t'))); }
        return $days ? $t->modify(($days > 0 ? '+' : '') . $days . ' days')->format('Y-m-d') : $t->format('Y-m-d');
    }

    /** The due dates of a company: confirmation statement and accounts, each with how it was found. */
    public static function due(object $co, ?object $h, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        if ((int) ($co->f_external ?? 0)) return ['external' => true, 'cs' => null, 'accounts' => null, 'note' => 'External company: no due dates, no tasks.'];
        $inc = $co->incorporate_date ?: null; $cs = null; $acc = null;
        // confirmation statement: due = review date + 14 days; the stored date is the review date, or the due date when cs_date_is = DUE; no anchor → incorporation + 12 months
        if ($h && $h->cs_review_date) { $review = $h->cs_date_is === 'DUE' ? self::plus($h->cs_review_date, 0, -14) : $h->cs_review_date; $cs = ['review' => $review, 'due' => self::plus($review, 0, 14), 'how' => $h->cs_date_is === 'DUE' ? 'Hub date is the due date' : 'Hub review date + 14 days']; }
        elseif ($inc) { $review = self::plus($inc, 12); $cs = ['review' => $review, 'due' => self::plus($review, 0, 14), 'how' => 'Incorporation + 12 months, + 14 days']; }
        // accounts: period end + 9 months; a first period over 12 months: the later of incorporation + 21 months and period end + 3 months
        // a first period of a few days is not made: a year end less than 6 months after incorporation is skipped, the next one closes the first period
        $ends = []; $fpe = $h->first_period_end ?? null; if ($fpe && (! $inc || $fpe > $inc)) $ends[] = $fpe;
        if ($h && $h->year_end_day && $h->year_end_month) { $from = (int) substr($inc ?: $today, 0, 4) - ($inc ? 0 : 2);
            for ($y = $from; $y <= (int) substr($today, 0, 4) + 2; $y++) { $m = (int) $h->year_end_month; $c = sprintf('%04d-%02d-%02d', $y, $m, min((int) $h->year_end_day, cal_days_in_month(CAL_GREGORIAN, $m, $y))); if (($inc && $c <= $inc) || ($ends && $c <= end($ends)) || ($inc && ! $ends && $c < self::plus($inc, 6))) continue; $ends[] = $c; } }
        foreach ($ends as $i => $pe) {
            $long = $i === 0 && $inc && self::plus($inc, 12) < $pe;      // the first period of a new company, longer than 12 months
            $date = $long ? max(self::plus($inc, 21), self::plus($pe, 3)) : self::plus($pe, 9);
            if ($date >= $today || $i === count($ends) - 1) { $acc = ['period_end' => $pe, 'due' => $date, 'how' => $long ? 'First period over 12 months: the later of incorporation + 21 months and period end + 3 months' : 'Period end + 9 months']; break; }
        }
        if ($co->file_return_date && (! $acc || abs((strtotime($co->file_return_date) - strtotime($acc['due'])) / 86400) <= 45)) $acc = ['period_end' => $acc['period_end'] ?? null, 'due' => $co->file_return_date, 'how' => 'Companies House next due date (file return date)' . ($acc ? ' – within 45 days of ' . $acc['due'] : '')];
        return ['external' => false, 'cs' => $cs, 'accounts' => $acc, 'note' => null];
    }

    /** Read-only block for the company page and form. */
    public static function display(int $companyId): array
    {
        $co = DB::table('cat_company_house')->where('id', $companyId)->first(); if (! $co) return [];
        $h = self::row($companyId); $due = self::due($co, $h); $name = fn ($id) => $id ? DB::table('t_account')->where('id', $id)->selectRaw("TRIM(CONCAT_WS(' ', first_name, sur_name)) n")->value('n') : null;
        $ye = $h && $h->year_end_day && $h->year_end_month ? sprintf('%d %s', $h->year_end_day, date('F', mktime(0, 0, 0, (int) $h->year_end_month, 1))) : null;
        return [
            ['label' => 'Year end', 'type' => 'text', 'value' => $ye], ['label' => 'VAT scheme', 'type' => 'text', 'value' => $h && $h->vat_registered ? ($h->vat_scheme ?: 'Standard') : ($h ? 'Not VAT registered' : null)],
            ['label' => 'VAT quarters', 'type' => 'text', 'value' => $h && $h->vat_registered ? (['Q' => 'Quarterly', 'M' => 'Monthly', 'A' => 'Annual'][$h->vat_frequency] ?? $h->vat_frequency) . ($h->vat_frequency === 'Q' ? ' – ' . (self::STAGGER[(int) $h->vat_stagger] ?? '') : '') : null],
            ['label' => 'Accountant', 'type' => 'text', 'value' => $h && (int) $h->fk_accountant ? DB::table('t_users')->where('id', $h->fk_accountant)->selectRaw('COALESCE(NULLIF(display_name, ""), login_id) n')->value('n') : null],      /* a login of the Hub team (R-ACC-17 / 20) */ ['label' => 'Compliance owner', 'type' => 'text', 'value' => $h ? $name((int) $h->fk_compliance_owner) : null],
            ['label' => 'Confirmation statement review date', 'type' => 'date', 'value' => $due['cs']['review'] ?? null], ['label' => 'Confirmation statement due', 'type' => 'text', 'value' => $due['cs'] ? $due['cs']['due'] . ' (' . $due['cs']['how'] . ')' : ($due['note'] ?? null)],
            ['label' => 'Accounts due', 'type' => 'text', 'value' => $due['accounts'] ? $due['accounts']['due'] . ' (' . $due['accounts']['how'] . ')' : ($due['note'] ?? null)],
            ['label' => 'Kept in', 'type' => 'applink', 'value' => '#/acc-company/' . $companyId, 'shown' => 'Edit in Accountancy Hub'],
        ];
    }

    public static function page(int $companyId, ?object $u): array
    {
        Perm::need($u, 'setup.accHub'); $co = DB::table('cat_company_house')->where('id', $companyId)->where('fk_id_ent', Context::ent())->first() ?? throw new \InvalidArgumentException('Company not found.');
        $h = self::row($companyId); $v = []; foreach (self::FIELDS as $f) $v[$f] = $h->$f ?? null; $v['cs_date_is'] ??= 'REVIEW';
        $people = DB::table('t_account')->where('f_active', 1)->orderBy('first_name')->limit(500)->selectRaw("id, TRIM(CONCAT_WS(' ', first_name, sur_name)) n")->pluck('n', 'id')->all();
        $v['f_tracked'] ??= 1; $v['pay_frequency'] ??= 'M'; $v['payday_day'] ??= 28; $ent = (int) Context::ent();
        return ['company' => ['id' => (int) $co->id, 'name' => $co->company_name, 'incorporated' => $co->incorporate_date, 'file_return_date' => $co->file_return_date, 'external' => (bool) $co->f_external], 'values' => $v, 'due' => self::due($co, $h), 'people' => $people, 'stagger' => self::STAGGER,
            'accountants' => AccFilings::accountants($ent), 'secrets' => AccHubAdmin::companySecrets($companyId), 'can_reveal' => AccHubAdmin::canReveal($u), 'history' => AccHubAdmin::history('cat_company_house', $companyId)] + AccHubAdmin::companyTax($ent, $companyId);      /* R-ACC-16 / 21 */
    }

    public static function save(int $companyId, array $d, ?object $u): array
    {
        Perm::need($u, 'setup.accHub'); $co = DB::table('cat_company_house')->where('id', $companyId)->where('fk_id_ent', Context::ent())->first() ?? throw new \InvalidArgumentException('Company not found.'); $e = [];
        $int = function ($k, $a, $b) use ($d, &$e) { $x = trim((string) ($d[$k] ?? '')); if ($x === '') return null; if (ctype_digit($x) && (int) $x >= $a && (int) $x <= $b) return (int) $x; $e[$k] = "Choose a value from $a to $b."; return null; };
        $date = function ($k) use ($d, &$e) { $x = trim((string) ($d[$k] ?? '')); if ($x === '') return null; $p = Parse::date($x); if (! $p) $e[$k] = 'Write a date.'; return $p ?: null; };
        $row = ['year_end_day' => $int('year_end_day', 1, 31), 'year_end_month' => $int('year_end_month', 1, 12), 'first_period_end' => $date('first_period_end'), 'cs_review_date' => $date('cs_review_date'), 'cs_date_is' => ($d['cs_date_is'] ?? '') === 'DUE' ? 'DUE' : 'REVIEW',
            'vat_registered' => ! empty($d['vat_registered']) ? 1 : 0, 'vat_frequency' => in_array($d['vat_frequency'] ?? '', ['Q', 'M', 'A'], true) ? $d['vat_frequency'] : 'Q', 'vat_stagger' => in_array((int) ($d['vat_stagger'] ?? 1), [1, 2, 3], true) ? (int) ($d['vat_stagger'] ?? 1) : 1,
            'vat_scheme' => mb_substr(trim((string) ($d['vat_scheme'] ?? '')), 0, 40) ?: null, 'vat_reg_date' => $date('vat_reg_date'), 'paye_registered' => ! empty($d['paye_registered']) ? 1 : 0, 'paye_ref' => mb_substr(strtoupper(trim((string) ($d['paye_ref'] ?? ''))), 0, 30) ?: null,
            'fk_accountant' => (int) ($d['fk_accountant'] ?? 0), 'fk_compliance_owner' => (int) ($d['fk_compliance_owner'] ?? 0) ?: null, 'notes' => trim((string) ($d['notes'] ?? '')) ?: null,
            /* R-ACC-17 the rest of the Hub settings of a company */
            'f_tracked' => array_key_exists('f_tracked', $d) ? (int) (bool) $d['f_tracked'] : 1, 'track_from' => $date('track_from'), 'accounts_office_ref' => mb_substr(strtoupper(trim((string) ($d['accounts_office_ref'] ?? ''))), 0, 30) ?: null,
            'pay_frequency' => in_array($d['pay_frequency'] ?? 'M', ['M', 'W', '4W'], true) ? ($d['pay_frequency'] ?? 'M') : 'M', 'payday_day' => $int('payday_day', 1, 31) ?? 28, 'first_payday' => $date('first_payday'),
            'paye_quarterly' => ! empty($d['paye_quarterly']) ? 1 : 0, 'eps_required' => ! empty($d['eps_required']) ? 1 : 0, 'paye_start_date' => $date('paye_start_date')];
        if (($row['year_end_day'] === null) !== ($row['year_end_month'] === null)) $e['year_end_day'] = 'Give both the day and the month of the year end.';
        elseif ($row['year_end_day'] && ! checkdate((int) $row['year_end_month'], (int) $row['year_end_day'], 2024)) $e['year_end_day'] = 'That day does not exist in that month.';      /* 29 Feb allowed = last day of February */
        foreach (['vat_scheme' => 40, 'paye_ref' => 30, 'accounts_office_ref' => 30] as $k => $max) if (mb_strlen(trim((string) ($d[$k] ?? ''))) > $max) $e[$k] = "At most $max characters.";
        if (mb_strlen((string) ($row['notes'] ?? '')) > 4000) $e['notes'] = 'At most 4000 characters.';
        if (in_array($row['pay_frequency'], ['W', '4W'], true) && ! $row['first_payday']) $e['first_payday'] = 'Weekly and 4-weekly pay need the first payday.';
        if ($row['fk_accountant'] && ! in_array($row['fk_accountant'], array_column(AccFilings::accountants((int) Context::ent()), 'id'), true)) $e['fk_accountant'] = 'Choose an accountant of the Hub.';
        if ($e) throw \Illuminate\Validation\ValidationException::withMessages($e);
        DB::table('acc_company_settings')->updateOrInsert(['fk_id_ent' => (int) Context::ent(), 'fk_id_company' => $companyId], $row + ['updated_by' => $u->id ?? 0, 'updated_at' => now()]);
        Audit::log('acc_company_settings', $companyId, 'UPDATE', $row);
        $ent = (int) Context::ent(); $assigned = 0;      /* R-ACC-17: open unassigned filings get the accountant, then the schedule is rebuilt (R-ACC-9) */
        if ($row['fk_accountant']) $assigned = DB::table('acc_obligations')->where('fk_id_ent', $ent)->where('entity_type', 'COMPANY')->where('entity_id', $companyId)->where('f_done', 0)->where('fk_assigned', 0)->update(['fk_assigned' => $row['fk_accountant']]);
        $rebuilt = $row['f_tracked'] ? AccCompliance::rebuild($ent, $companyId) : 'not tracked';
        return ['message' => 'Accountancy Hub values of ' . $co->company_name . ' saved.' . ($assigned ? " $assigned filing(s) given to the accountant." : '') . ' Schedule: ' . $rebuilt . '.', 'due' => self::due($co, self::row($companyId))];
    }
}
