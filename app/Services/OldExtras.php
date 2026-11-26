<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Owner 27-11 01:34: "everything of the old setup comes here – nothing that was there may be missing".
 * The last old tables that had no place yet (found by Old tool check) go to their place; each row once (old id kept), so it can run again:
 *   vpn_providers + vpn_servers → IP accounts list (cat_ip_burgers: IP, code, label, place, plan, provider) and a central list "IP / VPN providers"
 *   loc_warehouses              → the warehouse list (cat_warehouses) with its address, and the address on its stock location
 *   ota_lookups                 → central lists, one per lookup type ("Old lists: <type>")
 * Secret columns are never read.
 */
class OldExtras
{
    private static function rows(string $oldDb, string $t, array $cols): array
    {
        if (! preg_match('/^[A-Za-z0-9_]+$/', $oldDb) || $oldDb === DB::getDatabaseName()) throw new \InvalidArgumentException('Give the old database name (not this one).');
        if (! DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = ? AND table_name = ?', [$oldDb, $t])->n) return [];
        $have = array_map(fn ($c) => $c->c, DB::select('SELECT column_name AS c FROM information_schema.columns WHERE table_schema = ? AND table_name = ?', [$oldDb, $t]));
        $cols = array_values(array_filter($cols, fn ($c) => in_array($c, $have, true) && ! preg_match(OldImport::SECRET, $c)));
        return DB::select('SELECT ' . implode(', ', array_map(fn ($c) => "`$c`", $cols)) . " FROM `$oldDb`.`$t`");
    }

    private static function list(string $code, string $heading, string $dept, int $ent): int
    {
        return (int) (DB::table('cat_references')->where('ref_code', $code)->value('id') ?: DB::table('cat_references')->insertGetId(RecordService::fillNotNull('cat_references',
            ['ref_code' => $code, 'reference_heading' => $heading, 'department' => $dept, 'ref_type' => 'List', 'add_rule' => 'ANY', 'f_active' => 1, 'fk_id_ent' => 0, 'created_at' => now()])));
    }

    private static function item(int $list, string $name, string $legacy, int $id, int $ent, int $sort = 0): bool
    {
        $name = trim($name); if ($name === '') return false;
        if (DB::table('reference_items')->where('fk_reference_id', $list)->where(fn ($w) => $w->where(['legacy_table' => $legacy, 'legacy_id' => $id])->orWhereRaw('LOWER(reference_name) = ?', [mb_strtolower($name)]))->exists()) return false;
        DB::table('reference_items')->insert(RecordService::fillNotNull('reference_items', ['fk_reference_id' => $list, 'reference_name' => mb_substr($name, 0, 150), 'fk_id_ent' => $ent, 'sort_no' => $sort, 'f_active' => 1, 'legacy_table' => $legacy, 'legacy_id' => $id, 'created_at' => now()]));
        return true;
    }

    public static function bring(string $oldDb, int $ent, bool $dry = false): array
    {
        $done = ['vpn_providers' => 0, 'vpn_servers' => 0, 'loc_warehouses' => 0, 'ota_lookups' => 0];
        $run = function () use ($oldDb, $ent, &$done) {
            $prov = []; $pl = self::list('IP_PROVIDER', 'IP / VPN providers', 'Accounts', $ent);
            foreach (self::rows($oldDb, 'vpn_providers', ['id', 'provider_name', 'provider_code', 'service_type', 'website_url', 'f_active']) as $p) {
                $prov[(int) $p->id] = (string) $p->provider_name; if (self::item($pl, (string) $p->provider_name, 'vpn_providers', (int) $p->id, $ent)) $done['vpn_providers']++; }
            foreach (self::rows($oldDb, 'vpn_servers', ['id', 'fk_id_ent', 'fk_id_provider', 'server_code', 'ip_address', 'server_label', 'location', 'plan_name', 'notes', 'f_active']) as $s) {
                if (DB::table('cat_ip_burgers')->where(['legacy_table' => 'vpn_servers', 'legacy_id' => (int) $s->id])->exists()) continue;
                $ip = trim((string) $s->ip_address);
                $x = ['server_label' => $s->server_label, 'location' => $s->location, 'plan_name' => $s->plan_name, 'provider_name' => $prov[(int) $s->fk_id_provider] ?? null, 'notes' => $s->notes, 'legacy_table' => 'vpn_servers', 'legacy_id' => (int) $s->id];
                $same = $ip !== '' ? DB::table('cat_ip_burgers')->where('ip_burger_ip', $ip)->first() : null;      // the IP came already (old IP list): add what the server row knows
                if ($same) DB::table('cat_ip_burgers')->where('id', $same->id)->update(array_filter($x, fn ($v) => $v !== null && $v !== ''));
                else DB::table('cat_ip_burgers')->insert(RecordService::fillNotNull('cat_ip_burgers', $x + ['fk_id_ent' => (int) ($s->fk_id_ent ?: $ent), 'ip_burger_ip' => mb_substr($ip ?: (string) $s->server_code, 0, 50), 'isp_code' => $s->server_code, 'f_active' => (int) $s->f_active]));
                $done['vpn_servers']++;
            }
            foreach (self::rows($oldDb, 'loc_warehouses', ['id', 'fk_id_location', 'display_name', 'short_code', 'address_line1', 'address_line2', 'city', 'postcode', 'country']) as $w) {
                if (DB::table('cat_warehouses')->where('legacy_id', (int) $w->id)->exists()) continue;
                $addr = trim(implode(', ', array_filter([$w->address_line1, $w->address_line2, $w->city, $w->postcode, $w->country])));
                $loc = $w->fk_id_location ? (int) OldImport::mapped('cat_inventory_locations', (int) $w->fk_id_location) : null;
                if ($loc && DB::table('cat_inventory_locations')->where('id', $loc)->exists() && $addr !== '') DB::table('cat_inventory_locations')->where('id', $loc)->whereNull('address_text')->update(['address_text' => mb_substr($addr, 0, 500)]);
                $cty = $w->country ? DB::table('cat_country')->where(fn ($q) => $q->where('country_name', $w->country)->orWhere('iso_code', $w->country))->value('id') : null;
                DB::table('cat_warehouses')->insert(RecordService::fillNotNull('cat_warehouses', ['fk_id_ent' => $ent, 'warehouse_short' => mb_substr((string) ($w->short_code ?: $w->display_name), 0, 20), 'warehouse_name' => (string) $w->display_name,
                    'city' => $w->city, 'address' => trim(implode(', ', array_filter([$w->address_line1, $w->address_line2]))), 'postcode' => $w->postcode, 'fk_id_country' => $cty, 'fk_id_location' => $loc, 'legacy_id' => (int) $w->id, 'f_active' => 1, 'created_at' => now()]));
                $done['loc_warehouses']++;
            }
            foreach (self::rows($oldDb, 'ota_lookups', ['id', 'lookup_type', 'name', 'sort_order', 'f_active']) as $l) {
                $type = trim((string) $l->lookup_type) ?: 'other'; $code = 'OLD_' . mb_substr(strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $type)), 0, 40);
                if (self::item(self::list($code, 'Old list: ' . ucfirst(str_replace('_', ' ', $type)), 'Setup', $ent), (string) $l->name, 'ota_lookups', (int) $l->id, $ent, (int) $l->sort_order)) $done['ota_lookups']++;
            }
        };
        if ($dry) { DB::beginTransaction(); try { $run(); } finally { DB::rollBack(); } } else DB::transaction($run);
        return $done + ['dry' => $dry];
    }

    /** What of each table is here (for Old tool check). */
    public static function here(string $t): ?int
    {
        return match ($t) {
            'vpn_providers' => (int) DB::table('reference_items')->where('legacy_table', 'vpn_providers')->count() ?: (DB::table('cat_ip_burgers')->whereNotNull('provider_name')->exists() ? 1 : 0),
            'vpn_servers' => (int) DB::table('cat_ip_burgers')->where('legacy_table', 'vpn_servers')->count(),
            'loc_warehouses' => (int) DB::table('cat_warehouses')->whereNotNull('legacy_id')->count(),
            'ota_lookups' => (int) DB::table('reference_items')->where('legacy_table', 'ota_lookups')->count() + 0,
            default => null };
    }
}
