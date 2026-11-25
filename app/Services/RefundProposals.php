<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Refund proposals from the money report (RULES_A_SALES R-A_SALES-86).
 * One charge row per order + SKU + refund id; each makes a proposal on the matching sale line with a kind (Full / Half / Partial / Outside / To classify);
 * a refund whose order + SKU has no sale line is kept as "without a matching line" until a person links or rejects it. Open proposals block Month Confirm.
 */
class RefundProposals
{
    public const LABELS = ['FULL' => 'Full', 'HALF' => 'Half', 'PARTIAL' => 'Partial', 'OUTSIDE' => 'Outside the order', 'TO_CLASSIFY' => 'To classify'];

    /** [from %, to %] of the Half band of a marketplace profile (default 49 – 51). */
    public static function halfBand(?int $profileId): array
    {
        $r = $profileId ? DB::table('mp_profile_refund_kinds')->where('fk_id_mp_profile', $profileId)->where('kind_code', 'HALF')->where('f_active', 1)->first() : null;
        if ($profileId && ! $r && DB::table('mp_profile_refund_kinds')->where('fk_id_mp_profile', $profileId)->exists()) return [null, null];      // the profile has no Half kind
        return [(float) ($r->pct_from ?? 49), (float) ($r->pct_to ?? 51)];
    }

    /** Full = refund = line total (±0.01); Half = inside the band; Partial = any other amount below the total; no total known → To classify. */
    public static function kind(?float $lineTotal, float $refund, ?int $profileId = null): string
    {
        $refund = abs($refund); if ($lineTotal === null || $lineTotal <= 0) return 'TO_CLASSIFY'; if ($refund >= $lineTotal - 0.01) return 'FULL';
        [$from, $to] = self::halfBand($profileId); $pct = round($refund / $lineTotal * 100, 4);
        return $from !== null && $pct >= $from && $pct <= $to ? 'HALF' : 'PARTIAL';
    }

    /** The total used for the kind: the line's own total; with no price yet, the money report's sale amount for the same order + SKU. */
    private static function totalFor(?object $sale, ?float $reportSale): ?float
    {
        if ($sale && (float) $sale->total_amount > 0) return round((float) $sale->total_amount, 2);
        return $reportSale !== null && $reportSale > 0 ? round($reportSale, 2) : null;
    }

    private static function findSale(int $ent, int $shopId, string $order, ?string $sku): ?object
    {
        $q = DB::table('t_sales')->where('fk_id_ent', $ent)->where('fk_id_branch', $shopId)->where('order_no', $order)->whereNotIn('sale_source', ['history', 'summary']);
        if ($sku !== null && $sku !== '') return (clone $q)->where('sku', $sku)->orderBy('id')->first();      // per order + SKU when the money file names the SKU; no such line = a refund without a matching line
        $rows = $q->orderBy('id')->limit(2)->get(); return $rows->count() >= 1 ? $rows[0] : null;      // the money file names only the order: its (first) line
    }

    /**
     * One refund row of a money report. $r: shop, order_no, sku (may be empty), refund_id, amount, batch, date, report_sale (the report's own sale amount, may be null), order_level (the amount is for the whole order).
     * Returns 'proposal' | 'unknown' | 'adjusted' | 'duplicate'. Never writes a price on the sale line.
     */
    public static function fromReport(int $ent, array $r): string
    {
        $shop = DB::table('t_branches')->where('id', (int) $r['shop'])->first(); $order = trim((string) $r['order_no']); $sku = trim((string) ($r['sku'] ?? '')); $rid = mb_substr((string) $r['refund_id'], 0, 80); $amount = round(abs((float) $r['amount']), 2); $date = $r['date'] ?? date('Y-m-d');
        if (DB::table('t_sale_refund_proposals')->where('refund_id', $rid)->where('fk_id_branch', $shop->id)->where('order_no', $order)->where(fn ($w) => $sku === '' ? $w->whereNull('sku')->orWhere('sku', '') : $w->where('sku', $sku))->exists()) return 'duplicate';      // the same report twice adds nothing
        $sale = self::findSale($ent, (int) $shop->id, $order, $sku ?: null);
        if ($sale && DB::table('t_sale_refund_proposals')->where('fk_id_sale', $sale->id)->where('refund_id', $rid)->exists()) return 'duplicate';
        if (! DB::table('t_sale_charges')->where('fk_id_branch', $shop->id)->where('order_no', $order)->where('sku', $sku)->where('refund_id', $rid)->where('kind', 'REFUND_SEEN')->exists())      // one charge row per order + SKU + refund id (information; the money itself is booked when the proposal is accepted)
            DB::table('t_sale_charges')->insert(['fk_id_ent' => $ent, 'fk_id_sale' => $sale->id ?? null, 'fk_id_branch' => $shop->id, 'order_no' => $order, 'sku' => $sku, 'fk_id_product' => $sale->fk_product_id ?? null, 'kind' => 'REFUND_SEEN', 'amount' => 0, 'amount_gbp' => 0, 'd_charge' => $date, 'f_estimated' => 1, 'fk_id_batch' => $r['batch'] ?? null, 'note' => mb_substr("Refund $amount seen in the money report – waits for a decision", 0, 255), 'created_at' => now(), 'refund_id' => $rid]);
        $base = ['fk_id_batch' => $r['batch'] ?? null, 'refund_id' => $rid, 'amount' => $amount, 'fk_id_branch' => (int) $shop->id, 'order_no' => $order, 'sku' => $sku ?: null, 'report_sale_amount' => $r['report_sale'] ?? null, 'd_refund' => $date, 'created_at' => now()];
        if (! $sale) { DB::table('t_sale_refund_proposals')->insert(RecordService::fillNotNull('t_sale_refund_proposals', $base + ['refund_kind' => 'TO_CLASSIFY', 'status' => 'UNKNOWN', 'f_sku_unknown' => 1]) + ['fk_id_sale' => null]); return 'unknown'; }
        $total = ! empty($r['order_level']) && (float) $sale->total_amount > 0 ? round((float) DB::table('t_sales')->where('fk_id_ent', $ent)->where('fk_id_branch', $shop->id)->where('order_no', $order)->whereNotIn('sale_source', ['history', 'summary'])->sum('total_amount'), 2) : self::totalFor($sale, isset($r['report_sale']) ? (float) $r['report_sale'] : null);
        $kind = self::kind($total, $amount, $shop->fk_id_mp_profile ? (int) $shop->fk_id_mp_profile : null);
        $pid = (int) DB::table('t_sale_refund_proposals')->insertGetId(RecordService::fillNotNull('t_sale_refund_proposals', $base + ['fk_id_sale' => $sale->id, 'refund_kind' => $kind, 'line_total' => $total, 'status' => 'PROPOSED', 'kind_source' => (float) $sale->total_amount > 0 ? 'LINE' : ($total ? 'REPORT' : null)]));
        /* R-A_SALES-86 / 34: a refund of a confirmed (locked) line is never a proposal on that line – it is an adjustment charge in the next open month (Choice REFUND_ON_CONFIRMED, per shop) */
        if (($sale->status === 'Confirmed' || (int) $sale->f_locked) && $kind !== 'TO_CLASSIFY' && Choices::get('REFUND_ON_CONFIRMED', $ent, (int) $shop->id) === 'ADJUST') {
            try { SettlementService::refund($ent, (int) $sale->id, $kind === 'FULL' ? null : $amount, 'Marketplace money file – ' . (self::LABELS[$kind] ?? $kind) . ' refund of a confirmed line', $pid);
                DB::table('t_sale_refund_proposals')->where('id', $pid)->update(['status' => 'ADJUSTED', 'decided_at' => now()]); return 'adjusted'; }
            catch (\Exception $e) { DB::table('t_sale_refund_proposals')->where('id', $pid)->update(['reject_reason' => mb_substr('Not adjusted: ' . $e->getMessage(), 0, 250)]); }      // e.g. already refunded in full: a person decides
        }
        return 'proposal';
    }

    /** A "To classify" proposal gets its kind as soon as its line has a price. */
    public static function classifyWaiting(int $ent): int
    {
        $n = 0;
        foreach (DB::table('t_sale_refund_proposals as p')->join('t_sales as s', 's.id', '=', 'p.fk_id_sale')->join('t_branches as b', 'b.id', '=', 's.fk_id_branch')->where('s.fk_id_ent', $ent)->where('p.status', 'PROPOSED')->where('p.refund_kind', 'TO_CLASSIFY')->where('s.total_amount', '>', 0)->get(['p.id', 'p.amount', 's.total_amount', 'b.fk_id_mp_profile']) as $p) {
            DB::table('t_sale_refund_proposals')->where('id', $p->id)->update(['refund_kind' => self::kind((float) $p->total_amount, (float) $p->amount, $p->fk_id_mp_profile ? (int) $p->fk_id_mp_profile : null), 'line_total' => $p->total_amount, 'kind_source' => 'LINE']); $n++; }
        return $n;
    }

    private static function nature(string $kind): string { return ['FULL' => 'Full refund', 'HALF' => 'Half refund', 'PARTIAL' => 'Part refund', 'OUTSIDE' => 'Refund paid outside the marketplace'][$kind] ?? ''; }

    /** Waiting proposals, refunds without a matching line, and the last decisions. */
    public static function page(?object $u): array
    {
        $ent = (int) Context::ent(); self::classifyWaiting($ent); $scope = Perm::shops($u); $lab = fn ($r) => $r->shop_no ? trim((string) $r->shop_no) . ' (' . trim((string) $r->shop_name) . ')' : '';
        $rows = DB::table('t_sale_refund_proposals as p')->join('t_sales as s', 's.id', '=', 'p.fk_id_sale')->leftJoin('t_branches as b', 'b.id', '=', 's.fk_id_branch')->where('s.fk_id_ent', $ent)->where('p.status', 'PROPOSED')->when($scope !== null, fn ($q) => $q->whereIn('s.fk_id_branch', $scope))->orderBy('p.id')
            ->get(['p.*', 's.order_no as s_order', 's.sku as s_sku', 's.qty', 's.total_amount', 's.total_amount_gbp', 's.status as sale_status', 's.d_order', 's.f_locked', 'b.shop_no', 'b.shop_name'])
            ->map(function ($r) use ($lab) { $sold = $r->line_total !== null ? (float) $r->line_total : ((float) $r->total_amount > 0 ? (float) $r->total_amount : null); $k = $r->refund_kind;
                return ['id' => (int) $r->id, 'order_no' => $r->s_order, 'sku' => $r->s_sku, 'shop' => $lab($r), 'd_order' => $r->d_order ? date('d-m-Y', strtotime((string) $r->d_order)) : '', 'qty' => (int) $r->qty, 'total_amount_gbp' => (float) $r->total_amount_gbp, 'sale_status' => $r->sale_status, 'refund_kind' => $k, 'kind_label' => self::LABELS[$k] ?? $k, 'amount' => (float) $r->amount, 'sold' => $sold,
                    'pct' => $sold ? round((float) $r->amount / $sold * 100, 1) : null, 'text' => $sold !== null ? 'Sold £' . number_format($sold, 2) . ' → Refund £' . number_format((float) $r->amount, 2) . ' (' . (self::LABELS[$k] ?? $k) . ')' : 'Sold – no price yet → Refund £' . number_format((float) $r->amount, 2) . ' (To classify)',
                    'kind_from' => $r->kind_source === 'REPORT' ? "the money report's sale amount (the line has no price yet)" : null, 'can_accept' => $k !== 'TO_CLASSIFY', 'accept_why' => $k === 'TO_CLASSIFY' ? 'The line has no price yet: the kind is set when it gets one.' : null, 'nature' => self::nature($k)]; })->all();
        $unknown = DB::table('t_sale_refund_proposals as p')->leftJoin('t_branches as b', 'b.id', '=', 'p.fk_id_branch')->where('b.fk_id_ent', $ent)->where('p.status', 'UNKNOWN')->when($scope !== null, fn ($q) => $q->whereIn('p.fk_id_branch', $scope))->orderBy('p.id')->get(['p.*', 'b.shop_no', 'b.shop_name'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'shop' => $lab($r), 'shop_id' => (int) $r->fk_id_branch, 'order_no' => (string) $r->order_no, 'sku' => (string) $r->sku, 'amount' => (float) $r->amount, 'date' => $r->d_refund ? date('d-m-Y', strtotime((string) $r->d_refund)) : '', 'report_sale' => $r->report_sale_amount !== null ? (float) $r->report_sale_amount : null])->all();
        $decided = DB::table('t_sale_refund_proposals as p')->leftJoin('t_sales as s', 's.id', '=', 'p.fk_id_sale')->leftJoin('t_branches as b', 'b.id', '=', 'p.fk_id_branch')->leftJoin('t_users as u', 'u.id', '=', 'p.decided_by')->where(fn ($w) => $w->where('s.fk_id_ent', $ent)->orWhere('b.fk_id_ent', $ent))->whereIn('p.status', ['ACCEPTED', 'REJECTED'])
            ->when($scope !== null, fn ($q) => $q->where(fn ($w) => $w->whereIn('s.fk_id_branch', $scope)->orWhereIn('p.fk_id_branch', $scope)))->orderByDesc('p.decided_at')->limit(30)->get(['p.id', 'p.status', 'p.reject_reason', 'p.decided_at', 'p.amount', 'p.refund_kind', DB::raw('COALESCE(s.order_no, p.order_no) as order_no'), DB::raw('COALESCE(s.sku, p.sku) as sku'), 's.total_amount_gbp', 'u.display_name as by']);
        return ['rows' => $rows, 'unknown' => $unknown, 'decided' => $decided, 'kinds' => self::LABELS];
    }

    private static function open(int $id, string $status, ?object $u): object
    {
        $p = DB::table('t_sale_refund_proposals')->where('id', $id)->lockForUpdate()->first() ?? throw new \InvalidArgumentException('Refund proposal not found.'); $shop = (int) ($p->fk_id_branch ?: DB::table('t_sales')->where('id', $p->fk_id_sale)->value('fk_id_branch'));
        if (! DB::table('t_branches')->where('id', $shop)->where('fk_id_ent', Context::ent())->exists() || ! Perm::shopAllowed($u, $shop)) abort(403, "You don't have rights for this shop."); if ($p->status !== $status) throw new \InvalidArgumentException('This refund was already decided.'); return $p;
    }

    /** Sale lines a refund without a matching line can be linked to: the same shop, the order number first, then lines near its date. */
    public static function candidates(int $id, string $q, ?object $u): array
    {
        Perm::need($u, 'money.refunds_may'); $p = DB::table('t_sale_refund_proposals')->where('id', $id)->where('status', 'UNKNOWN')->first() ?? throw new \InvalidArgumentException('Refund not found.'); if (! Perm::shopAllowed($u, (int) $p->fk_id_branch)) abort(403, "You don't have rights for this shop.");
        $s = DB::table('t_sales')->where('fk_id_ent', Context::ent())->where('fk_id_branch', $p->fk_id_branch)->whereNotIn('sale_source', ['history', 'summary']); $kw = trim($q) !== '' ? trim($q) : (string) $p->order_no;
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $kw) . '%'; $s->where(fn ($w) => $w->where('order_no', 'like', $like)->orWhere('sku', 'like', $like)->orWhere('cust_name', 'like', $like));
        return ['rows' => $s->orderByDesc('d_order')->limit(30)->get(['id', 'order_no', 'sku', 'd_order', 'cust_name', 'total_amount', 'status'])->map(fn ($x) => ['id' => (int) $x->id, 'label' => $x->order_no . ' · ' . $x->sku . ' · ' . date('d-m-Y', strtotime((string) $x->d_order)) . ' · ' . $x->cust_name . ' · £' . number_format((float) $x->total_amount, 2) . ' · ' . $x->status])->all()];
    }

    /** The person picks the line (or corrects the SKU): the proposal is created on that line. */
    public static function link(int $id, ?int $saleId, ?string $sku, ?object $u): array
    {
        Perm::need($u, 'money.refunds_may');
        $r = DB::transaction(function () use ($id, $saleId, $sku, $u) {
            $p = self::open($id, 'UNKNOWN', $u); $ent = (int) Context::ent(); $sku = trim((string) $sku);
            $sale = $saleId ? DB::table('t_sales')->where('id', $saleId)->where('fk_id_ent', $ent)->where('fk_id_branch', $p->fk_id_branch)->first() : ($sku !== '' ? self::findSale($ent, (int) $p->fk_id_branch, (string) $p->order_no, $sku) : null);
            if (! $sale) throw new \InvalidArgumentException($saleId ? 'This sale line is not of the same shop.' : ($sku !== '' ? "Order {$p->order_no} has no line with SKU $sku in this shop." : 'Pick the sale line or type the right SKU.'));
            if (DB::table('t_sale_refund_proposals')->where('fk_id_sale', $sale->id)->where('refund_id', $p->refund_id)->where('id', '<>', $p->id)->exists()) throw new \InvalidArgumentException('This refund is already on that sale line.');
            $profile = (int) DB::table('t_branches')->where('id', $p->fk_id_branch)->value('fk_id_mp_profile'); $total = self::totalFor($sale, $p->report_sale_amount !== null ? (float) $p->report_sale_amount : null);
            DB::table('t_sale_refund_proposals')->where('id', $p->id)->update(['fk_id_sale' => $sale->id, 'status' => 'PROPOSED', 'refund_kind' => self::kind($total, (float) $p->amount, $profile ?: null), 'line_total' => $total, 'kind_source' => (float) $sale->total_amount > 0 ? 'LINE' : ($total ? 'REPORT' : null)]);
            DB::table('t_sale_charges')->where('fk_id_branch', $p->fk_id_branch)->where('order_no', $p->order_no)->where('refund_id', $p->refund_id)->where('kind', 'REFUND_SEEN')->update(['fk_id_sale' => $sale->id, 'fk_id_product' => $sale->fk_product_id]);
            Audit::log('t_sale_refund_proposals', (int) $p->id, 'UPDATE', ['linked to sale' => $sale->id, 'order' => $sale->order_no, 'sku' => $sale->sku]);
            return ['message' => "Refund linked to order {$sale->order_no} · {$sale->sku}. It now waits for Accept or Reject."];
        });
        SalesWatch::tasks((int) Context::ent()); return $r;
    }

    /** Reject a refund without a matching line (reason needed). */
    public static function rejectUnknown(int $id, ?string $reason, ?object $u): array
    {
        Perm::need($u, 'money.refunds_may'); if (trim((string) $reason) === '') throw new \InvalidArgumentException('Give the reason for rejecting.');
        DB::transaction(function () use ($id, $reason, $u) { $p = self::open($id, 'UNKNOWN', $u); DB::table('t_sale_refund_proposals')->where('id', $p->id)->update(['status' => 'REJECTED', 'reject_reason' => trim($reason), 'decided_by' => $u->id, 'decided_at' => now()]); Audit::log('t_sale_refund_proposals', (int) $p->id, 'UPDATE', ['status' => 'REJECTED', 'reason' => trim($reason)]); });
        SalesWatch::tasks((int) Context::ent());
        return ['message' => 'Refund rejected.'];
    }

    /** Mark a waiting proposal as paid outside the marketplace (or back to its worked-out kind). */
    public static function markOutside(int $id, bool $outside, ?object $u): array
    {
        Perm::need($u, 'money.refunds_may');
        return DB::transaction(function () use ($id, $outside, $u) { $p = self::open($id, 'PROPOSED', $u); $s = DB::table('t_sales')->where('id', $p->fk_id_sale)->first(); $profile = (int) DB::table('t_branches')->where('id', $s->fk_id_branch)->value('fk_id_mp_profile');
            $kind = $outside ? 'OUTSIDE' : self::kind(self::totalFor($s, $p->report_sale_amount !== null ? (float) $p->report_sale_amount : null), (float) $p->amount, $profile ?: null);
            DB::table('t_sale_refund_proposals')->where('id', $p->id)->update(['refund_kind' => $kind, 'kind_source' => $outside ? 'USER' : 'LINE']); Audit::log('t_sale_refund_proposals', (int) $p->id, 'UPDATE', ['refund_kind' => [$p->refund_kind, $kind]]);
            return ['message' => 'Kind set to ' . self::LABELS[$kind] . '.', 'kind' => $kind]; });
    }

    /** Month Confirm is refused while a refund of that shop and month waits for a decision or for its line. */
    public static function confirmBlock(int $branchId, string $month): ?string
    {
        $open = DB::table('t_sale_refund_proposals as p')->join('t_sales as s', 's.id', '=', 'p.fk_id_sale')->where('s.fk_id_branch', $branchId)->whereRaw("DATE_FORMAT(s.d_order,'%Y-%m') = ?", [$month])->where('p.status', 'PROPOSED')->count();
        $unknown = DB::table('t_sale_refund_proposals')->where('fk_id_branch', $branchId)->where('status', 'UNKNOWN')->whereRaw("DATE_FORMAT(d_refund,'%Y-%m') = ?", [$month])->count();
        if (! $open && ! $unknown) return null;
        return trim(($open ? "$open refund proposal(s) of this month wait for Accept or Reject. " : '') . ($unknown ? "$unknown refund(s) have no matching sale line: link or reject them. " : '')) . ' Open Money › Refunds, then confirm.';
    }
}
