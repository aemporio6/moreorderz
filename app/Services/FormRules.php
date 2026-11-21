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
        if (Registry::table($formKey) === 't_account' && ($old['record_status'] ?? null) === 'DRAFT') $data['record_status'] = 'ACTIVE';      // the final Save of a draft person (R-C-20 / R-C-9)
        $code = Registry::table($formKey) === 't_account' ? AccountCode::check($formKey, $data, $old, $id) : [];      // R-C-4 one account-code rule
        return $code + OwnItems::check($formKey, $data, $old, $id) + self::checkForm($formKey, $data, $old, $id, $already);      // R-C-20: an own item must be the person's own
    }

    private static function checkForm(string $formKey, array &$data, array $old, ?int $id, array $already = []): array
    {
        $table = Registry::table($formKey);
        if ($table === 't_src_cartons') return self::cartons($data, $old);
        if (in_array($table, ['t_src_products', 't_product_vendors'], true)) return self::productCodes($data, $old);
        if ($table === 'cat_ip_burgers') return self::isp($data);
        if ($table === 't_account_job_history') return self::jobHistory($data, $old, $id);
        if ($table === 't_src_cycles' || $table === 't_src_po') return self::srcDuplicate($table, $data, $old, $id);
        if ($table === 't_src_po_lines') return self::poLine($data, $old);
        if ($table === 't_product_marketplace_shipping') return self::mpFees($data, $old);
        if ($table === 't_account_branches') return ShopAssignments::check($data, $old, $id);      // R-C-41
        if ($table === 'cat_marketplace_fields') return MarketplaceFields::prepare($data, $old, $id);      // R-A_SALES-67: slug name fixed after the first save, options → JSON
        if (isset(SecurityQuestions::KIT[$table]) && ($e = SecurityQuestions::check($table, $data, $old))) return $e;      // R-C-22
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

    /**
     * Duplicate guard supplier + order reference (R-B_SOURCING-7) on the cycle form and the PO form.
     * Checked when the reference, the supplier or the cycle changes; "Save anyway" sends dup_reason, kept in history as DUPLICATE_FORCED.
     */
    private static function srcDuplicate(string $table, array &$data, array $old, ?int $id): array
    {
        $ent = (int) Context::ent();
        if ($table === 't_src_cycles') {
            if (array_key_exists('order_ref', $data) && $data['order_ref'] !== null) $data['order_ref'] = SrcDuplicate::clean($data['order_ref']) ?: null;
            if (! array_key_exists('order_ref', $data) || (string) $data['order_ref'] === SrcDuplicate::clean($old['order_ref'] ?? '')) return [];
            $x = SrcDuplicate::forCycle($ent, $id, $data['order_ref']); $cycle = $id;
        } else {
            $sup = (int) ($data['fk_id_supplier'] ?? $old['fk_id_supplier'] ?? 0); $cycle = (int) ($data['fk_id_cycle'] ?? $old['fk_id_cycle'] ?? 0) ?: null;
            if ($id && $sup === (int) ($old['fk_id_supplier'] ?? 0) && $cycle === ((int) ($old['fk_id_cycle'] ?? 0) ?: null)) return [];
            $x = $cycle ? SrcDuplicate::existing($ent, $sup, DB::table('t_src_cycles')->where('id', $cycle)->value('order_ref'), $cycle) : null;
        }
        try { if (SrcDuplicate::guard($x, request()->input('dup_reason')) && $cycle) SrcDuplicate::forced($cycle, $x, (string) request()->input('dup_reason'), $ent); }
        catch (SrcDuplicateException $e) { return ['order_ref' => $e->getMessage(), '_dup' => $x->cycle_code . '|' . $x->id] + ($table === 't_src_po' ? ['fk_id_supplier' => $e->getMessage()] : []); }
        return [];
    }

    /** 3PL fees per marketplace row (R-D-23): ≥ 0 at 2 dp; a new row with no fee typed starts from the company 3PL tariff. */
    private static function mpFees(array &$data, array $old): array
    {
        $typed = false;
        foreach (['packing_fee', 'storage_fee'] as $c) if (array_key_exists($c, $data) && $data[$c] !== null && $data[$c] !== '') { $data[$c] = ThreePlTariff::money($data[$c]); $typed = true; }
        if (! $old && ! empty($data['fk_id_marketplace'])) {
            $t = ThreePlTariff::defaults((int) Context::ent(), (int) $data['fk_id_marketplace']);
            foreach (['packing_fee', 'storage_fee'] as $c) if (! isset($data[$c]) || $data[$c] === null) $data[$c] = $t[$c];
            $data['fee_source'] = $typed ? 'TYPED' : ($t['from_tariff'] ? 'TARIFF' : null);
        } elseif ($typed) $data['fee_source'] = 'TYPED';
        return [];
    }

    /** PO line types (R-B_SOURCING-6): empty → PRODUCT, unknown → PRODUCT, a PRODUCT line needs a product, a FEE / COST / SAMPLE line a name; amount from qty × price or the lump sum. */
    private static function poLine(array &$data, array $old): array
    {
        $v = fn (string $c) => array_key_exists($c, $data) ? $data[$c] : ($old[$c] ?? null);
        $log = []; $type = PoLines::type($v('line_type'), (string) ($v('line_notes') ?: $v('sku')), $log);
        if (array_key_exists('line_type', $data) || ! $old) $data['line_type'] = $type;
        if ($type === 'PRODUCT' && ! $v('fk_id_product')) return ['fk_id_product' => 'A PRODUCT line needs a product from the product master.'];
        if ($type === 'PRODUCT' && (float) $v('qty_ordered') <= 0) return ['qty_ordered' => 'A product line needs a quantity above zero.'];
        if ($type !== 'PRODUCT' && ! $v('fk_id_product') && trim((string) $v('line_notes')) === '') return ['line_notes' => "Say what the $type amount is for (e.g. \"Agent fee\", \"Inspection\")."];
        if ($v('unit_price') !== null && $v('unit_price') !== '') $data['line_total'] = round(PoLines::amount($v('qty_ordered'), $v('unit_price')) + (float) $v('other_charges'), 2);
        return [];
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

    /**
     * Job history row (R-C-39): marketplace + start date required (old messages, in the old order); shop of that marketplace; end ≥ start; share 0–100 in steps of 0.5;
     * £ rate > 0 (empty → the latest rate of the salary currency); salary ≥ 0; salary currency defaults to the person's country currency, GBP last; no overlap with another
     * row of the same person, marketplace and shop ("Overlaps the row from {date}").
     */
    private static function jobHistory(array &$data, array $old, ?int $id): array
    {
        $e = []; $v = fn (string $c) => array_key_exists($c, $data) ? $data[$c] : ($old[$c] ?? null);
        if (! $v('fk_id_marketplace')) $e['fk_id_marketplace'] = 'Select Marketplace.';
        if (! $v('d_job_start_date')) $e['d_job_start_date'] = 'Start Date is required.';
        if ($e) return $e;
        $shop = $v('fk_id_branch'); if ($shop && (int) DB::table('t_branches')->where('id', $shop)->value('fk_id_marketplace') !== (int) $v('fk_id_marketplace')) $e['fk_id_branch'] = 'This shop belongs to another marketplace.';
        $start = (string) $v('d_job_start_date'); $end = $v('d_end_date'); if ($end && $end < $start) $e['d_end_date'] = 'End date must be on or after the start date.';
        $sh = $v('share_percentage'); if ($sh !== null && $sh !== '') { if (! is_numeric($sh) || (float) $sh < 0 || (float) $sh > 100) $e['share_percentage'] = 'Share % must be a number from 0 to 100.'; elseif (fmod((float) $sh * 2, 1.0) != 0.0) $e['share_percentage'] = 'Share % goes in steps of 0.5 (for example 12.5).'; }
        if (array_key_exists('salary', $data) && $data['salary'] !== null && $data['salary'] !== '' && (! is_numeric($data['salary']) || (float) $data['salary'] < 0)) $e['salary'] = 'Salary must be an amount of 0 or more.';
        if (! $v('fk_id_currency') && ($acc = $v('fk_id_account'))) {      // salary currency: the currency of the person's current country, else GBP
            $cur = DB::table('t_account as a')->join('cat_country as c', 'c.id', '=', 'a.current_nationality')->where('a.id', $acc)->value('c.fk_id_default_currency');
            $data['fk_id_currency'] = (int) ($cur ?: DB::table('cat_currency')->where('currency_code', 'GBP')->value('id')) ?: null;
        }
        $rate = $v('pound_exchange_rate');
        if ($rate !== null && $rate !== '' && (! is_numeric($rate) || (float) $rate <= 0)) $e['pound_exchange_rate'] = '£ rate must be a number above 0.';
        elseif (($rate === null || $rate === '') && ($c = $data['fk_id_currency'] ?? $v('fk_id_currency'))) { $code = DB::table('cat_currency')->where('id', $c)->value('currency_code');
            if ($code && $code !== Fx::base((int) Context::ent())) { try { $r = Fx::rate($code, date('Y-m-d'), (int) Context::ent()); if ($r > 0) $data['pound_exchange_rate'] = round($r, 6); } catch (\Throwable $x) { } } }      // the latest rate; a typed one is kept (and logged by the history)
        if (! $e && ($acc = $v('fk_id_account'))) {
            $clash = DB::table('t_account_job_history')->where('fk_id_account', $acc)->where('fk_id_marketplace', $v('fk_id_marketplace'))->where('f_active', 1)->when($id, fn ($q) => $q->where('id', '<>', $id))
                ->where(fn ($q) => $shop ? $q->where('fk_id_branch', $shop)->orWhereNull('fk_id_branch') : $q)->where(fn ($q) => $q->whereNull('d_end_date')->orWhere('d_end_date', '>=', $start))
                ->when($end, fn ($q) => $q->where('d_job_start_date', '<=', $end))->orderBy('d_job_start_date')->first(['d_job_start_date']);
            if ($clash) $e['d_job_start_date'] = 'Overlaps the row from ' . date('d-m-Y', strtotime($clash->d_job_start_date)) . '.';
        }
        return $e;
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
    public static function liveFields(string $formKey): array { $t = Registry::table($formKey); if ($t === 't_account') return ['account_code']; return $formKey === 'cat_company_house' ? array_keys(self::COMPANY) : (in_array($t, ['t_src_products', 't_product_vendors'], true) ? ['hs_code'] : ($t === 'cat_ip_burgers' ? ['isp_code'] : ($t === 't_src_cycles' ? ['order_ref'] : []))); }

    /** Live check while typing (the old screen's message). */
    public static function live(string $formKey, string $field, string $value, ?int $id): array
    {
        if (in_array($field, ['hs_code', 'isp_code'], true)) { $d = [$field => $value]; $e = $field === 'hs_code' ? self::productCodes($d, []) : self::isp($d); return ['ok' => ! $e, 'message' => $e[$field] ?? null, 'value' => $d[$field] ?? $value]; }
        if ($field === 'account_code' && Registry::table($formKey) === 't_account') { $d = ['account_code' => $value]; $e = AccountCode::check($formKey, $d, [], $id); return ['ok' => ! $e, 'message' => $e['account_code'] ?? null, 'value' => $d['account_code'] ?? $value]; }      // R-C-4 in the browser
        if ($field === 'order_ref' && Registry::table($formKey) === 't_src_cycles') return SrcDuplicate::live((int) Context::ent(), null, $value, $id) + ['value' => SrcDuplicate::clean($value)];
        if ($formKey !== 'cat_company_house') return ['ok' => true];
        if ($field === 'iso_code' && self::strict($formKey)) { $taken = trim($value) !== '' && self::isoTaken($value, $id); return ['ok' => ! $taken, 'message' => $taken ? 'This ISO code already exists. Please add another' : null]; }
        $d = [$field => $value]; $e = isset(self::COMPANY[$field]) ? self::check($formKey, $d, [], $id, array_fill_keys(self::COMPANY_REQUIRED, 1)) : [];
        return ['ok' => ! isset($e[$field]), 'message' => $e[$field] ?? null, 'value' => $d[$field] ?? $value];
    }
}
