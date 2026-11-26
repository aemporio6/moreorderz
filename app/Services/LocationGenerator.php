<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Makes bins in one go: zone × levels × positions, coded by the company settings (WH_LOC_FORMAT, separator, padding, level style). */
class LocationGenerator
{
    /** Preview or create. $p: parent (location id), zone (text), levels (count), positions (count), start_level, start_position. */
    /** The company's code settings (R-H-4), or the ones given (for the live preview before saving). */
    public static function set(int $ent, array $over = []): array
    {
        $g = fn (string $k, $d) => array_key_exists($k, $over) && $over[$k] !== null ? $over[$k] : Settings::get($k, $ent, $d);
        return ['fmt' => (string) $g('WH_LOC_FORMAT', '{ZONE}-{LEVEL}-{BIN}'), 'sep' => (string) $g('WH_LOC_SEPARATOR', '-'), 'pad' => max(0, min(4, (int) $g('WH_LOC_PAD', 0))), 'letters' => strtoupper((string) $g('WH_LOC_LEVEL_STYLE', 'LETTER')) === 'LETTER'];
    }

    /** One bin code: tokens {SITE} {ZONE} {LEVEL} {BIN} {POS}; padding 0 = none, 2 = "02"; the separator replaces "-"; always upper case. $level and $pos start at 1. */
    public static function code(array $set, string $site, string $zone, int $level, int $pos): string
    {
        $lv = $set['letters'] ? chr(ord('A') + ($level - 1) % 26) : ($set['pad'] ? str_pad((string) $level, $set['pad'], '0', STR_PAD_LEFT) : (string) $level);
        $n = $set['pad'] ? str_pad((string) $pos, $set['pad'], '0', STR_PAD_LEFT) : (string) $pos;
        return mb_strtoupper(str_replace('-', $set['sep'], strtr(mb_strtoupper($set['fmt']), ['{SITE}' => $site, '{ZONE}' => $zone, '{LEVEL}' => $lv, '{BIN}' => $n, '{POS}' => $n])));
    }

    /** Codes compare without case and without separators: "w3-b-2" = "W3 B 2" = "W3B2". */
    public static function norm(string $code): string { return preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($code)); }

    /** The live preview of the settings page: three sample codes. */
    public static function preview(int $ent, array $over = []): string
    {
        $s = self::set($ent, $over);
        return implode(' · ', [self::code($s, 'WH1', 'W3', 2, 2), self::code($s, 'WH1', 'R1', 1, 1), self::code($s, 'WH1', 'C1', 1, 1)]);
    }

    private static function siteCode(int $site): string { $r = DB::table('cat_inventory_locations')->where('id', $site)->first(['location_code', 'location_name']); return mb_strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) (($r->location_code ?? '') ?: ($r->location_name ?? '')))); }

    /**
     * "Rename all to the new format": bins that were made by the generator (they carry zone, level and position) get the code of the format in force now.
     * $apply = false is the preview and writes nothing. One bulk job with Undo; only the shelf labels of the renamed bins are printed again.
     */
    public static function rename(int $ent, bool $apply, object $u): array
    {
        if ($apply && ! Perm::has($u, 'stock.bins')) throw new \InvalidArgumentException('Your role cannot rename bins.');
        $set = self::set($ent); $plan = []; $skip = []; $taken = [];
        $bins = DB::table('cat_inventory_locations')->where('fk_id_ent', $ent)->where('kind', 'BIN')->orderBy('id')->get(['id', 'location_code', 'location_name', 'fk_id_site', 'fk_id_parent', 'level_code', 'position_code', 'zone_code_gen']);
        foreach ($bins as $b) $taken[(int) $b->fk_id_site][self::norm((string) $b->location_code)] = (int) $b->id;
        foreach ($bins as $b) {
            if ($b->zone_code_gen === null || $b->zone_code_gen === '' || (string) $b->level_code === '' || (string) $b->position_code === '') continue;      /* typed by hand – never renamed */
            $lv = ctype_alpha((string) $b->level_code) ? ord(strtoupper($b->level_code[0])) - 64 : (int) $b->level_code;
            $new = self::code($set, self::siteCode((int) $b->fk_id_site), (string) $b->zone_code_gen, max(1, $lv), max(1, (int) $b->position_code)); if ($new === (string) $b->location_code) continue;
            $n = self::norm($new); $site = (int) $b->fk_id_site;
            if (isset($taken[$site][$n]) && $taken[$site][$n] !== (int) $b->id) { $skip[] = "{$b->location_code}: $new is already used in this site"; continue; }
            unset($taken[$site][self::norm((string) $b->location_code)]); $taken[$site][$n] = (int) $b->id;
            $plan[] = ['id' => (int) $b->id, 'old' => (string) $b->location_code, 'old_name' => (string) $b->location_name, 'new' => $new];
        }
        $out = ['applied' => false, 'changes' => array_map(fn ($x) => ['old' => $x['old'], 'new' => $x['new']], array_slice($plan, 0, 300)), 'count' => count($plan), 'skipped' => $skip, 'preview' => self::preview($ent),
            'message' => count($plan) . ' bin(s) would get a new code' . ($skip ? ', ' . count($skip) . ' cannot (see the list)' : '') . '. Nothing is changed yet.'];
        if (! $apply || ! $plan) return $out;
        $job = DB::transaction(function () use ($plan, $u, $ent) {
            foreach ($plan as $x) { DB::table('cat_inventory_locations')->where('id', $x['id'])->update(['location_code' => $x['new'], 'location_name' => $x['old_name'] === $x['old'] ? $x['new'] : $x['old_name'], 'barcode_value' => $x['new']]);
                Audit::log('cat_inventory_locations', $x['id'], 'UPDATE', ['location_code' => [$x['old'], $x['new']]], 'Rename all to the new format', $ent); }
            return (int) DB::table('bulk_jobs')->insertGetId(['job_no' => 'BR-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5)), 'list_key' => 'bin_rename', 'fk_id_account' => $u->id, 'change_json' => json_encode(['bins' => $plan]),
                'row_count' => count($plan), 'status' => 'APPLIED', 'created_at' => now(), 'applied_at' => now()]);
        });
        $pj = PrintService::auto($ent, 'WH_AUTOPRINT_SHELF', 'LOCATION', array_column($plan, 'id'), 'AUTO_SHELF');      /* only the renamed bins */
        return ['applied' => true, 'job' => $job, 'message' => count($plan) . ' bin(s) renamed' . ($pj && ($pj['status'] ?? '') === 'PRINTED' ? ' – their shelf labels are ready to print.' : '.')] + ($pj && ($pj['status'] ?? '') === 'PRINTED' ? ['print' => $pj + ['url' => "/api/print-jobs/{$pj['job']}/pdf"]] : []) + $out;
    }

    public static function renameUndo(int $jobId, object $u): array
    {
        if (! Perm::has($u, 'stock.bins')) throw new \InvalidArgumentException('Your role cannot rename bins.');
        $j = DB::table('bulk_jobs')->where('id', $jobId)->where('list_key', 'bin_rename')->first() ?? throw new \InvalidArgumentException('Batch not found.');
        if ($j->status !== 'APPLIED') throw new \InvalidArgumentException('This batch was already undone.'); $ent = (int) Context::ent();
        $plan = json_decode((string) $j->change_json, true)['bins'] ?? [];
        DB::transaction(function () use ($plan, $j, $ent) {
            foreach ($plan as $x) { DB::table('cat_inventory_locations')->where('id', $x['id'])->where('fk_id_ent', $ent)->update(['location_code' => $x['old'], 'location_name' => $x['old_name'], 'barcode_value' => $x['old']]);
                Audit::log('cat_inventory_locations', (int) $x['id'], 'UPDATE', ['location_code' => [$x['new'], $x['old']]], 'Undo of rename ' . $j->job_no, $ent); }
            DB::table('bulk_jobs')->where('id', $j->id)->update(['status' => 'UNDONE', 'undone_at' => now()]);
        });
        return ['message' => 'Undone: ' . count($plan) . ' bin(s) have their old code again.'];
    }

    public static function run(int $ent, array $p, bool $create): array
    {
        $levels = (int) $p['levels']; $positions = (int) $p['positions'];
        if ($levels < 1 || $positions < 1 || $levels * $positions > 2000) throw new \InvalidArgumentException('Levels × positions must be between 1 and 2,000.');
        $zone = strtoupper(trim((string) $p['zone']));
        if ($zone === '' || ! preg_match('/^[A-Z0-9]{1,6}$/', $zone)) throw new \InvalidArgumentException('Zone: 1 to 6 letters or digits.');
        $parent = DB::table('cat_inventory_locations')->where('id', (int) $p['parent'])->where('fk_id_ent', $ent)->first() ?? throw new \RuntimeException('Parent location not found in this company.');
        $site = WarehouseSites::siteOf((int) $parent->id); if (! $site) throw new \InvalidArgumentException('Choose a site (or a zone of a site) as the parent – bins always sit in a site (R-H-2).');
        $set = self::set($ent); $sc = self::siteCode($site);
        $codes = []; $seq = (int) DB::table('cat_inventory_locations')->where('fk_id_ent', $ent)->max('pick_seq');
        for ($l = 0; $l < $levels; $l++) {
            $ln = (int) ($p['start_level'] ?? 1) + $l; $lv = $set['letters'] ? chr(ord('A') + ($ln - 1) % 26) : (string) $ln;
            for ($b = 0; $b < $positions; $b++) { $pn = (int) ($p['start_position'] ?? 1) + $b; $codes[] = ['code' => self::code($set, $sc, $zone, $ln, $pn), 'level' => $lv, 'pos' => (string) $pn]; }
        }
        /* unique per site, without case and with the separators normalised (R-H-4) */
        $have = []; foreach (DB::table('cat_inventory_locations')->where('fk_id_site', $site)->where('kind', 'BIN')->pluck('location_code') as $c) $have[self::norm((string) $c)] = (string) $c;
        $exists = array_values(array_map(fn ($c) => $c['code'], array_filter($codes, fn ($c) => isset($have[self::norm($c['code'])]))));
        $new = array_values(array_filter($codes, fn ($c) => ! isset($have[self::norm($c['code'])])));
        if (! $create) return ['codes' => array_column($codes, 'code'), 'already_there' => $exists, 'to_create' => count($new)];
        DB::transaction(function () use ($new, $ent, $parent, $site, $zone, &$seq) {
            foreach ($new as $c) {
                DB::table('cat_inventory_locations')->insert(['location_name' => $c['code'], 'fk_id_location_type' => (int) $parent->fk_id_location_type, 'fk_id_parent' => $parent->id, 'f_active' => 1, 'created_by' => Context::userId(), 'created_at' => now(),
                    'fk_id_ent' => $ent, 'location_code' => $c['code'], 'kind' => 'BIN', 'f_sellable' => 1, 'level_code' => $c['level'], 'position_code' => $c['pos'], 'zone_code_gen' => $zone, 'pick_seq' => ++$seq, 'f_pickable' => 1, 'f_receivable' => 1, 'barcode_value' => $c['code'], 'fk_id_site' => $site]);      /* R-H-2 */
            }
        });
        Audit::log('cat_inventory_locations', (int) $parent->id, 'INSERT', ['bins_created' => count($new), 'zone' => $p['zone']], null, $ent);
        return ['created' => count($new), 'skipped' => count($exists),
            'ids' => $new ? DB::table('cat_inventory_locations')->where('fk_id_ent', $ent)->whereIn('location_code', array_column($new, 'code'))->pluck('id')->all() : []];
    }
}
