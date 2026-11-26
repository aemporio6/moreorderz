<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-D-60 legacy per-shop product rows (t_products_branch) → listings. Mapping: shop, SKU (eBay) → marketplace SKU, title (eBay), ASIN → item id, URL (eBay),
 * type, eBay sale price → listing price, purchase price → listing "expected cost", vendor, vendor SKU / URL / ref, carrier.
 * Old required fields are checked first (Shop, SKU, Title, Vendor, Type, Sale price, Purchase price); a row that fails is listed, never guessed.
 * A dry run writes nothing; a row already moved (legacy_branch_row) is skipped, so a second run adds nothing; one batch with Undo.
 */
class LegacyListings
{
    public const KEY = 'legacy_listings';

    /** What one old row becomes, or the reason it cannot move. */
    public static function map(object $o, int $ent): array
    {
        $miss = [];
        foreach (['fk_id_branch' => 'Shop', 'sku_ebay' => 'SKU (Ebay)', 'product_name_local' => 'Product Title (Ebay)', 'fk_id_vendor' => 'Vendor', 'fk_id_product_type' => 'Type'] as $c => $label) if (trim((string) ($o->$c ?? '')) === '' || (str_starts_with($c, 'fk_') && (int) $o->$c === 0)) $miss[] = $label;
        foreach (['sale_price_ebay' => 'Sale Price (Ebay)', 'pur_price' => 'Purchase Price'] as $c => $label) if (! is_numeric($o->$c ?? null)) $miss[] = $label;
        if ($miss) return ['error' => 'Required: ' . implode(', ', $miss)];
        $shop = DB::table('t_branches')->where('fk_id_ent', $ent)->where('id', (int) $o->fk_id_branch)->first(); if (! $shop) return ['error' => 'Shop ' . (int) $o->fk_id_branch . ' not found'];
        $p = (int) ($o->fk_id_product_main ?? 0) ? DB::table('t_product_vendors')->where('fk_id_ent', $ent)->where('id', (int) $o->fk_id_product_main)->value('id') : null;
        foreach ([trim((string) ($o->sku_vendor ?? '')), trim((string) $o->sku_ebay)] as $sku) if (! $p && $sku !== '') $p = DB::table('t_product_vendors')->where('fk_id_ent', $ent)->where('SKU_VENDOR', $sku)->value('id');
        if (! $p) return ['error' => 'No product for SKU ' . $o->sku_ebay . ' – a listing needs a product'];
        $asin = trim((string) ($o->asin_amazon ?? ''));
        return ['row' => ['fk_id_ent' => $ent, 'fk_id_product' => (int) $p, 'fk_id_branch' => (int) $shop->id, 'fk_id_marketplace' => (int) $shop->fk_id_marketplace, 'marketplace_sku' => trim((string) $o->sku_ebay),
            'listing_title' => mb_substr(trim((string) $o->product_name_local), 0, 255), 'item_id' => $asin !== '' ? mb_substr($asin, 0, 60) : null, 'asin' => $asin !== '' && mb_strlen($asin) <= 20 ? $asin : null,
            'listing_url' => trim((string) ($o->url_ebay_product ?? '')) ?: null, 'fk_id_product_type' => (int) $o->fk_id_product_type, 'price' => round((float) $o->sale_price_ebay, 2), 'expected_cost' => round((float) $o->pur_price, 2),
            'fk_id_vendor' => (int) $o->fk_id_vendor, 'vendor_sku' => trim((string) ($o->sku_vendor ?? '')) ?: null, 'vendor_url' => trim((string) ($o->url_vendor_product ?? '')) ?: null, 'vendor_ref' => trim((string) ($o->ref_vendor_product ?? '')) ?: null,
            'fk_id_carrier' => (int) ($o->fk_id_carrier ?? 0) ?: null, 'status' => (int) ($o->f_active ?? 1) === 1 ? 'ACTIVE' : 'INACTIVE', 'f_active' => (int) ($o->f_active ?? 1) === 1 ? 1 : 0, 'legacy_branch_row' => (int) $o->id]];
    }

    public static function run(bool $apply, object $u): array
    {
        if ($apply && (($u->role_code ?? '') !== 'OWNER')) throw new \InvalidArgumentException('Only the Owner moves the old shop rows.');
        $ent = (int) Context::ent(); $done = DB::table('t_product_listings')->where('fk_id_ent', $ent)->whereNotNull('legacy_branch_row')->pluck('legacy_branch_row')->flip();
        $taken = []; foreach (DB::table('t_product_listings')->where('fk_id_ent', $ent)->whereNotNull('item_id')->get(['fk_id_branch', 'item_id']) as $l) $taken[$l->fk_id_branch . '|' . mb_strtoupper($l->item_id)] = 1;
        $ready = []; $errors = []; $skipped = 0;
        foreach (DB::table('t_products_branch as o')->join('t_branches as b', 'b.id', '=', 'o.fk_id_branch')->where('b.fk_id_ent', $ent)->orderBy('o.id')->get('o.*') as $o) {
            if (isset($done[$o->id])) { $skipped++; continue; }
            $m = self::map($o, $ent);
            if (isset($m['row']) && $m['row']['item_id'] !== null) { $k = $m['row']['fk_id_branch'] . '|' . mb_strtoupper($m['row']['item_id']); if (isset($taken[$k])) $m = ['error' => 'Item id ' . $m['row']['item_id'] . ' is already a listing of this shop']; else $taken[$k] = 1; }
            isset($m['row']) ? $ready[] = $m['row'] : $errors[] = ['row' => (int) $o->id, 'sku' => (string) $o->sku_ebay, 'reason' => $m['error']];
        }
        $out = ['applied' => false, 'ready' => count($ready), 'already' => $skipped, 'errors' => $errors, 'sample' => array_slice($ready, 0, 20)];
        if (! $apply || ! $ready) return $out + ['message' => count($ready) . ' row(s) ready, ' . count($errors) . ' cannot move, ' . $skipped . ' already moved.'];
        $ids = [];
        DB::transaction(function () use ($ready, $u, &$ids) { foreach ($ready as $r) $ids[] = (int) DB::table('t_product_listings')->insertGetId(RecordService::fillNotNull('t_product_listings', $r + ['d_listed' => null, 'created_by' => $u->id, 'created_at' => now()])); });
        $job = (int) DB::table('bulk_jobs')->insertGetId(['job_no' => 'LL-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5)), 'list_key' => self::KEY, 'fk_id_account' => $u->id, 'change_json' => json_encode(['created' => $ids]),
            'row_count' => count($ids), 'status' => 'APPLIED', 'created_at' => now(), 'applied_at' => now()]);
        Audit::log('bulk_jobs', $job, 'INSERT', ['listings' => [null, count($ids)]], 'Old shop rows moved to listings', $ent);
        return ['applied' => true, 'job' => $job, 'created' => count($ids), 'message' => count($ids) . ' listing(s) created from the old shop rows. ' . count($errors) . ' could not move.'] + $out;
    }

    public static function undo(int $jobId, object $u): array
    {
        if ((($u->role_code ?? '') !== 'OWNER')) throw new \InvalidArgumentException('Only the Owner undoes this.');
        $j = DB::table('bulk_jobs')->where('id', $jobId)->where('list_key', self::KEY)->first() ?? throw new \InvalidArgumentException('Batch not found.');
        if ($j->status !== 'APPLIED') throw new \InvalidArgumentException('This batch was already undone.');
        $n = 0; $kept = 0;
        DB::transaction(function () use ($j, &$n, &$kept) {
            foreach (json_decode((string) $j->change_json, true)['created'] ?? [] as $id) {
                if (DB::table('t_sales')->where('fk_id_listing', $id)->exists()) { DB::table('t_product_listings')->where('id', $id)->update(['f_active' => 0, 'status' => 'CLOSED']); $kept++; } else { DB::table('t_product_listings')->where('id', $id)->delete(); $n++; }
            }
            DB::table('bulk_jobs')->where('id', $j->id)->update(['status' => 'UNDONE', 'undone_at' => now()]);
        });
        Audit::log('bulk_jobs', (int) $j->id, 'UPDATE', ['status' => ['APPLIED', 'UNDONE']], 'Old shop rows: undo', (int) Context::ent());
        return ['message' => "Undone: $n listing(s) removed" . ($kept ? ", $kept already used in sales were closed instead." : '.')];
    }
}
