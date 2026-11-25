<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-F_SETUP-39 typed real-world codes of the masters: unique (per company, per country where the rule says so), the format auto-corrected,
 * checked live while typing (excluding the record itself) and again on save. Message "This <label> already exists. Please add another"; the old wording kept where the old form had one.
 */
class MasterCodes
{
    /** table => [column => [label, format, scope column or null]] */
    public const RULES = [
        'card_categories' => ['card_category_name' => ['Card Category Name', 'trim', null]],
        'card_types' => ['card_type' => ['Card Type', 'trim', null]],
        'cc_providers' => ['card_provider_name' => ['Card Provider', 'trim', 'country_id']],
        'cat_banks' => ['bank_iso_code' => ['ISO code', 'upper', null]],
        'cat_ip_burgers' => ['ip_burger_ip' => ['Ipburger IP', 'ip', null]],
        'cat_tbl_pc' => ['pc_number' => ['PC', 'upper', null]],
        'cat_photo_ids' => ['photo_iso_code' => ['ISO code', 'upper', null]],
        'cat_social_media' => ['social_media_name' => ['Social Media', 'trim', null]],
        'cat_subscription_items' => ['item_iso_code' => ['ISO code', 'upper', null]],
        'cat_warehouses' => ['warehouse_short' => ['Warehouse short name', 'upper', null]],
        'cat_currency' => ['currency_code' => ['Currency code', 'iso3', null]],
        'cat_incoterm' => ['incoterm_code' => ['Incoterm code', 'iso3', null]],
        'cat_payment_terms' => ['term_short' => ['Payment term', 'trim', null]],
        'cat_ports' => ['port_code' => ['Port code', 'locode', null]],
        'cat_doc_types' => ['iso_doc_type' => ['Document type code', 'upper', null]],
        'cat_mobile_network' => ['network_name' => ['Network', 'trim', 'fk_id_country']],
    ];

    public static function fields(string $table): array { return array_keys(self::RULES[$table] ?? []); }

    /** Clean a value by its format; returns [value, error or null]. */
    public static function format(string $fmt, string $v): array
    {
        $v = trim(preg_replace('/\s+/u', ' ', $v));
        return match ($fmt) {
            'upper' => [mb_strtoupper(str_replace(' ', '', $v)), null],
            'iso3' => preg_match('/^[A-Za-z]{3}$/', $v) ? [strtoupper($v), null] : [strtoupper($v), 'Use the 3-letter code (e.g. GBP, USD, EXW, FOB).'],
            'locode' => $v === '' ? ['', null] : (preg_match('/^[A-Za-z]{2}[A-Za-z0-9]{3}$/', str_replace(' ', '', $v)) ? [strtoupper(str_replace(' ', '', $v)), null] : [strtoupper($v), 'Port code: 5 characters, the first 2 are the country (e.g. GBFXT).']),
            'ip' => filter_var($v, FILTER_VALIDATE_IP) ? [$v, null] : [$v, 'Enter an IP address (e.g. 81.2.69.160).'],
            default => [$v, null],
        };
    }

    /** Is the value taken by another row (same company; same scope value when the rule has one)? */
    public static function taken(string $table, string $col, string $value, ?int $id, array $row = []): bool
    {
        if ($value === '') return false; [, , $scope] = self::RULES[$table][$col];
        return DB::table($table)->whereRaw("UPPER(TRIM($col)) = ?", [mb_strtoupper($value)])->when($id, fn ($q) => $q->where('id', '<>', $id))
            ->when(\Illuminate\Support\Facades\Schema::hasColumn($table, 'fk_id_ent') && Context::ent(), fn ($q) => $q->whereIn('fk_id_ent', [0, (int) Context::ent()]))
            ->when($scope && ! empty($row[$scope]), fn ($q) => $q->where($scope, $row[$scope]))->exists();
    }

    public static function message(string $label): string { return "This $label already exists. Please add another"; }

    /** Save check (FormRules): format + unique; cleans $data. */
    public static function check(string $table, array &$data, array $old, ?int $id): array
    {
        $e = [];
        foreach (self::RULES[$table] ?? [] as $col => [$label, $fmt]) {
            if (! array_key_exists($col, $data) || $data[$col] === null) continue;
            [$v, $err] = self::format($fmt, (string) $data[$col]); $data[$col] = $v;
            if ($err) { $e[$col] = $err; continue; }
            if (self::taken($table, $col, $v, $id, $data + $old)) $e[$col] = self::message($label);
        }
        return $e;
    }

    /** Live check while typing: ok / message / the cleaned value. A GET – never writes. */
    public static function live(string $table, string $col, string $value, ?int $id, array $row = []): array
    {
        [$label, $fmt] = self::RULES[$table][$col]; [$v, $err] = self::format($fmt, $value);
        if ($err) return ['ok' => false, 'message' => $err, 'value' => $v];
        return self::taken($table, $col, $v, $id, $row) ? ['ok' => false, 'message' => self::message($label), 'value' => $v] : ['ok' => true, 'value' => $v];
    }
}
