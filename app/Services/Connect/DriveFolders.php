<?php

namespace App\Services\Connect;

use App\Services\Context;
use App\Services\MarketplaceFiles;
use App\Services\Numbering;
use App\Services\OrderFiles;
use Illuminate\Support\Facades\DB;

/**
 * 14.2 Drive folders: one Drive folder per company, shop, supplier, purchase order and product (sys_drive_folders).
 * A folder link can always be pasted by hand (works today); with a Google Drive connection the tool makes the folder itself.
 * 15.3 Watched folders (imp_drive_folders): new files in a shop's report folder are found and loaded by the reader of their report type.
 */
class DriveFolders
{
    /** entity → [table, name column, label] */
    public const ENTITIES = ['COMPANY' => ['t_enterprise', 'ent_name', 'Companies'], 'SHOP' => ['t_branches', 'shop_name', 'Shops'], 'SUPPLIER' => ['t_src_suppliers', 'supplier_name', 'Suppliers'],
        'PO' => ['t_src_po', 'po_number', 'Purchase orders'], 'PRODUCT' => ['t_product_vendors', 'SKU_VENDOR', 'Products']];

    public static function connection(): ?object
    {
        return DB::table('api_connections')->where('adapter', 'GOOGLE_DRIVE')->whereIn('fk_id_ent', [(int) Context::ent(), 0])->whereNotIn('status', ['OFF'])->orderByDesc('fk_id_ent')->first();
    }

    private static function exists(string $t): bool { return DB::getSchemaBuilder()->hasTable($t); }

    public static function page(string $entity, string $q = ''): array
    {
        $e = self::ENTITIES[$entity] ?? self::ENTITIES['SHOP']; [$t, $nameCol] = $e; $ent = (int) Context::ent();
        $rows = collect();
        if (self::exists($t)) {
            $x = DB::table($t . ' as r')->leftJoin('sys_drive_folders as f', fn ($j) => $j->on('f.entity_id', '=', 'r.id')->where('f.entity', '=', $entity));
            if ($t !== 't_enterprise' && DB::getSchemaBuilder()->hasColumn($t, 'fk_id_ent') && $ent) $x->where('r.fk_id_ent', $ent);
            if ($t === 't_enterprise' && $ent) $x->where('r.id', $ent);
            if ($q !== '') $x->where('r.' . $nameCol, 'like', "%$q%");
            $rows = $x->orderByRaw('f.id IS NULL')->orderBy('r.' . $nameCol)->limit(300)->get(['r.id', 'r.' . $nameCol . ' as name', 'f.folder_url', 'f.folder_id', 'f.created_at']);
        }
        $c = self::connection();
        return ['entity' => $entity, 'entities' => array_map(fn ($k, $v) => ['code' => $k, 'label' => $v[2]], array_keys(self::ENTITIES), self::ENTITIES), 'rows' => $rows,
            'with_folder' => $rows->whereNotNull('folder_url')->count(), 'drive' => $c ? ['id' => $c->id, 'status' => $c->status, 'label' => $c->label] : null,
            'watched' => DB::table('imp_drive_folders as w')->leftJoin('t_branches as b', 'b.id', '=', 'w.fk_id_branch')->leftJoin('imp_report_types as r', 'r.code', '=', 'w.report_type')->when($ent, fn ($x) => $x->where('w.fk_id_ent', $ent))
                ->orderBy('b.shop_name')->get(['w.id', 'b.shop_name as shop', 'w.report_type', 'r.name as report_name', 'r.handler', 'w.folder_url', 'w.folder_id', 'w.last_checked_at', 'w.f_auto_save', 'w.f_active']),
            'waiting' => DB::table('imp_batches')->where('source', 'DRIVE')->where('status', 'UPLOADED')->when($ent, fn ($x) => $x->where('fk_id_ent', $ent))->orderByDesc('id')->limit(100)->get(['id', 'file_name', 'report_type', 'fk_id_branch', 'created_at'])];
    }

    public static function folderId(string $url): ?string
    {
        if (preg_match('~/folders/([A-Za-z0-9_-]{10,})~', $url, $m) || preg_match('~[?&]id=([A-Za-z0-9_-]{10,})~', $url, $m)) return $m[1];
        return preg_match('~^[A-Za-z0-9_-]{10,}$~', trim($url)) ? trim($url) : null;
    }

    public static function setLink(string $entity, int $id, string $url): void
    {
        if (! isset(self::ENTITIES[$entity])) throw new \InvalidArgumentException('Unknown kind of record.');
        $url = trim($url);
        if ($url === '') { DB::table('sys_drive_folders')->where(['entity' => $entity, 'entity_id' => $id])->delete(); return; }
        if (! str_starts_with($url, 'https://drive.google.com/') && ! str_starts_with($url, 'https://docs.google.com/')) throw new \InvalidArgumentException('Paste a Google Drive folder link (https://drive.google.com/…).');
        DB::table('sys_drive_folders')->updateOrInsert(['entity' => $entity, 'entity_id' => $id], ['folder_url' => mb_substr($url, 0, 500), 'folder_id' => self::folderId($url), 'created_at' => now()]);
    }

    /** Make the folder in Drive (needs the Google Drive connection). A shop's folder goes into its company's folder, a PO's into its supplier's, when those exist. */
    public static function make(string $entity, int $id): array
    {
        $c = self::connection() ?? throw new \InvalidArgumentException('Set up the Google Drive connection first (System › API connections), or paste the folder link by hand.');
        [$t, $nameCol] = self::ENTITIES[$entity] ?? throw new \InvalidArgumentException('Unknown kind of record.');
        $name = (string) (DB::table($t)->where('id', $id)->value($nameCol) ?? throw new \InvalidArgumentException('Record not found.'));
        $parent = null;
        if ($entity === 'SHOP') $parent = DB::table('sys_drive_folders')->where(['entity' => 'COMPANY', 'entity_id' => DB::table('t_branches')->where('id', $id)->value('fk_id_ent')])->value('folder_id');
        if ($entity === 'PO' && DB::getSchemaBuilder()->hasColumn('t_src_po', 'fk_id_supplier')) $parent = DB::table('sys_drive_folders')->where(['entity' => 'SUPPLIER', 'entity_id' => DB::table('t_src_po')->where('id', $id)->value('fk_id_supplier')])->value('folder_id');
        $ad = Connections::adapter($c);
        try { $f = $ad->folder(self::ENTITIES[$entity][2] === 'Products' ? "SKU $name" : $name, $parent); }
        catch (ApiError $e) { throw new \InvalidArgumentException($e->getMessage()); }
        finally { if ($ad->renewed) Connections::keepRenewed($c, $ad); }
        DB::table('sys_drive_folders')->updateOrInsert(['entity' => $entity, 'entity_id' => $id], ['folder_url' => $f['url'], 'folder_id' => $f['id'], 'created_at' => now()]);
        return $f;
    }

    /** Look into watched folders: new files are recorded once; with "load by itself" they are loaded at once, otherwise they wait for a click. */
    public static function check(?int $watchId = null): array
    {
        $c = self::connection() ?? throw new \InvalidArgumentException('Watching Drive folders needs the Google Drive connection (System › API connections).');
        $ad = Connections::adapter($c); $out = ['found' => 0, 'loaded' => 0, 'waiting' => 0, 'errors' => []];
        $q = DB::table('imp_drive_folders')->where('f_active', 1)->when($watchId, fn ($x) => $x->where('id', $watchId))->when(Context::ent(), fn ($x) => $x->where('fk_id_ent', Context::ent()));
        foreach ($q->get() as $w) {
            $fid = $w->folder_id ?: self::folderId((string) $w->folder_url); if (! $fid) { $out['errors'][] = "Folder of {$w->report_type}: no folder id in the link."; continue; }
            try { $files = $ad->files($fid); } catch (ApiError $e) { $out['errors'][] = $e->getMessage(); continue; }
            foreach ($files as $f) {
                if (($f['mimeType'] ?? '') === 'application/vnd.google-apps.folder' || DB::table('imp_batches')->where('drive_file_id', $f['id'])->exists()) continue;
                $bid = DB::table('imp_batches')->insertGetId(['batch_code' => Numbering::next('IMP', (int) $w->fk_id_ent), 'fk_id_ent' => (int) $w->fk_id_ent, 'fk_id_branch' => $w->fk_id_branch, 'report_type' => $w->report_type, 'source' => 'DRIVE',
                    'file_name' => mb_substr((string) $f['name'], 0, 200), 'drive_file_id' => $f['id'], 'status' => 'UPLOADED', 'created_by' => Context::userId(), 'created_at' => now()]);
                $out['found']++;
                if ($w->f_auto_save) { $r = self::load($bid, $ad); $r['ok'] ? $out['loaded']++ : $out['errors'][] = $f['name'] . ': ' . $r['error']; } else $out['waiting']++;
            }
            DB::table('imp_drive_folders')->where('id', $w->id)->update(['last_checked_at' => now(), 'folder_id' => $fid]);
        }
        if ($ad->renewed) Connections::keepRenewed($c, $ad);
        return $out;
    }

    /** Load one file found in a watched folder with the reader its report type names (orders or money). */
    public static function load(int $batchId, ?Adapter $ad = null): array
    {
        $b = DB::table('imp_batches')->where('id', $batchId)->where('source', 'DRIVE')->first() ?? throw new \InvalidArgumentException('File not found.');
        if ($b->status === 'SAVED') return ['ok' => true, 'already' => true];
        $handler = (string) DB::table('imp_report_types')->where('code', $b->report_type)->value('handler');
        $kind = in_array($handler, ['Imp_sales_handler', 'Imp_fulfilment_handler'], true) ? 'ORDERS' : ($handler === 'Imp_money_report_handler' ? 'MONEY' : null);
        if (! $kind) { DB::table('imp_batches')->where('id', $batchId)->update(['status' => 'FAILED']); return ['ok' => false, 'error' => "Files of type {$b->report_type} are not loaded from Drive by themselves – load it on its own screen."]; }
        $c = self::connection(); $ad ??= $c ? Connections::adapter($c) : throw new \InvalidArgumentException('The Google Drive connection is missing.');
        $path = null;
        try {
            [$path, $name] = $ad->download(['id' => $b->drive_file_id, 'name' => $b->file_name, 'mimeType' => str_contains((string) $b->file_name, '.') ? 'file' : 'application/vnd.google-apps.spreadsheet']);
            $r = $kind === 'ORDERS' ? OrderFiles::import((int) $b->fk_id_ent, (int) $b->fk_id_branch, $path, $name) : MarketplaceFiles::import((int) $b->fk_id_ent, (int) $b->fk_id_branch, $path, $name);
            DB::table('imp_batches')->where('id', $batchId)->update(['status' => 'SAVED', 'saved_by' => Context::userId(), 'saved_at' => now(), 'rows_total' => (int) ($r['lines_read'] ?? $r['lines_new'] ?? 0) + (int) ($r['lines_already'] ?? 0), 'rows_new' => (int) ($r['new'] ?? $r['lines_new'] ?? 0)]);
            return ['ok' => true, 'result' => array_intersect_key($r, array_flip(['format_name', 'new', 'updated', 'lines_new', 'lines_already', 'statements_new']))];
        } catch (\Throwable $e) {
            DB::table('imp_batches')->where('id', $batchId)->update(['status' => 'FAILED']);
            return ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 250)];
        } finally { if ($path) @unlink($path); }
    }
}
