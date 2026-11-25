<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Product imports of the Products rules.
 * - R-D-52 report type PRODUCT_FAMILY (parent SKU, child SKU; old layout: column 0 = parent, column 2 = child, header row skipped):
 *   exact SKU match (the old "contains" match linked AB-1 to AB-10 – not kept); the child gets its parent and f_parent 3; a parent that was alone
 *   becomes a parent (f_parent 2); preview "n links, n not found"; undo by batch.
 * - R-D-53 parent + child staged import: step 1 Vendor* and a .csv file*; columns parent SKU, child SKU, name, carrier preset, packing, sale price,
 *   qty (default 1), weight, dimensions. A row with a parent SKU starts a parent; the following rows with a child SKU are its children; a parent with
 *   no child row is a single product. The rows are staged per user (cleared when the screen opens); the preview shows the tree with the names;
 *   Confirm = two passes (parents, then children mapped to the new parent ids) in one transaction. Defaults come from company settings
 *   (PRODUCT_DEFAULT_TYPE, PRODUCT_DEFAULT_COUNTRY; fee 0.00, list price 0.00; parents out of stock, children not). Qty enters as OPENING lots of the children.
 */
class ProductImports
{
    private static function rows(string $text): array
    {
        $lines = array_values(array_filter(preg_split('/\r\n|\r|\n/', (string) preg_replace('/^\xEF\xBB\xBF/', '', $text)), fn ($l) => trim(str_replace(',', '', $l)) !== ''));
        return array_map(fn ($l) => array_map(fn ($x) => trim((string) $x), str_getcsv($l)), $lines);
    }

    private static function bySku(int $ent, string $sku): ?object { return $sku === '' ? null : DB::table('t_product_vendors')->where('fk_id_ent', $ent)->where('SKU_VENDOR', $sku)->orderByDesc('f_active')->orderBy('id')->first(['id', 'SKU_VENDOR', 'f_parent', 'f_id_parent', 'f_active']); }

    /* ---------------- R-D-52 family links ---------------- */
    public static function familyLinks(int $ent, string $text, bool $apply, object $u): array
    {
        Perm::need($u, 'stock.variations');
        $rows = self::rows($text); if (count($rows) < 2) throw new \InvalidArgumentException('The file has no rows under its heading.');
        array_shift($rows);      /* header row skipped */
        $links = []; $miss = []; $log = [];
        foreach ($rows as $i => $r) {
            $n = $i + 2; $ps = $r[0] ?? ''; $cs = count($r) >= 3 ? ($r[2] ?? '') : ($r[1] ?? '');      /* old layout: column 0 parent, column 2 child; a two-column file: parent, child */
            if ($ps === '' || $cs === '') { $miss[] = $n; $log[] = "Row $n: parent or child SKU is empty."; continue; }
            $p = self::bySku($ent, $ps); $c = self::bySku($ent, $cs);
            if (! $p) { $miss[] = $n; $log[] = "Row $n: parent SKU \"$ps\" not found."; continue; }
            if (! $c) { $miss[] = $n; $log[] = "Row $n: child SKU \"$cs\" not found."; continue; }
            if ((int) $p->id === (int) $c->id) { $miss[] = $n; $log[] = "Row $n: a product cannot be its own child."; continue; }
            if ((int) $p->f_parent === 3) { $miss[] = $n; $log[] = "Row $n: \"$ps\" is itself a child – a child cannot be a parent."; continue; }
            if ((int) $c->f_parent === 2 && DB::table('t_product_vendors')->where('f_id_parent', $c->id)->where('f_parent', 3)->exists()) { $miss[] = $n; $log[] = "Row $n: \"$cs\" is a parent with its own children."; continue; }
            $links[] = ['row' => $n, 'parent' => (int) $p->id, 'parent_sku' => $p->SKU_VENDOR, 'child' => (int) $c->id, 'child_sku' => $c->SKU_VENDOR, 'already' => (int) $c->f_id_parent === (int) $p->id && (int) $c->f_parent === 3];
        }
        $out = ['links' => count($links), 'not_found' => count($miss), 'rows' => array_map(fn ($l) => ['row' => $l['row'], 'parent' => $l['parent_sku'], 'child' => $l['child_sku'], 'already' => $l['already']], $links), 'log' => $log, 'applied' => false,
            'message' => 'Check: ' . count($links) . ' links, ' . count($miss) . ' not found. Nothing was saved.'];
        if (! $apply) return $out;
        if (! $links) throw new \InvalidArgumentException('No link can be made. ' . implode(' ', array_slice($log, 0, 3)));
        return DB::transaction(function () use ($links, $out, $u, $ent, $miss) {
            $old = []; $n = 0;
            foreach ($links as $l) {
                if ($l['already']) continue;
                foreach ([$l['child'], $l['parent']] as $id) if (! isset($old[$id])) { $o = DB::table('t_product_vendors')->where('id', $id)->first(['f_parent', 'f_id_parent']); $old[$id] = ['f_parent' => (int) $o->f_parent, 'f_id_parent' => $o->f_id_parent]; }
                DB::table('t_product_vendors')->where('id', $l['child'])->update(['f_id_parent' => $l['parent'], 'f_parent' => 3]);
                if ($old[$l['parent']]['f_parent'] !== 2) DB::table('t_product_vendors')->where('id', $l['parent'])->where('f_parent', '<>', 2)->update(['f_parent' => 2]);      /* a parent that was alone becomes a parent */
                Audit::log('t_product_vendors', $l['child'], 'UPDATE', ['f_id_parent' => [$old[$l['child']]['f_id_parent'], $l['parent']], 'f_parent' => [$old[$l['child']]['f_parent'], 3]], 'Import PRODUCT_FAMILY: child of ' . $l['parent_sku'], $ent);
                $n++;
            }
            $job = (int) DB::table('bulk_jobs')->insertGetId(['job_no' => 'PF-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5)), 'list_key' => 'product_family', 'fk_id_account' => $u->id, 'change_json' => json_encode(['old' => $old]),
                'row_count' => $n, 'status' => 'APPLIED', 'created_at' => now(), 'applied_at' => now()]);
            return ['applied' => true, 'job' => $job, 'updated' => $n, 'message' => "$n rows were updated" . ($miss ? ', ' . count($miss) . ' not found' : '') . '.'] + $out;
        });
    }

    public static function familyUndo(int $jobId, object $u): array
    {
        Perm::need($u, 'stock.variations');
        $j = DB::table('bulk_jobs')->where('id', $jobId)->where('list_key', 'product_family')->first() ?? throw new \InvalidArgumentException('Batch not found.');
        if ($j->status !== 'APPLIED') throw new \InvalidArgumentException('This batch was already undone.');
        $old = json_decode((string) $j->change_json, true)['old'] ?? [];
        DB::transaction(function () use ($old, $j) { foreach ($old as $id => $o) DB::table('t_product_vendors')->where('id', (int) $id)->where('fk_id_ent', Context::ent())->update($o); DB::table('bulk_jobs')->where('id', $j->id)->update(['status' => 'UNDONE', 'undone_at' => now()]); });
        return ['restored' => count($old), 'message' => 'Undone: ' . count($old) . ' product(s) put back as they were.'];
    }

    /* ---------------- R-D-53 parent + child staged import ---------------- */
    private const STAGE = 'product_staged';

    public static function stagedMeta(int $ent, object $u): array
    {
        Perm::need($u, 'stock.CHANGE');
        return ['vendors' => DB::table('t_vendors')->where('f_active', 1)->where(fn ($w) => $w->where('fk_id_ent', $ent)->orWhereNull('fk_id_ent')->orWhere('fk_id_ent', 0))->orderBy('vendor_name')->get(['id', 'vendor_name as name'])->map(fn ($v) => (array) $v)->all(),
            'shops' => array_values(array_map(fn ($s) => ['id' => $s['id'], 'label' => $s['label']], ShopLabel::list($u, ['active_only' => true]))), 'columns' => ['parent SKU', 'child SKU', 'name', 'carrier preset', 'packing', 'sale price', 'qty', 'weight', 'dimensions']];
    }

    /** Opening the screen clears the person's staging. (A POST – a GET never writes.) */
    public static function stagedClear(object $u): array { DB::table('bulk_jobs')->where('list_key', self::STAGE)->where('fk_id_account', $u->id)->where('status', 'PREVIEW')->delete(); return ['message' => 'Staging cleared.']; }

    /** Step 1: checks + the file read into the person's staging; returns the preview tree. */
    public static function stage(int $ent, array $in, ?string $fileName, ?string $text, object $u): array
    {
        Perm::need($u, 'stock.CHANGE');
        $vendor = (int) ($in['vendor'] ?? 0); if (! $vendor || ! DB::table('t_vendors')->where('id', $vendor)->exists()) throw new \InvalidArgumentException('Please select Vendor.');
        if ($fileName === null || $text === null) throw new \InvalidArgumentException('Please select a file to upload.');
        if (strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) !== 'csv') throw new \InvalidArgumentException('Invalid file type. Please upload an Excel file (CSV).');
        $rows = self::rows($text);
        if ($rows && preg_match('/sku/i', implode(' ', array_slice($rows[0], 0, 2)))) array_shift($rows);      /* a heading row */
        $presets = DB::table('t_carrier_pkg_svc')->where('f_active', 1)->get(['id', 'pkg_svc_name'])->mapWithKeys(fn ($p) => [mb_strtolower(trim((string) $p->pkg_svc_name)) => (int) $p->id])->all();
        $packs = DB::table('t_packings')->where('f_active', 1)->get(['id', 'packing_name'])->mapWithKeys(fn ($p) => [mb_strtolower(trim((string) $p->packing_name)) => (int) $p->id])->all();
        $pick = function (string $v, array $map, string $what, int $n, array &$log) { if ($v === '') return null; if (ctype_digit($v) && in_array((int) $v, $map, true)) return (int) $v; if (isset($map[mb_strtolower($v)])) return $map[mb_strtolower($v)]; $log[] = "Row $n: $what \"$v\" not found – left empty."; return null; };
        $tree = []; $log = []; $cur = null; $seen = [];
        foreach ($rows as $i => $r) {
            $n = $i + 1; $ps = $r[0] ?? ''; $cs = $r[1] ?? '';
            if ($ps === '' && $cs === '') { $cur = null; continue; }      /* a row with neither ends the family */
            $sku = $ps !== '' ? $ps : $cs;
            if (isset($seen[mb_strtoupper($sku)])) { $log[] = "Row $n: SKU \"$sku\" is in the file twice – skipped."; continue; }
            if (self::bySku($ent, $sku)) { $log[] = "Row $n: SKU \"$sku\" " . SkuCheck::BLOCK . ' – skipped.'; if ($ps !== '') $cur = null; continue; }
            $qty = ($r[6] ?? '') === '' ? 1 : (ctype_digit($r[6]) ? (int) $r[6] : null); if ($qty === null) { $log[] = "Row $n: qty \"{$r[6]}\" is not a whole number – skipped."; continue; }
            $price = ($r[5] ?? '') === '' ? 0.0 : (is_numeric($r[5]) && (float) $r[5] >= 0 ? round((float) $r[5], 2) : null); if ($price === null) { $log[] = "Row $n: sale price \"{$r[5]}\" is not a number – skipped."; continue; }
            $item = ['row' => $n, 'sku' => $sku, 'name' => ($r[2] ?? '') !== '' ? $r[2] : $sku, 'preset' => $pick($r[3] ?? '', $presets, 'carrier preset', $n, $log), 'preset_name' => $r[3] ?? '', 'packing' => $pick($r[4] ?? '', $packs, 'packing', $n, $log), 'packing_name' => $r[4] ?? '',
                'sale_price' => $price, 'qty' => $qty, 'weight' => ($r[7] ?? '') !== '' && is_numeric($r[7]) ? (float) $r[7] : null, 'dimensions' => ($r[8] ?? '') ?: null, 'children' => []];
            $seen[mb_strtoupper($sku)] = true;
            if ($ps !== '') { $tree[] = $item; $cur = count($tree) - 1; }
            elseif ($cur === null) $log[] = "Row $n: child SKU \"$cs\" has no parent row above it – skipped.";
            else { unset($item['children']); $tree[$cur]['children'][] = $item; }
        }
        if (! $tree) throw new \InvalidArgumentException('No product row could be read. ' . implode(' ', array_slice($log, 0, 3)));
        self::stagedClear($u);
        $type = (int) Settings::get('PRODUCT_DEFAULT_TYPE', $ent, 0) ?: null; $country = (int) (Settings::get('PRODUCT_DEFAULT_COUNTRY', $ent, 0) ?: Settings::get('COMPANY_COUNTRY', $ent, 0)) ?: null;
        $ctx = ['vendor' => $vendor, 'vendor_inventory' => (int) ($in['vendor_inventory'] ?? 0) ?: null, 'shop' => (int) ($in['shop'] ?? 0) ?: null, 'type' => $type, 'country' => $country];
        $job = (int) DB::table('bulk_jobs')->insertGetId(['job_no' => 'PS-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5)), 'list_key' => self::STAGE, 'fk_id_account' => $u->id, 'change_json' => json_encode(['ctx' => $ctx, 'tree' => $tree]),
            'row_count' => count($tree), 'status' => 'PREVIEW', 'created_at' => now()]);
        return self::preview($job, $u) + ['log' => $log];
    }

    public static function preview(int $jobId, object $u): array
    {
        $j = DB::table('bulk_jobs')->where('id', $jobId)->where('list_key', self::STAGE)->where('fk_id_account', $u->id)->first() ?? throw new \InvalidArgumentException('Nothing is staged – choose the file again.');
        $d = json_decode((string) $j->change_json, true); $c = $d['ctx'];
        $kids = array_sum(array_map(fn ($p) => count($p['children']), $d['tree'])); $singles = count(array_filter($d['tree'], fn ($p) => ! $p['children']));
        return ['job' => (int) $j->id, 'status' => $j->status, 'staged_today' => substr((string) $j->created_at, 0, 10) === date('Y-m-d'), 'vendor' => (string) DB::table('t_vendors')->where('id', $c['vendor'])->value('vendor_name'),
            'shop' => $c['shop'] ? (string) DB::table('t_branches')->where('id', $c['shop'])->value('shop_name') : null, 'type' => $c['type'] ? (string) (DB::table('cat_product_type')->where('id', $c['type'])->value(Registry::labelColumn('cat_product_type') ?: 'id')) : null,
            'tree' => array_map(fn ($p) => ['sku' => $p['sku'], 'name' => $p['name'], 'kind' => $p['children'] ? 'parent' : 'single', 'carrier' => $p['preset_name'], 'packing' => $p['packing_name'], 'sale_price' => $p['sale_price'], 'qty' => $p['qty'],
                'children' => array_map(fn ($k) => ['sku' => $k['sku'], 'name' => $k['name'], 'carrier' => $k['preset_name'], 'packing' => $k['packing_name'], 'sale_price' => $k['sale_price'], 'qty' => $k['qty']], $p['children'])], $d['tree']),
            'parents' => count($d['tree']) - $singles, 'children' => $kids, 'singles' => $singles, 'message' => (count($d['tree']) - $singles) . " parent(s) with $kids child(ren), $singles single product(s). Nothing is saved until you confirm."];
    }

    /** Confirm: parents first, then the children mapped to the new parent ids – one transaction. Qty enters as OPENING lots (never on a parent with children). */
    public static function confirm(int $ent, int $jobId, object $u): array
    {
        Perm::need($u, 'stock.CHANGE');
        $j = DB::table('bulk_jobs')->where('id', $jobId)->where('list_key', self::STAGE)->where('fk_id_account', $u->id)->where('status', 'PREVIEW')->first() ?? throw new \InvalidArgumentException('Nothing is staged – choose the file again.');
        $d = json_decode((string) $j->change_json, true); $c = $d['ctx'];
        return DB::transaction(function () use ($d, $c, $ent, $u, $j) {
            $made = ['parents' => 0, 'children' => 0, 'singles' => 0]; $ids = []; $lots = []; $map = [];
            $row = fn (array $x, int $fParent, ?int $parent, int $oos) => RecordService::fillNotNull('t_product_vendors', array_filter(['fk_id_ent' => $ent, 'SKU_VENDOR' => $x['sku'], 'product_name_vendor' => mb_substr($x['name'], 0, 250), 'fk_id_vendor' => $c['vendor'], 'fk_id_vendor_inventory' => $c['vendor_inventory'],
                'fk_id_branch' => $c['shop'], 'fk_id_product_type' => $c['type'], 'fk_id_country_make' => $c['country'], 'fk_id_car_pkg_svc' => $x['preset'], 'fk_id_packing' => $x['packing'], 'weight' => $x['weight'], 'dimensions' => $x['dimensions'], 'f_id_parent' => $parent], fn ($v) => $v !== null)
                + ['sale_price' => $x['sale_price'], 'list_price' => 0, 'ebay_fee' => 0, 'f_parent' => $fParent, 'f_out_of_stock' => $oos, 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool', 'created_by' => $u->id, 'd_created' => date('Y-m-d')]);
            foreach ($d['tree'] as $k => $p) {      /* pass 1: parents and single products */
                if (DB::table('t_product_vendors')->where('fk_id_ent', $ent)->where('SKU_VENDOR', $p['sku'])->exists()) throw new \InvalidArgumentException("SKU \"{$p['sku']}\" " . SkuCheck::BLOCK . ' – stage the file again.');
                $isParent = (bool) $p['children']; $id = (int) DB::table('t_product_vendors')->insertGetId($row($p, $isParent ? 2 : 1, null, $isParent ? 1 : 0));
                $map[$k] = $id; $ids[] = $id; $made[$isParent ? 'parents' : 'singles']++; ProductFamily::created($id, $ent); Audit::log('t_product_vendors', $id, 'INSERT', ['import' => 'staged parent + child', 'sku' => $p['sku']], null, $ent);
                if (! $isParent && $p['qty'] > 0) $lots[] = ['product' => $id, 'qty' => $p['qty']];
            }
            foreach ($d['tree'] as $k => $p) foreach ($p['children'] as $ch) {      /* pass 2: children on the new parent ids */
                if (DB::table('t_product_vendors')->where('fk_id_ent', $ent)->where('SKU_VENDOR', $ch['sku'])->exists()) throw new \InvalidArgumentException("SKU \"{$ch['sku']}\" " . SkuCheck::BLOCK . ' – stage the file again.');
                $id = (int) DB::table('t_product_vendors')->insertGetId($row($ch + ['children' => []], 3, $map[$k], 0)); $ids[] = $id; $made['children']++; ProductFamily::created($id, $ent);
                Audit::log('t_product_vendors', $id, 'INSERT', ['import' => 'staged parent + child', 'sku' => $ch['sku'], 'child of' => $p['sku']], null, $ent);
                if ($ch['qty'] > 0) $lots[] = ['product' => $id, 'qty' => $ch['qty']];
            }
            $units = 0;
            if ($lots) { $loc = (int) (Settings::get('MAIN_WAREHOUSE', $ent, 0) ?: DB::table('cat_inventory_locations')->where('fk_id_ent', $ent)->where('f_active', 1)->where('f_sellable', 1)->whereIn('kind', ['WAREHOUSE', 'BIN'])->whereNull('system_role')->orderByRaw("kind = 'WAREHOUSE' DESC")->orderBy('id')->value('id')) ?: StockService::systemLocation($ent, 'UNASSIGNED');      /* the main warehouse, else the first sellable place, else UNASSIGNED */ StockService::receive($ent, array_map(fn ($l) => $l + ['location' => $loc, 'unit_cost' => 0], $lots), 'OPENING', (int) $j->id, ['lot_source' => 'OPENING']); $units = array_sum(array_column($lots, 'qty')); }
            DB::table('bulk_jobs')->where('id', $j->id)->update(['status' => 'APPLIED', 'applied_at' => now(), 'change_json' => json_encode(['products' => $ids])]);
            foreach ($ids as $id) SearchIndex::put((string) RecordService::formOfTable('t_product_vendors'), 't_product_vendors', $id, $ent);
            return $made + ['opening_units' => $units, 'products' => $ids, 'message' => "Saved: {$made['parents']} parent(s), {$made['children']} child(ren), {$made['singles']} single product(s); $units unit(s) as opening stock (cost 0 – set the cost on the lots)."];
        });
    }
}
