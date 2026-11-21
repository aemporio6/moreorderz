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

    public static function nightly(int $ent): array { return ['no_cost' => self::noCost($ent), 'listings' => self::listingStatus($ent), 'imports' => self::importWaiting($ent)]; }
}
