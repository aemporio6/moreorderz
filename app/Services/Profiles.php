<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * The marketplace profile as the readers see it (R-A_SALES-87, IC-0): report types with their column mappings, fee buckets with the file columns / words that fill them,
 * refund kinds, required-for-close. A new marketplace works by filling its profile – no code per marketplace.
 */
class Profiles
{
    /** report roles and the roles of their columns */
    public const ROLES = [
        'MONEY' => ['order' => 'Order number', 'statement' => 'Payout / settlement number', 'date' => 'Date', 'payout_date' => 'Payout date', 'type' => 'Type', 'desc' => 'Description', 'sku' => 'SKU', 'qty' => 'Quantity', 'currency' => 'Currency', 'net' => 'Net / total', 'amount' => 'Amount (one amount column per line)'],
        'FULFILMENT' => ['order_no' => 'Order number', 'd_order' => 'Order date', 'item_name' => 'Item name', 'sku' => 'SKU', 'qty' => 'Quantity', 'buyer_full_name' => 'Buyer full name', 'buyer_first_name' => 'Buyer first name', 'buyer_last_name' => 'Buyer last name', 'buyer_email' => 'Buyer e-mail', 'buyer_phone' => 'Buyer phone',
            'recipient_first_name' => 'Recipient first name', 'recipient_last_name' => 'Recipient last name', 'recipient_phone' => 'Recipient phone', 'address_1' => 'Address line 1', 'address_2' => 'Address line 2', 'address_3' => 'Address line 3', 'city' => 'City', 'state' => 'State', 'postcode' => 'Postcode', 'country_name' => 'Country', 'country_code' => 'Country code'],
    ];
    public const BOOK_AS = ['NET_SALES', 'REFUND', 'MP_COMMISSION', 'MP_REGULATORY', 'MP_INTERNATIONAL', 'MP_PENALTY', 'MP_LISTING', 'MP_SUBSCRIPTION', 'MP_OTHER_FEE', 'MP_FULFILMENT', 'MP_STORAGE', 'MP_SHIPPING_LABEL', 'MP_ADS', 'MP_VAT_ON_FEES', 'MP_TAX_WITHHELD', 'MP_CHARGEBACK', 'MP_REIMBURSEMENT', 'MP_HOLD', 'MP_ADJUSTMENT', 'MP_FX', 'MP_OTHER'];

    /** R-A_SALES-87: every marketplace has one profile – a new marketplace gets a copy of the matching example (eBay / Amazon / TikTok by name or ISO code), else a blank one. */
    public static function ensureForMarketplace(int $marketplaceId): int
    {
        $m = DB::table('cat_marketplaces')->where('id', $marketplaceId)->first(); if (! $m) return 0;
        if ($id = (int) DB::table('mp_profiles')->where('fk_id_marketplace', $m->id)->where('fk_id_ent', $m->fk_id_ent)->value('id')) return $id;
        $name = trim(($m->marketplace_short ?? '') . ' ' . ($m->marketplace_descr ?? '')); $hay = mb_strtolower($name . ' ' . ($m->iso_code ?? '')); $src = null;
        foreach (['EBAY_EXAMPLE' => 'ebay', 'AMAZON_EXAMPLE' => 'amazon', 'TIKTOK_EXAMPLE' => 'tiktok'] as $code => $w) if (str_contains($hay, $w)) $src = DB::table('mp_profiles')->where('code', $code)->where('fk_id_ent', 0)->first();
        $base = $src ?? DB::table('mp_profiles')->where('fk_id_ent', 0)->orderBy('id')->first(); if (! $base) return 0;
        $row = (array) $base; unset($row['id']);
        return DB::transaction(function () use ($row, $m, $name, $src) {
            $id = (int) DB::table('mp_profiles')->insertGetId(array_merge($row, ['fk_id_ent' => $m->fk_id_ent, 'fk_id_marketplace' => $m->id, 'code' => mb_substr('MP_' . $m->id, 0, 30), 'name' => mb_substr(($name !== '' ? $name : 'Marketplace ' . $m->id) . ' profile', 0, 100), 'sale_sets_json' => null, 'created_by' => Context::userId(), 'created_at' => now(), 'updated_at' => null]));
            if ($src) foreach (['mp_profile_report_types', 'mp_profile_fee_buckets'] as $t) foreach (DB::table($t)->where('fk_id_mp_profile', $src->id)->get() as $r) { $r = (array) $r; unset($r['id']); $r['fk_id_mp_profile'] = $id; DB::table($t)->insert($r); }
            Audit::log('mp_profiles', $id, 'INSERT', ['profile for marketplace' => $m->id, 'copied from' => $src->code ?? 'blank']);
            return $id;
        });
    }

    public static function ofShop(int $shopId): ?object { $p = (int) DB::table('t_branches')->where('id', $shopId)->value('fk_id_mp_profile'); return $p ? DB::table('mp_profiles')->where('id', $p)->first() : null; }

    private static function names(?string $csv): array { return array_values(array_filter(array_map(fn ($x) => trim($x), explode(',', (string) $csv)), fn ($x) => $x !== '')); }

    /** The report type of a role with its column map: role => [header names]. */
    public static function report(int $profileId, string $role): ?array
    {
        $r = DB::table('mp_profile_report_types')->where('fk_id_mp_profile', $profileId)->where('report_role', $role)->where('f_active', 1)->orderBy('id')->first(); if (! $r) return null;
        $map = []; foreach ((array) json_decode((string) $r->column_map_json, true) as $k => $v) { $n = is_array($v) ? array_values(array_filter(array_map('trim', $v))) : self::names($v); if ($n) $map[$k] = $n; }
        return ['id' => (int) $r->id, 'type' => $r->report_type, 'required' => (bool) $r->f_required_for_close, 'detect' => self::names($r->detect_headers), 'map' => $map];
    }

    /**
     * R-A_SALES-87 a file date with a time, written in the profile's time zone, as the company's calendar day (Settings COMPANY_TIMEZONE, default the app zone).
     * A date without a time stays as it is. Example: "2026-10-31 23:30" in America/Los_Angeles is 1 Nov in London.
     */
    public static function localDate(string $raw, string $tz, string $date, int $ent): string
    {
        if (! preg_match('/\b(\d{1,2}):(\d{2})/', $raw, $m)) return $date;
        $home = (string) (Settings::get('COMPANY_TIMEZONE', $ent, config('app.timezone')) ?: 'Europe/London'); if (strcasecmp($tz, $home) === 0) return $date;
        try { $d = new \DateTimeImmutable($date . ' ' . sprintf('%02d:%02d', (int) $m[1], (int) $m[2]), new \DateTimeZone($tz)); return $d->setTimezone(new \DateTimeZone($home))->format('Y-m-d'); } catch (\Throwable) { return $date; }
    }

    /** R-FIN-69 every report type of the profile as a file layout: report_type => [map (role => header names), detect (headers that recognise the file)]. */
    public static function layouts(int $profileId): array
    {
        $out = [];
        foreach (DB::table('mp_profile_report_types')->where('fk_id_mp_profile', $profileId)->where('f_active', 1)->orderBy('id')->get() as $r) {
            $map = []; foreach ((array) json_decode((string) $r->column_map_json, true) as $k => $v) { $n = is_array($v) ? array_values(array_filter(array_map('trim', $v))) : self::names($v); if ($n) $map[$k] = $n; }
            $out[$r->report_type] = ['map' => $map, 'detect' => self::names($r->detect_headers)];
        }
        return $out;
    }

    /** Fee buckets that say how a file fills them: wide = header name → code, long = words in type / description → code. */
    public static function buckets(int $profileId): array
    {
        $wide = []; $long = [];
        foreach (DB::table('mp_profile_fee_buckets')->where('fk_id_mp_profile', $profileId)->where('f_active', 1)->orderBy('sort_no')->orderBy('id')->get() as $b) {
            $code = in_array($b->book_as, self::BOOK_AS, true) ? $b->book_as : null; if (! $code) continue;
            foreach (self::names($b->file_columns) as $h) $wide[MarketplaceFiles::keyOf($h)] = $code; $kw = array_map(fn ($x) => MarketplaceFiles::keyOf($x), self::names($b->match_keywords)); if ($kw) $long[] = [$code, $kw];
        }
        return ['wide' => $wide, 'long' => $long];
    }

    /** Is a report of this role required for Month Close? null = the shop has no profile, or its reports are not set up yet. */
    public static function required(int $shopId, string $role): ?bool
    {
        $p = self::ofShop($shopId); if (! $p) return null;
        if (! DB::table('mp_profile_report_types')->where('fk_id_mp_profile', $p->id)->where('f_active', 1)->exists()) return null;      // a profile without its reports set up yet: the general checks apply
        return DB::table('mp_profile_report_types')->where('fk_id_mp_profile', $p->id)->where('f_active', 1)->where('f_required_for_close', 1)->whereIn('report_role', $role === 'ORDERS' ? ['ORDERS', 'FULFILMENT'] : [$role])->exists();
    }

    public static function list(?object $u): array
    {
        Perm::need($u, 'setup.marketplaces');
        return DB::table('mp_profiles as p')->whereIn('p.fk_id_ent', [0, (int) Context::ent()])->orderBy('p.name')->get(['p.id', 'p.name', 'p.code', 'p.f_active'])->map(fn ($p) => ['id' => (int) $p->id, 'name' => $p->name, 'code' => $p->code, 'active' => (bool) $p->f_active,
            'shops' => DB::table('t_branches')->where('fk_id_mp_profile', $p->id)->count(), 'reports' => DB::table('mp_profile_report_types')->where('fk_id_mp_profile', $p->id)->count(), 'mapped' => DB::table('mp_profile_report_types')->where('fk_id_mp_profile', $p->id)->whereNotNull('column_map_json')->count()])->all();
    }

    /** Profile mappings editor (Setup): report types and buckets of one profile. */
    public static function page(int $profileId, ?object $u): array
    {
        Perm::need($u, 'setup.marketplaces'); $p = DB::table('mp_profiles')->where('id', $profileId)->first() ?? throw new \InvalidArgumentException('Profile not found.');
        return ['profile' => ['id' => (int) $p->id, 'name' => $p->name, 'code' => $p->code], 'roles' => self::ROLES, 'book_as' => self::BOOK_AS,
            'reports' => DB::table('mp_profile_report_types')->where('fk_id_mp_profile', $profileId)->orderBy('id')->get()->map(fn ($r) => ['id' => (int) $r->id, 'type' => $r->report_type, 'role' => $r->report_role, 'required' => (bool) $r->f_required_for_close, 'detect' => (string) $r->detect_headers, 'map' => (array) json_decode((string) $r->column_map_json, true), 'active' => (bool) $r->f_active])->all(),
            'buckets' => DB::table('mp_profile_fee_buckets')->where('fk_id_mp_profile', $profileId)->orderBy('sort_no')->orderBy('id')->get(['id', 'bucket_code', 'label', 'book_as', 'file_columns', 'match_keywords', 'hub_label', 'f_active'])->all()];
    }

    public static function saveReport(int $profileId, array $d, ?object $u): array
    {
        Perm::need($u, 'setup.marketplaces'); if (! DB::table('mp_profiles')->where('id', $profileId)->exists()) throw new \InvalidArgumentException('Profile not found.');
        $role = strtoupper((string) ($d['role'] ?? '')); $type = strtoupper(preg_replace('/[^A-Za-z0-9_]+/', '_', trim((string) ($d['type'] ?? ''))));
        if ($type === '') throw new \InvalidArgumentException('Give the report type a code, for example TEMU_SETTLEMENT.'); if (! in_array($role, ['ORDERS', 'FULFILMENT', 'MONEY', 'PAYOUTS', 'LISTINGS', 'HEALTH', 'DS_SUPPLIER_ORDERS'], true)) throw new \InvalidArgumentException('Choose the role of the report.');
        $map = []; foreach ((array) ($d['map'] ?? []) as $k => $v) { if (! isset(self::ROLES[$role][$k])) continue; $n = self::names(is_array($v) ? implode(',', $v) : (string) $v); if ($n) $map[$k] = implode(', ', $n); }
        if ($role === 'MONEY' && $map && empty($map['order'])) throw new \InvalidArgumentException('A money report mapping needs the Order number column.');
        if ($role === 'FULFILMENT' && $map && (empty($map['order_no']) || empty($map['d_order']))) throw new \InvalidArgumentException('A daily orders mapping needs the Order number and Order date columns.');
        $row = ['report_type' => $type, 'report_role' => $role, 'f_required_for_close' => ! empty($d['required']) ? 1 : 0, 'detect_headers' => mb_substr(implode(', ', self::names((string) ($d['detect'] ?? ''))), 0, 500) ?: null, 'column_map_json' => $map ? json_encode($map) : null, 'f_active' => array_key_exists('active', $d) ? (int) (bool) $d['active'] : 1];
        $id = (int) ($d['id'] ?? 0); if ($id) DB::table('mp_profile_report_types')->where('id', $id)->where('fk_id_mp_profile', $profileId)->update($row); else $id = (int) DB::table('mp_profile_report_types')->insertGetId($row + ['fk_id_mp_profile' => $profileId]);
        Audit::log('mp_profile_report_types', $id, 'UPDATE', $row); return ['message' => "Report type $type saved.", 'id' => $id];
    }

    public static function saveBucket(int $profileId, array $d, ?object $u): array
    {
        Perm::need($u, 'setup.marketplaces'); $code = strtoupper(preg_replace('/[^A-Za-z0-9_]+/', '_', trim((string) ($d['bucket_code'] ?? '')))); if ($code === '') throw new \InvalidArgumentException('Give the bucket a code.');
        $book = strtoupper((string) ($d['book_as'] ?? '')); if (! in_array($book, self::BOOK_AS, true)) throw new \InvalidArgumentException('Choose how the bucket is booked.');
        if (trim((string) ($d['file_columns'] ?? '')) === '' && trim((string) ($d['match_keywords'] ?? '')) === '') throw new \InvalidArgumentException('Give the file column(s) or the words in the type / description that fill this bucket.');
        $row = ['bucket_code' => $code, 'label' => mb_substr(trim((string) ($d['label'] ?? '')) ?: $code, 0, 150), 'book_as' => $book, 'file_columns' => mb_substr(implode(', ', self::names((string) ($d['file_columns'] ?? ''))), 0, 500) ?: null, 'match_keywords' => mb_substr(implode(', ', self::names((string) ($d['match_keywords'] ?? ''))), 0, 500) ?: null, 'hub_label' => trim((string) ($d['hub_label'] ?? '')) ?: null, 'f_active' => 1];
        $id = (int) ($d['id'] ?? 0); if ($id) DB::table('mp_profile_fee_buckets')->where('id', $id)->where('fk_id_mp_profile', $profileId)->update($row); else $id = (int) DB::table('mp_profile_fee_buckets')->insertGetId(RecordService::fillNotNull('mp_profile_fee_buckets', $row + ['fk_id_mp_profile' => $profileId, 'sort_no' => 100, 'charge_kind' => $code, 'sign_rule' => 'COST', 'f_in_selling_costs' => in_array($book, ['NET_SALES', 'REFUND', 'MP_HOLD', 'MP_REIMBURSEMENT'], true) ? 0 : 1]));
        Audit::log('mp_profile_fee_buckets', $id, 'UPDATE', $row); return ['message' => "Bucket $code saved.", 'id' => $id];
    }
}
