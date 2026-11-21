<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Night checks of the selling side that open a task and close it by themselves:
 * SAL_NO_COST (sale lines of a shop + month without a cost), LST_STATUS (a listing whose status disagrees with the stock), IMP_WAITING (an import batch stuck before Apply).
 */
class SalesWatch
{
    /** One task per shop + month while own-stock lines of that month have no cost at all; closed when none is left. Looks at the last $months months. */
    public static function noCost(int $ent, int $months = 3, ?string $today = null): int
    {
        $t = strtotime($today ?: 'today'); $from = date('Y-m-01', strtotime(date('Y-m-01', $t) . ' -' . ($months - 1) . ' month')); $n = 0;
        $bad = DB::table('t_sales as s')->join('t_branches as b', 'b.id', '=', 's.fk_id_branch')->where('s.fk_id_ent', $ent)->where('s.sale_source', 'active')->whereIn('s.status', ['Pending', 'Confirmed'])->where('s.d_order', '>=', $from)
            ->where(fn ($w) => $w->whereNull('s.raw_price')->orWhere('s.raw_price', '<=', 0))->where(fn ($w) => $w->whereNull('s.buy_amount')->orWhere('s.buy_amount', '<=', 0))->where(fn ($w) => $w->whereNull('s.cost_gbp')->orWhere('s.cost_gbp', '<=', 0))
            ->groupBy('s.fk_id_branch', 'b.shop_no')->groupByRaw("DATE_FORMAT(s.d_order,'%Y-%m')")->selectRaw("s.fk_id_branch shop, b.shop_no, DATE_FORMAT(s.d_order,'%Y-%m') ym, COUNT(*) n")->get();
        $open = [];
        foreach ($bad as $r) { $open["SAL_NO_COST:{$r->shop}:{$r->ym}"] = 1;
            if (! DB::table('tasks')->where('dedupe_key', "SAL_NO_COST:{$r->shop}:{$r->ym}")->whereNotIn('status', ['DONE', 'APPROVED', 'CANCELLED'])->exists()) $n++;
            self::task('SAL_NO_COST', [$r->shop, $r->ym], "{$r->n} sale line(s) of {$r->shop_no} " . date('M Y', strtotime($r->ym . '-01')) . ' have no cost', ['ent' => $ent, 'module' => 't_branches', 'record_id' => (int) $r->shop, 'fix_url' => '#/sales']); }
        foreach (DB::table('tasks')->where('fk_id_ent', $ent)->where('rule_code', 'SAL_NO_COST')->whereNotIn('status', ['DONE', 'APPROVED', 'CANCELLED'])->pluck('dedupe_key') as $k) if (! isset($open[$k])) TaskEngine::close('SAL_NO_COST', array_slice(explode(':', $k), 1), 'No line without cost');
        return $n;
    }

    /** A listing we ship ourselves that is ACTIVE while nothing is available, or that a marketplace report closed / suppressed while stock is available. */
    public static function listingStatus(int $ent): int
    {
        $n = 0; $open = [];
        foreach (DB::table('t_product_listings')->where('fk_id_ent', $ent)->where('f_active', 1)->where('fk_id_product', '>', 0)->whereIn('fulfilment', ['FBM', '3PL'])->whereIn('status', ['ACTIVE', 'CLOSED', 'SUPPRESSED', 'STRANDED', 'INACTIVE'])->get() as $l) {
            $avail = StockService::available((int) $l->fk_id_product); $why = null;
            if ($l->status === 'ACTIVE' && $avail <= 0) $why = 'is ACTIVE but nothing is available to sell';
            elseif ($l->status !== 'ACTIVE' && $l->last_report_at && $avail > 0) $why = 'was set to ' . $l->status . " by a marketplace report while $avail unit(s) are available";
            if (! $why) continue; $open["LST_STATUS:{$l->id}"] = 1; $name = trim((string) ($l->marketplace_sku ?: $l->item_id ?: $l->listing_title)) ?: 'listing ' . $l->id;
            if (! DB::table('tasks')->where('dedupe_key', "LST_STATUS:{$l->id}")->whereNotIn('status', ['DONE', 'APPROVED', 'CANCELLED'])->exists()) $n++;
            self::task('LST_STATUS', [$l->id], mb_substr("Listing $name $why", 0, 250), ['ent' => $ent, 'module' => 't_product_listings', 'record_id' => (int) $l->id, 'fix_url' => '#/rec/listing/' . $l->id]);
        }
        foreach (DB::table('tasks')->where('fk_id_ent', $ent)->where('rule_code', 'LST_STATUS')->whereNotIn('status', ['DONE', 'APPROVED', 'CANCELLED'])->pluck('dedupe_key') as $k) if (! isset($open[$k])) TaskEngine::close('LST_STATUS', array_slice(explode(':', $k), 1), 'Status agrees with the stock');
        return $n;
    }

    /** An import batch that was uploaded / mapped / checked but not applied for longer than IMP_WAITING_HOURS (24). Closed when it is applied or discarded. */
    public static function importWaiting(int $ent, ?string $now = null): int
    {
        $hours = max(1, (int) Settings::get('IMP_WAITING_HOURS', $ent, 24)); $limit = date('Y-m-d H:i:s', strtotime($now ?: 'now') - $hours * 3600); $n = 0; $open = [];
        foreach (DB::table('imp_batches')->where('fk_id_ent', $ent)->whereIn('status', ['UPLOADED', 'MAPPING', 'PREVIEW'])->where('created_at', '<', $limit)->get(['id', 'batch_code', 'file_name', 'status', 'report_type', 'created_at']) as $b) {
            $open["IMP_WAITING:{$b->id}"] = 1; if (! DB::table('tasks')->where('dedupe_key', "IMP_WAITING:{$b->id}")->whereNotIn('status', ['DONE', 'APPROVED', 'CANCELLED'])->exists()) $n++;
            self::task('IMP_WAITING', [$b->id], mb_substr('Import ' . ($b->batch_code ?: '#' . $b->id) . ' (' . ($b->file_name ?: $b->report_type) . ') waits since ' . date('d-m-Y H:i', strtotime((string) $b->created_at)) . ': apply it or discard it', 0, 250), ['ent' => $ent, 'module' => 'imp_batches', 'record_id' => (int) $b->id, 'fix_url' => '#/import-history']);
        }
        foreach (DB::table('tasks')->where('fk_id_ent', $ent)->where('rule_code', 'IMP_WAITING')->whereNotIn('status', ['DONE', 'APPROVED', 'CANCELLED'])->pluck('dedupe_key') as $k) if (! isset($open[$k])) TaskEngine::close('IMP_WAITING', array_slice(explode(':', $k), 1), 'Batch applied or discarded');
        return $n;
    }

    /** Opens (or reopens) the task and keeps its title current – the count or the reason may have changed since. */
    private static function task(string $rule, array $key, string $title, array $opt): void
    {
        $id = TaskEngine::open($rule, $key, $title, $opt); if ($id) DB::table('tasks')->where('id', $id)->where('title', '<>', mb_substr($title, 0, 255))->update(['title' => mb_substr($title, 0, 255)]);
    }

    public static function nightly(int $ent): array { return ['no_cost' => self::noCost($ent), 'listings' => self::listingStatus($ent), 'imports' => self::importWaiting($ent), 'tasks' => self::tasks($ent)]; }

    private static function openTasks(int $ent, string $code) { return DB::table('tasks')->where('fk_id_ent', $ent)->where('rule_code', $code)->whereIn('status', ['TODO', 'DOING', 'STUCK'])->get(['id', 'dedupe_key', 'linked_record_id']); }
    private static function done(int $taskId, string $why): void { DB::table('tasks')->where('id', $taskId)->update(['status' => 'DONE', 'done_at' => now(), 'last_update_at' => now()]); DB::table('task_history')->insert(['task_id' => $taskId, 'at' => now(), 'user_id' => 0, 'field' => 'status', 'old_value' => null, 'new_value' => 'AUTO_CLOSED', 'reason' => $why]); }

    /**
     * The sales task table (R-A_SALES-88): raise the shop × month tasks and close every sales task whose problem is gone (R-G_CORE-25).
     * Runs at night and after the actions that can end a problem (Confirm, refund decisions, Close daily orders, links of drop shipping rows, returns).
     */
    public static function tasks(int $ent): array
    {
        $closed = 0; $opened = 0;
        // SAL_ZERO – the line has a value now, was refunded / cancelled, or is gone
        foreach (self::openTasks($ent, 'SAL_ZERO') as $t) { $s = DB::table('t_sales')->where('id', $t->linked_record_id)->first(); if (! $s || (float) $s->total_amount > 0 || in_array($s->status, ['Refunded', 'Cancelled'], true) || DB::table('t_sale_refund_proposals')->where('fk_id_sale', $s->id)->exists()) { self::done($t->id, $s ? 'The line has a value or a refund now' : 'The line is gone'); $closed++; } }
        // SAL_STOCK_SHORT – a Confirm of that shop + month succeeded
        foreach (self::openTasks($ent, 'SAL_STOCK_SHORT') as $t) { if (preg_match('/:(\d+)-(\d{4}-\d{2})$/', $t->dedupe_key, $m) && DB::table('t_sales')->where('fk_id_branch', $m[1])->whereRaw("DATE_FORMAT(d_order,'%Y-%m') = ?", [$m[2]])->where('status', 'Confirmed')->exists()) { self::done($t->id, 'The month was confirmed'); $closed++; } }
        // SAL_RETURN_EXPECTED – the return was booked as received
        foreach (self::openTasks($ent, 'SAL_RETURN_EXPECTED') as $t) { $id = (int) $t->linked_record_id; if (! DB::table('t_sales')->where('id', $id)->exists() || DB::table('fin_lot_consumption')->where('fk_id_sale', $id)->whereNotNull('fk_id_reverses')->exists() || DB::table('wh_return_intakes')->where('fk_id_sale', $id)->exists()) { self::done($t->id, 'The return was booked as received'); $closed++; } }
        // PRICE_HISTORY_MISSING – a price period covers the dates of the open lines that used the current / main price
        foreach (self::openTasks($ent, 'PRICE_HISTORY_MISSING') as $t) { if (! preg_match('/:(\d+):(\d+)$/', $t->dedupe_key, $m)) continue; $first = DB::table('t_sales')->where('fk_product_id', $m[1])->where('fk_id_marketplace', $m[2])->where('status', 'Pending')->whereIn('cost_source', ['current', 'product'])->min('d_order');
            if (! $first || DB::table('t_product_inventory_change_log')->where(['fk_product_id' => $m[1], 'fk_id_marketplace' => $m[2], 'change_type' => 'price_change'])->where('from_date', '<=', $first)->exists()) { self::done($t->id, 'A price period covers the date'); $closed++; } }
        // SAL_REFUND_OPEN – refund proposals of the money report still open for a shop × month (raised and closed here)
        $open = DB::table('t_sale_refund_proposals as p')->leftJoin('t_sales as s', 's.id', '=', 'p.fk_id_sale')->join('t_branches as b', 'b.id', '=', DB::raw('COALESCE(p.fk_id_branch, s.fk_id_branch)'))->where('b.fk_id_ent', $ent)->whereIn('p.status', ['PROPOSED', 'UNKNOWN'])
            ->groupBy('b.id', 'b.shop_no', 'b.shop_name', DB::raw("DATE_FORMAT(COALESCE(s.d_order, p.d_refund, p.created_at),'%Y-%m')"))->get(['b.id', 'b.shop_no', 'b.shop_name', DB::raw("DATE_FORMAT(COALESCE(s.d_order, p.d_refund, p.created_at),'%Y-%m') as ym"), DB::raw('COUNT(*) as n'), DB::raw('SUM(p.amount) as amt')]); $keys = [];
        foreach ($open as $o) { $keys["SAL_REFUND_OPEN:{$o->id}:{$o->ym}"] = 1; if (TaskEngine::open('SAL_REFUND_OPEN', [$o->id, $o->ym], "{$o->n} refund proposal(s) of " . trim($o->shop_no . ' ' . $o->shop_name) . " · {$o->ym} wait for a decision", ['ent' => $ent, 'module' => 't_branches', 'record_id' => (int) $o->id, 'shop' => (int) $o->id, 'amount' => (float) $o->amt, 'fix_url' => '#/refunds'])) $opened++; }
        foreach (self::openTasks($ent, 'SAL_REFUND_OPEN') as $t) if (! isset($keys[$t->dedupe_key])) { self::done($t->id, 'No refund proposal is open any more'); $closed++; }
        // SAL_DAILY_OPEN – daily-only shop: lines still without price after Close daily orders (raised by closeDaily, closed here when every line is priced)
        foreach (self::openTasks($ent, 'SAL_DAILY_OPEN') as $t) { if (preg_match('/:(\d+):(\d{4}-\d{2})$/', $t->dedupe_key, $m) && ! DB::table('mo_sale_fulfilment as x')->join('t_sales as s', 's.id', '=', 'x.fk_id_sale')->where('s.fk_id_branch', $m[1])->whereRaw("DATE_FORMAT(s.d_order,'%Y-%m') = ?", [$m[2]])->where('s.status', 'Pending')->where('s.total_amount', '<=', 0)->exists()) { self::done($t->id, 'Every line of the month has its price'); $closed++; } }
        // DS_UNMATCHED – drop shipping rows not matched to a sale after the sales month is confirmed
        $rows = DB::table('drp_items as d')->join('t_branches as b', 'b.id', '=', 'd.fk_id_branch')->where('d.fk_id_ent', $ent)->where('d.period_month', '>', 0)->whereNotExists(fn ($q) => $q->from('t_sales as s')->whereColumn('s.fk_drp_item', 'd.id'))
            ->whereExists(fn ($q) => $q->from('t_sale_imports as i')->whereColumn('i.fk_id_branch', 'd.fk_id_branch')->whereColumn('i.period_month', 'd.period_month')->whereColumn('i.period_year', 'd.period_year')->where('i.status', 'Confirmed'))
            ->groupBy('b.id', 'b.shop_no', 'b.shop_name', 'd.period_month', 'd.period_year')->get(['b.id', 'b.shop_no', 'b.shop_name', 'd.period_month', 'd.period_year', DB::raw('COUNT(*) as n')]); $keys = [];
        foreach ($rows as $r) { $ym = sprintf('%04d-%02d', $r->period_year, $r->period_month); $keys["DS_UNMATCHED:{$r->id}:$ym"] = 1; if (TaskEngine::open('DS_UNMATCHED', [$r->id, $ym], "{$r->n} drop shipping row(s) of " . trim($r->shop_no . ' ' . $r->shop_name) . " · $ym match no sale line, and the month is confirmed", ['ent' => $ent, 'module' => 't_branches', 'record_id' => (int) $r->id, 'shop' => (int) $r->id, 'fix_url' => '#/ds-orders?linked=no'])) $opened++; }
        foreach (self::openTasks($ent, 'DS_UNMATCHED') as $t) if (! isset($keys[$t->dedupe_key])) { self::done($t->id, 'Every drop shipping row is matched'); $closed++; }
        // SAL_HUB_MISMATCH – a fee bucket of the month differs from the figure typed from the marketplace hub by more than HUB_TOLERANCE
        $keys = []; foreach (DB::table('mo_hub_figures')->where('fk_id_ent', $ent)->get() as $h) { $x = self::hubDiff($ent, (int) $h->fk_id_branch, $h->ym, $h->bucket); if ($x === null) continue;
            $key = "SAL_HUB_MISMATCH:{$h->fk_id_branch}:{$h->ym}:{$h->bucket}"; if (abs($x['diff']) > (float) Settings::get('HUB_TOLERANCE', $ent, 1.00)) { $keys[$key] = 1; $shop = DB::table('t_branches')->where('id', $h->fk_id_branch)->first();
                if (TaskEngine::open('SAL_HUB_MISMATCH', [$h->fk_id_branch, $h->ym, $h->bucket], trim(($shop->shop_no ?? '') . ' ' . ($shop->shop_name ?? '')) . " · {$h->ym}: {$h->bucket} is " . number_format($x['files'], 2) . ' in the money files, ' . number_format($x['hub'], 2) . ' in the hub (' . ($x['diff'] > 0 ? '+' : '') . number_format($x['diff'], 2) . ')', ['ent' => $ent, 'module' => 't_branches', 'record_id' => (int) $h->fk_id_branch, 'shop' => (int) $h->fk_id_branch, 'amount' => abs($x['diff']), 'fix_url' => "#/close/{$h->fk_id_branch}/{$h->ym}"])) $opened++; } }
        foreach (self::openTasks($ent, 'SAL_HUB_MISMATCH') as $t) if (! isset($keys[$t->dedupe_key])) { self::done($t->id, 'The difference is within the tolerance'); $closed++; }
        return ['closed' => $closed, 'opened' => $opened];
    }

    /** Money-file total of a fee bucket for a shop × month against the figure typed from the hub. */
    public static function hubDiff(int $ent, int $shopId, string $ym, string $bucket): ?array
    {
        $hub = DB::table('mo_hub_figures')->where('fk_id_branch', $shopId)->where('ym', $ym)->where('bucket', $bucket)->value('amount'); if ($hub === null) return null;
        $from = "$ym-01"; $to = date('Y-m-t', strtotime($from)); $sts = DB::table('mo_settlements')->where('fk_id_ent', $ent)->where('fk_id_branch', $shopId)->whereBetween('statement_date', [$from, $to])->pluck('statement_id');
        $files = round((float) DB::table('fin_settlement_lines')->where('branch_id', $shopId)->where('bucket', $bucket)->where(fn ($w) => $w->whereIn('settlement_id', $sts->all() ?: ['-'])->orWhereBetween('posted_date', [$from, $to . ' 23:59:59']))->sum('amount'), 2);
        return ['files' => $files, 'hub' => round((float) $hub, 2), 'diff' => round($files - (float) $hub, 2)];
    }

    /** Type the hub figures of a shop × month (TL / Owner): bucket => amount; an empty amount removes it. */
    public static function saveHub(int $shopId, string $ym, array $figures, ?object $u): array
    {
        Perm::need($u, 'orders.hubFigures'); if (! preg_match('/^\d{4}-\d{2}$/', $ym)) throw new \InvalidArgumentException('Choose the month.'); if (! Perm::shopAllowed($u, $shopId)) abort(403, "You don't have rights for this shop.");
        $ent = (int) Context::ent(); $rows = [];
        foreach ($figures as $b => $v) { $b = strtoupper(trim((string) $b)); if (! in_array($b, self::HUB_BUCKETS, true)) continue; $v = trim((string) $v);
            if ($v === '') { DB::table('mo_hub_figures')->where(['fk_id_branch' => $shopId, 'ym' => $ym, 'bucket' => $b])->delete(); continue; }
            if (! is_numeric($v)) throw new \InvalidArgumentException("$b: write a number."); DB::table('mo_hub_figures')->updateOrInsert(['fk_id_branch' => $shopId, 'ym' => $ym, 'bucket' => $b], ['fk_id_ent' => $ent, 'amount' => round((float) $v, 2), 'typed_by' => $u->id, 'typed_at' => now()]); }
        Audit::log('mo_hub_figures', $shopId, 'UPDATE', ['month' => $ym, 'figures' => $figures]); self::tasks($ent);
        return ['message' => 'Hub figures saved.', 'rows' => self::hubRows($shopId, $ym)];
    }

    public const HUB_BUCKETS = ['SALE', 'SHIPPING', 'FEE', 'PPC', 'REFUND', 'TAX'];

    public static function hubRows(int $shopId, string $ym): array
    {
        $ent = (int) Context::ent(); $tol = (float) Settings::get('HUB_TOLERANCE', $ent, 1.00);
        return array_map(function ($b) use ($ent, $shopId, $ym, $tol) { $x = self::hubDiff($ent, $shopId, $ym, $b); return ['bucket' => $b, 'hub' => $x['hub'] ?? null, 'files' => $x['files'] ?? null, 'diff' => $x['diff'] ?? null, 'ok' => $x === null ? null : abs($x['diff']) <= $tol]; }, self::HUB_BUCKETS);
    }
}
