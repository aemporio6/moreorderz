<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Ready to dispatch and tracking back to the marketplace (R-H-33, decision W-6). A parcel is Ready when packed, labelled (or untracked) and not voided; its units stay in DISPATCH.
 * Tracking goes back by the shop's marketplace profile: through its API when it has one, else through the profile's tracking-upload CSV (18_Labels §8) – never API-only:
 * while the API is not connected the parcel stays in the CSV. tracking_upload_when per profile: ON_LABEL (at label time) or ON_HANDOVER (default – the buyer is told "shipped"
 * only when the carrier has it). Not uploaded by the profile cut-off → LBL_TRACKING_UPLOAD; Ready but not handed over by the carrier's cut-off → WH_NOT_HANDED_OVER.
 */
class TrackingPush
{
    private static function base(int $ent)
    {
        return DB::table('wh_parcels as p')->join('t_branches as b', 'b.id', '=', 'p.fk_id_branch')->leftJoin('mp_profiles as m', 'm.id', '=', 'b.fk_id_mp_profile')->leftJoin('t_carriers as c', 'c.id', '=', 'p.fk_id_carrier')
            ->where('p.fk_id_ent', $ent)->where('p.status', '<>', 'VOIDED')->where(fn ($q) => $q->whereNull('p.label_status')->orWhere('p.label_status', '<>', 'VOID'));
    }

    /** Parcels whose tracking is due back now and not sent yet, per the profile's moment. */
    public static function due(int $ent, ?int $shop = null)
    {
        return self::base($ent)->whereNotNull('p.tracking_no')->where('p.tracking_no', '<>', '')->whereNull('p.tracking_uploaded_at')->when($shop, fn ($q) => $q->where('p.fk_id_branch', $shop))
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('m.tracking_upload_when', 'ON_LABEL')->whereIn('p.label_status', ['READY', 'PRINTED', 'REPRINTED', 'UPLOADED']))
                ->orWhere(fn ($w) => $w->where(fn ($x) => $x->whereNull('m.tracking_upload_when')->orWhere('m.tracking_upload_when', '<>', 'ON_LABEL'))->where('p.status', 'HANDED_OVER')))
            ->orderBy('b.shop_name')->orderBy('p.order_no')
            ->get(['p.id', 'p.parcel_code', 'p.fk_id_branch as shop_id', 'b.shop_name as shop', 'p.order_no', 'p.tracking_no', 'c.carrier_name as carrier', 'p.handed_over_at', 'p.packed_at', 'p.created_at', 'm.code as profile', 'm.tracking_upload_layout as layout', 'm.tracking_upload_when as moment', 'm.fk_id_api_connection as api', 'p.status']);
    }

    /** How the tracking of a shop goes back: API when the profile has a working connection, else its CSV layout. */
    public static function route(?object $row): string { return $row && $row->api && DB::table('api_connections')->where('id', $row->api)->where('status', 'CONNECTED')->exists() ? 'API' : 'CSV'; }

    /** The tracking-upload file of a shop in its profile's layout; the parcels in it are marked as sent (via CSV, with the file reference). */
    public static function export(int $ent, int $shop, bool $mark = true): array
    {
        $rows = self::due($ent, $shop); if ($rows->isEmpty()) throw new \InvalidArgumentException('No tracking is waiting for this shop.');
        $code = $rows[0]->layout ?: 'GENERIC'; $L = DB::table('mo_tracking_layouts')->where('code', $code)->where('f_active', 1)->first() ?? DB::table('mo_tracking_layouts')->where('code', 'GENERIC')->first();
        $cols = json_decode((string) $L->columns_json, true) ?: [['Order number', 'order_no'], ['Tracking number', 'tracking_no'], ['Carrier', 'carrier']];
        $lines = DB::table('wh_parcel_lines as l')->leftJoin('t_sales as s', 's.id', '=', 'l.fk_id_sale')->leftJoin('t_product_listings as li', 'li.id', '=', 's.fk_id_listing')->leftJoin('t_product_vendors as pv', 'pv.id', '=', 'l.fk_id_product')
            ->whereIn('l.fk_id_parcel', $rows->pluck('id'))->get(['l.fk_id_parcel', 'li.item_id', 'pv.SKU_VENDOR as sku', 'l.qty', 's.carrier_service_text as service'])->groupBy('fk_id_parcel');
        $perItem = (bool) array_intersect(array_column($cols, 1), ['item_id', 'sku', 'qty']); $out = [];
        foreach ($rows as $p) foreach ($perItem ? ($lines[$p->id] ?? collect([(object) ['item_id' => null, 'sku' => null, 'qty' => null, 'service' => null]])) : [($lines[$p->id] ?? collect())->first() ?? (object) ['service' => null]] as $l)
            $out[] = array_map(fn ($c) => match (true) { $c[1] === 'order_no' => $p->order_no, $c[1] === 'tracking_no' => $p->tracking_no, $c[1] === 'carrier' => $p->carrier, $c[1] === 'service' => $l->service ?? '', $c[1] === 'item_id' => $l->item_id ?? '',
                $c[1] === 'sku' => $l->sku ?? '', $c[1] === 'qty' => $l->qty ?? '', $c[1] === 'ship_date' => substr((string) ($p->handed_over_at ?: $p->packed_at ?: date('Y-m-d')), 0, 10), str_starts_with($c[1], 'text:') => substr($c[1], 5), default => '' }, $cols);
        $ref = 'TRK-' . $shop . '-' . date('YmdHis');
        if ($mark) { DB::table('wh_parcels')->whereIn('id', $rows->pluck('id'))->update(['tracking_uploaded_at' => now(), 'tracking_upload_via' => 'CSV', 'tracking_upload_ref' => $ref]); self::tasks($ent);
            Audit::log('wh_parcels', 0, 'EXPORT', ['tracking upload' => $ref, 'shop' => $shop, 'parcels' => $rows->count(), 'layout' => $L->code], null, $ent); }
        return ['headers' => array_column($cols, 0), 'rows' => $out, 'delimiter' => $L->delimiter === '\t' ? "\t" : ($L->delimiter ?: ','), 'file' => 'tracking_upload_' . strtolower($L->code) . '_' . date('Ymd_His') . ($L->delimiter === '\t' || $L->delimiter === "\t" ? '.txt' : '.csv'), 'ref' => $ref, 'parcels' => $rows->count(), 'layout' => $L->code];
    }

    /** What is waiting, per shop: route (API / CSV), count, oldest. */
    public static function board(int $ent): array
    {
        return self::due($ent)->groupBy('shop_id')->map(fn ($g) => ['shop_id' => (int) $g[0]->shop_id, 'shop' => $g[0]->shop, 'profile' => $g[0]->profile, 'moment' => $g[0]->moment ?: 'ON_HANDOVER', 'route' => self::route($g[0]),
            'note' => $g[0]->api && self::route($g[0]) === 'CSV' ? 'The profile has an API, but it is not connected – the CSV carries the tracking (never API-only).' : null, 'parcels' => $g->count(), 'layout' => $g[0]->layout ?: 'GENERIC'])->values()->all();
    }

    /** LBL_TRACKING_UPLOAD per shop and day past the profile's cut-off; WH_NOT_HANDED_OVER per Ready parcel past its carrier's cut-off. */
    public static function tasks(int $ent, ?string $now = null): array
    {
        $now ??= date('Y-m-d H:i:s'); $today = substr($now, 0, 10); $time = substr($now, 11, 8);
        $late = []; foreach (self::due($ent) as $p) { $cut = DB::table('mp_profiles')->where('code', $p->profile)->value('dispatch_cutoff_time') ?: '23:59:59'; $since = substr((string) ($p->moment === 'ON_LABEL' ? ($p->packed_at ?: $p->created_at) : $p->handed_over_at), 0, 10);
            if ($since && ($since < $today || $time > $cut)) $late[$p->shop_id . '|' . $since] = ['key' => [$p->shop_id, $since], 'title' => "Upload the tracking of {$p->shop} ($since) – not sent back yet", 'opt' => ['module' => 't_branches', 'record_id' => $p->shop_id, 'fix_url' => '#/tracking-upload']]; }
        $a = TaskEngine::sync('LBL_TRACKING_UPLOAD', array_values($late), null, $ent);
        $nh = []; foreach (self::base($ent)->where('p.status', 'READY')->get(['p.id', 'p.parcel_code', 'p.packed_at', 'p.created_at', 'c.carrier_name', 'c.collection_cutoff_time']) as $p) {
            $day = substr((string) ($p->packed_at ?: $p->created_at), 0, 10); $cut = $p->collection_cutoff_time ?: '23:59:59';
            if ($day < $today || $time > $cut) $nh[] = ['key' => [$p->parcel_code], 'title' => "Parcel {$p->parcel_code} is Ready but not handed over to " . ($p->carrier_name ?: 'the carrier') . ' – cut-off passed', 'opt' => ['module' => 'wh_parcels', 'record_id' => $p->id, 'fix_url' => '#/dispatch']]; }
        $b = TaskEngine::sync('WH_NOT_HANDED_OVER', $nh, null, $ent);
        return ['tracking' => $a, 'handover' => $b];
    }
}
