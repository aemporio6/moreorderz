<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Makes bins in one go: zone × levels × positions, coded by the company settings (WH_LOC_FORMAT, separator, padding, level style). */
class LocationGenerator
{
    /** Preview or create. $p: parent (location id), zone (text), levels (count), positions (count), start_level, start_position. */
    public static function run(int $ent, array $p, bool $create): array
    {
        $levels = (int) $p['levels']; $positions = (int) $p['positions'];
        if ($levels < 1 || $positions < 1 || $levels * $positions > 2000) throw new \InvalidArgumentException('Levels × positions must be between 1 and 2,000.');
        $zone = strtoupper(trim((string) $p['zone']));
        if ($zone === '' || ! preg_match('/^[A-Z0-9]{1,6}$/', $zone)) throw new \InvalidArgumentException('Zone: 1 to 6 letters or digits.');
        $parent = DB::table('cat_inventory_locations')->where('id', (int) $p['parent'])->where('fk_id_ent', $ent)->first() ?? throw new \RuntimeException('Parent location not found in this company.');
        $fmt = (string) Settings::get('WH_LOC_FORMAT', $ent, '{ZONE}-{LEVEL}-{BIN}'); $sep = (string) Settings::get('WH_LOC_SEPARATOR', $ent, '-');
        $pad = (int) Settings::get('WH_LOC_PAD', $ent, 0); $letters = Settings::get('WH_LOC_LEVEL_STYLE', $ent, 'LETTER') === 'LETTER';
        $codes = []; $seq = (int) DB::table('cat_inventory_locations')->where('fk_id_ent', $ent)->max('pick_seq');
        for ($l = 0; $l < $levels; $l++) {
            $lv = $letters ? chr(ord('A') + ((int) ($p['start_level'] ?? 1) - 1 + $l) % 26) : (string) ((int) ($p['start_level'] ?? 1) + $l);
            for ($b = 0; $b < $positions; $b++) {
                $bin = str_pad((string) ((int) ($p['start_position'] ?? 1) + $b), max($pad, 2), '0', STR_PAD_LEFT);
                $codes[] = ['code' => str_replace('-', $sep, strtr($fmt, ['{ZONE}' => $zone, '{LEVEL}' => $lv, '{BIN}' => $bin])), 'level' => $lv, 'pos' => $bin];
            }
        }
        $exists = DB::table('cat_inventory_locations')->where('fk_id_ent', $ent)->whereIn('location_code', array_column($codes, 'code'))->pluck('location_code')->all();
        $new = array_values(array_filter($codes, fn ($c) => ! in_array($c['code'], $exists, true)));
        if (! $create) return ['codes' => array_column($codes, 'code'), 'already_there' => $exists, 'to_create' => count($new)];
        DB::transaction(function () use ($new, $ent, $parent, &$seq) {
            foreach ($new as $c) {
                DB::table('cat_inventory_locations')->insert(['location_name' => $c['code'], 'fk_id_location_type' => (int) $parent->fk_id_location_type, 'fk_id_parent' => $parent->id, 'f_active' => 1, 'created_by' => Context::userId(), 'created_at' => now(),
                    'fk_id_ent' => $ent, 'location_code' => $c['code'], 'kind' => 'BIN', 'f_sellable' => 1, 'level_code' => $c['level'], 'position_code' => $c['pos'], 'pick_seq' => ++$seq, 'f_pickable' => 1, 'f_receivable' => 1, 'barcode_value' => $c['code'], 'fk_id_site' => $parent->fk_id_site ?? $parent->id]);
            }
        });
        Audit::log('cat_inventory_locations', (int) $parent->id, 'INSERT', ['bins_created' => count($new), 'zone' => $p['zone']], null, $ent);
        return ['created' => count($new), 'skipped' => count($exists),
            'ids' => $new ? DB::table('cat_inventory_locations')->where('fk_id_ent', $ent)->whereIn('location_code', array_column($new, 'code'))->pluck('id')->all() : []];
    }
}
