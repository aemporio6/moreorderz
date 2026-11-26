<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * MR-1443 … MR-1450 – the decision engine: "what to do next", each with the money it is worth and ONE click to the form that does it.
 * Reads only. Every rule runs on its own (a rule that cannot run is skipped and named in "skipped"), amounts are in the company's base
 * currency, the list is sorted by money, highest first.
 *
 *   ORDER_NOW      ICC red / yellow                         → profit lost if late + cash needed       → Direct PO pre-filled (product, qty)
 *   SWITCH_MODE    ICC compare: another mode cheaper in all → £ saved                                 → the ICC of the product in that mode
 *   RAISE_PRICE    last 30 days sell > 125 % of the plan and cover < cycle → £ a month at +5 %         → the product's listings
 *   PAUSE_LISTING  margin after fees under the floor        → £ lost a month now                      → the product's listings
 *   LOWER_PRICE    stock cover > 180 days                   → holding cost a month (2 % of the value) → the product's listings
 *   REBALANCE_FBA  FBA cover < 21 days, warehouse > 60 days → sales days lost × margin                → new FC / 3PL inbound shipment
 *   CANCEL_SUB     subscription with no shop sale for 60 days → £ a year                              → Subscriptions
 *   CHASE_PAYMENT  invoice past its due date, not paid      → £ open                                  → WhatsApp text ready + the invoice
 *   CAPITAL_CALL   cash in 13 weeks (forecast) − ICC orders due < 0 → shortfall                       → Capital calls
 */
class Decisions
{
    public const LABEL = ['ORDER_NOW' => 'Order now', 'SWITCH_MODE' => 'Ship another way', 'RAISE_PRICE' => 'Raise the price', 'PAUSE_LISTING' => 'Pause the listing', 'LOWER_PRICE' => 'Lower the price',
        'REBALANCE_FBA' => 'Send stock to FBA', 'CANCEL_SUB' => 'Cancel a subscription', 'CHASE_PAYMENT' => 'Chase a payment', 'CAPITAL_CALL' => 'Ask partners for capital'];

    public static function all(int $ent, ?string $only = null): array
    {
        $out = []; $skipped = []; $base = Fx::base($ent); $today = date('Y-m-d');
        $rules = ['ORDER_NOW' => 'orderNow', 'SWITCH_MODE' => 'switchMode', 'PRICE' => 'price', 'REBALANCE_FBA' => 'fba', 'CANCEL_SUB' => 'subs', 'CHASE_PAYMENT' => 'chase', 'CAPITAL_CALL' => 'capital'];
        foreach ($rules as $code => $fn) {
            try { foreach (self::$fn($ent, $today) as $d) if (! $only || $d['code'] === $only) $out[] = $d + ['label' => self::LABEL[$d['code']]]; }
            catch (\Throwable $e) { $skipped[] = $code . ': ' . mb_substr($e->getMessage(), 0, 120); }
        }
        usort($out, fn ($a, $b) => $b['amount'] <=> $a['amount']);
        $sum = []; foreach ($out as $d) $sum[$d['code']] = ($sum[$d['code']] ?? 0) + 1;
        return ['items' => array_slice($out, 0, 200), 'counts' => $sum, 'total' => round(array_sum(array_column($out, 'amount')), 2), 'base' => $base, 'skipped' => $skipped];
    }

    private static function latestIcc(int $ent)
    {
        return DB::table('icc_results as r')->join('t_product_vendors as p', 'p.id', '=', 'r.fk_id_product')->where('p.fk_id_ent', $ent)->where('p.f_active', 1)
            ->whereIn('r.id', DB::table('icc_results')->selectRaw('MAX(id)')->groupBy('fk_id_product'))
            ->get(['r.*', 'p.SKU_VENDOR as sku', 'p.product_name_vendor as name', 'p.sale_price', 'p.landing_price', 'p.raw_price']);
    }

    private static function name(object $r): string { return trim(($r->sku ?: '#' . $r->fk_id_product) . ' ' . ($r->name ? '· ' . mb_substr($r->name, 0, 50) : '')); }

    private static function orderNow(int $ent, string $today): array
    {
        $o = [];
        foreach (self::latestIcc($ent) as $r) {
            if (! in_array($r->status, ['RED', 'YELLOW'], true) || (int) $r->order_qty <= 0) continue;
            $o[] = ['code' => 'ORDER_NOW', 'amount' => round((float) $r->lost_profit, 2), 'title' => "Order {$r->order_qty} × " . self::name($r),
                'why' => ($r->status === 'RED' ? "Runs out {$r->stock_out} – {$r->late_days} days late already" : "Order by {$r->order_by}") . " · cover {$r->cover_days} days, cycle {$r->cycle_days} days",
                'money' => ((float) $r->lost_profit > 0 ? number_format((float) $r->lost_profit, 2) . ' profit lost if late · ' : '') . number_format((float) $r->goods, 2) . ' cash needed for the goods',
                'go' => '#/direct-po?product=' . $r->fk_id_product . '&qty=' . (int) $r->order_qty, 'go_label' => 'Create PO for ' . (int) $r->order_qty, 'more' => '#/icc/' . $r->fk_id_product];
        }
        return $o;
    }

    private static function switchMode(int $ent, string $today): array
    {
        $o = []; $modes = DB::table('icc_inputs')->pluck('ship_mode', 'fk_id_product');
        foreach (self::latestIcc($ent) as $r) {
            $cmp = json_decode((string) $r->compare_json, true) ?: []; if (count($cmp) < 2) continue;
            $cur = strtoupper((string) ($modes[$r->fk_id_product] ?? '') ?: 'SEA'); $now = collect($cmp)->firstWhere('mode', $cur); $best = $cmp[0];
            if (! $now || $best['mode'] === $cur) continue;
            $save = round((float) $now['total'] - (float) $best['total'], 2); if ($save < 1) continue;
            $o[] = ['code' => 'SWITCH_MODE', 'amount' => $save, 'title' => "Ship " . self::name($r) . " by {$best['mode']} instead of $cur",
                'why' => "$cur: freight " . number_format((float) $now['freight'], 2) . " + lost profit " . number_format((float) $now['lost_profit'], 2) . " · {$best['mode']}: freight " . number_format((float) $best['freight'], 2) . " + lost profit " . number_format((float) $best['lost_profit'], 2),
                'money' => number_format($save, 2) . ' saved in total', 'go' => '#/icc/' . $r->fk_id_product . '?mode=' . $best['mode'], 'go_label' => 'Open with ' . $best['mode']];
        }
        return $o;
    }

    private static function price(int $ent, string $today): array
    {
        $o = []; $floor = (float) Settings::get('MARGIN_FLOOR', $ent, 0); $from30 = date('Y-m-d', strtotime("$today -30 days"));
        $inputs = DB::table('icc_inputs')->get()->keyBy('fk_id_product');
        foreach (self::latestIcc($ent) as $r) {
            $pid = (int) $r->fk_id_product; $plan = (float) $r->monthly_used; $in = $inputs[$pid] ?? null;
            $price = (float) (($in->sale_price ?? 0) ?: $r->sale_price); $cost = (float) (($in->unit_cost ?? 0) ?: ($r->landing_price ?: $r->raw_price));
            $margin = (float) (($in->margin_unit ?? 0) ?: ($price > 0 ? round($price * 0.72 - $cost - 1.4, 2) : 0));
            $last30 = (float) DB::table('t_sales')->where('fk_product_id', $pid)->where('d_order', '>=', $from30)->where(fn ($w) => $w->whereNull('status')->orWhereNotIn('status', ['Cancelled', 'CANCELLED']))->sum('qty');
            $go = '#/list/listing?f[fk_id_product]=' . $pid;
            if ($plan > 0 && $last30 > $plan * 1.25 && $r->cover_days !== null && (int) $r->cover_days < (int) $r->cycle_days && $price > 0)
                $o[] = ['code' => 'RAISE_PRICE', 'amount' => round($last30 * $price * 0.05, 2), 'title' => 'Raise the price of ' . self::name($r) . ' by 5 %',
                    'why' => "Sold " . (int) $last30 . " in 30 days against a plan of " . (int) $plan . " · cover {$r->cover_days} days is shorter than the cycle {$r->cycle_days} days – it will run out before new stock comes",
                    'money' => number_format($last30 * $price * 0.05, 2) . ' more a month', 'go' => $go, 'go_label' => 'Open the listings'];
            if ($price > 0 && $plan > 0 && $margin < $floor)
                $o[] = ['code' => 'PAUSE_LISTING', 'amount' => round($plan * ($floor - $margin), 2), 'title' => 'Pause or re-price ' . self::name($r),
                    'why' => 'Margin after fees ' . number_format($margin, 2) . ' a unit – under the floor ' . number_format($floor, 2) . " (price " . number_format($price, 2) . ", cost " . number_format($cost, 2) . ')',
                    'money' => number_format($plan * ($floor - $margin), 2) . ' lost a month now', 'go' => $go, 'go_label' => 'Open the listings'];
            if ($r->cover_days !== null && (int) $r->cover_days > 180 && (int) $r->avail > 0 && $cost > 0) {
                $daily = max(0.0001, (float) $r->daily); $extra = max(0, (int) $r->avail - (int) ceil($daily * 90)); $hold = round($extra * $cost * 0.02, 2);
                if ($hold >= 1) $o[] = ['code' => 'LOWER_PRICE', 'amount' => $hold, 'title' => 'Lower the price of ' . self::name($r),
                    'why' => "Stock lasts {$r->cover_days} days – " . $extra . ' units more than 90 days need', 'money' => number_format($hold, 2) . ' holding cost a month (2 % of ' . number_format($extra * $cost, 2) . ')',
                    'go' => $go, 'go_label' => 'Open the listings'];
            }
        }
        return $o;
    }

    private static function fba(int $ent, string $today): array
    {
        $snap = DB::table('fin_fba_stock_snapshots as s')->join('t_branches as b', 'b.id', '=', 's.branch_id')->where('b.fk_id_ent', $ent)
            ->whereIn('s.id', DB::table('fin_fba_stock_snapshots')->selectRaw('MAX(id)')->groupBy('branch_id', 'sku'))->get(['s.branch_id', 's.sku', 's.fulfillable', 's.inbound_working', 's.inbound_shipped', 's.inbound_receiving', 'b.shop_name']);
        $o = []; $from30 = date('Y-m-d', strtotime("$today -30 days"));
        foreach ($snap as $s) {
            $pid = (int) DB::table('t_product_vendors')->where('fk_id_ent', $ent)->where('SKU_VENDOR', $s->sku)->value('id')
                ?: (int) DB::table('t_product_listings')->where('fk_id_branch', $s->branch_id)->where('marketplace_sku', $s->sku)->value('fk_id_product'); if (! $pid) continue;
            $sold = (float) DB::table('t_sales')->where('fk_id_branch', $s->branch_id)->where('sku', $s->sku)->where('d_order', '>=', $from30)->sum('qty'); $daily = $sold / 30; if ($daily <= 0) continue;
            $fba = (int) $s->fulfillable + (int) $s->inbound_working + (int) $s->inbound_shipped + (int) $s->inbound_receiving; $cover = $fba / $daily;
            $wh = StockService::available($pid); $whDays = $wh / $daily;
            if ($cover >= 21 || $whDays <= 60) continue;
            $margin = (float) (DB::table('icc_inputs')->where('fk_id_product', $pid)->value('margin_unit') ?: 0); $lostDays = (int) ceil(21 - $cover); $send = (int) min($wh - ceil($daily * 30), ceil($daily * 45));
            $o[] = ['code' => 'REBALANCE_FBA', 'amount' => round($lostDays * $daily * max(0, $margin), 2), 'title' => "Send $send × {$s->sku} to FBA ({$s->shop_name})",
                'why' => 'FBA has ' . (int) $cover . ' days of stock, the warehouse ' . (int) $whDays . ' days', 'money' => "$lostDays sales days lost" . ($margin > 0 ? ' · ' . number_format($lostDays * $daily * $margin, 2) . ' profit' : ''),
                'go' => '#/list/fba_shipment', 'go_label' => 'New FBA shipment'];
        }
        return $o;
    }

    private static function subs(int $ent, string $today): array
    {
        $o = []; $from = date('Y-m-d', strtotime("$today -60 days"));
        $rows = DB::table('t_account_subscriptions as s')->join('t_account as a', 'a.id', '=', 's.fk_id_account')->leftJoin('cat_subscription_items as i', 'i.id', '=', 's.fk_item_id')->leftJoin('t_branches as b', 'b.id', '=', 's.fk_id_branch')
            ->where('a.fk_id_ent', $ent)->where('s.f_active', 1)->where('s.price', '>', 0)->get(['s.id', 's.fk_id_branch', 's.price', 's.billing_cycle', 's.fk_id_currency', 'i.item_name', 'b.shop_name']);
        foreach ($rows as $s) {
            $m = Subscriptions::MONTHS[$s->billing_cycle ?: 'MONTHLY'] ?? null; if (! $m) continue;
            $used = $s->fk_id_branch && DB::table('t_sales')->where('fk_id_branch', $s->fk_id_branch)->where('d_order', '>=', $from)->exists(); if ($used) continue;
            $cur = $s->fk_id_currency ? Fx::code((int) $s->fk_id_currency, $ent) : Fx::base($ent); $year = Fx::toBase((float) $s->price / $m * 12, $cur, $today, $ent);
            $o[] = ['code' => 'CANCEL_SUB', 'amount' => round($year, 2), 'title' => 'Cancel ' . ($s->item_name ?: 'subscription #' . $s->id) . ($s->shop_name ? " ({$s->shop_name})" : ''),
                'why' => $s->fk_id_branch ? 'Its shop sold nothing in 60 days' : 'Not linked to any shop', 'money' => number_format($year, 2) . ' a year', 'go' => '#/subscriptions', 'go_label' => 'Open subscriptions'];
        }
        return $o;
    }

    private static function chase(int $ent, string $today): array
    {
        $o = [];
        foreach (DB::table('t_invoices')->where('fk_id_ent', $ent)->whereIn('status', ['UNPAID', 'PART_PAID'])->where('invoice_type', '<>', 'CREDIT_NOTE')->whereNotNull('d_due')->where('d_due', '<', $today)
            ->whereRaw('total - COALESCE(amount_paid,0) > 0.005')->limit(200)->get(['id', 'invoice_no', 'd_due', 'total', 'amount_paid', 'currency', 'recipient_json']) as $i) {
            $open = (float) $i->total - (float) $i->amount_paid; $who = (string) (json_decode((string) $i->recipient_json, true)['name'] ?? 'the customer'); $days = (int) floor((strtotime($today) - strtotime((string) $i->d_due)) / 86400);
            $text = "Hello $who, a friendly reminder: invoice {$i->invoice_no} of " . number_format($open, 2) . " {$i->currency} was due on " . date('d-m-Y', strtotime((string) $i->d_due)) . '. Could you let us know when it will be paid? Thank you.';
            $o[] = ['code' => 'CHASE_PAYMENT', 'amount' => round(Fx::toBase($open, $i->currency ?: Fx::base($ent), $today, $ent), 2), 'title' => "Chase invoice {$i->invoice_no} – $who",
                'why' => "$days days past due", 'money' => number_format($open, 2) . " {$i->currency} open", 'go' => 'https://wa.me/?text=' . rawurlencode($text), 'go_label' => 'WhatsApp reminder', 'more' => '#/invoices', 'text' => $text];
        }
        return $o;
    }

    private static function capital(int $ent, string $today): array
    {
        $f = CashForecast::build($ent, 13); $low = $f['rows'] ? min(array_column($f['rows'], 'balance') ?: [0]) : (float) $f['bank_now'];
        $limit = (float) ($f['alert_limit'] ?? 0); $icc = 0.0; $soon = date('Y-m-d', strtotime("$today +90 days"));
        foreach (self::latestIcc($ent) as $r) if (in_array($r->status, ['RED', 'YELLOW', 'GREEN'], true) && $r->order_by && $r->order_by <= $soon) $icc += (float) $r->goods;
        $short = round($limit + $icc - $low, 2); if ($short <= 0) return [];
        return [['code' => 'CAPITAL_CALL', 'amount' => $short, 'title' => 'Ask the partners for ' . number_format($short, 2),
            'why' => 'Lowest cash in the next 13 weeks ' . number_format($low, 2) . ' · ICC orders due in 90 days ' . number_format($icc, 2) . ($limit ? ' · keep at least ' . number_format($limit, 2) : ''),
            'money' => number_format($short, 2) . ' short', 'go' => '#/capital-calls', 'go_label' => 'New capital call']];
    }
}
