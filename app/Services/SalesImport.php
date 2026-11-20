<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The monthly sales import (RULES_A_SALES §1, R-A_SALES-1 … 18): one batch per shop + month, replaced only after a confirm;
 * pre-checks, shop + month from the file name, Sale Columns mapping, robust reader, row rules with an error table and a proof line,
 * no-SKU key, duplicates across months, out-of-period lines, three kinds of line (never a stock movement), result stats,
 * fix-up of lines without a product, daily totals, and the register of imported months.
 */
class SalesImport
{
    public const FIELDS = ['order_no' => 'Order No', 'order_date' => 'Order Date', 'cust_name' => 'Cust. Name', 'cust_contacts' => 'Cust. Contacts', 'sku' => 'SKU', 'product_info' => 'Product Info', 'qty' => 'Qty', 'sale_rate' => 'Sale Rate', 'total_amount' => 'Total Amount'];
    public const MATCH_FIELDS = ['ship_name' => 'Delivered To', 'ship_address' => 'Address', 'ship_city' => 'City', 'ship_postcode' => 'Postcode', 'ship_phone' => 'Phone'];
    public const REQUIRED = ['order_no', 'order_date', 'sku', 'qty', 'sale_rate'];
    public const JOINED = ['cust_name' => ' ', 'cust_contacts' => ', ', 'product_info' => ', '];
    public const SETS = ['SALE' => ['marketplace_sale_columns', 'sale_field', self::FIELDS], 'DS' => ['marketplace_ds_columns', 'ds_field', self::FIELDS], 'MATCH' => ['marketplace_5_matching_rule_columns', 'match_field', self::MATCH_FIELDS]];
    private const MONTHS = ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december'];

    public static function label(object $shop): string { return trim((string) $shop->shop_no) . ' (' . trim((string) $shop->shop_name) . ')'; }
    public static function monthName(int $m, int $y): string { return date('F Y', mktime(0, 0, 0, $m, 1, $y)); }

    // ---------- R-A_SALES-66 / 4: the mapping sets of a marketplace ----------
    /** field => [exact CSV header names] */
    public static function columns(int $marketplaceId, string $set = 'SALE'): array
    {
        [$table, $col, $fields] = self::SETS[$set]; $out = array_fill_keys(array_keys($fields), []);
        foreach (DB::table($table)->where('fk_marketplace_id', $marketplaceId)->whereIn('fk_id_ent', [0, (int) Context::ent()])->get() as $r) if (isset($out[$r->$col])) $out[$r->$col] = array_values(array_filter(array_map('trim', explode(',', (string) $r->csv_columns)), fn ($x) => $x !== ''));
        return $out;
    }

    public static function saveColumns(int $marketplaceId, string $set, array $data): array
    {
        if (! isset(self::SETS[$set])) throw new \InvalidArgumentException('Unknown mapping set.');
        if (! DB::table('cat_marketplaces')->where('id', $marketplaceId)->exists()) throw new \InvalidArgumentException('Marketplace not found.');
        [$table, $col, $fields] = self::SETS[$set]; $ent = (int) Context::ent(); $saved = [];
        DB::transaction(function () use ($table, $col, $fields, $data, $marketplaceId, $ent, &$saved) {
            foreach ($fields as $f => $label) {
                $list = Parse::headerList($data[$f] ?? ''); if (mb_strlen($list) > 1000) throw new \InvalidArgumentException("$label: too many CSV columns (1000 characters at most).");
                DB::table($table)->updateOrInsert(['fk_id_ent' => $ent, 'fk_marketplace_id' => $marketplaceId, $col => $f], ['csv_columns' => $list, 'updated_at' => now()]); $saved[$f] = $list;
            }
            Audit::log($table, $marketplaceId, 'UPDATE', ['set' => 'columns saved']);
        });
        return $saved;
    }

    // ---------- R-A_SALES-13: schema self-check ----------
    public const NEEDS = ['t_sales' => ['fk_id_import', 'sale_source', 'raw_csv', 'f_out_of_period', 'cost_source', 'batch_row_no'], 't_sale_imports' => ['period_month', 'period_year', 'saved_rows', 'replaced_rows', 'stats_json'], 't_sale_import_errors' => ['fk_id_import', 'row_no', 'reason'],
        'marketplace_sale_columns' => ['sale_field', 'csv_columns'], 'mo_sku_aliases' => ['alias_key'], 'mo_recycle_bin' => ['group_key']];

    /** Missing tables / columns the importer needs: ['table x', 'column y.z'] – empty when the database is up to date. */
    public static function selfCheck(): array
    {
        $miss = [];
        try { foreach (self::NEEDS as $t => $cols) { if (! Schema::hasTable($t)) { $miss[] = "table $t"; continue; } foreach ($cols as $c) if (! Schema::hasColumn($t, $c)) $miss[] = "column $t.$c"; } }
        catch (\Throwable $e) { return []; }      // the check never blocks when the schema cannot be read
        return $miss;
    }

    public static function selfCheckMessage(): ?string
    {
        $m = self::selfCheck();
        return $m ? 'The database is not up to date for the sales module. Run the migrations (php artisan migrate) – missing: ' . implode(', ', $m) . '.' : null;
    }

    // ---------- R-A_SALES-3: shop + month from the file name ----------
    public static function fileName(string $name, ?int $marketplaceId = null): array
    {
        $ent = (int) Context::ent(); $base = preg_replace('/\.[A-Za-z0-9]{2,5}$/', '', trim($name));
        if (! preg_match('/^([A-Za-z0-9\-]+)_([A-Za-z]+)[\s_\-]?(\d{4}|\d{2})\b/i', $base, $m)) return ['ok' => false, 'message' => "The file name doesn't follow SHOPNO_monthYY.csv, so select the shop and month yourself."];
        $month = null; $p = strtolower($m[2]);
        if (strlen($p) >= 3) foreach (self::MONTHS as $i => $full) if (str_starts_with($full, $p) || ($p === 'sept' && $full === 'september')) { $month = $i + 1; break; }
        if (! $month) return ['ok' => false, 'shop_no' => $m[1], 'message' => "The file name doesn't follow SHOPNO_monthYY.csv, so select the shop and month yourself."];
        $year = (int) $m[3]; if ($year < 100) $year += 2000; $mn = self::monthName($month, $year); $out = ['ok' => true, 'shop_no' => $m[1], 'month' => $month, 'year' => $year, 'month_name' => $mn];
        $shops = Perm::mine(DB::table('t_branches'), 'id')->where('fk_id_ent', $ent)->whereRaw('LOWER(shop_no) = ?', [strtolower($m[1])])->get();
        if ($shops->isEmpty()) return $out + ['message' => "Shop {$m[1]} from the file name isn't in your shop list. Month set to $mn."];
        $active = $shops->where('f_active', 1)->values();
        if ($active->isEmpty()) return $out + ['message' => "Shop {$m[1]} is inactive, so its sales can't be imported. Activate the shop first. Month set to $mn."];
        if ($active->count() > 1 && $marketplaceId) { $n = $active->where('fk_id_marketplace', $marketplaceId)->values(); if ($n->count() === 1) $active = $n; }
        if ($active->count() > 1) return $out + ['choices' => $active->map(fn ($s) => ['id' => $s->id, 'marketplace' => $s->fk_id_marketplace, 'label' => self::label($s)])->all(),
            'message' => $active->count() . " shops use shop no {$m[1]}: " . $active->map(fn ($s) => self::label($s))->implode(', ') . ". Select the right shop / marketplace and shop. Month set to $mn."];
        $s = $active[0]; $mp = (string) (DB::table('cat_marketplaces')->where('id', $s->fk_id_marketplace)->selectRaw("COALESCE(NULLIF(marketplace_short,''), marketplace_descr) n")->value('n') ?? '');
        return $out + ['shop' => (int) $s->id, 'marketplace' => (int) $s->fk_id_marketplace, 'message' => 'Filled from the file name: ' . self::label($s) . ", $mp, $mn.", 'detected' => "Detected from file name $base ✓"];
    }

    // ---------- R-A_SALES-2: pre-checks, in the rule's order ----------
    /** Returns the shop row, or throws with the rule's message. $file = ['name' =>, 'size' =>] or null when no file was sent. */
    public static function preCheck(array $d, ?array $file, ?object $u): object
    {
        if ($msg = self::selfCheckMessage()) throw new \InvalidArgumentException($msg);
        $nature = (string) ($d['nature'] ?? 'Normal');
        if ($nature === 'Manual') throw new \InvalidArgumentException("Manual import isn't available yet. Choose Normal sale.");
        if (empty($d['marketplace'])) throw new \InvalidArgumentException('Select a marketplace.');
        if (empty($d['shop'])) throw new \InvalidArgumentException('Select a shop.');
        $m = (int) ($d['month'] ?? 0); $y = (int) ($d['year'] ?? 0);
        if ($m < 1 || $m > 12 || $y < 2000 || $y > 2100) throw new \InvalidArgumentException('Select the month and year of these sales.');
        $fee = trim((string) ($d['fee'] ?? ''));
        if ($fee === '') throw new \InvalidArgumentException('Enter the marketplace fee. Use 0 if there is no fee.');
        if (! preg_match('/^\d{1,8}(\.\d{1,2})?$/', $fee)) throw new \InvalidArgumentException('Marketplace fee must be a number with up to 2 decimals, for example 12 or 12.50.');
        $shop = DB::table('t_branches')->where('id', (int) $d['shop'])->where('fk_id_ent', Context::ent())->first();
        if (! $shop) throw new \InvalidArgumentException('Shop not found.');
        $label = self::label($shop);
        if (! (int) $shop->f_active) throw new \InvalidArgumentException("Shop $label is inactive, so sales can't be imported for it. Activate the shop first, or choose another shop.");
        if ((int) $shop->fk_id_marketplace !== (int) $d['marketplace']) throw new \InvalidArgumentException("Shop $label belongs to a different marketplace.");
        if (! $u || ! Perm::has($u, 'orders.saleImport') || ! Perm::shopAllowed($u, (int) $shop->id)) throw new \InvalidArgumentException("You don't have rights to import sales for shop $label.");
        if (! $file) throw new \InvalidArgumentException('Choose a CSV file to upload.');
        if (strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION)) !== 'csv') throw new \InvalidArgumentException('The file must be a .csv file.');
        if ((int) $file['size'] > 20 * 1024 * 1024) throw new \InvalidArgumentException('The file is larger than 20 MB.');
        return $shop;
    }

    // ---------- helpers ----------
    private static function mapped(array $row, array $headers, string $field): string
    {
        $vals = []; foreach ($headers as $h) { $v = trim((string) ($row[Parse::header($h)] ?? '')); if ($v !== '') $vals[] = $v; }
        if (! $vals) return '';
        return isset(self::JOINED[$field]) ? implode(self::JOINED[$field], $vals) : $vals[0];
    }

    public static function noSku(string $order, string $info, mixed $qty, mixed $total): string { return 'NOSKU-' . substr(md5(mb_strtolower("$order|$info|$qty|$total")), 0, 12); }

    /** A line that was in the tool before the file came (daily orders): the monthly file was merged into it, so it is never deleted with the batch. */
    private static function fromIntake(object $line, object $batch): bool
    {
        return $line->sale_source !== 'summary' && $line->created_at && $batch->imported_at && strtotime((string) $line->created_at) < strtotime((string) $batch->imported_at) - 1;
    }

    /** The batch of a shop + month, if any. */
    public static function batchOf(int $shopId, int $month, int $year, ?string $nature = null): ?object
    {
        return DB::table('t_sale_imports')->where('fk_id_ent', Context::ent())->where('fk_id_branch', $shopId)->where('period_month', $month)->where('period_year', $year)->when($nature, fn ($q) => $nature === 'Totals' ? $q->where('import_nature', 'Totals') : $q->where('import_nature', '<>', 'Totals'))->orderByDesc('id')->first();
    }

    private static function productOf(int $ent, int $marketplaceId, string $sku, string $info, bool $nosku): ?object
    {
        if (! $nosku) { $p = DB::table('t_product_vendors')->where('fk_id_ent', $ent)->whereRaw('LOWER(TRIM(SKU_VENDOR)) = ?', [mb_strtolower(trim($sku))])->first(); if ($p) return $p; }
        $a = DB::table('mo_sku_aliases')->where('fk_id_ent', $ent)->whereIn('fk_id_marketplace', [0, $marketplaceId])->where(fn ($w) => $w->where(fn ($x) => $x->where('kind', 'SKU')->where('alias_key', mb_substr(mb_strtolower(trim($sku)), 0, 190)))
            ->orWhere(fn ($x) => $x->where('kind', 'TITLE')->where('alias_key', mb_substr(mb_strtolower(trim($info)), 0, 190))))->orderByDesc('fk_id_marketplace')->first();
        return $a && ($nosku ? true : true) ? DB::table('t_product_vendors')->where('id', $a->fk_id_product)->where('fk_id_ent', $ent)->first() : null;
    }

    // ---------- the import: check (nothing is kept) or apply ----------
    /**
     * $d: nature, marketplace, shop, month, year, fee, replace (bool), out_mode (keep | move), delimiter, header_line, sure (bool: the file name says another shop / month).
     * Returns the result of R-A_SALES-14, or ['confirm_overwrite' => …] when a Pending batch exists and replace was not confirmed.
     */
    public static function run(string $path, string $fileName, int $size, array $d, ?object $u, bool $apply): array
    {
        $shop = self::preCheck($d, ['name' => $fileName, 'size' => $size], $u);
        $ent = (int) Context::ent(); $m = (int) $d['month']; $y = (int) $d['year']; $mn = self::monthName($m, $y); $label = self::label($shop); $period = sprintf('%04d-%02d', $y, $m);
        // the file name against what was chosen (the same detection as on the screen, on the server too)
        $fn = self::fileName($fileName, (int) $d['marketplace']); $warnings = [];
        if (! empty($fn['ok'])) {
            $w = [];
            if (strtolower((string) $fn['shop_no']) !== strtolower((string) $shop->shop_no)) $w[] = "The file name says shop {$fn['shop_no']}, but you selected shop {$shop->shop_no}";
            if ((int) $fn['month'] !== $m || (int) $fn['year'] !== $y) $w[] = "The file name says {$fn['month_name']}, but you selected $mn";
            if ($w && empty($d['sure'])) return ['name_mismatch' => $w, 'message' => implode('. ', $w) . '. Tick "I\'m sure" to continue.'];
            $warnings = array_merge($warnings, $w);
        }
        $totals = ($d['nature'] ?? 'Normal') === 'Totals';
        $old = self::batchOf((int) $shop->id, $m, $y, $totals ? 'Totals' : 'Normal');
        if ($old && $old->status !== 'Pending' || Ledger::periodLocked($ent, $period)) throw new \InvalidArgumentException("Shop $label - $mn is confirmed and saved permanently, so it can't be imported again.");
        // read the file
        $cols = self::columns((int) $shop->fk_id_marketplace);
        if (! $totals) {
            $unset = array_filter(self::REQUIRED, fn ($f) => ! $cols[$f]);
            if ($unset) throw new \InvalidArgumentException('Sale Columns are not set for this marketplace: ' . implode(', ', array_map(fn ($f) => self::FIELDS[$f], $unset)) . '. Set them from Marketplaces > Sale Columns, then import again.');
            $csv = Parse::csv($path, array_merge(...array_values($cols)), 'Sale Columns', array_filter(['delimiter' => $d['delimiter'] ?? null, 'header_line' => $d['header_line'] ?? null]));
            $have = array_flip($csv['headers']); $missing = [];
            foreach (self::FIELDS as $f => $lab) { $hs = $cols[$f]; if (! $hs) continue; $found = array_filter($hs, fn ($h) => isset($have[Parse::header($h)]));
                if (in_array($f, self::REQUIRED, true) && ! $found) $missing[] = "$lab (" . implode(', ', $hs) . ')';
                elseif (count($found) < count($hs)) $warnings[] = "Column not found for $lab: " . implode(', ', array_diff($hs, $found)); }
            if ($missing) throw new \InvalidArgumentException('These columns are missing from the CSV: ' . implode('; ', $missing) . '. Check the file is the right marketplace export, or update Sale Columns.');
        } else $csv = self::totalsCsv($path);

        if ($old && empty($d['replace'])) {
            $deducted = DB::table('t_sales')->where('fk_id_import', $old->id)->where('f_stock_deducted', 1)->count(); $by = DB::table('t_users')->where('id', $old->imported_by)->value('display_name');
            return ['confirm_overwrite' => ['batch' => $old->id, 'lines' => (int) $old->saved_rows, 'file' => $old->file_name, 'imported_at' => $old->imported_at ? date('d-m-Y H:i', strtotime((string) $old->imported_at)) : '', 'imported_by' => $by, 'deducted' => $deducted,
                'title' => 'Replace existing sales?', 'text' => "{$old->saved_rows} sale line(s) from {$old->file_name}, imported " . ($old->imported_at ? date('d-m-Y H:i', strtotime((string) $old->imported_at)) : '') . ($by ? " by $by" : ''),
                'warn' => "If you continue, all $mn sales for shop $label are deleted and replaced with this file. The old lines stay in the recycle bin for 30 days.", 'buttons' => ['Keep existing sales', 'Yes, replace them']],
                'message' => "Shop $label already has sales imported for $mn."];
        }

        DB::beginTransaction();
        try {
            $replaced = 0; $diff = null; $oldKeys = [];
            if ($old) {      // replace: the old batch is undone first, in the same transaction
                foreach (DB::table('t_sales')->where('fk_id_import', $old->id)->lockForUpdate()->get() as $s) { $oldKeys[mb_strtolower($s->order_no . '|' . $s->sku)] = [(float) $s->total_amount, (int) $s->qty]; $replaced++;
                    if (self::fromIntake($s, $old)) { DB::table('t_sales')->where('id', $s->id)->update(['fk_id_import' => 0]); continue; }
                    DB::table('mo_recycle_bin')->insert(['fk_id_ent' => $ent, 'table_name' => 't_sales', 'row_id' => $s->id, 'label' => mb_substr("Order {$s->order_no} · {$s->sku}", 0, 255), 'group_key' => "batch:{$old->id}", 'row_json' => json_encode((array) $s), 'deleted_by' => $u->id ?? null, 'deleted_at' => now(), 'note' => 'Overwritten by a new import of the same month']);
                    if ($s->sale_source === 'active') DispatchService::release((int) $s->id, 'Overwritten by a new import of the same month'); }
                DB::table('t_sales')->where('fk_id_import', $old->id)->delete(); DB::table('t_sale_import_errors')->where('fk_id_import', $old->id)->delete(); DB::table('t_sale_imports')->where('id', $old->id)->delete();
            }
            $batch = (int) DB::table('t_sale_imports')->insertGetId(RecordService::fillNotNull('t_sale_imports', ['fk_id_ent' => $ent, 'fk_id_branch' => $shop->id, 'fk_id_marketplace' => $shop->fk_id_marketplace, 'import_nature' => $totals ? 'Totals' : 'Normal', 'file_name' => mb_substr($fileName, 0, 255), 'period_month' => $m, 'period_year' => $y,
                'marketplace_fee' => (float) $d['fee'], 'fee_source' => 'typed', 'status' => 'Pending', 'imported_by' => $u->id ?? null, 'imported_at' => now(), 'replaced_rows' => $replaced]));
            $st = ['saved' => 0, 'active' => 0, 'ds' => 0, 'unmatched' => 0, 'errors' => 0, 'skipped' => 0, 'out_of_period' => 0, 'moved' => 0, 'merged' => 0, 'qty' => 0, 'sale' => 0.0, 'buy' => 0.0, 'profit' => 0.0, 'cost_current' => 0, 'cost_product' => 0, 'file_rows' => count($csv['rows'])];
            $errors = []; $seen = []; $nosku = []; $newKeys = []; $unmatchedRows = [];
            $from = sprintf('%04d-%02d-01', $y, $m); $to = date('Y-m-t', strtotime($from)); $cur = Fx::code((int) $shop->fk_id_payout_currency ?: null, $ent);
            $err = function (int $ln, string $order, string $sku, string $why, array $row, ?int $in = null) use (&$errors, &$st, $batch) { $st['errors']++; $errors[] = ['line' => $ln, 'order_no' => $order, 'sku' => $sku, 'reason' => $why, 'batch' => $in];
                DB::table('t_sale_import_errors')->insert(['fk_id_import' => $batch, 'row_no' => $ln, 'order_no' => mb_substr($order, 0, 100), 'sku' => mb_substr($sku, 0, 100), 'reason' => mb_substr($why, 0, 255), 'raw_csv' => json_encode($row, JSON_UNESCAPED_UNICODE), 'f_resolved' => 0, 'f_unmatched' => 0, 'created_at' => now()]); };
            foreach ($csv['rows'] as [$ln, $row]) {
                if ($totals) { self::totalsRow($batch, $shop, $ln, $row, $st, $err, $cur, $ent, $u); continue; }
                if (! array_filter($row, fn ($v) => trim((string) $v) !== '')) { $st['skipped']++; continue; }
                $v = []; foreach (self::FIELDS as $f => $lab) $v[$f] = self::mapped($row, $cols[$f], $f);
                if ($v['order_no'] === '' && $v['sku'] === '') { $st['skipped']++; continue; }                          // footer line
                if ($v['sku'] === '' && $v['qty'] === '') { $st['skipped']++; continue; }                               // multi-item order summary line
                if ($v['order_no'] === '') { $err($ln, '', $v['sku'], 'Order No is empty', $row); continue; }
                if (mb_strlen($v['order_no']) > 100) { $err($ln, $v['order_no'], $v['sku'], 'Order No is longer than 100 characters', $row); continue; }
                if (mb_strlen($v['sku']) > 100) { $err($ln, $v['order_no'], $v['sku'], 'SKU is longer than 100 characters', $row); continue; }
                if (! preg_match('/^\d+$/', trim($v['qty'])) || (int) $v['qty'] < 1) { $err($ln, $v['order_no'], $v['sku'], "Qty '{$v['qty']}' is not a whole number above 0", $row); continue; }
                $qty = (int) $v['qty']; $rate = Parse::money($v['sale_rate']); $total = Parse::money($v['total_amount']);
                if ($rate === false) { $err($ln, $v['order_no'], $v['sku'], "Sale Rate '{$v['sale_rate']}' is not a number", $row); continue; }
                if ($total === false) { $err($ln, $v['order_no'], $v['sku'], "Total Amount '{$v['total_amount']}' is not a number", $row); continue; }
                if ($rate === null && $total === null) { $err($ln, $v['order_no'], $v['sku'], 'Sale Rate and Total Amount are both empty', $row); continue; }
                if ($rate === null) $rate = round($total / $qty, 2); if ($total === null) $total = round($rate * $qty, 2);
                $date = Parse::date($v['order_date']);
                if ($date === false || $date === '') { $err($ln, $v['order_no'], $v['sku'], "Order Date '{$v['order_date']}' is not a date format the system knows", $row); continue; }
                $isNo = $v['sku'] === ''; $sku = $v['sku'];
                if ($isNo) { $sku = self::noSku($v['order_no'], $v['product_info'], $qty, $total); $nosku[$sku] = ($nosku[$sku] ?? 0) + 1; if ($nosku[$sku] > 1) $sku .= '-' . $nosku[$sku]; }
                $key = mb_strtolower($v['order_no'] . '|' . $sku);
                if (isset($seen[$key])) { $err($ln, $v['order_no'], $isNo ? '' : $sku, "Same Order No + SKU appears twice in this file (line {$seen[$key]})", $row); continue; }
                $seen[$key] = $ln;
                // the same line in another batch of this shop → error that names the month; a line of the daily intake (no batch) is merged
                $dup = DB::table('t_sales')->where('fk_id_ent', $ent)->where('fk_id_branch', $shop->id)->whereRaw('LOWER(order_no) = ?', [mb_strtolower($v['order_no'])])->whereRaw('LOWER(sku) = ?', [mb_strtolower($sku)])->where('sale_source', '<>', 'history')->first();
                if ($dup && (int) $dup->fk_id_import && (int) $dup->fk_id_import !== $batch) { $ob = DB::table('t_sale_imports')->where('id', $dup->fk_id_import)->first(); $err($ln, $v['order_no'], $isNo ? '' : $sku, 'Already imported in the ' . ($ob ? date('M Y', mktime(0, 0, 0, (int) $ob->period_month, 1, (int) $ob->period_year)) : date('M Y', strtotime((string) $dup->d_order))) . ' import', $row, (int) $dup->fk_id_import); continue; }
                $out = $date < $from || $date > $to; $target = $batch; $flag = $out ? 1 : 0;
                if ($out && ($d['out_mode'] ?? 'keep') === 'move') {      // into the batch of its own month, when that month can still take lines
                    $om = (int) substr($date, 5, 2); $oy = (int) substr($date, 0, 4); $ob = self::batchOf((int) $shop->id, $om, $oy, 'Normal');
                    if ((! $ob || $ob->status === 'Pending') && ! Ledger::periodLocked($ent, substr($date, 0, 7))) { $target = $ob ? (int) $ob->id : (int) DB::table('t_sale_imports')->insertGetId(RecordService::fillNotNull('t_sale_imports', ['fk_id_ent' => $ent, 'fk_id_branch' => $shop->id, 'fk_id_marketplace' => $shop->fk_id_marketplace, 'import_nature' => 'Normal', 'file_name' => mb_substr($fileName, 0, 255), 'period_month' => $om, 'period_year' => $oy, 'marketplace_fee' => 0, 'status' => 'Pending', 'imported_by' => $u->id ?? null, 'imported_at' => now()]));
                        $flag = 0; $st['moved']++; DB::table('t_sale_imports')->where('id', $target)->increment('saved_rows'); }
                }
                if ($flag) $st['out_of_period']++;
                $product = self::productOf($ent, (int) $shop->fk_id_marketplace, $v['sku'], $v['product_info'], $isNo);
                $line = ['fk_id_ent' => $ent, 'fk_id_import' => $target, 'fk_id_branch' => $shop->id, 'fk_id_marketplace' => (int) $shop->fk_id_marketplace, 'fk_product_id' => $product->id ?? 0, 'order_no' => $v['order_no'], 'd_order' => $date, 'cust_name' => $v['cust_name'], 'cust_contacts' => $v['cust_contacts'], 'sku' => $sku,
                    'product_info' => $v['product_info'] !== '' ? $v['product_info'] : (string) ($product->product_name_vendor ?? ''), 'qty' => $qty, 'sale_rate' => $rate, 'total_amount' => $total, 'f_out_of_period' => $flag, 'status' => 'Pending', 'f_stock_deducted' => 0, 'sale_source' => $product ? 'active' : 'unmatched',
                    'raw_csv' => json_encode($row, JSON_UNESCAPED_UNICODE), 'fk_id_currency' => BuyingService::currencyId($cur), 'total_amount_gbp' => Fx::toBase((float) $total, $cur, $date, $ent), 'batch_row_no' => $ln, 'line_status' => 'OPEN', 'fulfilment_channel' => $shop->fulfilment_channel ?: 'SELF', 'fk_id_sell_location' => $shop->fk_id_main_location,
                    'fk_id_company' => (int) ($shop->fk_id_company ?? 0) ?: null, 'ds_note' => $product ? '' : ($isNo ? 'No SKU in the file' : 'SKU not in inventory')];
                if ($product) { $line = array_merge($line, SaleLines::intakeCost($product, $shop, $date, $qty, (float) $rate, (float) $total, $ent), ['d_buy' => $product->d_purchase_date ?? null, 'product_url' => isset($product->url_ebay) ? mb_substr((string) $product->url_ebay, 0, 500) : null, 'fk_id_vendor' => (int) ($product->fk_id_vendor ?? 0) ?: null]);
                    if ($line['cost_source'] === 'current') $st['cost_current']++; if ($line['cost_source'] === 'product') $st['cost_product']++; }
                if ($dup && ! (int) $dup->fk_id_import) {      // daily intake line: the monthly file is merged into it, the line is not replaced
                    if ($dup->status === 'Pending' && ! (int) $dup->f_locked) DB::table('t_sales')->where('id', $dup->id)->update(array_intersect_key($line, array_flip(['fk_id_import', 'qty', 'sale_rate', 'total_amount', 'total_amount_gbp', 'cust_name', 'cust_contacts', 'product_info', 'f_out_of_period', 'raw_csv', 'batch_row_no'])) + ['updated_at' => now()]);
                    else DB::table('t_sales')->where('id', $dup->id)->update(['fk_id_import' => $target]);
                    $id = (int) $dup->id; $st['merged']++;
                } else {
                    $id = (int) DB::table('t_sales')->insertGetId(RecordService::fillNotNull('t_sales', array_filter($line + ['created_by' => $u->id ?? null, 'created_at' => now()], fn ($x) => $x !== null)));
                    if ($product && $line['fulfilment_channel'] === 'SELF') DispatchService::reserve($id);      // a reservation, never a stock movement
                }
                $st['saved']++; $st[$product ? 'active' : 'unmatched']++; $st['qty'] += $qty; $st['sale'] += (float) $total; $st['buy'] += (float) ($line['buy_amount'] ?? 0); $st['profit'] += (float) ($line['profit'] ?? 0);
                $newKeys[$key] = [(float) $total, $qty];
                if (! $product && count($unmatchedRows) < 200) $unmatchedRows[] = ['id' => $id, 'order_no' => $v['order_no'], 'sku' => $isNo ? '-' : $sku, 'buyer' => $v['cust_name'], 'product' => mb_substr($v['product_info'], 0, 120), 'qty' => $qty, 'total' => (float) $total, 'why' => $line['ds_note']];
                if ((float) $total <= 0) TaskEngine::open('SAL_ZERO', [$id], "Sale line with zero value: order {$v['order_no']} · $sku", ['ent' => $ent, 'module' => 't_sales', 'record_id' => $id]);
            }
            foreach (['sale', 'buy', 'profit'] as $k) $st[$k] = round($st[$k], 2);
            if ($st['saved'] + $st['errors'] + $st['skipped'] === 0) throw new \InvalidArgumentException('The CSV has a header row but no sale rows.');
            $proofOk = $st['file_rows'] === $st['saved'] + $st['errors'] + $st['skipped'];
            if ($old) { $added = count(array_diff_key($newKeys, $oldKeys)); $removed = count(array_diff_key($oldKeys, $newKeys)); $changed = 0; foreach ($newKeys as $k => $x) if (isset($oldKeys[$k]) && $oldKeys[$k] != $x) $changed++;
                $diff = ['added' => $added, 'removed' => $removed, 'changed' => $changed, 'net' => count($newKeys) - count($oldKeys)]; $warnings[] = "$replaced previous line(s) for this month were replaced"; }
            if ($st['cost_current']) $warnings[] = "{$st['cost_current']} line(s) used the current marketplace price (no price history for that date)";
            if ($st['cost_product']) $warnings[] = "{$st['cost_product']} line(s) used the product's main price (no marketplace price set)";
            if ($st['merged']) $warnings[] = "{$st['merged']} line(s) were already in the tool from the daily orders and were merged, not doubled";
            if ($st['moved']) $warnings[] = "{$st['moved']} line(s) dated outside $mn were moved to the batch of their own month";
            $inBatch = $st['saved'] - $st['moved'];
            DB::table('t_sale_imports')->where('id', $batch)->update(['total_rows' => $st['file_rows'], 'saved_rows' => $inBatch, 'error_rows' => $st['errors'], 'skipped_rows' => $st['skipped'], 'out_of_period_rows' => $st['out_of_period'], 'warnings_json' => json_encode($warnings), 'stats_json' => json_encode($st), 'updated_at' => now()]);
            $msg = "{$st['saved']} sale line(s) imported: {$st['active']} active inventory, {$st['ds']} drop shipping, {$st['unmatched']} not matched.";
            $res = ['message' => $msg, 'batch' => $batch, 'applied' => $apply, 'shop' => $label, 'month' => $mn, 'stats' => $st + ['fee' => (float) $d['fee'], 'va_share_pct' => 0.0], 'warnings' => $warnings, 'detected' => $csv['detected'] ?? null, 'file_detected' => $fn['detected'] ?? null,
                'proof' => ['text' => "file rows {$st['file_rows']} = saved {$st['saved']} + errors {$st['errors']} + skipped {$st['skipped']}", 'ok' => $proofOk], 'diff' => $diff, 'errors' => array_slice($errors, 0, 100), 'errors_more' => max(0, count($errors) - 100),
                'unmatched' => $unmatchedRows, 'unmatched_more' => max(0, $st['unmatched'] - count($unmatchedRows)), 'out_of_period' => ['count' => $st['out_of_period'] + $st['moved'], 'mode' => $d['out_mode'] ?? 'keep', 'text' => ($st['out_of_period'] + $st['moved']) ? ($st['out_of_period'] + $st['moved']) . " lines dated outside $mn" : null]];
            if (! $apply) { DB::rollBack(); return $res; }
            if (! $proofOk) throw new \InvalidArgumentException('The rows of the file do not add up (' . $res['proof']['text'] . '). Nothing was imported.');
            $st['unmatched'] ? TaskEngine::open('SAL_UNMATCHED', [$shop->id, $period], "{$st['unmatched']} lines of {$shop->shop_no} " . date('M Y', strtotime($from)) . ' not matched', ['ent' => $ent, 'module' => 't_branches', 'record_id' => (int) $shop->id, 'fix_url' => "#/sales-import?fix=$batch"])
                : TaskEngine::close('SAL_UNMATCHED', [$shop->id, $period], 'All lines matched');
            TaskEngine::close('SAL_MONTH_MISSING', [$shop->id, $period], 'Imported');
            Audit::log('t_sale_imports', $batch, 'INSERT', ['file' => $fileName, 'saved' => $st['saved'], 'errors' => $st['errors'], 'replaced' => $replaced], null, $ent);
            EventBus::fire('SALES_BATCH_STAGED', 't_sale_imports', $batch, ['saved' => $st['saved'], 'active' => $st['active'], 'unmatched' => $st['unmatched'], 'errors' => $st['errors']], $ent);
            DB::commit();
            return $res;
        } catch (\Throwable $e) { DB::rollBack(); throw $e; }
    }

    // ---------- R-A_SALES-17: daily / period totals (shops without order files) ----------
    private static function totalsCsv(string $path): array
    {
        $raw = @file_get_contents($path); if ($raw === false) throw new \InvalidArgumentException('The uploaded file could not be opened.');
        $rows = []; $n = 0; foreach (preg_split('/\r\n|\n|\r/', preg_replace('/^\xEF\xBB\xBF/', '', $raw)) as $l) { $n++; if (trim($l) === '') continue; $c = str_getcsv($l, substr_count($l, ';') > substr_count($l, ',') ? ';' : ',', '"', '\\'); if (Parse::date($c[0] ?? '') === false || Parse::date($c[0] ?? '') === '') continue; $rows[] = [$n, ['date' => $c[0], 'sale' => $c[1] ?? '', 'purchase' => $c[2] ?? '', 'qty' => $c[3] ?? '']]; }
        return ['rows' => $rows, 'detected' => 'Daily totals: date, sale amount, purchase amount, qty'];
    }

    private static function totalsRow(int $batch, object $shop, int $ln, array $row, array &$st, \Closure $err, string $cur, int $ent, ?object $u): void
    {
        $date = Parse::date($row['date']); $sale = Parse::money($row['sale']); $buy = Parse::money($row['purchase']); $qty = trim((string) $row['qty']) === '' ? 1 : (int) $row['qty'];
        if ($sale === false || $sale === null) { $err($ln, (string) $row['date'], '', "Sale amount '{$row['sale']}' is not a number", $row); return; }
        if ($buy === false) { $err($ln, (string) $row['date'], '', "Purchase amount '{$row['purchase']}' is not a number", $row); return; }
        $buy = (float) ($buy ?? 0); $key = ['fk_id_ent' => $ent, 'fk_id_branch' => $shop->id, 'd_order' => $date, 'sale_source' => 'summary'];      // one line per date per shop
        $line = ['fk_id_import' => $batch, 'fk_id_marketplace' => (int) $shop->fk_id_marketplace, 'order_no' => 'TOTAL-' . $date, 'sku' => 'SUMMARY', 'product_info' => 'Daily total', 'qty' => max(1, $qty), 'sale_rate' => $sale, 'total_amount' => $sale, 'buy_rate' => $buy, 'buy_amount' => $buy, 'profit' => round($sale - $buy, 2),
            'status' => 'Pending', 'f_stock_deducted' => 0, 'fk_id_currency' => BuyingService::currencyId($cur), 'total_amount_gbp' => Fx::toBase((float) $sale, $cur, $date, $ent), 'batch_row_no' => $ln, 'fulfilment_channel' => 'OTHER', 'updated_at' => now()];
        $have = DB::table('t_sales')->where($key)->first();
        if ($have) { if ($have->status !== 'Pending') { $err($ln, 'TOTAL-' . $date, '', 'This day is already confirmed', $row); return; } DB::table('t_sales')->where('id', $have->id)->update($line); }
        else DB::table('t_sales')->insert(RecordService::fillNotNull('t_sales', $key + $line + ['created_by' => $u->id ?? null, 'created_at' => now()]));
        $st['saved']++; $st['qty'] += max(1, $qty); $st['sale'] += (float) $sale; $st['buy'] += $buy; $st['profit'] += round($sale - $buy, 2);
    }

    // ---------- R-A_SALES-18: the register of imported months ----------
    public static function register(?object $u): array
    {
        $ent = (int) Context::ent();
        $rows = Perm::mine(DB::table('t_sale_imports as i'), 'i.fk_id_branch')->join('t_branches as b', 'b.id', '=', 'i.fk_id_branch')->leftJoin('cat_marketplaces as m', 'm.id', '=', 'i.fk_id_marketplace')->leftJoin('t_users as p', 'p.id', '=', 'i.imported_by')
            ->where('i.fk_id_ent', $ent)->where('i.status', 'Pending')->orderByDesc('i.period_year')->orderByDesc('i.period_month')->orderBy('b.shop_no')->limit(200)
            ->get(['i.*', 'b.shop_no', 'b.shop_name', DB::raw("COALESCE(NULLIF(m.marketplace_short,''), m.marketplace_descr) as marketplace"), 'p.display_name as by_name'])
            ->map(function ($i) { $q = DB::table('t_sales')->where('fk_id_import', $i->id);
                return ['id' => $i->id, 'shop' => $i->fk_id_branch, 'shop_no' => $i->shop_no, 'shop_name' => $i->shop_name, 'marketplace' => $i->marketplace, 'month' => self::monthName((int) $i->period_month, (int) $i->period_year), 'period' => sprintf('%04d-%02d', $i->period_year, $i->period_month), 'file' => $i->file_name, 'nature' => $i->import_nature,
                    'lines' => (clone $q)->count(), 'ds' => (clone $q)->where('sale_source', 'ds')->count(), 'out' => (int) $i->out_of_period_rows, 'errors' => (int) $i->error_rows, 'unmatched' => (clone $q)->where('sale_source', 'unmatched')->count(), 'deducted' => (clone $q)->where('f_stock_deducted', 1)->count(),
                    'fee' => (float) $i->marketplace_fee, 'va_pct' => (float) $i->va_share_percentage, 'imported_at' => $i->imported_at ? date('d-m-Y H:i', strtotime((string) $i->imported_at)) : '', 'imported_by' => $i->by_name, 'status' => $i->status]; })->all();
        // shop × month for the last 12 months: Pending / Confirmed / missing
        $shops = Perm::mine(DB::table('t_branches'), 'id')->where('fk_id_ent', $ent)->where('f_active', 1)->orderBy('shop_no')->get(['id', 'shop_no', 'shop_name']); $months = [];
        for ($k = 0; $k < 12; $k++) { $t = mktime(0, 0, 0, (int) date('n') - $k, 1, (int) date('Y')); $months[] = ['key' => date('Y-m', $t), 'label' => date('M y', $t), 'm' => (int) date('n', $t), 'y' => (int) date('Y', $t)]; }
        $all = DB::table('t_sale_imports')->where('fk_id_ent', $ent)->get()->groupBy(fn ($i) => $i->fk_id_branch . ':' . sprintf('%04d-%02d', $i->period_year, $i->period_month));
        $grid = $shops->map(fn ($s) => ['shop' => $s->id, 'label' => self::label($s), 'cells' => array_map(function ($mo) use ($s, $all) { $b = $all[$s->id . ':' . $mo['key']] ?? null; $st = ! $b ? 'missing' : ($b->contains('status', 'Pending') ? 'Pending' : 'Confirmed'); return ['key' => $mo['key'], 'status' => $st, 'batch' => $b ? $b->first()->id : null]; }, $months)])->all();
        return ['rows' => $rows, 'months' => $months, 'grid' => $grid, 'note' => 'One import per shop per month. Delete a month to remove all its sales, then import it again.', 'empty' => 'Nothing imported yet.'];
    }

    public static function batch(int $id): object
    {
        $b = DB::table('t_sale_imports')->where('id', $id)->where('fk_id_ent', Context::ent())->first() ?? abort(404, 'Import not found.');
        if (! Perm::shopAllowed(\Illuminate\Support\Facades\Auth::user(), (int) $b->fk_id_branch)) abort(403, "You don't have rights for this shop.");
        return $b;
    }

    /** Errors popup "Lines not imported": at most 1000 rows. */
    public static function errors(int $batchId): array
    {
        $b = self::batch($batchId); $shop = DB::table('t_branches')->where('id', $b->fk_id_branch)->first();
        return ['title' => 'Lines not imported: ' . ($shop->shop_no ?? '') . ' - ' . self::monthName((int) $b->period_month, (int) $b->period_year), 'empty' => 'No errors.',
            'rows' => DB::table('t_sale_import_errors')->where('fk_id_import', $batchId)->orderBy('row_no')->limit(1000)->get(['row_no as line', 'order_no', 'sku', 'reason'])->map(fn ($r) => (array) $r)->all()];
    }

    /** Lines of a batch that are not matched to a product (for the fix-up grid and the CSV download). */
    public static function unmatched(int $batchId, int $limit = 2000): array
    {
        self::batch($batchId);
        return DB::table('t_sales')->where('fk_id_import', $batchId)->where('sale_source', 'unmatched')->orderBy('batch_row_no')->limit($limit)->get(['id', 'batch_row_no as line', 'order_no', 'sku', 'cust_name as buyer', 'product_info as product', 'qty', 'total_amount as total', 'd_order', 'ds_note as why'])
            ->map(fn ($r) => ['sku' => str_starts_with((string) $r->sku, 'NOSKU-') ? '' : $r->sku, 'no_sku' => str_starts_with((string) $r->sku, 'NOSKU-')] + (array) $r)->all();
    }

    // ---------- R-A_SALES-15 / 9: fix-up – link a line to a product, and remember it for the next import ----------
    public static function skuCheck(string $sku): array
    {
        $p = DB::table('t_product_vendors')->where('fk_id_ent', Context::ent())->whereRaw('LOWER(TRIM(SKU_VENDOR)) = ?', [mb_strtolower(trim($sku))])->first(['id', 'SKU_VENDOR', 'product_name_vendor']);
        return $p ? ['ok' => true, 'message' => '✓ Valid SKU', 'product' => $p->product_name_vendor, 'sku' => $p->SKU_VENDOR] : ['ok' => false, 'message' => '✗ SKU not found'];
    }

    /** Search while typing: by SKU or by words of the title. */
    public static function skuSearch(string $q, int $limit = 8): array
    {
        $q = trim($q); if (mb_strlen($q) < 2) return [];
        return DB::table('t_product_vendors')->where('fk_id_ent', Context::ent())->where(fn ($w) => $w->where('SKU_VENDOR', 'like', '%' . addcslashes($q, '%_\\') . '%')->orWhere('product_name_vendor', 'like', '%' . addcslashes($q, '%_\\') . '%'))->orderBy('SKU_VENDOR')->limit($limit)->get(['SKU_VENDOR as sku', 'product_name_vendor as name'])->map(fn ($r) => (array) $r)->all();
    }

    public static function link(int $saleId, string $sku, ?object $u): array
    {
        return DB::transaction(function () use ($saleId, $sku, $u) {
            $s = DB::table('t_sales')->where('id', $saleId)->where('fk_id_ent', Context::ent())->lockForUpdate()->first() ?? abort(404, 'Sale line not found.');
            Perm::need($u, 'orders.saleImport'); if (! Perm::shopAllowed($u, (int) $s->fk_id_branch)) abort(403, "You don't have rights for this shop.");
            if ($s->sale_source !== 'unmatched') throw new \InvalidArgumentException('This line is already matched to a product.');
            if ($s->status !== 'Pending' || (int) $s->f_locked) throw new \InvalidArgumentException("Only Pending lines can be changed. This line is {$s->status}.");
            $p = DB::table('t_product_vendors')->where('fk_id_ent', $s->fk_id_ent)->whereRaw('LOWER(TRIM(SKU_VENDOR)) = ?', [mb_strtolower(trim($sku))])->first();
            if (! $p) throw new \InvalidArgumentException('SKU not found');
            $shop = DB::table('t_branches')->where('id', $s->fk_id_branch)->first(); $nosku = str_starts_with((string) $s->sku, 'NOSKU-');
            $cost = SaleLines::intakeCost($p, $shop, (string) $s->d_order, (int) $s->qty, (float) $s->sale_rate, (float) $s->total_amount, (int) $s->fk_id_ent);
            DB::table('t_sales')->where('id', $s->id)->update(array_filter($cost + ['fk_product_id' => $p->id, 'sale_source' => 'active', 'ds_note' => '', 'd_buy' => $p->d_purchase_date ?? null, 'fk_id_vendor' => (int) ($p->fk_id_vendor ?? 0) ?: null, 'f_edited' => 1, 'updated_at' => now()], fn ($x) => $x !== null));
            // remembered per marketplace: the same SKU text (or, for a line without SKU, the same title) maps by itself next time
            $key = mb_substr(mb_strtolower(trim($nosku ? (string) $s->product_info : (string) $s->sku)), 0, 190);
            if ($key !== '') DB::table('mo_sku_aliases')->updateOrInsert(['fk_id_ent' => $s->fk_id_ent, 'fk_id_marketplace' => (int) $s->fk_id_marketplace, 'kind' => $nosku ? 'TITLE' : 'SKU', 'alias_key' => $key], ['fk_id_product' => $p->id, 'created_by' => $u->id ?? null, 'updated_at' => now(), 'created_at' => now()]);
            if (($s->fulfilment_channel ?: 'SELF') === 'SELF') DispatchService::reserve((int) $s->id);
            DB::table('t_sale_change_log')->insert(['fk_id_sale' => $s->id, 'save_key' => uniqid('l'), 'action' => 'Link to product', 'field_name' => 'fk_product_id', 'field_label' => 'Product', 'old_value' => 'Not matched', 'new_value' => $p->SKU_VENDOR, 'fk_id_user' => $u->id ?? null, 'changed_by' => $u ? ($u->display_name ?: $u->login_id) : 'system', 'changed_at' => now()]);
            $period = substr((string) $s->d_order, 0, 7); $left = DB::table('t_sales')->where('fk_id_import', $s->fk_id_import)->where('sale_source', 'unmatched')->count();
            if (! $left) TaskEngine::close('SAL_UNMATCHED', [$s->fk_id_branch, $period], 'All lines matched');
            return ['message' => 'Linked to ' . $p->SKU_VENDOR . ' and remembered for the next import.', 'left' => $left];
        });
    }

    // ---------- R-D-78 / R-A_SALES-40: undo (delete) one import ----------
    public static function deleteBatch(int $id, ?object $u): array
    {
        return DB::transaction(function () use ($id, $u) {
            $b = self::batch($id); Perm::need($u, 'orders.saleDeleteMonth'); $mn = self::monthName((int) $b->period_month, (int) $b->period_year);
            if ($b->status !== 'Pending' || DB::table('t_sales')->where('fk_id_import', $id)->where(fn ($w) => $w->where('status', 'Confirmed')->orWhere('f_locked', 1))->exists()) throw new \InvalidArgumentException("This month is confirmed and saved permanently, so it can't be deleted.");
            $group = "batch:$id:" . time(); $n = 0; $kept = 0;
            foreach (DB::table('t_sales')->where('fk_id_import', $id)->lockForUpdate()->get() as $s) {
                if (self::fromIntake($s, $b)) { DB::table('t_sales')->where('id', $s->id)->update(['fk_id_import' => 0]); $kept++; continue; } $n++;
                DB::table('mo_recycle_bin')->insert(['fk_id_ent' => (int) $s->fk_id_ent, 'table_name' => 't_sales', 'row_id' => $s->id, 'label' => mb_substr("Order {$s->order_no} · {$s->sku}", 0, 255), 'group_key' => $group, 'row_json' => json_encode((array) $s), 'deleted_by' => $u->id ?? null, 'deleted_at' => now(), 'note' => "Sales for $mn deleted"]);
                if ($s->sale_source === 'active') DispatchService::release((int) $s->id, "Sales for $mn deleted"); }
            DB::table('mo_recycle_bin')->insert(['fk_id_ent' => (int) $b->fk_id_ent, 'table_name' => 't_sale_imports', 'row_id' => $b->id, 'label' => "Import {$b->file_name}", 'group_key' => $group, 'row_json' => json_encode((array) $b), 'deleted_by' => $u->id ?? null, 'deleted_at' => now(), 'note' => "Sales for $mn deleted"]);
            DB::table('t_sales')->where('fk_id_import', $id)->delete(); DB::table('t_sale_import_errors')->where('fk_id_import', $id)->delete(); DB::table('t_sale_imports')->where('id', $id)->delete();
            TaskEngine::close('SAL_UNMATCHED', [$b->fk_id_branch, sprintf('%04d-%02d', $b->period_year, $b->period_month)], 'Month deleted');
            Audit::log('t_sale_imports', $id, 'DELETE', ['file' => $b->file_name, 'lines' => $n], "Sales for $mn deleted", (int) $b->fk_id_ent);
            return ['message' => "Sales for $mn deleted." . ($kept ? " $kept line(s) from the daily orders were kept." : ''), 'lines' => $n, 'kept' => $kept, 'undo' => $group];
        });
    }

    /** Put a deleted import back (its batch row and its lines), while nothing took its place. */
    public static function restoreBatch(string $group, ?object $u): array
    {
        return DB::transaction(function () use ($group, $u) {
            Perm::need($u, 'orders.saleDeleteMonth');
            $b = DB::table('mo_recycle_bin')->where('group_key', $group)->where('table_name', 't_sale_imports')->where('fk_id_ent', Context::ent())->whereNull('restored_at')->first();
            if ($b) { $row = json_decode($b->row_json, true);
                if (self::batchOf((int) $row['fk_id_branch'], (int) $row['period_month'], (int) $row['period_year'])) throw new \InvalidArgumentException('This shop and month were imported again since – delete that import first.');
                DB::table('t_sale_imports')->insert($row); DB::table('mo_recycle_bin')->where('id', $b->id)->update(['restored_at' => now()]); }
            $r = SaleLines::restore($group);
            if ($b) DB::table('t_sale_imports')->where('id', $row['id'])->update(['saved_rows' => DB::table('t_sales')->where('fk_id_import', $row['id'])->count()]);
            return $r;
        });
    }

    /** Night job: an active shop with no import for last month after day 5 gets the task SAL_MONTH_MISSING (closed by the import). */
    public static function missingMonths(?string $today = null): int
    {
        $t = strtotime($today ?: 'today'); if ((int) date('j', $t) <= 5) return 0; $n = 0;
        $pm = mktime(0, 0, 0, (int) date('n', $t) - 1, 1, (int) date('Y', $t)); $m = (int) date('n', $pm); $y = (int) date('Y', $pm); $period = date('Y-m', $pm);
        foreach (DB::table('t_branches')->where('f_active', 1)->get() as $s) {
            $has = DB::table('t_sale_imports')->where('fk_id_branch', $s->id)->where('period_month', $m)->where('period_year', $y)->exists() || DB::table('t_sales')->where('fk_id_branch', $s->id)->whereBetween('d_order', [date('Y-m-01', $pm), date('Y-m-t', $pm)])->where('sale_source', '<>', 'history')->exists();
            if ($has) { TaskEngine::close('SAL_MONTH_MISSING', [$s->id, $period], 'Imported'); continue; }
            if (! DB::table('t_sale_imports')->where('fk_id_branch', $s->id)->exists()) continue;      // a shop that never imported a file works another way (API / hand)
            if (DB::table('tasks')->where('dedupe_key', "SAL_MONTH_MISSING:{$s->id}:$period")->whereNotIn('status', ['DONE', 'APPROVED', 'CANCELLED'])->exists()) continue;
            if (TaskEngine::open('SAL_MONTH_MISSING', [$s->id, $period], 'No sales imported for ' . self::label($s) . ' – ' . date('F Y', $pm), ['ent' => (int) $s->fk_id_ent, 'module' => 't_branches', 'record_id' => (int) $s->id, 'fix_url' => '#/sales-import'])) $n++;
        }
        return $n;
    }
}
