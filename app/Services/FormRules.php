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
        $table = Registry::table($formKey);
        if ($table === 't_src_cartons') return self::cartons($data, $old);
        if (in_array($table, ['t_src_products', 't_product_vendors'], true)) return self::productCodes($data, $old);
        if ($table === 'cat_ip_burgers') return self::isp($data);
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

    /** Carton figures (R-B_SOURCING-34): every calculated column is worked out on save from the typed ones – never typed. */
    private static function cartons(array &$data, array $old): array
    {
        $e = []; $v = $data + $old;
        foreach (['unit_l', 'unit_w', 'unit_h', 'unit_net_weight', 'unit_gross_weight', 'units_per_carton', 'carton_l', 'carton_w', 'carton_h', 'carton_tare_weight', 'actual_carton_gross_weight', 'total_planned_units'] as $c)
            if (isset($data[$c]) && $data[$c] !== '' && $data[$c] !== null && (! is_numeric($data[$c]) || (float) $data[$c] < 0)) $e[$c] = (Registry::labelOf('t_src_cartons', $c) ?? $c) . ' must be a number of 0 or more.';
        if ($e) return $e;
        $f = CartonCalc::calc($v, CartonCalc::divisorForRow((int) Context::ent(), $v));
        foreach (['unit_cbm', 'volumetric_weight', 'carton_net_weight', 'carton_gross_weight', 'full_cartons', 'loose_units', 'carton_qty', 'total_units', 'total_net_weight', 'total_gross_weight', 'carton_cbm', 'total_cbm'] as $c) if (Registry::hasColumn('t_src_cartons', $c)) $data[$c] = $f[$c];
        return [];
    }

    public static function typedStrict(): bool { return Choices::get('TYPED_CODE_CHECKS', Context::ent()) !== 'OLD'; }

    /** HS code typed (digits only, 6–10, R-G_CORE-91) and carton CBM worked out (L × W × H ÷ 1,000,000, 4 dp). */
    private static function productCodes(array &$data, array $old): array
    {
        $e = [];
        if (isset($data['hs_code']) && is_string($data['hs_code']) && trim($data['hs_code']) !== '') { $hs = preg_replace('/[\s.\-]+/', '', $data['hs_code']); $data['hs_code'] = $hs;
            if (self::typedStrict() && ! preg_match('/^\d{6,10}$/', $hs)) $e['hs_code'] = 'HS code must be 6 to 10 digits (dots and spaces are removed), for example 3924.90.00.'; }
        $v = $data + $old; if (array_key_exists('carton_cbm', $old) || array_key_exists('carton_l', $data) || array_key_exists('carton_w', $data) || array_key_exists('carton_h', $data))
            if (isset($v['carton_l'], $v['carton_w'], $v['carton_h']) && is_numeric($v['carton_l']) && is_numeric($v['carton_w']) && is_numeric($v['carton_h'])) $data['carton_cbm'] = round((float) $v['carton_l'] * (float) $v['carton_w'] * (float) $v['carton_h'] / 1000000, 4);
        return $e;
    }

    /** ISP code: typed (IPBurger ISP code), letters / digits, up to 20 (R-G_CORE-91). */
    private static function isp(array &$data): array
    {
        if (! isset($data['isp_code']) || ! is_string($data['isp_code']) || trim($data['isp_code']) === '') return [];
        $data['isp_code'] = strtoupper(preg_replace('/\s+/', '', $data['isp_code']));
        return self::typedStrict() && ! preg_match('/^[A-Z0-9]{1,20}$/', $data['isp_code']) ? ['isp_code' => 'ISP code may hold only letters and digits, up to 20.'] : [];
    }

    /** Live figures of a form while it is typed (the carton form). */
    public static function calc(string $formKey, array $values): array
    {
        $t = Registry::table($formKey);
        if ($t === 't_src_cartons') { $f = CartonCalc::calc($values, CartonCalc::divisorForRow((int) Context::ent(), $values)); unset($f['gross_used']); return $f; }
        if (in_array($t, ['t_src_products', 't_product_vendors'], true) && is_numeric($values['carton_l'] ?? null) && is_numeric($values['carton_w'] ?? null) && is_numeric($values['carton_h'] ?? null)) return ['carton_cbm' => round((float) $values['carton_l'] * (float) $values['carton_w'] * (float) $values['carton_h'] / 1000000, 4)];
        return [];
    }

    public static function calcFields(string $formKey): array
    {
        $t = Registry::table($formKey);
        return match (true) { $t === 't_src_cartons' => ['unit_l', 'unit_w', 'unit_h', 'unit_net_weight', 'unit_gross_weight', 'units_per_carton', 'carton_l', 'carton_w', 'carton_h', 'carton_tare_weight', 'actual_carton_gross_weight', 'total_planned_units'], in_array($t, ['t_src_products', 't_product_vendors'], true) => ['carton_l', 'carton_w', 'carton_h'], default => [] };
    }

    public static function isoTaken(string $iso, ?int $id = null): bool
    {
        return DB::table('cat_company_house')->where('fk_id_ent', Context::ent())->whereRaw('UPPER(iso_code) = ?', [mb_strtoupper(preg_replace('/\s+/', '', $iso))])->when($id, fn ($q) => $q->where('id', '<>', $id))->exists();
    }

    /** Fields checked live while the person types. */
    public static function liveFields(string $formKey): array { $t = Registry::table($formKey); return $formKey === 'cat_company_house' ? array_keys(self::COMPANY) : (in_array($t, ['t_src_products', 't_product_vendors'], true) ? ['hs_code'] : ($t === 'cat_ip_burgers' ? ['isp_code'] : [])); }

    /** Live check while typing (the old screen's message). */
    public static function live(string $formKey, string $field, string $value, ?int $id): array
    {
        if (in_array($field, ['hs_code', 'isp_code'], true)) { $d = [$field => $value]; $e = $field === 'hs_code' ? self::productCodes($d, []) : self::isp($d); return ['ok' => ! $e, 'message' => $e[$field] ?? null, 'value' => $d[$field] ?? $value]; }
        if ($formKey !== 'cat_company_house') return ['ok' => true];
        if ($field === 'iso_code' && self::strict($formKey)) { $taken = trim($value) !== '' && self::isoTaken($value, $id); return ['ok' => ! $taken, 'message' => $taken ? 'This ISO code already exists. Please add another' : null]; }
        $d = [$field => $value]; $e = isset(self::COMPANY[$field]) ? self::check($formKey, $d, [], $id, array_fill_keys(self::COMPANY_REQUIRED, 1)) : [];
        return ['ok' => ! isset($e[$field]), 'message' => $e[$field] ?? null, 'value' => $d[$field] ?? $value];
    }
}
