<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * External Contact quick form (R-C-10): one screen that saves a person (kind E) with roles, addresses, mobiles, e-mails, company, banks, PayPal and
 * – for a paid role – one Job history row. Old External_contact_model::validate / create, with the old messages word for word.
 * Roles as pills: is_ec roles first (sort order), then "Show all roles (N more)" A–Z (role flags in the roles master, R-C-43, not config).
 * Repeatable rows are sent as arrays; empty rows are ignored; a row with no country takes the person's current country. Banks: an existing bank of the
 * list, or 'new' + the name (an equal name in the same country is reused). Company: none / new (name, country, VAT upper-cased) / existing (f_external=1 only).
 * PayPal: name or e-mail typed → a valid PayPal e-mail; the e-mail is added to E-mails when new. Payment terms are ignored while no paid role is ticked;
 * with one they need the marketplace and the start date (v1.7 B31 / R-C-39); salary is a whole number of 1–9 digits; blank dates are NULL (never 0000-00-00).
 */
class ExternalContact
{
    public const KIND = 'E';
    public const TITLES = ['Mr', 'Mrs', 'Miss', 'Ms', 'Dr'];

    /** Roles: is_ec first in sort order, then the rest A–Z. */
    public static function roles(int $ent): array
    {
        $rows = DB::table('cat_account_types')->where('f_active', 1)->where(fn ($w) => $w->where('fk_id_ent', $ent)->orWhere('fk_id_ent', 0))
            ->get(['id', 'account_type_name as name', 'role_code', 'is_ec', 'is_paid', 'sort'])->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->name, 'code' => $r->role_code, 'is_ec' => (int) $r->is_ec === 1, 'is_paid' => (int) $r->is_paid === 1, 'sort' => (int) $r->sort])->all();
        usort($rows, fn ($a, $b) => [$a['is_ec'] ? 0 : 1, $a['is_ec'] ? $a['sort'] : 0, strtoupper($a['name'])] <=> [$b['is_ec'] ? 0 : 1, $b['is_ec'] ? $b['sort'] : 0, strtoupper($b['name'])]);
        return $rows;
    }

    private static function countries(): array
    {
        return DB::table('cat_country')->where('f_active', 1)->orderBy('f_order')->orderBy('country_name')->get(['id', 'country_name as name'])->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->name])->all();
    }

    /** Everything the form needs. Default country = United Kingdom when in the list, else the first. */
    public static function meta(int $ent): array
    {
        $c = self::countries(); $uk = collect($c)->first(fn ($x) => strcasecmp($x['name'], 'United Kingdom') === 0);
        return ['roles' => self::roles($ent), 'countries' => $c, 'default_country' => $uk['id'] ?? ($c[0]['id'] ?? null), 'titles' => self::TITLES,
            'banks' => DB::table('cat_banks')->where('f_active', 1)->orderBy('bank_name')->get(['id', 'bank_name as name', 'country_id'])->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->name, 'country' => (int) $r->country_id])->all(),
            'currencies' => DB::table('cat_currency')->where('f_active', 1)->orderBy('currency_code')->get(['id', 'currency_code as code'])->map(fn ($r) => ['id' => (int) $r->id, 'code' => $r->code])->all(),
            'marketplaces' => DB::table('cat_marketplaces')->where('f_active', 1)->orderBy('marketplace_descr')->get(['id', 'marketplace_descr as name'])->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->name])->all(),
            'companies' => self::companies($ent), 'code' => AccountCode::suggest($ent, true)];
    }

    private static function companies(int $ent): array
    {
        return DB::table('cat_company_house')->where('f_external', 1)->where('f_active', 1)->where('fk_id_ent', $ent)->orderBy('company_name')->get(['id', 'company_name as name', 'fk_country_id as country'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'name' => (string) $r->name, 'country' => (int) $r->country])->all();
    }

    private static function s($v): string { return is_scalar($v) ? (string) preg_replace('/\s+/u', ' ', trim((string) $v)) : ''; }
    private static function rows($v): array { return is_array($v) ? array_values($v) : []; }
    private static function date(string $v): bool { $d = \DateTime::createFromFormat('!Y-m-d', $v); return $d && $d->format('Y-m-d') === $v; }
    private static function latin1(string $v): bool { return $v === '' || ! preg_match('/[^\x00-\x7F]/', $v) || @iconv('UTF-8', 'CP1252', $v) !== false; }

    public static function ibanOk(string $iban): bool
    {
        if (! preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{10,30}$/', $iban)) return false;
        $num = ''; foreach (str_split(substr($iban, 4) . substr($iban, 0, 4)) as $ch) $num .= ctype_alpha($ch) ? (string) (ord($ch) - 55) : $ch;
        $mod = 0; foreach (str_split($num, 7) as $part) $mod = (int) (($mod . $part) % 97);
        return $mod === 1;
    }

    /** The old checks, field keys as posted ("banks[0][bank_id]"). Returns [clean, errors]. */
    public static function validate(int $ent, array $in): array
    {
        $e = []; $c = [];
        $roles = collect(self::roles($ent))->keyBy('id'); $countries = array_column(self::countries(), 'id');
        $banks = DB::table('cat_banks')->where('f_active', 1)->pluck('id')->map(fn ($x) => (int) $x)->all();
        $curs = DB::table('cat_currency')->where('f_active', 1)->pluck('id')->map(fn ($x) => (int) $x)->all();
        $mps = DB::table('cat_marketplaces')->where('f_active', 1)->pluck('id')->map(fn ($x) => (int) $x)->all();
        $len = function ($f, $v, $max, $label) use (&$e) { if (mb_strlen($v) > $max) { $e[$f] = "$label can be at most $max characters."; return false; } return true; };
        $text = function ($f, $v, $label, $latin) use (&$e) {
            if ($v === '') return true;
            if ($latin && ! self::latin1($v)) { $e[$f] = "$label can only use English letters here (this field cannot store Chinese, Urdu, Bengali... letters). Please type it in English."; return false; }
            if (preg_match('/[\x{10000}-\x{10FFFF}]/u', $v)) { $e[$f] = "$label cannot contain emoji or special symbols."; return false; }
            return true; };
        $country = function ($f, $id, $label) use (&$e, $countries) { if (! in_array((int) $id, $countries, true)) { $e[$f] = "Please choose the $label."; return false; } return true; };

        // Type & role
        $c['roles'] = array_values(array_unique(array_filter(array_map('intval', self::rows($in['roles'] ?? [])), fn ($r) => $roles->has($r))));
        if (! $c['roles']) $e['roles'] = 'Please pick at least one role (for example Vendor).';
        $c['is_paid'] = collect($c['roles'])->contains(fn ($r) => $roles[$r]['is_paid']);

        // Profile
        $c['account_code'] = strtoupper(self::s($in['account_code'] ?? ''));
        $data = ['account_code' => $c['account_code'], 'fk_id_ent' => $ent];
        if ($c['account_code'] === '') $e['account_code'] = 'Account Code is required.';
        elseif ($x = AccountCode::check('external_contact', $data, [], null)) $e += $x;
        $c['title'] = self::s($in['title'] ?? '');
        if ($c['title'] !== '' && ! in_array($c['title'], self::TITLES, true)) $e['title'] = 'Please choose a title.';
        foreach (['first_name' => 'First Name', 'middle_name' => 'Middle Name', 'sur_name' => 'Surname'] as $f => $label) {
            $c[$f] = self::s($in[$f] ?? '');
            if ($c[$f] === '' && $f !== 'middle_name') $e[$f] = "$label is required.";
            elseif ($text($f, $c[$f], $label, true)) $len($f, $c[$f], 200, $label);
        }
        $c['current_country'] = (int) ($in['current_country'] ?? 0); $country('current_country', $c['current_country'], 'Current Country');
        $c['f_active'] = (string) ($in['f_active'] ?? '1') === '0' ? 0 : 1;
        $c['private_notes'] = is_scalar($in['private_notes'] ?? null) ? trim((string) $in['private_notes']) : '';
        if ($text('private_notes', $c['private_notes'], 'Private Notes', false)) $len('private_notes', $c['private_notes'], 5000, 'Private Notes');
        $def = in_array($c['current_country'], $countries, true) ? $c['current_country'] : 0;

        // Addresses
        $c['addresses'] = [];
        foreach (self::rows($in['addresses'] ?? []) as $k => $row) { $row = (array) $row; $p = "addresses[$k]";
            $a = ['country' => (int) ($row['country'] ?? 0), 'type' => ($row['type'] ?? '') === 'H' ? 'H' : 'O', 'line1' => self::s($row['line1'] ?? ''), 'line2' => self::s($row['line2'] ?? ''), 'post_code' => strtoupper(self::s($row['post_code'] ?? '')), 'counti' => self::s($row['counti'] ?? '')];
            if ($a['line1'] === '' && $a['line2'] === '' && $a['post_code'] === '' && $a['counti'] === '') continue;
            if (! $a['country']) $a['country'] = $def;
            $country("{$p}[country]", $a['country'], 'address country');
            if ($a['line1'] === '') $e["{$p}[line1]"] = 'Address is required (street, building, unit).';
            foreach (['line1' => ['Address', 500], 'line2' => ['Address line 2', 500], 'post_code' => ['Post Code', 50], 'counti' => ['County/City', 500]] as $f => $m) if ($text("{$p}[$f]", $a[$f], $m[0], true)) $len("{$p}[$f]", $a[$f], $m[1], $m[0]);
            $c['addresses'][] = $a; }

        // Mobiles
        $c['mobiles'] = []; $seen = [];
        foreach (self::rows($in['mobiles'] ?? []) as $k => $row) { $row = (array) $row; $p = "mobiles[$k]";
            $m = ['country' => (int) ($row['country'] ?? 0), 'mobile_no' => self::s($row['mobile_no'] ?? ''), 'type' => ($row['type'] ?? '') === 'H' ? 'H' : 'O'];
            if ($m['mobile_no'] === '') continue;
            if (! $m['country']) $m['country'] = $def;
            $country("{$p}[country]", $m['country'], 'mobile country');
            $d = preg_replace('/\D/', '', $m['mobile_no']);
            if (! preg_match('/^\+?[0-9 ()\-]{6,20}$/', $m['mobile_no']) || strlen($d) < 6) $e["{$p}[mobile_no]"] = 'Mobile No: numbers only (spaces, +, - and brackets are fine), 6 to 20 characters.';
            elseif (isset($seen[$d])) $e["{$p}[mobile_no]"] = 'This mobile number is entered twice.';
            $seen[$d] = true; $c['mobiles'][] = $m; }

        // E-mails
        $c['emails'] = []; $seen = [];
        foreach (self::rows($in['emails'] ?? []) as $k => $row) { $row = (array) $row; $p = "emails[$k][email]"; $em = strtolower(self::s($row['email'] ?? ''));
            if ($em === '') continue;
            if (! filter_var($em, FILTER_VALIDATE_EMAIL) || strlen($em) > 200) $e[$p] = 'Please enter a valid email address.';
            elseif (isset($seen[$em])) $e[$p] = 'This email is entered twice.';
            $seen[$em] = true; $c['emails'][] = $em; }
        if (! $c['mobiles'] && ! $c['emails']) $e['mobiles[0][mobile_no]'] = 'Add at least a mobile number or an email, so we can reach this person.';

        // Company
        $mode = (string) ($in['company_mode'] ?? 'none'); $c['company_mode'] = in_array($mode, ['none', 'new', 'existing'], true) ? $mode : 'none'; $c['company'] = null;
        if ($c['company_mode'] === 'new') {
            $co = ['name' => self::s($in['company_name'] ?? ''), 'country' => (int) ($in['company_country'] ?? 0) ?: $def, 'vat_no' => strtoupper(self::s($in['company_vat'] ?? ''))];
            if ($co['name'] === '') $e['company_name'] = 'Company Name is required (or choose "No company").';
            elseif ($text('company_name', $co['name'], 'Company Name', false)) $len('company_name', $co['name'], 150, 'Company Name');
            $country('company_country', $co['country'], 'company country');
            if ($text('company_vat', $co['vat_no'], 'VAT No', false)) $len('company_vat', $co['vat_no'], 100, 'VAT No');
            $c['company'] = $co;
        } elseif ($c['company_mode'] === 'existing') {
            $cid = (int) ($in['company_id'] ?? 0);
            if (! $cid || ! DB::table('cat_company_house')->where('id', $cid)->where('f_external', 1)->where('fk_id_ent', $ent)->exists()) $e['company_id'] = 'Please choose the company.';
            $c['company'] = ['id' => $cid];
        }

        // Banks
        $c['banks'] = [];
        foreach (self::rows($in['banks'] ?? []) as $k => $row) { $row = (array) $row; $p = "banks[$k]";
            $b = ['country' => (int) ($row['country'] ?? 0), 'bank_id' => (string) ($row['bank_id'] ?? ''), 'new_bank_name' => self::s($row['new_bank_name'] ?? ''), 'currency_id' => (int) ($row['currency_id'] ?? 0),
                'account_no' => preg_replace('/\s+/', '', self::s($row['account_no'] ?? '')), 'sort_code' => self::s($row['sort_code'] ?? ''), 'iban' => strtoupper(preg_replace('/\s+/', '', self::s($row['iban'] ?? ''))), 'swift' => strtoupper(preg_replace('/\s+/', '', self::s($row['swift'] ?? '')))];
            if (in_array($b['bank_id'], ['', '0'], true) && $b['new_bank_name'] === '' && $b['account_no'] === '' && $b['sort_code'] === '' && $b['iban'] === '' && $b['swift'] === '') continue;
            if (! $b['country']) $b['country'] = $def;
            $country("{$p}[country]", $b['country'], 'bank country');
            if ($b['bank_id'] === 'new') {
                if ($b['new_bank_name'] === '') $e["{$p}[new_bank_name]"] = 'Type the new bank name.';
                elseif ($text("{$p}[new_bank_name]", $b['new_bank_name'], 'Bank name', false)) $len("{$p}[new_bank_name]", $b['new_bank_name'], 150, 'Bank name');
                $b['bank_id'] = 0;
            } else { $b['bank_id'] = (int) $b['bank_id']; $b['new_bank_name'] = ''; if (! in_array($b['bank_id'], $banks, true)) $e["{$p}[bank_id]"] = 'Please choose the bank (or "+ New bank").'; }
            if ($b['currency_id'] && ! in_array($b['currency_id'], $curs, true)) $e["{$p}[currency_id]"] = 'Please choose a currency from the list.';
            if ($b['account_no'] === '' && $b['iban'] === '') $e["{$p}[account_no]"] = 'Enter the Account Number or the IBAN.';
            elseif ($b['account_no'] !== '' && ! preg_match('/^[A-Za-z0-9\-]{3,34}$/', $b['account_no'])) $e["{$p}[account_no]"] = 'Account Number: letters and numbers only, 3 to 34 characters.';
            if ($b['sort_code'] !== '' && ! preg_match('/^[0-9 \-]{4,12}$/', $b['sort_code'])) $e["{$p}[sort_code]"] = 'Sort Code: numbers, e.g. 04-06-05.';
            if ($b['iban'] !== '' && ! self::ibanOk($b['iban'])) $e["{$p}[iban]"] = 'This IBAN does not look right. Please check it.';
            if ($b['swift'] !== '' && ! preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $b['swift'])) $e["{$p}[swift]"] = 'SWIFT/BIC is 8 or 11 letters/numbers, e.g. HSBCGB2L.';
            $c['banks'][] = $b; }

        // PayPal
        $c['paypal'] = null; $pn = self::s($in['paypal_name'] ?? ''); $pe = strtolower(self::s($in['paypal_email'] ?? ''));
        if ($pn !== '' || $pe !== '') {
            if ($pe === '' || ! filter_var($pe, FILTER_VALIDATE_EMAIL) || strlen($pe) > 200) $e['paypal_email'] = 'Please enter the PayPal email address.';
            if ($text('paypal_name', $pn, 'PayPal Name', false)) $len('paypal_name', $pn, 150, 'PayPal Name');
            $c['paypal'] = ['name' => $pn, 'email' => $pe];
        }

        // Payment terms → one Job history row (only with a paid role)
        $c['job'] = null; $jm = (int) ($in['job_marketplace'] ?? 0); $jsd = self::s($in['job_start_date'] ?? ''); $jsl = self::s($in['job_salary'] ?? ''); $jdd = self::s($in['job_salary_date'] ?? '');
        if ($c['is_paid'] && ($jm || $jsd !== '' || $jsl !== '' || $jdd !== '')) {
            if (! in_array($jm, $mps, true)) $e['job_marketplace'] = 'Please choose the marketplace this person works on.';
            if ($jsd === '' || ! self::date($jsd)) $e['job_start_date'] = $jsd === '' ? 'Start Date is required.' : 'Please choose a valid date.';
            if ($jdd !== '' && ! self::date($jdd)) $e['job_salary_date'] = 'Please choose a valid date.';
            if ($jsl !== '' && ! preg_match('/^[0-9]{1,9}$/', $jsl)) $e['job_salary'] = 'Salary / Rate: whole number only (no decimals or symbols).';
            $c['job'] = ['marketplace' => $jm, 'start' => $jsd ?: null, 'salary' => $jsl !== '' ? (int) $jsl : 0, 'salary_date' => $jdd ?: null];
        }
        return [$c, $e];
    }

    private static function ins(string $t, array $d): int { return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }

    /** Check and save. Throws ValidationErrors with the posted keys. */
    /** Test hook for R-C-9: the step that must fail (e.g. 'bank') to prove that nothing is saved. Never set outside tests. */
    public static ?string $failAt = null;
    public const SECTIONS = [['roles', 'Type & role', true], ['profile', 'Profile', true], ['addresses', 'Addresses', false], ['reach', 'Reach (mobile or e-mail)', true], ['company', 'Company', false], ['banks', 'Banks', false], ['paypal', 'PayPal', false]];

    /** R-C-11 live status of the quick form: each section required / optional / done, and "N of 3 required sections done". Reads only. */
    public static function progress(array $in): array
    {
        $s = fn ($k) => trim((string) ($in[$k] ?? '')); $rows = fn ($k, $f) => count(array_filter((array) ($in[$k] ?? []), fn ($r) => trim((string) (is_array($r) ? ($r[$f] ?? '') : $r)) !== ''));
        $done = ['roles' => (bool) array_filter((array) ($in['roles'] ?? [])), 'profile' => $s('account_code') !== '' && $s('first_name') !== '' && $s('sur_name') !== '' && (int) ($in['current_country'] ?? 0) > 0,
            'addresses' => $rows('addresses', 'line1') > 0, 'reach' => $rows('mobiles', 'mobile_no') + $rows('emails', 'email') > 0,      /* mobile + e-mail share the one REACH rule */
            'company' => ($in['company_mode'] ?? 'none') !== 'none' && ($s('company_name') !== '' || (int) ($in['company_id'] ?? 0) > 0), 'banks' => $rows('banks', 'account_no') + $rows('banks', 'iban') > 0, 'paypal' => $s('paypal_email') !== ''];
        $out = []; $n = 0; $of = 0;
        foreach (self::SECTIONS as [$k, $title, $req]) { $out[] = ['key' => $k, 'title' => $title, 'status' => $done[$k] ? 'done' : ($req ? 'required' : 'optional')]; if ($req) { $of++; $n += $done[$k] ? 1 : 0; } }
        return ['sections' => $out, 'done' => $n, 'of' => $of, 'counter' => "$n of $of required sections done"];
    }

    public static function create(int $ent, array $in, int $userId): array
    {
        [$c, $e] = self::validate($ent, $in);
        if ($e) throw new ValidationErrors($e);
        /* R-C-8 old order: field checks first, then the look-alike scan; "Save anyway" needs a reason (dup_reason) */
        $alike = LookAlikes::guard(LookAlikes::find(trim($c['first_name'] . ' ' . $c['sur_name']), $c['emails'], array_column($c['mobiles'], 'mobile_no'), null, $ent), isset($in['dup_reason']) ? (string) $in['dup_reason'] : null);
        try { $res = DB::transaction(function () use ($c, $ent, $userId) {
            $today = now()->toDateString();
            $id = self::ins('t_account', ['fk_id_ent' => $ent, 'account_code' => $c['account_code'], 'first_name' => $c['first_name'], 'middle_name' => $c['middle_name'], 'sur_name' => $c['sur_name'], 'title' => $c['title'],
                'f_active' => $c['f_active'], 'f_login' => 0, 'current_nationality' => $c['current_country'], 'old_nationality' => $c['current_country'], 'private_notes' => $c['private_notes'], 'l_account_kind' => self::KIND]);
            foreach ($c['roles'] as $r) self::ins('t_account_in_types', ['fk_id_account' => $id, 'fk_id_account_type' => $r, 'f_active' => 1]);
            $addr = array_map(fn ($a) => self::ins('t_account_addresses', ['fk_id_account' => $id, 'line1' => $a['line1'], 'line2' => $a['line2'] ?: null, 'post_code' => $a['post_code'] ?: null, 'counti' => $a['counti'] ?: null, 'fk_id_country' => $a['country'], 'l_address_type' => $a['type'], 'f_active' => 1]), $c['addresses']);
            $a1 = $addr[0] ?? 0;
            $mob = array_map(fn ($m) => self::ins('t_account_mobiles', ['fk_id_account' => $id, 'l_office_home' => $m['type'], 'fk_id_country' => $m['country'], 'mobile_no' => $m['mobile_no'], 'fk_id_address' => $a1 ?: null, 'f_active' => 1]), $c['mobiles']);
            $m1 = $mob[0] ?? 0; $mails = [];
            $addMail = function (string $em) use ($id, $m1, &$mails) { return $mails[$em] = self::ins('t_account_emails', ['fk_id_account' => $id, 'fk_id_mobile' => $m1 ?: null, 'email' => $em, 'f_active' => 1]); };
            foreach ($c['emails'] as $em) $addMail($em);
            $e1 = $mails ? reset($mails) : 0;
            $co = 0;
            if ($c['company_mode'] === 'new') {
                $co = self::ins('cat_company_house', ['fk_id_ent' => $ent, 'fk_director_1' => $id, 'director_1_share' => '100', 'company_name' => $c['company']['name'], 'vat_no' => $c['company']['vat_no'] ?: null, 'fk_country_id' => $c['company']['country'],
                    'creation_date' => $today, 'company_telephone' => $c['mobiles'][0]['mobile_no'] ?? null, 'company_email_1' => $c['emails'][0] ?? null, 'company_address' => $c['addresses'] ? mb_substr(implode(', ', array_filter([$c['addresses'][0]['line1'], $c['addresses'][0]['line2'], $c['addresses'][0]['counti'], $c['addresses'][0]['post_code']])), 0, 255) : null,
                    'f_active' => 1, 'fk_director_1_home_addr' => $a1, 'fk_director_1_reg_addr' => $a1, 'fk_director_1_reg_mobile' => $m1, 'fk_director_1_reg_email' => $e1, 'f_external' => 1]);
            } elseif ($c['company_mode'] === 'existing') $co = (int) $c['company']['id'];
            $coLink = 0;      /* the bank's company field is the person's own company link (own:t_account_company_houses), as on the Full form */
            if ($co) $coLink = self::ins('t_account_company_houses', ['fk_id_account' => $id, 'fk_company_house_id' => $co, 'fk_country_id' => ($c['company_mode'] === 'new' ? $c['company']['country'] : (int) DB::table('cat_company_house')->where('id', $co)->value('fk_country_id')) ?: $c['current_country'], 'creation_date' => $today, 'f_active' => 1, 'fk_doc_submitted_id' => 0]);
            foreach ($c['banks'] as $b) {
                if (self::$failAt === 'bank') throw new \RuntimeException('forced bank failure');
                $bank = $b['bank_id'] ?: self::bankFor($b['new_bank_name'], $b['country'], $ent);
                self::ins('t_account_banks', ['fk_id_account' => $id, 'country_id' => $b['country'], 'fk_bank_id' => $bank, 'linked_mobile' => $m1, 'linked_email' => $e1, 'linked_address' => $a1, 'account_no' => $b['account_no'] ?: null, 'sort_branch_code' => $b['sort_code'] ?: null,
                    'iban_no' => $b['iban'] ?: null, 'swift_code' => $b['swift'] ?: null, 'f_active' => 1, 'account_type' => $co ? 'Business' : 'Personal', 'fk_id_company' => $coLink, 'fk_currency_id' => $b['currency_id']]);
            }
            if ($c['paypal']) {
                $pe = $mails[$c['paypal']['email']] ?? $addMail($c['paypal']['email']);      // the PayPal e-mail is listed in E-mails too
                self::ins('t_account_paypal', ['fk_id_account' => $id, 'paypal_account_type' => $co ? 'Business' : 'Personal', 'paypal_name' => $c['paypal']['name'] ?: trim($c['first_name'] . ' ' . $c['sur_name']), 'country_id' => $c['current_country'], 'fk_bank_id' => 0,
                    'linked_mobile' => $m1 ?: null, 'linked_email' => $pe, 'linked_address' => $a1 ?: null, 'f_active' => 1, 'fk_doc_submitted_id' => 0, 'registered_mobile_id' => $m1]);
            }
            $job = null;
            if ($c['job']) $job = self::ins('t_account_job_history', ['fk_id_account' => $id, 'fk_id_marketplace' => $c['job']['marketplace'], 'd_job_start_date' => $c['job']['start'], 'salary' => $c['job']['salary'], 'salary_date' => $c['job']['salary_date'],
                'fk_id_currency' => DB::table('cat_country')->where('id', $c['current_country'])->value('fk_id_default_currency') ?: null, 'f_active' => 1]);      // renewal, share %, £ rate blank (R-C-39)
            Audit::log('t_account', $id, 'INSERT', ['form' => 'external_contact', 'account_code' => $c['account_code'], 'roles' => $c['roles'], 'addresses' => count($addr), 'mobiles' => count($mob), 'emails' => count($mails), 'banks' => count($c['banks']), 'company' => $co ?: null, 'paypal' => (bool) $c['paypal'], 'job_history' => $job], null, $ent);
            SearchIndex::put('external_contact', 't_account', $id, $ent);
            $name = trim($c['first_name'] . ' ' . $c['sur_name']);
            return ['status' => 'ok', 'id' => $id, 'job_id' => $job, 'company_id' => $co ?: null, 'message' => "External Contact {$c['account_code']} saved.", 'name' => $name, 'open' => "#/rec/external_contact/$id", 'open_route' => "#/rec/external_contact/$id", 'open_list' => '#/list/external_contact'];
        }); }
        catch (\Illuminate\Database\QueryException $x) { report($x); throw new QuickSaveFailed('Nothing was saved. The database refused one of the values – the detail is in the error log.'); }      /* R-C-9 one transaction: any error rolls everything back */
        catch (\RuntimeException $x) { if ($x instanceof QuickSaveFailed) throw $x; report($x); throw new QuickSaveFailed('Nothing was saved. ' . ($x instanceof \InvalidArgumentException ? $x->getMessage() : 'A step of the save failed – the detail is in the error log.')); }
        if ($alike) LookAlikes::logged((int) $res['id'], $alike, (string) $in['dup_reason']);
        return $res;
    }

    /** A bank with the same name in the same country is reused, else added to the banks list. */
    private static function bankFor(string $name, int $country, int $ent): int
    {
        $id = DB::table('cat_banks')->whereRaw('LOWER(TRIM(bank_name)) = ?', [mb_strtolower($name)])->where('country_id', $country)->orderByDesc('f_active')->orderBy('id')->value('id');
        return $id ? (int) $id : self::ins('cat_banks', ['fk_id_ent' => $ent, 'f_active' => 1, 'bank_short_name' => mb_substr($name, 0, 50), 'bank_name' => $name, 'country_id' => $country]);
    }
}
