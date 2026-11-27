<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Owner 27-11 09:30 "My brands": one button per own brand (its logo), and each brand's own page – its company, trade mark, products, stock,
 * sales and profit of the last 30 days, the shops it sells on. Which brands are ours is the owner's choice (cat_brands.f_own, a switch on the page).
 */
class MyBrands
{
    public static function list(int $ent): array
    {
        $logo = DB::table('sys_logos')->where('kind', 'BRAND')->where('ref_table', 'cat_brands')->get(['id', 'ref_id', 'updated_at', DB::raw('image IS NOT NULL AS has'), DB::raw('image_dark IS NOT NULL AS dark')])->keyBy('ref_id');
        $prod = DB::table('t_product_vendors')->where('fk_id_ent', $ent)->where('f_active', 1)->whereNotNull('fk_id_brand')->groupBy('fk_id_brand')->selectRaw('fk_id_brand b, COUNT(*) n, SUM(GREATEST(stock,0)) units')->get()->keyBy('b');
        $since = now()->subDays(30)->toDateString();
        $sale = DB::table('t_sales as s')->join('t_product_vendors as p', 'p.id', '=', 's.fk_product_id')->where('s.fk_id_ent', $ent)->where('s.d_order', '>=', $since)->whereNull('s.refunded_at')
            ->groupBy('p.fk_id_brand')->selectRaw('p.fk_id_brand b, SUM(s.qty) qty, SUM(COALESCE(s.total_amount_gbp, s.total_amount)) amount')->get()->keyBy('b');
        $houses = DB::table('cat_company_house')->pluck('company_name', 'id');
        return DB::table('cat_brands')->where('fk_id_ent', $ent)->where('f_active', 1)->orderByDesc('f_own')->orderBy('brand_name')
            ->get(['id', 'brand_name', 'f_own', 'fk_id_company_house', 'tm_status', 'tm_expiry_date', 'website'])
            ->map(fn ($b) => ['id' => (int) $b->id, 'name' => trim((string) $b->brand_name), 'own' => (bool) $b->f_own, 'company' => $houses[$b->fk_id_company_house] ?? null,
                'tm' => $b->tm_status, 'tm_expiry' => $b->tm_expiry_date, 'website' => $b->website,
                'logo_row' => isset($logo[$b->id]) ? (int) $logo[$b->id]->id : null, 'logo' => isset($logo[$b->id]) && $logo[$b->id]->has ? ['id' => (int) $logo[$b->id]->id, 'v' => substr(md5((string) $logo[$b->id]->updated_at), 0, 6), 'dark' => (bool) $logo[$b->id]->dark] : null,
                'products' => (int) ($prod[$b->id]->n ?? 0), 'units' => (int) ($prod[$b->id]->units ?? 0), 'sold_30' => (int) ($sale[$b->id]->qty ?? 0), 'sales_30' => round((float) ($sale[$b->id]->amount ?? 0), 2)])->all();
    }

    /** One brand's page. */
    public static function one(int $ent, int $id): array
    {
        $b = collect(self::list($ent))->firstWhere('id', $id); if (! $b) throw new \InvalidArgumentException('This brand is not in this company.');
        $since = now()->subDays(30)->toDateString();
        $sold = DB::table('t_sales')->where('fk_id_ent', $ent)->where('d_order', '>=', $since)->whereNull('refunded_at')->groupBy('fk_product_id')
            ->selectRaw('fk_product_id p, SUM(qty) qty, SUM(COALESCE(total_amount_gbp, total_amount)) amount, SUM(profit) profit')->get()->keyBy('p');
        $products = DB::table('t_product_vendors')->where('fk_id_ent', $ent)->where('fk_id_brand', $id)->where('f_active', 1)->orderBy('SKU_VENDOR')->limit(500)
            ->get(['id', 'SKU_VENDOR', 'product_name_vendor', 'stock', 'sale_price', 'landing_price', 'raw_price', 'product_img', 'icc_status'])
            ->map(fn ($p) => ['id' => (int) $p->id, 'sku' => $p->SKU_VENDOR, 'name' => $p->product_name_vendor, 'stock' => (int) $p->stock, 'price' => $p->sale_price !== null ? (float) $p->sale_price : null,
                'cost' => (float) ($p->landing_price ?: $p->raw_price ?: 0), 'image' => ProductVault::imageUrl($p->product_img), 'reorder' => $p->icc_status,
                'sold_30' => (int) ($sold[$p->id]->qty ?? 0), 'sales_30' => round((float) ($sold[$p->id]->amount ?? 0), 2), 'profit_30' => round((float) ($sold[$p->id]->profit ?? 0), 2)])->all();
        $ids = array_column($products, 'id');
        $shops = $ids ? DB::table('t_sales as s')->leftJoin('t_branches as br', 'br.id', '=', 's.fk_id_branch')->where('s.fk_id_ent', $ent)->whereIn('s.fk_product_id', $ids)->where('s.d_order', '>=', now()->subDays(90)->toDateString())
            ->groupBy('br.shop_name')->selectRaw('br.shop_name shop, SUM(s.qty) qty, SUM(COALESCE(s.total_amount_gbp, s.total_amount)) amount')->orderByDesc('amount')->get()->map(fn ($r) => ['shop' => $r->shop ?: '—', 'qty' => (int) $r->qty, 'amount' => round((float) $r->amount, 2)])->all() : [];
        $value = array_sum(array_map(fn ($p) => max(0, $p['stock']) * (float) $p['cost'], $products));
        return ['brand' => $b, 'products' => $products, 'shops' => $shops, 'stock_value' => round($value, 2), 'profit_30' => round(array_sum(array_column($products, 'profit_30')), 2), 'currency' => Fx::base($ent)];
    }

    public static function setOwn(int $ent, int $id, bool $own): void
    {
        if (! DB::table('cat_brands')->where('id', $id)->where('fk_id_ent', $ent)->update(['f_own' => (int) $own, 'updated_at' => now()]) && ! DB::table('cat_brands')->where('id', $id)->where('fk_id_ent', $ent)->exists())
            throw new \InvalidArgumentException('This brand is not in this company.');
        Logos::sync($ent);
    }
}
