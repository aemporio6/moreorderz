<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;

/**
 * R-D-10 company-owned (central) inventory: a product whose own-stock supplier is the company's own supplier (setting PRODUCT_DEFAULT_VENDOR,
 * the old vendor 21 "Local Inventory") is changed only by people with the right products.edit_central – on every product form (old: only the
 * per-shop form). Old messages "Cannot add item of Local Inventory" / "Cannot edit item of Local Inventory". Listing fields (their own form)
 * stay open to the shop's people. No setting = no central products.
 */
class CentralInventory
{
    public const RIGHT = 'products.edit_central';

    public static function vendor(): int { return (int) (Settings::get('PRODUCT_DEFAULT_VENDOR', Context::ent(), 0) ?: 0); }

    public static function check(array $data, array $old, ?int $id): array
    {
        $v = self::vendor(); if (! $v) return [];
        $u = Auth::user(); if (! $u || Perm::has($u, self::RIGHT)) return [];
        $newV = (int) (array_key_exists('fk_id_vendor_inventory', $data) ? $data['fk_id_vendor_inventory'] : ($old['fk_id_vendor_inventory'] ?? 0));
        if (! $id) return $newV === $v ? ['fk_id_vendor_inventory' => 'Cannot add item of Local Inventory'] : [];
        return ((int) ($old['fk_id_vendor_inventory'] ?? 0) === $v || $newV === $v) ? ['_form' => 'Cannot edit item of Local Inventory'] : [];
    }
}
