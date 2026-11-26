<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * "Convert file" (R-A_SALES-78): any CSV → the standard sheet (Date, Order ID, Item Title, Customer, Address, Quantity, Sale / Buy Price, SKU), with saved formats.
 * The result is downloaded or goes straight into the daily fulfilment queue of a shop.
 * "Update existing data" (R-A_SALES-79): a file changes only the mapped columns of existing sale lines (key shop + order no + SKU), with a diff preview; confirmed lines are refused; every change is in the line's history.
 */
class CsvConvert
{
    /** system column => label. All must be mapped; an address part may be mapped to "(empty)" on purpose. */
    public const COLUMNS = ['date' => 'Date', 'order_id' => 'Order ID', 'item_title' => 'Item Title', 'first_name' => 'Customer First Name', 'last_name' => 'Customer Last Name',
        'address_1' => 'Address 1', 'address_2' => 'Address 2', 'address_3' => 'Address 3', 'address_4' => 'Address 4', 'address_5' => 'Address 5', 'address_6' => 'Address 6', 'address_7' => 'Address 7', 'address_8' => 'Address 8',
        'quantity' => 'Quantity', 'price' => 'Sale / Buy Price', 'sku' => 'SKU'];
    public const EMPTY = '(empty)';
    /** "Update existing data": the columns a file may change on a sale line (field => [label, t_sales column, max length]). */
    public const UPDATABLE = ['tracking_no' => ['Tracking number', 'tracking_no', 60], 'carrier_service' => ['Carrier service', 'carrier_service_text', 100], 'postcode' => ['Ship-to postcode', 'ship_to_postcode', 30], 'cust_name' => ['Customer name', 'cust_name', 255], 'cust_contacts' => ['Customer contacts', 'cust_contacts', 255], 'product_info' => ['Product info', 'product_info', 500]];

    private static function dir(): string { $d = storage_path('app/csv_convert'); if (! is_dir($d)) mkdir($d, 0775, true); return $d; }

    /** Step 1: .csv only, held as <user>_<YmdHis>_<rand>.csv outside the web folder. */
    public static function upload(string $path, string $name, ?object $u): array
    {
        Perm::need($u, 'orders.csvConvert'); if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'csv') throw new \InvalidArgumentException('The file must be a .csv file.');
        $csv = DsUpload::read($path); if (! $csv['rows']) throw new \InvalidArgumentException('The file has a header row but no rows.');
        // old held files are removed by the nightly job mo:cleanup-temp (R-G_CORE-75), never on a page action
        $token = $u->id . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)); copy($path, self::dir() . "/$token.csv"); file_put_contents(self::dir() . "/$token.json", json_encode(['user' => $u->id, 'ent' => Context::ent(), 'name' => $name]));
        $samples = []; foreach ($csv['headers'] as $i => $h) { $samples[] = ['letter' => self::letter($i), 'header' => $h, 'sample' => mb_substr((string) ($csv['rows'][0][1][$h] ?? ''), 0, 60)]; }
        return ['token' => $token, 'file' => $name, 'rows' => count($csv['rows']), 'headers' => $csv['headers'], 'samples' => $samples, 'formats' => self::formats($u), 'columns' => self::COLUMNS, 'updatable' => array_map(fn ($x) => $x[0], self::UPDATABLE),
            'can_map' => Perm::has($u, 'orders.csvFormats'), 'can_delete' => Perm::has($u, 'import.mapping.manage'), 'can_approve' => Perm::has($u, 'import.mapping.manage'), 'can_update' => Perm::has($u, 'orders.importUpdate'), 'map_note' => Perm::has($u, 'orders.csvFormats') ? null : 'They will appear once they are ready by the Super Admin'];
    }

    public static function letter(int $i): string { $s = ''; $i++; while ($i > 0) { $m = ($i - 1) % 26; $s = chr(65 + $m) . $s; $i = intdiv($i - 1, 26); } return $s; }

    private static function held(string $token, ?object $u): array
    {
        if (! preg_match('/^\d+_\d{14}_[0-9a-f]{8}$/', $token) || ! is_file(self::dir() . "/$token.json")) throw new \InvalidArgumentException('The uploaded file is no longer held (held files are removed by the nightly clean-up). Upload it again.');
        $meta = json_decode((string) file_get_contents(self::dir() . "/$token.json"), true); if ((int) $meta['user'] !== (int) ($u->id ?? 0) || (int) $meta['ent'] !== (int) Context::ent()) throw new \InvalidArgumentException('This uploaded file belongs to another person.');
        return $meta + ['csv' => DsUpload::read(self::dir() . "/$token.csv")];
    }

    public static function formats(?object $u): array
    {
        /* R-D-54: without the right "import.mapping.manage" a person picks from the approved formats (and sees the ones they saved themselves, marked as waiting) */
        $all = Perm::has($u, 'import.mapping.manage');
        return DB::table('csv_generator_history')->where('import_controller_name', 'standard_sheet')->when(! $all, fn ($q) => $q->where(fn ($w) => $w->where('f_approved', 1)->orWhere('created_by', (int) ($u->id ?? 0))))->orderBy('import_name')->get()->map(function ($f) { $sys = (array) json_decode((string) $f->system_columns, true); $csv = (array) json_decode((string) $f->csv_columns, true);
            return ['id' => (int) $f->id, 'name' => $f->import_name, 'approved' => (bool) $f->f_approved, 'map' => array_combine(array_keys($sys) ? $sys : [], $csv ?: []) ?: [], 'letters' => array_map(fn ($c) => is_int($c) ? self::letter($c) : $c, $csv)]; })->all();
    }

    /** Save a format by name (Owner / Admin): system columns + CSV column indexes (shown as letters). Every column must be mapped. */
    public static function saveFormat(string $name, array $map, ?object $u, ?array $headers = null): array
    {
        Perm::need($u, 'orders.csvFormats'); $name = trim($name); if ($name === '' || mb_strlen($name) > 200) throw new \InvalidArgumentException('Give the format a name (up to 200 characters).');
        $e = []; $sys = []; $cols = []; foreach (self::COLUMNS as $k => $lab) { $v = $map[$k] ?? null; if ($v === null || $v === '') { $e[$k] = "Map $lab."; continue; } if ($v !== self::EMPTY && ! preg_match('/^\d+$/', (string) $v)) { $e[$k] = "Map $lab to a column of the file."; continue; } if ($v === self::EMPTY && ! str_starts_with($k, 'address_')) { $e[$k] = "$lab cannot be empty."; continue; } $sys[] = $k; $cols[] = $v === self::EMPTY ? self::EMPTY : (int) $v; }
        if ($e) throw \Illuminate\Validation\ValidationException::withMessages($e);
        $row = ['import_name' => $name, 'system_columns' => json_encode($sys), 'csv_columns' => json_encode($cols), 'import_controller_name' => 'standard_sheet', 'fk_id_account' => (int) ($u->fk_id_account ?? 0), 'created_by' => $u->id, 'created_at' => now()];
        $ok = Perm::has($u, 'import.mapping.manage'); $row += ['f_approved' => $ok ? 1 : 0, 'approved_by' => $ok ? $u->id : null, 'approved_at' => $ok ? now() : null];      /* R-D-54 a format saved by someone else waits for approval */
        $id = DB::table('csv_generator_history')->where('import_controller_name', 'standard_sheet')->where('import_name', $name)->value('id');
        if ($id && ! $ok && (int) DB::table('csv_generator_history')->where('id', $id)->value('created_by') !== (int) $u->id) throw new \InvalidArgumentException("A format named \"$name\" already exists – choose another name.");
        if ($id) DB::table('csv_generator_history')->where('id', $id)->update($row); else $id = DB::table('csv_generator_history')->insertGetId($row); Audit::log('csv_generator_history', (int) $id, $id ? 'UPDATE' : 'INSERT', ['name' => $name]);
        $tpl = self::asExportTemplate($name, $u);      /* R-A_SALES-78 the format is also an export template of the sales list (List Standard) */
        return ['message' => "Format \"$name\" saved" . ($ok ? '' : ' – others see it once it is approved') . ($tpl ? ' – also an export template of the sales list.' : '.'), 'id' => (int) $id, 'template_id' => $tpl, 'formats' => self::formats($u)];
    }

    public const TEMPLATE_PREFIX = 'Standard sheet – ';
    /** The standard sheet's columns in the sales list: Date, Order ID, Item Title, Customer, Address (postcode), Quantity, Price, SKU. */
    public const TEMPLATE_COLUMNS = ['d_order', 'order_no', 'product_info', 'cust_name', 'ship_to_postcode', 'qty', 'sale_rate', 'sku'];

    public static function asExportTemplate(string $name, ?object $u): ?int
    {
        if (! $u || ! Registry::exists('sale_line')) return null;
        return ListExports::save($u, 'sale_line', ['name' => mb_substr(self::TEMPLATE_PREFIX . $name, 0, 120), 'columns' => self::TEMPLATE_COLUMNS, 'format' => 'CSV', 'shared_role' => Perm::has($u, 'admin.saveView_may') ? '*' : null]);
    }

    public static function deleteFormat(int $id, ?object $u): array
    {
        if (! Perm::has($u, 'import.mapping.manage')) throw new \InvalidArgumentException('Only the Owner can delete a format.');
        $f = DB::table('csv_generator_history')->where('id', $id)->where('import_controller_name', 'standard_sheet')->first() ?? throw new \InvalidArgumentException('Format not found.'); DB::table('csv_generator_history')->where('id', $id)->delete(); Audit::log('csv_generator_history', $id, 'DELETE', ['name' => $f->import_name]); DB::table('mo_export_templates')->where('list_key', 'sale_line')->where('name', self::TEMPLATE_PREFIX . $f->import_name)->delete();
        return ['message' => "Format \"{$f->import_name}\" deleted.", 'formats' => self::formats($u)];
    }

    /** R-D-54 approve / share a saved format, or take the approval back. */
    public static function approveFormat(int $id, bool $approved, ?object $u): array
    {
        Perm::need($u, 'import.mapping.manage');
        $f = DB::table('csv_generator_history')->where('id', $id)->where('import_controller_name', 'standard_sheet')->first() ?? throw new \InvalidArgumentException('Format not found.');
        DB::table('csv_generator_history')->where('id', $id)->update(['f_approved' => $approved ? 1 : 0, 'approved_by' => $approved ? $u->id : null, 'approved_at' => $approved ? now() : null]);
        Audit::log('csv_generator_history', $id, $approved ? 'APPROVE' : 'REJECT', ['f_approved' => [(int) $f->f_approved, $approved ? 1 : 0]], $f->import_name);
        return ['message' => "Format \"{$f->import_name}\" " . ($approved ? 'approved – everyone can pick it now.' : 'is no longer shared.'), 'formats' => self::formats($u)];
    }

    private static function mapOf(array $o, ?object $u): array
    {
        if (! empty($o['format'])) { $f = collect(self::formats($u))->firstWhere('id', (int) $o['format']) ?? throw new \InvalidArgumentException('Format not found.'); return $f['map']; }
        Perm::need($u, 'orders.csvFormats'); return (array) ($o['map'] ?? []);
    }

    private static function clean(string $v): string { return trim(str_replace(["\u{00C2}\u{00A0}", "\u{00C2}"], [' ', ''], $v)); }      // a stray "Â" (UTF-8 read as Latin-1) is removed

    /** The standard rows of the file under a mapping. */
    public static function rows(string $token, array $o, ?object $u): array
    {
        Perm::need($u, 'orders.csvConvert'); $meta = self::held($token, $u); $map = self::mapOf($o, $u); $h = $meta['csv']['headers'];
        foreach (self::COLUMNS as $k => $lab) { $v = $map[$k] ?? null; if ($v === null || $v === '' || ($v !== self::EMPTY && ! isset($h[(int) $v]))) throw new \InvalidArgumentException("$lab is not mapped to a column of this file."); }
        $get = fn (array $r, string $k) => $map[$k] === self::EMPTY ? '' : self::clean((string) ($r[$h[(int) $map[$k]]] ?? ''));
        $out = []; foreach ($meta['csv']['rows'] as [$ln, $r]) { $d = $get($r, 'date'); $p = $d !== '' ? Parse::date($d) : false;
            $out[] = ['line' => $ln, 'date' => $p ? date('d.m.Y', strtotime($p)) : $d, 'iso_date' => $p ?: null, 'order_id' => $get($r, 'order_id'), 'item_title' => $get($r, 'item_title'), 'customer' => trim($get($r, 'first_name') . ' ' . $get($r, 'last_name')), 'first_name' => $get($r, 'first_name'), 'last_name' => $get($r, 'last_name'),
                'address_parts' => array_values(array_filter(array_map(fn ($i) => $get($r, "address_$i"), range(1, 8)), fn ($x) => $x !== '')), 'quantity' => $get($r, 'quantity'), 'price' => $get($r, 'price'), 'sku' => $get($r, 'sku')]; }
        return ['rows' => $out, 'file' => $meta['name']];
    }

    /** Download: Date, Order ID, Item Title, Customer, Address, Quantity, Sale Price | Buy Price, SKU. The source file is deleted. */
    public static function download(string $token, array $o, ?object $u): array
    {
        $x = self::rows($token, $o, $u); $label = strtoupper((string) ($o['price_label'] ?? 'SALE')) === 'BUY' ? 'Buy Price' : 'Sale Price';
        $h = fopen('php://temp', 'w+'); fwrite($h, "\xEF\xBB\xBF"); fputcsv($h, ['Date', 'Order ID', 'Item Title', 'Customer', 'Address', 'Quantity', $label, 'SKU'], ',', '"', '');
        foreach ($x['rows'] as $r) fputcsv($h, array_map(fn ($v) => DsMatch::guard((string) $v), [$r['date'], $r['order_id'], $r['item_title'], $r['customer'], implode(', ', $r['address_parts']), $r['quantity'], $r['price'], $r['sku']]), ',', '"', '');
        rewind($h); $body = stream_get_contents($h); fclose($h); foreach (['csv', 'json'] as $e) @unlink(self::dir() . "/$token.$e");
        return ['name' => 'Download_' . preg_replace('/[^A-Za-z0-9._-]+/', '_', $x['file']), 'body' => $body, 'rows' => count($x['rows'])];
    }

    /** Straight into the daily fulfilment queue of a shop (no download step): one order per row; rows with a problem are listed, the others saved. */
    public static function intoQueue(string $token, array $o, int $shopId, ?object $u): array
    {
        Perm::need($u, 'orders.fulfilAdd'); $x = self::rows($token, $o, $u); $saved = 0; $errors = [];
        foreach ($x['rows'] as $r) { $a = $r['address_parts'];
            try { Fulfilment::save(['shop' => $shopId, 'order_no' => $r['order_id'], 'd_order' => $r['iso_date'] ?? '', 'item_name' => $r['item_title'], 'sku' => $r['sku'], 'qty' => $r['quantity'], 'buyer_first_name' => $r['first_name'], 'buyer_last_name' => $r['last_name'], 'recipient_first_name' => $r['first_name'], 'recipient_last_name' => $r['last_name'],
                'address_1' => $a[0] ?? '', 'address_2' => $a[1] ?? '', 'address_3' => implode(', ', array_slice($a, 2))], null, $u); $saved++; }
            catch (\Illuminate\Validation\ValidationException $e) { $errors[] = ['line' => $r['line'], 'order' => $r['order_id'], 'reason' => implode(' ', array_map(fn ($m) => (array) $m === $m ? implode(' ', $m) : $m, $e->errors()))]; }
            catch (\Throwable $e) { $errors[] = ['line' => $r['line'], 'order' => $r['order_id'], 'reason' => $e->getMessage()]; } }
        if (! $errors) foreach (['csv', 'json'] as $e) @unlink(self::dir() . "/$token.$e");
        return ['message' => "$saved order(s) added to the fulfilment queue" . ($errors ? ', ' . count($errors) . ' row(s) not saved' : '') . '.', 'saved' => $saved, 'errors' => $errors];
    }

    /**
     * "Update existing data" (Owner / Admin): $o = ['key' => ['order_no' => col, 'sku' => col|null], 'fields' => [updatable => col]]. Preview (apply false) lists old → new per field.
     * Match = shop + order no + SKU (order no alone only when that order has one line). Confirmed or locked lines are refused. Only the mapped columns change; every change is a history row.
     */
    public static function update(string $token, int $shopId, array $o, ?object $u, bool $apply): array
    {
        Perm::need($u, 'orders.importUpdate'); $meta = self::held($token, $u); $h = $meta['csv']['headers']; if (! Perm::shopAllowed($u, $shopId) || ! DB::table('t_branches')->where('id', $shopId)->where('fk_id_ent', Context::ent())->exists()) abort(403, "You don't have rights for this shop.");
        $col = fn ($v) => $v === null || $v === '' ? null : ($h[(int) $v] ?? throw new \InvalidArgumentException('A mapped column is not in this file.'));
        $kOrder = $col($o['key']['order_no'] ?? null) ?? throw new \InvalidArgumentException('Map the Order number column (the key).'); $kSku = $col($o['key']['sku'] ?? null);
        $fields = []; foreach ((array) ($o['fields'] ?? []) as $f => $c) if (isset(self::UPDATABLE[$f]) && ($c = $col($c))) $fields[$f] = $c; if (! $fields) throw new \InvalidArgumentException('Map at least one column to change.');
        foreach ($fields as $f => $c) if ($c === $kOrder || ($kSku !== null && $c === $kSku)) throw new \InvalidArgumentException('The column to match and the column to change must be different (' . self::UPDATABLE[$f][0] . ').');      /* R-D-54 */
        $changes = []; $refused = []; $notFound = []; $same = 0;
        foreach ($meta['csv']['rows'] as [$ln, $r]) {
            $no = trim((string) ($r[$kOrder] ?? '')); if ($no === '') continue; $sku = $kSku ? trim((string) ($r[$kSku] ?? '')) : '';
            $q = DB::table('t_sales')->where('fk_id_ent', Context::ent())->where('fk_id_branch', $shopId)->whereRaw('LOWER(order_no) = ?', [mb_strtolower($no)])->where('sale_source', '<>', 'history'); if ($sku !== '') $q->whereRaw('LOWER(sku) = ?', [mb_strtolower($sku)]);
            $lines = $q->limit(2)->get(); if ($lines->count() !== 1) { $notFound[] = ['line' => $ln, 'order' => $no . ($sku !== '' ? " · $sku" : ''), 'reason' => $lines->isEmpty() ? 'No sale line with this order' . ($sku !== '' ? ' and SKU' : '') . ' in this shop.' : 'This order has several lines: map the SKU column too.']; continue; }
            $s = $lines[0]; $diff = [];
            foreach ($fields as $f => $c) { [$lab, $dbc, $len] = self::UPDATABLE[$f]; $new = mb_substr(self::clean((string) ($r[$c] ?? '')), 0, $len); if ($f === 'postcode') $new = mb_strtoupper($new); $old = (string) ($s->$dbc ?? ''); if ($new !== $old) $diff[] = ['field' => $f, 'label' => $lab, 'column' => $dbc, 'old' => $old, 'new' => $new]; }
            if (! $diff) { $same++; continue; }
            if ($s->status !== 'Pending' || (int) $s->f_locked) { $refused[] = ['line' => $ln, 'order' => $s->order_no . ' · ' . $s->sku, 'reason' => "The line is {$s->status}" . ((int) $s->f_locked ? ' and locked' : '') . ': it cannot be changed by a file.']; continue; }
            $changes[] = ['id' => (int) $s->id, 'order' => $s->order_no . ' · ' . $s->sku, 'diff' => $diff];
        }
        $res = ['lines' => count($changes), 'fields' => array_sum(array_map(fn ($c) => count($c['diff']), $changes)), 'unchanged' => $same, 'refused' => $refused, 'not_found' => $notFound, 'preview' => array_slice($changes, 0, 200), 'applied' => $apply,
            'message' => count($changes) . ' line(s) will change' . ($refused ? ', ' . count($refused) . ' refused (confirmed)' : '') . ($notFound ? ', ' . count($notFound) . ' not found' : '') . ($same ? ", $same already the same" : '') . '.'];
        if (! $apply) return $res;
        $key = 'file:' . substr($token, -8); $by = (string) ($u->display_name ?: $u->login_id);
        DB::transaction(function () use ($changes, $key, $by, $u, $meta) {
            foreach ($changes as $c) { DB::table('t_sales')->where('id', $c['id'])->where('status', 'Pending')->update(array_column($c['diff'], 'new', 'column') + ['f_edited' => 1, 'updated_at' => now()]);
                foreach ($c['diff'] as $d) DB::table('t_sale_change_log')->insert(['fk_id_sale' => $c['id'], 'save_key' => mb_substr($key, 0, 40), 'action' => 'FILE_UPDATE', 'field_name' => $d['column'], 'field_label' => $d['label'], 'old_value' => $d['old'], 'new_value' => $d['new'], 'fk_id_user' => $u->id, 'changed_by' => mb_substr($by, 0, 150), 'changed_at' => now()]); }
            Audit::log('t_sales', null, 'UPDATE', ['file' => $meta['name'], 'lines' => count($changes)], 'Update existing data');
        });
        foreach (['csv', 'json'] as $e) @unlink(self::dir() . "/$token.$e");
        return ['message' => count($changes) . ' line(s) changed' . ($refused ? ', ' . count($refused) . ' refused (confirmed)' : '') . ($notFound ? ', ' . count($notFound) . ' not found' : '') . '.'] + $res;
    }
}
