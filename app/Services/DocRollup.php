<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Document roll-up (R-F_SETUP-10): docs_for(PO) = documents linked to the PO directly, its payments, shipments, QC, GRNs of its shipments (+ GRNs of the
 * cycle without shipment), the product master and cycle products of its lines, RFQs / samples / freight of the cycle, its production, investments of the PO
 * or its cycle. A document reached by two paths is shown once with both labels. Same rule for a cycle (every stage of it) and a product (master + all cycles).
 * Open = one preview panel: Drive file id from /d/<id> or ?id=<id> → https://drive.google.com/file/d/<id>/preview (no id → the link itself).
 */
class DocRollup
{
    public const NOTE = 'Files stay in Google Drive – only metadata is stored here; nothing heavy loads until you click.';

    private static function ids(string $t, string $col, array $in, bool $active = true): array
    {
        if (! $in) return [];
        return DB::table($t)->whereIn($col, $in)->when($active, fn ($q) => $q->where('f_active', 1))->pluck('id')->map(fn ($x) => (int) $x)->all();
    }

    /** [label => [document column, ids]] for one PO. */
    private static function poPaths(int $ent, object $po): array
    {
        $cy = (int) $po->fk_id_cycle; $ships = self::ids('t_src_shipments', 'fk_id_po', [$po->id]);
        $grn = array_values(array_unique(array_merge(self::ids('t_src_grn', 'fk_id_shipment', $ships), $cy ? DB::table('t_src_grn')->where('fk_id_cycle', $cy)->where('f_active', 1)->where(fn ($q) => $q->whereNull('fk_id_shipment')->orWhere('fk_id_shipment', 0))->pluck('id')->map(fn ($x) => (int) $x)->all() : [])));
        $lines = DB::table('t_src_po_lines')->where('fk_id_po', $po->id)->where('f_active', 1)->get(['fk_id_product', 'fk_id_cycle_product']);
        $inv = DB::table('t_src_investments')->where('f_active', 1)->where(fn ($q) => $q->where('fk_id_po', $po->id)->when($cy, fn ($w) => $w->orWhere('fk_id_cycle', $cy)))->pluck('id')->map(fn ($x) => (int) $x)->all();
        return ['Direct PO' => ['fk_id_po', [(int) $po->id]], 'Payment' => ['fk_id_payment', self::ids('t_src_payments', 'fk_id_po', [$po->id])], 'Shipment' => ['fk_id_shipment', $ships],
            'QC' => ['fk_id_qc', self::ids('t_src_qc', 'fk_id_po', [$po->id])], 'GRN' => ['fk_id_grn', $grn], 'Product Master' => ['fk_id_product', $lines->pluck('fk_id_product')->filter()->map(fn ($x) => (int) $x)->unique()->values()->all()],
            'Cycle Product' => ['fk_id_cycle_product', $lines->pluck('fk_id_cycle_product')->filter()->map(fn ($x) => (int) $x)->unique()->values()->all()], 'RFQ' => ['fk_id_rfq', $cy ? self::ids('t_src_rfq', 'fk_id_cycle', [$cy]) : []],
            'Sample' => ['fk_id_sample', $cy ? self::ids('t_src_samples', 'fk_id_cycle', [$cy]) : []], 'Production' => ['fk_id_production', self::ids('t_src_production', 'fk_id_po', [$po->id])],
            'Freight' => ['fk_id_freight', $cy ? self::ids('t_src_freight', 'fk_id_cycle', [$cy]) : []], 'Investment' => ['fk_id_investment', $inv]];
    }

    private static function cyclePaths(int $cy): array
    {
        $po = self::ids('t_src_po', 'fk_id_cycle', [$cy]);
        $p = ['Cycle' => ['fk_id_cycle', [$cy]]];
        foreach (['Direct PO' => ['fk_id_po', $po], 'Payment' => ['fk_id_payment', self::ids('t_src_payments', 'fk_id_cycle', [$cy])], 'Shipment' => ['fk_id_shipment', array_values(array_unique(array_merge(self::ids('t_src_shipments', 'fk_id_cycle', [$cy]), self::ids('t_src_shipments', 'fk_id_po', $po))))],
            'QC' => ['fk_id_qc', self::ids('t_src_qc', 'fk_id_cycle', [$cy])], 'GRN' => ['fk_id_grn', self::ids('t_src_grn', 'fk_id_cycle', [$cy])], 'Cycle Product' => ['fk_id_cycle_product', self::ids('t_src_cycle_products', 'fk_id_cycle', [$cy])],
            'RFQ' => ['fk_id_rfq', self::ids('t_src_rfq', 'fk_id_cycle', [$cy])], 'Sample' => ['fk_id_sample', self::ids('t_src_samples', 'fk_id_cycle', [$cy])], 'Production' => ['fk_id_production', self::ids('t_src_production', 'fk_id_cycle', [$cy])],
            'Freight' => ['fk_id_freight', self::ids('t_src_freight', 'fk_id_cycle', [$cy])], 'Customs' => ['fk_id_customs', self::ids('t_src_customs', 'fk_id_cycle', [$cy])], 'Investment' => ['fk_id_investment', self::ids('t_src_investments', 'fk_id_cycle', [$cy])],
            'Allocation' => ['fk_id_allocation', self::ids('t_src_allocations', 'fk_id_cycle', [$cy])]] as $k => $v) $p[$k] = $v;
        return $p;
    }

    /** One list from paths: a document reached twice shows once with both labels; sorted by type. */
    private static function collect(int $ent, array $paths): array
    {
        $rows = [];
        foreach ($paths as $label => [$col, $ids]) {
            if (! $ids) continue;
            foreach (DB::table('t_src_documents as d')->leftJoin('t_users as u', 'u.id', '=', DB::raw('COALESCE(NULLIF(d.uploaded_by, 0), d.created_by)'))->where('d.fk_id_ent', $ent)->where('d.f_active', 1)->whereIn("d.$col", $ids)
                ->get(['d.id', 'd.doc_type', 'd.doc_name', 'd.drive_url', 'd.created_at', 'u.display_name as by']) as $d) {
                $rows[$d->id] ??= ['id' => (int) $d->id, 'type' => (string) $d->doc_type, 'name' => (string) $d->doc_name, 'via' => [], 'uploaded_by' => $d->by, 'uploaded_on' => $d->created_at ? substr((string) $d->created_at, 0, 10) : null,
                    'url' => $d->drive_url, 'preview' => self::preview($d->drive_url)];
                if (! in_array($label, $rows[$d->id]['via'], true)) $rows[$d->id]['via'][] = $label;
            }
        }
        $rows = array_values($rows);
        usort($rows, fn ($a, $b) => [mb_strtolower($a['type']), mb_strtolower($a['name']), $a['id']] <=> [mb_strtolower($b['type']), mb_strtolower($b['name']), $b['id']]);
        foreach ($rows as &$r) $r['linked_via'] = implode(', ', $r['via']); unset($r);
        return $rows;
    }

    /** Drive preview link: file id from /d/<id> or ?id=<id>; none → the link itself. */
    public static function preview(?string $url): ?string
    {
        if (! $url) return null;
        if (preg_match('~/d/([A-Za-z0-9_-]{10,})~', $url, $m) || preg_match('~[?&]id=([A-Za-z0-9_-]{10,})~', $url, $m)) return 'https://drive.google.com/file/d/' . $m[1] . '/preview';
        return $url;
    }

    public static function forPo(int $ent, int $poId): array
    {
        $po = DB::table('t_src_po')->where('id', $poId)->where('fk_id_ent', $ent)->first(['id', 'po_number', 'po_code', 'fk_id_cycle']) ?? throw new \InvalidArgumentException('Not found.');
        return ['po' => ['id' => (int) $po->id, 'number' => $po->po_number ?: $po->po_code, 'cycle' => (int) $po->fk_id_cycle ?: null], 'rows' => self::collect($ent, self::poPaths($ent, $po)), 'note' => self::NOTE,
            'add_url' => $po->fk_id_cycle ? "#/cycle-extras/{$po->fk_id_cycle}?po={$po->id}&add=1" : null, 'add_preset' => ['fk_id_po' => (int) $po->id, 'fk_id_cycle' => (int) $po->fk_id_cycle ?: null]];
    }

    public static function forCycle(int $ent, int $cy): array
    {
        $c = DB::table('t_src_cycles')->where('id', $cy)->where('fk_id_ent', $ent)->first(['id', 'cycle_code']) ?? throw new \InvalidArgumentException('Not found.');
        return ['cycle' => ['id' => (int) $c->id, 'code' => $c->cycle_code], 'rows' => self::collect($ent, self::cyclePaths($cy)), 'note' => self::NOTE, 'add_preset' => ['fk_id_cycle' => $cy]];
    }

    /** A product: its master documents and every document of every cycle it is in. */
    public static function forProduct(int $ent, int $productId): array
    {
        $p = DB::table('t_product_vendors')->where('id', $productId)->where('fk_id_ent', $ent)->first(['id', 'SKU_VENDOR']) ?? throw new \InvalidArgumentException('Not found.');
        $paths = ['Product Master' => ['fk_id_product', [$productId]]];
        $cycles = DB::table('t_src_cycle_products')->where('fk_id_product', $productId)->where('f_active', 1)->pluck('fk_id_cycle')->merge(DB::table('t_src_po_lines')->where('fk_id_product', $productId)->where('f_active', 1)->pluck('fk_id_cycle'))->filter()->unique();
        foreach ($cycles as $cy) foreach (self::cyclePaths((int) $cy) as $label => [$col, $ids]) { $paths[$label][0] = $col; $paths[$label][1] = array_values(array_unique(array_merge($paths[$label][1] ?? [], $ids))); }
        return ['product' => ['id' => (int) $p->id, 'sku' => $p->SKU_VENDOR], 'rows' => self::collect($ent, $paths), 'note' => self::NOTE, 'add_preset' => ['fk_id_product' => $productId]];
    }
}
