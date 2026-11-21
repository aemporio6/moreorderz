<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Typed checks of one form that the generic registry cannot say: code formats, cross-field sums, live uniqueness (R-F_SETUP-35).
 * The checks marked "owner to confirm" in the rules sit behind one Choice (COMPANY_FORM_CHECKS): STRICT (all, suggested) or OLD (as the old tool: only the name).
 */
class FormRules
{
    /** form → field → [label, strict only?, clean, pattern, message] */
    private const COMPANY = [
        'company_utr' => ['strip', '/^\d{10}$/', 'UTR must be 10 digits.', true],
        'authentication_code' => ['upper_strip', '/^[A-Z0-9]{6}$/', 'Authentication code must be 6 letters or digits.', true],
        'hmrc_activation_code' => ['upper_strip', '/^[A-Z0-9]{12}$/', 'HMRC activation code must be 12 letters or digits, as on the HMRC letter.', true],
        'vat_no' => ['upper_strip', '/^GB(\d{9}|\d{12})$/', 'VAT number must be GB followed by 9 or 12 digits, for example GB123456789.', true],
        'eori_no' => ['upper_strip', '/^GB\d{12}$/', 'EORI number must be GB followed by 12 digits.', true],
        'iso_code' => ['upper_strip', '/^[A-Z0-9]{1,20}$/', 'ISO code may hold only letters and digits.', true],
        'registration_no' => ['upper_strip', '/^[A-Z0-9]{8}$/', 'Registration number must be 8 characters, for example 12345678 or SC123456.', true],
    ];
    private const COMPANY_REQUIRED = ['fk_country_id', 'iso_code', 'registration_no', 'company_house_telephone', 'company_email_1', 'company_address'];

    public static function strict(string $formKey): bool { return $formKey === 'cat_company_house' && Choices::get('COMPANY_FORM_CHECKS', Context::ent()) !== 'OLD'; }

    /** Fields this form requires on top of the registry (shown with a star). */
    public static function required(string $formKey): array { return $formKey === 'cat_company_house' && self::strict($formKey) ? self::COMPANY_REQUIRED : []; }

    /** Clean $data in place and return field → message. $old = the stored row on edit. */
    public static function check(string $formKey, array &$data, array $old, ?int $id, array $already = []): array
    {
        if ($formKey !== 'cat_company_house') return [];
        $strict = self::strict($formKey); $e = []; $val = fn (string $c) => array_key_exists($c, $data) ? $data[$c] : ($old[$c] ?? null);
        if (isset($data['company_name']) && is_string($data['company_name']) && $data['company_name'] !== '') $data['company_name'] = mb_strtoupper(mb_substr($data['company_name'], 0, 1)) . mb_substr($data['company_name'], 1);
        foreach (self::COMPANY as $c => [$clean, $re, $msg, $strictOnly]) {
            if (! array_key_exists($c, $data) || ! is_string($data[$c]) || $data[$c] === '' || Vault::isMasked($data[$c]) || $data[$c] === '-') continue;
            $v = preg_replace('/\s+/', '', $data[$c]); if ($clean === 'upper_strip') $v = mb_strtoupper($v); $data[$c] = $v;      // typed codes: spaces out, upper case (never a generated number)
            if (($strict || ! $strictOnly) && ! preg_match($re, $v)) $e[$c] = $msg;
        }
        $sum = 0.0;
        foreach ([1, 2, 3] as $n) { $c = "director_{$n}_share"; $v = $val($c); if ($v === null || $v === '') continue; $s = str_replace(['%', ' '], '', (string) $v);
            if (! is_numeric($s) || (float) $s < 0 || (float) $s > 100) { $e[$c] = "Director $n share must be a number from 0 to 100."; continue; }
            $s = number_format(round((float) $s, 2), 2, '.', ''); if (array_key_exists($c, $data)) $data[$c] = rtrim(rtrim($s, '0'), '.'); $sum += (float) $s; }
        if ($strict && round($sum, 2) > 100 && ! array_intersect(['director_1_share', 'director_2_share', 'director_3_share'], array_keys($e))) $e['director_1_share'] = 'Director shares add up to ' . rtrim(rtrim(number_format($sum, 2, '.', ''), '0'), '.') . ' % – must not exceed 100 %.';
        if ($strict) foreach (self::COMPANY_REQUIRED as $c) { $v = $val($c); if (($v === null || $v === '') && ! isset($already[$c]) && ! isset($e[$c])) $e[$c] = (Registry::labelOf('cat_company_house', $c) ?? $c) . ' is required.'; }
        $iso = $val('iso_code'); if ($strict && is_string($iso) && $iso !== '' && ! isset($e['iso_code']) && self::isoTaken($iso, $id)) $e['iso_code'] = 'This ISO code already exists. Please add another';
        return $e;
    }

    public static function isoTaken(string $iso, ?int $id = null): bool
    {
        return DB::table('cat_company_house')->where('fk_id_ent', Context::ent())->whereRaw('UPPER(iso_code) = ?', [mb_strtoupper(preg_replace('/\s+/', '', $iso))])->when($id, fn ($q) => $q->where('id', '<>', $id))->exists();
    }

    /** Fields checked live while the person types. */
    public static function liveFields(string $formKey): array { return $formKey === 'cat_company_house' ? array_keys(self::COMPANY) : []; }

    /** Live check while typing (the old screen's message). */
    public static function live(string $formKey, string $field, string $value, ?int $id): array
    {
        if ($formKey !== 'cat_company_house') return ['ok' => true];
        if ($field === 'iso_code' && self::strict($formKey)) { $taken = trim($value) !== '' && self::isoTaken($value, $id); return ['ok' => ! $taken, 'message' => $taken ? 'This ISO code already exists. Please add another' : null]; }
        $d = [$field => $value]; $e = isset(self::COMPANY[$field]) ? self::check($formKey, $d, [], $id, array_fill_keys(self::COMPANY_REQUIRED, 1)) : [];
        return ['ok' => ! isset($e[$field]), 'message' => $e[$field] ?? null, 'value' => $d[$field] ?? $value];
    }
}
