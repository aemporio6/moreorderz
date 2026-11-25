<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-B_SOURCING-42 the products grid of the cycle Details: one row per cycle product (Product, Brand, Supplier, Name, SKU, Variation,
 * Target qty, Target unit cost, Specification). A row needs its product (brand, name and SKU come from it); delete is refused when the
 * product has PO lines of this cycle – the row is set inactive instead; Next opens Samples with this product and supplier.
 */
class CycleProducts
{
    private static function cycle(int $cycleId): object { return DB::table('t_src_cycles')->where('id', $cycleId)->where('fk_id_ent', Context::ent())->first() ?? throw new \InvalidArgumentException('Cycle not found.'); }

    public static function rows(int $cycleId): array
    {
        self::cycle($cycleId);
        return DB::table('t_src_cycle_products as cp')->leftJoin('t_product_vendors as p', 'p.id', '=', 'cp.fk_id_product')->leftJoin('cat_brands as b', 'b.id', '=', 'cp.fk_id_brand')->leftJoin('t_src_suppliers as s', 's.id', '=', 'cp.fk_id_supplier')
            ->where('cp.fk_id_cycle', $cycleId)->orderBy('cp.id')->get(['cp.id', 'cp.fk_id_product', 'cp.fk_id_brand', 'cp.fk_id_supplier', 'cp.product_name', 'cp.sku', 'cp.variation', 'cp.target_qty', 'cp.target_unit_cost', 'cp.product_spec', 'cp.f_active', 'p.SKU_VENDOR as product_sku', 'b.brand_name', 's.supplier_name'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'product' => $r->fk_id_product ? (int) $r->fk_id_product : null, 'product_label' => $r->product_sku, 'brand' => $r->fk_id_brand ? (int) $r->fk_id_brand : null, 'brand_name' => $r->brand_name, 'supplier' => $r->fk_id_supplier ? (int) $r->fk_id_supplier : null, 'supplier_name' => $r->supplier_name,
                'name' => $r->product_name, 'sku' => $r->sku, 'variation' => $r->variation, 'target_qty' => $r->target_qty !== null ? (float) $r->target_qty : null, 'target_unit_cost' => $r->target_unit_cost !== null ? (float) $r->target_unit_cost : null, 'spec' => $r->product_spec, 'active' => (bool) $r->f_active])->map(fn ($r) => ['docs' => "#/list/t_src_documents?f[fk_id_cycle]=$cycleId&f[fk_id_cycle_product]={$r['id']}", 'next' => "#/cycle-extras/$cycleId?step=samples&cp={$r['id']}" . ($r['supplier'] ? "&supplier={$r['supplier']}" : '') . '&add=1'] + $r)->all();
    }

    /** The cycle core form of the Details section: Sourcing Date, Requested By (read-only), Required Delivery Date, Priority, Status, Notes. */
    public static function details(int $cycleId): array
    {
        $c = self::cycle($cycleId); $opt = fn (string $f) => array_map(fn ($o) => ['id' => $o[0], 'name' => $o[1]], FormAssist::options('t_src_cycles', $f, null)['options']);
        return ['values' => ['sourcing_date' => $c->sourcing_date ? substr((string) $c->sourcing_date, 0, 10) : null, 'required_delivery_date' => $c->required_delivery_date ? substr((string) $c->required_delivery_date, 0, 10) : null, 'priority' => $c->priority, 'status' => $c->status, 'notes' => $c->notes],
            'requested_by' => $c->requested_by ? DB::table('t_users')->where('id', $c->requested_by)->value('display_name') : null, 'priorities' => $opt('priority'), 'statuses' => $opt('status')];
    }

    /** "Save Details" → "Cycle details saved" (through the record form, so its rules and history apply; Requested by is never changed). */
    public static function saveDetails(int $cycleId, array $d): array
    {
        self::cycle($cycleId);
        RecordService::save('t_src_cycles', array_intersect_key($d, array_flip(['sourcing_date', 'required_delivery_date', 'priority', 'status', 'notes'])), $cycleId);
        return ['message' => 'Cycle details saved'] + self::details($cycleId);
    }

    /** ✓ save of one row (insert / update). Brand, name and SKU are filled from the product; a row cannot be saved without the product. */
    public static function save(int $cycleId, array $d, ?int $rowId = null): array
    {
        self::cycle($cycleId); $ent = (int) Context::ent();
        if ($rowId && ! DB::table('t_src_cycle_products')->where('id', $rowId)->where('fk_id_cycle', $cycleId)->exists()) throw new \InvalidArgumentException('Row not found on this cycle.');
        $p = ! empty($d['product']) ? DB::table('t_product_vendors')->where('id', (int) $d['product'])->where('fk_id_ent', $ent)->first() : null;
        if (! $p) throw \Illuminate\Validation\ValidationException::withMessages(['product' => 'Pick the product (or + Add product) for this line.']);
        $name = trim((string) ($d['name'] ?? '')) ?: (string) $p->product_name_vendor;
        if ($name === '') throw \Illuminate\Validation\ValidationException::withMessages(['name' => 'Product Name is required']);
        $v = ['fk_id_cycle' => $cycleId, 'fk_id_product' => (int) $p->id, 'fk_id_brand' => ! empty($d['brand']) ? (int) $d['brand'] : ((int) ($p->fk_id_brand ?? 0) ?: null), 'fk_id_supplier' => ! empty($d['supplier']) ? (int) $d['supplier'] : null, 'product_name' => mb_substr($name, 0, 250),
            'sku' => (string) $p->SKU_VENDOR, 'variation' => $d['variation'] ?? null, 'target_qty' => $d['target_qty'] ?? null, 'target_unit_cost' => $d['target_unit_cost'] ?? null, 'product_spec' => $d['spec'] ?? null, 'f_active' => 1];
        $r = RecordService::save('cycle_product', $v, $rowId);
        return ['message' => 'Product saved', 'id' => (int) $r['id'], 'rows' => self::rows($cycleId)];
    }

    /** 🗑 delete of one row: refused when the product has PO lines of this cycle – the row is set inactive instead. */
    public static function delete(int $cycleId, int $rowId): array
    {
        self::cycle($cycleId);
        $row = DB::table('t_src_cycle_products')->where('id', $rowId)->where('fk_id_cycle', $cycleId)->first() ?? throw new \InvalidArgumentException('Row not found on this cycle.');
        $lines = $row->fk_id_product ? DB::table('t_src_po_lines as l')->join('t_src_po as p', 'p.id', '=', 'l.fk_id_po')->where('p.fk_id_cycle', $cycleId)->where('l.fk_id_product', $row->fk_id_product)->where('l.f_active', 1)->count() : 0;
        if ($lines) { DB::table('t_src_cycle_products')->where('id', $rowId)->update(['f_active' => 0, 'updated_at' => now(), 'updated_by' => Context::userId()]); Audit::log('t_src_cycle_products', $rowId, 'UPDATE', ['f_active' => [1, 0]], "has $lines PO line(s)", (int) Context::ent());
            return ['message' => "This product has $lines PO line(s) on the cycle – it cannot be deleted. It is set inactive instead.", 'inactive' => true, 'rows' => self::rows($cycleId)]; }
        DB::table('t_src_cycle_products')->where('id', $rowId)->delete(); Audit::log('t_src_cycle_products', $rowId, 'DELETE', ['product' => $row->product_name], null, (int) Context::ent());
        return ['message' => 'Product deleted', 'inactive' => false, 'rows' => self::rows($cycleId)];
    }
}
