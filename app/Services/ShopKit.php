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
            $rows[] = ['account' => (int) $a->id, 'person_id' => (int) $a->fk_id_account, 'person' => $name((int) $a->fk_id_account), 'type' => $a->marketplace_type, 'two_fa' => ($holder !== '' || $dev) ? '2FA holder: ' . ($holder ?: '—') . ' on ' . ($dev ?: '—') : null,
                'payout_mode' => $a->payout_mode, 'health' => $health, 'ok' => ! $health];
        }
        $loose = DB::table('t_account_marketplaces')->where('fk_id_marketplace', $shop->fk_id_marketplace)->where(fn ($w) => $w->whereNull('fk_id_shop')->orWhere('fk_id_shop', 0))->where('market_place_type', 'Seller')
            ->where(fn ($w) => $w->whereNull('f_active')->orWhere('f_active', 1))->get(['id', 'fk_id_account'])->map(fn ($a) => ['account' => (int) $a->id, 'person' => $name((int) $a->fk_id_account), 'health' => ['missing shop link']])->all();
        return ['shop' => ['id' => (int) $shop->id, 'name' => trim(($shop->shop_no ? $shop->shop_no . '-' : '') . $shop->shop_name)], 'accounts' => $rows, 'missing_shop_link' => $loose, 'ok' => $rows && ! array_filter($rows, fn ($r) => ! $r['ok'])]
            + self::items($ent, $shop, array_values(array_unique(array_merge(array_column($rows, 'person_id'), DB::table('t_account_marketplaces')->where('fk_id_shop', $shopId)->pluck('fk_id_account')->map(fn ($x) => (int) $x)->all()))));
    }

    /**
     * M42 the rest of the kit of a shop, with due dates: subscriptions paid for the shop (tools, VPN, VPS …) with their cost per month (R-C-33),
     * social media accounts that promote it (R-C-37), and the IP accounts, PCs and SIMs the shop's sellers use on its marketplace (R-C-34 / 35).
     */
    private static function items(int $ent, object $shop, array $people): array
    {
        $act = fn ($q, string $a) => $q->where(fn ($w) => $w->whereNull("$a.f_active")->orWhere("$a.f_active", 1));
        $base = Fx::base($ent); $total = 0.0; $missing = [];
        $subs = $act(DB::table('t_account_subscriptions as s')->leftJoin('cat_subscription_items as i', 'i.id', '=', 's.fk_item_id')->leftJoin('cat_currency as c', 'c.id', '=', 's.fk_id_currency')->where('s.fk_id_branch', $shop->id), 's')
            ->orderBy('s.next_due_date')->get(['s.id', 'i.item_name', 'i.kind', 's.billing_cycle', 's.price', 'c.currency_code', 's.next_due_date'])->map(function ($r) use ($base, &$total, &$missing) {
                $m = Subscriptions::MONTHS[strtoupper((string) ($r->billing_cycle ?: 'MONTHLY'))] ?? null; $cur = $r->currency_code ?: $base;
                $pm = $m && $r->price !== null ? round((float) $r->price / $m, 2) : null;
                if ($pm !== null) { try { $total += $cur === $base ? $pm : $pm * Fx::rate($cur, date('Y-m-d'), (int) Context::ent()); } catch (\Throwable $e) { $missing[] = $cur; } }
                return ['id' => (int) $r->id, 'item' => $r->item_name, 'kind' => $r->kind ?: 'TOOL', 'cycle' => $r->billing_cycle, 'price' => $r->price === null ? null : (float) $r->price, 'currency' => $cur, 'per_month' => $pm, 'next_due' => $r->next_due_date];
            })->all();
        $social = $act(DB::table('t_account_social_media as s')->leftJoin('cat_social_media as c', 'c.id', '=', 's.fk_social_media_id')->where('s.fk_id_branch', $shop->id), 's')
            ->get(['s.id', 's.username', 's.fk_id_account', DB::raw('c.' . (Registry::labelColumn('cat_social_media') ?: 'id') . ' as platform')])->map(fn ($r) => ['id' => (int) $r->id, 'platform' => $r->platform, 'username' => $r->username, 'person_id' => (int) $r->fk_id_account])->all();
        $ips = $people ? $act(DB::table('t_account_ipburgers as p')->whereIn('p.fk_id_account', $people)->where('p.ipb_marketplace_id', $shop->fk_id_marketplace), 'p')
            ->get(['p.id', 'p.fk_id_account', 'p.fk_ipburger_id', 'p.ipb_expires', 'p.renewal_due', 'p.billing_cycle'])->map(fn ($r) => ['id' => (int) $r->id, 'person_id' => (int) $r->fk_id_account, 'ip' => (int) $r->fk_ipburger_id, 'expires' => $r->ipb_expires, 'next_renewal' => $r->renewal_due, 'cycle' => $r->billing_cycle])->all() : [];
        $pcs = $people ? DB::table('t_account_pc as p')->leftJoin('t_account_mobiles as m', 'm.id', '=', 'p.hotspot_mobile_id')->whereIn('p.fk_id_account', $people)->where('p.fk_marketplace_id', $shop->fk_id_marketplace)
            ->get(['p.id', 'p.pc_id', 'p.fk_id_account', 'm.mobile_no'])->map(fn ($r) => ['id' => (int) $r->id, 'pc' => (int) $r->pc_id, 'person_id' => (int) $r->fk_id_account, 'hotspot' => $r->mobile_no])->all() : [];
        $due = array_values(array_filter(array_merge(array_map(fn ($s) => ['what' => $s['item'], 'date' => $s['next_due']], $subs), array_map(fn ($i) => ['what' => 'IP account', 'date' => $i['next_renewal'] ?: $i['expires']], $ips)), fn ($d) => $d['date']));
        usort($due, fn ($a, $b) => strcmp((string) $a['date'], (string) $b['date']));
        return ['subscriptions' => $subs, 'social_media' => $social, 'ip_accounts' => $ips, 'pcs' => $pcs, 'monthly_cost' => round($total, 2), 'cost_currency' => $base,
            'cost_note' => $missing ? 'Rate missing for ' . implode(', ', array_unique($missing)) . ' – not in the monthly cost.' : null, 'due' => $due];
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
