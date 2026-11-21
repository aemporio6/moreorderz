<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-D-8 Duplicate product. Needs the Add right of the product form (old "You do not have add rights."). New SKU required (old "Source product
 * and new SKU are required."), unique in the company (old 'SKU "X" already exists.'); the next free "SKU-2", "SKU-3" … is suggested.
 * Copied (old): the product row (new SKU, created today, by the user), marketplace prices, marketplace shipping (packing / storage fee),
 * marketplace field values, visibility + shops, investors, media links, location rows. Improved: stock and location quantities are NEVER copied
 * (location rows keep only the bin note; no opening line); one opening price period per marketplace with reason "Created" and history
 * "copied from SKU X (#id)"; option "also copy variations" (children get the same suffix after the new SKU). Never copied: purchases / lots,
 * stock movements, price history.
 */
class ProductCopy
{
    private const NEVER = ['id', 'stock', 'f_out_of_stock', 'oos_manual', 'oos_reason', 'fk_id_merged_into', 'row_state', 'hidden_reason', 'hidden_at', 'hidden_by', 'delete_group',
        'icc_status', 'icc_order_by', 'icc_stock_out', 'icc_order_qty', 'icc_cycle_days', 'icc_run_at', 'fk_id_account_updated_by'];

    /** The next free "<SKU>-n" of the company. */
    public static function suggest(int $ent, string $sku): string
    {
        $root = trim($sku); $n = 2;      // "AB-100" → "AB-100-2", "AB-100-3" …
        while (DB::table('t_product_vendors')->where('fk_id_ent', $ent)->where('SKU_VENDOR', "$root-$n")->exists()) $n++;
        return "$root-$n";
    }

    public static function duplicate(int $sourceId, string $newSku, bool $withVariations, object $u): array
    {
        $ent = (int) Context::ent(); $form = (string) RecordService::formOfTable('t_product_vendors');
        if (! Rights::can($u->role_code, 'ADD', $form)) throw new \InvalidArgumentException('You do not have add rights.');
        $newSku = trim($newSku);
        $src = DB::table('t_product_vendors')->where('id', $sourceId)->where('fk_id_ent', $ent)->first();
        if (! $src || $newSku === '') throw new \InvalidArgumentException('Source product and new SKU are required.');
        if (mb_strlen($newSku) > 50) throw new \InvalidArgumentException('SKU can be at most 50 characters.');
        return DB::transaction(function () use ($ent, $src, $newSku, $withVariations, $u) {
            $kids = $withVariations ? DB::table('t_product_vendors')->where('f_id_parent', $src->id)->where('f_active', 1)->orderBy('id')->get() : collect();
            $map = [$src->id => $newSku];
            foreach ($kids as $k) $map[$k->id] = str_starts_with((string) $k->SKU_VENDOR, (string) $src->SKU_VENDOR) ? $newSku . substr((string) $k->SKU_VENDOR, strlen((string) $src->SKU_VENDOR)) : $newSku . '-' . $k->SKU_VENDOR;
            foreach ($map as $sku) if (DB::table('t_product_vendors')->where('fk_id_ent', $ent)->where('SKU_VENDOR', $sku)->exists()) throw new \InvalidArgumentException("SKU \"$sku\" already exists.");
            $newId = self::copyOne($ent, $src, $newSku, null, $u);
            foreach ($kids as $k) self::copyOne($ent, $k, $map[$k->id], $newId, $u);
            return ['id' => $newId, 'sku' => $newSku, 'children' => $kids->count(), 'edit_url' => '#/rec/' . RecordService::formOfTable('t_product_vendors') . '/' . $newId, 'message' => "Copied to $newSku" . ($kids->count() ? " with {$kids->count()} variation(s)" : '') . ' – stock is 0.'];
        });
    }

    private static function copyOne(int $ent, object $src, string $sku, ?int $parent, object $u): int
    {
        $row = array_diff_key((array) $src, array_flip(self::NEVER));
        $row = array_merge($row, ['SKU_VENDOR' => $sku, 'd_created' => date('Y-m-d'), 'created_by' => $u->id, 'stock' => 0, 'f_out_of_stock' => 1, 'f_active' => 1]);
        if ($parent) $row['f_id_parent'] = $parent;
        $id = (int) DB::table('t_product_vendors')->insertGetId($row);
        $copy = function (string $table, string $col, array $drop = ['id'], array $set = []) use ($src, $id) {
            foreach (DB::table($table)->where($col, $src->id)->get() as $r) { $x = array_diff_key((array) $r, array_flip($drop)); $x[$col] = $id; if (array_key_exists('created_at', $x)) $x['created_at'] = now(); DB::table($table)->insert(array_merge($x, $set)); }
        };
        $copy('t_product_marketplace_shipping', 'fk_id_product'); $copy('t_product_marketplace_values', 'fk_id_product'); $copy('t_product_visibility', 'fk_id_product'); $copy('t_product_visibility_shops', 'fk_id_product');
        $copy('t_product_investors', 'fk_id_product'); $copy('t_product_vendors_images', 'fk_id_product_vendors');
        $copy('t_product_locations', 'fk_id_product', ['id', 'last_counted_at', 'last_move_at'], ['qty' => 0]);      // the bin note stays, never the quantity
        // prices: today's price of each marketplace as one opening period "Created"
        $reason = DB::table('cat_price_change_reasons')->where('reason_name', 'Created')->value('id');
        foreach (DB::table('t_product_marketplace_prices')->where('fk_id_product', $src->id)->get() as $p)
            PriceService::set($id, (int) $p->fk_id_marketplace, (float) $p->raw_price, (float) $p->sale_price, date('Y-m-d'), $reason ? (int) $reason : null, ['list_price' => (float) $p->list_price, 'marketplace_fee' => (float) $p->marketplace_fee]);
        ProductFamily::created($id, $ent);      // a 0.00 row for a marketplace the source had no price on
        Audit::log('t_product_vendors', $id, 'INSERT', ['copied from' => "SKU {$src->SKU_VENDOR} (#{$src->id})"], 'Duplicate product', $ent);
        SearchIndex::put((string) RecordService::formOfTable('t_product_vendors'), 't_product_vendors', $id, $ent);
        return $id;
    }
}
