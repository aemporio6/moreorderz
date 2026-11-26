<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-G_CORE-82 data-quality checks (the old unused views, now rules).
 * DQ_ITEM_NOTE: sale lines with an item note, not bought locally and with no vendor.
 * DQ_PARENT_INACTIVE: an active child variant whose parent is inactive.
 * DQ_FAMILY_COUNTS: per product family the counts active / out of stock / inactive (a report, no task).
 * Each red row raises one task per record (key = rule + record) and the task closes by itself when the row is fixed.
 */
class DataQuality
{
    public static function itemNotes(int $ent): array
    {
        return DB::table('t_sales')->where('fk_id_ent', $ent)->whereNotNull('ds_note')->where('ds_note', '<>', '')->where(fn ($w) => $w->whereNull('bought_local')->orWhere('bought_local', 0))
            ->where(fn ($w) => $w->whereNull('bought_vendor_id')->orWhere('bought_vendor_id', 0))->where(fn ($w) => $w->whereNull('fk_id_vendor')->orWhere('fk_id_vendor', 0))->where('status', '<>', 'Cancelled')
            ->orderBy('id')->limit(500)->get(['id', 'order_no', 'sku', 'ds_note'])->map(fn ($s) => ['id' => (int) $s->id, 'order' => $s->order_no, 'sku' => $s->sku, 'note' => mb_substr((string) $s->ds_note, 0, 120)])->all();
    }

    public static function parentInactive(int $ent): array
    {
        return DB::table('t_product_vendors as c')->join('t_product_vendors as p', 'p.id', '=', 'c.f_id_parent')->where('c.fk_id_ent', $ent)->where('c.f_active', 1)->where('c.f_parent', 3)->where('p.f_active', 0)
            ->where(fn ($w) => $w->whereNull('c.row_state')->orWhere('c.row_state', 'ACTIVE'))->orderBy('c.id')->limit(500)->get(['c.id', 'c.SKU_VENDOR as child', 'p.id as parent_id', 'p.SKU_VENDOR as parent'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'child' => $r->child, 'parent_id' => (int) $r->parent_id, 'parent' => $r->parent])->all();
    }

    public static function familyCounts(int $ent): array
    {
        return DB::table('t_product_vendors as p')->join('t_product_vendors as c', 'c.f_id_parent', '=', 'p.id')->where('p.fk_id_ent', $ent)->where('p.f_parent', 2)->groupBy('p.id', 'p.SKU_VENDOR', 'p.product_name_vendor', 'p.f_active')
            ->selectRaw('p.id, p.SKU_VENDOR as sku, p.product_name_vendor as name, p.f_active as parent_active, SUM(c.f_active = 1 AND c.f_out_of_stock = 0) as active, SUM(c.f_active = 1 AND c.f_out_of_stock = 1) as out_of_stock, SUM(c.f_active = 0) as inactive')
            ->orderBy('p.SKU_VENDOR')->limit(2000)->get()->map(fn ($r) => ['id' => (int) $r->id, 'sku' => $r->sku, 'name' => $r->name, 'parent_active' => (bool) $r->parent_active, 'active' => (int) $r->active, 'out_of_stock' => (int) $r->out_of_stock, 'inactive' => (int) $r->inactive])->all();
    }

    /** Open a task per red row, close the tasks whose row is fixed. Called nightly and by "Check now". */
    public static function run(int $ent): array
    {
        $a = TaskEngine::sync('DQ_ITEM_NOTE', array_map(fn ($r) => ['key' => ['sale', $r['id']], 'title' => "Order {$r['order']} ({$r['sku']}) has an item note but no vendor – set the vendor or mark it bought locally", 'opt' => ['module' => 't_sales', 'record_id' => $r['id']]], self::itemNotes($ent)), 'sale:', $ent);      /* the code is shared with the completeness score (R-G_CORE-108), so only the sale keys are synced here */
        $b = TaskEngine::sync('DQ_PARENT_INACTIVE', array_map(fn ($r) => ['key' => [$r['id']], 'title' => "Child {$r['child']} active but parent {$r['parent']} inactive", 'opt' => ['module' => 't_product_vendors', 'record_id' => $r['id']]], self::parentInactive($ent)), null, $ent);
        return ['item_note' => $a, 'parent_inactive' => $b];
    }

    public static function page(int $ent, object $u): array
    {
        Perm::need($u, 'stock.SEE'); $n = self::itemNotes($ent); $p = self::parentInactive($ent);
        return ['item_notes' => $n, 'parent_inactive' => $p, 'families' => self::familyCounts($ent), 'can_run' => Perm::has($u, 'stock.CHANGE'),
            'open_tasks' => ['DQ_ITEM_NOTE' => DB::table('tasks')->where('rule_code', 'DQ_ITEM_NOTE')->where('fk_id_ent', $ent)->whereNotIn('status', ['DONE', 'APPROVED', 'CANCELLED'])->count(), 'DQ_PARENT_INACTIVE' => DB::table('tasks')->where('rule_code', 'DQ_PARENT_INACTIVE')->where('fk_id_ent', $ent)->whereNotIn('status', ['DONE', 'APPROVED', 'CANCELLED'])->count()]];
    }
}
