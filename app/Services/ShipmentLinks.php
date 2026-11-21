<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Shipment ↔ PO links (R-B_SOURCING-38). One PO can span several shipments (split) and one shipment can carry several POs (consolidation).
 * The grid "POs on this shipment" shows per PO: planned and actual on this shipment, ordered, on other shipments, remaining. One row per (shipment, PO) – saving an existing pair
 * updates it (and brings it back when removed); the units go onto the PO's lines (each line up to what it still has to ship). Σ actual over all shipments above the PO's quantity
 * needs a reason. The shipment's main PO is the first row. Totals feed the quantity check (R-39) and the GRN's expected units.
 */
class ShipmentLinks
{
    public const NOTE = 'One PO can span multiple shipments (split) and one shipment can carry multiple POs (consolidation).';

    private static function shipment(int $ent, int $id): object { return DB::table('t_src_shipments')->where('id', $id)->where('fk_id_ent', $ent)->first() ?? throw new \InvalidArgumentException('Shipment not found.'); }

    private static function ordered(int $poId): float { return (float) DB::table('t_src_po_lines')->where('fk_id_po', $poId)->where('f_active', 1)->where(fn ($q) => $q->whereNull('line_type')->orWhereIn('line_type', ['', 'PRODUCT']))->sum(DB::raw('COALESCE(NULLIF(qty_confirmed, 0), qty_ordered)')); }

    private static function onShipments(int $poId, ?int $except = null, string $col = 'actual_qty'): float
    {
        return (float) DB::table('t_src_shipment_po_link as k')->join('t_src_shipments as s', 's.id', '=', 'k.fk_id_shipment')->where('k.fk_id_po', $poId)->where('k.f_active', 1)->where('s.f_active', 1)->when($except, fn ($q) => $q->where('k.fk_id_shipment', '<>', $except))->sum("k.$col");
    }

    public static function page(int $ent, int $shipmentId): array
    {
        $s = self::shipment($ent, $shipmentId); $rows = [];
        $links = DB::table('t_src_shipment_po_link')->where('fk_id_shipment', $shipmentId)->where('f_active', 1)->orderBy('id')->get()->groupBy('fk_id_po');
        foreach ($links as $poId => $ls) {
            $po = DB::table('t_src_po')->where('id', $poId)->first(['id', 'po_number']); $ord = self::ordered((int) $poId); $other = self::onShipments((int) $poId, $shipmentId); $act = (float) $ls->sum('actual_qty');
            $rows[] = ['po' => (int) $poId, 'po_number' => $po->po_number ?? "#$poId", 'planned' => (float) $ls->sum('planned_qty'), 'actual' => $act, 'ordered' => $ord, 'other_shipments' => $other, 'remaining' => round($ord - $other - $act, 2),
                'reason' => $ls->pluck('over_reason')->filter()->first(), 'main' => (int) $poId === (int) $s->fk_id_po];
        }
        $pos = DB::table('t_src_po')->where('fk_id_ent', $ent)->where('f_active', 1)->whereIn('status', ['CONFIRMED', 'SHIPPED', 'RECEIVED'])->when($s->fk_id_cycle, fn ($q) => $q->where(fn ($w) => $w->where('fk_id_cycle', $s->fk_id_cycle)->orWhere('fk_id_supplier', $s->fk_id_supplier)))
            ->orderByDesc('id')->limit(300)->get(['id', 'po_number'])->map(fn ($p) => ['id' => (int) $p->id, 'po_number' => $p->po_number, 'ordered' => self::ordered((int) $p->id), 'shipped' => self::onShipments((int) $p->id)])->all();
        return ['shipment' => ['id' => (int) $s->id, 'code' => $s->shipment_code, 'main_po' => (int) $s->fk_id_po], 'rows' => $rows, 'pos' => $pos, 'note' => self::NOTE];
    }

    /** Save one (shipment, PO) pair: planned / actual (empty = 0). Above the PO's quantity over all shipments → a reason is needed. */
    public static function save(int $ent, int $shipmentId, array $d): array
    {
        $s = self::shipment($ent, $shipmentId); $poId = (int) ($d['po'] ?? 0); if (! $poId) throw new \InvalidArgumentException('Select a PO');      // old alert text
        $po = DB::table('t_src_po')->where('id', $poId)->where('fk_id_ent', $ent)->where('f_active', 1)->first() ?? throw new \InvalidArgumentException('Select a PO');
        $num = function ($k) use ($d) { $v = trim((string) ($d[$k] ?? '')); if ($v === '') return 0.0; if (! is_numeric($v) || (float) $v < 0) throw new \InvalidArgumentException(ucfirst($k) . ' quantity must be a number of 0 or more.'); return (float) $v; };
        $planned = $num('planned'); $actual = $num('actual');
        $ord = self::ordered($poId); $other = self::onShipments($poId, $shipmentId); $reason = trim((string) ($d['reason'] ?? ''));
        if ($other + $actual > $ord + 0.0001 && $reason === '') return ['need_reason' => true, 'message' => "PO {$po->po_number}: " . rtrim(rtrim(number_format($other + $actual, 2, '.', ''), '0'), '.') . ' would be on shipments, more than the ' . rtrim(rtrim(number_format($ord, 2, '.', ''), '0'), '.') . ' ordered. Give the reason.'];
        return DB::transaction(function () use ($ent, $s, $po, $planned, $actual, $reason, $shipmentId, $ord, $other) {
            // the units go onto the PO's lines: each up to what it still has to ship, the rest on the last line
            $lines = DB::table('t_src_po_lines')->where('fk_id_po', $po->id)->where('f_active', 1)->where(fn ($q) => $q->whereNull('line_type')->orWhereIn('line_type', ['', 'PRODUCT']))->orderBy('id')->get(); if ($lines->isEmpty()) throw new \InvalidArgumentException("PO {$po->po_number} has no product lines.");
            $leftP = $planned; $leftA = $actual; $n = $lines->count();
            foreach ($lines->values() as $i => $l) {
                $room = max(0.0, (float) ($l->qty_confirmed ?: $l->qty_ordered) - (float) DB::table('t_src_shipment_po_link as k')->join('t_src_shipments as s', 's.id', '=', 'k.fk_id_shipment')->where('k.fk_id_po_line', $l->id)->where('k.fk_id_shipment', '<>', $shipmentId)->where('k.f_active', 1)->where('s.f_active', 1)->sum('k.actual_qty'));
                $a = $i === $n - 1 ? $leftA : min($leftA, $room); $p = $i === $n - 1 ? $leftP : min($leftP, $room); $leftA -= $a; $leftP -= $p;
                DB::table('t_src_shipment_po_link')->updateOrInsert(['fk_id_shipment' => $shipmentId, 'fk_id_po_line' => $l->id], ['fk_id_po' => $po->id, 'fk_id_product' => $l->fk_id_product, 'planned_qty' => round($p, 2), 'actual_qty' => round($a, 2), 'f_active' => 1, 'over_reason' => $other + $actual > $ord + 0.0001 ? mb_substr($reason, 0, 255) : null, 'created_at' => now()]);
            }
            self::mainPo($shipmentId);
            DB::table('t_src_shipments')->where('id', $shipmentId)->update(['planned_qty' => DB::table('t_src_shipment_po_link')->where('fk_id_shipment', $shipmentId)->where('f_active', 1)->sum('planned_qty'), 'confirmed_qty' => DB::table('t_src_shipment_po_link')->where('fk_id_shipment', $shipmentId)->where('f_active', 1)->sum('actual_qty'), 'updated_at' => now()]);
            if (in_array($po->status, ['CONFIRMED'], true) && $actual > 0) DB::table('t_src_po')->where('id', $po->id)->update(['status' => 'SHIPPED']);
            Audit::log('t_src_shipments', $shipmentId, 'UPDATE', ['PO on this shipment' => $po->po_number, 'planned' => $planned, 'actual' => $actual] + ($reason !== '' && $other + $actual > $ord ? ['over the ordered quantity' => $reason] : []), $reason ?: null, $ent);
            CycleAlerts::touched();
            return ['message' => "PO {$po->po_number} saved on {$s->shipment_code}.", 'grid' => self::page($ent, $shipmentId)];
        });
    }

    /** Take a PO off the shipment (soft; saving the pair again brings it back). Refused once a GRN counted it. */
    public static function remove(int $ent, int $shipmentId, int $poId): array
    {
        $s = self::shipment($ent, $shipmentId);
        if (DB::table('t_src_grn')->where('fk_id_shipment', $shipmentId)->where('f_active', 1)->exists()) throw new \InvalidArgumentException('This shipment is already received (GRN): its POs cannot change.');
        DB::table('t_src_shipment_po_link')->where('fk_id_shipment', $shipmentId)->where('fk_id_po', $poId)->update(['f_active' => 0]); self::mainPo($shipmentId);
        Audit::log('t_src_shipments', $shipmentId, 'UPDATE', ['PO taken off' => (string) DB::table('t_src_po')->where('id', $poId)->value('po_number')], null, $ent);
        return ['message' => 'PO taken off the shipment.', 'grid' => self::page($ent, $shipmentId)];
    }

    /** The shipment's main PO = the first row. */
    private static function mainPo(int $shipmentId): void
    {
        $first = DB::table('t_src_shipment_po_link')->where('fk_id_shipment', $shipmentId)->where('f_active', 1)->orderBy('id')->value('fk_id_po');
        if ($first) DB::table('t_src_shipments')->where('id', $shipmentId)->update(['fk_id_po' => $first]);
    }
}
