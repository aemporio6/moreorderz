<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-D-51 Products bulk upload in the old 34-column layout – the built-in mapping "Old bulk products".
 * Columns: 0 family flag (1 = parent), 1 vendor, 2 SKU, 3 name, 4 expiry, 5 variant, 6 HS, 7 EAN, 8 alert-min flag, 9 active (ignored, forced 1), 10 country,
 * 11 carrier service (carrier derived), 12 packing, 13 type, 14 trend, 15 sale price, 16 list price, 17 OOS, 18 stock, 19 min level, 20 weight, 21 dimensions,
 * 22 eBay fee, 23 UPC, 24 OPC, 25–27 tag 1–3, 28 role, 29 role account, 30 vendor inventory, 31 URL eBay, 32 href, 33 description.
 * Names are accepted instead of ids (vendor, type, trend, country, packing, preset) – a name that is not found holds the row back and offers the look-alike.
 * Stock goes in as an OPENING lot; sale / list / fee go to the primary marketplace row. Preview before save; undo per batch.
 */
class OldBulkProducts
{
    public const KEY = 'product_old_bulk';
    public const NAME = 'Old bulk products';
    public const COLUMNS = ['family flag (1 = parent)', 'vendor', 'SKU', 'name', 'expiry', 'variant', 'HS code', 'EAN', 'alert at min level (1/0)', 'active (ignored)', 'country', 'carrier service', 'packing', 'type', 'trend', 'sale price', 'list price',
        'out of stock (1/0)', 'stock', 'min level', 'weight', 'dimensions', 'eBay fee', 'UPC', 'OPC', 'tag 1', 'tag 2', 'tag 3', 'role', 'role account', 'vendor inventory', 'URL eBay', 'href', 'description'];

    /** [lower name => id] and [id => name] of one look-up list. */
    private static function lists(int $ent): array
    {
        $own = fn ($q) => $q->where(fn ($w) => $w->where('fk_id_ent', $ent)->orWhereNull('fk_id_ent')->orWhere('fk_id_ent', 0));
        $mk = fn ($rows) => collect($rows)->mapWithKeys(fn ($r) => [(int) $r->id => trim((string) $r->name)])->filter(fn ($n) => $n !== '')->all();
        return ['vendor' => $mk($own(DB::table('t_vendors')->where('f_active', 1))->get(['id', 'vendor_name as name'])), 'type' => $mk($own(DB::table('cat_product_type')->where('f_active', 1))->get(['id', 'product_type_name as name'])),
            'trend' => $mk($own(DB::table('cat_product_trends')->where('f_active', 1))->get(['id', 'product_trend_name as name'])), 'country' => $mk($own(DB::table('cat_country')->where('f_active', 1))->get(['id', 'country_name as name'])),
            'packing' => $mk($own(DB::table('t_packings')->where('f_active', 1))->get(['id', 'packing_name as name'])),
            'carrier service' => $mk(DB::table('t_carrier_pkg_svc')->where('f_active', 1)->get(['id', 'pkg_svc_name as name']))];
    }

    /** id for a name or an id; else the reason with the closest name. */
    public static function pick(string $v, array $list, string $what): array
    {
        if ($v === '' || $v === '0') return ['id' => null];
        if (ctype_digit($v) && isset($list[(int) $v])) return ['id' => (int) $v];
        $lv = mb_strtolower($v); $best = null; $bd = 99;
        foreach ($list as $id => $n) { $ln = mb_strtolower($n); if ($ln === $lv) return ['id' => (int) $id];
            $d = str_contains($ln, $lv) || str_contains($lv, $ln) ? 1 : levenshtein(mb_substr($ln, 0, 200), mb_substr($lv, 0, 200)); if ($d < $bd) { $bd = $d; $best = $n; } }
        return ['error' => ucfirst($what) . " \"$v\" not found" . ($best !== null && $bd <= max(2, (int) floor(mb_strlen($lv) / 3)) ? " – did you mean \"$best\"?" : '.')];
    }

    private static function day(string $v): string|false|null
    {
        if ($v === '' || $v === '0' || str_starts_with($v, '0000')) return null;
        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd.m.Y'] as $f) { $d = \DateTime::createFromFormat('!' . $f, $v); if ($d && $d->format($f) === $v) return $d->format('Y-m-d'); }
        return false;
    }

    public static function stage(int $ent, ?string $fileName, ?string $text, object $u): array
    {
        Perm::need($u, 'stock.CHANGE');
        if ($fileName === null || $text === null) throw new \InvalidArgumentException('Please select a file to upload.');
        if (strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) !== 'csv') throw new \InvalidArgumentException('Invalid file type. Please upload an Excel file (CSV).');
        $rows = array_values(array_filter(PurchaseAudit::csv($text), fn ($r) => array_filter($r, fn ($x) => $x !== '')));
        if ($rows && ! ctype_digit((string) ($rows[0][0] ?? '')) && preg_match('/sku|vendor|name/i', implode(' ', array_slice($rows[0], 0, 4)))) array_shift($rows);      /* a heading row */
        if (! $rows) throw new \InvalidArgumentException('Empty file.');
        $L = self::lists($ent); $ok = []; $log = []; $seen = [];
        foreach ($rows as $i => $r) {
            $n = $i + 1; $c = fn (int $k) => trim((string) ($r[$k] ?? '')); $bad = [];
            if (count($r) < 19) { $log[] = "Row $n: only " . count($r) . ' column(s) – the layout has 34.'; continue; }
            $sku = $c(2); if ($sku === '') { $log[] = "Row $n: SKU is empty – skipped."; continue; }
            if (isset($seen[mb_strtoupper($sku)])) { $log[] = "Row $n: SKU \"$sku\" is in the file twice – skipped."; continue; }
            if (DB::table('t_product_vendors')->where('fk_id_ent', $ent)->where('SKU_VENDOR', $sku)->exists()) { $log[] = "Row $n: SKU \"$sku\" " . SkuCheck::BLOCK . ' – skipped.'; continue; }
            $id = [];
            foreach (['vendor' => 1, 'country' => 10, 'carrier service' => 11, 'packing' => 12, 'type' => 13, 'trend' => 14] as $what => $k) { $p = self::pick($c($k), $L[$what], $what); isset($p['error']) ? $bad[] = $p['error'] : $id[$what] = $p['id']; }
            $vi = self::pick($c(30), $L['vendor'], 'vendor inventory'); isset($vi['error']) ? $bad[] = $vi['error'] : $id['vendor inventory'] = $vi['id'];
            if (! $bad && ! $id['vendor']) $bad[] = 'Vendor is required.';
            $num = []; foreach (['sale price' => 15, 'list price' => 16, 'eBay fee' => 22] as $what => $k) { $v = $c($k); if ($v === '') $num[$what] = 0.0; elseif (is_numeric($v) && (float) $v >= 0) $num[$what] = round((float) $v, 2); else $bad[] = ucfirst($what) . " \"$v\" is not a number."; }
            $int = []; foreach (['stock' => 18, 'min level' => 19] as $what => $k) { $v = $c($k); if ($v === '') $int[$what] = 0; elseif (ctype_digit($v)) $int[$what] = (int) $v; else $bad[] = ucfirst($what) . " \"$v\" is not a whole number."; }
            $exp = self::day($c(4)); if ($exp === false) $bad[] = 'Expiry "' . $c(4) . '" is not a date (day/month/year).';
            $parent = $c(0) === '1'; if ($parent && ($int['stock'] ?? 0) > 0) $bad[] = 'A parent holds no stock – put the stock on its children.';
            if ($bad) { $log[] = "Row $n ($sku): " . implode(' ', $bad) . ' – not loaded.'; continue; }
            $flag = fn (int $k) => in_array(mb_strtolower($c($k)), ['1', 'y', 'yes', 'true'], true) ? 1 : 0; $seen[mb_strtoupper($sku)] = true;
            $ok[] = ['row' => $n, 'sku' => mb_substr($sku, 0, 50), 'name' => mb_substr($c(3) !== '' ? $c(3) : $sku, 0, 250), 'parent' => $parent, 'vendor' => $id['vendor'], 'expiry' => $exp, 'variant' => mb_substr($c(5), 0, 70), 'hs' => mb_substr($c(6), 0, 255), 'ean' => mb_substr($c(7), 0, 20),
                'alert' => $flag(8), 'country' => $id['country'], 'preset' => $id['carrier service'], 'packing' => $id['packing'], 'type' => $id['type'], 'trend' => $id['trend'], 'sale' => $num['sale price'], 'list' => $num['list price'], 'oos' => $flag(17),
                'stock' => $int['stock'], 'min' => $int['min level'], 'weight' => mb_substr($c(20), 0, 50), 'dimensions' => mb_substr($c(21), 0, 15), 'fee' => $num['eBay fee'], 'upc' => mb_substr($c(23), 0, 50), 'opc' => mb_substr($c(24), 0, 50),
                'tags' => [$flag(25), $flag(26), $flag(27)], 'role' => ctype_digit($c(28)) ? (int) $c(28) : 0, 'role_account' => ctype_digit($c(29)) ? (int) $c(29) : 0, 'vendor_inventory' => $id['vendor inventory'], 'url' => mb_substr($c(31), 0, 500), 'href' => mb_substr($c(32), 0, 255), 'description' => $c(33)];
        }
        DB::table('bulk_jobs')->where('list_key', self::KEY)->where('fk_id_account', $u->id)->where('status', 'PREVIEW')->delete();
        if (! $ok) return ['job' => null, 'ready' => 0, 'log' => $log, 'rows' => [], 'message' => 'No row can be loaded – see the list.'];
        $job = (int) DB::table('bulk_jobs')->insertGetId(['job_no' => 'OB-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5)), 'list_key' => self::KEY, 'fk_id_account' => $u->id, 'change_json' => json_encode(['rows' => $ok, 'file' => basename($fileName)]),
            'row_count' => count($ok), 'status' => 'PREVIEW', 'created_at' => now()]);
        $mp = ProductPrimary::marketplace($ent); $units = array_sum(array_column($ok, 'stock'));
        return ['job' => $job, 'mapping' => self::NAME, 'ready' => count($ok), 'log' => $log, 'opening_units' => $units, 'primary_marketplace' => $mp ? (string) DB::table('cat_marketplaces')->where('id', $mp)->value('marketplace_short') : null,
            'rows' => array_map(fn ($x) => ['row' => $x['row'], 'sku' => $x['sku'], 'name' => $x['name'], 'kind' => $x['parent'] ? 'parent' : 'product', 'vendor' => $L['vendor'][$x['vendor']] ?? '', 'type' => $L['type'][$x['type']] ?? '', 'carrier' => $L['carrier service'][$x['preset']] ?? '',
                'sale' => $x['sale'], 'stock' => $x['stock']], array_slice($ok, 0, 300)),
            'message' => count($ok) . ' product(s) ready, ' . count($log) . " row(s) not loaded, $units unit(s) of opening stock. Nothing is saved until you press Confirm."];
    }

    public static function confirm(int $ent, int $jobId, object $u): array
    {
        Perm::need($u, 'stock.CHANGE');
        $j = DB::table('bulk_jobs')->where('id', $jobId)->where('list_key', self::KEY)->where('fk_id_account', $u->id)->where('status', 'PREVIEW')->first() ?? throw new \InvalidArgumentException('Nothing is staged – choose the file again.');
        $rows = json_decode((string) $j->change_json, true)['rows']; $mp = ProductPrimary::marketplace($ent);
        return DB::transaction(function () use ($rows, $ent, $u, $j, $mp) {
            $ids = []; $lots = []; $prices = [];
            foreach ($rows as $x) {
                if (DB::table('t_product_vendors')->where('fk_id_ent', $ent)->where('SKU_VENDOR', $x['sku'])->exists()) throw new \InvalidArgumentException("SKU \"{$x['sku']}\" " . SkuCheck::BLOCK . ' – choose the file again.');
                $carrier = $x['preset'] ? (string) DB::table('t_carrier_pkg_svc as p')->join('t_carriers as c', 'c.id', '=', 'p.fk_id_carrier')->where('p.id', $x['preset'])->value('c.carrier_name') : null;
                $id = (int) DB::table('t_product_vendors')->insertGetId(RecordService::fillNotNull('t_product_vendors', array_filter(['fk_id_vendor' => $x['vendor'], 'fk_id_country_make' => $x['country'], 'fk_id_car_pkg_svc' => $x['preset'], 'carrier' => $carrier, 'fk_id_packing' => $x['packing'],
                    'fk_id_product_type' => $x['type'], 'fk_id_product_trend' => $x['trend'], 'fk_id_vendor_inventory' => $x['vendor_inventory'], 'd_product_expiry_date' => $x['expiry'], 'fk_id_role' => $x['role'] ?: null, 'fk_id_role_account' => $x['role_account'] ?: null], fn ($v) => $v !== null)
                    + ['fk_id_ent' => $ent, 'SKU_VENDOR' => $x['sku'], 'product_name_vendor' => $x['name'], 'variant' => $x['variant'], 'hs_code' => $x['hs'], 'ean' => $x['ean'], 'f_alert_min_level' => $x['alert'], 'f_active' => 1, 'sale_price' => $x['sale'], 'list_price' => $x['list'], 'ebay_fee' => $x['fee'],
                        'f_out_of_stock' => $x['oos'], 'stock' => 0, 'min_level' => $x['min'], 'weight' => $x['weight'], 'dimensions' => $x['dimensions'], 'upc' => $x['upc'], 'opc' => $x['opc'], 'f_tag1' => $x['tags'][0], 'f_tag2' => $x['tags'][1], 'f_tag3' => $x['tags'][2],
                        'url_ebay' => $x['url'], 'href_product' => $x['href'], 'product_description' => $x['description'], 'f_parent' => $x['parent'] ? 2 : 1, 'e_product_type' => 'pool', 'created_by' => $u->id, 'd_created' => date('Y-m-d')]));
                $ids[] = $id; ProductFamily::created($id, $ent); Audit::log('t_product_vendors', $id, 'INSERT', ['import' => self::NAME, 'sku' => $x['sku']], null, $ent);
                if ($mp) {      /* the product's creation may already have made its marketplace rows */
                    DB::table('t_product_marketplace_prices')->where('fk_id_product', $id)->where('fk_id_marketplace', $mp)->exists()
                        ? DB::table('t_product_marketplace_prices')->where('fk_id_product', $id)->where('fk_id_marketplace', $mp)->update(['sale_price' => $x['sale'], 'list_price' => $x['list'], 'marketplace_fee' => $x['fee'], 'updated_at' => now()])
                        : DB::table('t_product_marketplace_prices')->insert(['fk_id_product' => $id, 'fk_id_marketplace' => $mp, 'raw_price' => 0, 'sale_price' => $x['sale'], 'list_price' => $x['list'], 'marketplace_fee' => $x['fee'], 'created_by' => $u->id, 'created_at' => now()]); }
                if (! $x['parent'] && $x['stock'] > 0) $lots[] = ['product' => $id, 'qty' => $x['stock']];
            }
            $units = 0; $lotIds = [];
            if ($lots) { $loc = (int) (Settings::get('MAIN_WAREHOUSE', $ent, 0) ?: DB::table('cat_inventory_locations')->where('fk_id_ent', $ent)->where('f_active', 1)->where('f_sellable', 1)->whereIn('kind', ['WAREHOUSE', 'BIN'])->whereNull('system_role')->orderByRaw("kind = 'WAREHOUSE' DESC")->orderBy('id')->value('id')) ?: StockService::systemLocation($ent, 'UNASSIGNED');
                StockService::receive($ent, array_map(fn ($l) => $l + ['location' => $loc, 'unit_cost' => 0], $lots), 'OPENING', (int) $j->id, ['lot_source' => 'OPENING']); $units = array_sum(array_column($lots, 'qty'));
                $lotIds = DB::table('fin_lots')->where('company_id', $ent)->whereIn('fk_id_product', array_column($lots, 'product'))->pluck('id')->map(fn ($v) => (int) $v)->all(); }
            DB::table('bulk_jobs')->where('id', $j->id)->update(['status' => 'APPLIED', 'applied_at' => now(), 'change_json' => json_encode(['products' => $ids, 'prices' => $prices, 'lots' => $lotIds])]);
            foreach ($ids as $id) SearchIndex::put((string) RecordService::formOfTable('t_product_vendors'), 't_product_vendors', $id, $ent);
            return ['job' => (int) $j->id, 'products' => $ids, 'opening_units' => $units, 'message' => 'Saved: ' . count($ids) . " product(s); $units unit(s) as opening stock (cost 0 – set the cost on the lots)." . ($mp ? '' : ' No primary marketplace is set – the prices are on the product only.')];
        });
    }

    /** Undo: opening lots are voided (refused when units are sold or moved), price rows removed; a product without stock history is removed, one with it is kept as deleted. */
    public static function undo(int $ent, int $jobId, object $u, string $key = self::KEY): array
    {
        Perm::need($u, 'stock.CHANGE');
        $j = DB::table('bulk_jobs')->where('id', $jobId)->where('list_key', $key)->first() ?? throw new \InvalidArgumentException('Batch not found.');
        if ($j->status !== 'APPLIED') throw new \InvalidArgumentException('This batch was already undone.');
        if ((int) $j->fk_id_account !== (int) $u->id && ($u->role_code ?? '') !== 'OWNER') throw new \InvalidArgumentException('Only the person who loaded it (or the Owner) undoes it.');
        $c = json_decode((string) $j->change_json, true); $c['products'] = array_map('intval', $c['products'] ?? []);
        $c['lots'] ??= DB::table('fin_lots')->where('company_id', $ent)->whereIn('fk_id_product', $c['products'] ?: [0])->where(fn ($w) => $w->whereNull('lot_status')->orWhere('lot_status', '<>', 'VOID'))->pluck('id')->all();      /* R-G_CORE-74: the parent + child import keeps only the product ids */
        usort($c['products'], fn ($a, $b) => $b <=> $a);      /* children before their parents */
        return DB::transaction(function () use ($c, $j, $ent) {
            $sold = DB::table('t_sales')->whereIn('fk_product_id', $c['products'] ?: [0])->count(); if ($sold) throw new \InvalidArgumentException("$sold sale(s) already use these products – this batch cannot be undone.");
            foreach ($c['lots'] ?? [] as $lot) StockService::voidLot($ent, (int) $lot, 'Undo of bulk upload ' . $j->job_no);
            foreach (['t_product_marketplace_prices', 't_product_marketplace_shipping'] as $t) DB::table($t)->whereIn('fk_id_product', $c['products'] ?: [0])->delete(); $gone = 0; $kept = 0;
            foreach ($c['products'] as $id) {
                if (DB::table('fin_lots')->where('fk_id_product', $id)->exists()) { DB::table('t_product_vendors')->where('id', $id)->update(['f_active' => 0, 'row_state' => 'DELETED', 'SKU_VENDOR' => DB::raw("LEFT(CONCAT(SKU_VENDOR, '~', id), 50)")]); $kept++; }
                else { DB::table('t_product_vendors')->where('id', $id)->delete(); DB::table('sys_search_index')->where('entity', 't_product_vendors')->where('entity_id', $id)->delete(); $gone++; }
                Audit::log('t_product_vendors', (int) $id, 'DELETE', ['undo' => $j->job_no], null, $ent);
            }
            DB::table('bulk_jobs')->where('id', $j->id)->update(['status' => 'UNDONE', 'undone_at' => now()]);
            return ['message' => "Undone: $gone product(s) removed" . ($kept ? ", $kept with voided opening stock kept as deleted (their SKU is free again)." : '.')];
        });
    }
}
