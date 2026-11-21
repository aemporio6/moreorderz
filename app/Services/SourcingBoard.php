<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Buying dashboard cards:
 *  - R-F_SETUP-22 Contact today: the suppliers / forwarders behind the open Needs-action items (pay, QC, late shipment, claim), max 8, initials avatar,
 *    role label Supplier / Forwarder, WhatsApp click-to-chat with a message per item; the contact is logged on the cycle history.
 *  - R-F_SETUP-23 Shipments board: not-arrived shipments of open cycles, ETA ascending (no ETA last), top 15, customs / GRN chips, tracking link, ETA history.
 */
class SourcingBoard
{
    private static function wa(?string $phone): ?string { $d = preg_replace('/\D/', '', (string) $phone); if (str_starts_with((string) $phone, '00')) $d = substr($d, 2); return strlen($d) >= 8 && strlen($d) <= 15 ? $d : null; }
    private static function initials(string $n): string { $w = preg_split('/\s+/', trim($n)) ?: ['?']; return mb_strtoupper(mb_substr($w[0], 0, 1) . (count($w) > 1 ? mb_substr(end($w), 0, 1) : '')); }
    private static function dm(?string $d): string { return $d ? date('d M Y', strtotime($d)) : '—'; }

    private static function openCycles(int $ent): array
    {
        return DB::table('t_src_cycles')->where('fk_id_ent', $ent)->where('f_active', 1)->whereNotIn('status', ['CLOSED', 'Closed', 'CANCELLED', 'Cancelled', 'COMPLETED', 'Completed'])->pluck('id')->map(fn ($x) => (int) $x)->all();
    }

    /** R-F_SETUP-22 */
    public static function contactToday(int $ent): array
    {
        $people = [];
        $put = function (string $kind, ?int $id, string $item, string $text, int $cycle) use (&$people) {
            if (! $id) return;
            $row = $kind === 'Forwarder' ? DB::table('t_src_forwarders')->where('id', $id)->first(['forwarder_name as name', 'phone']) : DB::table('t_src_suppliers')->where('id', $id)->first(['supplier_name as name', 'phone_whatsapp as phone']);
            if (! $row) return; $k = $kind . ':' . $id;
            $people[$k] ??= ['key' => $k, 'kind' => $kind, 'id' => $id, 'name' => $row->name, 'initials' => self::initials((string) $row->name), 'whatsapp' => self::wa($row->phone), 'items' => []];
            $people[$k]['items'][] = ['item' => $item, 'cycle' => $cycle, 'message' => str_replace('<name>', (string) $row->name, $text)];
        };
        foreach (self::openCycles($ent) as $cy) foreach (CycleAlerts::detect($ent, $cy) as $r) {
            $rid = (int) ($r['opt']['record_id'] ?? 0);
            switch ($r['code']) {
                case 'SRC_PAY_DUE':
                    $po = DB::table('t_src_po')->where('id', $rid)->first(['po_number', 'fk_id_supplier']);
                    if ($po) $put('Supplier', (int) $po->fk_id_supplier, 'Payment', 'Hello <name>, we are arranging payment of ' . ($r['key'][1] ?? 'the next term') . " for PO {$po->po_number}.", $cy);
                    break;
                case 'SRC_QC_FAILED':
                    $qc = DB::table('t_src_qc as q')->leftJoin('t_src_po as p', 'p.id', '=', 'q.fk_id_po')->where('q.id', $rid)->first(['q.qc_code', 'q.qty_failed', 'q.fk_id_supplier', 'p.fk_id_supplier as po_sup']);
                    if ($qc) $put('Supplier', (int) ($qc->fk_id_supplier ?: $qc->po_sup), 'QC', "Hello <name>, QC {$qc->qc_code} found " . (int) $qc->qty_failed . ' defects – please send corrective action.', $cy);
                    break;
                case 'SRC_SHIP_OVERDUE':
                    $s = DB::table('t_src_shipments as s')->leftJoin('t_src_po as p', 'p.id', '=', 's.fk_id_po')->where('s.id', $rid)->first(['s.shipment_code', 's.eta', 's.fk_id_forwarder', 's.fk_id_supplier', 'p.fk_id_supplier as po_sup']);
                    if ($s) { $msg = "Hello <name>, shipment {$s->shipment_code} ETA was " . self::dm($s->eta) . ' – please send the latest tracking.';
                        $s->fk_id_forwarder ? $put('Forwarder', (int) $s->fk_id_forwarder, 'ETA chase', $msg, $cy) : $put('Supplier', (int) ($s->fk_id_supplier ?: $s->po_sup), 'ETA chase', $msg, $cy); }
                    break;
                case 'SRC_GRN_SHORT':
                    $g = DB::table('t_src_grn as g')->leftJoin('t_src_shipments as s', 's.id', '=', 'g.fk_id_shipment')->leftJoin('t_src_po as p', 'p.id', '=', 's.fk_id_po')->where('g.id', $rid)->first(['g.grn_number', 'g.missing_units', 'g.damaged_units', 'p.fk_id_supplier', 'g.fk_id_cycle']);
                    $sup = $g ? ((int) $g->fk_id_supplier ?: (int) DB::table('t_src_po')->where('fk_id_cycle', $g->fk_id_cycle)->where('f_active', 1)->value('fk_id_supplier')) : 0;
                    if ($g) $put('Supplier', $sup, 'Claim', "Hello <name>, GRN {$g->grn_number}: " . (int) $g->missing_units . ' missing / ' . (int) $g->damaged_units . ' damaged – please confirm the claim.', $cy);
                    break;
            }
        }
        $people = array_slice(array_values($people), 0, 8);
        foreach ($people as &$p) { $p['message'] = $p['items'][0]['message']; $p['cycle'] = $p['items'][0]['cycle']; $p['label'] = $p['kind']; } unset($p);
        return ['people' => $people, 'empty' => 'No one urgent today.'];
    }

    /** Click-to-chat: log "WhatsApp to <name> · <item>" on the cycle and give the wa.me link. */
    public static function whatsapp(int $ent, string $key, int $cycle, string $item, string $text): array
    {
        $p = collect(self::contactToday($ent)['people'])->firstWhere('key', $key) ?? throw new \InvalidArgumentException('This contact has nothing open today.');
        if (! $p['whatsapp']) throw new \InvalidArgumentException($p['name'] . ' has no WhatsApp number. Add it on the ' . strtolower($p['kind']) . ' first.');
        if (! DB::table('t_src_cycles')->where('id', $cycle)->where('fk_id_ent', $ent)->exists()) throw new \InvalidArgumentException('Not found.');
        Audit::log('t_src_cycles', $cycle, 'UPDATE', ['contact' => "WhatsApp to {$p['name']} · $item", 'message' => mb_substr($text, 0, 500)], "WhatsApp to {$p['name']} · $item", $ent);
        return ['url' => 'https://wa.me/' . $p['whatsapp'] . '?text=' . rawurlencode($text), 'message' => 'Logged on the cycle.'];
    }

    /** R-F_SETUP-23 */
    public static function shipments(int $ent, int $limit = 15, ?string $today = null): array
    {
        $today ??= date('Y-m-d'); $cy = self::openCycles($ent);
        $q = DB::table('t_src_shipments as s')->leftJoin('t_src_po as p', 'p.id', '=', 's.fk_id_po')->leftJoin('t_src_forwarders as f', 'f.id', '=', 's.fk_id_forwarder')
            ->where('s.fk_id_ent', $ent)->where('s.f_active', 1)->where(fn ($w) => $w->whereNull('s.actual_arrival')->orWhere('s.actual_arrival', '0000-00-00'))
            ->whereIn(DB::raw('COALESCE(s.fk_id_cycle, p.fk_id_cycle)'), $cy ?: [0])->orderByRaw('s.eta IS NULL, s.eta')->orderBy('s.id');
        $total = (clone $q)->count();
        $rows = $q->limit($limit)->get(['s.id', 's.shipment_code', 's.fk_id_po', 'p.po_number', 'p.po_code', DB::raw('COALESCE(s.fk_id_cycle, p.fk_id_cycle) as cycle_id'), 'f.forwarder_name', 's.shipping_mode', 's.actual_shipped_qty', 's.confirmed_qty', 's.planned_qty', 's.actual_departure', 's.eta', 's.tracking_url'])
            ->map(function ($s) use ($today) {
                $late = $s->eta && $s->eta < $today; $shipped = (bool) $s->actual_departure;
                $cu = DB::table('t_src_customs')->where('fk_id_shipment', $s->id)->where('f_active', 1)->orderByDesc('updated_at')->orderByDesc('id')->value('status');
                $customs = $cu === null || $cu === '' ? ['—', 'grey'] : [$cu, stripos($cu, 'clear') !== false ? 'teal' : ($late ? 'red' : 'amber')];
                $grn = DB::table('t_src_grn')->where('fk_id_shipment', $s->id)->where('f_active', 1)->exists();
                $g = $grn ? ['Received', 'teal'] : (! $shipped ? ['—', 'grey'] : ($late ? ['Not received · late', 'red'] : ['Not received', 'amber']));
                $hist = DB::table('src_shipment_eta_history')->where('fk_id_shipment', $s->id)->orderBy('id')->get(['old_eta', 'new_eta', 'changed_at']);
                $products = $s->fk_id_po ? DB::table('t_src_po_lines as l')->join('t_src_cycle_products as cp', 'cp.id', '=', 'l.fk_id_cycle_product')->where('l.fk_id_po', $s->fk_id_po)->where('l.f_active', 1)->distinct()->pluck('cp.product_name')->filter()->values()->all() : [];
                return ['id' => (int) $s->id, 'shipment' => $s->shipment_code, 'po' => $s->po_number ?: $s->po_code ?: '—', 'po_id' => $s->fk_id_po ? (int) $s->fk_id_po : null, 'products' => $products, 'cycle_id' => $s->cycle_id ? (int) $s->cycle_id : null,
                    'cycle' => $s->cycle_id ? DB::table('t_src_cycles')->where('id', $s->cycle_id)->value('cycle_code') : null, 'forwarder' => $s->forwarder_name, 'mode' => $s->shipping_mode,
                    'units' => (float) max((float) $s->actual_shipped_qty, (float) $s->confirmed_qty, (float) $s->planned_qty), 'departed' => $shipped ? date('d M', strtotime($s->actual_departure)) : 'Not shipped', 'eta' => $s->eta ? date('d M', strtotime($s->eta)) : '—',
                    'customs' => $customs[0], 'customs_tone' => $customs[1], 'grn' => $g[0], 'grn_tone' => $g[1], 'tracking_url' => $s->tracking_url,
                    'eta_changes' => $hist->count(), 'eta_history' => $hist->count() ? 'ETA changed ' . $hist->count() . ' times: ' . $hist->map(fn ($h) => self::dm($h->old_eta) . ' → ' . self::dm($h->new_eta))->implode(', ') : null];
            })->all();
        return ['rows' => $rows, 'total' => $total, 'show_all' => $total > $limit ? '#/src-transit' : null, 'empty' => 'No shipments under way.'];
    }
}
