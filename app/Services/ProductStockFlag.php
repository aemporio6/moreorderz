<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-D-7 out-of-stock automation. When the ledger's available quantity of a product or child reaches 0 the product and its listings are
 * "Out of stock" (event PRODUCT_OOS); above 0 again it clears by itself (PRODUCT_BACK_IN_STOCK). The manual tick stays as an override
 * (oos_manual = 1: out of stock whatever the stock); ticking or unticking it by hand needs a reason (oos_reason) and is in the history –
 * unticked, the flag follows the stock again. The old intent "child with stock 0 = out of stock" (computed, never applied) is applied.
 */
class ProductStockFlag
{
    public const REASON = 'Give the reason for changing "Out of stock" by hand.';

    public static function auto(int $productId): bool { return StockService::available($productId) <= 0; }

    /** Work the flag out again and tell the listings; returns the flag. */
    public static function apply(int $productId): int
    {
        $p = DB::table('t_product_vendors')->where('id', $productId)->first(['id', 'fk_id_ent', 'f_out_of_stock', 'oos_manual']); if (! $p) return 0;
        $new = (int) $p->oos_manual === 1 ? 1 : (int) self::auto($productId);
        if ((int) $p->f_out_of_stock !== $new) {
            DB::table('t_product_vendors')->where('id', $productId)->update(['f_out_of_stock' => $new]);
            EventBus::fire($new ? 'PRODUCT_OOS' : 'PRODUCT_BACK_IN_STOCK', 't_product_vendors', $productId, ['manual' => (int) $p->oos_manual === 1], (int) $p->fk_id_ent);
        }
        DB::table('t_product_listings')->where('fk_id_product', $productId)->where('f_out_of_stock', '<>', $new)->update(['f_out_of_stock' => $new]);
        return $new;
    }

    /** Form save: a change of the tick by hand needs a reason; ticked = override, unticked = automatic again. */
    public static function formCheck(array &$data, array $old, ?int $id): array
    {
        if (! array_key_exists('f_out_of_stock', $data)) return [];
        $new = (int) (bool) $data['f_out_of_stock']; $was = (int) ($old['f_out_of_stock'] ?? 0);
        if (! $id) { $data['oos_manual'] = $new ? 1 : null; return []; }      // a new product: the tick is its starting state
        if ($new === $was && ! ($new === 0 && (int) ($old['oos_manual'] ?? 0) === 1)) { unset($data['oos_reason']); return []; }
        if (trim((string) ($data['oos_reason'] ?? '')) === '') return ['oos_reason' => self::REASON];
        $data['oos_manual'] = $new ? 1 : null;
        if (! $new) $data['f_out_of_stock'] = (int) self::auto($id);      // unticked: back to what the stock says
        return [];
    }
}
