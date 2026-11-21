<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Save & Next with carried context (R-B_SOURCING-18). Every step form of a cycle has "Save & Next: <next step>". After the save the server
 * knows the new record (no "last id" guess), adds it and its parent ids to the cycle's context (po, cycle product, shipment, GRN, term, payment,
 * QC, customs, supplier, product) and opens the next step's form with its parent fields filled and locked (an unlock icon changes them).
 * The context is kept server-side per cycle and user (sys_workflow_context), so it survives reloads, devices and new sessions; any step form
 * opened inside the cycle takes its parents from it when empty (linked:workflow.<x>). Steps run in the workspace order; SKIPPED steps are jumped.
 * Before the next form opens, records that already exist for the same context are named ("This PO already has 1 shipment (SHP-…)", R-B_SOURCING-19).
 * Floating Previous / Next on every step place of the cycle record in the same order, skipped steps jumped (R-B_SOURCING-20, pager()).
 */
class WorkflowContext
{
    /** [key, form, label, skip group] in the workspace order */
    public const STEPS = [
        ['details', 't_src_cycles', 'Cycle details', null], ['rfq', 't_src_rfq', 'RFQ / Quotations', 'rfq'], ['samples', 't_src_samples', 'Samples', 'samples'],
        ['po', 't_src_po', 'Purchase order', 'po'], ['po_lines', 't_src_po_lines', 'PO lines', 'po'], ['cartons', 't_src_cartons', 'Cartons', 'po'],
        ['terms', 't_src_payment_terms', 'Payment schedule', 'pay'], ['payments', 't_src_payments', 'Payments', 'pay'], ['production', 't_src_production', 'Production', 'production'],
        ['qc', 't_src_qc', 'QC / Inspections', 'qc'], ['investment', 't_src_investments', 'Investments', null], ['allocation', 't_src_allocations', 'Destination allocation', 'allocation'],
        ['shipment', 't_src_shipments', 'Shipments', 'shipment'], ['freight', 't_src_freight', 'Freight', 'freight'], ['customs', 't_src_customs', 'Customs / Import', 'customs'],
        ['grn', 't_src_grn', 'Landing / GRN', 'grn'], ['receipt', 't_src_inventory_receipts', 'Inventory receiving', 'landed_cost'], ['landed', 't_src_landed_cost', 'Landed cost', 'landed_cost'],
        ['ownership', 't_src_ownership_transfers', 'Ownership transfers', null], ['platform', 't_src_platform_alloc', 'Platform allocation', null], ['documents', 't_src_documents', 'Documents', null],
    ];
    /** context key => column on the step tables */
    public const KEYS = ['po' => 'fk_id_po', 'cp' => 'fk_id_cycle_product', 'shipment' => 'fk_id_shipment', 'grn' => 'fk_id_grn', 'supplier' => 'fk_id_supplier', 'term' => 'fk_id_term',
        'payment' => 'fk_id_payment', 'qc' => 'fk_id_qc', 'customs' => 'fk_id_customs', 'product' => 'fk_id_product'];
    /** a saved record of this table IS this context value */
    public const SELF = ['t_src_po' => 'po', 't_src_shipments' => 'shipment', 't_src_grn' => 'grn', 't_src_payment_terms' => 'term', 't_src_payments' => 'payment', 't_src_qc' => 'qc', 't_src_customs' => 'customs', 't_src_cycle_products' => 'cp'];
    /** which parents each next form takes (supplier everywhere, when the table has it) */
    public const PREFILL = [
        't_src_po_lines' => ['po'], 't_src_cartons' => ['po', 'cp'], 't_src_payment_terms' => ['po'], 't_src_production' => ['po'], 't_src_landed_cost' => ['po'],
        't_src_allocations' => ['po', 'cp'], 't_src_customs' => ['shipment'], 't_src_freight' => ['shipment'], 't_src_grn' => ['shipment'], 't_src_inventory_receipts' => ['grn'],
        't_src_investments' => ['po'], 't_src_qc' => ['po'], 't_src_shipments' => ['po'], 't_src_payments' => ['term', 'po'], 't_src_ownership_transfers' => ['cp'], 't_src_platform_alloc' => ['cp'],
        't_src_samples' => ['cp'], 't_src_documents' => ['shipment', 'grn', 'term', 'payment', 'qc', 'customs', 'cp'],
    ];

    public static function stepOf(string $form): ?array
    {
        foreach (self::STEPS as $i => $s) if ($s[1] === $form) return ['index' => $i, 'key' => $s[0], 'label' => $s[2], 'next' => self::STEPS[$i + 1][2] ?? null];
        return null;
    }

    /** The next step after $form that the cycle does not skip. */
    public static function next(string $form, int $cycleId): ?array
    {
        $at = self::stepOf($form); if (! $at) return null;
        $skip = array_filter(array_map('trim', explode(',', (string) DB::table('t_src_cycles')->where('id', $cycleId)->value('skipped_steps'))));
        foreach (array_slice(self::STEPS, $at['index'] + 1) as $s) if (! $s[3] || ! in_array($s[3], $skip, true)) return ['key' => $s[0], 'form' => $s[1], 'label' => $s[2]];
        return null;
    }

    /**
     * R-B_SOURCING-20 step pager: where each step lives inside the cycle record – [page, section heading]. page 'cycle' = #/cycle/<id>,
     * 'extras' = #/cycle-extras/<id>, 'list' = the step's own list filtered to the cycle. Money sections fall back to the list without see money.
     */
    public const PLACE = [
        'details' => ['cycle', null], 'rfq' => ['cycle', '1. Quotes'], 'po' => ['cycle', '2. Purchase orders'], 'terms' => ['cycle', '3. Payments', true], 'payments' => ['cycle', '3. Payments', true],
        'shipment' => ['cycle', '4. Shipments'], 'grn' => ['cycle', '5. Receiving'], 'landed' => ['cycle', '6. Landed cost', true],
        'samples' => ['extras', 'Samples'], 'qc' => ['extras', 'Quality checks'], 'customs' => ['extras', 'Customs'],
    ];

    /** The steps of the cycle in the workspace order, SKIPPED ones left out. */
    public static function steps(int $cycleId): array
    {
        $skip = array_filter(array_map('trim', explode(',', (string) DB::table('t_src_cycles')->where('id', $cycleId)->value('skipped_steps'))));
        return array_values(array_filter(self::STEPS, fn ($s) => ! $s[3] || ! in_array($s[3], $skip, true)));
    }

    /** Link and section of one step inside the cycle record. */
    public static function place(string $key, int $cycleId, bool $money = true): array
    {
        $s = collect(self::STEPS)->firstWhere(0, $key) ?? throw new \InvalidArgumentException('Unknown step.');
        [$page, $section, $isMoney] = (self::PLACE[$key] ?? ['list', null]) + [2 => false];
        if ($isMoney && ! $money) [$page, $section] = ['list', null];
        $href = match ($page) { 'cycle' => "#/cycle/$cycleId", 'extras' => "#/cycle-extras/$cycleId", default => "#/list/{$s[1]}?f[fk_id_cycle]=$cycleId" };
        return ['key' => $key, 'label' => $s[2], 'href' => $href . (str_contains($href, '?') ? '&' : '?') . 'step=' . $key, 'section' => $section];
    }

    /** Previous / Next of a step (skipped steps jumped); a skipped or unknown current step starts from its place in the full order. */
    public static function pager(int $cycleId, string $key, bool $money = true): array
    {
        $all = array_column(self::STEPS, 0); if (! in_array($key, $all, true)) $key = 'details';
        $on = array_column(self::steps($cycleId), 0); $at = array_search($key, $all, true);
        $prev = null; for ($i = $at - 1; $i >= 0; $i--) if (in_array($all[$i], $on, true)) { $prev = $all[$i]; break; }
        $next = null; for ($i = $at + 1; $i < count($all); $i++) if (in_array($all[$i], $on, true)) { $next = $all[$i]; break; }
        return ['current' => self::place($key, $cycleId, $money) + ['skipped' => ! in_array($key, $on, true), 'no' => array_search($key, $on, true) === false ? null : array_search($key, $on, true) + 1, 'of' => count($on)],
            'prev' => $prev ? self::place($prev, $cycleId, $money) : null, 'next' => $next ? self::place($next, $cycleId, $money) : null];
    }

    public static function get(int $cycleId, int $userId): array
    {
        $j = DB::table('sys_workflow_context')->where(['workflow' => 'SRC_CYCLE', 'entity_id' => $cycleId, 'user_id' => $userId])->value('context_json');
        return $j ? (json_decode($j, true) ?: []) : [];
    }

    /** After a step record is saved: its id (when it is a context record) and its parent ids join the context. */
    public static function remember(string $table, int $id, int $userId): array
    {
        if (! Schema::hasColumn($table, 'fk_id_cycle')) return [];
        $row = (array) DB::table($table)->where('id', $id)->first(); $cycle = (int) ($row['fk_id_cycle'] ?? 0); if (! $cycle) return [];
        $ctx = self::get($cycle, $userId);
        foreach (self::KEYS as $k => $col) if (! empty($row[$col])) $ctx[$k] = (int) $row[$col];
        if (isset(self::SELF[$table])) $ctx[self::SELF[$table]] = $id;
        DB::table('sys_workflow_context')->updateOrInsert(['workflow' => 'SRC_CYCLE', 'entity_id' => $cycle, 'user_id' => $userId], ['context_json' => json_encode($ctx), 'updated_at' => now()]);
        return $ctx;
    }

    /** Parent values for a step form of the cycle from the context (only columns the table has). */
    public static function prefill(string $form, int $cycleId, array $ctx): array
    {
        $table = Registry::table($form) ?? $form; $out = ['fk_id_cycle' => $cycleId];
        foreach (array_merge(self::PREFILL[$table] ?? [], ['supplier']) as $k) { $col = self::KEYS[$k]; if (! empty($ctx[$k]) && Schema::hasColumn($table, $col)) $out[$col] = $ctx[$k]; }
        if ($table === 't_src_payments' && ! empty($out['fk_id_term']) && empty($out['fk_id_po'])) $out['fk_id_po'] = (int) DB::table('t_src_payment_terms')->where('id', $out['fk_id_term'])->value('fk_id_po');
        return $out;
    }

    /** Records of the next step that already exist for this context (cycle + PO / cycle product / shipment / GRN where the table has them). */
    public static function existing(string $table, array $pre): array
    {
        if (! Schema::hasTable($table)) return [];
        $q = DB::table($table)->where('fk_id_cycle', $pre['fk_id_cycle']); if (Schema::hasColumn($table, 'f_active')) $q->where('f_active', 1);
        foreach (['fk_id_po', 'fk_id_cycle_product', 'fk_id_shipment', 'fk_id_grn'] as $c) if (! empty($pre[$c]) && Schema::hasColumn($table, $c)) $q->where($c, $pre[$c]);
        $lc = 'id'; foreach (['po_number', 'shipment_code', 'grn_number', 'qc_code', 'customs_code', 'sample_code', 'rfq_code', 'freight_code', 'receipt_code', 'payment_code'] as $c) if (Schema::hasColumn($table, $c)) { $lc = $c; break; }
        if ($lc === 'id') $lc = Registry::labelColumn($table) ?: 'id';
        return $q->orderByDesc('id')->limit(10)->get(['id', $lc . ' as label'])->map(fn ($r) => ['id' => (int) $r->id, 'label' => (string) ($r->label ?: '#' . $r->id)])->all();
    }

    /** "Save & Next": the next step, its preset (locked parents) and what already exists. */
    public static function saveNext(string $form, int $recordId, int $userId): array
    {
        $table = Registry::table($form) ?? $form;
        $row = DB::table($table)->where('id', $recordId)->first() ?? throw new \InvalidArgumentException('The saved record was not found.');
        $cycle = (int) ($table === 't_src_cycles' ? $recordId : ($row->fk_id_cycle ?? 0)); if (! $cycle) throw new \InvalidArgumentException('This record is not part of a cycle.');
        $ctx = $table === 't_src_cycles' ? self::get($cycle, $userId) : self::remember($table, $recordId, $userId);
        $next = self::next($form, $cycle); if (! $next) return ['next' => null, 'message' => 'This was the last step of the cycle.'];
        $pre = self::prefill($next['form'], $cycle, $ctx); $ex = self::existing($next['form'], $pre);
        $parentLabel = ! empty($pre['fk_id_po']) ? 'This PO' : (! empty($pre['fk_id_shipment']) ? 'This shipment' : (! empty($pre['fk_id_grn']) ? 'This GRN' : 'This cycle'));
        return ['next' => $next, 'preset' => $pre, 'locked' => Choices::get('SAVE_NEXT_LOCK', (int) (DB::table('t_src_cycles')->where('id', $cycle)->value('fk_id_ent') ?: 0) ?: null) === '1' ? array_keys($pre) : [] /* Settings › Choices */, 'context' => $ctx, 'existing' => $ex,
            'existing_text' => $ex ? "$parentLabel already has " . count($ex) . ' ' . $next['label'] . (count($ex) === 1 ? ' record' : ' records') . ' (' . implode(', ', array_column(array_slice($ex, 0, 3), 'label')) . ').' : null];
    }
}
