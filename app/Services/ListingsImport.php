<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-D-59 eBay active-listings file per shop. Shop = the file-name part before the first "_" (or the seller id in the file); the user must be the shop's
 * seller or have products.importListingsAll. Required: "Custom label (SKU)" and "Title"; "Variation details" per Settings › Choices LISTING_VARIATION_COLUMN.
 * Diff instead of wipe: new SKUs → listing ACTIVE, SKUs missing from the file → CLOSED (ended), changed titles updated; one batch with Undo (bulk_jobs list_key listing_import).
 * Rows whose SKU is no product SKU are listed (a listing needs a product).
 */
class ListingsImport
{
    private static function key(string $h): string { return trim(preg_replace('/\s+/', ' ', mb_strtolower(preg_replace('/^\xEF\xBB\xBF/', '', $h)))); }

    public static function import(string $fileName, string $text, object $u): array
    {
        $ent = (int) Context::ent(); $rows = PurchaseAudit::csv($text); $rows = array_values(array_filter($rows, fn ($r) => array_filter($r)));
        if (! $rows) throw new \InvalidArgumentException('Empty file.');
        $h = array_map([self::class, 'key'], $rows[0]); $col = fn (string $n) => array_search($n, $h, true);
        $need = ['custom label (sku)', 'title']; if (Choices::get('LISTING_VARIATION_COLUMN', $ent) === 'REQUIRED') $need[] = 'variation details';
        foreach ($need as $n) if ($col($n) === false) throw new \InvalidArgumentException('Column [' . ucfirst($n) . '] missing');
        $prefix = explode('_', basename($fileName))[0];
        $shop = DB::table('t_branches')->where('fk_id_ent', $ent)->where('shop_no', $prefix)->where('f_active', 1)->first();
        if (! $shop && ($sc = $col('seller id')) !== false && ($sid = trim((string) ($rows[1][$sc] ?? ''))) !== '') $shop = DB::table('t_branches')->where('fk_id_ent', $ent)->where('seller_id', $sid)->where('f_active', 1)->first();
        if (! $shop) throw new \InvalidArgumentException('Kindly set valid File Name, Valid Shop No not found');
        $mine = $u->fk_id_account && (int) $shop->fk_id_seller === (int) $u->fk_id_account;
        if (! $mine && ! Perm::has($u, 'products.importListingsAll')) throw new \InvalidArgumentException('Refused: you are not the seller of shop ' . $shop->shop_no . ' – the Owner can give "Import listings of every shop".');
        $sk = $col('custom label (sku)'); $ti = $col('title'); $va = $col('variation details'); $it = $col('item number'); $pr = $col('current price') !== false ? $col('current price') : $col('start price');
        $qc = false; foreach (['available quantity', 'quantity available', 'quantity', 'qty'] as $n) if (($qc = $col($n)) !== false) break;      // inventory (d): what the shop shows
        $file = [];
        foreach (array_slice($rows, 1) as $r) {
            $sku = trim((string) ($r[$sk] ?? '')); if ($sku === '') continue;
            $file[mb_strtoupper($sku)] = ['sku' => $sku, 'title' => mb_substr(trim(trim((string) ($r[$ti] ?? '')) . ' ' . ($va !== false ? trim((string) ($r[$va] ?? '')) : '')), 0, 255), 'item' => $it !== false ? mb_substr(trim((string) ($r[$it] ?? '')), 0, 60) : null,
                'price' => $pr !== false ? MarketplaceFiles::num($r[$pr] ?? '') : null, 'qty' => $qc !== false && is_numeric(trim((string) ($r[$qc] ?? ''))) ? (int) trim((string) $r[$qc]) : null];
        }
        $have = DB::table('t_product_listings')->where('fk_id_ent', $ent)->where('fk_id_branch', $shop->id)->get()->keyBy(fn ($l) => mb_strtoupper((string) $l->marketplace_sku));
        $prod = DB::table('t_product_vendors')->where('fk_id_ent', $ent)->whereIn(DB::raw('UPPER(SKU_VENDOR)'), array_keys($file) ?: ['-'])->get(['id', 'SKU_VENDOR'])->keyBy(fn ($p) => mb_strtoupper($p->SKU_VENDOR));
        $log = ['created' => [], 'ended' => [], 'changed' => [], 'reopened' => []]; $unknown = [];
        DB::transaction(function () use ($ent, $shop, $file, $have, $prod, $u, &$log, &$unknown) {
            foreach ($file as $k => $f) {
                $l = $have[$k] ?? null;
                if (! $l) {
                    $p = $prod[$k] ?? null; if (! $p) { $unknown[] = $f['sku']; continue; }
                    $id = (int) DB::table('t_product_listings')->insertGetId(['fk_id_ent' => $ent, 'fk_id_product' => $p->id, 'fk_id_branch' => $shop->id, 'fk_id_marketplace' => (int) $shop->fk_id_marketplace, 'item_id' => $f['item'] ?: null, 'marketplace_sku' => mb_substr($f['sku'], 0, 100),
                        'listing_title' => $f['title'], 'status' => 'ACTIVE', 'price' => $f['price'] ?: null, 'd_listed' => date('Y-m-d'), 'last_report_at' => now(), 'f_active' => 1, 'created_by' => $u->id, 'created_at' => now()] + ($f['qty'] !== null ? ['listed_qty' => $f['qty'], 'listed_qty_at' => now()] : []));
                    $log['created'][] = $id; continue;
                }
                $set = ['last_report_at' => now()] + ($f['qty'] !== null ? ['listed_qty' => $f['qty'], 'listed_qty_at' => now()] : []);
                if ($l->status !== 'ACTIVE') { $set['status'] = 'ACTIVE'; $log['reopened'][] = ['id' => (int) $l->id, 'status' => $l->status]; }
                if ($f['title'] !== '' && $f['title'] !== (string) $l->listing_title) { $set['listing_title'] = $f['title']; $log['changed'][] = ['id' => (int) $l->id, 'title' => $l->listing_title]; }
                DB::table('t_product_listings')->where('id', $l->id)->update($set + ['updated_by' => $u->id, 'updated_at' => now()]);
            }
            foreach ($have as $k => $l) if (! isset($file[$k]) && $l->status === 'ACTIVE') {
                DB::table('t_product_listings')->where('id', $l->id)->update(['status' => 'CLOSED', 'updated_by' => $u->id, 'updated_at' => now()]); $log['ended'][] = ['id' => (int) $l->id, 'status' => $l->status];
            }
        });
        $job = DB::table('bulk_jobs')->insertGetId(['job_no' => 'LI-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5)), 'list_key' => 'listing_import', 'fk_id_account' => $u->id,
            'change_json' => json_encode(['shop' => (int) $shop->id] + $log), 'row_count' => count($log['created']) + count($log['ended']) + count($log['changed']), 'status' => 'APPLIED', 'created_at' => now(), 'applied_at' => now()]);
        Audit::log('t_branches', (int) $shop->id, 'UPDATE', ['listings' => array_map('count', $log)], 'eBay active listings ' . basename($fileName), $ent);
        $msg = count($file) . ' records loaded successfully – ' . count($log['created']) . ' new, ' . count($log['ended']) . ' ended, ' . count($log['changed']) . ' changed' . ($unknown ? ', ' . count($unknown) . ' SKU(s) are no product yet' : '') . '.';
        return ['job' => (int) $job, 'shop' => $shop->shop_no, 'new' => count($log['created']), 'ended' => count($log['ended']), 'changed' => count($log['changed']), 'unknown' => $unknown, 'message' => $msg];
    }

    /** Undo a listings batch: created listings deleted when nothing uses them (else switched off), ended / reopened ones get their status back, titles back. */
    public static function undo(int $jobId, object $u): array
    {
        $j = DB::table('bulk_jobs')->where('id', $jobId)->where('list_key', 'listing_import')->first() ?? throw new \InvalidArgumentException('Batch not found.');
        if ($j->status !== 'APPLIED') throw new \InvalidArgumentException('This batch was already undone.');
        if ((int) $j->fk_id_account !== (int) $u->id && ! Perm::has($u, 'products.importListingsAll')) throw new \InvalidArgumentException('Only the person who loaded it (or the Owner) undoes it.');
        $c = json_decode((string) $j->change_json, true); $n = 0;
        DB::transaction(function () use ($c, $j, &$n) {
            foreach ($c['created'] as $id) { $used = DB::table('t_sales')->where('fk_id_listing', $id)->exists(); $used ? DB::table('t_product_listings')->where('id', $id)->update(['f_active' => 0, 'status' => 'CLOSED']) : DB::table('t_product_listings')->where('id', $id)->delete(); $n++; }
            foreach (array_merge($c['ended'], $c['reopened'] ?? []) as $x) { DB::table('t_product_listings')->where('id', $x['id'])->update(['status' => $x['status']]); $n++; }
            foreach ($c['changed'] as $x) { DB::table('t_product_listings')->where('id', $x['id'])->update(['listing_title' => $x['title']]); $n++; }
            DB::table('bulk_jobs')->where('id', $j->id)->update(['status' => 'UNDONE', 'undone_at' => now()]);
        });
        return ['message' => "Undone: $n listing change(s) put back."];
    }
}
