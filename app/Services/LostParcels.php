<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Not handed over, lost parcels and carrier claims (R-H-36, decision W-9).
 * - Ready past the carrier cut-off → WH_NOT_HANDED_OVER (TrackingPush::sweep).
 * - Handed over, no delivery after the carrier's claim_days (or a buyer "not received" case) → "Open lost-parcel case": claim_status
 *   LOST_INVESTIGATING → CLAIMED (claim ref, amount, date) → PAID / REJECTED; task WH_PARCEL_LOST (closed when the case ends or the parcel is delivered).
 * - Stock: a lost parcel stays shipped (the units left us); the sale line follows refunds. A PAID claim is booked as other income on the shop
 *   (Dr 1150 other receivables, Cr 4100 other income, shop on the line).
 * - Resend: a new parcel on the same order (reason RESEND, resend_of = the lost one) that reserves and picks again.
 */
class LostParcels
{
    public const OPEN = ['LOST_INVESTIGATING', 'CLAIMED'];

    private static function parcel(int $ent, int $id): object
    {
        return DB::table('wh_parcels')->where('id', $id)->where('fk_id_ent', $ent)->first() ?? throw new \InvalidArgumentException('Parcel not found.');
    }

    /** Nightly: handed-over parcels past their carrier's claim_days and not delivered get a WH_PARCEL_LOST task (the sweep only reminds – the case is opened by a person). */
    public static function sweep(int $ent, ?string $today = null): int
    {
        $today ??= date('Y-m-d'); $rows = [];
        foreach (DB::table('wh_parcels as p')->join('t_carriers as c', 'c.id', '=', 'p.fk_id_carrier')->where('p.fk_id_ent', $ent)->where('p.status', 'HANDED_OVER')->whereNotNull('p.handed_over_at')->where('c.claim_days', '>', 0)
            ->where(fn ($w) => $w->whereNull('p.claim_status')->orWhereIn('p.claim_status', self::OPEN))->get(['p.id', 'p.parcel_code', 'p.order_no', 'p.handed_over_at', 'p.tracking_no', 'c.claim_days', 'c.carrier_name']) as $p) {
            if (date('Y-m-d', strtotime($p->handed_over_at . ' +' . (int) $p->claim_days . ' days')) > $today) continue;
            $rows[] = ['key' => [(int) $p->id], 'title' => "Parcel {$p->parcel_code} (order {$p->order_no}) not delivered " . (int) $p->claim_days . " days after handover to {$p->carrier_name} – open a lost-parcel case",
                'opt' => ['ent' => $ent, 'module' => 'wh_parcels', 'record_id' => (int) $p->id, 'fix_url' => '#/lost-parcels']];
        }
        $r = TaskEngine::sync('WH_PARCEL_LOST', $rows, null, $ent);
        return count($rows);
    }

    /** Open a lost-parcel case (a person: from the sweep task or a buyer "not received"). */
    public static function open(int $ent, int $id, string $why = 'Not delivered'): array
    {
        $p = self::parcel($ent, $id);
        if (! in_array($p->status, ['HANDED_OVER', 'SHIPPED'], true)) throw new \InvalidArgumentException('Only a handed-over parcel can be reported lost.');
        if (in_array($p->claim_status, self::OPEN, true)) throw new \InvalidArgumentException('A lost-parcel case is already open for this parcel.');
        DB::table('wh_parcels')->where('id', $id)->update(['claim_status' => 'LOST_INVESTIGATING']);
        Audit::log('wh_parcels', $id, 'UPDATE', ['claim_status' => [$p->claim_status, 'LOST_INVESTIGATING']], $why, $ent);
        TaskEngine::open('WH_PARCEL_LOST', [$id], "Lost parcel {$p->parcel_code} (order {$p->order_no}) – claim it from the carrier", ['ent' => $ent, 'module' => 'wh_parcels', 'record_id' => $id, 'fix_url' => '#/lost-parcels']);
        return ['message' => "Lost-parcel case opened for {$p->parcel_code}."];
    }

    public static function claim(int $ent, int $id, string $ref, float $amount, ?string $date = null): array
    {
        $p = self::parcel($ent, $id);
        if ($p->claim_status !== 'LOST_INVESTIGATING') throw new \InvalidArgumentException('Open the lost-parcel case first.');
        if (trim($ref) === '' || $amount <= 0) throw new \InvalidArgumentException('Give the claim reference and an amount above zero.');
        DB::table('wh_parcels')->where('id', $id)->update(['claim_status' => 'CLAIMED', 'claim_ref' => mb_substr(trim($ref), 0, 60), 'claim_amount' => round($amount, 2)]);
        Audit::log('wh_parcels', $id, 'UPDATE', ['claim_status' => ['LOST_INVESTIGATING', 'CLAIMED'], 'claim_ref' => $ref, 'claim_amount' => round($amount, 2), 'claim_date' => $date ?: date('Y-m-d')], null, $ent);
        return ['message' => "Claim {$ref} of " . number_format($amount, 2) . ' recorded.'];
    }

    /** The carrier's answer: PAID (booked as income on the shop) or REJECTED (reason). The task closes. */
    public static function settle(int $ent, int $id, string $result, ?string $reason = null, ?string $date = null): array
    {
        $p = self::parcel($ent, $id); $result = strtoupper($result);
        if ($p->claim_status !== 'CLAIMED') throw new \InvalidArgumentException('Record the claim first.');
        if (! in_array($result, ['PAID', 'REJECTED'], true)) throw new \InvalidArgumentException('The claim is PAID or REJECTED.');
        if ($result === 'REJECTED' && ! trim((string) $reason)) throw new \InvalidArgumentException('Give the reason the carrier rejected the claim.');
        return DB::transaction(function () use ($p, $ent, $id, $result, $reason, $date) {
            $j = null;
            if ($result === 'PAID') $j = Ledger::post($ent, $date ?: date('Y-m-d'), 'PARCEL_CLAIM', $id, [
                ['account' => '1150', 'debit' => (float) $p->claim_amount, 'memo' => "Carrier claim {$p->claim_ref}"],
                ['account' => '4100', 'credit' => (float) $p->claim_amount, 'ref_type' => 'SHOP', 'ref_id' => (int) $p->fk_id_branch, 'memo' => "Lost parcel {$p->parcel_code} · claim {$p->claim_ref}"],
            ], ['memo' => "Carrier claim paid: parcel {$p->parcel_code}", 'allow_no_ref' => true]);
            DB::table('wh_parcels')->where('id', $id)->update(['claim_status' => $result]);
            Audit::log('wh_parcels', $id, 'UPDATE', ['claim_status' => ['CLAIMED', $result], 'journal' => $j], $reason, $ent);
            TaskEngine::close('WH_PARCEL_LOST', [$id], $result === 'PAID' ? 'Claim paid' : 'Claim rejected');
            return ['journal' => $j, 'message' => $result === 'PAID' ? 'Claim paid – ' . number_format((float) $p->claim_amount, 2) . ' booked as income on the shop.' : 'Claim rejected.'];
        });
    }

    /** Resend: a new parcel on the same order, reserving and picking again. The lost parcel stays shipped. */
    public static function resend(int $ent, int $id): array
    {
        $p = self::parcel($ent, $id);
        if (! in_array($p->claim_status, array_merge(self::OPEN, ['PAID', 'REJECTED']), true)) throw new \InvalidArgumentException('Open the lost-parcel case first.');
        if (DB::table('wh_parcels')->where('resend_of', $id)->whereNotIn('status', ['VOID', 'CANCELLED'])->exists() || DB::table('wh_reservations')->whereIn('fk_id_sale', DB::table('wh_parcel_lines')->where('fk_id_parcel', $id)->pluck('fk_id_sale'))->where('reason', 'RESEND')->whereNotIn('status', ['RELEASED', 'SHIPPED'])->exists())
            throw new \InvalidArgumentException('A replacement is already on its way for this parcel.');
        $n = 0;
        foreach (DB::table('wh_parcel_lines')->where('fk_id_parcel', $id)->get(['fk_id_sale', 'fk_id_product', 'qty']) as $l) {
            $s = DB::table('t_sales')->where('id', $l->fk_id_sale)->first(); if (! $s) continue;
            $avail = StockService::available((int) $l->fk_id_product); $q = (int) $l->qty;
            DB::table('wh_reservations')->insert(RecordService::fillNotNull('wh_reservations', ['fk_id_ent' => $ent, 'fk_id_sale' => $s->id, 'fk_id_product' => $l->fk_id_product, 'fk_id_branch' => $s->fk_id_branch, 'fk_id_site' => (int) ($s->fk_id_sell_location ?: 0), 'qty' => min($q, max(0, $avail)), 'qty_short' => max(0, $q - max(0, $avail)),
                'qty_picked' => 0, 'status' => 'OPEN', 'reason' => 'RESEND', 'resend_no' => (int) DB::table('wh_reservations')->where('fk_id_sale', $s->id)->where('fk_id_product', $l->fk_id_product)->max('resend_no') + 1, 'release_reason' => 'Replacement of lost parcel ' . $p->parcel_code, 'created_by' => Context::userId(), 'created_at' => now()]));
            DB::table('t_sales')->where('id', $s->id)->update(['wh_status' => 'RESERVED']); $n++;
        }
        Audit::log('wh_parcels', $id, 'UPDATE', ['resend' => $n], 'RESEND', $ent);
        return ['lines' => $n, 'message' => "Replacement reserved ($n line(s)) – pick and pack it as a new parcel."];
    }

    public static function page(int $ent): array
    {
        return ['rows' => DB::table('wh_parcels as p')->leftJoin('t_carriers as c', 'c.id', '=', 'p.fk_id_carrier')->leftJoin('t_branches as b', 'b.id', '=', 'p.fk_id_branch')->where('p.fk_id_ent', $ent)
            ->where(fn ($w) => $w->whereNotNull('p.claim_status')->orWhereIn('p.id', DB::table('tasks')->where('rule_code', 'WH_PARCEL_LOST')->whereIn('status', ['TODO', 'DOING', 'STUCK'])->select('linked_record_id')))
            ->orderByDesc('p.id')->limit(300)->get(['p.id', 'p.parcel_code', 'p.order_no', 'p.tracking_no', 'p.handed_over_at', 'p.status', 'p.claim_status', 'p.claim_ref', 'p.claim_amount', 'c.carrier_name', 'c.claim_days', 'b.shop_name'])->map(fn ($r) => (array) $r)->all()];
    }
}
