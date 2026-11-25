<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-C-30 / PHASE_07 §7.5 the shop's Account kit reads the seller marketplace accounts (t_account_marketplaces, side Seller):
 * each linked seller account with its health – missing payout account, missing payment account, 2-step on but no authenticator holder, "2FA holder: {person} on {device}";
 * and seller accounts of the shop's marketplace that are not linked to any shop ("missing shop link").
 */
class ShopKit
{
    public static function kit(int $ent, int $shopId): array
    {
        $shop = DB::table('t_branches')->where('id', $shopId)->where('fk_id_ent', $ent)->first(['id', 'shop_no', 'shop_name', 'fk_id_marketplace']) ?? throw new \InvalidArgumentException('Shop not found.');
        $name = fn ($id) => $id ? trim((string) DB::table('t_account')->where('id', $id)->selectRaw("CONCAT_WS(' ', first_name, sur_name) n")->value('n')) : '';
        $rows = [];
        foreach (DB::table('t_account_marketplaces')->where('fk_id_shop', $shopId)->where(fn ($w) => $w->whereNull('f_active')->orWhere('f_active', 1))->get() as $a) {
            $health = [];
            if (! $a->fk_id_bank_account) $health[] = 'missing payout account';
            if (! $a->fk_id_bank_payment_account) $health[] = 'missing payment account';
            $dev = $a->authenticator_device ? DB::table('t_mobile_devices')->where('id', $a->authenticator_device)->value('mobile_name') : null;
            $holder = trim((string) $a->authenticator_person);
            if ((int) $a->two_step_verification && $holder === '' && ! $dev) $health[] = '2-step on but no authenticator holder';
            $rows[] = ['account' => (int) $a->id, 'person' => $name((int) $a->fk_id_account), 'type' => $a->marketplace_type, 'two_fa' => ($holder !== '' || $dev) ? '2FA holder: ' . ($holder ?: '—') . ' on ' . ($dev ?: '—') : null,
                'payout_mode' => $a->payout_mode, 'health' => $health, 'ok' => ! $health];
        }
        $loose = DB::table('t_account_marketplaces')->where('fk_id_marketplace', $shop->fk_id_marketplace)->where(fn ($w) => $w->whereNull('fk_id_shop')->orWhere('fk_id_shop', 0))->where('market_place_type', 'Seller')
            ->where(fn ($w) => $w->whereNull('f_active')->orWhere('f_active', 1))->get(['id', 'fk_id_account'])->map(fn ($a) => ['account' => (int) $a->id, 'person' => $name((int) $a->fk_id_account), 'health' => ['missing shop link']])->all();
        return ['shop' => ['id' => (int) $shop->id, 'name' => trim(($shop->shop_no ? $shop->shop_no . '-' : '') . $shop->shop_name)], 'accounts' => $rows, 'missing_shop_link' => $loose, 'ok' => $rows && ! array_filter($rows, fn ($r) => ! $r['ok'])];
    }

    /** FormRules hook (R-C-30): a Seller account needs its shop, and the shop must be an active shop of the chosen marketplace. */
    public static function check(array $data, array $old, ?int $id): array
    {
        $v = fn ($k) => array_key_exists($k, $data) ? $data[$k] : ($old[$k] ?? null);
        if (strcasecmp((string) $v('market_place_type'), 'Seller') !== 0) return [];
        $shop = (int) $v('fk_id_shop'); if (! $shop) return ['fk_id_shop' => 'Shop is required'];
        $mp = (int) $v('fk_id_marketplace');
        if ($mp && ! DB::table('t_branches')->where('id', $shop)->where('fk_id_marketplace', $mp)->where('f_active', 1)->exists()) return ['fk_id_shop' => 'Choose an active shop of this marketplace.'];
        return [];
    }
}
