<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-C-12 derived defaults when a person's rows are saved – the same on the quick form (ExternalContact::create) and on the Full forms.
 * The rules live in the registry (default_rule):  rule:firstOwn:<table>  ·  rule:businessIfCompany  ·  rule:linkedCompany  ·  rule:personCountry  ·  rule:personName  ·  copy:<field>
 * - firstOwn: the person's first active row of that table (first address, first mobile, first e-mail);
 * - businessIfCompany: Business if a company is linked to the person, else Personal;
 * - linkedCompany: the person's linked company; personCountry: the current country; personName: first name + surname.
 * A default is only a start value: it is filled when the field is empty, shown with its reason as a chip, and can be changed.
 */
class PartyDefaults
{
    public static function value(string $rule, int $person): mixed
    {
        if (! $person) return null;
        [$k, $a] = array_pad(explode(':', preg_replace('/^rule:/', '', $rule), 2), 2, '');
        return match ($k) {
            'firstOwn' => in_array($a, ['t_account_addresses', 't_account_mobiles', 't_account_emails'], true) ? (DB::table($a)->where('fk_id_account', $person)->where('f_active', 1)->orderBy('id')->value('id') ?: null) : null,
            'linkedCompany' => ((int) DB::table('t_account_company_houses')->where('fk_id_account', $person)->where('f_active', 1)->orderBy('id')->value('id')) ?: null,      /* the person's own company link – what the Full form's picker stores */
            'businessIfCompany' => self::company($person) ? 'Business' : 'Personal',
            'personCountry' => DB::table('t_account')->where('id', $person)->value('current_nationality') ?: null,
            'personName' => trim(implode(' ', array_filter((array) DB::table('t_account')->where('id', $person)->first(['first_name', 'sur_name'])))) ?: null,
            default => null,
        };
    }

    public static function company(int $person): int { return (int) DB::table('t_account_company_houses')->where('fk_id_account', $person)->where('f_active', 1)->orderBy('id')->value('fk_company_house_id'); }

    public static function why(string $rule): string
    {
        return match (true) { str_contains($rule, 'firstOwn:t_account_addresses') => "the person's first address", str_contains($rule, 'firstOwn:t_account_mobiles') => "the person's first mobile", str_contains($rule, 'firstOwn:t_account_emails') => "the person's first e-mail",
            str_contains($rule, 'businessIfCompany') => 'Business when a company is linked, else Personal', str_contains($rule, 'linkedCompany') => "the person's linked company", str_contains($rule, 'personCountry') => "the person's current country", default => "the person's name" };
    }

    private static function mine(array $f): bool { $r = (string) $f['default_rule']; return str_starts_with($r, 'rule:') && preg_match('/^rule:(firstOwn|linkedCompany|businessIfCompany|personCountry|personName)/', $r) === 1; }

    /** RecordService hook: a new row of a person takes its derived defaults where the field was left empty; a new person's old country = current country (copy:). */
    public static function input(string $formKey, string $table, array $input): array
    {
        $fields = array_filter(Registry::fields($formKey), fn ($f) => $f['column_name'] && Registry::baseTable((string) $f['table_name']) === $table);
        $get = fn ($f) => $input[$f['field_name']] ?? $input[$f['column_name']] ?? null; $empty = fn ($v) => $v === null || $v === '' || (string) $v === '0' || $v === [];
        $person = 0; foreach ($fields as $f) if ($f['column_name'] === 'fk_id_account') $person = (int) $get($f);
        foreach ($fields as $f) {
            if (! $empty($get($f))) continue;
            $r = (string) $f['default_rule'];
            if (str_starts_with($r, 'copy:')) { foreach ($fields as $src) if ($src['field_name'] === substr($r, 5) && ! $empty($get($src))) $input[$f['field_name']] = $get($src); continue; }
            if (self::mine($f) && ($v = self::value($r, $person)) !== null) $input[$f['field_name']] = $v;
        }
        return $input;
    }

    /** FormAssist hook: the same value as a chip on the Full form ("default" with its reason). */
    public static function chip(array $f, array $form): ?array
    {
        if (! self::mine($f)) return null;
        $v = self::value((string) $f['default_rule'], (int) ($form['fk_id_account'] ?? $form['account'] ?? 0));
        return $v === null ? null : [(string) $v, 'default: ' . self::why((string) $f['default_rule'])];
    }
}
