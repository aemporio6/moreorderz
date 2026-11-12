<?php

namespace App\Services;

use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Writer;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\DB;
use Picqer\Barcode\BarcodeGeneratorPNG;

/**
 * Universal printing (18_Labels §11 and §13).
 * What to print = template (kind + smallest size). Each printer = profile (what it can do) + media (what is loaded)
 * + calibration (small corrections). Routes decide which printer gets which job, with a backup printer.
 * One job = one PDF at the exact size of the loaded media, sent through the printer's adapter.
 * Adapter built now: DRIVER_PDF (the PDF opens on the computer and goes through the normal printer driver).
 */
class PrintService
{
    public const KINDS = ['PACKING_SLIP' => 'PARCEL', 'PICK_SHEET' => 'PICK_TASK', 'MANIFEST' => 'HANDOVER', 'LOCATION' => 'LOCATION', 'PO' => 'PO', 'TEST' => 'PRINTER'];
    public const KIND_NAMES = ['PACKING_SLIP' => 'Packing slip', 'PICK_SHEET' => 'Pick sheet', 'MANIFEST' => 'Handover sheet', 'LOCATION' => 'Bin labels', 'PO' => 'Purchase order', 'TEST' => 'Test page'];
    public const REASONS = ['DAMAGED_LABEL' => 'Label damaged', 'PRINTER_JAM' => 'Printer jam', 'WRONG_PRINTER' => 'Wrong printer', 'CARRIER_CHANGED' => 'Carrier changed',
        'ADDRESS_CHANGED' => 'Address changed', 'LOCATION_CHANGED' => 'Location changed', 'VOIDED_REPLACED' => 'Voided and replaced', 'LOST' => 'Lost', 'OTHER' => 'Other'];
    private const SCOPES = ['USER' => 1, 'WORKSTATION' => 2, 'LOCATION' => 3, 'COMPANY' => 4];

    /** Setup rows every install needs (rows, not logic): adapters on the two setup printers, templates, sheet layout, default routes. Safe to run again. */
    public static function seed(): void
    {
        $adapter = DB::table('print_adapters')->where('code', 'DRIVER_PDF')->value('id');
        DB::table('printer_profiles')->whereNull('fk_id_adapter')->update(['fk_id_adapter' => $adapter]);
        DB::table('printer_profiles')->where('paper_format', 'A4')->whereNull('max_print_length_mm')->update(['max_print_length_mm' => 297, 'min_media_width_mm' => 100, 'max_media_width_mm' => 216]);
        DB::table('printer_profiles')->where('code', 'EX_PR_THERMAL')->whereNull('max_print_length_mm')->update(['max_print_length_mm' => 300, 'min_media_width_mm' => 40, 'max_media_width_mm' => 110]);
        if (! DB::table('label_sheet_layouts')->where('code', 'A4_24UP')->exists()) {
            DB::table('label_sheet_layouts')->insert(['fk_id_ent' => 0, 'code' => 'A4_24UP', 'name' => 'A4 24-up 70 × 37 mm', 'paper_kind' => 'A4', 'cols' => 3, 'rows_per_sheet' => 8,
                'label_w_mm' => 70, 'label_h_mm' => 37, 'margin_top_mm' => 0.5, 'margin_left_mm' => 0, 'gap_x_mm' => 0, 'gap_y_mm' => 0, 'f_system' => 1, 'f_active' => 1]);
        }
        $sheet = DB::table('label_sheet_layouts')->where('code', 'A4_24UP')->value('id');
        $tpl = [   // code, name, kind, format, min width, min height, sheet layout
            ['PACKSLIP_FLOW', 'Packing slip (fits the loaded label or paper)', 'PACKING_SLIP', 'FLOW', 96, 136, null],
            ['PICK_A4', 'Pick sheet A4', 'PICK_SHEET', 'A4', 210, 297, null],
            ['MANIFEST_A4', 'Courier handover sheet A4', 'MANIFEST', 'A4', 210, 297, null],
            ['PO_A4', 'Purchase order A4', 'PO', 'A4', 210, 297, null],
            ['LOC_A4_24UP', 'Bin labels on A4 sheet, 24 per sheet', 'LOCATION', 'A4', 70, 37, $sheet],
            ['LOC_ROLL', 'Bin label on a roll, one per label', 'LOCATION', 'FLOW', 50, 25, null],
            ['TEST_PAGE', 'Printer test page (ruler, outline, barcodes)', 'TEST', 'FLOW', 40, 25, null],
        ];
        foreach ($tpl as [$code, $name, $kind, $format, $w, $h, $layout]) {
            if (DB::table('label_templates')->where('code', $code)->exists()) continue;
            DB::table('label_templates')->insert(['fk_id_ent' => 0, 'code' => $code, 'name' => $name, 'label_kind' => $kind, 'label_format' => $format, 'layout' => json_encode(['renderer' => $kind]),
                'copies_default' => 1, 'f_active' => 1, 'fk_id_sheet_layout' => $layout, 'width_mm' => $w, 'height_mm' => $h, 'orientation' => 'PORTRAIT', 'template_source' => 'BUILT', 'scale_rule' => 'FLOW']);
        }
        if (! DB::table('print_routes')->exists()) {
            $th = DB::table('printer_profiles')->where('code', 'SETUP_THERMAL_1')->value('id'); $a4 = DB::table('printer_profiles')->where('code', 'SETUP_A4_1')->value('id');
            $roll = DB::table('label_media')->where('code', 'SETUP_ROLL_100X150')->value('id'); $plain = DB::table('label_media')->where('code', 'SETUP_A4_PLAIN')->value('id');
            if ($th && $a4 && $roll && $plain) {
                foreach ([['PACKING_SLIP', $th, $a4, $roll], ['PICK_SHEET', $a4, null, $plain], ['MANIFEST', $a4, null, $plain], ['PO', $a4, null, $plain], ['LOCATION', $a4, null, $plain]] as [$k, $p, $b, $m]) {
                    DB::table('print_routes')->insert(['fk_id_ent' => 0, 'job_kind' => $k, 'scope' => 'COMPANY', 'fk_id_printer_profile' => $p, 'fk_id_backup_printer' => $b, 'fk_id_label_media' => $m, 'copies' => 1, 'f_active' => 1]);
                }
            }
        }
    }

    // ───────────────────────── routing ─────────────────────────

    /** The route for a job kind: user → workstation → location → company; a company's own row wins over the setup row. */
    public static function route(int $ent, string $kind, array $opt = []): ?object
    {
        $rows = DB::table('print_routes')->whereIn('fk_id_ent', [0, $ent])->where('job_kind', $kind)->where('f_active', 1)->get();
        $want = ['USER' => (string) ($opt['user'] ?? Context::userId()), 'WORKSTATION' => (string) ($opt['workstation'] ?? ''), 'LOCATION' => (string) ($opt['location'] ?? '')];
        $best = null; $bestRank = 99;
        foreach ($rows as $r) {
            $scope = $r->scope ?: 'COMPANY';
            if ($scope !== 'COMPANY' && ((string) $r->scope_value === '' || (string) $r->scope_value !== ($want[$scope] ?? null))) continue;
            $rank = (self::SCOPES[$scope] ?? 4) * 2 + ($r->fk_id_ent == $ent ? 0 : 1);
            if ($rank < $bestRank) { $best = $r; $bestRank = $rank; }
        }
        return $best;
    }

    public static function usable(?object $printer): bool
    {
        return $printer && (int) $printer->f_active === 1 && ($printer->printer_status ?: 'ACTIVE') === 'ACTIVE';
    }

    private static function printer(?int $id): ?object { return $id ? DB::table('printer_profiles')->where('id', $id)->first() : null; }

    /** Has this printer passed the test page with this media? */
    public static function tested(int $printerId, int $mediaId): bool
    {
        return (bool) DB::table('printer_calibrations')->where(['fk_id_printer_profile' => $printerId, 'fk_id_label_media' => $mediaId])->value('test_print_ok');
    }

    /** Page size for a job, after the fit check. Returns [width mm, height mm, rotated]. Throws with the reason when it cannot fit. */
    public static function fit(object $tpl, object $printer, object $media): array
    {
        $mw = (float) $media->width_mm; $mh = (float) $media->height_mm; $rot = false;
        $maxW = (float) ($printer->max_print_width_mm ?: 0); $maxL = (float) ($printer->max_print_length_mm ?: 0);
        $fitsPrinter = fn ($w, $h) => ($maxW == 0 || $w <= $maxW + 0.5) && ($maxL == 0 || $h <= $maxL + 0.5);
        if (! $fitsPrinter($mw, $mh)) {
            if ($fitsPrinter($mh, $mw)) { [$mw, $mh] = [$mh, $mw]; $rot = true; }
            else throw new \InvalidArgumentException("{$media->name} ({$media->width_mm} × {$media->height_mm} mm) is larger than {$printer->name} can print (" . ($maxW ?: '?') . ' × ' . ($maxL ?: '?') . ' mm). Choose another printer or paper for this route.');
        }
        if ($tpl->fk_id_sheet_layout) {
            $l = DB::table('label_sheet_layouts')->where('id', $tpl->fk_id_sheet_layout)->first();
            $needW = $l->margin_left_mm + $l->cols * $l->label_w_mm + ($l->cols - 1) * $l->gap_x_mm; $needH = $l->margin_top_mm + $l->rows_per_sheet * $l->label_h_mm + ($l->rows_per_sheet - 1) * $l->gap_y_mm;
        } else { $needW = (float) $tpl->width_mm; $needH = (float) $tpl->height_mm; }
        if ($needW > $mw + 0.5 || $needH > $mh + 0.5) {
            throw new \InvalidArgumentException("\"{$tpl->name}\" needs at least {$needW} × {$needH} mm but {$media->name} is {$media->width_mm} × {$media->height_mm} mm. Load larger paper or change the route.");
        }
        return [$mw, $mh, $rot];
    }

    /** Template of a kind that fits the media (sheet templates only on sheets, roll templates only on rolls when both exist). */
    private static function template(int $ent, string $kind, object $printer, object $media): array
    {
        $all = DB::table('label_templates')->whereIn('fk_id_ent', [0, $ent])->where('label_kind', $kind)->where('f_active', 1)->where('template_source', 'BUILT')->orderByDesc('fk_id_ent')->orderBy('id')->get();
        if ($all->isEmpty()) throw new \InvalidArgumentException('No template is set up for ' . (self::KIND_NAMES[$kind] ?? $kind) . '.');
        $sheetMedia = $media->media_kind === 'SHEET'; $why = null;
        foreach ($all->sortBy(fn ($t) => ((bool) $t->fk_id_sheet_layout === $sheetMedia) ? 0 : 1) as $t) {
            if ($t->fk_id_sheet_layout && ! $sheetMedia) continue;
            try { return [$t, self::fit($t, $printer, $media)]; } catch (\InvalidArgumentException $e) { $why ??= $e->getMessage(); }
        }
        throw new \InvalidArgumentException($why ?? 'No template of this kind fits the loaded paper.');
    }

    // ───────────────────────── printing ─────────────────────────

    /**
     * Print one job. $opt: copies, reason (needed when any of the records was printed before), printer (one-job switch), media,
     * use_backup (answer to the ASK question), trigger, start_cell (half-used label sheet), workstation.
     * Returns ['job','job_code','status','printer','file'] – or ['ask' => …] when the main printer is out and the setting says ask first.
     */
    public static function print(int $ent, string $kind, array $ids, array $opt = []): array
    {
        if (! isset(self::KINDS[$kind])) throw new \InvalidArgumentException("Unknown print kind $kind.");
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (! $ids) throw new \InvalidArgumentException('Nothing was chosen to print.');
        $copies = max(1, min(20, (int) ($opt['copies'] ?? 0)));
        $entityType = self::KINDS[$kind]; $user = (int) (Context::userId() ?? 0);

        // 1. route → printer (+ backup)
        $route = self::route($ent, $kind, $opt);
        $printer = self::printer($opt['printer'] ?? $route?->fk_id_printer_profile);
        $media = DB::table('label_media')->where('id', $opt['media'] ?? $route?->fk_id_label_media)->first();
        if (! $printer || ! $media) throw new \InvalidArgumentException('No printer is chosen for ' . self::KIND_NAMES[$kind] . ' yet. Open Settings › Printers and set the route.');
        if (! isset($opt['copies']) && $route?->copies) $copies = max(1, (int) $route->copies);
        $note = null;
        if (! self::usable($printer)) {
            $backup = self::printer($route?->fk_id_backup_printer); $mode = Choices::get('PRINT_FAILOVER', $ent);
            $state = $printer->f_active ? (['REPAIR' => 'in repair', 'OFFLINE' => 'offline', 'RETIRED' => 'retired'][$printer->printer_status] ?? strtolower($printer->printer_status)) : 'switched off';
            if ($mode !== 'OFF' && self::usable($backup) && $backup->id !== $printer->id) {
                if ($mode === 'ASK' && empty($opt['use_backup']) && empty($opt['auto'])) {
                    return ['ask' => ['printer' => $printer->name, 'state' => $state, 'backup_id' => $backup->id, 'backup' => $backup->name]];
                }
                $note = "{$printer->name} is $state – sent to the backup printer {$backup->name}";
                $printer = $backup;
                $media = self::mediaFor($ent, $printer, $media);
            } else {
                return self::fail($ent, $kind, $entityType, $ids, $printer, $copies, $opt, "{$printer->name} is $state and no backup printer is ready.");
            }
        }
        $adapter = DB::table('print_adapters')->where('id', $printer->fk_id_adapter)->value('code') ?: 'DRIVER_PDF';

        // 2. template + fit check
        [$tpl, [$w, $h, $rot]] = self::template($ent, $kind, $printer, $media);

        // 3. test page first (setting), reprint reason
        if ($kind !== 'TEST' && (int) Settings::get('PRINT_TEST_REQUIRED', $ent, 1) === 1 && ! self::tested($printer->id, $media->id)) {
            throw new \InvalidArgumentException("Print the test page on {$printer->name} with {$media->name} first (Settings › Printers › Test), then print again.", 22);
        }
        $reason = $opt['reason'] ?? null; $before = collect();
        if ($kind !== 'TEST') {
            $before = DB::table('label_print_log as g')->join('label_templates as t', 't.id', '=', 'g.fk_id_label_template')->where('t.label_kind', $kind)
                ->where('g.entity_type', $entityType)->whereIn('g.entity_id', $ids)->groupBy('g.entity_id')->selectRaw('g.entity_id, MAX(g.copy_no) as n')->pluck('n', 'entity_id');
            if ($before->isNotEmpty() && ! $reason) {
                throw new \InvalidArgumentException(count($ids) === 1 ? 'This was printed before. Choose a reason to print it again.' : $before->count() . ' of these were printed before. Choose a reason to print them again.', 21);
            }
            if ($reason && ! isset(self::REASONS[$reason])) throw new \InvalidArgumentException('Choose a reprint reason from the list.');
            if ($before->isEmpty()) $reason = null;
        }

        // 4. job row, PDF at exact size with the calibration, adapter
        $code = Numbering::next('PJ', $ent);
        $job = DB::table('print_jobs')->insertGetId(['fk_id_ent' => $ent, 'job_code' => $code, 'entity_type' => $entityType, 'entity_ids' => json_encode($ids), 'fk_id_label_template' => $tpl->id,
            'fk_id_printer_profile' => $printer->id, 'copies' => $copies, 'print_trigger' => $opt['trigger'] ?? 'MANUAL', 'status' => 'QUEUED', 'error_text' => $note ? mb_substr($note, 0, 255) : null,
            'workstation' => $opt['workstation'] ?? null, 'requested_by' => $user, 'requested_at' => now()]);
        if ($adapter !== 'DRIVER_PDF' && ! in_array($adapter, PrintAdapters::DIRECT, true)) {
            return self::failJob($ent, $job, $printer, "Adapter $adapter cannot print by itself: use its TSPL / ZPL / EPL emulation, or \"PDF through the printer driver\".");
        }
        try {
            $cal = DB::table('printer_calibrations')->where(['fk_id_printer_profile' => $printer->id, 'fk_id_label_media' => $media->id])->first();
            $html = self::html($ent, $kind, $ids, $tpl, $w, $h, $copies, $cal, ['printer' => $printer, 'media' => $media, 'start_cell' => (int) ($opt['start_cell'] ?? 1), 'job_code' => $code]);
            $pdf = self::pdf($html, $w, $h);
            $dir = storage_path('app/print'); if (! is_dir($dir)) mkdir($dir, 0775, true);
            file_put_contents("$dir/$code.pdf", $pdf);
        } catch (\InvalidArgumentException $e) {
            DB::table('print_jobs')->where('id', $job)->update(['status' => 'CANCELLED', 'error_text' => mb_substr($e->getMessage(), 0, 255)]);
            throw $e;
        } catch (\Throwable $e) {
            return self::failJob($ent, $job, $printer, 'The PDF could not be made: ' . $e->getMessage());
        }

        // 5. one-click adapters send the PDF themselves; DRIVER_PDF hands it to the browser (its "sent")
        $file = "print/$code.pdf"; $status = 'PRINTED';
        if ($adapter !== 'DRIVER_PDF') {
            try {
                $how = PrintAdapters::send($adapter, $printer, storage_path("app/$file"), $copies, $job, (float) $w, (float) $h);
                if ($adapter === 'PRINT_AGENT') $status = 'AGENT';
                $note = trim(($note ? $note . '. ' : '') . $how);
            } catch (\RuntimeException $e) {
                DB::table('print_jobs')->where('id', $job)->update(['file_ref' => $file]);
                return self::failJob($ent, $job, $printer, $e->getMessage());
            }
        }
        DB::table('print_jobs')->where('id', $job)->update(['status' => $status, 'file_ref' => $file, 'printed_at' => $status === 'PRINTED' ? now() : null, 'error_text' => $note ? mb_substr($note, 0, 255) : null]);
        if ($kind !== 'TEST') {
            $rows = [];
            foreach ($ids as $id) for ($c = 1; $c <= $copies; $c++) {
                $rows[] = ['fk_id_label_template' => $tpl->id, 'fk_id_printer_profile' => $printer->id, 'copy_no' => (int) ($before[$id] ?? 0) + $c, 'reprint_reason' => isset($before[$id]) ? $reason : null,
                    'label_file' => $file, 'printed_by' => $user, 'printed_at' => now(), 'entity_type' => $entityType, 'entity_id' => $id, 'fk_id_print_job' => $job, 'fk_id_parcel' => $entityType === 'PARCEL' ? $id : null];
            }
            foreach (array_chunk($rows, 500) as $chunk) DB::table('label_print_log')->insert($chunk);
            EventBus::fire($before->isNotEmpty() ? 'LABEL_REPRINTED' : 'LABEL_PRINTED', 'print_jobs', $job, ['kind' => $kind, 'count' => count($ids), 'copies' => $copies, 'reason' => $reason], $ent);
            TaskEngine::close('WH_PRINT_FAILED', [$printer->id, date('Y-m-d')], 'A later job on this printer printed');
        }
        return ['job' => $job, 'job_code' => $code, 'status' => $status, 'adapter' => $adapter, 'printer' => $printer->name, 'media' => $media->name, 'file' => $file, 'note' => $note,
            'size_mm' => [$w, $h], 'rotated' => $rot, 'labels' => count($ids) * $copies];
    }

    /** Automatic print after an action (setting on / off). Never stops the action: a printer that is not ready or not tested is skipped. */
    public static function auto(int $ent, string $setting, string $kind, array $ids, string $trigger): ?array
    {
        if (! $ids || strtoupper((string) Settings::get($setting, $ent, 'N')) !== 'Y') return null;
        try { return self::print($ent, $kind, $ids, ['trigger' => $trigger, 'auto' => true]); }
        catch (\InvalidArgumentException) { return null; }
    }

    /** Media to use on the backup printer: the same if it fits, else the first media the backup has passed a test with, else the first that fits. */
    private static function mediaFor(int $ent, object $printer, object $media): object
    {
        $fits = fn ($m) => (! $printer->max_print_width_mm || min($m->width_mm, $m->height_mm) <= $printer->max_print_width_mm + 0.5)
            && (! $printer->min_media_width_mm || $m->width_mm >= $printer->min_media_width_mm - 0.5) && (! $printer->max_media_width_mm || $m->width_mm <= $printer->max_media_width_mm + 0.5);
        if ($fits($media) && self::tested($printer->id, $media->id)) return $media;
        $all = DB::table('label_media')->whereIn('fk_id_ent', [0, $ent])->where('f_active', 1)->orderBy('id')->get()->filter($fits);
        return $all->first(fn ($m) => self::tested($printer->id, $m->id)) ?? ($fits($media) ? $media : ($all->first() ?? $media));
    }

    private static function fail(int $ent, string $kind, string $entityType, array $ids, object $printer, int $copies, array $opt, string $why): array
    {
        $tpl = DB::table('label_templates')->where('label_kind', $kind)->where('f_active', 1)->value('id') ?? 0;
        $job = DB::table('print_jobs')->insertGetId(['fk_id_ent' => $ent, 'job_code' => Numbering::next('PJ', $ent), 'entity_type' => $entityType, 'entity_ids' => json_encode($ids), 'fk_id_label_template' => $tpl,
            'fk_id_printer_profile' => $printer->id, 'copies' => $copies, 'print_trigger' => $opt['trigger'] ?? 'MANUAL', 'status' => 'QUEUED', 'requested_by' => (int) (Context::userId() ?? 0), 'requested_at' => now()]);
        return self::failJob($ent, $job, $printer, $why);
    }

    /** FAILED: no log row, event PRINT_FAILED, task WH_PRINT_FAILED (one per printer per day). */
    public static function failJob(int $ent, int $job, object $printer, string $why): array
    {
        DB::table('print_jobs')->where('id', $job)->update(['status' => 'FAILED', 'error_text' => mb_substr($why, 0, 255)]);
        $j = DB::table('print_jobs')->where('id', $job)->first();
        EventBus::fire('PRINT_FAILED', 'print_jobs', $job, ['printer' => $printer->id, 'why' => $why], $ent);
        TaskEngine::open('WH_PRINT_FAILED', [$printer->id, date('Y-m-d')], "Print failed on {$printer->name}: " . mb_substr($why, 0, 150), ['ent' => $ent, 'module' => 'print_jobs', 'record_id' => $job, 'fix_url' => '#/print-queue', 'owner_user_id' => $j->requested_by ?: null]);
        return ['job' => $job, 'job_code' => $j->job_code, 'status' => 'FAILED', 'error' => $why, 'printer' => $printer->name];
    }

    /** The user says the paper did not come out right. The job turns FAILED; with a ready backup the same job is printed there. */
    public static function reportProblem(int $ent, int $jobId, string $what): array
    {
        $j = DB::table('print_jobs')->where('id', $jobId)->where('fk_id_ent', $ent)->first() ?? throw new \InvalidArgumentException('Print job not found.');
        if ($j->status !== 'PRINTED') throw new \InvalidArgumentException('Only a printed job can be reported.');
        $printer = self::printer($j->fk_id_printer_profile);
        $out = self::failJob($ent, $jobId, $printer, $what ?: 'Reported: did not print correctly');
        $kind = DB::table('label_templates')->where('id', $j->fk_id_label_template)->value('label_kind');
        $route = self::route($ent, $kind); $backup = self::printer($route?->fk_id_backup_printer);
        if ($kind !== 'TEST' && self::usable($backup) && $backup->id !== $printer->id && Choices::get('PRINT_FAILOVER', $ent) !== 'OFF') {
            $out['backup'] = ['id' => $backup->id, 'name' => $backup->name];
        }
        return $out;
    }

    /** Print a job again (reason every time). $printerId sends it to another printer for this one job. */
    public static function reprint(int $ent, int $jobId, string $reason, ?int $printerId = null): array
    {
        $j = DB::table('print_jobs')->where('id', $jobId)->where('fk_id_ent', $ent)->first() ?? throw new \InvalidArgumentException('Print job not found.');
        $kind = DB::table('label_templates')->where('id', $j->fk_id_label_template)->value('label_kind');
        $opt = ['copies' => $j->copies, 'reason' => $reason, 'use_backup' => true];
        if ($printerId) { $opt['printer'] = $printerId; $p = self::printer($printerId); $m = DB::table('label_media')->where('id', self::route($ent, $kind)?->fk_id_label_media)->first(); if ($p && $m) $opt['media'] = self::mediaFor($ent, $p, $m)->id; }
        return self::print($ent, $kind, json_decode($j->entity_ids, true), $opt);
    }

    // ───────────────────────── printers ─────────────────────────

    /** Test page: ruler, outline, Code 128 and QR at the exact size of the media. */
    public static function testPage(int $ent, int $printerId, int $mediaId): array
    {
        $p = self::printer($printerId) ?? throw new \InvalidArgumentException('Printer not found.');
        $out = self::print($ent, 'TEST', [$printerId], ['printer' => $printerId, 'media' => $mediaId, 'use_backup' => false]);
        DB::table('printer_profiles')->where('id', $printerId)->update(['last_test_print_at' => now()]);
        return $out;
    }

    /** Result of the test page and the small corrections. Scale is limited by PRINT_SCALE_LIMIT_PCT. */
    public static function calibrate(int $ent, int $printerId, int $mediaId, bool $ok, array $c = []): void
    {
        $limit = (float) Settings::get('PRINT_SCALE_LIMIT_PCT', $ent, 3); $scale = (float) ($c['scale_pct'] ?? 100);
        if (abs($scale - 100) > $limit + 0.0001) throw new \InvalidArgumentException("Scale can be corrected by at most ± $limit %. A larger difference means the wrong paper size is chosen.");
        $rot = (int) ($c['rotate_deg'] ?? 0);
        if (! in_array($rot, [0, 180], true)) throw new \InvalidArgumentException('Rotation can be 0 or 180 degrees. Turning by 90 is done by the fit check.');
        foreach (['offset_x_mm', 'offset_y_mm'] as $k) if (abs((float) ($c[$k] ?? 0)) > 10) throw new \InvalidArgumentException('An offset can be at most 10 mm.');
        $key = ['fk_id_printer_profile' => $printerId, 'fk_id_label_media' => $mediaId];
        $row = ['offset_x_mm' => (float) ($c['offset_x_mm'] ?? 0), 'offset_y_mm' => (float) ($c['offset_y_mm'] ?? 0), 'scale_pct' => $scale, 'rotate_deg' => $rot, 'test_print_ok' => $ok ? 1 : 0, 'calibrated_by' => Context::userId(), 'calibrated_at' => now()];
        DB::table('printer_calibrations')->updateOrInsert($key, $row);
        DB::table('printer_profiles')->where('id', $printerId)->update(['last_test_print_ok' => $ok ? 1 : 0, 'last_test_print_at' => now()]);
        EventBus::fire('PRINTER_TESTED', 'printer_profiles', $printerId, ['media' => $mediaId, 'ok' => $ok], $ent);
    }

    /** "Replace printer": every route of the old printer (main and backup) moves to the new one in one step; the old one is retired. */
    public static function replace(int $ent, int $oldId, int $newId): int
    {
        if ($oldId === $newId) throw new \InvalidArgumentException('Choose a different printer as the replacement.');
        $old = self::printer($oldId); $new = self::printer($newId);
        if (! $old || ! $new) throw new \InvalidArgumentException('Printer not found.');
        if (! self::usable($new)) throw new \InvalidArgumentException("{$new->name} is not active.");
        return DB::transaction(function () use ($ent, $old, $new) {
            $n = 0;
            foreach (DB::table('print_routes')->whereIn('fk_id_ent', [0, $ent])->where(fn ($q) => $q->where('fk_id_printer_profile', $old->id)->orWhere('fk_id_backup_printer', $old->id))->get() as $r) {
                $main = $r->fk_id_printer_profile == $old->id ? $new->id : $r->fk_id_printer_profile; $backup = $r->fk_id_backup_printer == $old->id ? $new->id : $r->fk_id_backup_printer;
                if ($backup == $main) $backup = null;
                $m = DB::table('label_media')->where('id', $r->fk_id_label_media)->first();
                DB::table('print_routes')->where('id', $r->id)->update(['fk_id_printer_profile' => $main, 'fk_id_backup_printer' => $backup, 'fk_id_label_media' => $m && $main == $new->id ? self::mediaFor($ent, $new, $m)->id : $r->fk_id_label_media]);
                $n++;
            }
            DB::table('printer_profiles')->where('id', $old->id)->update(['printer_status' => 'RETIRED', 'fk_id_replaced_by' => $new->id]);
            Audit::log('printer_profiles', $old->id, 'UPDATE', ['replaced_by' => $new->code, 'routes_moved' => $n], null, $ent);
            EventBus::fire('PRINTER_REPLACED', 'printer_profiles', $old->id, ['new' => $new->id, 'routes' => $n], $ent);
            return $n;
        });
    }

    // ───────────────────────── PDF ─────────────────────────

    public static function pdf(string $html, float $wMm, float $hMm): string
    {
        $dir = storage_path('app/print/fonts'); if (! is_dir($dir)) mkdir($dir, 0775, true);
        $o = new Options(); $o->setIsRemoteEnabled(false); $o->setIsPhpEnabled(false); $o->setDefaultFont('DejaVu Sans'); $o->setFontDir($dir); $o->setFontCache($dir); $o->setTempDir($dir); $o->setDpi(203);
        $d = new Dompdf($o);
        $d->setPaper([0, 0, $wMm * 72 / 25.4, $hMm * 72 / 25.4]);
        $d->loadHtml($html, 'UTF-8'); $d->render();
        $out = $d->output();
        if (! str_starts_with($out, '%PDF')) throw new \RuntimeException('PDF maker returned no PDF.');
        return $out;
    }

    public static function barcode(string $value, int $heightPx = 60, int $bar = 2): string
    {
        return 'data:image/png;base64,' . base64_encode((new BarcodeGeneratorPNG())->getBarcode($value, BarcodeGeneratorPNG::TYPE_CODE_128, $bar, $heightPx));
    }

    public static function qr(string $value, int $px = 240): string
    {
        return 'data:image/png;base64,' . base64_encode((new Writer(new GDLibRenderer($px, 2)))->writeString($value));
    }

    private static function e(mixed $v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

    private static function html(int $ent, string $kind, array $ids, object $tpl, float $w, float $h, int $copies, ?object $cal, array $x): string
    {
        $pages = match ($kind) {
            'TEST' => [self::pTest($w, $h, $x)],
            'PICK_SHEET' => array_map(fn ($id) => self::pPick($ent, $id), $ids),
            'PACKING_SLIP' => array_map(fn ($id) => self::pSlip($ent, $id, $w), $ids),
            'MANIFEST' => array_map(fn ($id) => self::pManifest($ent, $id), $ids),
            'PO' => array_map(fn ($id) => self::pPo($ent, $id), $ids),
            'LOCATION' => self::pBins($ent, $ids, $tpl, $w, $h, $copies, $x['start_cell']),
        };
        if ($kind !== 'LOCATION' && $copies > 1) { $one = $pages; for ($c = 1; $c < $copies; $c++) $pages = array_merge($pages, $one); }
        $ox = (float) ($cal->offset_x_mm ?? 0); $oy = (float) ($cal->offset_y_mm ?? 0); $sc = (float) ($cal->scale_pct ?? 100) / 100; $rot = (int) ($cal->rotate_deg ?? 0);
        $tf = []; if (abs($sc - 1) > 0.0001) $tf[] = "scale($sc)"; if ($rot === 180) $tf[] = 'rotate(180deg)';
        $style = "position:absolute; left:{$ox}mm; top:{$oy}mm; width:{$w}mm; height:{$h}mm; overflow:hidden;" . ($tf ? ' transform:' . implode(' ', $tf) . '; transform-origin:' . ($rot === 180 ? 'center' : 'top left') . ';' : '');
        $small = $w < 150; $fs = $small ? 8 : 10;
        $css = "@page{margin:0} *{box-sizing:border-box} body{margin:0;font-family:'DejaVu Sans',sans-serif;font-size:{$fs}pt;color:#000}
            .pg{position:relative;width:{$w}mm;height:{$h}mm;page-break-after:always} .pg.last{page-break-after:auto} .in{padding:" . ($small ? 3 : 10) . "mm}
            h1{font-size:" . ($small ? 12 : 16) . "pt;margin:0 0 2mm} .mut{color:#444;font-size:" . ($fs - 1) . "pt} table.t{width:100%;border-collapse:collapse;margin-top:3mm}
            table.t th{text-align:left;border-bottom:0.4mm solid #000;padding:1mm;font-size:" . ($fs - 1) . "pt} table.t td{border-bottom:0.15mm solid #999;padding:1mm;vertical-align:top}
            .r{text-align:right} .c{text-align:center} .box{display:inline-block;width:4mm;height:4mm;border:0.3mm solid #000} .big{font-weight:bold}";
        $body = '';
        foreach (array_values($pages) as $i => $p) $body .= '<div class="pg' . ($i === count($pages) - 1 ? ' last' : '') . '"><div style="' . $style . '">' . $p . '</div></div>';
        return "<!doctype html><html><head><meta charset=\"utf-8\"><style>$css</style></head><body>$body</body></html>";
    }

    private static function pTest(float $w, float $h, array $x): string
    {
        $p = $x['printer']; $m = $x['media']; $o = '<div style="position:absolute;left:1mm;top:1mm;width:' . ($w - 2) . 'mm;height:' . ($h - 2) . 'mm;border:0.3mm solid #000"></div>';
        for ($i = 10; $i < $w - 2; $i += 10) $o .= '<div style="position:absolute;left:' . $i . 'mm;top:1mm;width:0.2mm;height:' . ($i % 50 === 0 ? 5 : 3) . 'mm;background:#000"></div><div style="position:absolute;left:' . ($i - 2) . 'mm;top:6mm;width:6mm;font-size:5pt">' . $i . '</div>';
        for ($i = 10; $i < $h - 2; $i += 10) $o .= '<div style="position:absolute;top:' . $i . 'mm;left:1mm;height:0.2mm;width:' . ($i % 50 === 0 ? 5 : 3) . 'mm;background:#000"></div><div style="position:absolute;top:' . ($i - 1.2) . 'mm;left:6.5mm;font-size:5pt">' . $i . '</div>';
        $bw = min(60, $w - 26); $q = min(22, $h / 4);
        $o .= '<div style="position:absolute;left:13mm;top:11mm;width:' . ($w - 18) . 'mm"><div class="big" style="font-size:9pt">Test page – ' . self::e($p->name) . '</div>'
            . '<div class="mut">' . self::e($m->name) . ' · ' . rtrim(rtrim(number_format($w, 1), '0'), '.') . ' × ' . rtrim(rtrim(number_format($h, 1), '0'), '.') . ' mm · ' . date('d M Y H:i') . '</div>'
            . '<div style="margin-top:3mm"><img src="' . self::barcode('TEST-' . $p->code) . '" style="width:' . $bw . 'mm;height:10mm"><div style="font-size:6pt">TEST-' . self::e($p->code) . '</div></div>'
            . '<div style="margin-top:2mm"><img src="' . self::qr('TEST-' . $p->code . '-QR') . '" style="width:' . $q . 'mm;height:' . $q . 'mm"></div>'
            . '<div class="mut" style="margin-top:2mm">Check: 1) the outline is whole on all four sides · 2) the ruler matches a real ruler · 3) both codes scan.</div></div>';
        return $o;
    }

    private static function head(int $ent, string $title, string $code): string
    {
        $c = DB::table('t_enterprise')->where('id', $ent)->first(['ent_name']);
        return '<table style="width:100%"><tr><td><h1>' . self::e($title) . '</h1><div class="mut">' . self::e($c->ent_name ?? '') . ' · printed ' . date('d M Y H:i') . '</div></td>'
            . '<td class="r" style="width:70mm"><img src="' . self::barcode($code) . '" style="width:62mm;height:11mm"><div style="font-size:8pt">' . self::e($code) . '</div></td></tr></table>';
    }

    private static function pPick(int $ent, int $id): string
    {
        $t = DB::table('wh_pick_tasks as t')->leftJoin('t_carriers as c', 'c.id', '=', 't.fk_id_carrier')->leftJoin('t_users as u', 'u.id', '=', 't.assigned_to')->where('t.id', $id)->where('t.fk_id_ent', $ent)
            ->first(['t.task_code', 't.status', 'c.carrier_name', 'c.collection_cutoff_time', 'u.display_name']) ?? throw new \InvalidArgumentException('Pick list not found.');
        $lines = DB::table('wh_pick_task_lines as l')->join('t_product_vendors as p', 'p.id', '=', 'l.fk_id_product')->leftJoin('cat_inventory_locations as b', 'b.id', '=', 'l.fk_id_from_location')->leftJoin('t_sales as s', 's.id', '=', 'l.fk_id_sale')
            ->where('l.fk_id_pick_task', $id)->orderBy('l.line_no')->get(['l.line_no', 'b.location_name as bin', 'p.SKU_VENDOR as sku', 'p.product_name_vendor as name', 'l.qty', 'l.slot_no', 's.order_no']);
        $o = '<div class="in">' . self::head($ent, 'Pick sheet', $t->task_code) . '<div class="mut" style="margin-top:2mm">Picker: ' . self::e($t->display_name ?: '________________') . ($t->carrier_name ? ' · Courier: ' . self::e($t->carrier_name) : '') . ($t->collection_cutoff_time ? ' · Cut-off: ' . self::e(substr($t->collection_cutoff_time, 0, 5)) : '') . ' · ' . count($lines) . ' lines · ' . $lines->sum('qty') . ' units</div>'
            . '<table class="t"><tr><th style="width:8mm">#</th><th style="width:30mm">Bin</th><th style="width:36mm">SKU</th><th>Product</th><th class="r" style="width:14mm">Qty</th><th style="width:34mm">Order</th><th class="c" style="width:12mm">Done</th></tr>';
        foreach ($lines as $l) $o .= '<tr><td>' . $l->line_no . '</td><td class="big">' . self::e($l->bin) . '</td><td>' . self::e($l->sku) . '</td><td>' . self::e(mb_substr((string) $l->name, 0, 60)) . '</td><td class="r big">' . (int) $l->qty . '</td><td>' . self::e($l->order_no) . ($l->slot_no ? ' · slot ' . $l->slot_no : '') . '</td><td class="c"><span class="box"></span></td></tr>';
        return $o . '</table></div>';
    }

    private static function pSlip(int $ent, int $id, float $w): string
    {
        $p = DB::table('wh_parcels as p')->leftJoin('t_branches as b', 'b.id', '=', 'p.fk_id_branch')->leftJoin('t_carriers as c', 'c.id', '=', 'p.fk_id_carrier')->where('p.id', $id)->where('p.fk_id_ent', $ent)
            ->first(['p.id', 'p.parcel_code', 'p.order_no', 'p.tracking_no', 'p.status', 'p.label_status', 'b.shop_name', 'c.carrier_name']) ?? throw new \InvalidArgumentException('Parcel not found.');
        if ($p->status === 'VOID' || $p->label_status === 'VOIDED') throw new \InvalidArgumentException("Parcel {$p->parcel_code} is voided – it cannot be printed.");
        $lines = DB::table('wh_parcel_lines as l')->join('t_product_vendors as v', 'v.id', '=', 'l.fk_id_product')->leftJoin('t_sales as s', 's.id', '=', 'l.fk_id_sale')->where('l.fk_id_parcel', $id)
            ->get(['v.SKU_VENDOR as sku', 'v.product_name_vendor as name', 'l.qty_packed', 'l.qty', 's.d_order', 's.cust_name', 's.ship_to_address', 's.ship_to_city', 's.ship_to_postcode']);
        $f = $lines->first(); $small = $w < 150; $bw = $small ? min(70, $w - 12) : 62;
        $o = '<div class="in"><h1>Packing slip</h1><div class="big">' . self::e($p->shop_name) . '</div><div class="mut">Order ' . self::e($p->order_no) . ($f && $f->d_order ? ' · ' . date('d M Y', strtotime($f->d_order)) : '')
            . ($p->carrier_name ? ' · ' . self::e($p->carrier_name) : '') . '</div>';
        if (strtoupper((string) Settings::get('WH_PACKSLIP_SHOW_ADDRESS', $ent, 'N')) === 'Y' && $f) {   // address only when the setting says so; never written to the print log
            $o .= '<div style="margin-top:2mm;border:0.2mm solid #000;padding:1.5mm">' . self::e($f->cust_name) . '<br>' . self::e($f->ship_to_address) . '<br>' . self::e(trim($f->ship_to_city . ' ' . $f->ship_to_postcode)) . '</div>';
        }
        $o .= '<table class="t"><tr><th>SKU</th><th>Product</th><th class="r">Qty</th></tr>';
        foreach ($lines as $l) $o .= '<tr><td>' . self::e($l->sku) . '</td><td>' . self::e(mb_substr((string) $l->name, 0, $small ? 34 : 70)) . '</td><td class="r big">' . (int) ($l->qty_packed ?: $l->qty) . '</td></tr>';
        $c = DB::table('t_enterprise')->where('id', $ent)->first(['ent_name', 'ent_address']);
        return $o . '</table><div style="margin-top:4mm" class="c"><img src="' . self::barcode($p->parcel_code) . '" style="width:' . $bw . 'mm;height:11mm"><div style="font-size:7pt">' . self::e($p->parcel_code) . '</div></div>'
            . '<div class="mut" style="margin-top:3mm">Thank you for your order. If something is wrong, please contact us through the shop where you ordered before sending anything back.'
            . ($c && $c->ent_name ? '<br>' . self::e($c->ent_name) : '') . '</div></div>';
    }

    private static function pManifest(int $ent, int $id): string
    {
        $s = DB::table('wh_handover_sessions as s')->leftJoin('t_carriers as c', 'c.id', '=', 's.fk_id_carrier')->leftJoin('t_users as u', 'u.id', '=', 's.opened_by')->where('s.id', $id)->where('s.fk_id_ent', $ent)
            ->first(['s.session_code', 's.handover_mode', 's.status', 's.scanned_count', 's.exception_count', 's.driver_name', 's.opened_at', 's.closed_at', 'c.carrier_name', 'u.display_name']) ?? throw new \InvalidArgumentException('Handover not found.');
        $parcels = DB::table('wh_parcels')->where('fk_id_handover_session', $id)->orderBy('id')->get(['parcel_code', 'order_no', 'tracking_no', 'weight_g']);
        $o = '<div class="in">' . self::head($ent, 'Courier handover sheet', $s->session_code) . '<div class="mut" style="margin-top:2mm">Courier: ' . self::e($s->carrier_name) . ' · ' . self::e($s->handover_mode ?: 'COLLECTION') . ' · ' . date('d M Y H:i', strtotime($s->closed_at ?: $s->opened_at))
            . ' · Parcels: <span class="big">' . count($parcels) . '</span> · Problems at scan: ' . (int) $s->exception_count . ' · Staff: ' . self::e($s->display_name) . '</div>'
            . '<table class="t"><tr><th style="width:10mm">#</th><th>Parcel</th><th>Order</th><th>Tracking</th><th class="r">Weight</th></tr>';
        foreach ($parcels as $i => $p) $o .= '<tr><td>' . ($i + 1) . '</td><td>' . self::e($p->parcel_code) . '</td><td>' . self::e($p->order_no) . '</td><td>' . self::e($p->tracking_no) . '</td><td class="r">' . ($p->weight_g ? number_format($p->weight_g / 1000, 2) . ' kg' : '') . '</td></tr>';
        return $o . '</table><table style="width:100%;margin-top:14mm"><tr><td>Driver name: ' . self::e($s->driver_name ?: '______________________') . '</td><td>Signature: ______________________</td><td class="r">Parcels taken: ________</td></tr></table></div>';
    }

    private static function pPo(int $ent, int $id): string
    {
        $po = DB::table('t_src_po as p')->leftJoin('t_src_suppliers as s', 's.id', '=', 'p.fk_id_supplier')->leftJoin('cat_currency as c', 'c.id', '=', 'p.fk_id_currency')->where('p.id', $id)->where('p.fk_id_ent', $ent)
            ->first(['p.id', 'p.po_number', 'p.po_code', 'p.po_date', 'p.status', 'p.po_total', 'p.deposit_pct', 'p.required_ship_date', 'p.notes', 's.supplier_name', 'c.currency_code']) ?? throw new \InvalidArgumentException('Purchase order not found.');
        $no = $po->po_number ?: $po->po_code ?: ('PO-' . $po->id); $cur = $po->currency_code ?: '';
        $lines = DB::table('t_src_po_lines as l')->leftJoin('t_product_vendors as v', 'v.id', '=', 'l.fk_id_product')->where('l.fk_id_po', $id)->where('l.f_active', 1)->orderBy('l.id')->get(['l.sku', 'v.product_name_vendor as name', 'l.qty_confirmed', 'l.qty_ordered', 'l.unit_price', 'l.line_total']);
        $c = DB::table('t_enterprise')->where('id', $ent)->first(['ent_name', 'ent_address']);
        $o = '<div class="in">' . self::head($ent, 'Purchase order', $no) . '<table style="width:100%;margin-top:4mm"><tr><td style="width:50%;vertical-align:top"><div class="mut">Buyer</div><div class="big">' . self::e($c->ent_name ?? '') . '</div>' . nl2br(self::e($c->ent_address ?? '')) . '</td>'
            . '<td style="vertical-align:top"><div class="mut">Supplier</div><div class="big">' . self::e($po->supplier_name) . '</div><div class="mut">Date: ' . ($po->po_date ? date('d M Y', strtotime($po->po_date)) : '') . ($po->required_ship_date ? ' · Ship by: ' . date('d M Y', strtotime($po->required_ship_date)) : '') . ' · Status: ' . self::e($po->status) . '</div></td></tr></table>'
            . '<table class="t"><tr><th style="width:8mm">#</th><th style="width:38mm">SKU</th><th>Product</th><th class="r" style="width:18mm">Qty</th><th class="r" style="width:26mm">Unit price</th><th class="r" style="width:30mm">Total</th></tr>';
        foreach ($lines as $i => $l) $o .= '<tr><td>' . ($i + 1) . '</td><td>' . self::e($l->sku) . '</td><td>' . self::e(mb_substr((string) $l->name, 0, 70)) . '</td><td class="r">' . number_format((float) ($l->qty_confirmed ?: $l->qty_ordered)) . '</td><td class="r">' . number_format((float) $l->unit_price, 4) . '</td><td class="r">' . number_format((float) $l->line_total, 2) . '</td></tr>';
        $o .= '<tr><td colspan="5" class="r big">Total ' . self::e($cur) . '</td><td class="r big">' . number_format((float) $po->po_total, 2) . '</td></tr></table>';
        $terms = DB::table('t_src_payment_terms')->where('fk_id_po', $id)->where('f_active', 1)->orderBy('term_no')->get(['milestone', 'pct', 'planned_amount', 'due_date']);
        if ($terms->isNotEmpty()) {
            $o .= '<div class="big" style="margin-top:5mm">Payment terms</div><table class="t" style="margin-top:1mm"><tr><th>Step</th><th class="r">%</th><th class="r">Amount ' . self::e($cur) . '</th><th>Due</th></tr>';
            foreach ($terms as $t) $o .= '<tr><td>' . self::e($t->milestone) . '</td><td class="r">' . (float) $t->pct . '</td><td class="r">' . number_format((float) $t->planned_amount, 2) . '</td><td>' . ($t->due_date ? date('d M Y', strtotime($t->due_date)) : '') . '</td></tr>';
            $o .= '</table>';
        }
        return $o . ($po->notes ? '<div style="margin-top:4mm"><span class="mut">Notes:</span> ' . nl2br(self::e($po->notes)) . '</div>' : '') . '</div>';
    }

    /** Bin labels: on a label sheet (cells, start cell on a half-used sheet) or one per roll label. Returns the pages. */
    private static function pBins(int $ent, array $ids, object $tpl, float $w, float $h, int $copies, int $startCell): array
    {
        $bins = DB::table('cat_inventory_locations as b')->leftJoin('cat_inventory_locations as z', 'z.id', '=', 'b.fk_id_parent')->whereIn('b.id', $ids)->where('b.fk_id_ent', $ent)->orderBy('b.pick_seq')->orderBy('b.location_name')
            ->get(['b.id', 'b.location_name', 'b.location_code', 'b.barcode_value', 'z.location_name as parent']);
        if ($bins->isEmpty()) throw new \InvalidArgumentException('No bins found to print.');
        $prefix = (string) Settings::get('WH_BIN_BARCODE_PREFIX', $ent, 'L:'); $qr = strtoupper((string) Settings::get('WH_BIN_LABEL_QR', $ent, 'N')) === 'Y';
        $cell = function (object $b, float $cw, float $ch) use ($prefix, $qr) {
            $code = $b->location_code ?: $b->location_name; $bv = (string) ($b->barcode_value ?: $code); $val = $prefix !== '' && str_starts_with($bv, $prefix) ? $bv : $prefix . $bv;
            $fs = max(9, min(30, $ch * 0.9, $cw * 2.4 / max(4, mb_strlen($code)) * 2.2)); $bh = max(7, min(16, $ch * 0.3)); $bw = min($cw - 8, 80) - ($qr ? $bh + 2 : 0);
            return '<div style="padding:1.5mm 3mm;text-align:center"><div style="font-size:' . round($fs, 1) . 'pt;font-weight:bold;line-height:1.05">' . self::e($code) . '</div>'
                . ($b->parent ? '<div style="font-size:6pt;color:#333">' . self::e($b->parent) . '</div>' : '')
                . '<div style="margin-top:1mm"><img src="' . self::barcode($val, 50, 2) . '" style="width:' . $bw . 'mm;height:' . $bh . 'mm">' . ($qr ? ' <img src="' . self::qr($val, 160) . '" style="width:' . $bh . 'mm;height:' . $bh . 'mm">' : '') . '</div>'
                . '<div style="font-size:5.5pt">' . self::e($val) . '</div></div>';
        };
        $list = []; foreach ($bins as $b) for ($c = 0; $c < $copies; $c++) $list[] = $b;
        if (! $tpl->fk_id_sheet_layout) return array_map(fn ($b) => $cell($b, $w, $h), $list);
        $l = DB::table('label_sheet_layouts')->where('id', $tpl->fk_id_sheet_layout)->first(); $per = $l->cols * $l->rows_per_sheet;
        $pos = max(1, min($per, $startCell)) - 1; $pages = []; $page = '';
        foreach ($list as $b) {
            $col = $pos % $l->cols; $row = intdiv($pos, $l->cols);
            $x = $l->margin_left_mm + $col * ($l->label_w_mm + $l->gap_x_mm); $y = $l->margin_top_mm + $row * ($l->label_h_mm + $l->gap_y_mm);
            $page .= '<div style="position:absolute;left:' . $x . 'mm;top:' . $y . 'mm;width:' . $l->label_w_mm . 'mm;height:' . $l->label_h_mm . 'mm;overflow:hidden">' . $cell($b, (float) $l->label_w_mm, (float) $l->label_h_mm) . '</div>';
            if (++$pos >= $per) { $pages[] = $page; $page = ''; $pos = 0; }
        }
        if ($page !== '') $pages[] = $page;
        return $pages;
    }

    // ───────────────────────── screens ─────────────────────────

    public static function setup(int $ent): array
    {
        $printers = DB::table('printer_profiles as p')->leftJoin('print_adapters as a', 'a.id', '=', 'p.fk_id_adapter')->whereIn('p.fk_id_ent', [0, $ent])->where('p.code', 'not like', 'EX\_%')->orderBy('p.id')
            ->get(['p.id', 'p.code', 'p.name', 'p.printer_kind', 'p.brand_text', 'p.model_text', 'p.connection', 'p.dpi', 'p.max_print_width_mm', 'p.max_print_length_mm', 'p.min_media_width_mm', 'p.max_media_width_mm', 'p.os_printer_name',
                'p.network_address', 'p.f_duplex', 'p.colour_mode', 'p.printer_status', 'p.f_active', 'p.fk_id_adapter', 'a.code as adapter', 'a.name as adapter_name', 'p.last_test_print_at', 'p.last_test_print_ok', 'p.fk_id_replaced_by', 'p.notes']);
        $cal = DB::table('printer_calibrations')->get()->groupBy('fk_id_printer_profile');
        foreach ($printers as $p) $p->tests = ($cal[$p->id] ?? collect())->map(fn ($c) => ['media' => $c->fk_id_label_media, 'ok' => (int) $c->test_print_ok, 'offset_x_mm' => (float) $c->offset_x_mm, 'offset_y_mm' => (float) $c->offset_y_mm, 'scale_pct' => (float) ($c->scale_pct ?: 100), 'rotate_deg' => (int) $c->rotate_deg])->values();
        $routes = [];
        foreach (self::KINDS as $kind => $x) {
            if ($kind === 'TEST') continue;
            $r = self::route($ent, $kind, ['user' => '-']);
            $routes[] = ['kind' => $kind, 'name' => self::KIND_NAMES[$kind], 'printer' => $r?->fk_id_printer_profile, 'backup' => $r?->fk_id_backup_printer, 'media' => $r?->fk_id_label_media, 'copies' => (int) ($r->copies ?? 1),
                'tested' => $r ? self::tested($r->fk_id_printer_profile, (int) $r->fk_id_label_media) : false, 'own' => $r && $r->fk_id_ent == $ent];
        }
        return ['printers' => $printers, 'routes' => $routes,
            'media' => DB::table('label_media')->whereIn('fk_id_ent', [0, $ent])->where('f_active', 1)->orderBy('id')->get(['id', 'code', 'name', 'media_kind', 'width_mm', 'height_mm']),
            'adapters' => DB::table('print_adapters')->where('f_active', 1)->orderBy('id')->get(['id', 'code', 'name', 'needs_local_agent']),
            'settings' => ['failover' => Choices::get('PRINT_FAILOVER', $ent), 'scale_limit' => (float) Settings::get('PRINT_SCALE_LIMIT_PCT', $ent, 3), 'test_required' => (int) Settings::get('PRINT_TEST_REQUIRED', $ent, 1)],
            'reasons' => self::REASONS];
    }

    /** Set the company's route for a job kind (its own row; the setup row stays as the fallback). */
    public static function setRoute(int $ent, string $kind, int $printer, ?int $backup, int $media, int $copies = 1): void
    {
        if (! isset(self::KINDS[$kind]) || $kind === 'TEST') throw new \InvalidArgumentException('Unknown print kind.');
        $p = self::printer($printer) ?? throw new \InvalidArgumentException('Choose a printer.');
        $m = DB::table('label_media')->where('id', $media)->first() ?? throw new \InvalidArgumentException('Choose the paper or label loaded in the printer.');
        if ($backup === $printer) throw new \InvalidArgumentException('The backup printer must be a different printer.');
        self::template($ent, $kind, $p, $m);   // fit check now, with the reason in plain words
        DB::table('print_routes')->updateOrInsert(['fk_id_ent' => $ent, 'job_kind' => $kind, 'scope' => 'COMPANY'], ['fk_id_printer_profile' => $printer, 'fk_id_backup_printer' => $backup ?: null, 'fk_id_label_media' => $media, 'copies' => max(1, $copies), 'f_active' => 1]);
        Audit::log('print_routes', null, 'UPDATE', ['kind' => $kind, 'printer' => $p->code, 'media' => $m->code], null, $ent);
    }

    public static function queue(int $ent, int $limit = 100): array
    {
        return DB::table('print_jobs as j')->leftJoin('label_templates as t', 't.id', '=', 'j.fk_id_label_template')->leftJoin('printer_profiles as p', 'p.id', '=', 'j.fk_id_printer_profile')->leftJoin('t_users as u', 'u.id', '=', 'j.requested_by')
            ->where('j.fk_id_ent', $ent)->orderByDesc('j.id')->limit($limit)
            ->get(['j.id', 'j.job_code', 't.label_kind as kind', 't.name as template', 'p.name as printer', 'j.copies', 'j.print_trigger', 'j.status', 'j.error_text', 'j.requested_at', 'j.printed_at', 'u.display_name as by', 'j.entity_ids', 'j.file_ref'])
            ->map(function ($j) { $j->count = count(json_decode($j->entity_ids, true) ?: []); $j->kind_name = self::KIND_NAMES[$j->kind] ?? $j->kind; $j->has_file = (bool) $j->file_ref; unset($j->entity_ids, $j->file_ref); return $j; })->all();
    }
}
