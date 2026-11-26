<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-F_SETUP-47 allocations drive the stock plan. An allocation row (cycle product → shop / location: planned, confirmed, dispatched, received) feeds:
 * (1) incoming stock per location = Σ (planned − received) of open allocations of shipped POs to that location, dated by the shipment ETA –
 *     used instead of the PO total when the PO has allocations (incoming());
 * (2) transfers: when the GRN posts at the receiving warehouse, each allocation to another location proposes a transfer for its accepted quantity –
 *     the user confirms, nothing moves by itself (propose() / confirm() / dismiss()). Allocations never write the ledger.
 */
class AllocationPlan
{
    private const SHIPPED = ['SHIPPED', 'IN_TRANSIT', 'DEPARTED', 'AT_PORT', 'CUSTOMS'];

    /** Incoming units: [location, product, sku, qty, eta, po, basis ALLOCATION | PO_TOTAL]. */
    public static function incoming(int $ent, ?int $location = null, ?int $product = null): array
    {
        $ships = DB::table('t_src_shipments')->where('fk_id_ent', $ent)->where('f_active', 1)->whereNull('actual_arrival')->where(fn ($w) => $w->whereNotNull('actual_departure')->orWhereIn('status', self::SHIPPED))->orderBy('eta')->get();
        $out = []; $seenPo = [];
        foreach ($ships as $s) {
            $po = (int) $s->fk_id_po; if (! $po || isset($seenPo[$po])) continue; $seenPo[$po] = 1;
            $allocs = DB::table('t_src_allocations as a')->leftJoin('t_src_cycle_products as cp', 'cp.id', '=', 'a.fk_id_cycle_product')->where('a.fk_id_ent', $ent)->where('a.f_active', 1)
                ->where(fn ($w) => $w->where('a.fk_id_po', $po)->orWhere(fn ($x) => $x->where(fn ($y) => $y->whereNull('a.fk_id_po')->orWhere('a.fk_id_po', 0))->where('a.fk_id_cycle', $s->fk_id_cycle)))->get(['a.*', 'cp.fk_id_product']);
            if ($allocs->isNotEmpty()) {
                foreach ($allocs as $a) { $q = (int) $a->allocated_qty - (int) $a->received_qty; if ($q <= 0 || ! $a->fk_id_warehouse) continue;
                    $out[] = ['location' => (int) $a->fk_id_warehouse, 'product' => (int) $a->fk_id_product, 'sku' => $a->sku, 'qty' => $q, 'eta' => $s->eta, 'po' => $po, 'shipment' => (int) $s->id, 'basis' => 'ALLOCATION']; }
                continue;
            }
            foreach (DB::table('t_src_po_lines')->where('fk_id_po', $po)->where('f_active', 1)->where(fn ($q) => $q->whereNull('line_type')->orWhereIn('line_type', ['', 'PRODUCT']))->get() as $l) {
                $q = (int) ($l->qty_confirmed ?: $l->qty_ordered); if ($q <= 0 || ! $s->fk_id_warehouse) continue;
                $out[] = ['location' => (int) $s->fk_id_warehouse, 'product' => (int) $l->fk_id_product, 'sku' => $l->sku, 'qty' => $q, 'eta' => $s->eta, 'po' => $po, 'shipment' => (int) $s->id, 'basis' => 'PO_TOTAL'];
            }
        }
        return array_values(array_filter($out, fn ($r) => (! $location || $r['location'] === $location) && (! $product || $r['product'] === $product)));
    }

    /** After a GRN is posted: one proposed transfer per allocation to another location. Stock does not move. */
    public static function propose(int $grnId): int
    {
        $g = DB::table('t_src_grn')->where('id', $grnId)->first(); if (! $g || $g->posting_status !== 'POSTED') return 0; $ent = (int) $g->fk_id_ent;
        $po = (int) DB::table('t_src_shipments')->where('id', $g->fk_id_shipment)->value('fk_id_po'); $n = 0;
        $got = []; foreach (DB::table('t_src_grn_lines')->where('fk_id_grn', $grnId)->get() as $l) { $k = (int) $l->fk_id_product; $got[$k] = ['qty' => ($got[$k]['qty'] ?? 0) + max(0, (int) $l->received_qty - (int) $l->damaged_qty), 'loc' => (int) ($l->fk_id_location ?: $g->fk_id_warehouse)]; }
        $allocs = DB::table('t_src_allocations as a')->leftJoin('t_src_cycle_products as cp', 'cp.id', '=', 'a.fk_id_cycle_product')->where('a.fk_id_ent', $ent)->where('a.f_active', 1)->whereNotNull('a.fk_id_warehouse')
            ->where(fn ($w) => $w->where('a.fk_id_po', $po)->orWhere(fn ($x) => $x->where(fn ($y) => $y->whereNull('a.fk_id_po')->orWhere('a.fk_id_po', 0))->where('a.fk_id_cycle', $g->fk_id_cycle)))->orderBy('a.id')->get(['a.*', 'cp.fk_id_product']);
        foreach ($allocs as $a) {
            $pid = (int) $a->fk_id_product; if (! isset($got[$pid])) continue; $from = $got[$pid]['loc']; $to = (int) $a->fk_id_warehouse;
            if ($to === $from || $to === (int) $g->fk_id_warehouse) continue;      /* stays where it was received */
            $qty = min((int) $a->allocated_qty - (int) $a->dispatched_qty, $got[$pid]['qty']); if ($qty <= 0) continue; $got[$pid]['qty'] -= $qty;
            if (DB::table('src_transfer_proposals')->where('fk_id_grn', $grnId)->where('fk_id_allocation', $a->id)->exists()) continue;
            $id = (int) DB::table('src_transfer_proposals')->insertGetId(['fk_id_ent' => $ent, 'fk_id_grn' => $grnId, 'fk_id_allocation' => $a->id, 'fk_id_product' => $pid, 'qty' => $qty, 'fk_id_from' => $from, 'fk_id_to' => $to, 'status' => 'PROPOSED', 'created_at' => now()]);
            TaskEngine::open('SRC_ALLOC_TRANSFER', [$id], "Transfer $qty × {$a->sku} to " . DB::table('cat_inventory_locations')->where('id', $to)->value('location_name') . " (allocation {$a->alloc_code}) – confirm or dismiss", ['ent' => $ent, 'module' => 't_src_allocations', 'record_id' => (int) $a->id, 'fix_url' => '#/allocation-plan']);
            $n++;
        }
        return $n;
    }

    public static function page(int $ent, object $u): array
    {
        Perm::need($u, 'buying.SEE'); $loc = DB::table('cat_inventory_locations')->where('fk_id_ent', $ent)->pluck('location_name', 'id');
        $props = DB::table('src_transfer_proposals as t')->join('t_product_vendors as p', 'p.id', '=', 't.fk_id_product')->leftJoin('t_src_grn as g', 'g.id', '=', 't.fk_id_grn')->leftJoin('t_src_allocations as a', 'a.id', '=', 't.fk_id_allocation')->where('t.fk_id_ent', $ent)
            ->orderByRaw("t.status = 'PROPOSED' DESC")->orderByDesc('t.id')->limit(300)->get(['t.*', 'p.SKU_VENDOR', 'g.grn_number', 'a.alloc_code']);
        return ['incoming' => array_map(fn ($r) => $r + ['location_name' => $loc[$r['location']] ?? ''], self::incoming($ent)),
            'proposals' => $props->map(fn ($t) => ['id' => (int) $t->id, 'sku' => $t->SKU_VENDOR, 'qty' => (int) $t->qty, 'from' => $loc[$t->fk_id_from] ?? '', 'to' => $loc[$t->fk_id_to] ?? '', 'grn' => $t->grn_number, 'allocation' => $t->alloc_code, 'status' => $t->status, 'note' => $t->note])->all(),
            'can_confirm' => Perm::has($u, 'stock.CHANGE')];
    }

    /** The user confirms: only now the stock moves (one stock writer – StockService). */
    public static function confirm(int $id, object $u): array
    {
        Perm::need($u, 'stock.CHANGE'); $ent = (int) Context::ent();
        return DB::transaction(function () use ($id, $u, $ent) {
            $t = DB::table('src_transfer_proposals')->where('id', $id)->where('fk_id_ent', $ent)->lockForUpdate()->first() ?? throw new \InvalidArgumentException('Proposal not found.');
            if ($t->status !== 'PROPOSED') throw new \InvalidArgumentException('This proposal is already ' . strtolower($t->status) . '.');
            $a = DB::table('t_src_allocations')->where('id', $t->fk_id_allocation)->first();
            StockService::transfer($ent, (int) $t->fk_id_product, (int) $t->qty, (int) $t->fk_id_from, (int) $t->fk_id_to, 'Allocation ' . ($a->alloc_code ?? $t->fk_id_allocation));
            DB::table('src_transfer_proposals')->where('id', $id)->update(['status' => 'CONFIRMED', 'done_by' => $u->id, 'done_at' => now()]);
            if ($a) DB::table('t_src_allocations')->where('id', $a->id)->update(['dispatched_qty' => (int) $a->dispatched_qty + (int) $t->qty, 'updated_at' => now()]);
            TaskEngine::close('SRC_ALLOC_TRANSFER', [$id], 'Transfer confirmed'); Audit::log('src_transfer_proposals', $id, 'APPROVE', ['status' => ['PROPOSED', 'CONFIRMED'], 'qty' => [null, (int) $t->qty]], null, $ent);
            return ['message' => (int) $t->qty . ' unit(s) moved.'];
        });
    }

    public static function dismiss(int $id, string $reason, object $u): array
    {
        Perm::need($u, 'stock.CHANGE'); $ent = (int) Context::ent(); if (trim($reason) === '') throw new \InvalidArgumentException('Give the reason.');
        $t = DB::table('src_transfer_proposals')->where('id', $id)->where('fk_id_ent', $ent)->first() ?? throw new \InvalidArgumentException('Proposal not found.');
        if ($t->status !== 'PROPOSED') throw new \InvalidArgumentException('This proposal is already ' . strtolower($t->status) . '.');
        DB::table('src_transfer_proposals')->where('id', $id)->update(['status' => 'DISMISSED', 'note' => mb_substr(trim($reason), 0, 255), 'done_by' => $u->id, 'done_at' => now()]);
        TaskEngine::close('SRC_ALLOC_TRANSFER', [$id], 'Dismissed'); Audit::log('src_transfer_proposals', $id, 'REJECT', ['status' => ['PROPOSED', 'DISMISSED']], $reason, $ent);
        return ['message' => 'Proposal dismissed – no stock moved.'];
    }
}
