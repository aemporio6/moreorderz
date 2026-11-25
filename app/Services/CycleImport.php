<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-B_SOURCING-14 cycle CSV import (Import Center type SRC_CYCLES – planned orders).
 * Header (lower case, BOM stripped): cycle_ref, cycle_date, priority, status, notes, supplier_name, brand, product_name, variation, sku, quantity, unit_cost, spec_notes.
 * Required columns cycle_ref, product_name ("Missing required column: <x>"); a row with either empty → "Line n skipped (cycle_ref / product_name empty)".
 * Rows with the same cycle_ref = one cycle (type FULL, steps as CycleAlerts::TYPE_SKIPS), one cycle product per row; cycle_ref kept in order_ref; sourcing date = cycle_date
 * (empty = today), priority default Normal, status default Draft. Supplier = contains-match on the supplier name, brand = exact name (case-insensitive);
 * not found → left empty + "Line n: supplier '<x>' not found - left empty" / "Line n: brand '<x>' not found - left empty". A SKU of the product master links the product.
 * Quantity / unit cost: commas removed. Check (dry run) writes nothing; the import is one batch that Undo removes while nothing later uses it.
 */
class CycleImport
{
    public const COLUMNS = ['cycle_ref', 'cycle_date', 'priority', 'status', 'notes', 'supplier_name', 'brand', 'product_name', 'variation', 'sku', 'quantity', 'unit_cost', 'spec_notes'];
    public const REQUIRED = ['cycle_ref', 'product_name'];

    private static function num(string $v): float { return (float) str_replace([',', ' '], '', $v); }

    public static function template(): string
    {
        return implode(',', self::COLUMNS) . "\nPLAN-01,2026-12-01,Normal,Draft,Spring range,Ningbo Housewares,,Steel mug,Black,MUG-STEEL,\"1,000\",1.20,Matt finish\nPLAN-01,2026-12-01,,,,Ningbo Housewares,,Steel lid,,LID-STEEL,1000,0.30,\n";
    }

    /** The file as cycles (no write). */
    public static function parse(int $ent, string $text): array
    {
        if (trim($text) === '') throw new \InvalidArgumentException('Empty file.');
        $rows = PurchaseAudit::csv($text); $head = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), array_shift($rows) ?? []);
        foreach (self::REQUIRED as $c) if (! in_array($c, $head, true)) throw new \InvalidArgumentException("Missing required column: $c");
        $addBrands = [];      /* R-B_SOURCING-12 a brand is never created by the file – the check screen offers "+ Add brand" */
        $cycles = []; $log = []; $skipped = 0;
        foreach ($rows as $i => $r) {
            if (! array_filter($r, fn ($x) => trim((string) $x) !== '')) continue;
            $a = []; foreach ($head as $k => $h) $a[$h] = trim((string) ($r[$k] ?? '')); $n = $i + 2;
            if (($a['cycle_ref'] ?? '') === '' || ($a['product_name'] ?? '') === '') { $log[] = "Line $n skipped (cycle_ref / product_name empty)"; $skipped++; continue; }
            $sup = null; if (($a['supplier_name'] ?? '') !== '') { $sup = SrcNames::supplier($ent, (string) $a['supplier_name'])->id ?? null; if (! $sup) $log[] = "Line $n: supplier '{$a['supplier_name']}' not found - left empty"; }
            $brand = null; if (($a['brand'] ?? '') !== '') { $brand = SrcNames::brand($ent, $a['brand']); if (! $brand) $addBrands[$a['brand']] = true; if (! $brand) $log[] = "Line $n: brand '{$a['brand']}' not found - left empty"; }
            $prod = ($a['sku'] ?? '') !== '' ? DB::table('t_product_vendors')->where('fk_id_ent', $ent)->where('SKU_VENDOR', $a['sku'])->value('id') : null;
            $ref = $a['cycle_ref'];
            $cycles[$ref] ??= ['ref' => $ref, 'date' => ($d = strtotime($a['cycle_date'] ?? '')) ? date('Y-m-d', $d) : date('Y-m-d'), 'priority' => ($a['priority'] ?? '') ?: 'Normal', 'status' => ($a['status'] ?? '') ?: 'Draft', 'notes' => ($a['notes'] ?? '') ?: null, 'products' => []];
            $cycles[$ref]['products'][] = ['name' => $a['product_name'], 'variation' => ($a['variation'] ?? '') ?: null, 'sku' => ($a['sku'] ?? '') ?: null, 'qty' => self::num($a['quantity'] ?? '0'), 'cost' => self::num($a['unit_cost'] ?? '0'),
                'spec' => ($a['spec_notes'] ?? '') ?: null, 'supplier' => $sup ? (int) $sup : null, 'brand' => $brand ? (int) $brand : null, 'product' => $prod ? (int) $prod : null];
        }
        return ['add_brands' => array_keys($addBrands), 'cycles' => array_values($cycles), 'log' => $log, 'skipped' => $skipped, 'products' => array_sum(array_map(fn ($c) => count($c['products']), $cycles))];
    }

    public static function check(int $ent, string $text): array
    {
        $p = self::parse($ent, $text);
        return ['add_brands' => $p['add_brands'], 'cycles' => count($p['cycles']), 'products' => $p['products'], 'skipped' => $p['skipped'], 'log' => $p['log'], 'message' => 'Check: ' . count($p['cycles']) . ' cycles, ' . $p['products'] . ' products would be created. Nothing was saved.'];
    }

    public static function import(int $ent, string $text, object $u): array
    {
        $p = self::parse($ent, $text);
        return DB::transaction(function () use ($ent, $p, $u) {
            $made = [];
            foreach ($p['cycles'] as $c) {
                $first = $c['products'][0];
                $id = (int) DB::table('t_src_cycles')->insertGetId(RecordService::fillNotNull('t_src_cycles', ['fk_id_ent' => $ent, 'cycle_code' => Numbering::next('SC', $ent, $c['date']), 'sourcing_date' => $c['date'], 'priority' => $c['priority'], 'status' => $c['status'],
                    'notes' => $c['notes'], 'product_name' => mb_substr($first['name'] . (count($c['products']) > 1 ? ' + ' . (count($c['products']) - 1) . ' more' : ''), 0, 200), 'sku' => $first['sku'], 'target_qty' => array_sum(array_column($c['products'], 'qty')),
                    'cycle_type' => 'FULL', 'skipped_steps' => CycleAlerts::TYPE_SKIPS['FULL'], 'order_ref' => mb_substr($c['ref'], 0, 100), 'created_by' => $u->id, 'f_active' => 1, 'created_at' => now()]));
                foreach ($c['products'] as $x) DB::table('t_src_cycle_products')->insert(['fk_id_ent' => $ent, 'fk_id_cycle' => $id, 'fk_id_brand' => $x['brand'], 'fk_id_supplier' => $x['supplier'], 'fk_id_product' => $x['product'], 'product_name' => mb_substr($x['name'], 0, 255),
                    'sku' => $x['sku'], 'variation' => $x['variation'], 'target_qty' => $x['qty'], 'target_unit_cost' => $x['cost'], 'product_spec' => $x['spec'], 'row_status' => 'Draft', 'created_by' => $u->id, 'f_active' => 1, 'created_at' => now()]);
                Audit::log('t_src_cycles', $id, 'INSERT', ['import' => 'SRC_CYCLES', 'cycle_ref' => $c['ref'], 'products' => count($c['products'])], null, $ent);
                $made[] = $id;
            }
            $job = $made ? (int) DB::table('bulk_jobs')->insertGetId(['job_no' => 'SC-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5)), 'list_key' => 'src_cycles', 'fk_id_account' => $u->id, 'change_json' => json_encode(['cycles' => $made]),
                'row_count' => count($made), 'status' => 'APPLIED', 'created_at' => now(), 'applied_at' => now()]) : null;
            return ['job' => $job, 'created' => count($made), 'log' => $p['log'], 'message' => 'Done: ' . count($made) . ' cycles, ' . $p['products'] . ' products created.'];
        });
    }

    /** Undo a batch: a cycle goes only while nothing was added under it (no RFQ, PO or other step record). */
    public static function undo(int $jobId): array
    {
        $j = DB::table('bulk_jobs')->where('id', $jobId)->where('list_key', 'src_cycles')->first() ?? throw new \InvalidArgumentException('Batch not found.');
        if ($j->status !== 'APPLIED') throw new \InvalidArgumentException('This batch was already undone.');
        $gone = 0; $kept = [];
        foreach (json_decode((string) $j->change_json, true)['cycles'] as $cy) {
            $used = false; foreach (['t_src_rfq', 't_src_po', 't_src_samples', 't_src_payments', 't_src_shipments', 't_src_grn'] as $t) if (DB::table($t)->where('fk_id_cycle', $cy)->exists()) { $used = true; break; }
            if ($used) { $kept[] = DB::table('t_src_cycles')->where('id', $cy)->value('cycle_code'); continue; }
            DB::table('t_src_cycle_products')->where('fk_id_cycle', $cy)->delete(); DB::table('t_src_cycles')->where('id', $cy)->delete(); $gone++;
        }
        DB::table('bulk_jobs')->where('id', $j->id)->update(['status' => $kept ? 'PARTIAL' : 'UNDONE', 'undone_at' => now()]);
        return ['removed' => $gone, 'kept' => $kept, 'message' => "Undone: $gone cycle(s) removed" . ($kept ? ' · kept (work already added): ' . implode(', ', $kept) : '') . '.'];
    }
}
