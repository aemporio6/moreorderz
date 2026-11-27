<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Found on live 27-11: sale lines whose SKU "was not found" are mostly marketplace SKUs that carry the product inside them –
 * a shop prefix and number ("MON-109-B095…", "ALX-101C-1005008666351859"), an ASIN glued to the shop number ("MON-109B07KVQY61C109"),
 * or an AliExpress / eBay item id. After the exact SKU and the remembered aliases, the resolver tries, in order:
 *   1. a listing of the same marketplace with this marketplace SKU;
 *   2. the SKU without its shop prefix (letters-number-) as a product SKU or a listing SKU;
 *   3. an ASIN inside the SKU (B0 + 8) → the listing with that ASIN;
 *   4. a long item number inside the SKU (10+ digits) → the listing with that item id.
 * Two products joined with "+" are a bundle and are never guessed. Each answer says how it was found.
 */
class SkuResolver
{
    /** [product id, how] or null */
    public static function find(int $ent, int $marketplaceId, string $sku): ?array
    {
        $raw = trim($sku); if ($raw === '' || str_contains($raw, '+')) return null;
        $low = mb_strtolower($raw);
        $listing = fn (string $col, string $v) => DB::table('t_product_listings as l')->join('t_branches as b', 'b.id', '=', 'l.fk_id_branch')->where('b.fk_id_ent', $ent)
            ->when($marketplaceId, fn ($q) => $q->where('b.fk_id_marketplace', $marketplaceId))->whereRaw("LOWER(TRIM(l.$col)) = ?", [mb_strtolower($v)])->where('l.fk_id_product', '>', 0)->distinct()->pluck('l.fk_id_product');
        $product = fn (string $v) => DB::table('t_product_vendors')->where('fk_id_ent', $ent)->whereRaw('LOWER(TRIM(SKU_VENDOR)) = ?', [mb_strtolower($v)])->pluck('id');
        $one = fn ($ids, string $how) => count($ids) === 1 ? [(int) $ids[0], $how] : null;
        if ($r = $one($listing('marketplace_sku', $raw), 'listing SKU')) return $r;
        if (preg_match('/^[A-Za-z]{2,5}-[0-9]{2,4}[A-Za-z]?-(.+)$/', $raw, $m)) {
            $rest = trim($m[1], " -");
            if ($rest !== '' && ($r = $one($product($rest), 'SKU without the shop prefix') ?? $one($listing('marketplace_sku', $rest), 'listing SKU without the shop prefix'))) return $r;
        }
        if (preg_match('/B0[A-Z0-9]{8}/', strtoupper($raw), $m) && ($r = $one($listing('asin', $m[0]), 'ASIN ' . $m[0]))) return $r;
        if (preg_match('/\d{10,}/', $raw, $m) && ($r = $one($listing('item_id', $m[0]), 'item id ' . $m[0]))) return $r;
        unset($low);
        return null;
    }
}
