<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Carrier label PDF import (R-A_SALES-85, 18_Labels §5). A PDF of labels downloaded from the carrier is split into one page per label;
 * from each page the tool reads the tracking number (carrier's tracking pattern), the service text (the carrier's service map), the delivery postcode
 * and the order reference when printed – in memory only: no name and no address line is ever saved, only the outward postcode.
 * Each page is matched to an open order line of the shop (order reference, else tracking already on a line, else postcode + date window with exactly one candidate)
 * and stored as that line's label (label_status READY). Pages without a sure match wait in the set-aside list with their candidates. The label PDF is never redrawn.
 * Needs the poppler tools pdfseparate and pdftotext on the server (package poppler-utils).
 */
class CarrierLabels
{
    public const UK_POSTCODE = '/\b([A-Z]{1,2}\d[A-Z\d]?)\s*(\d[A-Z]{2})\b/';

    private static function tool(string $name): string
    {
        foreach (['/usr/bin/', '/usr/local/bin/', '/opt/homebrew/bin/'] as $dir) if (is_executable($dir . $name)) return $dir . $name;
        throw new \RuntimeException("The server is missing the PDF tool \"$name\" (package poppler-utils). Ask the developer to install it; nothing was loaded.");
    }

    /** Split a PDF into one file per page; returns the page paths in order. */
    public static function pages(string $pdf, string $dir): array
    {
        @mkdir($dir, 0700, true);
        $p = new Process([self::tool('pdfseparate'), $pdf, $dir . '/page-%d.pdf']); $p->setTimeout(120); $p->run();
        if (! $p->isSuccessful()) throw new \InvalidArgumentException('This file could not be opened as a PDF.');
        $files = glob($dir . '/page-*.pdf'); natsort($files);
        return array_values($files);
    }

    public static function text(string $pdf): string
    {
        $p = new Process([self::tool('pdftotext'), '-layout', '-enc', 'UTF-8', $pdf, '-']); $p->setTimeout(60); $p->run();
        return $p->isSuccessful() ? $p->getOutput() : '';
    }

    /** What one label page tells us. $openOrders = order numbers of the shop that are still open (to find a printed order reference). */
    public static function read(string $text, ?string $trackingPattern, array $serviceTexts, array $openOrders): array
    {
        $flat = preg_replace('/[ \t]+/', ' ', strtoupper($text));
        $pattern = $trackingPattern ?: '/\b[A-Z]{2}\d{9}GB\b/';
        if (@preg_match($pattern, '') === false) $pattern = '/' . str_replace('/', '\/', $pattern) . '/';
        $track = preg_match($pattern, $flat, $m) ? str_replace(' ', '', $m[0]) : null;
        $service = null; foreach ($serviceTexts as $s) if ($s !== '' && str_contains($flat, strtoupper($s))) { $service = $s; break; }
        // the delivery postcode: the last UK postcode on the label that is not the sender's (the sender comes first on most labels; the setting LABEL_SENDER_POSTCODE excludes it for sure)
        preg_match_all(self::UK_POSTCODE, $flat, $pc, PREG_SET_ORDER); $post = null;
        foreach ($pc as $x) { $full = $x[1] . ' ' . $x[2]; if ($full !== strtoupper((string) Settings::get('LABEL_SENDER_POSTCODE', Context::ent(), ''))) $post = $full; }
        $order = null; foreach ($openOrders as $o) if (strlen((string) $o) >= 5 && str_contains($flat, strtoupper((string) $o))) { $order = (string) $o; break; }
        return ['tracking' => $track, 'service' => $service, 'postcode' => $post, 'order' => $order];
    }

    private static function out(?string $postcode): ?string { return $postcode ? strtok($postcode, ' ') : null; }

    public static function import(int $ent, int $carrierId, int $branchId, string $pdf, string $fileName): array
    {
        if (! in_array(Context::role(), ['OWNER', 'MANAGER', 'WAREHOUSE', 'TL'], true)) throw new \InvalidArgumentException('Your role cannot load carrier labels.');
        $car = DB::table('t_carriers')->where('id', $carrierId)->whereIn('fk_id_ent', [0, $ent])->first() ?? throw new \InvalidArgumentException('Choose the carrier.');
        if (($car->label_pdf_layout ?: 'ONE_PER_PAGE') !== 'ONE_PER_PAGE') throw new \InvalidArgumentException("Carrier {$car->carrier_name} is set to \"{$car->label_pdf_layout}\" (several labels on one A4 page). Only one label per page is read for now – download the labels one per page from the carrier.");
        $shop = DB::table('t_branches')->where('id', $branchId)->where('fk_id_ent', $ent)->first() ?? throw new \InvalidArgumentException('Choose the shop.');
        $sha = hash_file('sha256', $pdf);
        if (DB::table('fin_import_batches')->where('company_id', $ent)->where('report_type', 'CARRIER_LABEL_PDF')->where('file_hash', substr($sha, 0, 40))->exists()) throw new \InvalidArgumentException('Already imported: this exact PDF was loaded before.');
        $tmp = sys_get_temp_dir() . '/mo_labels_' . bin2hex(random_bytes(6));
        $pages = self::pages($pdf, $tmp);
        if (! $pages) throw new \InvalidArgumentException('The PDF has no pages.');
        $services = DB::table('carrier_service_map')->where('fk_id_carrier', $carrierId)->where('f_active', 1)->orderByRaw('CHAR_LENGTH(service_text) DESC')->pluck('service_text')->all();
        $open = DB::table('t_sales')->where('fk_id_ent', $ent)->where('fk_id_branch', $branchId)->where('sale_source', 'active')->where('status', 'Pending')->where(fn ($w) => $w->whereNull('label_status')->orWhereIn('label_status', ['', 'NONE']))->pluck('order_no')->unique()->sortByDesc(fn ($o) => strlen((string) $o))->values()->all();
        $days = (int) Settings::get('LABEL_MATCH_DAYS', $ent, 3);
        try {
            return DB::transaction(function () use ($ent, $car, $shop, $pages, $services, $open, $days, $fileName, $sha) {
                $batch = DB::table('fin_import_batches')->insertGetId(['report_type' => 'CARRIER_LABEL_PDF', 'company_id' => $ent, 'branch_id' => $shop->id, 'file_name' => mb_substr($fileName, 0, 200), 'file_hash' => substr($sha, 0, 40), 'rows_total' => count($pages), 'imported_by' => Context::userId(), 'imported_at' => now()]);
                $res = ['batch' => $batch, 'pages' => count($pages), 'matched' => 0, 'set_aside' => 0, 'unmapped_service' => 0, 'by' => []]; $taken = [];
                foreach ($pages as $i => $page) {
                    $r = self::read(self::text($page), $car->tracking_pattern, $services, $open);
                    $name = "labels/$ent/" . date('Y/m') . "/$batch-" . ($i + 1) . '.pdf'; Storage::disk('local')->put($name, file_get_contents($page));
                    $doc = DB::table('sys_documents')->insertGetId(RecordService::fillNotNull('sys_documents', ['fk_id_ent' => $ent, 'doc_no' => Numbering::next('DOC', $ent), 'title' => 'Carrier label', 'stored_name' => $name, 'orig_name' => mb_substr($fileName, 0, 150) . ' p' . ($i + 1), 'mime' => 'application/pdf', 'size_bytes' => filesize($page),
                        'sha256' => hash_file('sha256', $page), 'd_doc' => date('Y-m-d'), 'version' => 1, 'status' => 'ACTIVE', 'uploaded_by' => Context::userId(), 'created_at' => now(), 'tags' => 'label']));
                    // match: order reference → tracking already on a line → postcode + date window with exactly one candidate
                    $q = fn () => DB::table('t_sales')->where('fk_id_ent', $ent)->where('fk_id_branch', $shop->id)->where('sale_source', 'active')->where('status', 'Pending')->whereNotIn('id', $taken ?: [0]);
                    $sale = null; $by = null; $cands = [];
                    if ($r['order']) { $sale = $q()->where('order_no', $r['order'])->orderBy('id')->first(); $by = 'ORDER_REF'; }
                    if (! $sale && $r['tracking']) { $sale = $q()->where('tracking_no', $r['tracking'])->first(); $by = 'TRACKING'; }
                    if (! $sale && $r['postcode']) {
                        $cands = $q()->where(fn ($w) => $w->whereNull('label_status')->orWhereIn('label_status', ['', 'NONE']))->where('d_order', '>=', date('Y-m-d', strtotime("-$days day")))
                            ->whereRaw("UPPER(REPLACE(ship_to_postcode, ' ', '')) = ?", [str_replace(' ', '', $r['postcode'])])->get(['id', 'order_no', 'sku', 'd_order'])->unique('order_no')->values();
                        if ($cands->count() === 1) { $sale = $q()->where('id', $cands[0]->id)->first(); $by = 'POSTCODE'; }
                    }
                    $problem = null;
                    if (! $r['tracking']) $problem = 'No tracking number found with the carrier\'s pattern.';
                    elseif (! $r['service']) { $problem = 'Service text not found in the carrier\'s service map – add it (+ Add map).'; $res['unmapped_service']++; }
                    $row = ['fk_id_ent' => $ent, 'fk_id_batch' => $batch, 'fk_id_branch' => $shop->id, 'fk_id_carrier' => $car->id, 'page_no' => $i + 1, 'fk_id_document' => $doc, 'tracking_no' => $r['tracking'], 'service_text' => $r['service'], 'postcode_out' => self::out($r['postcode']), 'order_ref' => $r['order'], 'problem' => $problem, 'created_at' => now(), 'updated_at' => now()];
                    if ($sale && $r['tracking']) {
                        self::apply($sale, $ent, $r, $doc);
                        foreach (DB::table('t_sales')->where('fk_id_ent', $ent)->where('fk_id_branch', $shop->id)->where('order_no', $sale->order_no)->pluck('id') as $sid) $taken[] = (int) $sid;
                        DB::table('mo_label_pages')->insert($row + ['status' => 'MATCHED', 'fk_id_sale' => $sale->id, 'matched_by' => $by]); $res['matched']++; $res['by'][$by] = ($res['by'][$by] ?? 0) + 1;
                    } else {
                        DB::table('mo_label_pages')->insert($row + ['status' => 'SET_ASIDE', 'candidates_json' => $cands ? json_encode($cands->map(fn ($c) => ['id' => $c->id, 'order_no' => $c->order_no, 'sku' => $c->sku, 'd_order' => $c->d_order])->all()) : null,
                            'problem' => $problem ?? ($cands && $cands->count() > 1 ? $cands->count() . ' open orders have this postcode – pick the right one.' : 'No open order found for this label.')]);
                        $res['set_aside']++;
                    }
                }
                DB::table('fin_import_batches')->where('id', $batch)->update(['rows_new' => $res['matched'], 'rows_error' => $res['set_aside']]);
                EventBus::fire('IMPORT_APPLIED', 'fin_import_batches', $batch, ['type' => 'CARRIER_LABEL_PDF', 'matched' => $res['matched']], $ent);
                Audit::log('fin_import_batches', $batch, 'INSERT', ['type' => 'CARRIER_LABEL_PDF', 'pages' => $res['pages'], 'matched' => $res['matched']], null, $ent);
                return $res;
            });
        } finally { array_map('unlink', glob($tmp . '/*') ?: []); @rmdir($tmp); }
    }

    /** Put the label on every line of the order (one parcel per order). */
    private static function apply(object $sale, int $ent, array $r, int $doc): void
    {
        DB::table('t_sales')->where('fk_id_ent', $ent)->where('fk_id_branch', $sale->fk_id_branch)->where('order_no', $sale->order_no)->where('status', 'Pending')
            ->update(['tracking_no' => $r['tracking'], 'carrier_service_text' => $r['service'], 'label_file' => (string) $doc, 'label_status' => 'READY', 'updated_at' => now()]);
    }

    /** A person picks the order for a set-aside label page. */
    public static function pick(int $ent, int $pageId, int $saleId): void
    {
        if (! in_array(Context::role(), ['OWNER', 'MANAGER', 'WAREHOUSE', 'TL'], true)) throw new \InvalidArgumentException('Your role cannot link labels.');
        DB::transaction(function () use ($ent, $pageId, $saleId) {
            $p = DB::table('mo_label_pages')->where('id', $pageId)->where('fk_id_ent', $ent)->lockForUpdate()->first() ?? throw new \InvalidArgumentException('Label page not found.');
            if ($p->status !== 'SET_ASIDE') throw new \InvalidArgumentException('This label is already linked.');
            if (! $p->tracking_no) throw new \InvalidArgumentException('No tracking number was read on this label, so it cannot be linked.');
            $s = DB::table('t_sales')->where('id', $saleId)->where('fk_id_ent', $ent)->where('fk_id_branch', $p->fk_id_branch)->where('status', 'Pending')->first() ?? throw new \InvalidArgumentException('Choose an open order of the same shop.');
            if (DB::table('t_sales')->where('fk_id_ent', $ent)->where('fk_id_branch', $p->fk_id_branch)->where('order_no', $s->order_no)->where('label_status', 'READY')->exists()) throw new \InvalidArgumentException("Order {$s->order_no} already has a label.");
            self::apply($s, $ent, ['tracking' => $p->tracking_no, 'service' => $p->service_text], (int) $p->fk_id_document);
            DB::table('mo_label_pages')->where('id', $pageId)->update(['status' => 'MATCHED', 'fk_id_sale' => $saleId, 'matched_by' => 'BY_HAND', 'updated_at' => now()]);
        });
    }

    /** Undo a whole label file: the links on order lines that are still Pending are taken off, the pages and their files removed. */
    public static function undo(int $ent, int $batchId): int
    {
        if (! in_array(Context::role(), ['OWNER', 'MANAGER'], true)) throw new \InvalidArgumentException('Only the Owner or a Manager undoes a label file.');
        return DB::transaction(function () use ($ent, $batchId) {
            $pages = DB::table('mo_label_pages')->where('fk_id_batch', $batchId)->where('fk_id_ent', $ent)->get(); if ($pages->isEmpty()) throw new \InvalidArgumentException('Label file not found.');
            $n = 0;
            foreach ($pages as $p) {
                if ($p->fk_id_sale && ($s = DB::table('t_sales')->where('id', $p->fk_id_sale)->first())) $n += DB::table('t_sales')->where('fk_id_ent', $ent)->where('fk_id_branch', $s->fk_id_branch)->where('order_no', $s->order_no)->where('status', 'Pending')->where('label_file', (string) $p->fk_id_document)
                    ->update(['tracking_no' => null, 'carrier_service_text' => null, 'label_file' => null, 'label_status' => null, 'updated_at' => now()]);
                if ($p->fk_id_document && ($d = DB::table('sys_documents')->where('id', $p->fk_id_document)->first())) { Storage::disk('local')->delete($d->stored_name); DB::table('sys_documents')->where('id', $d->id)->update(['status' => 'DELETED']); }
            }
            DB::table('mo_label_pages')->where('fk_id_batch', $batchId)->delete();
            DB::table('fin_import_batches')->where('id', $batchId)->update(['file_hash' => 'UNDONE-' . $batchId]);   // the same PDF may be loaded again after an undo
            Audit::log('fin_import_batches', $batchId, 'DELETE', ['type' => 'CARRIER_LABEL_PDF', 'lines_unlinked' => $n], 'Label file undone', $ent);
            return $n;
        });
    }

    public static function page(int $ent): array
    {
        return ['set_aside' => DB::table('mo_label_pages as p')->leftJoin('t_branches as b', 'b.id', '=', 'p.fk_id_branch')->where('p.fk_id_ent', $ent)->where('p.status', 'SET_ASIDE')->orderByDesc('p.id')->limit(200)
                ->get(['p.id', 'p.fk_id_batch', 'p.page_no', 'p.fk_id_document', 'p.tracking_no', 'p.service_text', 'p.postcode_out', 'p.problem', 'p.candidates_json', 'p.fk_id_branch', 'b.shop_name as shop'])->map(function ($p) { $p->candidates = json_decode((string) $p->candidates_json, true) ?: []; unset($p->candidates_json); return $p; }),
            'batches' => DB::table('fin_import_batches')->where('company_id', $ent)->where('report_type', 'CARRIER_LABEL_PDF')->where('file_hash', 'not like', 'UNDONE-%')->orderByDesc('id')->limit(30)->get(['id', 'file_name', 'rows_total', 'rows_new', 'rows_error', 'imported_at']),
            'carriers' => DB::table('t_carriers')->whereIn('fk_id_ent', [0, $ent])->where('f_active', 1)->orderBy('carrier_name')->get(['id', 'carrier_name as name', 'label_pdf_layout', 'tracking_pattern']),
            'shops' => DB::table('t_branches')->where('fk_id_ent', $ent)->where('f_active', 1)->orderBy('shop_name')->get(['id', 'shop_name as name']),
            'can_undo' => in_array(Context::role(), ['OWNER', 'MANAGER'], true)];
    }

    /** The stored one-page label PDF (for printing it unchanged). */
    public static function file(int $ent, int $docId): array
    {
        $d = DB::table('sys_documents')->where('id', $docId)->where('fk_id_ent', $ent)->where('tags', 'label')->where('status', 'ACTIVE')->first() ?? throw new \InvalidArgumentException('Label not found.');
        return [Storage::disk('local')->get($d->stored_name), 'label_' . $d->doc_no . '.pdf'];
    }
}
