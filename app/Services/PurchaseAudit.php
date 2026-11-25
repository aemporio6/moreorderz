<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * RULES_A_SALES §9 the monthly purchase audit of a shop (old tables t_audit_main / ebay_audit / pur_audit / pur_audit_gd):
 * R-A_SALES-60 the eBay orders file of a run (shop-no file prefix, fixed columns, one transaction, master rows copied to their item rows, total = sold for × qty),
 * R-A_SALES-61 the "My item note" parser (buy evidence → Bought from fields; errors kept on the row, never stop the import),
 * R-A_SALES-62 file against our sales: Missing / Extra / Different (key shop + order + SKU),
 * R-A_SALES-63 Amazon purchase audit ([Price] beyond the tolerance, [Customer]),
 * R-A_SALES-64 GD supplier audit (HOLD / BUY rows, inheritance, 5 checks, hold ageing task DS_HOLD_UNUSED).
 * Rights: running = orders.CONFIRM (Owner / Manager / TL) and the shop in the user's scope; contact columns follow orders.dsSeeContact.
 */
class PurchaseAudit
{
    /** The eBay orders report columns, in file order (69). */
    public const EBAY = ['Sales_record_number', 'Order_number', 'Buyer_username', 'Buyer_name', 'Buyer_email', 'Buyer_note', 'Buyer_address_1', 'Buyer_address_2', 'Buyer_city', 'Buyer_county', 'Buyer_postcode', 'Buyer_country',
        'Buyer_tax_identifier_name', 'Buyer_tax_identifier_value', 'Post_to_name', 'Post_to_phone', 'Post_to_address_1', 'Post_to_address_2', 'Post_to_city', 'Post_to_county', 'Post_to_postcode', 'Post_to_country', 'Item_number',
        'Item_title', 'Custom_label', 'Sold_via_Promoted_listings', 'Quantity', 'Sold_for', 'Postage_and_packaging', 'Item_location', 'Item_postcode', 'Item_country', 'Included_VAT_rate', 'eBay_Collect_And_Remit_Tax_Rate',
        'eBay_Collect_And_Remit_Tax_Type', 'eBay_reference_name', 'eBay_reference_value', 'Tax_status', 'Seller_collected_tax', 'eBay_collected_tax', 'Electronic_waste_recycling_fee', 'Mattress_recycling_fee', 'Battery_recycling_fee',
        'White_goods_disposal_tax', 'Tyre_recycling_fee', 'Additional_fee', 'Total_price', 'eBay_collected_tax_and_fees_included_in_total', 'Payment_method', 'Sale_date', 'Paid_on_date', 'Post_by_date', 'Minimum_estimated_delivery_date',
        'Maximum_estimated_delivery_date', 'Dispatched_on_date', 'Feedback_left', 'Feedback_received', 'My_item_note', 'PayPal_transaction_ID', 'Delivery_service', 'Tracking_number', 'Transaction_ID', 'Variation_details',
        'Global_Shipping_Programme', 'Global_Shipping_Reference_ID', 'Click_and_Collect', 'Click_and_Collect_Reference_Number', 'eBay_Plus', 'eBay_Fulfilment_Programme'];
    /** Copied from the master row of a multi-item order to its item rows. */
    private const FROM_MASTER = ['Buyer_username', 'Buyer_name', 'Buyer_email', 'Buyer_note', 'Buyer_address_1', 'Buyer_address_2', 'Buyer_city', 'Buyer_county', 'Buyer_postcode', 'Buyer_country', 'Post_to_name', 'Post_to_phone',
        'Post_to_address_1', 'Post_to_address_2', 'Post_to_city', 'Post_to_county', 'Post_to_postcode', 'Post_to_country', 'Paid_on_date', 'Global_Shipping_Programme', 'Global_Shipping_Reference_ID', 'Click_and_Collect', 'Click_and_Collect_Reference_Number', 'eBay_Plus'];
    public const GD = ['order_no', 'd_order', 'currency', 'g_pur_price', 'sku_gd', 'title', 'name', 'qty', 'price', 'trading_id', 'tracking_number', 'payer_email', 'address1', 'address2', 'address3', 'city', 'county', 'county2', 'country', 'recipient', 'post_code', 'email', 'phone'];

    /* ---------------- helpers ---------------- */

    public static function csv(string $text): array
    {
        $fh = fopen('php://temp', 'r+'); fwrite($fh, preg_replace('/^\xEF\xBB\xBF/', '', $text)); rewind($fh); $rows = [];
        while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) $rows[] = array_map(fn ($x) => trim((string) $x), $r);
        fclose($fh); return $rows;
    }

    public static function money(?string $v): ?float { $x = preg_replace('/[^0-9.\-]/', '', (string) $v); return $x === '' || ! is_numeric($x) ? null : round((float) $x, 2); }
    private static function norm(?string $s): string { return preg_replace('/[^a-z0-9]/', '', mb_strtolower((string) $s)); }
    private static function date(?string $v): ?string
    {
        $v = trim((string) $v); if ($v === '') return null;
        foreach (['d-M-y', 'd-M-Y', 'd/m/Y', 'd/m/y', 'd-m-y', 'd-m-Y', 'Y-m-d', 'M d, Y', 'd M Y'] as $f) { $d = \DateTime::createFromFormat('!' . $f, $v); if ($d && $d->format($f) === $v) return $d->format('Y-m-d'); }
        $t = strtotime($v); return $t ? date('Y-m-d', $t) : null;
    }

    private static function run(int $id, object $u): object
    {
        $r = DB::table('t_audit_main')->where('id', $id)->first() ?? throw new \InvalidArgumentException('Audit run not found.');
        if (! Perm::shopAllowed($u, (int) $r->fk_id_branch)) throw new \InvalidArgumentException(' User has no rights for that shop');
        return $r;
    }

    private static function vendorsByIso(): array { return DB::table('t_vendors')->where('f_active', 1)->get(['id', 'iso_vendor', 'audit_profile'])->keyBy(fn ($v) => mb_strtoupper(trim((string) $v->iso_vendor)))->all(); }

    /* ---------------- runs ---------------- */

    public static function runs(object $u, array $f = []): array
    {
        Perm::need($u, 'orders.SEE'); $shops = Perm::shops($u);
        /* R-A_SALES-59 chips: period current / previous / "start to" two months ago × status All / Pending / Sale-loaded */
        $cur = date('Y-m-01'); $prev = date('Y-m-01', strtotime('first day of last month')); $m2 = date('Y-m-01', strtotime('first day of -2 months'));
        $per = (string) ($f['period'] ?? ''); $st = strtoupper((string) ($f['status'] ?? ''));
        $rows = DB::table('t_audit_main as a')->join('t_branches as b', 'b.id', '=', 'a.fk_id_branch')->leftJoin('t_vendors as v', 'v.id', '=', 'a.fk_id_vendor')->when($shops !== null, fn ($q) => $q->whereIn('a.fk_id_branch', $shops ?: [0]))
            ->when($per === 'current', fn ($q) => $q->where('a.d_yr_mon', $cur))->when($per === 'previous', fn ($q) => $q->where('a.d_yr_mon', $prev))->when($per === 'start', fn ($q) => $q->where('a.d_yr_mon', '<=', $m2))
            ->when(in_array($st, ['PENDING', 'SALE-LOADED', 'COMPLETED', 'SKIP'], true), fn ($q) => $q->where('a.e_audit_status', $st))
            ->orderByDesc('a.d_yr_mon')->orderBy('b.shop_no')->limit(300)->get(['a.*', 'b.shop_no', 'b.shop_name', 'v.vendor_name', 'v.audit_profile'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'shop' => trim($r->shop_no . ' ' . $r->shop_name), 'month' => substr((string) $r->d_yr_mon, 0, 7), 'status' => $r->e_audit_status,
                'supplier' => $r->vendor_name, 'tiles' => ['ebay' => true, 'amazon' => $r->audit_profile === 'AMAZON', 'gd' => $r->audit_profile === 'GD'],
                'lines' => DB::table('ebay_audit')->where('fk_id_audit_main', $r->id)->count()])->all();
        $vendors = DB::table('t_vendors')->where('f_active', 1)->whereIn('audit_profile', ['AMAZON', 'GD'])->orderBy('vendor_name')->get(['id', 'vendor_name', 'iso_vendor', 'audit_profile']);
        $branches = DB::table('t_branches')->where('f_active', 1)->when($shops !== null, fn ($q) => $q->whereIn('id', $shops ?: [0]))->orderBy('shop_no')->get(['id', 'shop_no', 'shop_name']);
        return ['rows' => $rows, 'vendors' => $vendors, 'shops' => $branches, 'can_run' => Perm::has($u, 'orders.CONFIRM')];
    }

    public static function createRun(int $shop, string $ym, object $u, ?int $vendor = null): int
    {
        Perm::need($u, 'orders.CONFIRM'); if (! Perm::shopAllowed($u, $shop)) throw new \InvalidArgumentException(' User has no rights for that shop');
        if (! preg_match('/^(\d{4})-(\d{2})$/', $ym, $m) || (int) $m[2] < 1 || (int) $m[2] > 12) throw new \InvalidArgumentException('Choose the month.');
        $ex = DB::table('t_audit_main')->where('fk_id_branch', $shop)->where('yr', (int) $m[1])->where('mon', (int) $m[2])->value('id'); if ($ex) return (int) $ex;
        return (int) DB::table('t_audit_main')->insertGetId(['mon' => (int) $m[2], 'yr' => (int) $m[1], 'd_yr_mon' => "$ym-01", 'fk_id_branch' => $shop, 'fk_id_vendor' => $vendor ?: null, 'e_audit_status' => 'PENDING']);
    }

    /** R-A_SALES-59 the run's status by hand: COMPLETED or SKIP (or back to PENDING / SALE-LOADED). */
    public static function setStatus(int $runId, string $status, object $u): array
    {
        Perm::need($u, 'orders.CONFIRM'); self::run($runId, $u); $status = strtoupper(trim($status));
        if (! in_array($status, ['PENDING', 'SALE-LOADED', 'COMPLETED', 'SKIP'], true)) throw new \InvalidArgumentException('Choose a status.');
        if ($status === 'SALE-LOADED' && ! DB::table('ebay_audit')->where('fk_id_audit_main', $runId)->exists()) throw new \InvalidArgumentException('No eBay file is loaded for this run yet.');
        DB::table('t_audit_main')->where('id', $runId)->update(['e_audit_status' => $status]);
        return ['message' => "Run set to $status."];
    }

    /** Delete a run with the audit lines it loaded (eBay file, Amazon and GD purchase files). Sales are never touched. */
    public static function deleteRun(int $runId, object $u): array
    {
        Perm::need($u, 'orders.CONFIRM'); self::run($runId, $u);
        DB::transaction(function () use ($runId) { foreach (['ebay_audit', 'pur_audit', 'pur_audit_gd'] as $t) DB::table($t)->where('fk_id_audit_main', $runId)->delete(); DB::table('t_audit_main')->where('id', $runId)->delete(); });
        return ['message' => 'Audit run deleted with its loaded lines.'];
    }

    /* ---------------- R-A_SALES-61 note parser ---------------- */

    /** [fields, error|null] of one line. */
    public static function parseNote(?string $note, string $sku, ?string $saleDate, int $qty, ?array $vendors = null): array
    {
        $vendors ??= self::vendorsByIso(); $note = (string) $note;
        $out = ['item_note' => mb_substr($note, 0, 500), 'f_local' => 0, 'fk_id_vendor' => null, 'pur_ref_no' => null, 'pur_ref_no_gd_hold' => null, 'd_purchase' => null, 'qty_pur' => null, 'pur_rate' => null, 'tot_pur_amount' => null, 'reason_by_va' => null, 'sku_ebay' => $sku];
        if (preg_match('/\[([^\]]+)\]/', $note, $m)) { $out['sku_ebay'] = trim($m[1]); $note = str_replace($m[0], '', $note); }
        if (preg_match('/\{([^}]*)\}/', $note, $m)) { $out['reason_by_va'] = mb_substr(trim($m[1]), 0, 200); $note = str_replace($m[0], '', $note); }
        if (preg_match('/^(B|VAL)/i', $out['sku_ebay'])) return [['f_local' => 1, 'd_purchase' => $saleDate, 'qty_pur' => $qty] + $out, null];      /* local stock: the cost comes from the lot at Confirm */
        $parts = explode(':', $note); if (count($parts) !== 5) return [$out, null];
        $w = array_map(fn ($p) => preg_split('/\s+/', trim($p))[0] ?? '', $parts);
        $v = $vendors[mb_strtoupper($w[0])] ?? null; if (! $v) return [$out, null];
        $out['fk_id_vendor'] = (int) $v->id; $ref = $w[1];
        if (($v->audit_profile ?? '') === 'GD' && str_contains($ref, '=')) [$ref, $hold] = array_map('trim', explode('=', $ref, 2));
        $out['pur_ref_no'] = mb_substr($ref, 0, 50); $out['pur_ref_no_gd_hold'] = isset($hold) && $hold !== '' ? mb_substr($hold, 0, 50) : null;
        $out['d_purchase'] = self::date($w[2]); $err = [];
        if (ctype_digit($w[3]) && (int) $w[3] > 0) $out['qty_pur'] = (int) $w[3]; else $err[] = "qty \"{$w[3]}\" is not a whole number";
        if (is_numeric($w[4])) $out['pur_rate'] = round((float) $w[4], 2); else $err[] = "rate \"{$w[4]}\" is not a number";
        if ($out['qty_pur'] !== null && $out['pur_rate'] !== null) $out['tot_pur_amount'] = round($out['qty_pur'] * $out['pur_rate'], 2);
        if (! $out['d_purchase']) $err[] = "date \"{$w[2]}\" is not dd-mm-yy";
        return [$out, $err ? implode('; ', $err) : null];
    }

    /* ---------------- R-A_SALES-60 eBay file ---------------- */

    public static function importEbay(int $runId, string $fileName, string $text, object $u): array
    {
        Perm::need($u, 'orders.CONFIRM'); $run = self::run($runId, $u);
        $shop = DB::table('t_branches')->where('id', $run->fk_id_branch)->first();
        $prefix = preg_split('/[_\s\-.]/', basename($fileName))[0] ?? '';
        if ($prefix !== (string) $shop->shop_no) throw new \InvalidArgumentException("[{$shop->shop_no}] / [$prefix] not matched");
        $rows = self::csv($text); $start = null;
        foreach ($rows as $i => $r) if (($r[1] ?? '') !== '') { $start = $i; break; }
        if ($start === null) throw new \InvalidArgumentException('The file has no rows.');
        if (count($rows[$start]) !== count(self::EBAY)) throw new \InvalidArgumentException('No. of Columns not matched , see template');
        if (strcasecmp(str_replace(' ', '_', $rows[$start][0]), 'Sales_record_number') === 0) $start++;      /* the header line */
        $data = []; $masters = []; $skipped = [];
        foreach (array_slice($rows, $start) as $n => $r) {
            if (count($r) !== count(self::EBAY)) { if (array_filter($r)) $skipped[] = ['row' => $n + $start + 1, 'why' => 'column count']; continue; }
            $a = array_combine(self::EBAY, $r); if ($a['Sales_record_number'] === '' || ! ctype_digit($a['Sales_record_number'])) { if (array_filter($r)) $skipped[] = ['row' => $n + $start + 1, 'why' => 'no sales record number']; continue; }
            if ($a['Transaction_ID'] === '') { $masters[$a['Sales_record_number']] = $a; continue; }      /* the master row of a multi-item order */
            $data[] = $a;
        }
        $vendors = self::vendorsByIso(); $out = []; $errors = 0;
        foreach ($data as $a) {
            if (isset($masters[$a['Sales_record_number']])) foreach (self::FROM_MASTER as $k) if ($a[$k] === '') $a[$k] = $masters[$a['Sales_record_number']][$k];
            $qty = $a['Quantity']; $sold = self::money($a['Sold_for']);
            if (! ctype_digit($qty) || $sold === null) throw new \InvalidArgumentException("Sales record {$a['Sales_record_number']}: quantity or sold for is not a number – nothing was imported.");
            $row = []; foreach (self::EBAY as $k) $row[$k] = $a[$k] === '' ? null : $a[$k];
            $row['Quantity'] = (int) $qty; $row['Sold_for'] = $sold; $row['Total_price'] = round($sold * (int) $qty, 2); $row['Sale_date'] = self::date($a['Sale_date']); $row['Sales_record_number'] = (int) $a['Sales_record_number'];
            foreach ($row as $k => $v) if (is_string($v)) $row[$k] = mb_substr($v, 0, in_array($k, ['My_item_note'], true) ? 500 : 250);
            [$note, $err] = self::parseNote($a['My_item_note'], $a['Custom_label'], $row['Sale_date'], (int) $qty, $vendors);
            $out[] = $row + $note + ['fk_id_audit_main' => $runId, 'parse_error' => $err]; if ($err) $errors++;
        }
        DB::transaction(function () use ($runId, $out) {
            DB::table('ebay_audit')->where('fk_id_audit_main', $runId)->delete();
            foreach (array_chunk($out, 200) as $c) DB::table('ebay_audit')->insert(array_map(fn ($r) => RecordService::fillNotNull('ebay_audit', $r), $c));
            DB::table('t_audit_main')->where('id', $runId)->update(['e_audit_status' => 'SALE-LOADED']);
        });
        Audit::log('t_audit_main', $runId, 'UPDATE', ['ebay_rows' => count($out), 'file' => basename($fileName)], 'eBay audit file', Context::ent());
        return ['file' => basename($fileName), 'shop' => $shop->shop_no, 'imported' => count($out), 'skipped' => $skipped, 'note_errors' => $errors,
            'message' => count($out) . ' orders imported, ' . count($skipped) . ' cannot be imported.'];
    }

    /** Notes that did not parse: not local and no supplier, or with an error. */
    public static function invalidNotes(int $runId, object $u): array
    {
        self::run($runId, $u);
        return DB::table('ebay_audit')->where('fk_id_audit_main', $runId)->where(fn ($w) => $w->where(fn ($x) => $x->where('f_local', 0)->whereNull('fk_id_vendor'))->orWhereNotNull('parse_error'))
            ->orderBy('Sale_date')->get(['id', 'Order_number', 'Sale_date', 'sku_ebay', 'Item_title', 'Quantity', 'My_item_note', 'parse_error'])->all();
    }

    /* ---------------- R-A_SALES-62 file against our sales ---------------- */

    public static function compare(int $runId, object $u): array { return self::diffs(self::run($runId, $u), Perm::has($u, 'orders.dsSeeContact')); }

    /** The Missing / Extra / Different lists of a run (also the Month Close check ORDERS_FILE_VS_SALES – no user, no contact columns). */
    public static function diffs(object $run, bool $contact = false): array
    {
        $runId = (int) $run->id; $from = $run->d_yr_mon; $to = date('Y-m-t', strtotime($from));
        $file = DB::table('ebay_audit')->where('fk_id_audit_main', $runId)->get(); $key = fn ($o, $s) => mb_strtoupper(trim((string) $o)) . '|' . mb_strtoupper(trim((string) $s));
        $fk = []; foreach ($file as $f) $fk[$key($f->Order_number, $f->sku_ebay)] = $f;
        $sales = DB::table('t_sales')->where('fk_id_branch', $run->fk_id_branch)->whereBetween('d_order', [$from, $to])->where(fn ($w) => $w->whereNull('line_status')->orWhere('line_status', '<>', 'DELETED'))->get();
        $sk = []; foreach ($sales as $s) $sk[$key($s->order_no, $s->sku)] = $s;
        $missing = []; $diff = []; $extra = [];
        foreach ($fk as $k => $f) {
            $s = $sk[$k] ?? null;
            if (! $s) { $missing[] = ['order' => $f->Order_number, 'date' => $f->Sale_date, 'name' => $contact ? $f->Post_to_name : '•••', 'sku' => $f->sku_ebay, 'title' => $f->Item_title, 'qty' => (int) $f->Quantity, 'sold_for' => (float) $f->Sold_for, 'total' => (float) $f->Total_price]; continue; }
            $d = [];
            if ((int) $s->qty !== (int) $f->Quantity) $d['qty'] = [(int) $f->Quantity, (int) $s->qty];
            if (abs((float) $s->sale_rate - (float) $f->Sold_for) > 0.005) $d['rate'] = [(float) $f->Sold_for, (float) $s->sale_rate];
            if (abs((float) $s->total_amount - (float) $f->Total_price) > 0.005) $d['total'] = [(float) $f->Total_price, (float) $s->total_amount];
            if (trim((string) $s->ds_note) !== trim((string) $f->My_item_note)) $d['note'] = [(string) $f->My_item_note, (string) $s->ds_note];
            if ($d) $diff[] = ['sale' => (int) $s->id, 'order' => $f->Order_number, 'sku' => $f->sku_ebay, 'diff' => $d];
        }
        foreach ($sk as $k => $s) if (! isset($fk[$k])) $extra[] = ['sale' => (int) $s->id, 'order' => $s->order_no, 'date' => $s->d_order, 'customer' => $contact ? $s->cust_name : '•••', 'sku' => $s->sku, 'qty' => (int) $s->qty, 'rate' => (float) $s->sale_rate, 'total' => (float) $s->total_amount,
            'buy_rate' => (float) $s->buy_rate, 'buy_amount' => (float) $s->buy_amount, 'profit' => (float) $s->profit, 'buy_date' => $s->d_buy, 'item_note' => $s->ds_note, 'status' => $s->status];
        return ['missing' => $missing, 'extra' => $extra, 'different' => $diff, 'counts' => ['missing' => count($missing), 'extra' => count($extra), 'different' => count($diff)]];
    }

    /** "Accept file value" on a Different line: qty / rate / total / note of the file onto a sale still Pending. */
    public static function acceptFile(int $runId, int $saleId, object $u): array
    {
        Perm::need($u, 'orders.CONFIRM'); $run = self::run($runId, $u);
        $s = DB::table('t_sales')->where('id', $saleId)->where('fk_id_branch', $run->fk_id_branch)->first() ?? throw new \InvalidArgumentException('Sale not found in this shop.');
        if ($s->status !== 'Pending') throw new \InvalidArgumentException('Only a Pending sale takes the file value – this one is ' . $s->status . '.');
        $f = DB::table('ebay_audit')->where('fk_id_audit_main', $runId)->where('Order_number', $s->order_no)->where('sku_ebay', $s->sku)->first() ?? throw new \InvalidArgumentException('The line is not in the file.');
        $set = ['qty' => (int) $f->Quantity, 'sale_rate' => (float) $f->Sold_for, 'total_amount' => (float) $f->Total_price, 'ds_note' => (string) $f->My_item_note, 'f_edited' => 1, 'updated_at' => now()];
        DB::table('t_sales')->where('id', $saleId)->update($set);
        Audit::log('t_sales', $saleId, 'UPDATE', ['qty' => [(int) $s->qty, $set['qty']], 'sale_rate' => [(float) $s->sale_rate, $set['sale_rate']], 'total_amount' => [(float) $s->total_amount, $set['total_amount']]], 'eBay audit: file value accepted', Context::ent());
        return ['message' => 'The sale now has the file values.'];
    }

    /** R-A_SALES-62 "Create missing sale": a file line not in sales becomes a Pending sale of the shop (unmatched source until it is matched to a listing). */
    public static function createMissing(int $runId, string $order, string $sku, object $u): array
    {
        Perm::need($u, 'orders.CONFIRM'); $run = self::run($runId, $u);
        $f = DB::table('ebay_audit')->where('fk_id_audit_main', $runId)->where('Order_number', $order)->where('sku_ebay', $sku)->first() ?? throw new \InvalidArgumentException('The line is not in the file.');
        if (DB::table('t_sales')->where('fk_id_branch', $run->fk_id_branch)->where('order_no', $order)->where('sku', $sku)->where(fn ($w) => $w->whereNull('line_status')->orWhere('line_status', '<>', 'DELETED'))->exists()) throw new \InvalidArgumentException('This order line is already in sales.');
        $shop = DB::table('t_branches')->where('id', $run->fk_id_branch)->first();
        $listing = DB::table('t_product_listings')->where('fk_id_branch', $shop->id)->where('marketplace_sku', $sku)->first();
        $id = (int) DB::table('t_sales')->insertGetId(RecordService::fillNotNull('t_sales', ['fk_id_ent' => (int) $shop->fk_id_ent, 'fk_id_branch' => $shop->id, 'fk_id_marketplace' => $shop->fk_id_marketplace, 'order_no' => $order, 'sku' => $sku,
            'd_order' => self::date((string) $f->Sale_date) ?? $run->d_yr_mon, 'qty' => (int) $f->Quantity, 'sale_rate' => (float) $f->Sold_for, 'total_amount' => (float) $f->Total_price, 'ds_note' => (string) $f->My_item_note, 'product_info' => mb_substr((string) $f->Item_title, 0, 250),
            'cust_name' => mb_substr((string) $f->Post_to_name, 0, 100), 'status' => 'Pending', 'sale_source' => $listing ? 'active' : 'unmatched', 'fk_id_listing' => $listing->id ?? null, 'fk_product_id' => $listing->fk_id_product ?? null, 'created_by' => $u->id, 'created_at' => now()]));
        Audit::log('t_sales', $id, 'INSERT', ['from' => 'eBay audit file', 'order' => $order, 'sku' => $sku], 'Create missing sale', (int) $shop->fk_id_ent);
        return ['sale' => $id, 'message' => "Sale $order / $sku created (Pending)."];
    }

    /** R-A_SALES-62 "Delete extra": a Pending sale of the month that is not in the file is marked DELETED (kept for the audit trail, never a confirmed one). */
    public static function deleteExtra(int $runId, int $saleId, string $reason, object $u): array
    {
        Perm::need($u, 'orders.CONFIRM'); $run = self::run($runId, $u);
        if (trim($reason) === '') throw new \InvalidArgumentException('Give the reason for deleting the sale.');
        $s = DB::table('t_sales')->where('id', $saleId)->where('fk_id_branch', $run->fk_id_branch)->first() ?? throw new \InvalidArgumentException('Sale not found in this shop.');
        if ($s->status !== 'Pending') throw new \InvalidArgumentException('Only a Pending sale can be deleted – this one is ' . $s->status . '.');
        if (DB::table('ebay_audit')->where('fk_id_audit_main', $runId)->where('Order_number', $s->order_no)->where('sku_ebay', $s->sku)->exists()) throw new \InvalidArgumentException('This sale is in the file – it is not extra.');
        DB::table('t_sales')->where('id', $saleId)->update(['line_status' => 'DELETED', 'updated_at' => now()]);
        Audit::log('t_sales', $saleId, 'UPDATE', ['line_status' => [$s->line_status, 'DELETED']], 'eBay audit: extra sale deleted – ' . trim($reason), Context::ent());
        return ['message' => 'The sale is deleted (kept in the history).'];
    }

    /* ---------------- R-A_SALES-63 Amazon ---------------- */

    private static function vendor(int $id, string $profile): object
    {
        $v = DB::table('t_vendors')->where('id', $id)->first() ?? throw new \InvalidArgumentException('Supplier not found.');
        if (($v->audit_profile ?? 'NONE') !== $profile) throw new \InvalidArgumentException("This supplier has no $profile audit profile (supplier form › Audit profile).");
        return $v;
    }

    public static function uploadAmazon(int $runId, int $vendorId, string $text, object $u): array
    {
        Perm::need($u, 'orders.CONFIRM'); self::run($runId, $u); self::vendor($vendorId, 'AMAZON'); $rows = [];
        foreach (array_slice(self::csv($text), 1) as $r) {
            if (trim($r[0] ?? '') === '') continue;
            $rows[] = ['fk_id_audit_main' => $runId, 'fk_id_vendor' => $vendorId, 'order_no' => mb_substr($r[0], 0, 50), 'd_order' => self::date($r[1] ?? ''), 'pur_amount' => self::money($r[2] ?? ''), 'cust_name' => mb_substr($r[3] ?? '', 0, 200) ?: null, 'cust_address' => mb_substr($r[4] ?? '', 0, 1000) ?: null];
        }
        DB::transaction(function () use ($runId, $vendorId, $rows) { DB::table('pur_audit')->where('fk_id_audit_main', $runId)->where('fk_id_vendor', $vendorId)->delete(); foreach (array_chunk($rows, 300) as $c) DB::table('pur_audit')->insert($c); });
        return ['rows' => count($rows), 'message' => count($rows) . ' Amazon order(s) loaded.'] + self::amazon($runId, $vendorId, $u);
    }

    public static function amazon(int $runId, int $vendorId, object $u): array
    {
        $run = self::run($runId, $u); $tol = (float) Choices::get('AUDIT_PRICE_TOLERANCE', Context::ent());
        $shop = (string) DB::table('t_branches')->where('id', $run->fk_id_branch)->value('shop_no'); $contact = Perm::has($u, 'orders.dsSeeContact');
        $amz = DB::table('pur_audit')->where('fk_id_audit_main', $runId)->where('fk_id_vendor', $vendorId)->get()->keyBy(fn ($a) => mb_strtoupper($a->order_no));
        $lines = DB::table('ebay_audit')->where('fk_id_audit_main', $runId)->where('fk_id_vendor', $vendorId)->orderBy('Sale_date')->orderBy('Order_number')->get();
        $sum = $lines->groupBy(fn ($l) => mb_strtoupper((string) $l->pur_ref_no))->map(fn ($g) => round((float) $g->sum('tot_pur_amount'), 2));
        $diff = [];
        foreach ($lines as $l) {
            $a = $amz[mb_strtoupper((string) $l->pur_ref_no)] ?? null; $tags = [];
            if (! $a || abs(($sum[mb_strtoupper((string) $l->pur_ref_no)] ?? 0) - (float) $a->pur_amount) > $tol + 0.0001) $tags[] = '[Price]';
            if ($a && self::norm($l->Post_to_name ?: $l->Buyer_name) !== self::norm($a->cust_name)) $tags[] = '[Customer]';
            if (! $tags) continue;
            $diff[] = ['shop' => $shop, 'order' => $l->Order_number, 'sale_date' => $l->Sale_date, 'sku' => $l->sku_ebay, 'title' => $l->Item_title, 'rate' => (float) $l->Sold_for, 'qty' => (int) $l->Quantity, 'total' => (float) $l->Total_price,
                'note' => "{$l->qty_pur} × {$l->pur_rate} = {$l->tot_pur_amount}", 'amazon_order' => $l->pur_ref_no, 'buy_date' => [$l->d_purchase, $a->d_order ?? null],
                'customer' => $contact ? [$l->Post_to_name ?: $l->Buyer_name, $a->cust_name ?? null] : ['•••', '•••'], 'buy_amount' => [$sum[mb_strtoupper((string) $l->pur_ref_no)] ?? 0, $a ? (float) $a->pur_amount : null], 'tags' => $tags];
        }
        return ['tolerance' => $tol, 'diff' => $diff, 'amazon' => $amz->values()->map(fn ($a) => ['shop' => $shop, 'order' => $a->order_no, 'date' => $a->d_order, 'customer' => $contact ? $a->cust_name : '•••', 'address' => $contact ? $a->cust_address : '•••', 'total' => (float) $a->pur_amount])->all()];
    }

    /* ---------------- R-A_SALES-64 GD ---------------- */

    public static function uploadGd(int $runId, int $vendorId, string $text, object $u): array
    {
        Perm::need($u, 'orders.CONFIRM'); self::run($runId, $u); self::vendor($vendorId, 'GD');
        $all = self::csv($text); $rows = []; $prev = null;
        foreach ($all as $i => $r) {
            if (! array_filter($r)) continue;
            if (count($r) !== count(self::GD)) throw new \InvalidArgumentException('No. of Columns not matched , see template');
            $a = array_combine(self::GD, $r);
            if ($i === 0 && strcasecmp(str_replace(' ', '_', $a['order_no']), 'order_no') === 0) continue;      /* header */
            if ($a['order_no'] === '' && $prev) foreach (['order_no', 'd_order', 'g_pur_price', 'trading_id', 'payer_email'] as $k) $a[$k] = $prev[$k];      /* a blank order no inherits from the row above */
            elseif ($a['order_no'] !== '') $prev = $a;
            if ($a['sku_gd'] === '') continue;
            $qty = is_numeric($a['qty']) ? (int) $a['qty'] : 0; $price = is_numeric(preg_replace('/[^0-9.\-]/', '', $a['price'])) ? (float) preg_replace('/[^0-9.\-]/', '', $a['price']) : 0.0;
            $rows[] = ['fk_id_audit_main' => $runId, 'fk_id_vendor' => $vendorId, 'order_no' => mb_substr($a['order_no'], 0, 50), 'd_order' => self::date(substr($a['d_order'], 0, 10)), 'sku_gd' => mb_substr($a['sku_gd'], 0, 50),
                'e_hold_buy' => str_starts_with(strtoupper($a['order_no']), 'HO') ? 'HOLD' : 'BUY', 'g_pur_price' => (float) (self::money($a['g_pur_price']) ?? 0), 'product_title' => mb_substr($a['title'], 0, 500), 'qty' => $qty, 'price' => round($price, 2), 'amount' => round($price * $qty, 2),
                'trading_id' => mb_substr($a['trading_id'], 0, 25), 'tracking_number' => mb_substr($a['tracking_number'], 0, 25), 'payer_email' => mb_substr($a['payer_email'], 0, 200), 'address1' => $a['address1'], 'address2' => $a['address2'], 'address3' => $a['address3'],
                'city' => $a['city'], 'county' => trim($a['county'] . ' ' . $a['county2']), 'buyer_country' => $a['country'], 'recipient' => $a['recipient'], 'buyer_post_code' => mb_substr($a['post_code'], 0, 50), 'buyer_email' => $a['email'], 'phone' => mb_substr($a['phone'], 0, 50)];
        }
        DB::transaction(function () use ($runId, $vendorId, $rows) { DB::table('pur_audit_gd')->where('fk_id_audit_main', $runId)->where('fk_id_vendor', $vendorId)->delete(); foreach (array_chunk($rows, 300) as $c) DB::table('pur_audit_gd')->insert($c); });
        $r = self::gd($runId, $vendorId, $u); self::holdTasks($runId, $vendorId, $r);
        return ['rows' => count($rows), 'message' => count($rows) . ' GD row(s) loaded.'] + $r;
    }

    public static function gd(int $runId, int $vendorId, object $u): array
    {
        self::run($runId, $u); $contact = Perm::has($u, 'orders.dsSeeContact'); $tol = (float) Choices::get('AUDIT_PRICE_TOLERANCE', Context::ent()); $U = fn ($x) => mb_strtoupper(trim((string) $x));
        $gd = DB::table('pur_audit_gd')->where('fk_id_audit_main', $runId)->where('fk_id_vendor', $vendorId)->get();
        $lines = DB::table('ebay_audit')->where('fk_id_audit_main', $runId)->where('fk_id_vendor', $vendorId)->orderBy('Sale_date')->get();
        $hold = $gd->where('e_hold_buy', 'HOLD')->groupBy(fn ($g) => $U($g->order_no)); $buy = $gd->where('e_hold_buy', 'BUY')->groupBy(fn ($g) => $U($g->order_no));
        $holdUsed = $lines->pluck('pur_ref_no_gd_hold')->filter()->map($U)->unique()->all(); $buyUsed = $lines->pluck('pur_ref_no')->filter()->map($U)->unique()->all();
        $c1 = $hold->keys()->diff($holdUsed)->values()->all(); $c2 = collect($holdUsed)->diff($hold->keys())->values()->all();
        $c3 = $buy->keys()->diff($buyUsed)->values()->all(); $c4 = collect($buyUsed)->diff($buy->keys())->values()->all();
        $diff = [];
        foreach ($lines as $l) {
            $g = $buy[$U($l->pur_ref_no)] ?? null; if (! $g) continue; $tags = []; $gq = (int) $g->sum('qty'); $ga = round((float) $g->sum('amount'), 2); $f = $g->first();
            if ((int) $l->Quantity !== (int) $l->qty_pur) $tags[] = '[Qty1]';
            if ((int) $l->qty_pur !== $gq) $tags[] = '[Qty2]';
            if (abs((float) $l->pur_rate - (float) $f->price) > $tol + 0.0001) $tags[] = '[Rate]';
            if (self::norm($l->Post_to_name) !== self::norm($f->recipient)) $tags[] = '[Customer]';
            if (self::norm($l->Post_to_postcode) !== self::norm($f->buyer_post_code)) $tags[] = '[Address]';
            if (abs((float) $l->tot_pur_amount - $ga) > $tol + 0.0001) $tags[] = '[Total Pur]';
            if ($l->pur_ref_no_gd_hold && ! isset($hold[$U($l->pur_ref_no_gd_hold)])) $tags[] = '[Hold Missing]';
            if ($tags) $diff[] = ['order' => $l->Order_number, 'sku' => $l->sku_ebay, 'ref' => $l->pur_ref_no, 'hold' => $l->pur_ref_no_gd_hold, 'tags' => $tags,
                'qty' => [(int) $l->Quantity, (int) $l->qty_pur, $gq], 'rate' => [(float) $l->Sold_for, (float) $l->pur_rate, (float) $f->price], 'total' => [(float) $l->Total_price, (float) $l->tot_pur_amount, $ga],
                'customer' => $contact ? [$l->Post_to_name, null, $f->recipient] : ['•••', null, '•••'], 'postcode' => $contact ? [$l->Post_to_postcode, null, $f->buyer_post_code] : ['•••', null, '•••']];
        }
        return ['checks' => [['n' => 1, 'title' => 'HO orders on GD not used in a sale', 'rows' => $c1], ['n' => 2, 'title' => 'Hold order nos in item notes not on GD', 'rows' => $c2], ['n' => 3, 'title' => 'BUY orders on GD not in a sale', 'rows' => $c3],
            ['n' => 4, 'title' => 'BUY refs in sales not on GD', 'rows' => $c4], ['n' => 5, 'title' => 'Differences', 'rows' => $diff]],
            'gd' => $gd->map(fn ($g) => ['type' => $g->e_hold_buy, 'order' => $g->order_no, 'date' => $g->d_order, 'sku' => $g->sku_gd, 'hold_total' => $g->e_hold_buy === 'HOLD' ? (float) $g->amount : null, 'calc' => "{$g->qty} × {$g->price} = {$g->amount}",
                'recipient' => $contact ? $g->recipient : '•••', 'tracking' => $g->tracking_number, 'address' => $contact ? trim("$g->address1 $g->city $g->buyer_post_code") : '•••'])->values()->all()];
    }

    /** Hold ageing: an HO not used in a sale after DS_HOLD_DAYS → task "cancel hold" (one per order, closed when used). */
    private static function holdTasks(int $runId, int $vendorId, array $r): void
    {
        $days = (int) Choices::get('DS_HOLD_DAYS', Context::ent()); $unused = $r['checks'][0]['rows'];
        foreach (DB::table('pur_audit_gd')->where('fk_id_audit_main', $runId)->where('fk_id_vendor', $vendorId)->where('e_hold_buy', 'HOLD')->get() as $g) {
            $old = $g->d_order && $g->d_order <= date('Y-m-d', strtotime("-$days days"));
            if (in_array(mb_strtoupper(trim($g->order_no)), $unused, true) && $old) TaskEngine::open('DS_HOLD_UNUSED', ['gd_hold', $vendorId, $g->order_no], "Cancel hold {$g->order_no} – not used in a sale after $days days", ['ent' => (int) Context::ent(), 'module' => 'pur_audit_gd', 'record_id' => (int) $g->id]);
            elseif (! in_array(mb_strtoupper(trim($g->order_no)), $unused, true)) TaskEngine::close('DS_HOLD_UNUSED', ['gd_hold', $vendorId, $g->order_no], 'Hold used in a sale');
        }
    }
}
