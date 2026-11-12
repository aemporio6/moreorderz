<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use App\Services\Context;
use App\Services\EventBus;
use App\Services\PrintService as Prints;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Printers (profiles, test page, calibration, routes, replace), the print queue and the Print button of every screen. */
class PrintController extends Controller
{
    private const SETUP = ['OWNER', 'MANAGER'];
    private const KIND_ROLES = [
        'PACKING_SLIP' => ['OWNER', 'MANAGER', 'WAREHOUSE', 'TL'], 'PICK_SHEET' => ['OWNER', 'MANAGER', 'WAREHOUSE', 'TL'], 'MANIFEST' => ['OWNER', 'MANAGER', 'WAREHOUSE', 'TL'],
        'LOCATION' => ['OWNER', 'MANAGER', 'WAREHOUSE'], 'PO' => ['OWNER', 'MANAGER', 'SOURCING', 'FINANCE', 'ACCOUNTANT'],
    ];
    private const QUEUE = ['OWNER', 'MANAGER', 'WAREHOUSE', 'TL', 'SOURCING', 'FINANCE', 'ACCOUNTANT'];

    private function can(Request $r, array $roles): void
    {
        abort_unless(in_array($r->user()->role_code, $roles, true), 403, 'Your role cannot do this here. Ask the Owner if you need it.');
    }

    private function run(callable $fn)
    {
        try { return $fn(); }
        catch (\InvalidArgumentException|\RuntimeException $e) { return response()->json(['message' => $e->getMessage(), 'errors' => ['_form' => [$e->getMessage()]]], 422); }
    }

    private function answer(array $x)
    {
        if (isset($x['ask'])) return response()->json(['ask' => $x['ask'], 'message' => "{$x['ask']['printer']} is {$x['ask']['state']}. Print on {$x['ask']['backup']} instead?"], 409);
        if ($x['status'] === 'FAILED') return response()->json(['message' => $x['error'], 'job' => $x['job'], 'errors' => ['_form' => [$x['error']]]], 422);
        return $x + ['url' => "/api/print-jobs/{$x['job']}/pdf", 'message' => "Sent to {$x['printer']}" . (! empty($x['note']) ? " ({$x['note']})" : '')];
    }

    // ---- print button ----
    public function print(Request $r)
    {
        $d = $r->validate(['kind' => 'required|string|in:' . implode(',', array_keys(self::KIND_ROLES)), 'ids' => 'required|array|min:1|max:2000', 'ids.*' => 'integer', 'copies' => 'nullable|integer|min:1|max:20',
            'reason' => 'nullable|string|max:30', 'use_backup' => 'boolean', 'printer' => 'nullable|integer', 'start_cell' => 'nullable|integer|min:1|max:200', 'workstation' => 'nullable|string|max:80']);
        $this->can($r, self::KIND_ROLES[$d['kind']]);
        return $this->run(fn () => $this->answer(Prints::print((int) Context::ent(), $d['kind'], $d['ids'], array_filter($d, fn ($v) => $v !== null))));
    }

    // ---- queue ----
    public function queue(Request $r)
    {
        $this->can($r, self::QUEUE);
        $ent = (int) Context::ent(); $rows = Prints::queue($ent);
        if (! in_array($r->user()->role_code, self::KIND_ROLES['PO'], true)) $rows = array_values(array_filter($rows, fn ($j) => $j->kind !== 'PO'));
        return ['rows' => $rows, 'reasons' => Prints::REASONS, 'failed' => count(array_filter($rows, fn ($j) => $j->status === 'FAILED')),
            'printers' => DB::table('printer_profiles')->whereIn('fk_id_ent', [0, $ent])->where('f_active', 1)->where('printer_status', 'ACTIVE')->where('code', 'not like', 'EX\_%')->get(['id', 'name'])];
    }

    private function job(Request $r, int $id): object
    {
        $j = DB::table('print_jobs as j')->leftJoin('label_templates as t', 't.id', '=', 'j.fk_id_label_template')->where('j.id', $id)->where('j.fk_id_ent', Context::ent())->first(['j.*', 't.label_kind']) ?? abort(404);
        $this->can($r, self::KIND_ROLES[$j->label_kind] ?? ['OWNER', 'MANAGER', 'WAREHOUSE']);   // test pages: whoever may test a printer
        return $j;
    }

    public function pdf(Request $r, int $id)
    {
        $j = $this->job($r, $id);
        $path = $j->file_ref ? storage_path('app/' . $j->file_ref) : null;
        abort_unless($path && is_file($path), 404, 'This job has no PDF.');
        return response()->file($path, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="' . $j->job_code . '.pdf"', 'Cache-Control' => 'private, no-store']);
    }

    public function reprint(Request $r, int $id)
    {
        $this->job($r, $id);
        $d = $r->validate(['reason' => 'required|string|max:30', 'printer' => 'nullable|integer']);
        return $this->run(fn () => $this->answer(Prints::reprint((int) Context::ent(), $id, $d['reason'], $d['printer'] ?? null)));
    }

    public function problem(Request $r, int $id)
    {
        $this->job($r, $id);
        $d = $r->validate(['what' => 'required|string|max:200']);
        return $this->run(function () use ($id, $d) { $x = Prints::reportProblem((int) Context::ent(), $id, $d['what']); return ['job' => $x['job'], 'backup' => $x['backup'] ?? null, 'message' => 'Marked as failed' . (isset($x['backup']) ? " – you can print it again on {$x['backup']['name']}" : '')]; });
    }

    // ---- printers ----
    public function printers(Request $r)
    {
        $this->can($r, self::QUEUE);
        return Prints::setup((int) Context::ent()) + ['can_edit' => in_array($r->user()->role_code, self::SETUP, true),
            'kinds' => ['THERMAL' => 'Thermal label printer', 'LASER' => 'Laser printer', 'INKJET' => 'Inkjet printer', 'OTHER' => 'Other'],
            'defaults' => ['THERMAL' => ['dpi' => 203, 'max_print_width_mm' => 108, 'max_print_length_mm' => 300, 'min_media_width_mm' => 40, 'max_media_width_mm' => 110, 'paper_format' => '100X150_MM'],
                'LASER' => ['dpi' => 600, 'max_print_width_mm' => 210, 'max_print_length_mm' => 297, 'min_media_width_mm' => 100, 'max_media_width_mm' => 216, 'paper_format' => 'A4'],
                'INKJET' => ['dpi' => 600, 'max_print_width_mm' => 210, 'max_print_length_mm' => 297, 'min_media_width_mm' => 90, 'max_media_width_mm' => 216, 'paper_format' => 'A4'],
                'OTHER' => ['dpi' => 300, 'max_print_width_mm' => 210, 'max_print_length_mm' => 297, 'min_media_width_mm' => 40, 'max_media_width_mm' => 216, 'paper_format' => 'A4']]];
    }

    public function savePrinter(Request $r)
    {
        $this->can($r, self::SETUP);
        $d = $r->validate(['id' => 'nullable|integer', 'name' => 'required|string|max:120', 'printer_kind' => 'required|in:THERMAL,LASER,INKJET,OTHER', 'brand_text' => 'nullable|string|max:60', 'model_text' => 'nullable|string|max:120',
            'connection' => 'required|in:USB,NETWORK,BLUETOOTH,WIFI', 'os_printer_name' => 'nullable|string|max:120', 'network_address' => 'nullable|string|max:120', 'dpi' => 'nullable|integer|min:72|max:2400',
            'max_print_width_mm' => 'required|numeric|min:20|max:1200', 'max_print_length_mm' => 'nullable|numeric|min:20|max:5000', 'min_media_width_mm' => 'nullable|numeric|min:10', 'max_media_width_mm' => 'nullable|numeric|min:10',
            'f_duplex' => 'boolean', 'fk_id_adapter' => 'nullable|integer', 'printer_status' => 'nullable|in:ACTIVE,OFFLINE,REPAIR,RETIRED', 'notes' => 'nullable|string|max:500']);
        $ent = (int) Context::ent(); $id = $d['id'] ?? null; unset($d['id']);
        $d['fk_id_adapter'] = $d['fk_id_adapter'] ?? DB::table('print_adapters')->where('code', 'DRIVER_PDF')->value('id');
        $d['printer_status'] = $d['printer_status'] ?? 'ACTIVE';
        $d['paper_format'] = $d['max_print_width_mm'] >= 200 ? 'A4' : '100X150_MM';
        if ($id) {
            $old = DB::table('printer_profiles')->where('id', $id)->whereIn('fk_id_ent', [0, $ent])->first() ?? abort(404);
            DB::table('printer_profiles')->where('id', $id)->update($d);
            Audit::log('printer_profiles', $id, 'UPDATE', array_filter(['name' => $d['name'], 'status' => $old->printer_status !== $d['printer_status'] ? $d['printer_status'] : null]), null, $ent);
            return ['id' => $id, 'message' => 'Printer saved'];
        }
        $n = DB::table('printer_profiles')->where('fk_id_ent', $ent)->count() + 1;
        do { $code = 'PR-' . $ent . '-' . $n++; } while (DB::table('printer_profiles')->where('code', $code)->exists());
        $id = DB::table('printer_profiles')->insertGetId($d + ['fk_id_ent' => $ent, 'code' => $code, 'f_active' => 1, 'f_default' => 0]);
        Audit::log('printer_profiles', $id, 'INSERT', ['name' => $d['name'], 'kind' => $d['printer_kind']], null, $ent);
        EventBus::fire('PRINTER_ADDED', 'printer_profiles', $id, ['code' => $code], $ent);
        return ['id' => $id, 'message' => 'Printer added. Next: print the test page.'];
    }

    public function status(Request $r, int $id)
    {
        $this->can($r, ['OWNER', 'MANAGER', 'WAREHOUSE']);
        $d = $r->validate(['status' => 'required|in:ACTIVE,OFFLINE,REPAIR']);
        $ent = (int) Context::ent();
        $p = DB::table('printer_profiles')->where('id', $id)->whereIn('fk_id_ent', [0, $ent])->first() ?? abort(404);
        abort_if($p->printer_status === 'RETIRED', 422, 'A retired printer stays retired. Add it again as a new printer.');
        DB::table('printer_profiles')->where('id', $id)->update(['printer_status' => $d['status']]);
        Audit::log('printer_profiles', $id, 'UPDATE', ['from' => $p->printer_status, 'to' => $d['status']], null, $ent);
        return ['message' => "{$p->name} is now " . strtolower($d['status'])];
    }

    public function test(Request $r, int $id)
    {
        $this->can($r, ['OWNER', 'MANAGER', 'WAREHOUSE']);
        $d = $r->validate(['media' => 'required|integer']);
        return $this->run(fn () => $this->answer(Prints::testPage((int) Context::ent(), $id, (int) $d['media'])));
    }

    public function testResult(Request $r, int $id)
    {
        $this->can($r, ['OWNER', 'MANAGER', 'WAREHOUSE']);
        $d = $r->validate(['media' => 'required|integer', 'ok' => 'required|boolean', 'offset_x_mm' => 'nullable|numeric', 'offset_y_mm' => 'nullable|numeric', 'scale_pct' => 'nullable|numeric', 'rotate_deg' => 'nullable|integer']);
        return $this->run(function () use ($id, $d) {
            Prints::calibrate((int) Context::ent(), $id, (int) $d['media'], (bool) $d['ok'], array_filter($d, fn ($v) => $v !== null));
            return ['message' => $d['ok'] ? 'Test passed – this printer can be used with this paper' : 'Saved. Print the test page again to check the correction.'];
        });
    }

    public function replace(Request $r, int $id)
    {
        $this->can($r, self::SETUP);
        $d = $r->validate(['new' => 'required|integer']);
        return $this->run(function () use ($id, $d) { $n = Prints::replace((int) Context::ent(), $id, (int) $d['new']); return ['routes' => $n, 'message' => "$n route(s) moved to the new printer. Print its test page before the first job."]; });
    }

    public function route(Request $r)
    {
        $this->can($r, self::SETUP);
        $d = $r->validate(['kind' => 'required|string', 'printer' => 'required|integer', 'backup' => 'nullable|integer', 'media' => 'required|integer', 'copies' => 'nullable|integer|min:1|max:10']);
        return $this->run(function () use ($d) { Prints::setRoute((int) Context::ent(), $d['kind'], (int) $d['printer'], $d['backup'] ?? null, (int) $d['media'], (int) ($d['copies'] ?? 1)); return ['message' => 'Route saved']; });
    }
}
