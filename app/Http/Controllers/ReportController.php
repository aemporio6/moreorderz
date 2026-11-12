<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use App\Services\Context;
use App\Services\ReportService as Reports;
use Illuminate\Http\Request;

/** Reports: the list a role may see, one report on screen, and the same report as a spreadsheet file. */
class ReportController extends Controller
{
    private function dates(Request $r): array
    {
        $d = $r->validate(['from' => 'nullable|date', 'to' => 'nullable|date']);
        return [$d['from'] ?? date('Y-m-01', strtotime('-2 month')), $d['to'] ?? date('Y-m-d')];
    }

    public function index(Request $r) { return ['reports' => Reports::catalogue($r->user()->role_code)]; }

    public function show(Request $r, string $key)
    {
        abort_unless(Reports::allowed($key, $r->user()->role_code), 403, 'Your role cannot open this report.');
        [$from, $to] = $this->dates($r);
        try { return Reports::run((int) Context::ent(), $key, $from, $to, $r->user()->role_code); }
        catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); }
    }

    public function csv(Request $r, string $key)
    {
        abort_unless(Reports::allowed($key, $r->user()->role_code), 403, 'Your role cannot open this report.');
        [$from, $to] = $this->dates($r);
        $x = Reports::run((int) Context::ent(), $key, $from, $to, $r->user()->role_code);
        Audit::log('reports', null, 'EXPORT', ['report' => $key, 'from' => $from, 'to' => $to, 'rows' => count($x['rows'])], null, (int) Context::ent());
        $safe = fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v;   // no formula injection in spreadsheets
        return response()->streamDownload(function () use ($x, $safe) {
            $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
            fputcsv($o, array_column($x['columns'], 'label'));
            foreach ($x['rows'] as $row) fputcsv($o, array_map(fn ($c) => $safe($row[$c['key']] ?? ''), $x['columns']));
            if ($x['totals']) fputcsv($o, array_map(fn ($c, $i) => $i === 0 ? 'Total' : ($x['totals'][$c['key']] ?? ''), $x['columns'], array_keys($x['columns'])));
            fclose($o);
        }, $key . '_' . ($x['dates'] ? $from . '_' . $to : date('Y-m-d')) . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
