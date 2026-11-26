<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * What a person's item is used for, shown on its record page ("Used for"), and the read-only views of the people area:
 * R-C-35 a SIM: "Hotspot for PC {no}"; R-C-25 a photo ID / document: where it was submitted and when; R-C-31 a buyer account: its purchases;
 * R-C-29 a card: "spend / limit (n%)" of this statement; R-C-36 a company link: this person's director slot and share, and the company preview card;
 * R-C-19 the External Contacts list.
 */
class PersonLinks
{
    private static function name(?int $id): string { return $id ? trim((string) DB::table('t_account')->where('id', $id)->selectRaw("CONCAT_WS(' ', NULLIF(first_name,''), NULLIF(middle_name,''), NULLIF(sur_name,'')) n")->value('n')) : ''; }
    private static function pct($v): string { return rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.'); }

    public static function uses(string $table, int $id): array
    {
        $out = [];
        if ($table === 't_account_mobiles')
            foreach (DB::table('t_account_pc as p')->leftJoin('cat_tbl_pc as c', 'c.id', '=', 'p.pc_id')->where('p.hotspot_mobile_id', $id)->get(['p.id', 'c.pc_number']) as $r) $out[] = ['text' => 'Hotspot for PC ' . ($r->pc_number ?: '#' . $r->id), 'url' => '#/rec/t_account_pc/' . $r->id];
        if ($table === 't_account_photo_ids' || $table === 't_account_doc')
            foreach (PersonRules::submittedTo($table === 't_account_doc' ? 'doc' : 'photo', $id) as $s) $out[] = ['text' => 'Submitted to ' . $s['where'] . ' ' . ($s['name'] ?? '') . ($s['date'] ? ' on ' . $s['date'] : ''), 'url' => null];
        if ($table === 't_account_marketplaces') {
            $n = DB::table('t_src_po')->where('fk_id_buyer_account', $id)->where('f_active', 1)->count();
            if ($n || DB::table('t_account_marketplaces')->where('id', $id)->value('market_place_type') === 'Buyer') $out[] = ['text' => "Purchases ($n)", 'url' => $n ? '#/list/t_src_po?fk_id_buyer_account=' . $id : null, 'count' => $n];
        }
        if ($table === 't_account_emails' && ($e = DB::table('t_account_emails')->where('id', $id)->first(['email', 'recovery_email', 'recovery_owner_name']))) {      // R-C-27 the recovery chain, shown on both records
            if ($e->recovery_email && ($r = DB::table('t_account_emails')->whereRaw('LOWER(TRIM(email)) = ?', [mb_strtolower(trim($e->recovery_email))])->where('id', '<>', $id)->first(['id', 'fk_id_account'])))
                $out[] = ['text' => 'Recovered by ' . $e->recovery_email . ' (' . self::name((int) $r->fk_id_account) . ')', 'url' => '#/rec/t_account_email/' . $r->id];
            elseif ($e->recovery_email) $out[] = ['text' => 'Recovered by ' . $e->recovery_email . ($e->recovery_owner_name ? ' (' . $e->recovery_owner_name . ')' : '') . ' – not in the tool', 'url' => null];
            if ($e->email) foreach (DB::table('t_account_emails')->whereRaw('LOWER(TRIM(recovery_email)) = ?', [mb_strtolower(trim($e->email))])->where('id', '<>', $id)->get(['id', 'email']) as $r) $out[] = ['text' => 'Recovery e-mail of ' . $r->email, 'url' => '#/rec/t_account_email/' . $r->id];
        }
        if ($table === 't_account_credit_cards' && ($u = self::cardUsage($id))) $out[] = ['text' => $u['text'], 'url' => null, 'usage' => $u];
        if ($table === 't_account_company_houses' && ($l = DB::table('t_account_company_houses')->where('id', $id)->first(['fk_id_account', 'fk_company_house_id'])) && ($p = self::companyPreview((int) $l->fk_company_house_id, (int) $l->fk_id_account)) && $p['slot'])
            $out[] = ['text' => 'Director ' . $p['slot'] . ' · ' . collect($p['directors'])->firstWhere('slot', $p['slot'])['share'] . '%', 'url' => '#/rec/cat_company_house/' . $l->fk_company_house_id];
        return $out;
    }

    /** R-C-36 the read-only preview of a company before it is linked to a person; the person's director slot and share are read from the company. */
    public static function companyPreview(int $company, ?int $person = null): ?array
    {
        $c = DB::table('cat_company_house')->where('id', $company)->first(); if (! $c) return null;
        $addr = fn ($id) => $id ? trim((string) DB::table('t_account_addresses')->where('id', $id)->value(DB::raw("CONCAT_WS(', ', NULLIF(line1,''), NULLIF(line2,''), NULLIF(post_code,''))"))) : '';
        $dirs = []; $slot = null;
        foreach ([1, 2, 3] as $i) {
            $pid = (int) ($c->{"fk_director_$i"} ?? 0); if (! $pid) continue;
            $dirs[] = ['slot' => $i, 'name' => self::name($pid), 'share' => self::pct($c->{"director_{$i}_share"} ?? 0), 'me' => $person && $pid === $person];
            if ($person && $pid === $person) $slot ??= $i;
        }
        $g = fn ($k) => isset($c->$k) && $c->$k !== '' ? (string) $c->$k : null;
        return ['id' => (int) $c->id, 'name' => $c->company_name, 'iso' => $g('iso_code'), 'reg_no' => $g('registration_no'), 'vat' => $g('vat_no'), 'eori' => $g('eori_no'),
            'ch_phone' => $g('company_house_telephone'), 'phone' => $g('company_telephone'), 'emails' => array_values(array_filter([$g('company_email_1'), $g('company_emial_2')])),
            'address' => $g('company_address') ?? ($addr($c->fk_director_1_reg_addr ?? null) ?: null), 'external' => (int) ($c->f_external ?? 0) === 1,
            'directors' => $dirs, 'slot' => $slot, 'open' => '#/rec/cat_company_house/' . $c->id];
    }

    /** R-C-29 "spend / limit (n%)": card lines of this statement (since the last bill day) of the money accounts linked to the card. */
    public static function cardUsage(int $card): ?array
    {
        $c = DB::table('t_account_credit_cards')->where('id', $card)->first(['credit_limit', 'f_card_billing_payment_date']); if (! $c || $c->credit_limit === null || (float) $c->credit_limit <= 0) return null;
        $day = $c->f_card_billing_payment_date ? (int) date('j', strtotime((string) $c->f_card_billing_payment_date)) : 1;
        $from = date('Y-m-') . str_pad((string) min($day, (int) date('t')), 2, '0', STR_PAD_LEFT); if ($from > date('Y-m-d')) { $p = strtotime('first day of last month'); $from = date('Y-m-', $p) . str_pad((string) min($day, (int) date('t', $p)), 2, '0', STR_PAD_LEFT); }
        $accs = DB::table('fin_bank_accounts')->where('fk_id_card', $card)->pluck('id')->all();
        $spend = $accs ? round(-(float) DB::table('fin_bank_transactions')->whereIn('bank_account_id', $accs)->where('txn_date', '>=', $from)->where('amount', '<', 0)->sum('amount'), 2) : 0.0;
        $limit = (float) $c->credit_limit; $pct = (int) round($spend / $limit * 100);
        return ['spend' => $spend, 'limit' => $limit, 'pct' => $pct, 'from' => $from, 'linked' => (bool) $accs,
            'text' => number_format($spend, 0) . ' / ' . number_format($limit, 0) . " ($pct%)" . ($accs ? '' : ' – link the card to its money account (Money › Bank accounts) to see the spend')];
    }

    /** R-C-19 External Contacts: Code, Title + names, Roles, Country, first mobile, first e-mail, first company, bank count, Active; role chips with counts. */
    public static function external(int $ent, ?string $role = null): array
    {
        $ids = DB::table('t_account')->where('l_account_kind', 'E')->where(fn ($w) => $w->where('fk_id_ent', $ent)->orWhere('fk_id_ent', 0))->where(fn ($w) => $w->whereNull('fk_id_merged_into')->orWhere('fk_id_merged_into', 0))->pluck('id')->all();
        $roles = DB::table('t_account_in_types as i')->join('cat_account_types as t', 't.id', '=', 'i.fk_id_account_type')->whereIn('i.fk_id_account', $ids ?: [0])->where('i.f_active', 1)->orderBy('t.account_type_name')->get(['i.fk_id_account', 't.account_type_name', 't.role_code']);
        $byPerson = $roles->groupBy('fk_id_account');
        $chips = $roles->groupBy('account_type_name')->map(fn ($g, $n) => ['role' => $n, 'code' => $g->first()->role_code, 'count' => $g->pluck('fk_id_account')->unique()->count()])->values()->all();
        if ($role !== null && $role !== '') $ids = $roles->filter(fn ($r) => strcasecmp((string) $r->account_type_name, $role) === 0 || strcasecmp((string) $r->role_code, $role) === 0)->pluck('fk_id_account')->unique()->values()->all();
        $first = fn (string $t, string $col) => DB::table($t)->whereIn('fk_id_account', $ids ?: [0])->where(fn ($w) => $w->whereNull('f_active')->orWhere('f_active', 1))->orderBy('id')->get(['fk_id_account', $col])->groupBy('fk_id_account')->map(fn ($g) => $g->first()->$col);
        $mob = $first('t_account_mobiles', 'mobile_no'); $mail = $first('t_account_emails', 'email');
        $co = DB::table('t_account_company_houses as l')->join('cat_company_house as c', 'c.id', '=', 'l.fk_company_house_id')->whereIn('l.fk_id_account', $ids ?: [0])->orderBy('l.id')->get(['l.fk_id_account', 'c.company_name'])->groupBy('fk_id_account')->map(fn ($g) => $g->first()->company_name);
        $banks = DB::table('t_account_banks')->whereIn('fk_id_account', $ids ?: [0])->groupBy('fk_id_account')->pluck(DB::raw('COUNT(*)'), 'fk_id_account');
        $rows = DB::table('t_account as a')->leftJoin('cat_country as k', 'k.id', '=', 'a.current_nationality')->whereIn('a.id', $ids ?: [0])->orderBy('a.first_name')->orderBy('a.sur_name')
            ->get(['a.id', 'a.account_code', 'a.title', 'a.first_name', 'a.middle_name', 'a.sur_name', 'k.country_name', 'a.f_active'])
            ->map(fn ($a) => ['id' => (int) $a->id, 'code' => $a->account_code, 'name' => trim(implode(' ', array_filter([$a->title, $a->first_name, $a->middle_name, $a->sur_name]))),
                'roles' => ($byPerson[$a->id] ?? collect())->pluck('account_type_name')->unique()->sort()->values()->all(), 'country' => $a->country_name, 'mobile' => $mob[$a->id] ?? null, 'email' => $mail[$a->id] ?? null,
                'company' => $co[$a->id] ?? null, 'banks' => (int) ($banks[$a->id] ?? 0), 'active' => (int) $a->f_active === 1, 'open' => '#/rec/t_account/' . $a->id])->all();
        return ['title' => 'External Contacts (' . count($rows) . ')', 'rows' => $rows, 'chips' => $chips, 'role' => $role, 'empty' => 'No External Contacts yet. Add the first one.'];
    }
}
