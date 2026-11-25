<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * The Etsy-style month ("Gen-1" header: shop + month + year) and its sale files.
 * - R-A_SALES-58 header: shop, month, year, supplier, Fee (Sale), Expenses, Expenses detail; default month by menu flag (1 current, 2 previous, 3 two months back);
 *   flags Sale loaded / Summary generated; deleting a header removes its sale and buy lines too.
 * - R-A_SALES-54 report type GEN1_SALE_SHEET: file <shop>_SALE_….csv, first column dropped, then d_sale (d.m.y), order_id, item_title, customer_name, cust_address,
 *   no_of_items, price, item_note. Item note: [..] removed, {reason} → reason of the VA, the rest VENDOR:order ref (else "Invalid Item Notes").
 * - R-A_SALES-55 report type ETSY_ORDERS: same file name; columns found by name DATE, ORDER ID, SKU, ITEM TITLE, CUSTOMER, ADDRESS, QUANTITY, SALE PRICE
 *   ("Column [X] missing"); a date that is not d.m.y stops the import. A SKU of our own stock (local supplier, not a variation parent) becomes an own-stock
 *   sale line of the tool (stock and cost at Month Confirm) – no made-up buy line any more.
 * - Both: rows outside the header's month are set aside and listed (never dropped silently); rows without title / name / address / qty / price are skipped
 *   with a count; the old sale lines of the header are replaced; f_sale_loaded = 1. A check (apply = false) writes nothing.
 */
class Gen1Files
{
    public const ETSY = ['DATE', 'ORDER ID', 'SKU', 'ITEM TITLE', 'CUSTOMER', 'ADDRESS', 'QUANTITY', 'SALE PRICE'];

    /** Menu flag → default month: 1 current, 2 previous, 3 two months back ("start to M−2"). */
    public static function defaultMonth(int $flag, ?string $today = null): array
    {
        $t = strtotime(date('Y-m-01', strtotime($today ?? date('Y-m-d')))); $back = max(0, min(2, $flag - 1));
        $d = strtotime("-$back month", $t); return ['mon' => (int) date('n', $d), 'yr' => (int) date('Y', $d), 'up_to' => $flag >= 3];
    }

    public static function meta(int $ent, int $flag = 1): array
    {
        return ['default' => self::defaultMonth($flag), 'shops' => array_values(array_map(fn ($s) => ['id' => $s['id'], 'label' => $s['label']], ShopLabel::list(\Illuminate\Support\Facades\Auth::user(), ['active_only' => true]))),
            'suppliers' => DB::table('t_vendors')->where('f_active', 1)->where(fn ($w) => $w->where('fk_id_ent', $ent)->orWhereNull('fk_id_ent')->orWhere('fk_id_ent', 0))->orderBy('iso_vendor')->get(['id', 'vendor_name as name', 'iso_vendor as code'])->map(fn ($v) => (array) $v)->all()];
    }

    public static function create(int $ent, array $in): array
    {
        $shop = (int) ($in['shop'] ?? 0); $mon = (int) ($in['mon'] ?? 0); $yr = (int) ($in['yr'] ?? 0);
        if (! DB::table('t_branches')->where('id', $shop)->where('fk_id_ent', $ent)->exists()) throw new \InvalidArgumentException('Choose the shop.');
        $mine = Perm::shops(\Illuminate\Support\Facades\Auth::user()); if ($mine !== null && ! in_array($shop, $mine, true)) throw new \InvalidArgumentException('Choose the shop.');
        if ($mon < 1 || $mon > 12 || $yr < 2000 || $yr > 2100) throw new \InvalidArgumentException('Choose the month and the year.');
        if (DB::table('t_sale_buy_main')->where('fk_id_branch', $shop)->where('mon', $mon)->where('yr', $yr)->exists()) throw new \InvalidArgumentException('This shop already has a header for that month.');
        $num = function ($k, $label) use ($in) { $v = trim((string) ($in[$k] ?? '')); if ($v === '') return 0.0; if (! is_numeric($v) || (float) $v < 0) throw new \InvalidArgumentException("$label must be a number, 0 or more."); return round((float) $v, 2); };
        $vendor = (int) ($in['supplier'] ?? 0) ?: null; if ($vendor && ! DB::table('t_vendors')->where('id', $vendor)->exists()) throw new \InvalidArgumentException('Choose the supplier from the list.');
        $id = (int) DB::table('t_sale_buy_main')->insertGetId(RecordService::fillNotNull('t_sale_buy_main', ['mon' => $mon, 'yr' => $yr, 'd_yr_mon' => sprintf('%04d-%02d-01', $yr, $mon), 'fk_id_branch' => $shop, 'f_sale_loaded' => 0, 'f_summary_generated' => 0,
            'sale_fee' => $num('sale_fee', 'Fee (Sale)'), 'tot_expenses' => $num('expenses', 'Expenses'), 'expenses_descr' => mb_substr(trim((string) ($in['expenses_detail'] ?? '')), 0, 200), 'fk_id_vendor' => $vendor]));
        Audit::log('t_sale_buy_main', $id, 'INSERT', ['shop' => $shop, 'month' => sprintf('%02d/%04d', $mon, $yr)], null, $ent);
        return ['id' => $id, 'open' => "#/gen1/$id/sales", 'message' => 'Header made for ' . sprintf('%02d/%04d', $mon, $yr) . '. Load its sale file next.'];
    }

    /** Deleting a header cascades to its sale and buy lines. */
    public static function delete(int $id): array
    {
        $h = Gen1Compare::header($id);
        return DB::transaction(function () use ($h, $id) {
            $s = DB::table('t_sale')->where('fk_id_main', $id)->delete(); $b = DB::table('t_buy')->where('fk_id_main', $id)->delete(); DB::table('t_sale_buy_main')->where('id', $id)->delete();
            Audit::log('t_sale_buy_main', $id, 'DELETE', ['sale_lines' => $s, 'buy_lines' => $b], sprintf('Header %02d/%04d deleted with its lines', $h->mon, $h->yr), Context::ent());
            return ['sale_lines' => $s, 'buy_lines' => $b, 'message' => "Header deleted with $s sale line(s) and $b buy line(s)."];
        });
    }

    /** Remove the sale of a header: the lines go, Sale loaded = No. */
    public static function removeSale(int $id): array
    {
        Gen1Compare::header($id);
        $n = DB::table('t_sale')->where('fk_id_main', $id)->delete(); DB::table('t_sale_buy_main')->where('id', $id)->update(['f_sale_loaded' => 0, 'f_summary_generated' => 0, 'set_aside_json' => null]);
        Audit::log('t_sale_buy_main', $id, 'UPDATE', ['f_sale_loaded' => [1, 0]], "Sale removed ($n line(s))", Context::ent());
        return ['removed' => $n, 'message' => "Sale removed: $n line(s). Sale loaded = No."];
    }

    private static function money(string $v): ?float { $v = (string) preg_replace('/[^\d.,\-]/', '', $v); if ($v === '') return null; if (str_contains($v, ',') && ! str_contains($v, '.')) $v = str_replace(',', '.', $v); $v = str_replace(',', '', $v); return is_numeric($v) ? round((float) $v, 2) : null; }

    /** Item note of the SALE sheet → [vendor id, buy order ref, reason of the VA, cleaned note]; throws "Invalid Item Notes". */
    public static function note(string $raw, array $vendors): array
    {
        $reason = null; $n = (string) preg_replace('/\[[^\]]*\]/', '', $raw);
        if (preg_match('/\{([^}]*)\}/', $n, $m)) { $reason = trim($m[1]) ?: null; $n = (string) preg_replace('/\{[^}]*\}/', '', $n); }
        $n = trim($n); $p = array_map('trim', explode(':', $n));
        if (count($p) !== 2 || $p[0] === '' || $p[1] === '' || ! isset($vendors[strtoupper($p[0])])) throw new \InvalidArgumentException('Invalid Item Notes');
        return [$vendors[strtoupper($p[0])], $p[1], $reason, $n];
    }

    public static function import(int $headerId, string $fileName, string $text, bool $apply): array
    {
        $h = Gen1Compare::header($headerId); $ent = (int) Context::ent();
        $shopNo = trim((string) DB::table('t_branches')->where('id', $h->fk_id_branch)->value('shop_no'));
        $tok = explode('_', pathinfo($fileName, PATHINFO_FILENAME));
        if (strcasecmp(trim($tok[0] ?? ''), $shopNo) !== 0) throw new \InvalidArgumentException('Shop no not matched, ' . trim($tok[0] ?? '') . '<=>' . $shopNo);
        if (strtoupper(trim($tok[1] ?? '')) !== 'SALE') throw new \InvalidArgumentException('The second part of the file name must be SALE: <shop>_SALE_….csv');
        $lines = array_values(array_filter(preg_split('/\r\n|\r|\n/', (string) preg_replace('/^\xEF\xBB\xBF/', '', $text)), fn ($l) => trim($l) !== ''));
        if (! $lines) throw new \InvalidArgumentException('The file has no rows.');
        $first = array_map(fn ($x) => strtoupper(trim((string) $x)), str_getcsv($lines[0]));
        $etsy = count(array_intersect(self::ETSY, $first)) >= 3;      /* a row of column names = the Etsy orders report; else the positional SALE sheet */
        $type = $etsy ? 'ETSY_ORDERS' : 'GEN1_SALE_SHEET';
        if ($etsy) { if ($miss = array_values(array_diff(self::ETSY, $first))) throw new \InvalidArgumentException(implode(' · ', array_map(fn ($c) => "Column [$c] missing", $miss))); $ix = array_flip($first); array_shift($lines); }
        elseif (BuyFile::date((string) (str_getcsv($lines[0])[1] ?? '')) === null && count($lines) > 1 && BuyFile::date((string) (str_getcsv($lines[1])[1] ?? '')) !== null) array_shift($lines);      /* a heading row of the sheet */
        $vendors = DB::table('t_vendors')->where(fn ($w) => $w->where('fk_id_ent', $ent)->orWhereNull('fk_id_ent')->orWhere('fk_id_ent', 0))->whereNotNull('iso_vendor')->orderBy('f_active')->get(['id', 'iso_vendor', 'f_local'])->mapWithKeys(fn ($v) => [strtoupper(trim((string) $v->iso_vendor)) => (int) $v->id])->all();
        $from = sprintf('%04d-%02d-01', $h->yr, $h->mon); $to = date('Y-m-t', strtotime($from));
        $rows = []; $own = []; $aside = []; $skip = ['title' => 0, 'customer' => 0, 'address' => 0, 'qty' => 0, 'price' => 0, 'order' => 0];
        foreach ($lines as $n => $line) {
            $v = array_map(fn ($x) => trim((string) $x), str_getcsv($line)); $rowNo = $n + 2;
            if ($etsy) { $g = fn ($k) => $v[$ix[$k]] ?? ''; $r = ['d' => $g('DATE'), 'order' => $g('ORDER ID'), 'sku' => $g('SKU'), 'title' => $g('ITEM TITLE'), 'customer' => $g('CUSTOMER'), 'address' => $g('ADDRESS'), 'qty' => $g('QUANTITY'), 'price' => $g('SALE PRICE'), 'note' => '']; }
            else $r = ['d' => $v[1] ?? '', 'order' => $v[2] ?? '', 'sku' => null, 'title' => $v[3] ?? '', 'customer' => $v[4] ?? '', 'address' => $v[5] ?? '', 'qty' => $v[6] ?? '', 'price' => $v[7] ?? '', 'note' => $v[8] ?? ''];
            $d = BuyFile::date($r['d']);
            if ($d === null) throw new \InvalidArgumentException("Row $rowNo: the date \"{$r['d']}\" is not day.month.year (i.e. [31.01.23]). Nothing was loaded.");
            if ($d < $from || $d > $to) { $aside[] = ['row' => $rowNo, 'date' => $d, 'order' => $r['order'], 'customer' => $r['customer'], 'title' => mb_substr($r['title'], 0, 80)]; continue; }
            if ($r['order'] === '') { $skip['order']++; continue; }
            if (! $etsy && $r['title'] === '') { $skip['title']++; continue; }
            if ($r['customer'] === '') { $skip['customer']++; continue; }
            if ($r['address'] === '') { $skip['address']++; continue; }
            if (! ctype_digit($r['qty']) || (int) $r['qty'] < 1) { $skip['qty']++; continue; }
            $price = self::money($r['price']); if ($price === null) { $skip['price']++; continue; }
            $row = ['fk_id_main' => $headerId, 'd_sale' => $d, 'order_id' => mb_substr($r['order'], 0, 40), 'sku' => $r['sku'] !== null && $r['sku'] !== '' ? mb_substr($r['sku'], 0, 100) : null, 'item_title' => mb_substr($r['title'], 0, 1000),
                'customer_name' => mb_substr($r['customer'], 0, 250), 'cust_address' => mb_substr($r['address'], 0, 800), 'no_of_items' => (int) $r['qty'], 'price' => $price];
            if ($etsy) {
                $p = $row['sku'] ? DB::table('t_product_vendors as p')->leftJoin('t_vendors as tv', 'tv.id', '=', 'p.fk_id_vendor')->where('p.fk_id_ent', $ent)->where('p.SKU_VENDOR', $row['sku'])->first(['p.id', 'p.f_parent', 'tv.f_local']) : null;
                if ($p && (int) $p->f_local === 1 && (int) $p->f_parent !== 2) { $own[] = $row + ['product' => (int) $p->id]; continue; }      /* our own stock: a sale line of the tool, no made-up buy line */
            } else {
                try { [$vid, $ref, $reason, $clean] = self::note($r['note'], $vendors); } catch (\InvalidArgumentException $e) { throw new \InvalidArgumentException("Invalid Item Notes (row $rowNo: \"" . mb_substr($r['note'], 0, 60) . '") – write it as SUPPLIERCODE:buy order ref. Nothing was loaded.'); }
                $row += ['item_note' => mb_substr($clean, 0, 150), 'item_note_orig' => mb_substr($r['note'], 0, 500), 'fk_id_vendor' => $vid, 'order_id_buy_in_sale' => mb_substr($ref, 0, 50), 'reason_by_va' => $reason ? mb_substr($reason, 0, 250) : null];
            }
            $rows[] = $row;
        }
        $notes = [];
        if ($aside) $notes[] = count($aside) . ' records not loaded due to out of Month';
        foreach (['order' => 'Order ID', 'title' => 'Item title', 'customer' => 'Customer', 'address' => 'Address', 'qty' => 'Quantity', 'price' => 'Price'] as $k => $label) if ($skip[$k]) $notes[] = "Empty [$label] in {$skip[$k]} records....";
        $old = DB::table('t_sale')->where('fk_id_main', $headerId)->count();
        $out = ['report_type' => $type, 'rows' => count($rows), 'own_stock' => count($own), 'set_aside' => $aside, 'skipped' => array_sum($skip), 'replaces' => $old, 'notes' => $notes, 'applied' => false,
            'message' => 'Check: ' . count($rows) . ' sale line(s)' . ($own ? ' and ' . count($own) . ' own-stock line(s)' : '') . ' would be loaded' . ($old ? ", replacing the $old loaded before" : '') . '. Nothing was saved.'];
        if (! $apply) return $out;
        if (! $rows && ! $own) throw new \InvalidArgumentException('No line of the file can be loaded. ' . implode(' ', $notes));
        DB::transaction(function () use ($rows, $own, $aside, $headerId, $h, $fileName, $type, $old) {
            DB::table('t_sale')->where('fk_id_main', $headerId)->delete();
            foreach (array_chunk(array_map(fn ($r) => RecordService::fillNotNull('t_sale', $r), $rows), 300) as $c) DB::table('t_sale')->insert($c);
            if ($own) SalesService::intake((int) $h->fk_id_branch, array_map(fn ($o) => ['order_no' => $o['order_id'], 'd_order' => $o['d_sale'], 'sku' => $o['sku'], 'qty' => $o['no_of_items'], 'sale_rate' => round($o['price'] / max(1, $o['no_of_items']), 4),
                'cust_name' => $o['customer_name'], 'cust_contacts' => $o['cust_address'], 'product_info' => $o['item_title']], $own));
            DB::table('t_sale_buy_main')->where('id', $headerId)->update(['f_sale_loaded' => 1, 'set_aside_json' => $aside ? json_encode($aside) : null]);
            Audit::log('t_sale_buy_main', $headerId, 'UPDATE', ['sale_file' => [$old . ' line(s)', count($rows) . ' line(s)']], "$type $fileName" . ($aside ? ' · ' . count($aside) . ' set aside (out of month)' : ''), Context::ent());
        });
        return ['applied' => true, 'message' => 'Loaded: ' . count($rows) . ' sale line(s)' . ($own ? ', ' . count($own) . ' own-stock line(s) into Sales' : '') . ($old ? " (replaced $old)" : '') . ($notes ? ' · ' . implode(' · ', $notes) : '') . '. Sale loaded = Yes.'] + $out;
    }

    /** The header as the screen shows it: figures, flags, the set-aside panel and the supplier codes with buy lines. */
    public static function header(int $id): array
    {
        $h = Gen1Compare::header($id);
        return ['id' => (int) $h->id, 'shop' => $h->shop, 'month' => sprintf('%02d/%04d', $h->mon, $h->yr), 'sale_loaded' => (bool) $h->f_sale_loaded, 'summary_generated' => (bool) $h->f_summary_generated,
            'sale_fee' => (float) $h->sale_fee, 'expenses' => (float) $h->tot_expenses, 'expenses_detail' => $h->expenses_descr, 'supplier' => $h->fk_id_vendor ? DB::table('t_vendors')->where('id', $h->fk_id_vendor)->value('vendor_name') : null,
            'set_aside' => json_decode((string) ($h->set_aside_json ?? ''), true) ?: [],
            'buy_suppliers' => DB::table('t_buy as y')->join('t_vendors as v', 'v.id', '=', 'y.fk_id_vendor')->where('y.fk_id_main', $id)->groupBy('v.id', 'v.iso_vendor', 'v.vendor_name')->orderBy('v.iso_vendor')->get(['v.iso_vendor as code', 'v.vendor_name as name', DB::raw('COUNT(*) as n')])->map(fn ($r) => (array) $r)->all(),
            'payslip' => '#/va-statements'];
    }
}
