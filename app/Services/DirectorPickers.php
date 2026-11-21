<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Company (cat_company_house) director pickers.
 *  - R-F_SETUP-36: choosing Director 1 reloads four pickers with only that person's active items:
 *    Home address and Registered address (label "Home|Office <county> <country> <line1>", Home first, then Office, then line 1),
 *    Registered e-mail (A-Z) and Registered mobile (office / home, then code). Changing Director 1 clears the four (cascades_from);
 *    "+ Add" opens Director 1's own sub-list and comes back selected. A saved item that is not Director 1's is refused.
 *  - R-F_SETUP-38: Directors 1–3 (and Director 1's "+ Add") = parties with an active role OWNER, DIRECTOR or PARTNER;
 *    "+ Add" on a director creates the party with role DIRECTOR.
 */
class DirectorPickers
{
    public const FORM = 'cat_company_house';
    public const PERSON = 'fk_director_1';
    /** field => [kind, table, placeholder] */
    public const FIELDS = [
        'fk_director_1_home_addr' => ['addr', 't_account_addresses', 'Select Address'],
        'fk_director_1_reg_addr' => ['addr', 't_account_addresses', 'Select Address'],
        'fk_director_1_reg_mobile' => ['mobile', 't_account_mobiles', 'Select Mobile'],
        'fk_director_1_reg_email' => ['email', 't_account_emails', 'Select Email'],
    ];
    /* the director roles are the Settings › Choices multiple choice DIRECTOR_ROLES (default Owner, Director, Partner); registry source party:@DIRECTOR_ROLES */
    public const DIRECTORS = ['fk_director_1', 'fk_director_2', 'fk_director_3'];
    public const ADD_ROLE = 'DIRECTOR';

    public static function isPicker(string $formKey, string $name): bool { return $formKey === self::FORM && isset(self::FIELDS[$name]); }
    public static function placeholder(string $formKey, string $name): ?string { return self::isPicker($formKey, $name) ? self::FIELDS[$name][2] : null; }

    /** The field holding the person whose sub-list "+ Add" opens. */
    public static function personField(string $formKey, string $name): ?string { return self::isPicker($formKey, $name) ? self::PERSON : null; }

    private static function active($q, string $t) { return $q->where(fn ($w) => $w->whereNull("$t.f_active")->orWhere("$t.f_active", 1)); }

    /** "Home|Office <county> <country> <line1>" (type H = Home, else Office). */
    public static function addressLabel(object $r): string
    {
        $country = $r->fk_id_country ? (string) DB::table('cat_country')->where('id', $r->fk_id_country)->value('country_name') : '';
        return trim(implode(' ', array_filter([strtoupper((string) $r->l_address_type) === 'H' ? 'Home' : 'Office', $r->counti, $country, $r->line1], fn ($x) => trim((string) $x) !== '')));
    }

    /** [[id, label], ...] of one picker for one director, in the rule's order. */
    public static function items(string $name, int $person): array
    {
        [$kind, $t] = self::FIELDS[$name];
        $x = self::active(DB::table($t)->where("$t.fk_id_account", $person), $t);
        if ($kind === 'addr') return $x->orderByRaw("CASE WHEN UPPER(l_address_type) = 'H' THEN 0 ELSE 1 END")->orderBy('line1')->orderBy('id')->get()->map(fn ($r) => [(string) $r->id, self::addressLabel($r)])->all();
        if ($kind === 'email') return $x->orderBy('email')->get(['id', 'email'])->map(fn ($r) => [(string) $r->id, (string) $r->email])->all();
        return $x->leftJoin('cat_country as c', 'c.id', '=', "$t.fk_id_country")->orderBy("$t.l_office_home")->orderBy('c.country_code')->orderBy("$t.mobile_no")->orderBy("$t.id")->get(["$t.*"])
            ->map(fn ($r) => [(string) $r->id, OwnItems::label($t, $r) ?: (string) $r->mobile_no])->all();
    }

    /** Options for Chains::options (null = not one of these fields). */
    public static function options(string $formKey, string $name, array $parents, ?string $q): ?array
    {
        if (! self::isPicker($formKey, $name)) return null;
        $p = (int) ($parents[self::PERSON] ?? 0);
        if (! $p) return ['options' => [], 'narrowed_by' => [self::PERSON], 'hint' => 'Choose Director 1 first.', 'placeholder' => self::FIELDS[$name][2]];
        $o = array_values(array_filter(self::items($name, $p), fn ($i) => $q === null || $q === '' || mb_stripos($i[1], $q) !== false));
        return ['options' => $o, 'narrowed_by' => [self::PERSON], 'placeholder' => self::FIELDS[$name][2]];
    }

    /** "+ Add" on Director 1–3: the new party gets role DIRECTOR (R-F_SETUP-38). */
    public static function addPreset(string $formKey, string $name): ?array
    {
        if ($formKey !== self::FORM || ! in_array($name, self::DIRECTORS, true)) return null;
        $role = DB::table('cat_account_types')->whereRaw('UPPER(role_code) = ?', [self::ADD_ROLE])->where('f_active', 1)
            ->where(fn ($w) => $w->where('fk_id_ent', Context::ent())->orWhere('fk_id_ent', 0)->orWhereNull('fk_id_ent'))->orderByDesc('fk_id_ent')->value('id');
        return $role ? ['roles' => (string) $role] : null;
    }

    /** On save: each of the four must be Director 1's own active item; without Director 1 they are emptied. */
    public static function check(array &$data, array $old): array
    {
        $person = (int) (array_key_exists(self::PERSON, $data) ? $data[self::PERSON] : ($old[self::PERSON] ?? 0));
        $e = [];
        foreach (self::FIELDS as $c => [$kind, $t]) {
            $v = array_key_exists($c, $data) ? $data[$c] : ($old[$c] ?? null);
            if ($v === null || $v === '' || (string) $v === '0') continue;
            if (! $person) { $data[$c] = null; continue; }
            if (! self::active(DB::table($t)->where('id', (int) $v)->where('fk_id_account', $person), $t)->exists())
                $e[$c] = 'This ' . ['addr' => 'address', 'email' => 'e-mail', 'mobile' => 'mobile'][$kind] . ' is not one of Director 1\'s. Choose it again.';
        }
        return $e;
    }
}
