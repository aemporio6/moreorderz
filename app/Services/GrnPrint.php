<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * GRN PDF (template grn_a4) and thermal carton label (grn_label_100x150), R-F_SETUP-13.
 * Shortage = Expected − Received (negative = over-delivery, printed "+n over"); Damage % = Damaged / Received × 100 (2 dp, 0 when nothing received);
 * per-SKU lines with Accepted = Received − Damaged and a total row; Receiving Notes, Photos (the cycle's Drive folder link), Claim ref when a claim exists;
 * signature lines Received By / Checked By. File GRN_<grn_number>_<yyyymmdd>.pdf, kept in the cycle's documents; the same PDF is made when the GRN is posted.
 * Labels: one per carton – GRN no, SKU, carton n of m (over the whole GRN), qty, location, QR of the GRN link. Cartons of a line = received ÷ units per
 * carton of the PO's carton row for that SKU (rounded up, the last carton holds the rest); no carton row → one label for the line.
 */
class GrnPrint
{
    private static function e($v): string { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); }
    private static function n($v): string { return rtrim(rtrim(number_format((float) $v, 3, '.', ','), '0'), '.'); }

    /** Full location name: parent › child. */
    public static function locName(?int $id): ?string
    {
        if (! $id) return null; $names = []; $seen = [];
        while ($id && ! isset($seen[$id]) && ($l = DB::table('cat_inventory_locations')->where('id', $id)->first(['id', 'location_name', 'fk_id_parent']))) { $seen[$id] = 1; array_unshift($names, $l->location_name); $id = (int) $l->fk_id_parent; }
        return $names ? implode(' › ', $names) : null;
    }

    private static function person($v): ?string
    {
        if ($v === null || $v === '') return null;
        if (ctype_digit((string) $v)) return DB::table('t_users')->where('id', (int) $v)->value('display_name') ?? (string) $v;
        return (string) $v;
    }

    public static function data(int $ent, int $id): array
    {
        $g = DB::table('t_src_grn')->where('id', $id)->where('fk_id_ent', $ent)->first() ?? throw new \InvalidArgumentException('Not found.');      // R-F_SETUP-17: an id outside the company → Not found
        $cy = $g->fk_id_cycle ? DB::table('t_src_cycles')->where('id', $g->fk_id_cycle)->first(['id', 'cycle_code', 'drive_folder_url']) : null;
        $ship = $g->fk_id_shipment ? DB::table('t_src_shipments')->where('id', $g->fk_id_shipment)->first(['id', 'shipment_code', 'fk_id_po']) : null;
        $lines = DB::table('t_src_grn_lines as l')->leftJoin('t_product_vendors as p', 'p.id', '=', 'l.fk_id_product')->leftJoin('t_src_po_lines as pl', 'pl.id', '=', 'l.fk_id_po_line')->where('l.fk_id_grn', $id)->orderBy('l.id')
            ->get(['l.id', 'l.sku', 'p.SKU_VENDOR', 'p.product_name_vendor as product', 'l.expected_qty', 'l.received_qty', 'l.damaged_qty', 'l.missing_qty', 'l.fk_id_location', 'l.fk_id_product', 'pl.fk_id_po', 'pl.fk_id_cycle_product'])
            ->map(fn ($l) => ['sku' => $l->sku ?: $l->SKU_VENDOR, 'product' => $l->product, 'expected' => (float) $l->expected_qty, 'received' => (float) $l->received_qty, 'damaged' => (float) $l->damaged_qty, 'missing' => (float) $l->missing_qty,
                'accepted' => (float) $l->received_qty - (float) $l->damaged_qty, 'location' => self::locName((int) $l->fk_id_location ?: (int) $g->fk_id_warehouse), 'po' => (int) ($l->fk_id_po ?: ($ship->fk_id_po ?? 0)), 'product_id' => (int) $l->fk_id_product, 'cp' => (int) $l->fk_id_cycle_product])->all();
        $exp = (float) $g->expected_units; $rec = (float) $g->units_received; $dam = (float) $g->damaged_units;
        if ($lines && ! $exp && ! $rec) { $exp = array_sum(array_column($lines, 'expected')); $rec = array_sum(array_column($lines, 'received')); $dam = array_sum(array_column($lines, 'damaged')); }
        $short = $exp - $rec; $pct = $rec > 0 ? round($dam / $rec * 100, 2) : 0.0;
        $tot = ['expected' => 0, 'received' => 0, 'damaged' => 0, 'missing' => 0, 'accepted' => 0]; foreach ($lines as $l) foreach ($tot as $k => $_) $tot[$k] += $l[$k];
        $no = $g->grn_number ?: $g->grn_code ?: ('GRN-' . $g->id);
        return ['id' => (int) $g->id, 'no' => $no, 'code' => $g->grn_code ?: $no, 'title' => 'GOODS RECEIPT NOTE — ' . ($g->grn_code ?: $no), 'date' => $g->grn_date, 'arrival' => $g->arrival_date,
            'cycle' => $cy->cycle_code ?? null, 'cycle_id' => $cy->id ?? null, 'warehouse' => self::locName((int) $g->fk_id_warehouse), 'shipment' => $ship->shipment_code ?? null, 'received_by' => self::person($g->received_by),
            'cartons_expected' => (float) $g->expected_cartons, 'cartons_received' => (float) $g->cartons_received, 'qc_status' => $g->receiving_qc_status,
            'summary' => ['expected' => $exp, 'received' => $rec, 'damaged' => $dam, 'missing' => (float) ($g->missing_units ?: $tot['missing']), 'shortage' => $short, 'shortage_text' => $short < 0 ? '+' . self::n(-$short) . ' over' : self::n($short), 'damage_pct' => $pct],
            'lines' => $lines, 'totals' => $tot, 'notes' => $g->notes, 'photos' => $cy->drive_folder_url ?? null, 'claim' => $g->claim_reference ?: null,
            'file' => 'GRN_' . preg_replace('/[^A-Za-z0-9_-]/', '-', $no) . '_' . date('Ymd') . '.pdf', 'url' => url('/#/cycle/' . ($cy->id ?? 0) . '?grn=' . $g->id)];
    }

    /** The A4 page (inside PrintService's page frame). */
    public static function page(int $ent, int $id): string
    {
        $d = self::data($ent, $id); $s = $d['summary']; $dt = fn ($v) => $v ? date('d-m-Y', strtotime((string) $v)) : '—';
        $cell = fn ($l, $v) => '<td style="width:33%;vertical-align:top;padding:1mm 2mm 2mm 0"><div class="mut">' . $l . '</div><div class="big">' . self::e($v ?? '—') . '</div></td>';
        $o = '<div class="in"><div style="float:right">' . Brand::img($ent, 15) . '</div><h1>' . self::e($d['title']) . '</h1><table style="width:100%">'
            . '<tr>' . $cell('GRN Number', $d['no']) . $cell('Date', $dt($d['date'])) . $cell('Cycle / Warehouse', trim(($d['cycle'] ?? '—') . ' / ' . ($d['warehouse'] ?? '—'))) . '</tr>'
            . '<tr>' . $cell('Shipment', $d['shipment']) . $cell('Received By', $d['received_by']) . $cell('Arrival date', $dt($d['arrival'])) . '</tr>'
            . '<tr>' . $cell('Cartons expected / received', self::n($d['cartons_expected']) . ' / ' . self::n($d['cartons_received'])) . $cell('Receiving QC status', $d['qc_status']) . '<td></td></tr></table>'
            . '<table class="t"><tr><th class="r">Expected Units</th><th class="r">Received</th><th class="r">Damaged</th><th class="r">Missing</th><th class="r">Shortage</th><th class="r">Damage %</th></tr>'
            . '<tr><td class="r">' . self::n($s['expected']) . '</td><td class="r">' . self::n($s['received']) . '</td><td class="r">' . self::n($s['damaged']) . '</td><td class="r">' . self::n($s['missing']) . '</td><td class="r">' . self::e($s['shortage_text']) . '</td><td class="r">' . number_format($s['damage_pct'], 2) . '</td></tr></table>'
            . '<table class="t"><tr><th>SKU</th><th>Product</th><th class="r">Expected</th><th class="r">Received</th><th class="r">Damaged</th><th class="r">Missing</th><th class="r">Accepted</th><th>Location</th></tr>';
        foreach ($d['lines'] as $l) $o .= '<tr><td>' . self::e($l['sku']) . '</td><td>' . self::e(mb_substr((string) $l['product'], 0, 60)) . '</td><td class="r">' . self::n($l['expected']) . '</td><td class="r">' . self::n($l['received']) . '</td><td class="r">' . self::n($l['damaged']) . '</td><td class="r">' . self::n($l['missing']) . '</td><td class="r">' . self::n($l['accepted']) . '</td><td>' . self::e($l['location']) . '</td></tr>';
        $t = $d['totals'];
        $o .= '<tr><td colspan="2" class="big">Total</td><td class="r big">' . self::n($t['expected']) . '</td><td class="r big">' . self::n($t['received']) . '</td><td class="r big">' . self::n($t['damaged']) . '</td><td class="r big">' . self::n($t['missing']) . '</td><td class="r big">' . self::n($t['accepted']) . '</td><td></td></tr></table>'
            . '<div style="margin-top:4mm"><div class="big">Receiving Notes</div><div>' . ($d['notes'] ? nl2br(self::e($d['notes'])) : '—') . '</div></div>'
            . ($d['photos'] ? '<div style="margin-top:2mm">Photos: ' . self::e($d['photos']) . '</div>' : '') . ($d['claim'] ? '<div style="margin-top:2mm">Claim ref: ' . self::e($d['claim']) . '</div>' : '')
            . '<table style="width:100%;margin-top:14mm"><tr><td style="width:50%"><div>Received By</div><div style="margin-top:8mm">Name: ____________________</div><div style="margin-top:3mm">Date: ____________________</div></td>'
            . '<td><div>Checked By</div><div style="margin-top:8mm">Name: ____________________</div><div style="margin-top:3mm">Date: ____________________</div></td></tr></table></div>';
        return $o;
    }

    /** The cartons of a GRN for the labels: [sku, n, of, qty, location]. */
    public static function cartons(int $ent, int $id): array
    {
        $d = self::data($ent, $id); $out = [];
        foreach ($d['lines'] as $l) {
            if ($l['received'] <= 0) continue;
            $upc = (float) DB::table('t_src_cartons')->where('f_active', 1)->where('fk_id_po', $l['po'])->where(fn ($q) => $q->where('sku', $l['sku'])->when($l['cp'], fn ($w) => $w->orWhere('fk_id_cycle_product', $l['cp'])))->where('units_per_carton', '>', 0)->value('units_per_carton');
            $n = $upc > 0 ? (int) ceil($l['received'] / $upc) : 1; $left = $l['received'];
            for ($i = 0; $i < $n; $i++) { $q = $upc > 0 ? min($upc, $left) : $left; $left -= $q; $out[] = ['sku' => $l['sku'], 'qty' => $q, 'location' => $l['location']]; }
        }
        $m = count($out); foreach ($out as $i => &$c) { $c['n'] = $i + 1; $c['of'] = $m; } unset($c);
        return ['grn' => $d['no'], 'url' => $d['url'], 'cartons' => $out];
    }

    /** One 100 × 150 label per carton. */
    public static function labels(int $ent, int $id): array
    {
        $x = self::cartons($ent, $id); $qr = PrintService::qr($x['url'], 240);
        return array_map(fn ($c) => '<div class="in"><div class="mut">GRN</div><h1 style="font-size:18pt">' . self::e($x['grn']) . '</h1><div class="big" style="font-size:14pt">' . self::e($c['sku']) . '</div>'
            . '<div style="font-size:24pt;font-weight:bold;margin:3mm 0">CARTON ' . $c['n'] . ' / ' . $c['of'] . '</div><table class="t"><tr><td>Qty</td><td class="r big">' . self::n($c['qty']) . '</td></tr><tr><td>Location</td><td class="r">' . self::e($c['location'] ?? '—') . '</td></tr></table>'
            . '<div style="margin-top:3mm"><img src="' . $qr . '" style="width:30mm;height:30mm"></div></div>', $x['cartons']);
    }

    /** Where a kept file lives (tests write to a temp folder, never into the app's storage). */
    public static function abs(string $rel): string { return app()->runningUnitTests() ? sys_get_temp_dir() . '/mo_test_storage/' . $rel : storage_path('app/' . $rel); }

    /** The PDF file, kept in the cycle's documents (made again on posting). */
    public static function store(int $ent, int $id): array
    {
        $d = self::data($ent, $id);
        $html = '<html><head><meta charset="utf-8"><style>@page{margin:0} body{margin:0;font-family:"DejaVu Sans",sans-serif;font-size:10pt} h1{font-size:15pt;margin:0 0 3mm} .in{padding:12mm} .mut{color:#555;font-size:9pt} .big{font-weight:bold} table.t{width:100%;border-collapse:collapse;margin-top:4mm} table.t th{text-align:left;border-bottom:0.4mm solid #000;padding:1mm;font-size:9pt} table.t td{border-bottom:0.15mm solid #999;padding:1mm} .r{text-align:right}</style></head><body>' . self::page($ent, $id) . '</body></html>';
        $pdf = PrintService::pdf($html, 210, 297);
        $rel = 'src_docs/cycle_' . ($d['cycle_id'] ?: 0) . '/' . $d['file']; $abs = self::abs($rel); if (! is_dir(dirname($abs))) mkdir(dirname($abs), 0775, true); file_put_contents($abs, $pdf);
        $row = ['fk_id_ent' => $ent, 'fk_id_cycle' => $d['cycle_id'] ?: null, 'fk_id_grn' => $id, 'doc_type' => 'GRN PDF', 'doc_category' => 'GRN', 'doc_name' => $d['file'], 'notes' => $rel, 'doc_status' => 'GENERATED', 'f_active' => 1, 'updated_at' => now()];
        $old = DB::table('t_src_documents')->where('fk_id_grn', $id)->where('doc_type', 'GRN PDF')->value('id');
        $old ? DB::table('t_src_documents')->where('id', $old)->update($row) : $old = DB::table('t_src_documents')->insertGetId(RecordService::fillNotNull('t_src_documents', $row + ['created_by' => Context::userId(), 'created_at' => now()]));
        return ['file' => $d['file'], 'path' => $rel, 'document_id' => (int) $old, 'body' => $pdf, 'html' => $html];
    }
}
