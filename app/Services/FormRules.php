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
    /** R-C-26 a SIM: 1 ≤ slot ≤ the device's slots and not used by another active SIM of the device; SMS forward target is not the mobile itself; required office/home, person, country, mobile no, network, SIM no, capacity. */
    public static function mobile(array $data, array $old, ?int $id): array
    {
        $v = fn ($k) => array_key_exists($k, $data) ? $data[$k] : ($old[$k] ?? null); $e = [];
        foreach (['l_office_home' => 'Office / home', 'fk_id_account' => 'Person', 'fk_id_country' => 'Country', 'mobile_no' => 'Mobile no', 'fk_id_mobile_network' => 'Network', 'simno' => 'SIM no', 'mobile_capacity' => 'Capacity'] as $k => $l)
            if (trim((string) $v($k)) === '') $e[$k] = "$l is required";
        if ($e) return $e;
        $dev = (int) $v('fk_id_mobile_device'); $slot = (int) $v('slotno');
        if ($dev && $slot) {
            $d = DB::table('t_mobile_devices')->where('id', $dev)->first(['mobile_name', 'tot_slots']);
            if ($d && ($slot < 1 || $slot > (int) $d->tot_slots)) return ['slotno' => "{$d->mobile_name} has {$d->tot_slots} slot" . ((int) $d->tot_slots === 1 ? '' : 's') . '.'];
            $other = DB::table('t_account_mobiles')->where('fk_id_mobile_device', $dev)->where('slotno', $slot)->where(fn ($w) => $w->whereNull('f_active')->orWhere('f_active', 1))->when($id, fn ($q) => $q->where('id', '<>', $id))->value('mobile_no');
            if ($other !== null && (int) ($v('f_active') ?? 1)) return ['slotno' => "Slot $slot of " . ($d->mobile_name ?? 'this device') . " is already used by $other"];
        }
        if ($id && (int) $v('sms_forward_to_mobile') === $id) return ['sms_forward_to_mobile' => 'SMS cannot be forwarded to the same mobile.'];
        if ($id && (array_key_exists('mobile_no', $data) && (string) $data['mobile_no'] !== (string) ($old['mobile_no'] ?? '') || array_key_exists('f_active', $data) && ! (int) $data['f_active'] && (int) ($old['f_active'] ?? 1))) {      /* R-C-48 the WhatsApp number follows its mobile */
            $n = PersonChannels::intl((string) $v('mobile_no'));
            if ((int) ($v('f_active') ?? 1) && ($who = DB::table('t_account')->where('fk_id_whatsapp_mobile', $id)->exists() ? PersonChannels::waOwner($n, (int) $v('fk_id_account')) : null)) return ['mobile_no' => "This number is already the WhatsApp number of $who."];
            PersonChannels::mobileChanged($id, (string) $v('mobile_no'), (bool) (int) ($v('f_active') ?? 1));
        }
        return [];
    }

    public static function check(string $formKey, array &$data, array $old, ?int $id, array $already = []): array
    {
        if (Registry::table($formKey) === 't_account' && ($old['record_status'] ?? null) === 'DRAFT') $data['record_status'] = 'ACTIVE';      // the final Save of a draft person (R-C-20 / R-C-9)
        $code = Registry::table($formKey) === 't_account' ? AccountCode::check($formKey, $data, $old, $id) : [];      // R-C-4 one account-code rule
        $inherit = SourcingInherit::check((string) Registry::table($formKey), $data, $old);      // R-B_SOURCING-21 supplier / SKU of the cycle steps
        $def = [];      // R-D-68: the default item of a list is never switched off
        if ($id && array_key_exists('f_active', $data) && ! (int) $data['f_active'] && (int) ($old['f_active'] ?? 1) && ($t = Registry::table($formKey)) && ($m = ListDefaults::protect($t, $id, (string) ($old[Registry::labelColumn($t) ?: 'id'] ?? $id)))) $def = ['f_active' => str_replace('cannot be deleted', 'cannot be switched off', $m)];
        $ch = Registry::table($formKey) === 't_account' ? PersonChannels::check($data, $old, $id) : [];      // R-G_CORE-48 channels per person
        $ch += in_array(Registry::table($formKey), ['cat_tags', 'cat_brands'], true) ? self::tagBrand((string) Registry::table($formKey), $data, $old, $id) : [];      // R-D-69 tags and brands
        $pr = ($t = (string) Registry::table($formKey)) && PersonRules::applies($t) ? PersonRules::check($formKey, $t, $data, $old, $id) : [];      // R-C-5 / 6 / 7 / 24 / 25 / 27 / 29 / 33 / 34 / 35, D7
        return $def + $code + $inherit + $ch + $pr + OwnItems::check($formKey, $data, $old, $id) + self::checkForm($formKey, $data, $old, $id, $already);      // R-C-20: an own item must be the person's own
    }

    /**
     * R-B_SOURCING-43 manual landed-cost line (a cycle without the data): total = Σ the ten buckets, per unit = accepted > 0 ? total ÷ accepted (4 dp) : 0,
     * cost source MANUAL. A line made by a landed-cost run is changed by a new run or an override, never by hand.
     */
    /** R-D-69: tag name trimmed + Title Case, unique (trimmed, any case) "Tag name already exist", colour required; brand name trimmed + first letter upper, unique per linked company. */
    private static function tagBrand(string $table, array &$data, array $old, ?int $id): array
    {
        $ent = (int) (Context::ent() ?? 0); $e = [];
        if ($table === 'cat_tags') {
            if (array_key_exists('tag_name', $data) || ! $id) { $n = ucwords(preg_replace('/\s+/u', ' ', trim((string) ($data['tag_name'] ?? '')))); $data['tag_name'] = $n;
                if ($n === '') $e['tag_name'] = 'Tag name is required.';
                elseif (DB::table('cat_tags')->whereRaw('LOWER(TRIM(tag_name)) = ?', [mb_strtolower($n)])->where(fn ($w) => $w->whereIn('fk_id_ent', [0, $ent])->orWhereNull('fk_id_ent'))->when($id, fn ($q) => $q->where('id', '<>', $id))->exists()) $e['tag_name'] = 'Tag name already exist'; }
            if ((array_key_exists('tag_color', $data) || ! $id) && trim((string) ($data['tag_color'] ?? '')) === '') $e['tag_color'] = 'Tag colour is required – pick it.';
        }
        if ($table === 'cat_brands' && (array_key_exists('brand_name', $data) || ! $id)) {
            $n = preg_replace('/\s+/u', ' ', trim((string) ($data['brand_name'] ?? ''))); $n = $n === '' ? '' : mb_strtoupper(mb_substr($n, 0, 1)) . mb_substr($n, 1); $data['brand_name'] = $n;
            $co = (int) ($data['fk_id_company_house'] ?? $old['fk_id_company_house'] ?? 0);
            if ($n !== '' && $co && DB::table('cat_brands')->where('fk_id_company_house', $co)->whereRaw('LOWER(TRIM(brand_name)) = ?', [mb_strtolower($n)])->when($id, fn ($q) => $q->where('id', '<>', $id))->exists()) $e['brand_name'] = "Brand \"$n\" already exists for this company.";
        }
        return $e;
    }

    private static function landedManual(array &$data, array $old, ?int $id): array
    {
        if ($id && ($old['landed_status'] ?? 'MANUAL') !== 'MANUAL') return ['_form' => 'This line was made by a landed-cost run – change it with a new run or an override.'];
        $v = fn (string $c) => (float) (array_key_exists($c, $data) ? $data[$c] : ($old[$c] ?? 0));
        $b = []; foreach (array_keys(LandedEngine::BUCKETS) as $c) $b[$c] = $v($c);
        foreach ($b as $c => $x) if ($x < 0) return [$c => 'Costs cannot be negative.'];
        [$total, $unit] = LandedEngine::bucketTotal($b, $v('accepted_qty'));
        $data['total_cost'] = $total; $data['landed_cost_per_unit'] = $unit; $data['landed_status'] = 'MANUAL';
        return [];
    }

    private static function checkForm(string $formKey, array &$data, array $old, ?int $id, array $already = []): array
    {
        $table = Registry::table($formKey);
        if (isset(MasterCodes::RULES[$table]) && ($w = MasterCodes::check($table, $data, $old, $id))) return $w;      // R-F_SETUP-39 typed codes: format + unique
        if ($table === 'wa_templates' && array_key_exists('params_json', $data) && trim((string) $data['params_json']) !== '') {      // R-G_CORE-53 ordered params
            $p = json_decode((string) $data['params_json'], true);
            if (! is_array($p) || array_values($p) !== $p || array_filter($p, fn ($x) => ! is_string($x) || ! preg_match('/^\w+$/', $x))) return ['params_json' => 'Parameters must be a list of names, e.g. ["first_name","task_ref"].'];
            $data['params_json'] = json_encode(array_values($p));
        }
        if (in_array($table, ['tax_periods', 'tax_line_items', 'tax_entries', 'tax_rates'], true)) return TaxService::check($table, $data, $old, $id);      // R-TAX-4 / R-TAX-6
        if ($table === 't_src_shipments') Packaging::fillShipment($data, $old);      // owner 27-11: cartons, CBM and gross weight from the PO's packing when empty
        if ($table === 't_account_mobiles' && ($w = self::mobile($data, $old, $id))) return $w;
        if ($table === 't_account_marketplaces' && ($w = ShopKit::check($data, $old, $id))) return $w;      // R-C-30 a seller account needs its shop      // R-C-26 slot rule, SMS forward, required set
        if ($table === 'cat_inventory_locations' && ($w = WarehouseSites::check($data, $old, $id))) return $w;      // R-H-3 / 6 system bins, bins with units
        if ($table === 'user_absence') return TaskSettings::absence($data, $old, $id);      // R-G_CORE-16
        if ($table === 'tm_delegations') return TaskSettings::delegation($data, $old, $id);      // R-G_CORE-17
        if ($table === 'task_categories') return TaskSettings::category($data, $old, $id);      // R-G_CORE-23
        if ($table === 'task_templates') return TaskTemplates::checkTemplate($data, $old, $id);      // R-G_CORE-24
        if ($table === 'task_template_items') return TaskTemplates::checkItem($data, $old, $id);
        if ($table === 't_src_cartons') return self::cartons($data, $old);
        if ($table === 't_src_documents') return self::document($data, $old, $already);      // R-B_SOURCING-47 / 49
        if ($table === 'fin_categories') return self::finCategory($data, $old, $id);
        if ($table === 't_src_landed_cost') return self::landedManual($data, $old, $id);      // R-B_SOURCING-43 manual landed line      // form standard: money categories get a form
        if ($table === 't_product_vendors') return CentralInventory::check($data, $old, $id) + ProductFamily::check($data, $old, $id) + ProductStockFlag::formCheck($data, $old, $id) + SkuCheck::onSave($data, $old, $id) + self::productCodes($data, $old);      // R-D-10, R-D-3, R-D-7      // R-D-1 family coding / checks, R-D-2 exact SKU in the company
        if (in_array($table, ['t_src_products', 't_product_vendors'], true)) return self::productCodes($data, $old);
        if ($table === 't_src_cycles' && $id && array_key_exists('status', $data)) {      /* R-F_SETUP-24: a cycle is not Completed / Closed while its quantities do not balance */
            $st = (string) $data['status']; if (ctype_digit($st)) $st = (string) DB::table('reference_items')->where('id', (int) $st)->value('reference_name');
            if (in_array(strtoupper($st), ['COMPLETED', 'CLOSED'], true) && ($bad = CycleAlerts::unbalanced($id))) return ['status' => 'Quantities do not balance – see Quantity check (' . implode(', ', $bad) . ').'];
        }
        if ($table === 'cat_account_types') return RoleMaster::check($data, $old, $id);      // R-C-43 one roles master: names, fixed codes, flags
        if ($table === 'cat_ip_burgers') return self::isp($data);
        if ($table === 't_account_job_history') return self::jobHistory($data, $old, $id);
        if ($table === 't_src_cycles' || $table === 't_src_po') return self::srcDuplicate($table, $data, $old, $id);
        if ($table === 't_src_po_lines') return self::poLine($data, $old);
        if ($table === 't_product_marketplace_shipping') return self::mpFees($data, $old);
        if ($table === 't_account_branches') return ShopAssignments::check($data, $old, $id);
        if ($table === 't_branches' && ($w = FinanceArea::payoutCheck($data, $old))) return $w;      // R-FIN-5 the payout bank belongs to the shop's company      // R-C-41
        if ($table === 'fin_partner_users' && ($e = PartnerOnboarding::checkLink(isset($data['user_id']) ? (int) $data['user_id'] : (isset($old['user_id']) ? (int) $old['user_id'] : null)))) return ['user_id' => $e];      // R-FIN-3
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
        return $e + DirectorPickers::check($data, $old);      // R-F_SETUP-36: the four pickers hold Director 1's own items
    }

    /** A system money category keeps its code, direction and place in the profit (the program uses them); a code is unique, in capitals. */
    private static function finCategory(array &$data, array $old, ?int $id): array
    {
        $e = [];
        if (isset($data['code'])) { $data['code'] = trim(preg_replace('/[^A-Z0-9]+/', '_', mb_strtoupper((string) $data['code'])), '_');
            if ($data['code'] !== '' && DB::table('fin_categories')->where('code', $data['code'])->when($id, fn ($q) => $q->where('id', '<>', $id))->exists()) $e['code'] = 'This code already exists. Please add another'; }
        if (! empty($old['f_system'])) foreach (['code', 'direction', 'profit_role'] as $c) if (array_key_exists($c, $data) && (string) $data[$c] !== (string) $old[$c]) $e[$c] = 'A system category keeps its ' . str_replace('_', ' ', $c) . ' – the program uses it.';
        return $e;
    }

    /** R-B_SOURCING-47 / 49: name and Drive URL required (old alert); a URL without http(s):// is refused "Must start with https://". */
    private static function document(array &$data, array $old, array $already = []): array
    {
        if (isset($already['drive_url'])) return in_array('Google Drive URL is required', (array) $already['drive_url'], true) ? ['_form' => DocRollup::REQUIRED, 'drive_url' => 'Google Drive URL is required'] : ['drive_url' => 'Must start with https://'];      /* the PO docs modal shows the joint message, the field its own */      // the general link check took the value out already
        $v = fn (string $c) => array_key_exists($c, $data) ? trim((string) $data[$c]) : trim((string) ($old[$c] ?? ''));
        if ($v('doc_name') === '' || $v('drive_url') === '') return ['_form' => DocRollup::REQUIRED, ($v('doc_name') === '' ? 'doc_name' : 'drive_url') => DocRollup::REQUIRED];
        if (array_key_exists('drive_url', $data)) { $data['drive_url'] = trim((string) $data['drive_url']); if (! DocRollup::linkOk($data['drive_url'])) return ['drive_url' => 'Must start with https://'];
            if (preg_match('~^http://~i', $data['drive_url']) && preg_match('~(^|\.)google\.com/~i', $data['drive_url'])) $data['drive_url'] = 'https://' . substr($data['drive_url'], 7); }
        return [];
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
        if ($type === 'PRODUCT' && ! $v('fk_id_product')) return ['fk_id_product' => 'Pick the product (or + Add product) for this line.'];
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
        if ($t === 't_account_subscriptions') return ['item_kind' => PersonRules::itemKind(isset($values['fk_item_id']) ? (int) $values['fk_item_id'] : null)];      // R-C-33 the item's kind decides which picker shows
        if ($t === 't_src_cartons') { $f = CartonCalc::calc($values, CartonCalc::divisorForRow((int) Context::ent(), $values)); unset($f['gross_used']); return $f; }
        if (in_array($t, ['t_src_products', 't_product_vendors'], true) && is_numeric($values['carton_l'] ?? null) && is_numeric($values['carton_w'] ?? null) && is_numeric($values['carton_h'] ?? null)) return ['carton_cbm' => round((float) $values['carton_l'] * (float) $values['carton_w'] * (float) $values['carton_h'] / 1000000, 4)];
        return [];
    }

    public static function calcFields(string $formKey): array
    {
        $t = Registry::table($formKey);
        return match (true) { $t === 't_src_cartons' => ['unit_l', 'unit_w', 'unit_h', 'unit_net_weight', 'unit_gross_weight', 'units_per_carton', 'carton_l', 'carton_w', 'carton_h', 'carton_tare_weight', 'actual_carton_gross_weight', 'total_planned_units'], in_array($t, ['t_src_products', 't_product_vendors'], true) => ['carton_l', 'carton_w', 'carton_h'], $t === 't_account_subscriptions' => ['fk_item_id'], default => [] };
    }

    public static function isoTaken(string $iso, ?int $id = null): bool
    {
        return DB::table('cat_company_house')->where('fk_id_ent', Context::ent())->whereRaw('UPPER(iso_code) = ?', [mb_strtoupper(preg_replace('/\s+/', '', $iso))])->when($id, fn ($q) => $q->where('id', '<>', $id))->exists();
    }

    /** Fields checked live while the person types. */
    public static function liveFields(string $formKey): array { return array_values(array_unique(array_merge(self::liveOwn($formKey), self::liveTyped($formKey), self::coached($formKey)))); }

    /** Owner 26-11 20:31: every number, amount, date and phone field is checked when it is left – what is wrong and the right value with one click. */
    private static function coached(string $formKey): array
    {
        return array_values(array_map(fn ($f) => $f['field_name'], array_filter(Registry::fields($formKey), fn ($f) => in_array($f['type'], ['number', 'money', 'date', 'phone'], true) && ! $f['secret'] && ! $f['readonly'])));
    }

    /**
     * The field coach: [ok, level, message, suggest]. Numbers / amounts: below the field's minimum or above its maximum → the limit; far from what this
     * field usually holds (10× the usual) → "usually about x" with x to use; dates more than two years away → "is the year right?"; phones: 00 → +, spaces out.
     */
    public static function coach(string $formKey, array $f, string $value): array
    {
        $v = trim($value); if ($v === '') return ['ok' => true];
        if (in_array($f['type'], ['number', 'money'], true)) {
            $clean = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', $v));
            if (! is_numeric($v)) return is_numeric($clean) ? ['ok' => false, 'message' => 'Numbers only.', 'suggest' => $clean] : ['ok' => false, 'message' => 'Numbers only – for example 12.50.'];
            $n = (float) $v;
            if ($f['min'] !== null && $f['min'] !== '' && $n < (float) $f['min']) return ['ok' => false, 'message' => 'At least ' . $f['min'] . ' here.', 'suggest' => $n < 0 && abs($n) >= (float) $f['min'] && ($f['max'] === null || $f['max'] === '' || abs($n) <= (float) $f['max']) ? (string) abs($n) : (string) $f['min']];      // a minus typed by mistake → the same number without it
            if ($f['max'] !== null && $f['max'] !== '' && $n > (float) $f['max']) return ['ok' => false, 'message' => 'At most ' . $f['max'] . ' here.', 'suggest' => (string) $f['max']];
            if ($n < 0 && ! preg_match('/(adjust|diff|variance|balance|profit|margin|change)/i', $f['column_name'])) return ['ok' => false, 'message' => 'A minus amount is not expected here.', 'suggest' => (string) abs($n)];
            $t = Registry::table($formKey); $col = (string) $f['column_name'];
            if ($n > 0 && $t && \Illuminate\Support\Facades\Schema::hasColumn($t, $col)) {
                $usual = ReadCache::remember("usual:$t:$col", 900, function () use ($t, $col) { $q = \Illuminate\Support\Facades\DB::table($t)->where($col, '>', 0); $n = (clone $q)->count(); if ($n < 5) return null;
                    return (float) (clone $q)->orderBy($col)->offset((int) floor($n / 2))->limit(1)->value($col); });
                if ($usual && ($n > $usual * 10 || $n < $usual / 10)) { $u = rtrim(rtrim(number_format($usual, 4, '.', ''), '0'), '.');
                    return ['ok' => true, 'level' => 'warn', 'message' => "Please check: this field is usually about $u – $v is " . ($n > $usual ? 'much higher' : 'much lower') . '.', 'suggest' => $u]; }
            }
            return ['ok' => true];
        }
        if ($f['type'] === 'date') {
            $ts = strtotime($v); if (! $ts) return ['ok' => false, 'message' => 'Not a date – pick it from the calendar.'];
            if (abs($ts - time()) > 2 * 366 * 86400) return ['ok' => true, 'level' => 'warn', 'message' => 'Is the year right? ' . date('d M Y', $ts) . ' is more than two years ' . ($ts > time() ? 'ahead' : 'ago') . '.', 'suggest' => date('Y') . substr(date('Y-m-d', $ts), 4)];
            return ['ok' => true];
        }
        if ($f['type'] === 'phone') {
            $p = preg_replace('/[^0-9+]/', '', $v); if (str_starts_with($p, '00')) $p = '+' . substr($p, 2);
            if (strlen(preg_replace('/\D/', '', $p)) < 7) return ['ok' => false, 'message' => 'Too short for a phone number.'];
            return $p !== $v ? ['ok' => true, 'level' => 'warn', 'message' => 'Written the standard way: ' . $p, 'suggest' => $p] : ['ok' => true];
        }
        return ['ok' => true];
    }
    private static function liveOwn(string $formKey): array { $t = Registry::table($formKey); if (isset(MasterCodes::RULES[$t])) return array_values(array_filter(array_map(fn ($f) => in_array($f['column_name'], MasterCodes::fields($t), true) ? $f['field_name'] : null, Registry::fields($formKey)))); if ($t === 't_account') return ['account_code']; return $formKey === 'cat_company_house' ? array_keys(self::COMPANY) : ($t === 't_product_vendors' ? ['hs_code', 'sku_vendor'] : ($t === 't_src_products' ? ['hs_code'] : ($t === 'cat_ip_burgers' ? ['isp_code'] : ($t === 't_src_cycles' ? ['order_ref'] : [])))); }

    /** Live fields checked 500 ms after the last key (not only when the field is left). */
    public static function liveTyping(string $formKey): array { return Registry::table($formKey) === 't_product_vendors' ? ['sku_vendor'] : []; }

    /** Form standard (d): every e-mail and link field of every form is checked when it is left, with a click-to-fix suggestion. */
    private static function liveTyped(string $formKey): array
    {
        return array_values(array_map(fn ($f) => $f['field_name'], array_filter(Registry::fields($formKey), fn ($f) => in_array($f['type'], ['email', 'link'], true) && ! $f['secret'])));
    }

    public const MAIL_TYPOS = ['gmial.com' => 'gmail.com', 'gmai.com' => 'gmail.com', 'gmal.com' => 'gmail.com', 'gmail.co' => 'gmail.com', 'gmail.con' => 'gmail.com', 'gamil.com' => 'gmail.com', 'gnail.com' => 'gmail.com',
        'hotmial.com' => 'hotmail.com', 'hotmai.com' => 'hotmail.com', 'hotmail.co' => 'hotmail.com', 'yahooo.com' => 'yahoo.com', 'yaho.com' => 'yahoo.com', 'yahoo.co' => 'yahoo.com', 'outlok.com' => 'outlook.com', 'outlook.co' => 'outlook.com', 'icloud.co' => 'icloud.com'];

    /** A typed e-mail / link: [ok, message, suggest] – suggest is the value the "Use …" link puts into the field. */
    public static function typed(string $type, string $value): array
    {
        $v = trim($value); if ($v === '') return ['ok' => true];
        if ($type === 'email') {
            $v = mb_strtolower(preg_replace('/\s+/', '', $v));
            if (! preg_match('/^[^@\s]+@([^@\s]+\.[^@\s]+)$/', $v, $m)) { return ['ok' => false, 'message' => 'This is not an e-mail address (name@domain.com).', 'suggest' => null]; }
            if (isset(self::MAIL_TYPOS[$m[1]])) { $s = substr($v, 0, -strlen($m[1])) . self::MAIL_TYPOS[$m[1]]; return ['ok' => false, 'message' => "Did you mean $s?", 'suggest' => $s]; }
            return ['ok' => true, 'value' => $v];
        }
        if ($type === 'link') {
            if (preg_match('~^https?://\S+\.\S+~i', $v)) return ['ok' => true];
            $s = preg_match('~^(www\.|[\w-]+\.[a-z]{2,})~i', $v) ? 'https://' . preg_replace('~^/+~', '', $v) : null;
            return ['ok' => false, 'message' => 'Must start with https://', 'suggest' => $s];
        }
        return ['ok' => true];
    }

    /** Live check while typing (the old screen's message). */
    public static function live(string $formKey, string $field, string $value, ?int $id, array $row = []): array
    {
        $t = Registry::table($formKey); $col = collect(Registry::fields($formKey))->firstWhere('field_name', $field)['column_name'] ?? $field;
        if ($t && isset(MasterCodes::RULES[$t][$col])) return MasterCodes::live($t, $col, $value, $id, $row);      // R-F_SETUP-39
        if (in_array($field, ['hs_code', 'isp_code'], true)) { $d = [$field => $value]; $e = $field === 'hs_code' ? self::productCodes($d, []) : self::isp($d); return ['ok' => ! $e, 'message' => $e[$field] ?? null, 'value' => $d[$field] ?? $value]; }
        if ($field === 'account_code' && Registry::table($formKey) === 't_account') { $d = ['account_code' => $value]; $e = AccountCode::check($formKey, $d, [], $id); return ['ok' => ! $e, 'message' => $e['account_code'] ?? null, 'value' => $d['account_code'] ?? $value]; }      // R-C-4 in the browser
        if ($field === 'sku_vendor' && Registry::table($formKey) === 't_product_vendors') return SkuCheck::check((int) Context::ent(), $value, $id);      // R-D-2 live (block / warn / available)
        if ($field === 'order_ref' && Registry::table($formKey) === 't_src_cycles') return SrcDuplicate::live((int) Context::ent(), null, $value, $id) + ['value' => SrcDuplicate::clean($value)];
        $tf = collect(Registry::fields($formKey))->firstWhere('field_name', $field);
        if ($tf && in_array($tf['type'], ['email', 'link'], true) && ! ($formKey === 'cat_company_house' && isset(self::COMPANY[$field]))) return self::typed($tf['type'], $value);
        if ($formKey !== 'cat_company_house') return $tf ? self::coach($formKey, $tf, $value) : ['ok' => true];      // the field coach for every other field
        if ($field === 'iso_code' && self::strict($formKey)) { $taken = trim($value) !== '' && self::isoTaken($value, $id); return ['ok' => ! $taken, 'message' => $taken ? 'This ISO code already exists. Please add another' : null]; }
        $d = [$field => $value]; $e = isset(self::COMPANY[$field]) ? self::check($formKey, $d, [], $id, array_fill_keys(self::COMPANY_REQUIRED, 1)) : [];
        return ['ok' => ! isset($e[$field]), 'message' => $e[$field] ?? null, 'value' => $d[$field] ?? $value];
    }
}
