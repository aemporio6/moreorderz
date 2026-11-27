<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * MR-1414 / MR-1258 one import book (old: a separate importer per screen, nothing shows the whole picture).
 * Every file that came in – money / bank / marketplace reports, monthly sales files, drop-shipping supplier orders, files from a watched Drive folder
 * or from a connection – in one list, with the breakdown the owner asked for: which module it fed, which marketplace and shop, which folder / source,
 * what kind of report, how many rows were new / already there / had problems, and the screen where it is checked or undone.
 * Read only: each screen keeps its own checks, mapping and undo.
 */
class ImportBook
{
    /** report kind → module (when imp_report_types does not say) */
    private const MODULE = ['BANK_STATEMENT' => 'Finance', 'CARRIER_LABEL_PDF' => 'Sales', 'TIKTOK_SETTLEMENT' => 'Finance'];

    public static function rows(int $ent, int $limit = 400): array
    {
        $types = DB::table('imp_report_types')->get(['code', 'name', 'module'])->keyBy('code');
        $shops = DB::table('t_branches')->where('fk_id_ent', $ent)->get(['id', 'shop_name', 'fk_id_marketplace'])->keyBy('id');
        $mps = DB::table('cat_marketplaces')->pluck('marketplace_short', 'id');
        $mod = fn (string $k, string $else) => $types[$k]->module ?? self::MODULE[$k] ?? (str_contains($k, 'SETTLEMENT') || str_contains($k, 'PAYOUT') ? 'Finance' : $else);
        $kind = fn (string $k) => $types[$k]->name ?? ucfirst(strtolower(str_replace('_', ' ', $k)));
        $shop = fn ($id) => $id && isset($shops[$id]) ? $shops[$id]->shop_name : null;
        $mpOf = fn ($shopId, $mp = null) => ($mp ? ($mps[$mp] ?? null) : null) ?? ($shopId && isset($shops[$shopId]) ? ($mps[$shops[$shopId]->fk_id_marketplace] ?? null) : null);
        $out = [];
        foreach (DB::table('fin_import_batches')->where('company_id', $ent)->orderByDesc('id')->limit($limit)->get() as $b) {
            $k = (string) $b->report_type;
            $out[] = ['at' => (string) $b->imported_at, 'store' => 'money', 'id' => (int) $b->id, 'module' => $mod($k, 'Finance'), 'kind' => $kind($k), 'code' => $k, 'file' => $b->file_name,
                'shop' => $shop($b->branch_id), 'marketplace' => $mpOf($b->branch_id), 'source' => 'Upload', 'period' => trim(($b->period_from ?? '') . ' – ' . ($b->period_to ?? ''), ' –'),
                'rows' => (int) $b->rows_total, 'new' => (int) $b->rows_new, 'there' => (int) $b->rows_duplicate, 'problems' => (int) $b->rows_error,
                'status' => str_starts_with((string) $b->file_hash, 'UNDONE-') ? 'UNDONE' : 'SAVED', 'open' => '#/import-history'];
        }
        foreach (DB::table('t_sale_imports')->where('fk_id_ent', $ent)->orderByDesc('id')->limit($limit)->get() as $b) {
            $out[] = ['at' => (string) ($b->imported_at ?? $b->updated_at), 'store' => 'sales', 'id' => (int) $b->id, 'module' => 'Sales', 'kind' => (int) ($b->f_daily ?? 0) ? 'Daily orders' : 'Monthly sales file', 'code' => 'SALES',
                'file' => $b->file_name, 'shop' => $shop($b->fk_id_branch), 'marketplace' => $mpOf($b->fk_id_branch, $b->fk_id_marketplace), 'source' => 'Upload',
                'period' => $b->period_month ? sprintf('%04d-%02d', (int) $b->period_year, (int) $b->period_month) : '', 'rows' => (int) $b->total_rows, 'new' => (int) $b->saved_rows,
                'there' => (int) $b->skipped_rows + (int) $b->replaced_rows, 'problems' => (int) $b->error_rows, 'status' => strtoupper((string) $b->status), 'open' => '#/sales-import?fix=' . $b->id];
        }
        foreach (DB::table('drp_imports')->where('fk_id_ent', $ent)->orderByDesc('id')->limit($limit)->get() as $b) {
            $out[] = ['at' => (string) $b->uploaded_at, 'store' => 'dropship', 'id' => (int) $b->id, 'module' => 'Dropshipping', 'kind' => 'Drop-shipping supplier orders', 'code' => 'DS_SUPPLIER_ORDERS',
                'file' => $b->file_name, 'shop' => $shop($b->fk_id_branch), 'marketplace' => $mpOf($b->fk_id_branch, $b->fk_id_marketplace), 'source' => 'Upload',
                'period' => $b->period_month ? sprintf('%04d-%02d', (int) $b->period_year, (int) $b->period_month) : '', 'rows' => (int) $b->total_rows, 'new' => (int) $b->inserted_rows,
                'there' => (int) $b->skipped_rows + (int) $b->replaced_rows + (int) $b->merged_rows, 'problems' => (int) $b->error_rows, 'status' => 'SAVED', 'open' => '#/ds-upload'];
        }
        foreach (DB::table('imp_batches')->where('fk_id_ent', $ent)->orderByDesc('id')->limit($limit)->get() as $b) {
            $k = (string) $b->report_type;
            $out[] = ['at' => (string) $b->created_at, 'store' => 'files', 'id' => (int) $b->id, 'module' => $mod($k, 'Imports'), 'kind' => $kind($k), 'code' => $k, 'file' => $b->file_name,
                'shop' => $shop($b->fk_id_branch), 'marketplace' => $mpOf($b->fk_id_branch, $b->fk_id_marketplace), 'source' => ['DRIVE' => 'Drive folder', 'API' => 'Connection'][strtoupper((string) $b->source)] ?? ucfirst(strtolower((string) $b->source ?: 'upload')),
                'period' => trim(($b->period_from ?? '') . ' – ' . ($b->period_to ?? ''), ' –'), 'rows' => (int) $b->rows_total, 'new' => (int) $b->rows_new, 'there' => (int) $b->rows_duplicate,
                'problems' => (int) $b->rows_problem, 'status' => $b->undone_at ? 'UNDONE' : strtoupper((string) $b->status), 'open' => strtoupper((string) $b->source) === 'DRIVE' ? '#/drive?tab=watched' : '#/import-history'];
        }
        usort($out, fn ($a, $b) => strcmp($b['at'], $a['at']));
        return array_slice($out, 0, $limit);
    }

    /** The list and its breakdown by module, marketplace / shop, source and report kind; plus the watched folders. */
    public static function page(int $ent): array
    {
        $rows = self::rows($ent);
        $by = function (string $k) use ($rows) { $g = [];
            foreach ($rows as $r) { $x = $r[$k] ?: '—'; $g[$x] ??= ['name' => $x, 'files' => 0, 'rows' => 0, 'problems' => 0, 'last' => null];
                $g[$x]['files']++; $g[$x]['rows'] += $r['rows']; $g[$x]['problems'] += $r['problems']; if (! $g[$x]['last'] || $r['at'] > $g[$x]['last']) $g[$x]['last'] = $r['at']; }
            usort($g, fn ($a, $b) => $b['files'] <=> $a['files']); return array_values($g); };
        $shopBy = []; foreach ($rows as $r) { $x = trim(($r['marketplace'] ?? '') . ' · ' . ($r['shop'] ?? ''), ' ·') ?: '—'; $shopBy[$x] = ($shopBy[$x] ?? 0) + 1; } arsort($shopBy);
        $folders = DB::table('imp_drive_folders as w')->leftJoin('t_branches as b', 'b.id', '=', 'w.fk_id_branch')->where('w.fk_id_ent', $ent)->get(['w.id', 'w.report_type', 'b.shop_name', 'w.last_checked_at', 'w.f_auto_save', 'w.f_active'])
            ->map(fn ($w) => ['shop' => $w->shop_name, 'kind' => $w->report_type, 'last_checked' => $w->last_checked_at, 'auto' => (bool) $w->f_auto_save, 'active' => (bool) $w->f_active,
                'files' => (int) DB::table('imp_batches')->where('source', 'DRIVE')->where('report_type', $w->report_type)->where('fk_id_branch', DB::table('imp_drive_folders')->where('id', $w->id)->value('fk_id_branch'))->count()])->all();
        $targets = array_map(fn ($k, $t) => ['code' => $k, 'title' => $t[0], 'screen' => $t[1]], array_keys(Importer::TARGETS), Importer::TARGETS);
        return ['rows' => $rows, 'by_module' => $by('module'), 'by_kind' => $by('kind'), 'by_source' => $by('source'), 'by_shop' => array_map(fn ($k, $n) => ['name' => $k, 'files' => $n], array_keys($shopBy), $shopBy),
            'folders' => $folders, 'targets' => $targets, 'total' => count($rows)];
    }
}
