<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Carrier service selection rules (R-H-30). `carrier_service_rules`: marketplace profile, shop, destination country group (DOMESTIC / REGION / WORLD),
 * weight from / to (g), max length / girth, parcel value from / to, service level asked by the order → carrier + carrier service preset + label template; priority; active.
 * The first matching rule by priority suggests the service; the packer may change it (reason logged). When the marketplace profile says the buyer chose
 * the service (`mp_profiles.f_service_from_order`) and the order's service text is in `carrier_service_map`, that service always wins.
 * No rule matches → the shop's default (`DEFAULT_CARRIER_SERVICE`) and a hint.
 */
class CarrierRules
{
    /** DOMESTIC = the company's country (COMPANY_COUNTRY, default GB); REGION = WH_REGION_COUNTRIES; else WORLD. */
    public static function group(int $ent, ?string $country): string
    {
        $c = strtoupper(trim((string) $country)); $home = strtoupper((string) (Settings::get('COMPANY_COUNTRY', $ent) ?: 'GB'));
        if ($c === '' || $c === $home || ($home === 'GB' && $c === 'UK')) return 'DOMESTIC';
        $region = array_filter(array_map(fn ($x) => strtoupper(trim($x)), explode(',', (string) (Settings::get('WH_REGION_COUNTRIES', $ent) ?: 'IE,FR,DE,NL,BE,LU,ES,PT,IT,AT,DK,SE,FI,PL,CZ,SK,HU,SI,HR,RO,BG,GR,CY,MT,EE,LV,LT'))));
        return in_array($c, $region, true) ? 'REGION' : 'WORLD';
    }

    /** The order's service text → mapped service (and its level) for the shop's marketplace profile. */
    private static function mapped(?int $profile, ?string $text): ?object
    {
        if (! $profile || ! trim((string) $text)) return null;
        return DB::table('carrier_service_map')->where('fk_id_mp_profile', $profile)->where('f_active', 1)->whereRaw('LOWER(service_text) = ?', [mb_strtolower(trim($text))])->first();
    }

    private static function levelOf(?string $text): ?string
    {
        $t = mb_strtolower((string) $text);
        return match (true) { $t === '' => null, str_contains($t, 'express') || str_contains($t, 'next day') || str_contains($t, '24') => 'EXPRESS', str_contains($t, 'economy') || str_contains($t, 'saver') => 'ECONOMY', default => 'STANDARD' };
    }

    /**
     * Suggest the service. $p = branch, country, weight_g, length_cm, girth_cm, value, service_text.
     * Returns ['service' => preset id|null, 'carrier', 'label_template', 'rule' => id|null, 'why' => text, 'hint' => text|null].
     */
    public static function suggest(int $ent, array $p): array
    {
        $shop = DB::table('t_branches')->where('id', (int) ($p['branch'] ?? 0))->first(['id', 'fk_id_mp_profile', 'fk_id_default_carrier']);
        $profile = $shop->fk_id_mp_profile ?? null;
        $map = self::mapped($profile, $p['service_text'] ?? null);
        if ($map && $profile && DB::table('mp_profiles')->where('id', $profile)->value('f_service_from_order'))
            return ['service' => (int) $map->fk_id_car_pkg_svc ?: null, 'carrier' => (int) $map->fk_id_carrier ?: null, 'label_template' => null, 'rule' => null, 'why' => 'The buyer chose this service on the marketplace', 'hint' => null];
        $group = self::group($ent, $p['country'] ?? null); $w = isset($p['weight_g']) ? (int) $p['weight_g'] : null; $v = isset($p['value']) ? (float) $p['value'] : null;
        $level = $map ? self::levelOf($map->service_text) : self::levelOf($p['service_text'] ?? null);
        $rules = DB::table('carrier_service_rules')->where(fn ($q) => $q->where('fk_id_ent', $ent)->orWhere('fk_id_ent', 0)->orWhereNull('fk_id_ent'))->where('f_active', 1)->orderBy('priority')->orderBy('id')->get();
        foreach ($rules as $r) {
            if ($r->fk_id_mp_profile && (int) $r->fk_id_mp_profile !== (int) $profile) continue;
            if ($r->fk_id_branch && (int) $r->fk_id_branch !== (int) ($shop->id ?? 0)) continue;
            if ($r->dest_country_group && strtoupper($r->dest_country_group) !== $group) continue;
            if ($r->weight_from_g !== null && ($w === null || $w < $r->weight_from_g)) continue;
            if ($r->weight_to_g !== null && ($w === null || $w > $r->weight_to_g)) continue;
            if ($r->max_length_cm !== null && isset($p['length_cm']) && (float) $p['length_cm'] > (float) $r->max_length_cm) continue;
            if ($r->max_girth_cm !== null && isset($p['girth_cm']) && (float) $p['girth_cm'] > (float) $r->max_girth_cm) continue;
            if ($r->value_from !== null && ($v === null || $v < (float) $r->value_from)) continue;
            if ($r->value_to !== null && ($v === null || $v >= (float) $r->value_to)) continue;      // "value < 50" = value_to 50
            if ($r->service_level && $level && strtoupper($r->service_level) !== $level) continue;
            $name = DB::table('t_carrier_pkg_svc')->where('id', $r->fk_id_car_pkg_svc)->value('pkg_svc_name');
            return ['service' => $r->fk_id_car_pkg_svc ? (int) $r->fk_id_car_pkg_svc : null, 'carrier' => $r->fk_id_carrier ? (int) $r->fk_id_carrier : null, 'label_template' => $r->fk_id_label_template ? (int) $r->fk_id_label_template : null,
                'rule' => (int) $r->id, 'why' => 'Rule #' . $r->id . ' (priority ' . $r->priority . ')' . ($name ? " → $name" : ''), 'hint' => null];
        }
        $def = Settings::get('DEFAULT_CARRIER_SERVICE', $ent);
        return ['service' => $def && $def !== 'NULL' ? (int) $def : null, 'carrier' => $shop->fk_id_default_carrier ?? null, 'label_template' => null, 'rule' => null, 'why' => 'No rule matches – the shop\'s default',
            'hint' => 'No carrier rule fits this parcel (' . $group . ($w ? ", {$w} g" : '') . ($v !== null ? ', value ' . number_format($v, 2) : '') . '). Add a rule under Carrier service rules.'];
    }

    /** The parcel facts of an order: destination, value, weight. */
    public static function facts(int $ent, string $orderNo, int $branch, ?int $weight = null): array
    {
        $l = DB::table('t_sales')->where('fk_id_ent', $ent)->where('order_no', $orderNo)->where('fk_id_branch', $branch)->where('status', 'Pending')->get(['ship_to_country_code', 'total_amount_gbp', 'carrier_service_text']);
        return ['branch' => $branch, 'country' => $l->pluck('ship_to_country_code')->filter()->first(), 'value' => round((float) $l->sum('total_amount_gbp'), 2), 'service_text' => $l->pluck('carrier_service_text')->filter()->first(), 'weight_g' => $weight];
    }
}
