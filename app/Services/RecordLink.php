<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Owner 27-11 11:55 "is the system connected?": wherever a SKU, PO, order, supplier, partner, lot, shop, cycle or task is written on a screen
 * it opens that record – one place that knows where each kind of record lives. The screens mark such words (by their column heading) and ask
 * here only when pressed, so nothing is slower. Only what this person may open; nothing found = a clear message, never a wrong record.
 */
class RecordLink
{
    /** column heading (screen) → kind */
    public const KINDS = ['sku' => 'Product', 'po' => 'Purchase order', 'order' => 'Order', 'supplier' => 'Supplier', 'partner' => 'Partner', 'lot' => 'Stock lot', 'shop' => 'Shop', 'cycle' => 'Sourcing cycle', 'task' => 'Task'];

    public static function resolve(string $kind, string $v, object $u): ?array
    {
        $ent = (int) Context::ent(); $v = trim($v); if ($v === '' || mb_strlen($v) > 120) return null;
        $plain = trim(preg_replace('/^#/', '', $v)); $num = preg_match('/^\d+$/', $plain) ? (int) $plain : null;
        $rec = fn (string $table, int $id) => ($f = RecordService::formOfTable($table)) ? "#/rec/$f/$id" : null;
        switch ($kind) {
            case 'sku':
                $p = DB::table('t_product_vendors')->where('fk_id_ent', $ent)->where(fn ($w) => $w->where('SKU_VENDOR', $plain)->orWhere('ean', $plain))->first(['id']);
                if (! $p && preg_match('/^(.+?)\s+[·–-]\s+/u', $v, $m)) $p = DB::table('t_product_vendors')->where('fk_id_ent', $ent)->where('SKU_VENDOR', trim($m[1]))->first(['id']);      // "SKU · name"
                if (! $p) $p = DB::table('t_product_vendors')->where('fk_id_ent', $ent)->where('product_name_vendor', $v)->first(['id']);
                return $p ? ['url' => '#/inventory/' . $p->id] : null;
            case 'po':
                $po = DB::table('t_src_po')->where('fk_id_ent', $ent)->where(fn ($w) => $w->where('po_code', $plain)->orWhere('po_number', $plain)->when($num, fn ($x) => $x->orWhere('id', $num)))->first(['id']);
                return $po ? ['url' => '#/po/' . $po->id] : null;
            case 'order':
                $s = Perm::mine(DB::table('t_sales'), 'fk_id_branch')->where('fk_id_ent', $ent)->where('order_no', $plain)->orderByDesc('id')->first(['id']);
                return $s && ($r = $rec('t_sales', (int) $s->id)) ? ['url' => $r] : null;
            case 'supplier':
                $s = DB::table('t_src_suppliers')->where('fk_id_ent', $ent)->where(fn ($w) => $w->where('supplier_name', $v)->orWhere('supplier_code', $plain))->first(['id']);
                if ($s && ($r = $rec('t_src_suppliers', (int) $s->id))) return ['url' => $r];
                $c = DB::table('cat_vendors')->whereIn('fk_id_ent', [0, $ent])->where(fn ($w) => $w->where('vendor_name', $v)->orWhere('vendor_company_name', $v))->first(['id']);
                return $c && ($r = $rec('cat_vendors', (int) $c->id)) ? ['url' => $r] : null;
            case 'partner':
                if (! Perm::has($u, 'partners.SEE')) return null;
                foreach (PartnerService::partners($ent) as $p) if (mb_strtolower(trim((string) $p['name'])) === mb_strtolower($v)) return ['url' => '#/partners/' . $p['id']];
                return null;
            case 'lot':
                $l = DB::table('fin_lots')->where('company_id', $ent)->where(fn ($w) => $w->where('lot_code', $plain)->orWhere('batch_no', $plain)->when($num, fn ($x) => $x->orWhere('id', $num)))->first(['id', 'fk_id_product']);
                return $l && ($r = $rec('fin_lots', (int) $l->id)) ? ['url' => $r] : null;
            case 'shop':
                $b = Perm::mine(DB::table('t_branches'), 'id')->where('fk_id_ent', $ent)->where('shop_name', $v)->first(['id']);
                return $b && ($r = $rec('t_branches', (int) $b->id)) ? ['url' => $r] : null;
            case 'cycle':
                $c = DB::table('t_src_cycles')->where('fk_id_ent', $ent)->where(fn ($w) => $w->where('cycle_code', $plain)->when($num, fn ($x) => $x->orWhere('id', $num)))->first(['id']);
                return $c ? ['url' => '#/cycle/' . $c->id] : null;
            case 'task':
                $t = DB::table('tasks')->where('fk_id_ent', $ent)->where(fn ($w) => $w->where('title', $v)->when($num, fn ($x) => $x->orWhere('id', $num)))->orderByDesc('id')->first(['id']);
                return $t ? ['url' => '#/task/' . $t->id] : null;
        }
        return null;
    }
}
