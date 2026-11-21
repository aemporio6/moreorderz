<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-D-21 "Who sees which product" (row scope) with R-D-17 super roles.
 * A super user sees every product. Anyone else sees a product that is POOL on any marketplace, or visible in a shop where the user is owner,
 * seller or active assignee (t_account_branches active today, plus the shop scope set on the login). A product with no visibility rows is hidden
 * from non-super users (old). Not logged in → pool only. Applied to every product list, export, record call, dropdown and the search
 * (RecordService::scope, Registry::tableOptions, Finder::records / scan).
 * Who is super is the Owner's choice PRODUCT_SCOPE (Choices): SUPER (default, the rule: OWNER / MANAGER logins and people holding the account
 * role ADMN-SUPER), SHOP_ROLES (only VA / SELLER logins are limited) or OFF (everybody sees every product).
 */
class ProductScope
{
    public const TABLE = 't_product_vendors';
    public const SUPER_LOGINS = ['OWNER', 'MANAGER'];
    public const SUPER_ACCOUNT_ROLE = 'ADMN-SUPER';
    public const SHOP_ROLES = ['VA', 'SELLER'];

    /** R-D-17: one check by role codes – the login role and the person's active account roles. */
    public static function isSuper(?object $u): bool
    {
        if (! $u) return false;
        $mode = Choices::get('PRODUCT_SCOPE', Context::ent());
        if ($mode === 'OFF') return true;
        $role = strtoupper((string) ($u->role_code ?? ''));
        if ($mode === 'SHOP_ROLES') return ! in_array($role, self::SHOP_ROLES, true);
        if (in_array($role, self::SUPER_LOGINS, true)) return true;
        $acc = (int) ($u->fk_id_account ?? 0); if (! $acc) return false;
        return DB::table('t_account_in_types as i')->join('cat_account_types as t', 't.id', '=', 'i.fk_id_account_type')->where('i.fk_id_account', $acc)->where('i.f_active', 1)->where('t.f_active', 1)
            ->where(fn ($w) => $w->whereRaw('UPPER(t.role_code) = ?', [self::SUPER_ACCOUNT_ROLE])->orWhereRaw('UPPER(t.iso_account_type) = ?', [self::SUPER_ACCOUNT_ROLE]))->exists();
    }

    /** null = sees every product; else the shops whose private products the user sees (may be empty = pool only). */
    public static function shops(?object $u): ?array
    {
        if ($u && self::isSuper($u)) return null;
        if (! $u) return [];
        $own = DB::table('mo_user_scopes')->where('user_id', $u->id)->where('kind', 'SHOP')->pluck('ref_id')->map(fn ($x) => (int) $x)->all();
        return array_values(array_unique(array_merge($own, ShopAssignments::shopsOfUser($u))));
    }

    /** SQL condition "product $col is visible" for the shops, with its bindings. */
    public static function condition(array $shops, string $col): array
    {
        $pool = "EXISTS (SELECT 1 FROM t_product_visibility pv WHERE pv.fk_id_product = $col AND UPPER(pv.visibility_mode) <> 'PRIVATE')";
        if (! $shops) return [$pool, []];
        $in = implode(',', array_fill(0, count($shops), '?'));
        return ["($pool OR EXISTS (SELECT 1 FROM t_product_visibility_shops ps JOIN t_product_visibility pv2 ON pv2.fk_id_product = ps.fk_id_product AND pv2.fk_id_marketplace = ps.fk_id_marketplace AND UPPER(pv2.visibility_mode) = 'PRIVATE' WHERE ps.fk_id_product = $col AND ps.fk_id_branch IN ($in)))", $shops];
    }

    /** Narrow a query on products (alias = the table name or its alias). */
    public static function apply($q, ?object $u, string $alias = self::TABLE): void
    {
        $shops = self::shops($u); if ($shops === null) return;
        [$sql, $b] = self::condition($shops, "$alias.id"); $q->whereRaw($sql, $b);
    }

    /** Narrow the search index: product rows only when the product is visible. */
    public static function applyIndex($q, ?object $u): void
    {
        $shops = self::shops($u); if ($shops === null) return;
        [$sql, $b] = self::condition($shops, 'entity_id');
        $q->where(fn ($w) => $w->where(fn ($x) => $x->whereNull('entity')->orWhere('entity', '<>', self::TABLE))->orWhereRaw($sql, $b));
    }

    public static function visible(?object $u, int $productId): bool
    {
        $q = DB::table(self::TABLE)->where('id', $productId); self::apply($q, $u); return $q->exists();
    }
}
