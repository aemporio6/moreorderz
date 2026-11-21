<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Hide, delete, restore products (R-D-9).
 * - Hide (= archive) = row state HIDDEN with a reason (the product and its children leave the active lists); the saved view "Hidden products" (old Invisible Listings) has Restore.
 * - Delete = soft delete that cascades to the children in the same batch (one Restore brings all back), answer "Deleted <SKU> + n child SKU(s)";
 *   blocked when the product or a child has stock > 0, an open lot, an open PO line or any sale → "Archive instead".
 *   The children's t_variation_marketplace_* rows go with them and come back with them.
 * Rights: products.hide (Owner, Manager), products.delete (Owner). Every step is in the audit log.
 */
class ProductArchive
{
    public const VARIATION_TABLES = ['t_variation_marketplace_prices', 't_variation_marketplace_shipping'];

    /** The product and its children (any state). */
    public static function family(int $id): array
    {
        return array_merge([$id], DB::table('t_product_vendors')->where('f_id_parent', $id)->where('id', '<>', $id)->pluck('id')->map(fn ($x) => (int) $x)->all());
    }

    /** Why the family cannot be deleted (empty = it can). */
    public static function blockers(array $ids): array
    {
        $out = [];
        $stock = DB::table('t_product_vendors')->whereIn('id', $ids)->where('stock', '>', 0)->pluck('SKU_VENDOR')->all();
        if ($stock) $out[] = 'stock on ' . implode(', ', array_slice($stock, 0, 5));
        if (DB::table('fin_lots')->whereIn('fk_id_product', $ids)->where('qty_left', '>', 0)->exists()) $out[] = 'an open lot';
        if (DB::table('t_src_po_lines as l')->join('t_src_po as p', 'p.id', '=', 'l.fk_id_po')->whereIn('l.fk_id_product', $ids)->where('l.f_active', 1)->where('p.f_active', 1)
            ->where(fn ($w) => $w->whereNull('p.status')->orWhereNotIn('p.status', ['RECEIVED', 'CLOSED', 'CANCELLED']))->exists()) $out[] = 'an open PO line';
        if (DB::table('t_sales')->whereIn('fk_product_id', $ids)->exists()) $out[] = 'sales';
        return $out;
    }

    public static function hide(int $id, string $reason, object $u): array
    {
        Perm::need($u, 'products.hide');
        $reason = trim($reason); if (mb_strlen($reason) < 3) throw new \InvalidArgumentException('Give the reason for hiding the product.');
        $p = DB::table('t_product_vendors')->where('id', $id)->first() ?? throw new \InvalidArgumentException('Product not found.');
        if ($p->row_state === 'DELETED') throw new \InvalidArgumentException('This product is in the recycle bin – restore it first.');
        $ids = DB::table('t_product_vendors')->whereIn('id', self::family($id))->where('row_state', 'ACTIVE')->pluck('id')->all();
        DB::table('t_product_vendors')->whereIn('id', $ids)->update(['row_state' => 'HIDDEN', 'f_active' => 0, 'hidden_reason' => mb_substr($reason, 0, 255), 'hidden_at' => now(), 'hidden_by' => $u->id]);
        foreach ($ids as $i) Audit::log('t_product_vendors', (int) $i, 'UPDATE', ['row_state' => ['ACTIVE', 'HIDDEN']], $reason);
        return ['hidden' => count($ids), 'message' => "{$p->SKU_VENDOR} hidden" . (count($ids) > 1 ? ' with ' . (count($ids) - 1) . ' child SKU(s)' : '') . '. It is in the saved view "Hidden products".'];
    }

    public static function delete(int $id, object $u): array
    {
        Perm::need($u, 'products.delete');
        return DB::transaction(function () use ($id, $u) {
            $p = DB::table('t_product_vendors')->where('id', $id)->lockForUpdate()->first() ?? throw new \InvalidArgumentException('Product not found.');
            if ($p->row_state === 'DELETED') throw new \InvalidArgumentException('This product is already in the recycle bin.');
            $ids = self::family($id);
            if ($b = self::blockers($ids)) throw new \InvalidArgumentException('Archive instead: ' . $p->SKU_VENDOR . ' has ' . implode(', ', $b) . '. Hide it – a product with history cannot be deleted.');
            $group = 'PRD-' . $id . '-' . date('YmdHis');
            foreach (DB::table('t_product_vendors')->whereIn('id', $ids)->get() as $r) {
                DB::table('mo_recycle_bin')->insert(['fk_id_ent' => (int) ($r->fk_id_ent ?: Context::ent()), 'table_name' => 't_product_vendors', 'row_id' => (int) $r->id, 'label' => mb_substr(trim($r->SKU_VENDOR . ' · ' . $r->product_name_vendor), 0, 255), 'group_key' => $group,
                    'row_json' => json_encode(['row_state' => $r->row_state, 'f_active' => (int) $r->f_active, 'variations' => collect(self::VARIATION_TABLES)->mapWithKeys(fn ($t) => [$t => DB::table($t)->where('fk_id_variation', $r->id)->where('f_active', 1)->pluck('id')->all()])->all()]),
                    'deleted_by' => $u->id, 'deleted_at' => now(), 'note' => (int) $r->id === $id ? 'Deleted with its children' : 'Child of ' . $p->SKU_VENDOR]);
                foreach (self::VARIATION_TABLES as $t) DB::table($t)->where('fk_id_variation', $r->id)->update(['f_active' => 0]);
                Audit::log('t_product_vendors', (int) $r->id, 'DELETE', ['group' => $group]);
            }
            DB::table('t_product_vendors')->whereIn('id', $ids)->update(['row_state' => 'DELETED', 'f_active' => 0, 'delete_group' => $group]);
            return ['group' => $group, 'rows' => count($ids), 'message' => "Deleted {$p->SKU_VENDOR} + " . (count($ids) - 1) . ' child SKU(s).'];
        });
    }

    /** Restore: a hidden product (and its hidden children) comes back; a deleted one brings its whole batch back. */
    public static function restore(int $id, object $u): array
    {
        Perm::need($u, 'products.hide');
        $p = DB::table('t_product_vendors')->where('id', $id)->first() ?? throw new \InvalidArgumentException('Product not found.');
        if ($p->row_state === 'HIDDEN') {
            $ids = DB::table('t_product_vendors')->whereIn('id', self::family($id))->where('row_state', 'HIDDEN')->pluck('id')->all();
            DB::table('t_product_vendors')->whereIn('id', $ids)->update(['row_state' => 'ACTIVE', 'f_active' => 1, 'hidden_reason' => null, 'hidden_at' => null, 'hidden_by' => null]);
            foreach ($ids as $i) Audit::log('t_product_vendors', (int) $i, 'RESTORE', ['row_state' => ['HIDDEN', 'ACTIVE']]);
            return ['restored' => count($ids), 'message' => "{$p->SKU_VENDOR} is back" . (count($ids) > 1 ? ' with ' . (count($ids) - 1) . ' child SKU(s)' : '') . '.'];
        }
        if ($p->row_state !== 'DELETED' || ! $p->delete_group) throw new \InvalidArgumentException('This product is not hidden or deleted.');
        Perm::need($u, 'products.delete');
        return DB::transaction(function () use ($p) {
            $n = 0;
            foreach (DB::table('mo_recycle_bin')->where('group_key', $p->delete_group)->where('table_name', 't_product_vendors')->whereNull('restored_at')->lockForUpdate()->get() as $b) {
                $j = json_decode((string) $b->row_json, true) ?: [];
                DB::table('t_product_vendors')->where('id', $b->row_id)->update(['row_state' => $j['row_state'] ?? 'ACTIVE', 'f_active' => (int) ($j['f_active'] ?? 1), 'delete_group' => null]);
                foreach ($j['variations'] ?? [] as $t => $vids) if (in_array($t, self::VARIATION_TABLES, true) && $vids) DB::table($t)->whereIn('id', $vids)->update(['f_active' => 1]);
                DB::table('mo_recycle_bin')->where('id', $b->id)->update(['restored_at' => now()]);
                Audit::log('t_product_vendors', (int) $b->row_id, 'RESTORE', ['group' => $p->delete_group]); $n++;
            }
            return ['restored' => $n, 'message' => "{$p->SKU_VENDOR} restored with " . ($n - 1) . ' child SKU(s).'];
        });
    }

    /** The recycle bin of products: one row per delete batch. */
    public static function bin(int $ent): array
    {
        return DB::table('mo_recycle_bin as b')->leftJoin('t_users as u', 'u.id', '=', 'b.deleted_by')->where('b.table_name', 't_product_vendors')->whereNull('b.restored_at')->where(fn ($w) => $w->where('b.fk_id_ent', $ent)->orWhere('b.fk_id_ent', 0))
            ->groupBy('b.group_key', 'u.display_name')->orderByDesc(DB::raw('MAX(b.deleted_at)'))->limit(200)
            ->get(['b.group_key', DB::raw('MIN(b.row_id) as product'), DB::raw('COUNT(*) as rows_n'), DB::raw("GROUP_CONCAT(b.label ORDER BY b.row_id SEPARATOR ' | ') as labels"), DB::raw('MAX(b.deleted_at) as deleted_at'), 'u.display_name as by'])
            ->map(fn ($r) => ['product' => (int) explode('-', $r->group_key)[1]] + (array) $r)->all();
    }
}
