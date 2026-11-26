<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-D-45 / MR-0219 / 0887 the Inventory setup page (old Inventory_setup, 4 catalogs) – one page, every catalog with its use:
 *   location types (the central list LOCATION_KIND; system kinds cannot be switched off), locations (site › zone › bin tree, with units in each),
 *   adjustment reasons and price-change reasons (add, rename, switch off; a name once, any case; a reason in use is never deleted, only switched off).
 */
class InventorySetup
{
    public const REASONS = ['adjust' => 'cat_adjustment_reasons', 'price' => 'cat_price_change_reasons'];

    public static function page(int $ent): array
    {
        $units = DB::table('fin_lot_balances')->groupBy('fk_id_location')->selectRaw('fk_id_location l, SUM(qty) q')->pluck('q', 'l');
        $locs = DB::table('cat_inventory_locations')->where('fk_id_ent', $ent)->orderBy('location_name')->get(['id', 'location_name', 'kind', 'fk_id_parent', 'f_active', 'f_sellable', 'location_code']);
        $kinds = DB::table('sys_field_options')->where('list_code', 'LOCATION_KIND')->orderBy('sort')->get(['value', 'label', 'f_active']);
        $byKind = $locs->groupBy(fn ($l) => strtoupper((string) $l->kind));
        $used = ['adjust' => DB::table('t_stock_movements')->whereNotNull('fk_id_reason')->groupBy('fk_id_reason')->selectRaw('fk_id_reason r, COUNT(*) n')->pluck('n', 'r')->all(),
            'price' => \Illuminate\Support\Facades\Schema::hasColumn('t_product_inventory_change_log', 'fk_id_reason') ? DB::table('t_product_inventory_change_log')->whereNotNull('fk_id_reason')->groupBy('fk_id_reason')->selectRaw('fk_id_reason r, COUNT(*) n')->pluck('n', 'r')->all() : []];
        $reasons = []; foreach (self::REASONS as $k => $t) $reasons[$k] = DB::table($t)->orderBy('reason_name')->get(['id', 'reason_name', 'f_active', 'f_system'])->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->reason_name, 'active' => (bool) $r->f_active, 'system' => (bool) $r->f_system, 'used' => (int) ($used[$k][$r->id] ?? 0)])->all();
        $tree = []; $kids = $locs->groupBy(fn ($l) => (int) $l->fk_id_parent);
        $walk = function (int $parent, int $depth) use (&$walk, &$tree, $kids, $units) { foreach ($kids[$parent] ?? [] as $l) { if ($depth > 8) return;
            $tree[] = ['id' => (int) $l->id, 'name' => $l->location_name, 'code' => $l->location_code, 'kind' => $l->kind, 'depth' => $depth, 'active' => (bool) $l->f_active, 'sellable' => $l->f_sellable === null ? true : (bool) $l->f_sellable, 'units' => (int) ($units[$l->id] ?? 0)];
            $walk((int) $l->id, $depth + 1); } };
        $walk(0, 0);
        $seen = array_flip(array_column($tree, 'id')); foreach ($locs as $l) if (! isset($seen[$l->id])) $tree[] = ['id' => (int) $l->id, 'name' => $l->location_name, 'code' => $l->location_code, 'kind' => $l->kind, 'depth' => 0, 'active' => (bool) $l->f_active, 'sellable' => (bool) ($l->f_sellable ?? 1), 'units' => (int) ($units[$l->id] ?? 0), 'orphan' => true];
        return ['kinds' => $kinds->map(fn ($k) => ['value' => $k->value, 'label' => $k->label, 'active' => (bool) $k->f_active, 'system' => str_contains(mb_strtolower((string) $k->label), 'system'), 'locations' => count($byKind[strtoupper((string) $k->value)] ?? [])])->all(),
            'locations' => $tree, 'reasons' => $reasons];
    }

    /** Add, rename or switch a reason on / off. */
    public static function reason(string $kind, array $in, ?object $u): array
    {
        Perm::need($u, 'stock.CHANGE'); $t = self::REASONS[$kind] ?? throw new \InvalidArgumentException('Unknown list.');
        $id = (int) ($in['id'] ?? 0); $old = $id ? DB::table($t)->where('id', $id)->first() : null; if ($id && ! $old) throw new \InvalidArgumentException('Not found.');
        $set = [];
        if (array_key_exists('name', $in)) { $n = trim(preg_replace('/\s+/', ' ', (string) $in['name'])); if ($n === '') throw new \InvalidArgumentException('Write the reason.'); if (mb_strlen($n) > 100) throw new \InvalidArgumentException('At most 100 letters.');
            if ($old && (int) $old->f_system && $n !== $old->reason_name) throw new \InvalidArgumentException('A system reason keeps its name.');
            if (DB::table($t)->whereRaw('LOWER(TRIM(reason_name)) = ?', [mb_strtolower($n)])->when($id, fn ($w) => $w->where('id', '<>', $id))->exists()) throw new \InvalidArgumentException("“{$n}” is already in the list.");
            $set['reason_name'] = $n; }
        if (array_key_exists('active', $in)) { if ($old && (int) $old->f_system && ! $in['active']) throw new \InvalidArgumentException('A system reason cannot be switched off.'); $set['f_active'] = $in['active'] ? 1 : 0; }
        if (! $set) throw new \InvalidArgumentException('Nothing to save.');
        if ($id) DB::table($t)->where('id', $id)->update($set);
        else $id = (int) DB::table($t)->insertGetId($set + ['f_active' => 1, 'f_system' => 0, 'created_by' => $u->id ?? null, 'created_at' => now()]);
        Audit::log($t, $id, $old ? 'UPDATE' : 'INSERT', array_map(fn ($k) => [$old->$k ?? null, $set[$k]], array_combine(array_keys($set), array_keys($set))), 'Inventory setup');
        return ['message' => $old ? 'Saved.' : 'Added – it is in every adjustment / price form now.', 'id' => $id];
    }
}
