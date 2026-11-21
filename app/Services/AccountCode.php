<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Account code: one numbering + format rule (R-C-4). Every person: upper case, ^[A-Z0-9][A-Z0-9-]{0,9}$ (max 10), unique per enterprise; checked in the
 * browser (the default is the suggestion) and on the server (FormRules). Suggestion: MAX(CAST(code AS UNSIGNED)) + 1 over the enterprise's codes
 * ("214-EX" counts as 214; none → 1); external series PERSON-EX adds "-" + suffix (setting ACCOUNT_CODE_EXTERNAL_SUFFIX, default EX), cut to 10;
 * up to 500 numbers are tried until one is unused (none → empty suggestion). Managed series PERSON = the same without the suffix.
 */
class AccountCode
{
    public const RE = '/^[A-Z0-9][A-Z0-9-]{0,9}$/';
    public const EXTERNAL_FORMS = ['external_contact'];

    private static function used(int $ent, string $code, ?int $exceptId = null): bool
    {
        return DB::table('t_account')->where('fk_id_ent', $ent)->whereRaw('UPPER(account_code) = ?', [strtoupper($code)])->when($exceptId, fn ($q) => $q->where('id', '<>', $exceptId))->exists();
    }

    public static function suggest(int $ent, bool $external = false): string
    {
        $n = (int) DB::table('t_account')->where('fk_id_ent', $ent)->whereNotNull('account_code')->where('account_code', '<>', '')->max(DB::raw('CAST(account_code AS UNSIGNED)')) + 1;
        $suffix = $external ? '-' . strtoupper((string) (Settings::get('ACCOUNT_CODE_EXTERNAL_SUFFIX', $ent) ?: 'EX')) : '';
        for ($i = 0; $i < 500; $i++, $n++) { $c = substr($n . $suffix, 0, 10); if (! self::used($ent, $c)) return $c; }
        return '';
    }

    /** Server check for the person forms. $data is changed in place (upper case). Returns errors. */
    public static function check(string $formKey, array &$data, array $old, ?int $id): array
    {
        if (! array_key_exists('account_code', $data) && $id) return [];
        $ent = (int) ($data['fk_id_ent'] ?? $old['fk_id_ent'] ?? Context::ent());
        $external = in_array($formKey, self::EXTERNAL_FORMS, true);
        $code = strtoupper(trim((string) ($data['account_code'] ?? '')));
        if ($code === '') { if (! $external && ! $id) return ['account_code' => 'Account Code is required.']; $data['account_code'] = self::suggest($ent, $external); return []; }
        $data['account_code'] = $code;
        if (! preg_match(self::RE, $code)) return ['account_code' => 'Account Code: up to 10 letters, numbers or "-" (for example 214-EX).'];
        if (self::used($ent, $code, $id)) return ['account_code' => "Account Code $code is already used. Suggested: " . self::suggest($ent, $external)];
        return [];
    }
}
