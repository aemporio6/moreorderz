<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * RULES_H_WAREHOUSE
 * - R-H-3 system bins per managed site: switching a site to managed makes UNASSIGNED, RECEIVING, DISPATCH, SHIPPED, RETURNS, QUARANTINE, DAMAGED, MISSING
 *   (code = WH_SYSTEM_BIN_PREFIX + role). Sellable only UNASSIGNED (WH_UNASSIGNED_SELLABLE, default Y) and RECEIVING when WH_RECEIVING_SELLABLE = Y.
 *   A system bin cannot be deactivated, deleted or given another role while its site is active.
 * - R-H-6 a bin that holds units cannot be deactivated ("Move the 12 units first").
 * - R-H-8 a site belongs to one company and may be shared with other companies of the group (shared_companies); a bin shows only the SKUs of the
 *   viewer's company plus "+ n other SKUs not in your scope".
 * - R-H-14 go-live: dry run per SKU – book units at the site vs the old shelf rows; with a migration map (old shelf → new bin) each row is placed up to
 *   the book units not yet placed, the rest goes to UNASSIGNED; a second run changes nothing. Book data is never touched.
 */
class WarehouseSites
{
    /** role => [name, pickable, receivable] (sellable from the settings). */
    public const SYSTEM = [
        'UNASSIGNED' => ['Unassigned (not yet put away)', 0, 0], 'RECEIVING' => ['Receiving', 0, 1], 'DISPATCH' => ['Dispatch area', 0, 0], 'SHIPPED' => ['Shipped (awaiting month confirm)', 0, 0],
        'RETURNS' => ['Returns', 0, 1], 'QUARANTINE' => ['Quarantine', 0, 1], 'DAMAGED' => ['Damaged', 0, 1], 'MISSING' => ['Missing (count losses)', 0, 0],
    ];

    private static function site(int $ent, int $id): object
    {
        $s = DB::table('cat_inventory_locations')->where('id', $id)->first();
        if (! $s || $s->kind !== 'WAREHOUSE' || ! self::mine($s, $ent)) throw new \InvalidArgumentException('Site not found.');
        return $s;
    }

    /** The location belongs to the company, or its site is shared with it (R-H-8). */
    public static function mine(object $loc, int $ent): bool
    {
        if ((int) $loc->fk_id_ent === $ent || ! $loc->fk_id_ent) return true;
        $site = $loc->kind === 'WAREHOUSE' ? $loc : ($loc->fk_id_site ? DB::table('cat_inventory_locations')->where('id', $loc->fk_id_site)->first() : null);
        return $site && in_array((string) $ent, array_map('trim', explode(',', (string) $site->shared_companies)), true);
    }

    private static function sellable(int $ent, string $role): int
    {
        if ($role === 'UNASSIGNED') return Settings::get('WH_UNASSIGNED_SELLABLE', $ent, 'Y') === 'Y' ? 1 : 0;
        if ($role === 'RECEIVING') return Settings::get('WH_RECEIVING_SELLABLE', $ent, 'N') === 'Y' ? 1 : 0;
        return 0;
    }

    /** Switch a site to managed (or back): on = the 8 system bins exist (made once, never twice). Returns the system bins. */
    public static function manage(int $ent, int $siteId, bool $on): array
    {
        $s = self::site($ent, $siteId);
        return DB::transaction(function () use ($ent, $s, $on) {
            DB::table('cat_inventory_locations')->where('id', $s->id)->update(['f_wh_managed' => $on ? 1 : 0]);
            if ($on) self::ensureBins($ent, (int) $s->id);
            Audit::log('cat_inventory_locations', (int) $s->id, 'UPDATE', ['f_wh_managed' => [(int) $s->f_wh_managed, $on ? 1 : 0]], 'warehouse site', $ent);
            return self::bins((int) $s->id);
        });
    }

    public static function ensureBins(int $ent, int $siteId): void
    {
        $pre = (string) (Settings::get('WH_SYSTEM_BIN_PREFIX', $ent, 'SYS-') ?: 'SYS-'); $site = DB::table('cat_inventory_locations')->where('id', $siteId)->first();
        foreach (self::SYSTEM as $role => [$name, $pick, $recv]) {
            if (DB::table('cat_inventory_locations')->where('fk_id_site', $siteId)->where('system_role', $role)->exists()) continue;
            DB::table('cat_inventory_locations')->insert(['location_name' => $name, 'fk_id_location_type' => 0, 'fk_id_parent' => $siteId, 'fk_id_site' => $siteId, 'f_active' => 1, 'created_at' => now(),
                'fk_id_ent' => (int) ($site->fk_id_ent ?: $ent), 'location_code' => substr($pre . $role, 0, 30), 'kind' => $role, 'system_role' => $role, 'f_sellable' => self::sellable($ent, $role),
                'f_pickable' => $pick, 'f_receivable' => $recv, 'barcode_value' => substr((string) (Settings::get('WH_BIN_BARCODE_PREFIX', $ent, 'L:') ?: 'L:') . $pre . $role, 0, 60)]);
        }
    }

    public static function bins(int $siteId): array
    {
        return DB::table('cat_inventory_locations')->where('fk_id_site', $siteId)->whereNotNull('system_role')->orderBy('id')->get(['id', 'location_code', 'location_name', 'system_role', 'f_sellable', 'f_active'])->map(fn ($b) => (array) $b)->all();
    }

    /** The system bin of a site (null when the site is not managed). */
    public static function systemBin(int $siteId, string $role): ?int
    {
        $id = DB::table('cat_inventory_locations')->where('fk_id_site', $siteId)->where('system_role', $role)->value('id');
        return $id ? (int) $id : null;
    }

    /** The settings changed: the sellable flag of UNASSIGNED / RECEIVING follows them on every site of the company. */
    public static function syncSellable(int $ent): int
    {
        $n = 0;
        foreach (['UNASSIGNED', 'RECEIVING'] as $r) $n += DB::table('cat_inventory_locations')->where('fk_id_ent', $ent)->where('system_role', $r)->update(['f_sellable' => self::sellable($ent, $r)]);
        return $n;
    }

    /** The site (kind WAREHOUSE) a location sits in, walking up the tree. */
    public static function siteOf(int $id): ?int
    {
        for ($i = 0; $i < 8 && $id; $i++) { $r = DB::table('cat_inventory_locations')->where('id', $id)->first(['id', 'kind', 'fk_id_parent']); if (! $r) return null; if ($r->kind === 'WAREHOUSE') return (int) $r->id; $id = (int) $r->fk_id_parent; }
        return null;
    }

    /** A location and everything under it (site › zone › bin). */
    public static function under(int $id): array
    {
        $all = [$id]; $level = [$id];
        for ($i = 0; $i < 8 && $level; $i++) { $level = DB::table('cat_inventory_locations')->whereIn('fk_id_parent', $level)->pluck('id')->map(fn ($x) => (int) $x)->all(); $level = array_values(array_diff($level, $all)); $all = array_merge($all, $level); }
        return $all;
    }

    /** R-H-6 has this location ever held stock or been in a movement? Then it is only ever deactivated. */
    public static function hasHistory(int $id): bool
    {
        return DB::table('fin_lot_balances')->where('fk_id_location', $id)->exists() || DB::table('t_stock_movements')->where('fk_id_location', $id)->exists()
            || DB::table('wh_movements')->where(fn ($w) => $w->where('fk_id_from_location', $id)->orWhere('fk_id_to_location', $id))->exists();
    }

    /** R-H-6 after a save: a renamed bin keeps its id (the audit line shows old → new) and one shelf label is queued. */
    public static function afterSave(int $id, array $old): ?string
    {
        if (! $old) return null;
        $now = DB::table('cat_inventory_locations')->where('id', $id)->first(['kind', 'location_name', 'location_code', 'fk_id_ent', 'f_active']);
        if (! $now || $now->kind !== 'BIN' || ! $now->f_active) return null;
        if ((string) $now->location_name === (string) ($old['location_name'] ?? '') && (string) $now->location_code === (string) ($old['location_code'] ?? '')) return null;
        try { PrintService::print((int) ($now->fk_id_ent ?: Context::ent()), 'LOCATION', [$id], ['trigger' => 'RENAME', 'reason' => 'Bin renamed ' . ($old['location_name'] ?? '') . ' → ' . $now->location_name]); return 'One shelf label queued.'; }
        catch (\Throwable $e) { return 'Shelf label not queued: ' . $e->getMessage(); }
    }

    /** FormRules hook for a location (R-H-3 / 6). */
    public static function check(array &$data, array $old, ?int $id): array
    {
        /* R-D-45: a location can never sit under itself or under one of its own children (the tree would loop) */
        $par = (int) ($data['fk_id_parent'] ?? 0);
        if ($id && $par) { $seen = []; for ($p = $par; $p && ! isset($seen[$p]) && count($seen) < 50; $p = (int) DB::table('cat_inventory_locations')->where('id', $p)->value('fk_id_parent')) {
            if ($p === $id) return ['fk_id_parent' => $par === $id ? 'A location cannot be its own parent.' : 'This parent is inside this location – choose a parent above it.']; $seen[$p] = 1; } }
        /* R-H-2 one tree site › zone › bin: a zone or bin takes its site from its parent; the old kind SHELF is read as BIN; a bin code is unique in its site */
        $kind = strtoupper((string) ($data['kind'] ?? $old['kind'] ?? ''));
        if ($kind === 'SHELF') { $data['kind'] = $kind = 'BIN'; }
        if (in_array($kind, ['ZONE', 'BIN'], true) && ($parent = (int) ($data['fk_id_parent'] ?? $old['fk_id_parent'] ?? 0))) {
            $site = self::siteOf($parent); if (! $site) return ['fk_id_parent' => 'Choose a site or a zone of a site as the parent.'];
            $data['fk_id_site'] = $site;
            $name = trim((string) ($data['location_name'] ?? $old['location_name'] ?? ''));
            if ($kind === 'BIN' && $name !== '') {      /* R-H-4: unique per site – letter case and separators do not make a new code */
                $n = LocationGenerator::norm($name); $siteName = (string) DB::table('cat_inventory_locations')->where('id', $site)->value('location_name');
                foreach (DB::table('cat_inventory_locations')->where('fk_id_site', $site)->where('kind', 'BIN')->when($id, fn ($q) => $q->where('id', '<>', $id))->get(['location_name', 'location_code']) as $o)
                    if (LocationGenerator::norm((string) $o->location_name) === $n || ($o->location_code !== null && LocationGenerator::norm((string) $o->location_code) === $n)) return ['location_name' => 'Bin code ' . mb_strtoupper($name) . " already exists in $siteName."];
            }
        }
        if (! $id) return [];
        if (! empty($old['system_role'])) {
            if (array_key_exists('f_active', $data) && ! (int) $data['f_active'] && (int) ($old['f_active'] ?? 1)) return ['f_active' => 'System bins cannot be deactivated.'];
            foreach (['kind', 'system_role'] as $c) if (array_key_exists($c, $data) && (string) $data[$c] !== (string) $old[$c]) return [$c => 'A system bin keeps its role.'];
        }
        if (array_key_exists('f_active', $data) && ! (int) $data['f_active'] && (int) ($old['f_active'] ?? 1)) {
            /* R-H-6 a site or zone counts everything under it; units, open pick lines and open reservations stop the deactivation; a reason is always asked */
            $ids = self::under($id);
            $units = (int) DB::table('fin_lot_balances')->whereIn('fk_id_location', $ids)->sum('qty');
            if ($units > 0) return ['f_active' => "Move the $units unit" . ($units === 1 ? '' : 's') . ' first.'];
            $lines = DB::table('wh_pick_task_lines')->whereIn('line_status', ['OPEN', 'RELEASED'])->where(fn ($w) => $w->whereIn('fk_id_from_location', $ids)->orWhereIn('fk_id_to_location', $ids))->count();
            if ($lines) return ['f_active' => "Finish or release the $lines open pick line" . ($lines === 1 ? '' : 's') . ' first.'];
            if (($old['kind'] ?? '') === 'WAREHOUSE' && ($res = DB::table('wh_reservations')->where('fk_id_site', $id)->where('status', 'OPEN')->count())) return ['f_active' => "Release the $res open reservation" . ($res === 1 ? '' : 's') . ' of this site first.'];
            if (trim((string) ($data['deactivated_reason'] ?? '')) === '') return ['deactivated_reason' => 'Give the reason for deactivating.'];
        }
        if (($old['kind'] ?? '') === 'WAREHOUSE' && array_key_exists('f_wh_managed', $data) && (int) $data['f_wh_managed'] && ! (int) ($old['f_wh_managed'] ?? 0)) self::ensureBins((int) ($old['fk_id_ent'] ?: Context::ent()), $id);
        return [];
    }

    /** R-H-8 what a person sees in a bin: the SKUs of his company (book units), and how many other SKUs are there. */
    public static function contents(int $binId, int $ent): array
    {
        $b = DB::table('cat_inventory_locations')->where('id', $binId)->first();
        if (! $b || ! self::mine($b, $ent)) throw new \InvalidArgumentException('Not in your scope.');
        $rows = DB::table('fin_lot_balances as x')->join('fin_lots as l', 'l.id', '=', 'x.fk_id_lot')->join('t_product_vendors as p', 'p.id', '=', 'l.fk_id_product')->where('x.fk_id_location', $binId)->where('x.qty', '>', 0)
            ->groupBy('p.id', 'p.SKU_VENDOR', 'p.product_name_vendor', 'p.fk_id_ent')->selectRaw('p.id, p.SKU_VENDOR sku, p.product_name_vendor name, p.fk_id_ent ent, SUM(x.qty) qty')->orderBy('p.SKU_VENDOR')->get();
        $mine = $rows->filter(fn ($r) => (int) $r->ent === $ent)->values(); $other = $rows->count() - $mine->count();
        return ['bin' => $b->location_name, 'items' => $mine->map(fn ($r) => ['product' => (int) $r->id, 'sku' => $r->sku, 'name' => $r->name, 'qty' => (int) $r->qty])->all(), 'other' => $other,
            'note' => $other ? "+ $other other SKU" . ($other === 1 ? '' : 's') . ' not in your scope' : null];
    }

    /**
     * R-H-14 go-live of a site. $map = [old shelf location id => new bin id]. Per SKU: book units at the site (the site and its old shelf children),
     * each old shelf row with a mapped bin placed with min(row qty, book not yet placed), the rest → UNASSIGNED. Dry run = the plan only.
     * Apply moves the lot balances (same lots, same owners, same cost) – physical place only; Σ before = Σ after. Idempotent.
     */
    public static function goLive(int $ent, int $siteId, array $map, bool $apply): array
    {
        $s = self::site($ent, $siteId); if ($apply) self::ensureBins($ent, $siteId); $un = self::systemBin($siteId, 'UNASSIGNED');      /* a dry run changes nothing – not even the system bins */
        $kids = DB::table('cat_inventory_locations')->where('fk_id_parent', $siteId)->where('kind', 'SHELF')->pluck('id')->map(fn ($x) => (int) $x)->all();
        $old = array_merge([$siteId], $kids);      // where the book sits today: the site and its old shelves
        $plan = []; $moves = 0;
        foreach (DB::table('fin_lot_balances as x')->join('fin_lots as l', 'l.id', '=', 'x.fk_id_lot')->whereIn('x.fk_id_location', $old)->where('x.qty', '>', 0)->groupBy('l.fk_id_product')->selectRaw('l.fk_id_product p, SUM(x.qty) q')->get() as $r) {
            $book = (int) $r->q; $left = $book; $lines = [];
            foreach (DB::table('t_product_locations')->where('fk_id_product', $r->p)->whereIn('fk_id_location', $kids)->where('qty', '>', 0)->where('fk_id_owner_seg', 0)->orderBy('id')->get() as $row) {
                $to = $map[$row->fk_id_location] ?? null;
                if (! $to) { $lines[] = ['from' => (int) $row->fk_id_location, 'to' => null, 'qty' => 0, 'note' => DB::table('cat_inventory_locations')->where('id', $row->fk_id_location)->value('location_name') . " {$row->qty} – no mapped bin"]; continue; }
                $q = min((int) $row->qty, $left); if ($q <= 0) continue; $left -= $q;
                $lines[] = ['from' => (int) $row->fk_id_location, 'to' => (int) $to, 'qty' => $q, 'note' => null];
            }
            if ($left > 0) $lines[] = ['from' => null, 'to' => $un, 'qty' => $left, 'note' => 'UNASSIGNED'];
            $plan[] = ['product' => (int) $r->p, 'sku' => (string) DB::table('t_product_vendors')->where('id', $r->p)->value('SKU_VENDOR'), 'book' => $book, 'lines' => $lines];
        }
        if (! $apply) return ['site' => $s->location_name, 'plan' => $plan, 'changes' => array_sum(array_map(fn ($p) => count(array_filter($p['lines'], fn ($l) => $l['qty'] > 0)), $plan))];
        DB::transaction(function () use ($plan, $old, &$moves) {
            foreach ($plan as $p) foreach ($p['lines'] as $l) {
                if ($l['qty'] <= 0 || ! $l['to']) continue; $need = $l['qty'];
                foreach (DB::table('fin_lot_balances as x')->join('fin_lots as lt', 'lt.id', '=', 'x.fk_id_lot')->where('lt.fk_id_product', $p['product'])->whereIn('x.fk_id_location', $l['from'] ? [$l['from']] : $old)->where('x.qty', '>', 0)->orderBy('lt.received_date')->orderBy('lt.id')->lockForUpdate()->get(['x.id', 'x.fk_id_lot', 'x.qty']) as $b) {
                    if ($need <= 0) break; $t = min($need, (int) $b->qty); $need -= $t;
                    DB::table('fin_lot_balances')->where('id', $b->id)->decrement('qty', $t);
                    $dest = DB::table('fin_lot_balances')->where('fk_id_lot', $b->fk_id_lot)->where('fk_id_location', $l['to'])->first();
                    if ($dest) DB::table('fin_lot_balances')->where('id', $dest->id)->increment('qty', $t); else DB::table('fin_lot_balances')->insert(['fk_id_lot' => $b->fk_id_lot, 'fk_id_location' => $l['to'], 'qty' => $t, 'updated_at' => now()]);
                    $moves++;
                }
            }
        });
        StockService::syncSegAll($ent);      // R-H-11 segregated owners' rows follow the moved lots
        Audit::log('cat_inventory_locations', $siteId, 'UPDATE', ['go_live_moves' => $moves], 'warehouse go-live', $ent);
        return ['site' => $s->location_name, 'plan' => $plan, 'moved' => $moves] + ['again' => self::goLive($ent, $siteId, $map, false)['changes']];
    }
}
