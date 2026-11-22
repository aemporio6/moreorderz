<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Dependent dropdowns of the person forms (R-C-21, S2). Each chain narrows a list by the fields the form already has:
 * - mobile country → active networks of that country, and this person's addresses in that country;
 * - bank / card provider / marketplace / social platform / subscription item / company house / PayPal → its 6 security questions;
 * - bank + person → this person's accounts at that bank (payout and payment banks on seller accounts; paid-from on subscriptions);
 * - PayPal bank → this person's Debit cards at that bank (3 pickers), each showing its sort code;
 * - marketplace → its active shops "id-shop name"; device → its free slots; shop owner → that owner's companies with their directors.
 * - Every "own" list (the person's mobiles, e-mails, addresses, banks, cards, documents …) shows only that person's rows.
 * Returns null when a field is not a chain (the general cascade then applies).
 */
class Chains
{
    /** form => [question owner type, field naming the owner] */
    public const QUESTION_OWNER = [
        't_account_bank' => ['BANK', 'fk_bank_id'], 't_account_credit_card' => ['CARD_PROVIDER', 'fk_cc_provider_id'], 't_account_marketplace' => ['MARKETPLACE', 'fk_id_marketplace'],
        't_account_social_media' => ['SOCIAL', 'fk_social_media_id'], 't_account_subscription' => ['SUBSCRIPTION', 'fk_item_id'], 't_account_company_house' => ['COMPANIES_HOUSE', 'fk_company_house_id'],
        't_account_paypal' => ['PAYPAL', 'fk_bank_id'],
    ];
    /** form.field (own:t_account_banks) => the field holding the bank (cat_banks id) */
    public const BANK_OF = ['t_account_marketplace.fk_id_bank_account' => 'fk_id_bank', 't_account_marketplace.fk_id_bank_payment_account' => 'fk_id_bank_payment', 't_account_subscription.fk_id_bank_account' => 'fk_id_bank'];

    /** The parents each chain field follows – written into cascades_from so a changed parent clears its children. */
    public static function cascades(): array
    {
        $c = ['t_account_mobile' => ['fk_id_mobile_network' => 'fk_id_country', 'fk_id_address' => 'fk_id_account,fk_id_country', 'slotno' => 'fk_id_mobile_device'],
            't_account_paypal' => ['linked_debit_card_id1' => 'fk_id_account,fk_bank_id', 'linked_debit_card_id2' => 'fk_id_account,fk_bank_id', 'linked_debit_card_id3' => 'fk_id_account,fk_bank_id'],
            't_account_marketplace' => ['fk_id_bank_account' => 'fk_id_account,fk_id_bank', 'fk_id_bank_payment_account' => 'fk_id_account,fk_id_bank_payment', 'fk_id_shop' => 'fk_id_marketplace'],
            't_account_subscription' => ['fk_id_bank_account' => 'fk_id_account,fk_id_bank'], 't_account_job_history' => ['fk_id_branch' => 'fk_id_marketplace'],
            'shop' => ['company' => 'fk_id_owner'], 't_branch' => ['fk_id_company' => 'fk_id_owner']];
        foreach (self::QUESTION_OWNER as $form => [, $owner]) for ($i = 1; $i <= 6; $i++) $c[$form]["fk_question_id$i"] = $owner;
        return $c;
    }

    /** Value of a parent field by field name or column name. */
    private static function pv(array $parents, array $fields, string $name): ?string
    {
        if (isset($parents[$name]) && $parents[$name] !== '') return (string) $parents[$name];
        foreach ($fields as $f) if (($f['column_name'] === $name || $f['field_name'] === $name) && isset($parents[$f['field_name']]) && $parents[$f['field_name']] !== '') return (string) $parents[$f['field_name']];
        return null;
    }

    private static function like($q, ?string $text, string $col) { if ($text !== null && $text !== '') $q->where($col, 'like', '%' . addcslashes($text, '%_\\') . '%'); return $q; }

    public static function options(string $formKey, array $f, array $parents, ?string $q): ?array
    {
        if (($dp = DirectorPickers::options($formKey, (string) $f['field_name'], $parents, $q)) !== null) return $dp;      // R-F_SETUP-36: Director 1's own addresses / e-mails / mobiles
        $fields = Registry::fields($formKey); $name = $f['field_name']; $col = (string) $f['column_name'];
        $src = (string) $f['source']; [$kind, $arg] = array_pad(explode(':', $src, 2), 2, '');
        $person = self::pv($parents, $fields, 'fk_id_account');
        $map = fn ($rows) => $rows->map(fn ($r) => [(string) $r->id, (string) $r->lbl])->values()->all();

        // security questions of the bank / provider / marketplace … chosen
        if (preg_match('/^fk_question_id[1-6]$/', $name) && isset(self::QUESTION_OWNER[$formKey])) {
            [$type, $ownerField] = self::QUESTION_OWNER[$formKey]; $owner = self::pv($parents, $fields, $ownerField);
            $shared = in_array($type, ['PAYPAL', 'COMPANIES_HOUSE'], true);      // PayPal (set 1) and Company House links (set 2): one shared list (owner 0), the chosen item's own list first
            if ($owner === null && ! $shared) return ['options' => [], 'narrowed_by' => [$ownerField], 'hint' => 'Choose the ' . str_replace(['fk_', '_id', '_'], ['', '', ' '], $ownerField) . ' first.'];
            $x = DB::table('sys_security_questions')->where('owner_type', $type)->where('f_active', 1)->whereIn('owner_id', $shared ? array_unique([0, (int) $owner]) : [(int) $owner]);
            return ['options' => $map(self::like($x, $q, 'question')->orderBy('question', $type === 'MARKETPLACE' ? 'desc' : 'asc')->get(['id', 'question as lbl'])), 'narrowed_by' => [$ownerField]];      // A–Z; marketplace Z–A (old)
        }
        // mobile: networks of the country
        if ($formKey === 't_account_mobile' && $col === 'fk_id_mobile_network') {
            $c = self::pv($parents, $fields, 'fk_id_country'); if ($c === null) return ['options' => [], 'narrowed_by' => ['fk_id_country'], 'hint' => 'Choose the country first.'];
            return ['options' => $map(self::like(DB::table('cat_mobile_network')->where('fk_id_country', (int) $c)->where('f_active', 1), $q, 'network_name')->orderBy('network_name')->get(['id', 'network_name as lbl'])), 'narrowed_by' => ['fk_id_country']];
        }
        // device → its free slots (slots used by other mobiles of the device are left out)
        if ($formKey === 't_account_mobile' && $col === 'slotno') {
            $d = self::pv($parents, $fields, 'fk_id_mobile_device'); if ($d === null) return ['options' => [], 'narrowed_by' => ['fk_id_mobile_device'], 'hint' => 'Choose the device first.'];
            $tot = (int) DB::table('t_mobile_devices')->where('id', (int) $d)->value('tot_slots'); $mine = (string) ($parents['slotno'] ?? '');
            $used = DB::table('t_account_mobiles')->where('fk_id_mobile_device', (int) $d)->where(fn ($w) => $w->whereNull('f_active')->orWhere('f_active', 1))->pluck('slotno')->map(fn ($s) => (string) $s)->all();
            $free = []; for ($i = 1; $i <= $tot; $i++) if (! in_array((string) $i, $used, true) || (string) $i === $mine) $free[] = [(string) $i, "Slot $i"];
            return ['options' => $free, 'narrowed_by' => ['fk_id_mobile_device']];
        }
        // marketplace → its active shops "id-shop name"
        if ($arg === 't_branches' && ($mp = self::pv($parents, $fields, 'fk_id_marketplace')) !== null && in_array($formKey, ['t_account_marketplace', 't_account_job_history'], true)) {
            return ['options' => $map(self::like(DB::table('t_branches')->where('fk_id_marketplace', (int) $mp)->where('f_active', 1), $q, 'shop_name')->orderBy('id')->get(['id', DB::raw("CONCAT(id, '-', COALESCE(shop_name, '')) as lbl")])), 'narrowed_by' => ['fk_id_marketplace']];
        }
        // shop owner → that owner's companies, with their directors
        if ($kind === 'own' && $arg === 't_account_company_houses' && in_array($formKey, ['shop', 't_branch'], true)) {
            $o = self::pv($parents, $fields, 'fk_id_owner'); if ($o === null) return ['options' => [], 'narrowed_by' => ['fk_id_owner'], 'hint' => 'Choose the owner first.'];
            $ids = DB::table('t_account_company_houses')->where('fk_id_account', (int) $o)->pluck('fk_company_house_id')->filter()->all();
            $rows = DB::table('cat_company_house')->where(fn ($w) => $w->whereIn('id', $ids ?: [0])->orWhere('fk_director_1', (int) $o)->orWhere('fk_director_2', (int) $o)->orWhere('fk_director_3', (int) $o))->orderBy('company_name')->get();
            $names = DB::table('t_account')->whereIn('id', $rows->flatMap(fn ($r) => [$r->fk_director_1, $r->fk_director_2, $r->fk_director_3])->filter()->unique()->all() ?: [0])->get(['id', DB::raw("TRIM(CONCAT_WS(' ', NULLIF(first_name,''), NULLIF(sur_name,''))) as n")])->pluck('n', 'id');
            $out = [];
            foreach ($rows as $r) {
                $d = []; foreach ([1, 2, 3] as $i) if ($r->{"fk_director_$i"}) $d[] = "Director $i: " . ($names[$r->{"fk_director_$i"}] ?? '#' . $r->{"fk_director_$i"}) . ' Share: ' . rtrim(rtrim(number_format((float) $r->{"director_{$i}_share"}, 2, '.', ''), '0'), '.') . '%';
                $label = $r->company_name . ($d ? ' – [' . implode(', ', $d) . ']' : '');
                if ($q === null || $q === '' || mb_stripos($label, $q) !== false) $out[] = [(string) $r->id, $label];
            }
            return ['options' => $out, 'narrowed_by' => ['fk_id_owner']] + ($out || ($q !== null && $q !== '') ? [] : ['hint' => 'No company for this owner – press + Add']);
        }
        if ($kind !== 'own' || ! str_starts_with($arg, 't_account_')) return null;
        $shopOwner = in_array($formKey, ['shop', 't_branch'], true) && in_array($arg, ['t_account_addresses', 't_account_mobiles'], true);      // R-G_CORE-76: the shop owner's own addresses / mobiles
        if ($shopOwner) { $person = self::pv($parents, $fields, 'fk_id_owner'); if ($person === null) return ['options' => [], 'narrowed_by' => ['fk_id_owner'], 'hint' => 'Choose the owner first.']; }
        elseif (! collect($fields)->contains(fn ($x) => $x['column_name'] === 'fk_id_account')) return null;      // a form without a person field keeps the general list

        // every "own" list: this person's rows only
        if ($person === null) return ['options' => [], 'narrowed_by' => ['fk_id_account'], 'hint' => 'Choose the person first.'];
        $t = $arg; $lc = match ($t) { 't_account_mobiles' => 'mobile_no', 't_account_emails' => 'email', 't_account_banks' => 'account_no', 't_account_credit_cards' => 'card_last4', 't_account_addresses' => 'line1', default => Registry::labelColumn($t) ?: 'id' };
        if (! Registry::hasColumn($t, $lc)) $lc = Registry::labelColumn($t) ?: 'id';
        $x = DB::table($t)->where($t . '.fk_id_account', (int) $person); if (Registry::hasColumn($t, 'f_active')) $x->where(fn ($w) => $w->whereNull($t . '.f_active')->orWhere($t . '.f_active', 1));
        $narrow = [$shopOwner ? 'fk_id_owner' : 'fk_id_account'];
        if ($formKey === 't_account_mobile' && $col === 'fk_id_address' && ($c = self::pv($parents, $fields, 'fk_id_country')) !== null) { $x->where($t . '.fk_id_country', (int) $c); $narrow[] = 'fk_id_country'; }
        if (isset(self::BANK_OF["$formKey.$name"])) {
            $bf = self::BANK_OF["$formKey.$name"]; $b = self::pv($parents, $fields, $bf);
            if ($b === null) return ['options' => [], 'narrowed_by' => [$bf], 'hint' => 'Choose the bank first.'];
            $x->where($t . '.fk_bank_id', (int) $b); $narrow[] = $bf;
        }
        if ($formKey === 't_account_paypal' && preg_match('/^linked_debit_card_id[1-3]$/', $name)) {
            $b = self::pv($parents, $fields, 'fk_bank_id'); if ($b === null) return ['options' => [], 'narrowed_by' => ['fk_bank_id'], 'hint' => 'Choose the bank first.'];
            $x->where('fk_bank_id', (int) $b)->where('card_type', 'like', '%Debit%'); $narrow[] = 'fk_bank_id';
            return ['options' => $x->orderBy('id')->get()->map(fn ($r) => [(string) $r->id, trim(($r->name_on_card ?: 'Debit card') . ' ••' . ($r->card_last4 ?: substr((string) $r->front_four_digits, -4)) . ($r->sort_branch_code ? ' · sort code ' . $r->sort_branch_code : ''))])->all(), 'narrowed_by' => $narrow];
        }
        if ($t === 't_account_banks') {      // a bank account shows its bank and the last 4 digits only
            $bn = Registry::hasColumn('cat_banks', 'bank_name') ? 'bank_name' : (Registry::labelColumn('cat_banks') ?: 'id');
            return ['options' => $x->leftJoin('cat_banks as cb', 'cb.id', '=', 't_account_banks.fk_bank_id')->orderBy('t_account_banks.id')->get(['t_account_banks.id', 'cb.' . $bn . ' as bank', 't_account_banks.account_no', 't_account_banks.sort_branch_code'])
                ->map(fn ($r) => [(string) $r->id, trim(($r->bank ?: 'Bank') . ' ••' . substr(preg_replace('/\D/', '', (string) $r->account_no), -4) . ($r->sort_branch_code ? ' · sort code ' . $r->sort_branch_code : ''))])
                ->filter(fn ($o) => $q === null || $q === '' || mb_stripos($o[1], $q) !== false)->values()->all(), 'narrowed_by' => $narrow];
        }
        if (in_array($t, ['t_account_addresses', 't_account_mobiles', 't_account_credit_cards'], true)) {      // the rule's labels (R-C-20)
            return ['options' => $x->orderBy($t . '.id')->get([$t . '.*'])->map(fn ($r) => [(string) $r->id, OwnItems::label($t, $r) ?: '#' . $r->id])->filter(fn ($o) => $q === null || $q === '' || mb_stripos($o[1], $q) !== false)->values()->all(), 'narrowed_by' => $narrow];
        }
        $rows = $x->orderBy('id')->get(['id', $lc . ' as lbl'] + (Registry::hasColumn($t, 'sort_branch_code') ? [2 => 'sort_branch_code'] : []));
        $opts = $rows->map(fn ($r) => [(string) $r->id, (string) ($r->lbl ?? $r->id) . (! empty($r->sort_branch_code) ? ' · ' . $r->sort_branch_code : '')])->filter(fn ($o) => $q === null || $q === '' || mb_stripos($o[1], $q) !== false)->values()->all();
        return ['options' => $opts, 'narrowed_by' => $narrow];
    }
}
