<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-H-6 locations and SKU homes by CSV – Import Center report types WH_LOCATIONS and WH_SKU_HOMES.
 * - export: the rows as they are now (also the template when there are none);
 * - check: reads the file and says what would happen – never writes;
 * - import: all rows that passed, in one transaction, as one batch (bulk_jobs);
 * - look-alike: a new code that differs from a code already there only by case, spaces, dashes or leading zeros
 *   (A-01-1 / A1-1 / a 01 1) is held back until the row says confirm_new = Y;
 * - undo: a location made by the batch goes only while it never held stock, has no home and no movement;
 *   a home goes back to what it was before the batch.
 */
class WarehouseCsv
{
    public const TYPES = ['WH_LOCATIONS' => ['site', 'parent', 'kind', 'code', 'name', 'zone_type', 'bin_type', 'pick_seq', 'capacity_units', 'pickable', 'receivable', 'active', 'deactivated_reason', 'confirm_new'],
        'WH_SKU_HOMES' => ['site', 'bin', 'sku', 'role', 'min_qty', 'max_qty']];

    private static function type(string $type): array { return self::TYPES[$type] ?? throw new \InvalidArgumentException('Report type is WH_LOCATIONS or WH_SKU_HOMES.'); }
    private static function line(array $v): string { return implode(',', array_map(fn ($x) => preg_match('/[",\n]/', (string) $x) ? '"' . str_replace('"', '""', (string) $x) . '"' : (string) $x, $v)); }
    /** The key used for the look-alike check: letters and digits only, upper case, no leading zeros in a number group. */
    public static function alike(string $code): string { return (string) preg_replace_callback('/\d+/', fn ($m) => ltrim($m[0], '0') ?: '0', strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', preg_replace('/(?<=\d)[^A-Za-z0-9]+(?=\d)/', 'x', $code)))); }

    private static function sites(int $ent): array
    {
        return DB::table('cat_inventory_locations')->where('kind', 'WAREHOUSE')->orderBy('id')->get()->filter(fn ($s) => (int) $s->fk_id_ent === $ent)->values()->all();
    }

    public static function export(int $ent, string $type): string
    {
        $cols = self::type($type); $out = [self::line($cols)];
        $sites = collect(self::sites($ent))->keyBy('id');
        if ($type === 'WH_LOCATIONS') {
            $rows = DB::table('cat_inventory_locations')->where('fk_id_ent', $ent)->whereIn('kind', ['ZONE', 'BIN'])->whereNull('system_role')->whereIn('fk_id_site', $sites->keys())->orderBy('fk_id_site')->orderByRaw("kind = 'BIN'")->orderBy('pick_seq')->orderBy('id')->get();
            $code = fn ($r) => (string) ($r->location_code ?: $r->location_name); $by = $rows->keyBy('id');
            foreach ($rows as $r) {
                $parent = (int) $r->fk_id_parent === (int) $r->fk_id_site ? '' : (isset($by[$r->fk_id_parent]) ? $code($by[$r->fk_id_parent]) : '');
                $out[] = self::line([$code($sites[$r->fk_id_site]), $parent, $r->kind, $code($r), $r->location_name, $r->zone_type, $r->bin_type, $r->pick_seq, $r->capacity_units, $r->f_pickable ? 'Y' : 'N', $r->f_receivable ? 'Y' : 'N', $r->f_active ? 'Y' : 'N', $r->deactivated_reason, '']);
            }
        } else {
            $rows = DB::table('t_product_locations as h')->join('cat_inventory_locations as l', 'l.id', '=', 'h.fk_id_location')->join('t_product_vendors as p', 'p.id', '=', 'h.fk_id_product')
                ->where('p.fk_id_ent', $ent)->where('h.fk_id_owner_seg', 0)->whereIn('h.home_role', ['PRIMARY', 'OVERFLOW'])->whereIn('l.fk_id_site', $sites->keys())->orderBy('p.SKU_VENDOR')->orderBy('h.home_role', 'desc')
                ->get(['l.fk_id_site', 'l.location_name', 'p.SKU_VENDOR', 'h.home_role', 'h.min_qty', 'h.max_qty']);
            foreach ($rows as $r) { $s = $sites[$r->fk_id_site]; $out[] = self::line([(string) ($s->location_code ?: $s->location_name), $r->location_name, $r->SKU_VENDOR, $r->home_role, $r->min_qty, $r->max_qty]); }
        }
        return implode("\n", $out) . "\n";
    }

    /** Reads the file. Returns the rows that can be applied, the log (one line per problem) and the look-alikes. Writes nothing. */
    public static function parse(int $ent, string $type, string $text): array
    {
        $cols = self::type($type);
        $lines = array_values(array_filter(preg_split('/\r\n|\r|\n/', (string) preg_replace('/^\xEF\xBB\xBF/', '', $text)), fn ($l) => trim($l) !== ''));
        if (count($lines) < 2) throw new \InvalidArgumentException('The file has no rows.');
        $head = array_map(fn ($h) => strtolower(trim((string) $h)), str_getcsv(array_shift($lines)));
        $need = $type === 'WH_LOCATIONS' ? ['site', 'kind', 'code'] : ['site', 'bin', 'sku', 'role'];
        if ($miss = array_diff($need, $head)) throw new \InvalidArgumentException('Column missing: ' . implode(', ', $miss) . '. Expected: ' . implode(', ', $cols) . '.');
        $sites = []; foreach (self::sites($ent) as $s) { $sites[strtoupper((string) $s->location_code)] = $s; $sites[strtoupper((string) $s->location_name)] ??= $s; }
        $rows = []; $log = []; $alike = []; $skipped = 0;
        $have = []; foreach (DB::table('cat_inventory_locations')->where('fk_id_ent', $ent)->whereIn('kind', ['ZONE', 'BIN'])->get(['id', 'fk_id_site', 'kind', 'location_name', 'location_code', 'fk_id_parent', 'system_role', 'f_active']) as $l) $have[(int) $l->fk_id_site][] = $l;
        $find = function (int $site, string $code, ?string $kind = null) use (&$have) { foreach ($have[$site] ?? [] as $l) if ((! $kind || $l->kind === $kind) && (strcasecmp((string) $l->location_code, $code) === 0 || strcasecmp((string) $l->location_name, $code) === 0)) return $l; return null; };
        $yn = fn ($v, $def) => ($v = strtoupper(trim((string) $v))) === '' ? $def : (in_array($v, ['Y', 'YES', '1', 'TRUE'], true) ? 1 : 0);
        $seen = [];
        foreach ($lines as $i => $line) {
            $n = $i + 2; $v = str_getcsv($line); $a = []; foreach ($head as $k => $h) $a[$h] = trim((string) ($v[$k] ?? ''));
            $bad = function (string $why) use (&$log, &$skipped, $n) { $log[] = "Row $n: $why"; $skipped++; };
            $site = $sites[strtoupper($a['site'] ?? '')] ?? null; if (! $site) { $bad('site "' . ($a['site'] ?? '') . '" not found.'); continue; }
            $sid = (int) $site->id;
            if ($type === 'WH_LOCATIONS') {
                $kind = strtoupper($a['kind']); $code = strtoupper((string) preg_replace('/\s+/', '', $a['code']));
                if (! in_array($kind, ['ZONE', 'BIN'], true)) { $bad('kind is ZONE or BIN.'); continue; }
                if ($code === '') { $bad('code is empty.'); continue; }
                if (isset($seen["$sid|$kind|$code"])) { $bad("$code is in the file twice."); continue; }
                $seen["$sid|$kind|$code"] = true;
                $parentCode = strtoupper($a['parent'] ?? ''); $parent = $sid;
                if ($parentCode !== '') {
                    $p = $find($sid, $parentCode, 'ZONE'); $inFile = isset($seen["$sid|ZONE|$parentCode"]);
                    if (! $p && ! $inFile) { $bad("parent zone $parentCode not found (put the zone row above its bins)."); continue; }
                    $parent = $p ? (int) $p->id : 'new:' . $parentCode;
                }
                $old = $find($sid, $code, $kind);
                if ($old && $old->system_role) { $bad("$code is a system bin – not changed by file."); continue; }
                $active = $yn($a['active'] ?? '', $old ? (int) $old->f_active : 1);
                if (! $old) {
                    $key = self::alike($code); $near = null;
                    foreach ($have[$sid] ?? [] as $l) if ($l->kind === $kind && self::alike((string) ($l->location_code ?: $l->location_name)) === $key) { $near = (string) ($l->location_code ?: $l->location_name); break; }
                    if ($near !== null && ! $yn($a['confirm_new'] ?? '', 0)) { $alike[] = ['row' => $n, 'code' => $code, 'looks_like' => $near]; $bad("$code looks like $near that is already there – use $near, or put Y in confirm_new to add it as a new one."); continue; }
                }
                if ($old && ! $active && (int) $old->f_active) {
                    $data = ['f_active' => 0, 'deactivated_reason' => $a['deactivated_reason'] ?? ''];
                    if ($e = WarehouseSites::check($data, (array) DB::table('cat_inventory_locations')->where('id', $old->id)->first(), (int) $old->id)) { $bad("$code: " . reset($e)); continue; }
                }
                $rows[] = ['n' => $n, 'site' => $sid, 'type_id' => (int) $site->fk_id_location_type, 'kind' => $kind, 'code' => $code, 'name' => ($a['name'] ?? '') !== '' ? $a['name'] : $code, 'parent' => $parent, 'id' => $old ? (int) $old->id : null,
                    'zone_type' => ($a['zone_type'] ?? '') ?: null, 'bin_type' => ($a['bin_type'] ?? '') ?: null, 'pick_seq' => ($a['pick_seq'] ?? '') !== '' ? (int) $a['pick_seq'] : null, 'capacity_units' => ($a['capacity_units'] ?? '') !== '' ? (int) $a['capacity_units'] : null,
                    'f_pickable' => $yn($a['pickable'] ?? '', 1), 'f_receivable' => $yn($a['receivable'] ?? '', 1), 'f_active' => $active, 'deactivated_reason' => $active ? null : (($a['deactivated_reason'] ?? '') ?: null)];
            } else {
                $role = strtoupper($a['role']); if (! in_array($role, ['PRIMARY', 'OVERFLOW'], true)) { $bad('role is PRIMARY or OVERFLOW.'); continue; }
                $bin = $find($sid, $a['bin'], 'BIN'); if (! $bin) { $bad('bin "' . $a['bin'] . '" not found in ' . $site->location_name . '.'); continue; }
                if (! $bin->f_active) { $bad($a['bin'] . ' is not active – a home is always an active bin.'); continue; }
                $p = DB::table('t_product_vendors')->where('fk_id_ent', $ent)->where('SKU_VENDOR', $a['sku'])->first(['id']); if (! $p) { $bad('SKU "' . $a['sku'] . '" not found.'); continue; }
                foreach (['min_qty', 'max_qty'] as $c) if (($a[$c] ?? '') !== '' && (! ctype_digit($a[$c]))) { $bad("$c must be a whole number."); continue 2; }
                $min = ($a['min_qty'] ?? '') !== '' ? (int) $a['min_qty'] : null; $max = ($a['max_qty'] ?? '') !== '' ? (int) $a['max_qty'] : null;
                if ($min !== null && $max !== null && $min > $max) { $bad('min cannot be more than max.'); continue; }
                if (isset($seen["$sid|{$p->id}|{$bin->id}"])) { $bad($a['sku'] . ' / ' . $a['bin'] . ' is in the file twice.'); continue; }
                $seen["$sid|{$p->id}|{$bin->id}"] = true;
                $rows[] = ['n' => $n, 'site' => $sid, 'bin' => (int) $bin->id, 'product' => (int) $p->id, 'sku' => $a['sku'], 'role' => $role, 'min' => $min, 'max' => $max];
            }
        }
        return ['rows' => $rows, 'log' => $log, 'skipped' => $skipped, 'alike' => $alike];
    }

    public static function check(int $ent, string $type, string $text): array
    {
        $p = self::parse($ent, $type, $text); $new = count(array_filter($p['rows'], fn ($r) => $type === 'WH_SKU_HOMES' || ! $r['id']));
        $what = $type === 'WH_LOCATIONS' ? "$new new location(s), " . (count($p['rows']) - $new) . ' to update' : count($p['rows']) . ' SKU home(s) to set';
        return ['rows' => count($p['rows']), 'skipped' => $p['skipped'], 'log' => $p['log'], 'alike' => $p['alike'], 'message' => "Check: $what, {$p['skipped']} row(s) held back. Nothing was saved."];
    }

    public static function import(int $ent, string $type, string $text, object $u): array
    {
        $p = self::parse($ent, $type, $text);
        if (! $p['rows']) throw new \InvalidArgumentException('No row can be loaded. ' . implode(' ', array_slice($p['log'], 0, 3)));
        return DB::transaction(function () use ($ent, $type, $p, $u) {
            $made = []; $changed = []; $log = $p['log']; $done = 0;
            if ($type === 'WH_LOCATIONS') {
                $newZones = [];
                foreach ($p['rows'] as $r) {
                    $parent = is_string($r['parent']) ? ($newZones[$r['site'] . '|' . substr($r['parent'], 4)] ?? $r['site']) : $r['parent'];
                    $data = ['location_name' => mb_substr($r['name'], 0, 150), 'location_code' => mb_substr($r['code'], 0, 30), 'kind' => $r['kind'], 'fk_id_parent' => $parent, 'fk_id_site' => $r['site'], 'zone_type' => $r['zone_type'], 'bin_type' => $r['bin_type'],
                        'pick_seq' => $r['pick_seq'], 'capacity_units' => $r['capacity_units'], 'f_pickable' => $r['f_pickable'], 'f_receivable' => $r['f_receivable'], 'f_active' => $r['f_active'], 'deactivated_reason' => $r['deactivated_reason']];
                    if ($r['id']) {
                        $old = (array) DB::table('cat_inventory_locations')->where('id', $r['id'])->first();
                        $diff = array_filter($data, fn ($v, $k) => (string) ($old[$k] ?? '') !== (string) ($v ?? ''), ARRAY_FILTER_USE_BOTH);
                        if (! $diff) continue;
                        DB::table('cat_inventory_locations')->where('id', $r['id'])->update($diff);
                        $ch = []; foreach ($diff as $k => $v) $ch[$k] = [$old[$k] ?? null, $v]; Audit::log('cat_inventory_locations', $r['id'], 'UPDATE', $ch, 'Import WH_LOCATIONS', $ent);
                        $changed[] = ['id' => $r['id'], 'old' => array_intersect_key($old, $diff)];
                        if ($note = WarehouseSites::afterSave($r['id'], $old)) $log[] = "Row {$r['n']}: {$r['code']} renamed. $note";
                    } else {
                        $id = (int) DB::table('cat_inventory_locations')->insertGetId(RecordService::fillNotNull('cat_inventory_locations', array_filter($data, fn ($v) => $v !== null) + ['fk_id_location_type' => $r['type_id'], 'fk_id_ent' => $ent, 'f_sellable' => 1,
                            'barcode_value' => $r['kind'] === 'BIN' ? mb_substr($r['code'], 0, 60) : null, 'created_by' => $u->id, 'created_at' => now()]));
                        if ($r['kind'] === 'ZONE') $newZones[$r['site'] . '|' . $r['code']] = $id;
                        Audit::log('cat_inventory_locations', $id, 'INSERT', ['import' => 'WH_LOCATIONS', 'code' => $r['code']], null, $ent);
                        $made[] = $id;
                    }
                    $done++;
                }
            } else {
                foreach ($p['rows'] as $r) {
                    $before = DB::table('t_product_locations')->where('fk_id_product', $r['product'])->where('fk_id_location', $r['bin'])->where('fk_id_owner_seg', 0)->first();
                    try { $hid = SkuHomes::set($ent, $r['product'], $r['bin'], $r['role'], $r['min'], $r['max']); }
                    catch (\InvalidArgumentException $e) { $log[] = "Row {$r['n']}: " . $e->getMessage(); continue; }
                    if ($before) $changed[] = ['id' => (int) $before->id, 'old' => ['home_role' => $before->home_role, 'min_qty' => $before->min_qty, 'max_qty' => $before->max_qty]]; else $made[] = $hid;
                    $done++;
                }
            }
            $job = (int) DB::table('bulk_jobs')->insertGetId(['job_no' => 'WH-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5)), 'list_key' => strtolower($type), 'fk_id_account' => $u->id,
                'change_json' => json_encode(['made' => $made, 'changed' => $changed]), 'row_count' => $done, 'status' => 'APPLIED', 'created_at' => now(), 'applied_at' => now()]);
            return ['job' => $job, 'done' => $done, 'created' => count($made), 'updated' => count($changed), 'skipped' => count($p['rows']) - $done + $p['skipped'], 'log' => $log,
                'message' => "Done: " . count($made) . ' created, ' . count($changed) . ' updated' . (count($log) ? ', ' . count($log) . ' note(s) – see the list' : '') . '.'];
        });
    }

    public static function undo(int $ent, int $jobId): array
    {
        $j = DB::table('bulk_jobs')->where('id', $jobId)->whereIn('list_key', ['wh_locations', 'wh_sku_homes'])->first() ?? throw new \InvalidArgumentException('Batch not found.');
        if ($j->status !== 'APPLIED') throw new \InvalidArgumentException('This batch was already undone.');
        $c = json_decode((string) $j->change_json, true); $table = $j->list_key === 'wh_locations' ? 'cat_inventory_locations' : 't_product_locations';
        return DB::transaction(function () use ($j, $c, $table, $ent) {
            $gone = 0; $back = 0; $kept = [];
            foreach (array_reverse($c['made']) as $id) {
                if ($table === 'cat_inventory_locations') {
                    $l = DB::table($table)->where('id', $id)->where('fk_id_ent', $ent)->first(['id', 'location_name']); if (! $l) continue;
                    if (WarehouseSites::hasHistory($id) || DB::table('t_product_locations')->where('fk_id_location', $id)->exists() || DB::table($table)->where('fk_id_parent', $id)->whereNotIn('id', $c['made'])->exists()) { $kept[] = $l->location_name; continue; }
                    DB::table($table)->where('id', $id)->delete(); Audit::log($table, $id, 'DELETE', ['location_name' => $l->location_name], 'Undo of batch ' . $j->job_no, $ent);
                } else DB::table($table)->where('id', $id)->delete();
                $gone++;
            }
            foreach ($c['changed'] as $x) { if (DB::table($table)->where('id', $x['id'])->update($x['old'])) $back++; }
            DB::table('bulk_jobs')->where('id', $j->id)->update(['status' => 'UNDONE', 'undone_at' => now()]);
            return ['removed' => $gone, 'restored' => $back, 'kept' => $kept, 'message' => "Undone: $gone removed, $back put back" . ($kept ? ' · kept (already used – deactivate instead): ' . implode(', ', $kept) : '') . '.'];
        });
    }
}
