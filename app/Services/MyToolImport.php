<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Owner 26-11: MY TOOL's purchase history (MYTOOL-DATA.json from its Drive folder) into Old purchases (R-B_SOURCING-5 / 8 / 13 engine).
 * Every MY TOOL purchase becomes one HISTORICAL cycle + PO with its lines, its payments (deposit / balance / full / legacy allocations) and the freight
 * of its shipments; nothing is received – stock comes from the opening stock of the old-data import, so no unit is counted twice.
 * MY TOOL suppliers and forwarders are made as masters first (code MT-<code>, exact name) – the only place a supplier is made by an import, listed in the log.
 * check() reads and writes nothing; import() runs entry by entry and skips a purchase already imported (same supplier + order reference).
 */
class MyToolImport
{
    public static function read(string $json): array
    {
        $d = json_decode($json, true);
        if (! is_array($d) || ! isset($d['data']['purchases'])) throw new \InvalidArgumentException('This is not a MY TOOL data file (MYTOOL-DATA.json).');
        return $d['data'];
    }

    private static function num($v): float { return is_numeric($v) ? (float) $v : 0.0; }
    private static function cur($v): string { $c = strtoupper(trim((string) $v)); return $c === 'RMB' ? 'CNY' : $c; }      // MY TOOL writes RMB; the tool's code is CNY
    private static function str($v): string { $v = trim((string) $v); return in_array($v, ['None', 'null'], true) ? '' : $v; }

    /** [order_ref => entry] in the shape HistoricalPurchase::entry takes (supplier filled in by import()). */
    /** [MY TOOL no => [refs, date, total]] of the last entries() call – the supplier order numbers used to find the same order in the old MoreOrderz data. */
    public static array $refs = [];

    public static function entries(array $d): array
    {
        $parties = $d['parties'] ?? []; $ships = $d['shipments'] ?? []; $out = []; $matchRefs = [];
        $cos = []; foreach (($d['setup']['main']['companies'] ?? []) as $c) $cos[$c['id']] = $c['name'] ?? '';
        $pays = [];      // payments per purchase: allocations to a PO, else forRef
        foreach ($d['payments'] ?? [] as $p) {
            if (($p['direction'] ?? 'out') !== 'out' || ! empty($p['deletedAt'])) continue;
            $allocs = $p['allocations'] ?? []; if (! $allocs && self::str($p['forRef'] ?? '') !== '') $allocs = [['ref' => $p['forRef'], 'amount' => $p['amount']]];
            foreach ($allocs as $a) if (! empty($a['ref'])) $pays[(string) $a['ref']][] = ['amount' => self::num($a['amount']), 'currency' => self::cur(self::str($p['cur'] ?? '')), 'date' => self::str($p['date'] ?? ''), 'type' => (string) ($p['type'] ?? ''), 'ref' => self::str($p['no'] ?? ''), 'method' => self::str($p['platform'] ?? '')];
        }
        foreach ($d['purchases'] as $no => $po) {
            if (! empty($po['deletedAt'])) continue;
            $lines = [];
            foreach ($po['lines'] ?? [] as $l) $lines[] = ['line_type' => 'PRODUCT', 'sku' => self::str($l['sku'] ?? ''), 'product_name' => self::str($l['p'] ?? '') ?: self::str($l['sku'] ?? ''), 'qty' => self::num($l['q'] ?? 0), 'unit_price' => self::num($l['u'] ?? 0), 'notes' => self::str($l['n'] ?? '') ?: null];
            foreach ($po['extras'] ?? [] as $x) if (self::num($x['a'] ?? $x['amount'] ?? 0) > 0) $lines[] = ['line_type' => 'FEE', 'product_name' => self::str($x['p'] ?? $x['label'] ?? 'Extra'), 'qty' => 1, 'unit_price' => self::num($x['a'] ?? $x['amount'] ?? 0)];
            $goods = array_filter($pays[$no] ?? [], fn ($p) => ! in_array($p['type'], ['freight', 'inspection'], true));
            if (! $lines) {      // an old purchase kept as money only: one line of its paid value, product unknown
                $sum = array_sum(array_column($goods, 'amount'));
                if ($sum <= 0) continue;
                $lines[] = ['line_type' => 'PRODUCT', 'sku' => '', 'product_name' => self::str($po['desc'] ?? '') && self::str($po['desc']) !== '(see notes)' ? self::str($po['desc']) : 'Old purchase ' . $no, 'qty' => 1, 'unit_price' => round($sum, 2), 'notes' => 'MY TOOL kept no lines – value = what was paid'];
            }
            $freight = []; $ship = null;
            foreach ($ships as $sh) {
                $linked = in_array($no, (array) ($sh['purchases'] ?? []), true) || collect($sh['lines'] ?? [])->contains(fn ($l) => ($l['po'] ?? null) === $no);
                if (! $linked) continue; $ship ??= $sh;
                $n = max(1, count((array) ($sh['purchases'] ?? [])));
                foreach ($pays[$sh['no']] ?? [] as $fp) $freight[] = ['cost' => round($fp['amount'] / $n, 2), 'currency' => $fp['currency']];
            }
            $party = $parties[$po['party'] ?? ''] ?? null;
            preg_match_all('/[A-Z]{2,}[A-Z0-9-]*\d{6,}/', strtoupper(self::str($po['piNo'] ?? '') . ' / ' . self::str($po['yourRef'] ?? '')), $rm);
            $matchRefs[$no] = ['refs' => array_values(array_unique($rm[0])), 'date' => self::str($po['date'] ?? ''), 'total' => round(array_sum(array_map(fn ($l) => self::num($l['a'] ?? 0), $po['lines'] ?? [])), 2)];
            $out[$no] = ['source' => 'MYTOOL', 'supplier_code' => (string) ($po['party'] ?? ''), 'supplier_name' => $party['name'] ?? (string) ($po['party'] ?? ''), 'order_date' => self::str($po['date'] ?? ''), 'order_ref' => $no,
                'order_ref_note' => self::str($po['oldRef'] ?? '') ?: (self::str($po['piNo'] ?? '') ?: null), 'currency' => self::cur(self::str($po['cur'] ?? '')), 'rate' => null, 'owner_name' => $cos[$po['account'] ?? ''] ?? '', 'brand' => null,
                'notes' => trim('MY TOOL ' . $no . ($po['status'] ?? '' ? ' · ' . $po['status'] : '') . (self::str($po['folder'] ?? '') && self::str($po['folder']) !== 'https://drive.google.com/' ? ' · ' . self::str($po['folder']) : '')),
                'payments' => array_values(array_map(fn ($p) => ['amount' => $p['amount'], 'currency' => $p['currency'], 'date' => $p['date'], 'ref' => $p['ref'], 'method' => $p['method']], $goods)), 'lines' => $lines,
                'shipping' => ['shipped_date' => $ship ? self::str($ship['date'] ?? '') : null, 'arrived_date' => $ship ? (self::str($ship['arrival'] ?? '') ?: null) : null, 'mode' => $ship ? strtoupper(self::str($ship['mode'] ?? '')) : null,
                    'forwarder' => $ship ? ($parties[$ship['party'] ?? '']['name'] ?? null) : null, 'tracking' => null, 'freight' => $freight, 'duty' => 0, 'other' => 0], 'received' => ['date' => null, 'warehouse' => null]];
        }
        foreach ($ships as $sh) {      // a shipment linked to no purchase: its freight is kept as its own old entry with the forwarder
            if (! empty($sh['deletedAt']) || ! empty($sh['purchases']) || collect($sh['lines'] ?? [])->contains(fn ($l) => ! empty($l['po']))) continue;
            $fp = $pays[$sh['no']] ?? []; if (! $fp) continue;
            $fw = $parties[$sh['party'] ?? ''] ?? null; $cur = $fp[0]['currency'] ?: 'USD';
            $out[$sh['no']] = ['source' => 'MYTOOL', 'supplier_code' => (string) ($sh['party'] ?? ''), 'supplier_name' => $fw['name'] ?? (string) ($sh['party'] ?? ''), 'supplier_type' => 'FORWARDER', 'order_date' => self::str($sh['date'] ?? ''), 'order_ref' => $sh['no'],
                'order_ref_note' => self::str($sh['oldRef'] ?? '') ?: null, 'currency' => $cur, 'rate' => null, 'owner_name' => '', 'brand' => null, 'notes' => 'MY TOOL shipment ' . $sh['no'] . ' – freight only (MY TOOL linked it to no purchase) · ' . trim(self::str($sh['goods'] ?? '') . ' · ' . strtoupper(self::str($sh['mode'] ?? '')) . ' · shipped ' . self::str($sh['date'] ?? '') . (self::str($sh['arrival'] ?? '') ? ' · arrived ' . self::str($sh['arrival']) : ''), ' ·'),
                'payments' => array_values(array_map(fn ($p) => ['amount' => $p['amount'], 'currency' => $p['currency'], 'date' => $p['date'], 'ref' => $p['ref'], 'method' => $p['method']], $fp)),
                'lines' => [['line_type' => 'COST', 'product_name' => 'Freight ' . $sh['no'] . (self::str($sh['goods'] ?? '') ? ' – ' . self::str($sh['goods']) : ''), 'qty' => 1, 'unit_price' => round(array_sum(array_column($fp, 'amount')), 2)]],
                'shipping' => ['shipped_date' => null, 'arrived_date' => null, 'mode' => null, 'forwarder' => null, 'tracking' => null, 'freight' => [], 'duty' => 0, 'other' => 0],      // the dates / mode stay in the notes: a cost-only PO has no goods to ship
                'received' => ['date' => null, 'warehouse' => null]];
        }
        self::$refs = $matchRefs;
        return $out;
    }

    /**
     * FX rates from MY TOOL's own payments (fxRate to GBP on the payment day) into Money › FX rates where that day has none; and for every order date
     * that has no rate on or before it, the nearest MY TOOL rate of that currency (logged). So every old amount is converted with the rate MY TOOL used.
     */
    public static function rates(array $d, array &$log): int
    {
        $byCur = [];
        foreach ($d['payments'] ?? [] as $p) { $c = self::cur($p['cur'] ?? ''); $r = self::num($p['fxRate'] ?? 0); $dt = self::str($p['date'] ?? '');
            if ($c === '' || $c === 'GBP' || $r <= 0 || ! $dt) continue; $byCur[$c][$dt] = $r; }
        $n = 0;
        foreach ($byCur as $c => $days) {
            ksort($days);
            foreach ($days as $dt => $r) if (! DB::table('fin_fx_rates')->where('currency', $c)->where('rate_date', $dt)->exists()) { Fx::set($c, $dt, $r, 'MYTOOL'); $n++; }
            $first = array_key_first($days);
            foreach (array_merge(array_column($d['purchases'] ?? [], 'date'), array_column($d['shipments'] ?? [], 'date')) as $od) {
                $od = self::str($od); if (! $od || $od >= $first) continue;
                if (! DB::table('fin_fx_rates')->where('currency', $c)->where('rate_date', '<=', $od)->exists()) { Fx::set($c, $od, $days[$first], 'MYTOOL'); $n++; $log[] = "NOTE rate $c on $od = MY TOOL's first rate ($first) " . $days[$first]; }
            }
        }
        if ($n) $log[] = "$n exchange rate(s) taken from MY TOOL's payments into Money › FX rates";
        return $n;
    }

    /**
     * Owner 26-11 19:21: an order that is in BOTH the old MoreOrderz sourcing data and MY TOOL is kept from MY TOOL (the original);
     * the old MoreOrderz copy is removed. Old orders that MY TOOL does not have stay as they are.
     * Same order = a supplier order number in common (WAP-UK-JW…), else the same day (±2) with the same total (±2 %).
     * An old cycle that already has goods received (GRN / lots) is never removed – it is listed to look at by hand.
     * @return array{replace: list<array>, keep: int, blocked: list<array>}
     */
    public static function oldMatches(int $ent, array $entries): array
    {
        $mine = array_keys($entries); $refs = self::$refs; $byRef = [];
        foreach ($refs as $no => $r) foreach ($r['refs'] as $x) $byRef[$x][] = $no;
        $old = DB::table('t_src_cycles')->where('fk_id_ent', $ent)->where('f_active', 1)->whereNotIn('order_ref', $mine ?: ['-'])->where(fn ($w) => $w->whereNull('notes')->orWhere('notes', 'not like', 'MY TOOL %'))->get(['id', 'cycle_code', 'order_ref', 'sourcing_date', 'product_name']);
        $out = ['replace' => [], 'keep' => 0, 'blocked' => []]; $taken = [];
        foreach ($old as $c) {
            $pos = DB::table('t_src_po')->where('fk_id_cycle', $c->id)->get(['id', 'po_total', 'po_date']);
            $total = round((float) $pos->sum('po_total'), 2); $date = substr((string) ($pos->pluck('po_date')->filter()->first() ?: $c->sourcing_date), 0, 10);
            preg_match_all('/[A-Z]{2,}[A-Z0-9-]*\d{6,}/', strtoupper((string) $c->order_ref), $m); $hit = null; $why = '';
            foreach ($m[0] as $x) if (! empty($byRef[$x])) { $hit = $byRef[$x][0]; $why = "same supplier order $x"; break; }
            if (! $hit && $total > 0 && $date) foreach ($refs as $no => $r) {
                if (isset($taken[$no]) || ! $r['date'] || $r['total'] <= 0) continue;
                if (abs(strtotime($r['date']) - strtotime($date)) <= 2 * 86400 && abs($r['total'] - $total) <= 0.02 * max($r['total'], $total)) { $hit = $no; $why = "same day and total ($date, " . number_format($total, 2) . ')'; break; }
            }
            if (! $hit) { $out['keep']++; continue; }
            $row = ['old_cycle' => (int) $c->id, 'old_code' => $c->cycle_code, 'old_ref' => $c->order_ref, 'old_date' => $date, 'old_total' => $total, 'mytool' => $hit, 'why' => $why];
            $poIds = $pos->pluck('id')->all();
            if (DB::table('t_src_grn')->where('fk_id_cycle', $c->id)->exists() || ($poIds && DB::table('fin_lots')->whereIn('po_id', $poIds)->exists())) { $out['blocked'][] = $row + ['reason' => 'goods already received on it – look at it by hand']; continue; }
            $taken[$hit] = true; $out['replace'][] = $row;
        }
        return $out;
    }

    /** Remove the old MoreOrderz copies (cycle + every row of its POs and steps). A copy of every removed row is kept in storage/app/mytool-replaced-<time>.json. */
    private static function removeOld(int $ent, array $replace): int
    {
        if (! $replace) return 0;
        $ids = array_column($replace, 'old_cycle'); $poIds = DB::table('t_src_po')->whereIn('fk_id_cycle', $ids)->pluck('id')->all(); $backup = []; $n = 0;
        $tables = collect(DB::select("SELECT TABLE_NAME t, COLUMN_NAME c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME IN ('fk_id_cycle', 'fk_id_po') AND TABLE_NAME LIKE 't\\_src\\_%'"))->groupBy('t');
        foreach ($tables as $t => $cols) {
            if ($t === 't_src_cycles') continue;
            $q = DB::table($t)->where(function ($w) use ($cols, $ids, $poIds) { foreach ($cols as $c) $w->orWhereIn($c->c, $c->c === 'fk_id_po' ? ($poIds ?: [0]) : $ids); });
            $rows = (clone $q)->get(); if (! $rows->count()) continue; $backup[$t] = $rows->all(); $n += $q->delete();
        }
        $backup['t_src_cycles'] = DB::table('t_src_cycles')->whereIn('id', $ids)->get()->all(); $n += DB::table('t_src_cycles')->whereIn('id', $ids)->delete();
        @mkdir(storage_path('app'), 0775, true); file_put_contents(storage_path('app/mytool-replaced-' . date('Ymd-His') . '.json'), json_encode(['pairs' => $replace, 'rows' => $backup]));
        Audit::log('t_src_cycles', null, 'DELETE', ['OLD_REPLACED_BY_MYTOOL' => [count($ids) . ' cycles', null]], 'Owner 26-11: same order in MY TOOL – the MY TOOL copy is kept', $ent);
        return count($ids);
    }

    /** Dry run: purchases, lines, payments, money per currency, masters that would be made, purchases already in. Writes nothing. */
    public static function check(int $ent, string $json): array
    {
        $d = self::read($json); $e = self::entries($d); $money = []; $newSup = []; $dups = 0;
        foreach ($e as $x) {
            foreach ($x['payments'] as $p) $money[$p['currency'] ?: '?'] = round(($money[$p['currency'] ?: '?'] ?? 0) + $p['amount'], 2);
            if (! DB::table('t_src_suppliers')->where('fk_id_ent', $ent)->where('supplier_code', 'MT-' . $x['supplier_code'])->exists() && ! SrcNames::supplier($ent, $x['supplier_name'])) $newSup[$x['supplier_name']] = 1;
            if (DB::table('t_src_cycles')->where('fk_id_ent', $ent)->where('order_ref', $x['order_ref'])->where('cycle_type', 'HISTORICAL')->exists()) $dups++;
        }
        $om = self::oldMatches($ent, $e);
        return ['old_same' => $om['replace'], 'old_kept' => $om['keep'], 'old_blocked' => $om['blocked'], 'purchases' => count($e), 'lines' => array_sum(array_map(fn ($x) => count($x['lines']), $e)), 'payments' => array_sum(array_map(fn ($x) => count($x['payments']), $e)), 'paid' => $money,
            'with_freight' => count(array_filter($e, fn ($x) => $x['shipping']['freight'])), 'freight_only' => count(array_filter($e, fn ($x) => ($x['supplier_type'] ?? '') === 'FORWARDER')),
            'not_purchases' => count(array_filter($d['payments'] ?? [], fn ($p) => collect($p['allocations'] ?? [])->contains(fn ($a) => empty($a['ref'])))), 'new_suppliers' => array_keys($newSup), 'already_in' => $dups, 'saved_by_mytool' => count($d['purchases'] ?? [])];
    }

    public static function import(int $ent, string $json, object $u): array { return self::importData($ent, self::read($json), $u); }

    /** The same import from MY TOOL's records as an array (a data file, or the items of a direct-link package – MyToolLink). */
    public static function importData(int $ent, array $d, object $u): array
    {
        $d += ['parties' => [], 'purchases' => [], 'shipments' => [], 'payments' => []]; $done = 0; $skip = 0; $fail = []; $log = [];
        $asSupplier = array_unique(array_column(self::entries($d), 'supplier_code'));      // forwarders paid as the party of an entry become suppliers too (type FORWARDER)
        foreach ($d['parties'] ?? [] as $code => $p) if (in_array($p['type'] ?? '', ['supplier', 'forwarder'], true)) {
            if (($p['type'] === 'supplier' || in_array($code, $asSupplier, true)) && ! DB::table('t_src_suppliers')->where('fk_id_ent', $ent)->where(fn ($q) => $q->where('supplier_code', "MT-$code")->orWhereRaw('LOWER(TRIM(supplier_name)) = ?', [mb_strtolower(trim($p['name']))]))->exists()) {
                DB::table('t_src_suppliers')->insert(RecordService::fillNotNull('t_src_suppliers', ['fk_id_ent' => $ent, 'supplier_code' => "MT-$code", 'supplier_name' => mb_substr($p['name'], 0, 150), 'supplier_type' => $p['type'] === 'forwarder' ? 'FORWARDER' : null, 'notes' => 'From MY TOOL ' . $code . (self::str($p['company'] ?? '') ? ' · ' . $p['company'] : ''), 'f_active' => 1, 'created_by' => $u->id, 'created_at' => now()]));
                $log[] = "NEW supplier {$p['name']} (MY TOOL $code)";
            }
            if ($p['type'] === 'forwarder' && ! DB::table('t_src_forwarders')->where('fk_id_ent', $ent)->whereRaw('LOWER(TRIM(forwarder_name)) = ?', [mb_strtolower(trim($p['name']))])->exists()) {
                DB::table('t_src_forwarders')->insert(RecordService::fillNotNull('t_src_forwarders', ['fk_id_ent' => $ent, 'forwarder_code' => "MT-$code", 'forwarder_name' => mb_substr($p['name'], 0, 150), 'notes' => 'From MY TOOL ' . $code, 'f_active' => 1, 'created_by' => $u->id, 'created_at' => now()]));
                $log[] = "NEW forwarder {$p['name']} (MY TOOL $code)";
            }
        }
        $rates = self::rates($d, $log);
        $entries = self::entries($d); $om = self::oldMatches($ent, $entries); $removed = 0; $same = collect($om['replace'])->groupBy('mytool');
        foreach ($om['blocked'] as $x) $log[] = "KEPT old MoreOrderz {$x['old_code']} although MY TOOL {$x['mytool']} looks the same – {$x['reason']}";
        $swap = function (string $ref) use ($ent, $same, &$removed, &$log) {      // only after the MY TOOL copy is saved: then its old MoreOrderz copy goes
            $rows = $same->get($ref, collect())->all(); if (! $rows) return;
            $removed += self::removeOld($ent, $rows);
            foreach ($rows as $x) $log[] = "REMOVED old MoreOrderz {$x['old_code']} ({$x['old_ref']}) – the same order is MY TOOL {$x['mytool']} ({$x['why']})";
        };
        foreach ($entries as $ref => $e) {
            if (DB::table('t_src_cycles')->where('fk_id_ent', $ent)->where('order_ref', $ref)->where('cycle_type', 'HISTORICAL')->exists()) { $skip++; $swap((string) $ref); continue; }
            $sup = DB::table('t_src_suppliers')->where('fk_id_ent', $ent)->where('supplier_code', 'MT-' . $e['supplier_code'])->value('id') ?: (SrcNames::supplier($ent, $e['supplier_name'])->id ?? null);
            if (! $sup) { $fail[] = "$ref: supplier {$e['supplier_name']} not found"; continue; }
            $owner = $e['owner_name'] !== '' ? SrcNames::owner($ent, $e['owner_name'], $log) : null;      // the buying company of MY TOOL by name; not found → left empty with a WARN (pick it on the cycle)
            try { $r = HistoricalPurchase::entry($ent, ['supplier' => (int) $sup] + ($owner ? ['owner' => $owner] : []) + $e, $u); $done++; $swap((string) $ref); foreach ((array) ($r['log'] ?? []) as $x) if (str_starts_with((string) $x, 'NEW')) $log[] = "$ref: $x"; }
            catch (\Throwable $x) { $fail[] = "$ref: " . $x->getMessage(); }
        }
        Audit::log('t_src_cycles', null, 'INSERT', ['MYTOOL_IMPORT' => [null, "$done purchases"]], 'MY TOOL purchase history imported', $ent);
        return ['removed_old' => $removed, 'imported' => $done, 'already_in' => $skip, 'failed' => $fail, 'log' => $log, 'message' => "$done MY TOOL purchase(s) imported as old purchases, $skip already there, $removed old MoreOrderz cop" . ($removed === 1 ? 'y' : 'ies') . " of the same orders removed" . ($fail ? ', ' . count($fail) . ' not imported (see the list)' : '') . '.'];
    }
}
