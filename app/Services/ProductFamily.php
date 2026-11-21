<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Family coding and Add defaults of the product form (R-D-1, Product_service::familyCode()).
 * The form's switch f_family: 0 = Alone, 1 = Parent (old option values), stored in f_parent:
 *   Parent → f_parent = 2, fk_id_product_vendor_self = 1, group name kept;  Alone → f_parent = 1, fk_id_product_vendor_self = 0, group name cleared;
 *   a child always has f_parent = 3 and f_id_parent = its parent (children are made on the Variations tab, never from this switch).
 * Defaults on Add live in the registry (default_rule), not in code: Active Yes, Min alert Yes, Out of stock No, Condition = PRODUCT_DEFAULT_CONDITION (1 "New"),
 * vendor = PRODUCT_DEFAULT_VENDOR (the company's own supplier), branch 0, role 0, Min level 0, Landing 0.00, d_created today, purchase date empty;
 * every active marketplace gets a 0.00 price row. Checks: SKU trimmed ≤ 50, Min level a whole number ≥ 0, Landing price ≥ 0 with 2 dp.
 */
class ProductFamily
{
    public const ALONE = 1, PARENT = 2, CHILD = 3;

    /** f_family (0 / 1) → the stored codes. */
    public static function familyCode(int $fFamily): array
    {
        return $fFamily === 1 ? ['f_parent' => self::PARENT, 'fk_id_product_vendor_self' => 1] : ['f_parent' => self::ALONE, 'fk_id_product_vendor_self' => 0];
    }

    /** The switch shown on the form from the stored code (only Parent is "on"). */
    public static function switchOf($fParent): int { return (int) $fParent === self::PARENT ? 1 : 0; }

    /** Form save check (keys of $data are column names; errors are keyed by field name). */
    public static function check(array &$data, array $old, ?int $id): array
    {
        $e = [];
        if (array_key_exists('SKU_VENDOR', $data) && $data['SKU_VENDOR'] !== null) { $data['SKU_VENDOR'] = trim((string) $data['SKU_VENDOR']); if (mb_strlen($data['SKU_VENDOR']) > 50) $e['sku_vendor'] = 'SKU can be at most 50 characters.'; }
        if (array_key_exists('min_level', $data) && $data['min_level'] !== null && $data['min_level'] !== '') {
            if (! preg_match('/^\d+$/', (string) $data['min_level'])) $e['min_level'] = 'Min level must be a whole number, 0 or more.';
        }
        if (array_key_exists('landing_price', $data) && $data['landing_price'] !== null && $data['landing_price'] !== '') {
            if (! is_numeric($data['landing_price']) || (float) $data['landing_price'] < 0) $e['landing_price'] = 'Landing price must be 0.00 or more.';
            else $data['landing_price'] = round((float) $data['landing_price'], 2);
        }
        $wasChild = (int) ($old['f_parent'] ?? 0) === self::CHILD || ! empty($old['f_id_parent']);
        if (array_key_exists('f_parent', $data) && ! $wasChild) {
            $code = self::familyCode((int) (bool) $data['f_parent']);
            $data = array_merge($data, $code);
            if ($code['f_parent'] === self::ALONE) $data['f_group_name'] = null;      // Alone clears the group name
        } elseif ($wasChild) unset($data['f_parent']);      // a child stays a child
        if (! $id && ! array_key_exists('f_parent', $data)) $data += self::familyCode(0);
        if (! $id && empty($data['fk_id_product_condition']) && ($c = Settings::get('PRODUCT_DEFAULT_CONDITION', Context::ent(), 1))) $data['fk_id_product_condition'] = (int) $c;
        return $e;
    }

    /** After a new product: a 0.00 price row on every active marketplace. */
    public static function created(int $productId, int $ent): int
    {
        $n = 0;
        foreach (DB::table('cat_marketplaces')->whereIn('fk_id_ent', [0, $ent])->where('f_active', 1)->pluck('id') as $mp)
            if (! DB::table('t_product_marketplace_prices')->where(['fk_id_product' => $productId, 'fk_id_marketplace' => $mp])->exists()) {
                DB::table('t_product_marketplace_prices')->insert(['fk_id_product' => $productId, 'fk_id_marketplace' => $mp, 'raw_price' => 0, 'sale_price' => 0, 'list_price' => 0, 'marketplace_fee' => 0, 'created_by' => (int) (Context::userId() ?? 0), 'created_at' => now(), 'updated_at' => now()]); $n++; }
        return $n;
    }

    /** Edit: the switch shows Parent only for code 2; an empty condition pre-selects the default. */
    public static function forForm(array $row): array
    {
        if (array_key_exists('f_parent', $row)) $row['f_parent'] = self::switchOf($row['f_parent']);
        if (array_key_exists('fk_id_product_condition', $row) && empty($row['fk_id_product_condition'])) $row['fk_id_product_condition'] = (int) (Settings::get('PRODUCT_DEFAULT_CONDITION', Context::ent(), 1) ?: 1);
        return $row;
    }
}
