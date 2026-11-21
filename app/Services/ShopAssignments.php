<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Shop assignment = the person's shop scope (R-C-41, IC-11, B14). One place: t_account_branches (person, shop, role SUPERVISOR / VA / MANAGER …, from, to).
 * - Unique (person, shop, role): "This shop is already assigned to this person". Only active shops. Two VA rows of one shop overlapping in dates are refused
 *   ("Shop <label> already has a VA from <date>."). From date defaults to today.
 * - Every grant / removal is in the history with the event SHOP_ACCESS_GRANTED / SHOP_ACCESS_REVOKED; created_by is kept, updated_by added.
 * - The VA valid on a date is the one source; t_branches.fk_id_seller is a computed cache of today's VA (refreshed on every change, never typed).
 * - Rights scope (Perm::shops) = the person's scope rows + these assignments valid today + shops where the person is owner or seller.
 */
class ShopAssignments
{
    public const ROLES = ['SUPERVISOR', 'VA', 'MANAGER', 'SELLER'];

    private static function label(int $shop): string
    {
        $b = DB::table('t_branches')->where('id', $shop)->first(['shop_no', 'shop_name']);
        return $b ? trim($b->shop_no . ' ' . $b->shop_name) : '#' . $shop;
    }

    /** The checks of one assignment (generic form and the Shops card). $data is changed in place. */
    public static function check(array &$data, array $old = [], ?int $id = null): array
    {
        $v = fn ($c) => array_key_exists($c, $data) ? $data[$c] : ($old[$c] ?? null);
        $shop = (int) $v('fk_id_branch'); $person = (int) $v('fk_id_account'); $role = strtoupper((string) ($v('role_code') ?: 'VA'));
        if (array_key_exists('role_code', $data) || ! $id) $data['role_code'] = $role === 'SELLER' ? 'VA' : $role;      // SELLER is the old name of VA
        $role = $data['role_code'] ?? $role;
        if (! $id && empty($v('d_from'))) $data['d_from'] = date('Y-m-d');
        $from = (string) ($data['d_from'] ?? $old['d_from'] ?? date('Y-m-d')); $to = $v('d_to');
        if (! $shop) return ['fk_id_branch' => 'Choose the shop'];
        if (! DB::table('t_branches')->where('id', $shop)->where('f_active', 1)->exists()) return ['fk_id_branch' => 'Choose an active shop'];
        if ($to && $to < $from) return ['d_to' => 'The end date is before the start date.'];
        if (DB::table('t_account_branches')->where('fk_id_account', $person)->where('fk_id_branch', $shop)->where('role_code', $role)->where(fn ($w) => $w->whereNull('f_active')->orWhere('f_active', 1))->when($id, fn ($q) => $q->where('id', '<>', $id))->exists())
            return ['fk_id_branch' => 'This shop is already assigned to this person'];
        if ($role === 'VA') {
            $clash = DB::table('t_account_branches')->where('fk_id_branch', $shop)->whereIn('role_code', ['VA', 'SELLER'])->where(fn ($w) => $w->whereNull('f_active')->orWhere('f_active', 1))->when($id, fn ($q) => $q->where('id', '<>', $id))
                ->where(fn ($w) => $w->whereNull('d_to')->orWhere('d_to', '>=', $from))->when($to, fn ($q) => $q->where(fn ($w) => $w->whereNull('d_from')->orWhere('d_from', '<=', $to)))->orderBy('d_from')->first();
            if ($clash) return ['d_from' => 'Shop ' . self::label($shop) . ' already has a VA from ' . ($clash->d_from ?: 'the start') . '.'];
        }
        return [];
    }

    /** t_branches.fk_id_seller = today's VA (a cache, never typed). */
    public static function refreshSeller(int $shop): void
    {
        if (! DB::table('t_account_branches')->where('fk_id_branch', $shop)->whereIn('role_code', ['VA', 'SELLER'])->exists()) return;      // a shop never assigned keeps its old seller
        $va = SalesImport::vaAccount($shop, date('Y-m-d'));
        DB::table('t_branches')->where('id', $shop)->update(['fk_id_seller' => $va ?: 0]);
    }

    /** After a save through the generic form or the card: history + event, cache. */
    public static function saved(int $id, bool $new): void
    {
        $a = DB::table('t_account_branches')->where('id', $id)->first(); if (! $a) return;
        EventBus::fire($new ? 'SHOP_ACCESS_GRANTED' : 'SHOP_ACCESS_GRANTED', 't_account_branches', $id, ['person' => (int) $a->fk_id_account, 'shop' => (int) $a->fk_id_branch, 'role' => $a->role_code, 'from' => $a->d_from, 'to' => $a->d_to]);
        self::refreshSeller((int) $a->fk_id_branch);
    }

    public static function save(array $d, ?int $id = null): array
    {
        $old = $id ? (array) (DB::table('t_account_branches')->where('id', $id)->first() ?? throw new \InvalidArgumentException('Assignment not found.')) : [];
        $row = array_intersect_key($d, array_flip(['fk_id_account', 'fk_id_branch', 'role_code', 'd_from', 'd_to']));
        if (array_key_exists('d_to', $row) && $row['d_to'] === '') $row['d_to'] = null;
        if ($e = self::check($row, $old, $id)) throw new ValidationErrors($e);
        if ($id) { DB::table('t_account_branches')->where('id', $id)->update($row + ['updated_at' => now(), 'updated_by' => Context::userId()]); Audit::log('t_account_branches', $id, 'UPDATE', $row); }      // created_by is kept
        else { $id = (int) DB::table('t_account_branches')->insertGetId($row + ['f_active' => 1, 'created_by' => Context::userId(), 'created_at' => now(), 'updated_at' => now()]); Audit::log('t_account_branches', $id, 'INSERT', $row); }
        self::saved($id, ! $old);
        return ['id' => $id, 'message' => 'Shop assigned.'];
    }

    /** Remove an assignment (kept in history, the event says REVOKED). */
    public static function remove(int $id): array
    {
        $a = DB::table('t_account_branches')->where('id', $id)->first() ?? throw new \InvalidArgumentException('Assignment not found.');
        DB::table('t_account_branches')->where('id', $id)->update(['f_active' => 0, 'd_to' => $a->d_to && $a->d_to < date('Y-m-d') ? $a->d_to : date('Y-m-d'), 'updated_at' => now(), 'updated_by' => Context::userId()]);
        Audit::log('t_account_branches', $id, 'DELETE', ['shop' => (int) $a->fk_id_branch, 'role' => $a->role_code]);
        EventBus::fire('SHOP_ACCESS_REVOKED', 't_account_branches', $id, ['person' => (int) $a->fk_id_account, 'shop' => (int) $a->fk_id_branch, 'role' => $a->role_code]);
        self::refreshSeller((int) $a->fk_id_branch);
        return ['message' => 'Shop removed from this person.'];
    }

    /** The cards of a person: shop no + name, marketplace, owner, VA, address, mobile. */
    public static function cards(int $person): array
    {
        return DB::table('t_account_branches as a')->join('t_branches as b', 'b.id', '=', 'a.fk_id_branch')->leftJoin('cat_marketplaces as m', 'm.id', '=', 'b.fk_id_marketplace')
            ->leftJoin('t_account as o', 'o.id', '=', 'b.fk_id_owner')->leftJoin('t_account as v', 'v.id', '=', 'b.fk_id_seller')->leftJoin('t_account_addresses as ad', 'ad.id', '=', 'b.fk_id_address')->leftJoin('t_account_mobiles as mo', 'mo.id', '=', 'b.fk_id_mobile')
            ->where('a.fk_id_account', $person)->where(fn ($w) => $w->whereNull('a.f_active')->orWhere('a.f_active', 1))->orderBy('b.shop_no')
            ->get(['a.id', 'a.fk_id_branch as shop', 'a.role_code', 'a.d_from', 'a.d_to', 'b.shop_no', 'b.shop_name', DB::raw("COALESCE(NULLIF(m.marketplace_short,''), m.marketplace_descr) as marketplace"),
                DB::raw("TRIM(CONCAT_WS(' ', o.first_name, o.sur_name)) as owner"), DB::raw("TRIM(CONCAT_WS(' ', v.first_name, v.sur_name)) as va"), 'ad.line1 as address', 'mo.mobile_no as mobile'])->map(fn ($r) => (array) $r)->all();
    }

    /**
     * Linked shops by role (R-C-42): read-only, straight from t_branches – no assignment needed, inactive shops shown greyed.
     * OWNER = t_branches.fk_id_owner; SELLER (= VA) = VA assignments valid today (R-C-41, the one source) plus the computed cache fk_id_seller.
     * Same card as the assignments, newest shop first, with counts for the role chips.
     */
    public static function linked(int $person): array
    {
        $day = date('Y-m-d');
        $va = DB::table('t_account_branches')->where('fk_id_account', $person)->whereIn('role_code', ['VA', 'SELLER'])->where(fn ($w) => $w->whereNull('f_active')->orWhere('f_active', 1))
            ->where(fn ($w) => $w->whereNull('d_from')->orWhere('d_from', '<=', $day))->where(fn ($w) => $w->whereNull('d_to')->orWhere('d_to', '>=', $day))->pluck('fk_id_branch')->all();
        $card = fn ($q) => $q->leftJoin('cat_marketplaces as m', 'm.id', '=', 'b.fk_id_marketplace')->leftJoin('t_account as o', 'o.id', '=', 'b.fk_id_owner')->leftJoin('t_account as v', 'v.id', '=', 'b.fk_id_seller')
            ->leftJoin('t_account_addresses as ad', 'ad.id', '=', 'b.fk_id_address')->leftJoin('t_account_mobiles as mo', 'mo.id', '=', 'b.fk_id_mobile')->orderByDesc('b.id')
            ->get(['b.id as shop', 'b.shop_no', 'b.shop_name', 'b.f_active', DB::raw("COALESCE(NULLIF(m.marketplace_short,''), m.marketplace_descr) as marketplace"), DB::raw("TRIM(CONCAT_WS(' ', o.first_name, o.sur_name)) as owner"),
                DB::raw("TRIM(CONCAT_WS(' ', v.first_name, v.sur_name)) as va"), 'ad.line1 as address', 'mo.mobile_no as mobile'])->map(fn ($r) => (array) $r + ['inactive' => (int) $r->f_active !== 1, 'link' => '#/rec/t_branch/' . $r->shop])->all();
        $owner = $card(DB::table('t_branches as b')->where('b.fk_id_owner', $person));
        $seller = $card(DB::table('t_branches as b')->where(fn ($w) => $w->whereIn('b.id', $va ?: [0])->orWhere('b.fk_id_seller', $person)));
        return ['roles' => [['code' => 'OWNER', 'label' => 'Owner', 'count' => count($owner), 'rows' => $owner], ['code' => 'SELLER', 'label' => 'Seller', 'count' => count($seller), 'rows' => $seller]],
            'empty' => 'No linked shop found for this role for this user.'];
    }

    /** Shops of a login from assignments valid today + shops where its person is owner or seller. */
    public static function shopsOfUser(object $u): array
    {
        $acc = (int) ($u->fk_id_account ?? 0); if (! $acc) return [];
        $day = date('Y-m-d');
        $a = DB::table('t_account_branches')->where('fk_id_account', $acc)->where(fn ($w) => $w->whereNull('f_active')->orWhere('f_active', 1))
            ->where(fn ($w) => $w->whereNull('d_from')->orWhere('d_from', '<=', $day))->where(fn ($w) => $w->whereNull('d_to')->orWhere('d_to', '>=', $day))->pluck('fk_id_branch')->all();
        $b = DB::table('t_branches')->where(fn ($w) => $w->where('fk_id_owner', $acc)->orWhere('fk_id_seller', $acc))->pluck('id')->all();
        return array_values(array_unique(array_map('intval', array_merge($a, $b))));
    }
}
