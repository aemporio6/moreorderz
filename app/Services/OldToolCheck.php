<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Owner 27-11 01:30: "look inside my old MoreOrderz tool – what is still left – put it in the checklist and finish it".
 * Two sides, on one screen (Setup › Old tool check):
 *   screens – every old controller (138) with its register lines and what is still open (docs/OLD_TOOL_MAP.json, made from the old code + the register audit)
 *   data    – every old table with rows: how many rows came over (the import report of mo:import-old, plus a live count of the old database by mo:old-check)
 */
class OldToolCheck
{
    public const FILE = 'old_check.json';
    /** Old tables that are not business data, with the reason (they stay out on purpose). */
    public const NOT_NEEDED = ['sidebar_modules_001' => 'an old copy of the menu – the new tool has its own menu', 'icl_settings' => 'a setting of the old website plugin (WordPress), not of the business',
        'ota_pw_log' => 'a log of passwords – passwords are never copied'];
    /** Old tables whose job the new tool does in its own way. */
    public const REPLACED = ['t_stock_movements' => 'the new tool keeps its own stock ledger; the old stock came in as opening lots'];

    /** Live count of the old database (artisan mo:old-check --from=<old db>): old rows per table, the same-name table here, the import report. Read only. */
    public static function count(string $oldDb): array
    {
        if (! preg_match('/^[A-Za-z0-9_]+$/', $oldDb) || $oldDb === DB::getDatabaseName()) throw new \InvalidArgumentException('Give the old database name (not this one).');
        $new = DB::getDatabaseName(); $rows = [];
        $rep = DB::table('mo_import_report')->orderBy('id')->get()->groupBy('old_table');
        foreach (DB::select('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = ? AND table_type = ?', [$oldDb, 'BASE TABLE']) as $x) {
            $t = (string) $x->t; if (str_starts_with($t, 'wp_') || ! preg_match('/^[A-Za-z0-9_]+$/', $t)) continue;
            $n = (int) DB::selectOne("SELECT COUNT(*) AS n FROM `$oldDb`.`$t`")->n; if ($n === 0) continue;
            $here = (bool) DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = ? AND table_name = ?', [$new, $t])->n;
            $r = ($rep[$t] ?? collect())->sortBy(fn ($y) => [(int) $y->f_dry, -((int) $y->imported + (int) $y->already_there + (int) $y->merged)])->first();
            $cols = array_map(fn ($c) => $c->c, DB::select('SELECT column_name AS c FROM information_schema.columns WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position', [$oldDb, $t]));
            $extra = OldExtras::here($t);      // the last tables brought to their place by mo:old-extras
            if ($extra !== null) { $r = (object) ['plan' => 'EXTRA', 'imported' => $extra, 'already_there' => 0, 'merged' => 0, 'failed' => 0, 'f_dry' => 0, 'note' => 'brought to its place (IP / VPN list, warehouse list, central lists)'];
                if ($t === 'ota_lookups') $n = (int) DB::selectOne("SELECT COUNT(DISTINCT lookup_type, LOWER(TRIM(name))) AS n FROM `$oldDb`.`ota_lookups` WHERE TRIM(name) <> ''")->n; }
            $rows[] = ['table' => $t, 'columns' => array_values(array_filter($cols, fn ($c) => ! preg_match(OldImport::SECRET, $c))), 'old' => $n, 'here' => $here ? (int) DB::table($t)->count() : null, 'plan' => $r->plan ?? OldImport::plan($t),
                'imported' => $r ? (int) $r->imported + (int) $r->already_there + (int) $r->merged : null, 'failed' => $r ? (int) $r->failed : null, 'dry_only' => $r ? (bool) $r->f_dry : null, 'note' => $r->note ?? null];
        }
        $out = ['at' => now()->toDateTimeString(), 'tables' => $rows];
        Storage::disk('local')->put(self::FILE, json_encode($out));
        return $out;
    }

    public static function page(): array
    {
        $map = json_decode((string) @file_get_contents(base_path('docs/OLD_TOOL_MAP.json')), true) ?: ['controllers' => []];
        $data = Storage::disk('local')->exists(self::FILE) ? json_decode((string) Storage::disk('local')->get(self::FILE), true) : null;
        $tables = [];
        foreach ($data['tables'] ?? [] as $t) {
            $got = max((int) ($t['imported'] ?? 0), $t['plan'] === 'COPY' ? (int) ($t['here'] ?? 0) : 0);
            if (isset(self::NOT_NEEDED[$t['table']])) $t['note'] = self::NOT_NEEDED[$t['table']]; if (isset(self::REPLACED[$t['table']])) $t['note'] = self::REPLACED[$t['table']];
            $state = isset(self::REPLACED[$t['table']]) ? 'REPLACED' : (isset(self::NOT_NEEDED[$t['table']]) || $t['plan'] === 'TOOL' ? 'NOT_NEEDED' : ($t['plan'] === 'RETIRED' ? 'RETIRED' : ($got >= $t['old'] ? 'CAME' : ($got > 0 ? 'PART' : 'NOT_YET'))));
            $tables[] = $t + ['got' => $got, 'state' => $state];
        }
        $order = ['NOT_YET' => 0, 'PART' => 1, 'CAME' => 2, 'REPLACED' => 3, 'RETIRED' => 4, 'NOT_NEEDED' => 5];
        usort($tables, fn ($a, $b) => [$order[$a['state']], -$a['old']] <=> [$order[$b['state']], -$b['old']]);
        $ctr = $map['controllers'];
        usort($ctr, fn ($a, $b) => [-count($a['open']), $a['controller']] <=> [-count($b['open']), $b['controller']]);
        return ['screens' => $ctr, 'screens_open' => array_sum(array_map(fn ($c) => count($c['open']), $ctr)), 'controllers' => count($ctr),
            'tables' => $tables, 'counted_at' => $data['at'] ?? null, 'data_open' => count(array_filter($tables, fn ($t) => in_array($t['state'], ['NOT_YET', 'PART'], true)))];
    }
}
