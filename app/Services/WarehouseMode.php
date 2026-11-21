<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Simple mode to full mode by settings (R-H-7, decision W-3). The same module runs a one-room store or a large warehouse; only settings change.
 * Two preset buttons fill the switches (each switch stays editable on Settings). Switching from full to simple never loses data:
 * open putaway tasks must be finished first ("3 putaway tasks open").
 * WH_PUTAWAY_STEP = N: a GRN line posts straight to the SKU's PRIMARY home bin. Y: it posts to RECEIVING and a putaway task (WH_PUTAWAY_WAITING) opens,
 * closed when the units are put away (putaway()).
 */
class WarehouseMode
{
    public const SWITCHES = [
        'WH_ZONES_ON' => ['Zones used', 'N', 'Y'],
        'WH_PUTAWAY_STEP' => ['Receive to RECEIVING, then put away', 'N', 'Y'],
        'WH_PICK_MODE' => ['Pick mode', 'SINGLE', 'BATCH'],
        'WH_REPLEN_MODE' => ['Replenishment', 'OFF', 'TASK'],
        'WH_HANDOVER_SCAN_REQUIRED' => ['Handover scan required', 'Y', 'Y'],
        'WH_PACK_SCAN_REQUIRED' => ['Pack scan required', 'N', 'Y'],
        'WH_CYCLE_COUNT_MODE' => ['Cycle counts', 'TRIGGERS_ONLY', 'ABC_SCHEDULE'],
        'WH_STORAGE_MODE' => ['Storage mode', 'FIXED', 'FIXED'],
    ];

    public static function openPutaways(int $ent): int
    {
        return DB::table('tasks')->where('rule_code', 'WH_PUTAWAY_WAITING')->where(fn ($w) => $w->where('fk_id_ent', $ent)->orWhereNull('fk_id_ent'))->whereIn('status', ['TODO', 'DOING', 'STUCK', 'REWORK'])->count();
    }

    public static function page(int $ent): array
    {
        $now = []; foreach (self::SWITCHES as $k => [$label, $s, $f]) $now[] = ['key' => $k, 'label' => $label, 'value' => (string) Settings::get($k, $ent, $s), 'simple' => $s, 'full' => $f];
        $preset = 'CUSTOM'; foreach (['SIMPLE' => 1, 'FULL' => 2] as $p => $i) if (collect($now)->every(fn ($x) => $x['value'] === ($i === 1 ? $x['simple'] : $x['full']))) $preset = $p;
        return ['switches' => $now, 'preset' => $preset, 'open_putaways' => self::openPutaways($ent)];
    }

    /** A preset button: fill the switches. Full → Simple waits until no putaway task is open. */
    public static function apply(int $ent, string $preset, object $u): array
    {
        $preset = strtoupper($preset); if (! in_array($preset, ['SIMPLE', 'FULL'], true)) throw new \InvalidArgumentException('Choose Simple or Full.');
        if ($preset === 'SIMPLE' && ($n = self::openPutaways($ent))) throw new \InvalidArgumentException("$n putaway task" . ($n === 1 ? '' : 's') . ' open – finish them first. Switching to Simple never loses data.');
        $changed = [];
        foreach (self::SWITCHES as $k => [, $s, $f]) { $v = $preset === 'SIMPLE' ? $s : $f; $old = (string) Settings::get($k, $ent, $s); if ($old !== $v) { Settings::set($k, $v, $ent, $u->id); $changed[$k] = [$old, $v]; } }
        Settings::set('WH_PRESET', $preset, $ent, $u->id);
        Audit::log('sys_settings', 0, 'UPDATE', $changed + ['WH_PRESET' => $preset], 'Warehouse preset ' . $preset, $ent);
        return ['changed' => count($changed), 'message' => ($preset === 'SIMPLE' ? 'Simple' : 'Full') . ' preset applied – ' . count($changed) . ' switch(es) changed.'];
    }

    /** Where a GRN line goes: RECEIVING (putaway step on), else the SKU's PRIMARY home bin, else the line's own location. */
    public static function receiveTo(int $ent, int $product, int $lineLoc): int
    {
        if (Settings::get('WH_PUTAWAY_STEP', $ent, 'N') === 'Y') return StockService::systemLocation($ent, 'RECEIVING');
        $site = DB::table('cat_inventory_locations')->where('id', $lineLoc)->value('fk_id_site');
        $home = DB::table('t_product_locations as h')->join('cat_inventory_locations as l', 'l.id', '=', 'h.fk_id_location')->where('h.fk_id_product', $product)->where('h.home_role', 'PRIMARY')->where('l.fk_id_ent', $ent)->where('l.f_active', 1)
            ->orderByRaw('l.fk_id_site <=> ? DESC', [$site])->value('h.fk_id_location');
        return (int) ($home ?: $lineLoc);
    }

    /** After a GRN posted to RECEIVING: one putaway task per product. */
    public static function openPutaway(int $ent, int $grnId, int $product, int $qty, string $grnNo): void
    {
        $sku = (string) DB::table('t_product_vendors')->where('id', $product)->value('SKU_VENDOR');
        TaskEngine::open('WH_PUTAWAY_WAITING', [$grnId, $product], "Put away $qty × $sku from RECEIVING ($grnNo)", ['ent' => $ent, 'module' => 't_src_grn', 'record_id' => $grnId, 'fix_url' => '#/putaway']);
    }

    /** Open putaways: what waits in RECEIVING, with the suggested bin. */
    public static function putaways(int $ent): array
    {
        $rec = StockService::systemLocation($ent, 'RECEIVING');
        return DB::table('tasks')->where('rule_code', 'WH_PUTAWAY_WAITING')->where(fn ($w) => $w->where('fk_id_ent', $ent)->orWhereNull('fk_id_ent'))->whereIn('status', ['TODO', 'DOING', 'STUCK', 'REWORK'])->orderBy('id')->get(['id', 'title', 'dedupe_key', 'linked_record_id'])
            ->map(function ($t) use ($ent, $rec) {
                [, $grn, $product] = array_pad(explode(':', (string) $t->dedupe_key), 3, 0);
                $qty = (int) DB::table('fin_lot_balances as b')->join('fin_lots as l', 'l.id', '=', 'b.fk_id_lot')->where('b.fk_id_location', $rec)->where('l.fk_id_product', (int) $product)->sum('b.qty');
                $s = SkuHomes::suggest($ent, (int) $product, $qty);
                return ['task' => (int) $t->id, 'title' => $t->title, 'grn' => (int) $grn, 'product' => (int) $product, 'sku' => DB::table('t_product_vendors')->where('id', $product)->value('SKU_VENDOR'), 'qty' => $qty, 'to' => $s[0] ?? null];
            })->all();
    }

    /** Put the units away: RECEIVING → the chosen bin; the task closes when nothing of the product waits in RECEIVING. */
    public static function putaway(int $ent, int $taskId, int $bin, ?int $qty = null): array
    {
        $t = DB::table('tasks')->where('id', $taskId)->where('rule_code', 'WH_PUTAWAY_WAITING')->first() ?? throw new \InvalidArgumentException('Putaway task not found.');
        [, $grn, $product] = array_pad(explode(':', (string) $t->dedupe_key), 3, 0); $rec = StockService::systemLocation($ent, 'RECEIVING');
        $have = (int) DB::table('fin_lot_balances as b')->join('fin_lots as l', 'l.id', '=', 'b.fk_id_lot')->where('b.fk_id_location', $rec)->where('l.fk_id_product', (int) $product)->sum('b.qty');
        $qty = $qty ?: $have; if ($qty <= 0 || $qty > $have) throw new \InvalidArgumentException("Only $have unit(s) wait in RECEIVING.");
        StockService::transfer($ent, (int) $product, $qty, $rec, $bin, 'Putaway of GRN #' . $grn);
        if ($qty === $have) TaskEngine::close('WH_PUTAWAY_WAITING', [(int) $grn, (int) $product], 'Put away');
        return ['message' => "$qty unit(s) put away." . ($qty < $have ? ' ' . ($have - $qty) . ' still in RECEIVING.' : '')];
    }
}
