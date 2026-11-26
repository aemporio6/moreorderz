<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The person rules of the record forms (FormRules hook) – one place for the checks the people tabs share:
 * R-C-5 managed person (names in any script, DOB in the past), R-C-6 text safety (unreadable text, emoji, spaces) on every person table,
 * R-C-7 mobile / e-mail format, an e-mail once per person, the last way to reach a person is not removed,
 * R-C-24 addresses (lived upto ≥ from, post code upper, country from the person), R-C-25 "submitted on" today when an ID / document is picked,
 * R-C-27 e-mail lower case, R-C-29 card address types (Personal = Home, Business = Office) and limit ≥ 0, R-C-33 subscription kind,
 * R-C-34 one IP on two shops of the same marketplace needs a reason, R-C-35 a PC's marketplace is one the person has an account on and the hotspot SIM is his own,
 * R-C-52 one active employment row per person, D7 staff bank details for the HR right only.
 */
class PersonRules
{
    public const TABLES = ['cat_company_house', 'cat_banks'];
    private const NAME_COLS = ['first_name', 'middle_name', 'sur_name'];
    private const NOTES = ['private_notes', 'notes'];
    public const STAFF_BANK = ['account_no', 'iban_no', 'sort_branch_code', 'swift_code'];

    public static function applies(string $table): bool { return $table === 't_account' || str_starts_with($table, 't_account_') || in_array($table, self::TABLES, true); }

    /** The reason typed for a warning that may be saved anyway (RecordService opt reason). */
    public static string $reason = '';

    public static function check(string $formKey, string $table, array &$data, array $old, ?int $id): array
    {
        $e = self::text($formKey, $data);
        if ($e) return $e;
        $v = fn (string $c) => array_key_exists($c, $data) ? $data[$c] : ($old[$c] ?? null);
        return match ($table) {
            't_account' => self::managed($formKey, $data, $v),
            't_account_mobiles' => self::mobile($data, $old, $id, $v),
            't_account_emails' => self::email($data, $old, $id, $v),
            't_account_addresses' => self::address($data, $old, $id, $v),
            't_account_credit_cards' => self::card($data, $v),
            't_account_subscriptions' => self::subscription($data, $v),
            't_account_ipburgers' => self::ip($data, $id, $v, self::$reason),
            't_account_pc' => self::pc($data, $v),
            't_account_banks' => self::staffBank($data, $v),
            't_account_marketplaces', 't_account_paypal', 't_account_company_houses' => self::submitted($table, $data, $old),
            default => [],
        };
    }

    /** R-C-6 text safety: unreadable text refused, emoji / 4-byte symbols refused, runs of spaces made one (notes only trimmed). Secrets and links are left alone. */
    public static function text(string $formKey, array &$data): array
    {
        $e = [];
        foreach (Registry::fields($formKey) as $f) {
            $c = $f['column_name']; if (! $c || ! array_key_exists($c, $data) || ! is_string($data[$c]) || $data[$c] === '' || ! empty($f['secret']) || Vault::isMasked($data[$c])) continue;
            if (! in_array($f['type'], ['text', 'textarea', 'email', 'tel', null, ''], true)) continue;
            $label = $f['label'] ?: $c;
            if (! mb_check_encoding($data[$c], 'UTF-8')) { $e[$f['field_name']] = "$label has unreadable characters."; continue; }
            if (preg_match('/[\x{10000}-\x{10FFFF}]/u', $data[$c])) { $e[$f['field_name']] = "$label cannot contain emoji or special symbols."; continue; }
            $data[$c] = in_array($c, self::NOTES, true) || $f['type'] === 'textarea' ? trim($data[$c]) : (string) preg_replace('/\s+/u', ' ', trim($data[$c]));
        }
        return $e;
    }

    /** R-C-5 names: letters of any script, space . , - and apostrophe; DOB in the past. */
    private static function managed(string $formKey, array &$data, callable $v): array
    {
        $e = [];
        foreach (self::NAME_COLS as $c) {
            $x = $data[$c] ?? null; if (! is_string($x) || $x === '') continue;
            if (! preg_match("/^[\\p{L}\\p{M} .,\\-']+$/u", $x)) $e[$c] = (Registry::labelOf($formKey, $c) ?? $c) . " can use letters, spaces and . , - ' only.";
        }
        $dob = $data['d_of_birth'] ?? null;
        if (is_string($dob) && $dob !== '' && $dob >= date('Y-m-d')) $e['d_of_birth'] = 'Date of birth must be in the past.';
        return $e;
    }

    /** R-C-7 mobile format; the last mobile / e-mail of a person is not switched off (REACH). */
    private static function mobile(array &$data, array $old, ?int $id, callable $v): array
    {
        $n = $data['mobile_no'] ?? null;
        if (is_string($n) && $n !== '' && (! preg_match('/^\+?[0-9 ()\-]{6,20}$/', $n) || strlen(preg_replace('/\D/', '', $n)) < 6)) return ['mobile_no' => 'Mobile No: numbers only (spaces, +, - and brackets are fine), 6 to 20 characters.'];
        if ($id && $n !== null && $n !== '' && ($acc = (int) $v('fk_id_account')) && ($d = preg_replace('/\D/', '', (string) $n)) && DB::table('t_account_mobiles')->where('fk_id_account', $acc)->where('id', '<>', $id)->where(fn ($w) => $w->whereNull('f_active')->orWhere('f_active', 1))->get(['mobile_no'])->contains(fn ($m) => preg_replace('/\D/', '', (string) $m->mobile_no) === $d))
            return ['mobile_no' => 'This mobile number is entered twice.'];
        return self::reach('t_account_mobiles', $data, $old, $id, $v);
    }

    /** R-C-7 / R-C-27 e-mail lower case, valid, ≤ 200, once per person. */
    private static function email(array &$data, array $old, ?int $id, callable $v): array
    {
        if (isset($data['email']) && is_string($data['email']) && $data['email'] !== '') {
            $data['email'] = mb_strtolower(trim($data['email']));
            if (mb_strlen($data['email']) > 200 || ! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) return ['email' => 'Please enter a valid email address.'];
            if (($acc = (int) $v('fk_id_account')) && DB::table('t_account_emails')->where('fk_id_account', $acc)->whereRaw('LOWER(TRIM(email)) = ?', [$data['email']])->when($id, fn ($q) => $q->where('id', '<>', $id))->where(fn ($w) => $w->whereNull('f_active')->orWhere('f_active', 1))->exists())
                return ['email' => 'This email is entered twice.'];
        }
        foreach (['recovery_email'] as $c) if (isset($data[$c]) && is_string($data[$c]) && $data[$c] !== '') { $data[$c] = mb_strtolower(trim($data[$c])); if (! filter_var($data[$c], FILTER_VALIDATE_EMAIL)) return [$c => 'Please enter a valid email address.']; }
        if (($m = $v('popup_notification_mobile')) && ($acc = (int) $v('fk_id_account')) && ! DB::table('t_account_mobiles')->where('id', (int) $m)->where('fk_id_account', $acc)->exists()) return ['popup_notification_mobile' => 'Choose one of this person\'s own mobiles.'];
        return self::reach('t_account_emails', $data, $old, $id, $v);
    }

    /** REACH: a person keeps at least one active mobile or e-mail (old message word for word). */
    private static function reach(string $table, array $data, array $old, ?int $id, callable $v): array
    {
        if (! $id || ! array_key_exists('f_active', $data) || (int) $data['f_active'] || ! (int) ($old['f_active'] ?? 1)) return [];
        $acc = (int) $v('fk_id_account'); if (! $acc) return [];
        $act = fn ($q) => $q->where(fn ($w) => $w->whereNull('f_active')->orWhere('f_active', 1));
        $left = $act(DB::table('t_account_mobiles')->where('fk_id_account', $acc))->when($table === 't_account_mobiles', fn ($q) => $q->where('id', '<>', $id))->count()
            + $act(DB::table('t_account_emails')->where('fk_id_account', $acc))->when($table === 't_account_emails', fn ($q) => $q->where('id', '<>', $id))->count();
        return $left > 0 ? [] : ['f_active' => 'Add at least a mobile number or an email, so we can reach this person.'];
    }

    /** R-C-24 addresses. */
    private static function address(array &$data, array $old, ?int $id, callable $v): array
    {
        if (isset($data['post_code']) && is_string($data['post_code'])) $data['post_code'] = mb_strtoupper(trim($data['post_code']));
        if (! $id && empty($data['fk_id_country']) && ($acc = (int) $v('fk_id_account'))) $data['fk_id_country'] = DB::table('t_account')->where('id', $acc)->value('current_nationality') ?: null;
        $e = [];
        foreach ([1, 2] as $i) { $f = $v("d_lived_from_addr$i"); $t = $v("d_lived_upto_addr$i"); if ($f && $t && (string) $t < (string) $f) $e["d_lived_upto_addr$i"] = 'Lived upto must be on or after lived from.'; }
        if (($t = $v('l_address_type')) !== null && $t !== '' && ! in_array($t, ['O', 'H'], true)) $e['l_address_type'] = 'Choose Office or Home.';
        return $e;
    }

    /** R-C-29 a card: the personal address is a Home address, the business address an Office address; the limit is 0 or more. */
    private static function card(array &$data, callable $v): array
    {
        $e = [];
        foreach (['linked_personal_address' => ['H', 'Personal address must be a Home address.'], 'linked_business_address' => ['O', 'Business address must be an Office address.']] as $c => [$type, $msg]) {
            $a = $v($c); if (! $a) continue;
            if (DB::table('t_account_addresses')->where('id', (int) $a)->value('l_address_type') !== $type) $e[$c] = $msg;
        }
        $l = $v('credit_limit'); if ($l !== null && $l !== '' && (! is_numeric($l) || (float) $l < 0)) $e['credit_limit'] = 'Credit limit must be an amount of 0 or more.';
        return $e;
    }

    public static function itemKind(?int $item): string
    {
        return $item ? (string) (DB::table('cat_subscription_items')->where('id', $item)->value('kind') ?: 'TOOL') : '';
    }

    /** R-C-33 the item's kind decides which link is kept: IP keeps the IP account, COMPANY the company, any other stores 0 in both. */
    private static function subscription(array &$data, callable $v): array
    {
        if (! array_key_exists('fk_item_id', $data) && ! array_key_exists('fk_ipburger_id', $data) && ! array_key_exists('fk_company_house_id', $data)) return [];
        $k = self::itemKind((int) $v('fk_item_id'));
        if ($k !== 'IP') $data['fk_ipburger_id'] = 0;
        if ($k !== 'COMPANY') $data['fk_company_house_id'] = 0;
        return [];
    }

    /** R-C-34 one IP used by another shop of the same marketplace: "IP {ip} is already used by {shop} on {marketplace}" – saved only with a reason. */
    private static function ip(array &$data, ?int $id, callable $v, string $reason): array
    {
        $ip = (int) $v('fk_ipburger_id'); $mp = (int) $v('ipb_marketplace_id'); $acc = (int) $v('fk_id_account');
        if (! $ip || ! $mp) return [];
        $other = DB::table('t_account_ipburgers')->where('fk_ipburger_id', $ip)->where('ipb_marketplace_id', $mp)->where('fk_id_account', '<>', $acc)->when($id, fn ($q) => $q->where('id', '<>', $id))
            ->where(fn ($w) => $w->whereNull('f_active')->orWhere('f_active', 1))->value('fk_id_account');
        if (! $other) return [];
        if (trim($reason) !== '') return [];
        $shop = DB::table('t_account_marketplaces as m')->join('t_branches as b', 'b.id', '=', 'm.fk_id_shop')->where('m.fk_id_account', $other)->where('m.fk_id_marketplace', $mp)->value(DB::raw("CONCAT_WS('-', b.shop_no, b.shop_name)"))
            ?: trim((string) DB::table('t_account')->where('id', $other)->selectRaw("CONCAT_WS(' ', first_name, sur_name) n")->value('n'));
        $ipName = (string) (DB::table('cat_ip_burgers')->where('id', $ip)->value(Registry::labelColumn('cat_ip_burgers') ?: 'id') ?? $ip);
        $mpName = (string) DB::table('cat_marketplaces')->where('id', $mp)->value('marketplace_short');
        return ['fk_ipburger_id' => "IP $ipName is already used by $shop on $mpName. Give a reason to save anyway."];
    }

    /** R-C-35 a PC: the marketplace is one this person has accounts on; the hotspot SIM is his own. */
    private static function pc(array &$data, callable $v): array
    {
        $acc = (int) $v('fk_id_account'); $e = [];
        if (($mp = (int) $v('fk_marketplace_id')) && $acc && ! DB::table('t_account_marketplaces')->where('fk_id_account', $acc)->where('fk_id_marketplace', $mp)->exists()) $e['fk_marketplace_id'] = 'This person has no account on that marketplace.';
        if (($m = (int) $v('hotspot_mobile_id')) && $acc && ! DB::table('t_account_mobiles')->where('id', $m)->where('fk_id_account', $acc)->exists()) $e['hotspot_mobile_id'] = 'Choose one of this person\'s own SIMs.';
        return $e;
    }

    /** D7 bank numbers of a managed person (staff) are personal data: without the HR right they are neither shown nor changed. */
    private static function staffBank(array &$data, callable $v): array
    {
        if (self::mayPersonal(Auth::user()) || ! self::isStaff((int) $v('fk_id_account'))) return [];
        foreach (self::STAFF_BANK as $c) unset($data[$c]);
        return [];
    }

    public static function mayPersonal(?object $u): bool { return ! $u || Perm::has($u, 'people.personal_data'); }

    public static function isStaff(int $acc): bool
    {
        return $acc > 0 && (string) (DB::table('t_account')->where('id', $acc)->value('l_account_kind') ?: 'M') === 'M' 
            && ! DB::table('t_account_in_types as i')->join('cat_account_types as t', 't.id', '=', 'i.fk_id_account_type')->where('i.fk_id_account', $acc)->where('i.f_active', 1)->whereIn(DB::raw('UPPER(t.role_code)'), ['SUPPLIER', 'FORWARDER', 'VENDOR', 'MANUFACTURER', 'DISTRIBUTOR'])->exists();      // a supplier's bank is paid by Finance
    }

    /** R-C-25: picking a photo ID / document on a marketplace, PayPal or company link fills "submitted on" with today. */
    private static function submitted(string $table, array &$data, array $old): array
    {
        foreach (['fk_photo_submitted_id' => 'photo_submitted_date', 'company_photo_submitted_id' => 'photo_submitted_date', 'fk_doc_submitted_id' => 'doc_submitted_date'] as $pick => $date) {
            if (! array_key_exists($pick, $data) || ! $data[$pick] || (string) $data[$pick] === (string) ($old[$pick] ?? '')) continue;
            if (array_key_exists($date, $data) && $data[$date]) continue;
            if (Registry::hasColumn($table, $date)) $data[$date] = date('Y-m-d');
        }
        return [];
    }

    /** R-C-52 after an employment row is saved active: the other active row of the person is closed (one active row). */
    public static function employmentSaved(int $id): void
    {
        $r = DB::table('t_account_employment')->where('id', $id)->first(['fk_id_account', 'f_active']);
        if (! $r || ! (int) $r->f_active) return;
        foreach (DB::table('t_account_employment')->where('fk_id_account', $r->fk_id_account)->where('id', '<>', $id)->where('f_active', 1)->pluck('id') as $o) {
            DB::table('t_account_employment')->where('id', $o)->update(['f_active' => 0, 'updated_at' => now(), 'updated_by' => Context::userId()]);
            Audit::log('t_account_employment', (int) $o, 'UPDATE', ['f_active' => [1, 0]], 'A newer employment row replaced it', (int) (Context::ent() ?? 0));
        }
    }

    /** R-C-24 the module entities an address is used for (when it is not used for all). */
    public static function entities(int $address): array
    {
        return DB::table('t_account_addresses_entities')->where('fk_id_address', $address)->where(fn ($w) => $w->whereNull('f_active')->orWhere('f_active', 1))->orderBy('id')->pluck('fk_id_entites')->map(fn ($x) => (int) $x)->all();
    }

    /** Writes the links; "used for all entities" on → no links (the address serves every module). */
    public static function syncEntities(int $address, $given): void
    {
        $all = (int) DB::table('t_account_addresses')->where('id', $address)->value('f_used_for_all_entities') === 1;
        $want = $all ? [] : array_values(array_unique(array_filter(array_map('intval', is_array($given) ? $given : explode(',', (string) $given)))));
        if (! $all && $given === null) return;      // the form did not send the list: keep it
        $have = self::entities($address);
        foreach (array_diff($have, $want) as $e) DB::table('t_account_addresses_entities')->where('fk_id_address', $address)->where('fk_id_entites', $e)->delete();
        foreach (array_diff($want, $have) as $e) DB::table('t_account_addresses_entities')->insert(['fk_id_address' => $address, 'fk_id_entites' => $e, 'f_active' => 1]);
        if (array_diff($have, $want) || array_diff($want, $have)) Audit::log('t_account_addresses', $address, 'UPDATE', ['entities' => [$have, $want]], null, (int) (Context::ent() ?? 0));
    }

    /** R-C-25 where a photo ID / document was submitted: marketplace, PayPal and company links pointing at it. */
    public static function submittedTo(string $kind, int $id): array
    {
        $col = $kind === 'doc' ? ['fk_doc_submitted_id', 'fk_doc_submitted_id', 'fk_doc_submitted_id'] : ['fk_photo_submitted_id', 'fk_photo_submitted_id', 'company_photo_submitted_id'];
        $date = $kind === 'doc' ? 'doc_submitted_date' : 'photo_submitted_date';
        $out = [];
        foreach (DB::table('t_account_marketplaces as m')->leftJoin('cat_marketplaces as c', 'c.id', '=', 'm.fk_id_marketplace')->where("m.{$col[0]}", $id)->get(['m.id', 'c.marketplace_short as name', "m.$date as d"]) as $r) $out[] = ['where' => 'Marketplace', 'name' => $r->name, 'date' => $r->d, 'id' => (int) $r->id];
        foreach (DB::table('t_account_paypal')->where($col[1], $id)->get(['id', 'paypal_name as name']) as $r) $out[] = ['where' => 'PayPal', 'name' => $r->name, 'date' => null, 'id' => (int) $r->id];
        foreach (DB::table('t_account_company_houses as l')->leftJoin('cat_company_house as c', 'c.id', '=', 'l.fk_company_house_id')->where("l.{$col[2]}", $id)->get(['l.id', 'c.company_name as name', "l.$date as d"]) as $r) $out[] = ['where' => 'Company', 'name' => $r->name, 'date' => $r->d, 'id' => (int) $r->id];
        return $out;
    }
}
