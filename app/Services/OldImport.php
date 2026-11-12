<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Import of the old tool's data (Step 7). Reads another database on the same server and writes the new tables.
 * Part 1 (this class today): master data – every old table that keeps its name and is not a transaction. Rows keep their old id,
 * so running it again adds nothing twice. Passwords, PINs, card security codes and security answers are never read.
 * Transactions (sales, purchases, stock, sourcing money) are marked LATER: they go through the stock and ledger services in part 2.
 */
class OldImport
{
    /** Never read, whatever the table: logins and secrets. */
    public const SECRET = '/(^|_)(pass|password|passwd|pwd|token|secret|api_key|apikey|cvv|cvc|pin|otp|salt|answer)(_|$)/i';
    /** Masked when the run asks for it (development copies): people's contact details and account numbers. */
    public const PERSONAL = '/(phone|mobile|telephone|whatsapp|wechat|e_?mail|address|postcode|card_no|card_number|account_no|account_number|acc_no|iban|sort_code|swift|cnic|passport|buyer|cust_name|customer_name|ship_to|ni_number|utr)/i';

    /** Owned by the new tool – its own menu, rights and module lists replace these. */
    public const TOOL = ['sidebar_modules', 't_controller_rights', 't_role_rights', 'cat_module_controllers', 'cat_modules', 'cat_module_types', 'cat_module_params', 'cat_module_entities', 'cat_rights'];
    /** Old backup copies and one-day helper tables (migration plan: RETIRE). */
    public const RETIRED = ['t_product_vendors_2025', 't_product_vendors_12_7_23', 't_product_vendors_backup', 't_product_vendors_dec_2025', 't_import_options'];
    /** Transactions and their logs: part 2, through the services. */
    public const LATER = ['ebay', 'ebay_audit', 't_sale', 't_sale_orders', 't_sale_orders_1', 't_sale_orders_new', 't_tiktok_sale', 't_buy', 'pur_audit', 'pur_audit_gd', 't_stock_movements', 't_product_inventory_change_log', 't_product_sale_change_log',
        't_audit_main', 't_sale_buy_main', 't_product_locations', 't_sale_order_reminders', 't_shop_feedbacks', 't_product_investors', 't_src_cycles', 't_src_cycle_products', 't_src_rfq', 't_src_po', 't_src_payments', 't_src_shipments', 't_src_freight',
        't_src_customs', 't_src_production', 't_src_qc', 't_src_cartons', 't_src_landed_cost', 't_src_inventory_receipts'];
    /** Copy these first (others follow by name). */
    private const FIRST = ['t_enterprise', 'cat_country', 'cat_currency', 'cat_marketplaces', 'cat_location_types', 'cat_inventory_locations', 't_branches', 't_account', 't_users', 't_product_vendors'];

    private static function ident(string $s): string { if (! preg_match('/^[A-Za-z0-9_]+$/', $s)) throw new \InvalidArgumentException("Bad name: $s"); return '`' . $s . '`'; }

    public static function plan(string $table): string
    {
        return in_array($table, self::TOOL, true) ? 'TOOL' : (in_array($table, self::RETIRED, true) ? 'RETIRED' : (in_array($table, self::LATER, true) ? 'LATER' : 'COPY'));
    }

    public static function mask(?string $v): ?string
    {
        if ($v === null || $v === '') return $v;
        $n = mb_strlen($v); if ($n <= 2) return str_repeat('•', $n);
        return str_repeat('•', min(8, $n - 2)) . mb_substr($v, -2);
    }

    /**
     * Run the import. $opt: dry (count only), mask (hide personal details – for development copies), only (list of tables).
     * Returns ['run' => code, 'rows' => report lines, 'totals' => …].
     */
    public static function run(string $oldDb, array $opt = []): array
    {
        $old = self::ident($oldDb); $dry = ! empty($opt['dry']); $mask = ! empty($opt['mask']); $target = DB::getDatabaseName();
        if ($oldDb === $target) throw new \InvalidArgumentException('The old database and the new database are the same.');
        $tables = collect(DB::select('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = ? AND table_type = ?', [$oldDb, 'BASE TABLE']))->pluck('t');
        if ($tables->isEmpty()) throw new \InvalidArgumentException("Database $oldDb has no tables (or this login cannot read it).");
        if (! empty($opt['only'])) $tables = $tables->intersect($opt['only']);
        $tables = $tables->sortBy(fn ($t) => (($i = array_search($t, self::FIRST, true)) === false ? 100 : $i) . $t)->values();
        $newTables = collect(DB::select('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = ? AND table_type = ?', [$target, 'BASE TABLE']))->pluck('t')->flip();
        $run = 'IMP-' . date('ymd-His'); $report = [];
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($tables as $t) {
                $n = (int) DB::selectOne('SELECT COUNT(*) AS n FROM ' . $old . '.' . self::ident($t))->n; if ($n === 0) continue;
                $plan = self::plan($t); $line = ['run_code' => $run, 'old_table' => $t, 'plan' => $plan, 'old_rows' => $n, 'imported' => 0, 'already_there' => 0, 'merged' => 0, 'failed' => 0, 'left_out_columns' => null, 'masked_columns' => null, 'note' => null, 'f_dry' => $dry ? 1 : 0, 'created_at' => now()];
                if ($plan !== 'COPY') $line['note'] = ['TOOL' => 'The new tool has its own menu, rights and module lists.', 'RETIRED' => 'Old backup copy or helper table – not carried over.', 'LATER' => 'Transactions: imported in part 2 through the stock and ledger services.'][$plan];
                elseif (! isset($newTables[$t])) { $line['plan'] = 'LATER'; $line['note'] = 'No table of this name in the new tool – needs its own mapping.'; }
                else $line = self::copy($oldDb, $t, $line, $dry, $mask) + $line;
                $report[] = $line;
            }
        } finally { DB::statement('SET FOREIGN_KEY_CHECKS = 1'); }
        foreach (array_chunk($report, 200) as $c) DB::table('mo_import_report')->insert($c);
        $sum = fn (string $k, ?string $plan = null) => array_sum(array_map(fn ($r) => $plan === null || $r['plan'] === $plan ? $r[$k] : 0, $report));
        return ['run' => $run, 'rows' => $report, 'totals' => ['tables' => count($report), 'old_rows' => $sum('old_rows'), 'to_copy' => $sum('old_rows', 'COPY'), 'imported' => $sum('imported'), 'already_there' => $sum('already_there'), 'merged' => $sum('merged'), 'failed' => $sum('failed'),
            'later' => $sum('old_rows', 'LATER'), 'retired' => $sum('old_rows', 'RETIRED'), 'tool' => $sum('old_rows', 'TOOL')]];
    }

    private static function copy(string $oldDb, string $t, array $line, bool $dry, bool $mask): array
    {
        $target = DB::getDatabaseName();
        $cols = fn (string $db) => collect(DB::select('SELECT column_name AS c, data_type AS ty, is_nullable AS nu, column_default AS df, extra AS ex, column_key AS k, character_maximum_length AS len FROM information_schema.columns WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position', [$db, $t]))->keyBy('c');
        $oc = $cols($oldDb); $nc = $cols($target);
        $secret = $oc->keys()->filter(fn ($c) => preg_match(self::SECRET, $c))->values();
        $gone = $oc->keys()->diff($nc->keys())->diff($secret)->values();
        $use = $oc->keys()->intersect($nc->keys())->reject(fn ($c) => preg_match(self::SECRET, $c) || str_contains((string) $nc[$c]->ex, 'GENERATED'))->values();
        $masked = $mask ? $use->filter(fn ($c) => preg_match(self::PERSONAL, $c) && in_array($nc[$c]->ty, ['varchar', 'char', 'text', 'tinytext', 'mediumtext', 'longtext'], true))->values() : collect();
        $left = $secret->map(fn ($c) => "$c (secret – never read)")->merge($gone->map(fn ($c) => "$c (no such column in the new tool)"));
        $line['left_out_columns'] = $left->isEmpty() ? null : mb_substr($left->implode(', '), 0, 500); $line['masked_columns'] = $masked->isEmpty() ? null : mb_substr($masked->implode(', '), 0, 500);
        $pk = $nc->first(fn ($c) => $c->k === 'PRI')->c ?? null; $hasPk = $pk && $use->contains($pk);
        $have = (int) DB::table($t)->count();
        if (! $hasPk && $have > 0) { $line['already_there'] = min($have, $line['old_rows']); $line['note'] = 'Table without an id: it already holds rows, so it was not loaded again.'; return $line; }
        if ($dry) { $line['already_there'] = $hasPk ? (int) DB::selectOne('SELECT COUNT(*) AS n FROM ' . self::ident($oldDb) . '.' . self::ident($t) . ' o WHERE EXISTS (SELECT 1 FROM ' . self::ident($t) . ' n WHERE n.' . self::ident($pk) . ' = o.' . self::ident($pk) . ')')->n : 0;
            $line['note'] = 'Dry run: ' . ($line['old_rows'] - $line['already_there']) . ' row(s) would be imported.'; return $line; }
        // defaults for columns the new tool needs and the old table does not have
        $fill = []; foreach ($nc as $c => $m) if (! $use->contains($c) && $m->nu === 'NO' && $m->df === null && ! str_contains((string) $m->ex, 'auto_increment') && ! str_contains((string) $m->ex, 'GENERATED')) $fill[$c] = self::zero($m->ty);
        $hasLegacy = isset($nc['legacy_table'], $nc['legacy_id']) && ! $use->contains('legacy_table');
        $dateCols = $use->filter(fn ($c) => in_array($nc[$c]->ty, ['date', 'datetime', 'timestamp'], true))->flip(); $errors = [];
        $selectList = $use->map(fn ($c) => self::ident($c))->implode(', ');
        $q = DB::table(DB::raw(self::ident($oldDb) . '.' . self::ident($t)))->selectRaw($selectList); if ($hasPk) $q->orderBy($pk);
        // unique keys of the new table (other than the id): an old row that repeats one is a duplicate of the row that is kept
        $uniq = collect(DB::select('SELECT index_name AS i, column_name AS c FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND non_unique = 0 AND index_name <> ? ORDER BY index_name, seq_in_index', [$target, $t, 'PRIMARY']))->groupBy('i')->map(fn ($g) => $g->pluck('c')->all());
        $settle = function (array $batch) use ($t, $pk, $hasPk, $uniq, &$line) {   // after an insert that skipped rows: which were already there, which are duplicates
            if (! $hasPk) return;
            $there = DB::table($t)->whereIn($pk, array_column($batch, $pk))->pluck($pk)->flip();
            foreach ($batch as $row) {
                if (isset($there[$row[$pk]])) continue;
                $kept = null; foreach ($uniq as $cols) { $q = DB::table($t); foreach ($cols as $c) $q->where($c, $row[$c] ?? null); if ($kept = $q->value($pk)) break; }
                if (! $kept) { $line['failed']++; continue; }
                $line['merged']++; $line['already_there']--;
                DB::table('sys_merge_map')->updateOrInsert(['source_table' => $t, 'source_id' => $row[$pk], 'master_table' => $t], ['entity' => 'LIST_ITEM', 'master_id' => $kept, 'match_score' => 100, 'status' => 'CONFIRMED', 'decided_at' => now()]);
            }
        };
        $handle = function ($rows) use ($t, $use, $nc, $masked, $fill, $hasLegacy, $dateCols, $pk, $hasPk, $settle, &$line, &$errors) {
            $batch = [];
            foreach ($rows as $r) {
                $row = (array) $r;
                foreach ($row as $c => $v) {
                    if (isset($dateCols[$c]) && is_string($v) && str_starts_with($v, '0000-00-00')) $row[$c] = $nc[$c]->nu === 'YES' ? null : '1970-01-01';
                    elseif ($v === null && $nc[$c]->nu === 'NO' && $nc[$c]->df === null && ! str_contains((string) $nc[$c]->ex, 'auto_increment')) $row[$c] = self::zero($nc[$c]->ty);
                    elseif (is_string($v) && $nc[$c]->len && mb_strlen($v) > (int) $nc[$c]->len) $row[$c] = mb_substr($v, 0, (int) $nc[$c]->len);
                }
                foreach ($masked as $c) $row[$c] = self::mask($row[$c] === null ? null : (string) $row[$c]);
                if ($hasLegacy && $hasPk) { $row['legacy_table'] = $t; $row['legacy_id'] = $row[$pk]; }
                if ($t === 't_product_vendors' && array_key_exists('stock', $row)) $row['stock'] = 0;   // stock comes in part 2 as opening lots; the product's figure must equal its lots
                $batch[] = $row + $fill;
            }
            try { $n = DB::table($t)->insertOrIgnore($batch); $line['imported'] += $n; $line['already_there'] += count($batch) - $n; if ($n < count($batch)) $settle($batch); }
            catch (\Throwable $e) {   // one bad row must not stop the others: try them one by one and keep the reason
                foreach ($batch as $row) {
                    try { $n = DB::table($t)->insertOrIgnore([$row]); $line['imported'] += $n; $line['already_there'] += 1 - $n; if (! $n) $settle([$row]); }
                    catch (\Throwable $e2) { $line['failed']++; $why = preg_replace('/\(Connection:.*$/s', '', $e2->getMessage()); $why = preg_replace("/'[^']{0,80}'/", "'…'", $why); $errors[mb_substr(trim($why), 0, 110)] = true; }
                }
            }
        };
        if ($hasPk) $q->chunk(500, $handle); else $handle($q->get());
        if ($line['merged']) $line['note'] = "{$line['merged']} old row(s) repeat a row that is kept (same code); they are merged into it and the old id → kept id is recorded.";
        if ($errors) $line['note'] = ($line['note'] ? $line['note'] . ' ' : '') . mb_substr(count($errors) . ' kind(s) of problem: ' . implode(' | ', array_keys($errors)), 0, 500);
        if ($t === 't_users') $line['note'] = trim(($line['note'] ?? '') . ' Passwords are not carried over: every user sets a new one, and the Owner gives each user a role.');
        if ($t === 't_product_vendors') $line['note'] = trim(($line['note'] ?? '') . ' Stock figures are set to 0 here; stock comes in part 2 as opening lots.');
        return $line;
    }

    /** The id to use in the new tool for an old id of a table (follows the merge of duplicates). */
    public static function mapped(string $table, int|string|null $oldId): int|string|null
    {
        if ($oldId === null) return null;
        return DB::table('sys_merge_map')->where('source_table', $table)->where('source_id', $oldId)->where('master_table', $table)->where('status', 'CONFIRMED')->value('master_id') ?? $oldId;
    }

    private static function zero(string $type): mixed
    {
        return match (true) { in_array($type, ['int', 'bigint', 'smallint', 'tinyint', 'mediumint', 'decimal', 'float', 'double'], true) => 0, $type === 'date' => '1970-01-01', in_array($type, ['datetime', 'timestamp'], true) => '1970-01-01 00:00:00', $type === 'json' => '[]', default => '' };
    }
}
