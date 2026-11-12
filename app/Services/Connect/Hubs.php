<?php

namespace App\Services\Connect;

use App\Services\Context;
use Illuminate\Support\Facades\DB;

/**
 * 6.4 Marketplace operations hubs: one card per shop – how it is connected (files or an API), when orders and money last came in,
 * what waits (order lines with an unknown SKU, money statements not booked, payouts not on the bank), and the buttons to pull or load.
 */
class Hubs
{
    public static function page(int $ent): array
    {
        $month = date('Y-m-01');
        $shops = DB::table('t_branches as b')->leftJoin('cat_marketplaces as m', 'm.id', '=', 'b.fk_id_marketplace')->leftJoin('mp_profiles as p', 'p.id', '=', 'b.fk_id_mp_profile')
            ->where('b.fk_id_ent', $ent)->where('b.f_active', 1)->orderBy('m.marketplace_short')->orderBy('b.shop_name')
            ->get(['b.id', 'b.shop_name', 'm.marketplace_short as marketplace', 'p.code as profile', 'p.name as profile_name', 'p.overdue_days', 'p.seller_portal_url']);
        $ids = $shops->pluck('id')->all();
        $conn = DB::table('api_connections')->whereIn('fk_id_branch', $ids ?: [0])->get()->groupBy('fk_id_branch');
        $orders = DB::table('t_sales')->whereIn('fk_id_branch', $ids ?: [0])->where(fn ($q) => $q->whereNull('sale_source')->orWhere('sale_source', '<>', 'history'))
            ->groupBy('fk_id_branch')->selectRaw('fk_id_branch, MAX(created_at) last_in, MAX(d_order) last_order, SUM(d_order >= ?) this_month, SUM(fk_product_id IS NULL AND status <> ?) unknown_sku, SUM(status = ?) pending', [$month, 'Cancelled', 'Pending'])->get()->keyBy('fk_id_branch');
        $money = DB::table('mo_settlements')->whereIn('fk_id_branch', $ids ?: [0])->groupBy('fk_id_branch')
            ->selectRaw("fk_id_branch, MAX(created_at) last_in, MAX(statement_date) last_statement, SUM(status = 'LOADED') not_booked, SUM(status = 'POSTED') not_on_bank, SUM(statement_date >= ?) this_month", [$month])->get()->keyBy('fk_id_branch');
        $out = [];
        foreach ($shops as $s) {
            $c = $conn[$s->id] ?? collect(); $api = $c->first(fn ($x) => $x->adapter && $x->adapter !== 'FILE');
            $o = $orders[$s->id] ?? null; $m = $money[$s->id] ?? null; $late = (int) ($s->overdue_days ?: 5);
            $warn = [];
            if ($o && $o->unknown_sku) $warn[] = $o->unknown_sku . ' order line(s) with an unknown SKU';
            if ($m && $m->not_booked) $warn[] = $m->not_booked . ' money statement(s) not booked';
            if ($m && $m->not_on_bank) $warn[] = $m->not_on_bank . ' payout(s) not matched to the bank yet';
            if (! $m || ! $m->last_statement || $m->last_statement < date('Y-m-d', strtotime("-$late days -1 month"))) $warn[] = 'No money file for more than a month';
            if ($api && $api->status !== 'CONNECTED') $warn[] = 'Connection: ' . strtolower($api->status) . ($api->last_error ? ' – ' . $api->last_error : '');
            $out[] = ['id' => $s->id, 'shop' => $s->shop_name, 'marketplace' => $s->marketplace, 'profile' => $s->profile_name ?: $s->profile, 'portal' => $s->seller_portal_url,
                'connection' => $api ? ['id' => $api->id, 'adapter' => $api->adapter, 'label' => $api->label, 'status' => $api->status, 'last_sync_at' => $api->last_sync_at,
                    'money_api' => ! in_array($api->adapter, ['ONBUY_API', 'TEMU_API'], true), 'honest' => Connections::ADAPTERS[$api->adapter][6] ?? ''] : null,
                'orders' => ['last_in' => $o->last_in ?? null, 'last_order' => $o->last_order ?? null, 'this_month' => (int) ($o->this_month ?? 0), 'pending' => (int) ($o->pending ?? 0)],
                'money' => ['last_in' => $m->last_in ?? null, 'last_statement' => $m->last_statement ?? null, 'this_month' => (int) ($m->this_month ?? 0)],
                'warnings' => $warn];
        }
        return ['shops' => $out, 'month' => substr($month, 0, 7), 'manage' => Connections::mayManage()];
    }
}
