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
    public const PERSONAL = '/(phone|mobile|telephone|whatsapp|wechat|e_?mail|address|postcode|card_no|card_number|account_no|account_number|acc_no|iban|sort_code|swift|cnic|passport|buyer|cust_|customer|post_to|recipient|ship_to|ni_number|utr)/i';

    /** Owned by the new tool – its own menu, rights and module lists replace these. */
    public const TOOL = ['sidebar_modules', 't_controller_rights', 't_role_rights', 'cat_module_controllers', 'cat_modules', 'cat_module_types', 'cat_module_params', 'cat_module_entities', 'cat_rights'];
    /** Old backup copies and one-day helper tables (migration plan: RETIRE). */
    public const RETIRED = ['t_product_vendors_2025', 't_product_vendors_12_7_23', 't_product_vendors_backup', 't_product_vendors_dec_2025', 't_import_options'];
    /** Transactions and their logs: not part of the master-data run (see transactions()). */
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

    // ═════════════ part 2: transactions ═════════════

    /** Old tables kept as they are, as history (every original column that the new table has). The old stock movements are not among them: opening lots replace them. */
    public const HISTORY = ['ebay', 'ebay_audit', 'pur_audit', 'pur_audit_gd', 't_buy', 't_sale', 't_audit_main', 't_sale_buy_main', 't_sale_orders', 't_sale_orders_1', 't_sale_orders_new', 't_tiktok_sale', 't_product_inventory_change_log', 't_product_sale_change_log',
        't_sale_order_reminders', 't_shop_feedbacks', 't_product_investors', 't_product_locations', 't_src_cycles', 't_src_cycle_products', 't_src_rfq', 't_src_po', 't_src_payments', 't_src_shipments', 't_src_freight', 't_src_customs', 't_src_production',
        't_src_qc', 't_src_cartons', 't_src_landed_cost', 't_src_inventory_receipts'];
    /** Lookup columns to repoint after duplicates were merged. */
    private const REPOINT = ['cat_currency' => ['fk_id_currency'], 'cat_incoterm' => ['fk_id_incoterm'], 'cat_payment_terms' => ['fk_id_payment_term'], 'cat_ports' => ['fk_id_port_loading', 'fk_id_port_discharge']];

    /**
     * Part 2. (1) history tables copied as they are; (2) the old sales tables merged into one sales list as history lines (same shop + order + SKU only once);
     * (3) opening stock: one opening lot per product with stock, at the product's cost where the old tool knew one. $opt: dry, mask, opening_date.
     */
    public static function transactions(string $oldDb, array $opt = []): array
    {
        $old = self::ident($oldDb); $dry = ! empty($opt['dry']); $mask = ! empty($opt['mask']); $target = DB::getDatabaseName();
        if ($oldDb === $target) throw new \InvalidArgumentException('The old database and the new database are the same.');
        $has = fn (string $t) => (bool) DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = ? AND table_name = ?', [$oldDb, $t])->n;
        $run = 'IMT-' . date('ymd-His'); $report = [];
        $blank = fn (string $t, string $plan, int $n) => ['run_code' => $run, 'old_table' => $t, 'plan' => $plan, 'old_rows' => $n, 'imported' => 0, 'already_there' => 0, 'merged' => 0, 'failed' => 0, 'left_out_columns' => null, 'masked_columns' => null, 'note' => null, 'f_dry' => $dry ? 1 : 0, 'created_at' => now()];
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            // 1. history tables
            foreach (self::HISTORY as $t) {
                if (! $has($t)) continue; $n = (int) DB::selectOne('SELECT COUNT(*) AS n FROM ' . $old . '.' . self::ident($t))->n; if ($n === 0) continue;
                $report[] = self::copy($oldDb, $t, $blank($t, 'HISTORY', $n), $dry, $mask) + $blank($t, 'HISTORY', $n);
            }
            if ($has('t_stock_movements') && ($n = (int) DB::selectOne('SELECT COUNT(*) AS n FROM ' . $old . '.t_stock_movements')->n)) { $l = $blank('t_stock_movements', 'REPLACED', $n); $l['note'] = 'Not copied: the new tool writes its own stock movements. Opening lots (below) bring in the stock.'; $report[] = $l; }
            // lookups that were merged: repoint the documents that used a repeated id
            if (! $dry) foreach (self::REPOINT as $lookup => $colsWanted) foreach ($colsWanted as $c) foreach (DB::select('SELECT table_name AS t FROM information_schema.columns WHERE table_schema = ? AND column_name = ?', [$target, $c]) as $tt) {
                DB::update('UPDATE ' . self::ident($tt->t) . ' x JOIN sys_merge_map m ON m.source_table = ? AND m.master_table = ? AND m.source_id = x.' . self::ident($c) . " AND m.status = 'CONFIRMED' SET x." . self::ident($c) . ' = m.master_id', [$lookup, $lookup]);
            }
            // 2. one sales list
            foreach (self::salesSources($oldDb) as $src) {
                if (! $has($src['table'])) continue; $l = $blank($src['table'] . ' → sales list', 'SALES', 0);
                try { $report[] = self::mergeSales($oldDb, $src, $l, $dry, $mask); }
                catch (\Illuminate\Database\QueryException $e) {   // an old table that does not have the expected columns must not stop the rest
                    $l['old_rows'] = (int) DB::selectOne('SELECT COUNT(*) AS n FROM ' . $old . '.' . self::ident($src['table']))->n; $l['failed'] = $l['old_rows'];
                    $l['note'] = 'Not merged into the sales list: this table does not have the columns the mapping expects (its rows are still kept in the history copy).'; $report[] = $l;
                }
            }
            // 3. opening stock
            if ($has('t_product_vendors')) $report[] = self::openingStock($oldDb, $blank('t_product_vendors.stock → opening lots', 'OPENING', 0), $dry, $opt['opening_date'] ?? date('Y-m-d'));
        } finally { DB::statement('SET FOREIGN_KEY_CHECKS = 1'); }
        foreach (array_chunk($report, 200) as $c) DB::table('mo_import_report')->insert($c);
        return ['run' => $run, 'rows' => $report];
    }

    /** The old sales tables in order of trust (the first that has a shop + order + SKU wins) and how each maps to a sale line. SQL expressions on alias o. */
    private static function salesSources(string $oldDb): array
    {
        $so = fn (string $t, string $extra) => ['table' => $t, 'from' => "`$t` o", 'where' => "o.ebay_order_no IS NOT NULL AND o.ebay_order_no <> '' AND o.d_ebay_order > '1971-01-01'", 'cols' => ['branch' => 'o.fk_id_branch', 'order_no' => 'o.ebay_order_no', 'd_order' => 'DATE(o.d_ebay_order)',
            'sku' => "IF(o.sku_ebay IS NULL OR o.sku_ebay = '', CONCAT('NO-SKU-', o.id), o.sku_ebay)", 'qty' => 'o.qty_ordered', 'rate' => 'o.sale_rate', 'total' => 'o.c_grand_total', 'cust_name' => 'o.cust_name', 'cust_contacts' => 'o.cust_phone', 'ship_to_address' => 'o.cust_address',
            'buy_rate' => 'o.pur_rate', 'd_buy' => 'o.d_purchase'] + self::sqlCols($extra)];
        return [
            $so('t_sale_orders_new', "product=IF(o.fk_id_vendor_inventory > 0, o.fk_id_vendor_inventory, NULL)|buy_amount=o.pur_amount|profit=COALESCE(o.product_profit,0) - COALESCE(o.product_loss,0)|refunded=IF(o.sale_order_status = 'Refunded', 1, 0)|refunded_at=o.refunded_at|refund_reason=o.refunded_reason|info=o.product_info"),
            $so('t_sale_orders', 'buy_amount=COALESCE(o.qty_pur,0) * COALESCE(o.pur_rate,0)|profit=COALESCE(o.product_profit,0) - COALESCE(o.product_loss,0)'),
            $so('t_sale_orders_1', 'buy_amount=COALESCE(o.qty_pur,0) * COALESCE(o.pur_rate,0)'),
            ['table' => 'ebay', 'from' => '`ebay` o', 'where' => "o.Order_number IS NOT NULL AND o.Order_number <> '' AND STR_TO_DATE(o.Sale_date, '%d-%b-%y') IS NOT NULL", 'cols' => ['branch' => 'o.fk_id_branch', 'order_no' => 'o.Order_number', 'd_order' => "STR_TO_DATE(o.Sale_date, '%d-%b-%y')",
                'sku' => "IF(o.sku_ebay IS NULL OR o.sku_ebay = '', IF(o.Custom_label IS NULL OR o.Custom_label = '', CONCAT('NO-SKU-', o.id), o.Custom_label), o.sku_ebay)", 'qty' => 'CAST(o.Quantity AS SIGNED)', 'rate' => "CAST(REGEXP_REPLACE(o.Sold_for, '[^0-9.]', '') AS DECIMAL(12,2))",
                'total' => "CAST(REGEXP_REPLACE(o.Total_price, '[^0-9.]', '') AS DECIMAL(12,2))", 'cust_name' => 'o.Buyer_name', 'cust_contacts' => 'o.Post_to_phone', 'ship_to_address' => "CONCAT_WS(', ', o.Post_to_address_1, o.Post_to_city, o.Post_to_postcode)", 'info' => 'o.Item_title']],
            ['table' => 't_sale', 'from' => '`t_sale` o JOIN `t_audit_main` m ON m.id = o.fk_id_main', 'where' => "m.fk_id_branch > 0 AND o.order_id IS NOT NULL AND o.order_id <> '' AND o.d_sale IS NOT NULL", 'cols' => ['branch' => 'm.fk_id_branch', 'order_no' => 'o.order_id', 'd_order' => 'o.d_sale',
                'sku' => "IF(o.sku IS NULL OR o.sku = '', CONCAT('NO-SKU-', o.id), o.sku)", 'qty' => 'o.no_of_items', 'rate' => 'IF(o.no_of_items > 0, o.price / o.no_of_items, o.price)', 'total' => 'o.price', 'cust_name' => 'o.customer_name', 'ship_to_address' => 'o.cust_address',
                'product' => 'IF(o.fk_id_product > 0, o.fk_id_product, NULL)', 'info' => 'o.item_title']],
            ['table' => 't_tiktok_sale', 'from' => '`t_tiktok_sale` o', 'where' => "o.order_no IS NOT NULL AND o.order_no <> '' AND o.d_order_date > '1971-01-01'", 'cols' => ['branch' => 'o.fk_id_shop', 'order_no' => 'o.order_no', 'd_order' => 'o.d_order_date',
                'sku' => "IF(o.item_sku IS NULL OR o.item_sku = '', CONCAT('NO-SKU-', o.id), o.item_sku)", 'qty' => 'o.item_qty', 'rate' => '0', 'total' => '0', 'cust_name' => 'o.buyer_full_name', 'cust_contacts' => 'o.recipient_phone', 'ship_to_address' => "CONCAT_WS(', ', o.address_line_1, o.buyer_city, o.buyer_postal_code)", 'info' => 'o.item_name']],
        ];
    }

    private static function sqlCols(string $spec): array { $o = []; foreach (array_filter(explode('|', $spec)) as $p) { [$k, $v] = explode('=', $p, 2); $o[$k] = $v; } return $o; }

    private static function mergeSales(string $oldDb, array $src, array $line, bool $dry, bool $mask): array
    {
        $old = self::ident($oldDb); $t = $src['table']; $c = $src['cols'];
        $from = preg_replace('/`([a-z0-9_]+)` /', $old . '.`$1` ', $src['from']);
        $all = DB::selectOne("SELECT COUNT(*) AS n, COALESCE(SUM({$c['total']}), 0) AS v FROM $from"); $line['old_rows'] = (int) DB::selectOne("SELECT COUNT(*) AS n FROM $old." . self::ident($t))->n;
        $shops = DB::table('t_branches')->get(['id', 'fk_id_ent', 'fk_id_marketplace'])->keyBy('id');
        $sel = 'o.id AS _id'; foreach ($c as $k => $expr) $sel .= ", $expr AS `$k`";
        $before = (int) DB::table('t_sales')->where('legacy_table', $t)->count(); $okValue = 0.0; $noShop = 0; $ok = 0;
        $last = 0;
        while (true) {
            $rows = DB::select("SELECT $sel FROM $from WHERE ({$src['where']}) AND o.id > ? ORDER BY o.id LIMIT 1000", [$last]); if (! $rows) break; $last = end($rows)->_id; $batch = [];
            foreach ($rows as $r) {
                $shop = $shops[$r->branch] ?? null; if (! $shop) { $noShop++; continue; }
                $qty = max(0, (int) $r->qty); $total = round((float) $r->total, 2); $m = fn (?string $v) => $mask ? self::mask($v) : $v;
                $batch[] = RecordService::fillNotNull('t_sales', ['fk_id_ent' => (int) $shop->fk_id_ent, 'fk_id_branch' => (int) $r->branch, 'fk_id_marketplace' => (int) $shop->fk_id_marketplace, 'fk_product_id' => $r->product ?? null, 'order_no' => mb_substr((string) $r->order_no, 0, 100),
                    'd_order' => $r->d_order, 'cust_name' => (string) $m($r->cust_name ?? ''), 'cust_contacts' => (string) $m($r->cust_contacts ?? ''), 'sku' => mb_substr((string) $r->sku, 0, 100), 'product_info' => isset($r->info) ? mb_substr((string) $r->info, 0, 250) : null, 'qty' => $qty,
                    'sale_rate' => round((float) $r->rate, 2), 'total_amount' => $total, 'total_amount_gbp' => $total, 'buy_rate' => round((float) ($r->buy_rate ?? 0), 2), 'buy_amount' => round((float) ($r->buy_amount ?? 0), 2), 'profit' => round((float) ($r->profit ?? 0), 2),
                    'd_buy' => isset($r->d_buy) && $r->d_buy && ! str_starts_with((string) $r->d_buy, '0000') ? substr((string) $r->d_buy, 0, 10) : null, 'status' => ! empty($r->refunded) ? 'Refunded' : 'Confirmed',
                    'refunded_at' => ! empty($r->refunded) && ! empty($r->refunded_at) && ! str_starts_with((string) $r->refunded_at, '0000') ? $r->refunded_at : null, 'refund_reason' => $r->refund_reason ?? null, 'ship_to_address' => isset($r->ship_to_address) ? mb_substr((string) $m($r->ship_to_address), 0, 300) : null,
                    'sale_source' => 'history', 'f_locked' => 1, 'f_stock_deducted' => 1, 'legacy_table' => $t, 'legacy_id' => $r->_id, 'created_by' => 0, 'created_at' => now()]);
                $ok++; $okValue += $total;
            }
            if (! $dry && $batch) foreach (array_chunk($batch, 500) as $ch) DB::table('t_sales')->insertOrIgnore($ch);
        }
        $filtered = $line['old_rows'] - $ok - $noShop;   // no order number / no usable date
        if ($dry) { $line['note'] = "Dry run: $ok row(s) usable (value " . number_format($okValue, 2) . "), $noShop without a shop in the new tool, $filtered without an order number or date."; return $line; }
        $now = DB::table('t_sales')->where('legacy_table', $t)->selectRaw('COUNT(*) n, COALESCE(SUM(total_amount),0) v')->first();
        $line['imported'] = (int) $now->n - $before; $line['already_there'] = $before; $line['merged'] = $ok - (int) $now->n; $line['failed'] = $noShop + $filtered;
        $line['note'] = mb_substr('Old total ' . number_format((float) $all->v, 2) . ' → in the sales list ' . number_format((float) $now->v, 2) . ". {$line['merged']} line(s) were already in the list from a more trusted table or repeat inside this one (same shop + order + SKU)."
            . ($noShop ? " $noShop line(s) left out: their shop is not in the new tool." : '') . ($filtered ? " $filtered line(s) left out: no order number or no usable date." : '') . ' Kept as history: no effect on stock, the books or the profit figures.', 0, 500);
        return $line;
    }

    private static function openingStock(string $oldDb, array $line, bool $dry, string $date): array
    {
        $old = self::ident($oldDb);
        $cost = "IF(COALESCE(o.landing_price, 0) > 0, o.landing_price, COALESCE(o.raw_price, 0))";
        $tot = DB::selectOne("SELECT COUNT(*) AS n, COALESCE(SUM(o.stock),0) AS u, SUM($cost <= 0) AS nocost, COALESCE(SUM(o.stock * $cost),0) AS v FROM $old.t_product_vendors o WHERE o.stock > 0");
        $line['old_rows'] = (int) $tot->n;
        if ($dry) { $line['note'] = "Dry run: {$tot->n} product(s) with stock, " . number_format((float) $tot->u) . ' units, value known ' . number_format((float) $tot->v, 2) . ", {$tot->nocost} product(s) without a cost."; return $line; }
        $rows = DB::select("SELECT o.id, o.stock, $cost AS cost FROM $old.t_product_vendors o JOIN t_product_vendors p ON p.id = o.id WHERE o.stock > 0 ORDER BY o.id");
        $ents = DB::table('t_product_vendors')->whereIn('id', array_column($rows, 'id'))->pluck('fk_id_ent', 'id'); $have = DB::table('fin_lots')->where('source', 'OPENING')->pluck('fk_id_product')->flip(); $byEnt = []; $units = 0; $value = 0.0;
        foreach ($rows as $r) { if (isset($have[$r->id])) { $line['already_there']++; continue; } $byEnt[(int) $ents[$r->id]][] = $r; }
        foreach ($byEnt as $ent => $list) {
            $loc = DB::table('cat_inventory_locations')->where('fk_id_ent', $ent)->where('f_active', 1)->where('f_sellable', 1)->orderBy('id')->value('id')
                ?? DB::table('cat_inventory_locations')->insertGetId(RecordService::fillNotNull('cat_inventory_locations', ['location_name' => 'Opening stock (put away from here)', 'location_code' => 'OPENING', 'kind' => 'WAREHOUSE', 'fk_id_ent' => $ent, 'f_sellable' => 1, 'f_active' => 1, 'f_pickable' => 1, 'f_receivable' => 1, 'created_at' => now()]));
            $prev = [Context::ent(), Context::userId()]; Context::set($ent, $prev[1]);
            foreach (array_chunk($list, 100) as $chunk) {
                $x = StockService::receive($ent, array_map(fn ($r) => ['product' => (int) $r->id, 'qty' => (int) $r->stock, 'location' => (int) $loc, 'unit_cost' => (float) $r->cost, 'received_date' => $date, 'landed_status' => (float) $r->cost > 0 ? 'FINAL' : 'PROVISIONAL'], $chunk), 'OPENING', null, ['lot_source' => 'OPENING']);
                $line['imported'] += count($chunk); $value += $x['value']; $units += array_sum(array_map(fn ($r) => (int) $r->stock, $chunk));
            }
            Context::set($prev[0], $prev[1]);
        }
        $line['note'] = mb_substr("Old tool: {$tot->n} product(s) with stock, " . number_format((float) $tot->u) . ' units. Opening lots made now: ' . $line['imported'] . ' (' . number_format($units) . ' units, value ' . number_format($value, 2) . ") dated $date. "
            . ((int) $tot->nocost ? "{$tot->nocost} product(s) had NO cost in the old tool: their opening lots have cost 0 – give each its cost (Stock › Lots) before its units are sold, otherwise profit is overstated. " : '') . 'Bin positions of the old tool are not used: the units sit in one location per company and are put away from there.', 0, 500);
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
