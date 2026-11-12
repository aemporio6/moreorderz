<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * SKU homes (R-H-9, R-H-43): per site a SKU has one PRIMARY home bin and any number of OVERFLOW homes, each with a min and max quantity.
 * Kept on t_product_locations (home_role, min_qty, max_qty). The units themselves are counted by the stock ledger (lots per bin), not here.
 * Move home: the new bin becomes PRIMARY, the old one becomes overflow or stops being a home, the units can be moved at once, and exactly two shelf labels go to the print queue.
 */
class SkuHomes
{
    private static function can(): void { if (! in_array(Context::role(), ['OWNER', 'MANAGER', 'WAREHOUSE'], true)) throw new \InvalidArgumentException('Only the Owner, a Manager or the warehouse changes SKU homes.'); }

    private static function bin(int $ent, int $id): object
    {
        $b = DB::table('cat_inventory_locations')->where('id', $id)->where('fk_id_ent', $ent)->first(['id', 'location_name', 'kind', 'f_active', 'fk_id_site']) ?? throw new \InvalidArgumentException('Choose a bin of this company.');
        if ($b->kind !== 'BIN' || ! $b->f_active) throw new \InvalidArgumentException("{$b->location_name} is not an active bin. A home is always a bin.");
        return $b;
    }

    private static function siteName(?int $site): string { return $site ? (string) (DB::table('cat_inventory_locations')->where('id', $site)->value('location_name') ?? 'the site') : 'the warehouse'; }

    private static function primary(int $ent, int $product, ?int $site): ?object
    {
        return DB::table('t_product_locations as h')->join('cat_inventory_locations as l', 'l.id', '=', 'h.fk_id_location')->where('h.fk_id_product', $product)->where('h.home_role', 'PRIMARY')->where('l.fk_id_ent', $ent)
            ->when($site, fn ($q) => $q->where('l.fk_id_site', $site), fn ($q) => $q->whereNull('l.fk_id_site'))->first(['h.id', 'h.fk_id_location', 'l.location_name', 'h.min_qty', 'h.max_qty']);
    }

    public static function set(int $ent, int $product, int $binId, string $role, ?int $min = null, ?int $max = null): int
    {
        self::can();
        if (! in_array($role, ['PRIMARY', 'OVERFLOW'], true)) throw new \InvalidArgumentException('A home is PRIMARY or OVERFLOW.');
        $p = DB::table('t_product_vendors')->where('id', $product)->where('fk_id_ent', $ent)->first(['id', 'SKU_VENDOR']) ?? throw new \InvalidArgumentException('Choose a product of this company.');
        $b = self::bin($ent, $binId);
        if ($min !== null && $min < 0 || $max !== null && $max < 0) throw new \InvalidArgumentException('Min and max cannot be below zero.');
        if ($min !== null && $max !== null && $min > $max) throw new \InvalidArgumentException('Min cannot be more than max.');
        if ($min === null && ($def = Settings::get('WH_HOME_MIN_DEFAULT', $ent, '')) !== '' && $def !== null) $min = (int) $def;
        $have = DB::table('t_product_locations')->where('fk_id_product', $product)->where('fk_id_location', $binId)->where('fk_id_owner_seg', 0)->first();
        if ($role === 'PRIMARY' && ($old = self::primary($ent, $product, $b->fk_id_site)) && (! $have || (int) $old->id !== (int) $have->id)) throw new \InvalidArgumentException("{$p->SKU_VENDOR} already has a home in " . self::siteName($b->fk_id_site) . " ({$old->location_name}). Change it with Move home.");
        if (Settings::get('WH_BIN_MULTI_SKU', $ent, 'Y') === 'N' && DB::table('t_product_locations')->where('fk_id_location', $binId)->where('fk_id_product', '<>', $product)->whereIn('home_role', ['PRIMARY', 'OVERFLOW'])->exists()) throw new \InvalidArgumentException("{$b->location_name} is already the home of another SKU (one SKU per bin is switched on).");
        $row = ['home_role' => $role, 'min_qty' => $min, 'max_qty' => $max, 'updated_at' => now()];
        if ($have) { DB::table('t_product_locations')->where('id', $have->id)->update($row); $id = (int) $have->id; }
        else $id = (int) DB::table('t_product_locations')->insertGetId($row + ['fk_id_product' => $product, 'fk_id_location' => $binId, 'qty' => 0, 'fk_id_owner_seg' => 0, 'created_by' => (int) Context::userId(), 'created_at' => now()]);
        Audit::log('t_product_locations', $id, $have ? 'UPDATE' : 'INSERT', ['sku' => $p->SKU_VENDOR, 'bin' => $b->location_name, 'role' => $role, 'min' => $min, 'max' => $max], null, $ent);
        return $id;
    }

    public static function remove(int $ent, int $id): void
    {
        self::can();
        $h = DB::table('t_product_locations as h')->join('cat_inventory_locations as l', 'l.id', '=', 'h.fk_id_location')->where('h.id', $id)->where('l.fk_id_ent', $ent)->first(['h.id', 'h.home_role', 'l.location_name']) ?? throw new \InvalidArgumentException('Home not found.');
        DB::table('t_product_locations')->where('id', $id)->delete();
        Audit::log('t_product_locations', $id, 'DELETE', ['bin' => $h->location_name, 'role' => $h->home_role], 'Home removed (units are not moved)', $ent);
    }

    /** R-H-43. Returns what was done, with the print job of the two shelf labels (or why they could not be queued). */
    public static function move(int $ent, int $product, int $newBin, bool $moveUnits = false, bool $keepOldAsOverflow = false): array
    {
        self::can();
        return DB::transaction(function () use ($ent, $product, $newBin, $moveUnits, $keepOldAsOverflow) {
            $b = self::bin($ent, $newBin); $old = self::primary($ent, $product, $b->fk_id_site);
            if (! $old) throw new \InvalidArgumentException('This SKU has no home in ' . self::siteName($b->fk_id_site) . ' yet. Add a home first.');
            if ((int) $old->fk_id_location === $newBin) throw new \InvalidArgumentException("{$b->location_name} is already the home.");
            if ($keepOldAsOverflow) DB::table('t_product_locations')->where('id', $old->id)->update(['home_role' => 'OVERFLOW', 'updated_at' => now()]); else DB::table('t_product_locations')->where('id', $old->id)->delete();
            $id = self::set($ent, $product, $newBin, 'PRIMARY', $old->min_qty !== null ? (int) $old->min_qty : null, $old->max_qty !== null ? (int) $old->max_qty : null);
            $moved = 0;
            if ($moveUnits && ($qty = StockService::balance($product, (int) $old->fk_id_location)) > 0) { StockService::transfer($ent, $product, $qty, (int) $old->fk_id_location, $newBin, 'Home moved'); $moved = $qty; }
            $labels = null; $why = null;
            try { $labels = PrintService::print($ent, 'LOCATION', [(int) $old->fk_id_location, $newBin], ['trigger' => 'HOME_MOVE', 'reason' => 'SKU home moved']); } catch (\Throwable $e) { $why = $e->getMessage(); }
            Audit::log('t_product_locations', $id, 'UPDATE', ['home' => [$old->location_name, $b->location_name], 'units_moved' => $moved, 'old_kept_as_overflow' => $keepOldAsOverflow], 'Move home', $ent);
            return ['home' => $id, 'from' => $old->location_name, 'to' => $b->location_name, 'units_moved' => $moved, 'labels' => $labels, 'labels_problem' => $why];
        });
    }

    /** Units of a SKU per bin (bins only). */
    private static function inBins(int $ent, int $product): array
    {
        return DB::table('fin_lot_balances as b')->join('fin_lots as l', 'l.id', '=', 'b.fk_id_lot')->join('cat_inventory_locations as c', 'c.id', '=', 'b.fk_id_location')->where('l.company_id', $ent)->where('l.fk_id_product', $product)->where('c.kind', 'BIN')->where('b.qty', '>', 0)
            ->groupBy('b.fk_id_location')->selectRaw('b.fk_id_location loc, SUM(b.qty) q')->pluck('q', 'loc')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * Where to put units of a SKU (R-H-10). Best first; each: bin, name, why, room (null = no max set), fits.
     * FIXED / HYBRID: the main home, then the overflow homes, then bins that already hold the SKU, then empty bins. CHAOTIC: bins that hold it, then empty bins.
     */
    public static function suggest(int $ent, int $product, int $qty = 0): array
    {
        $mode = (string) Settings::get('WH_STORAGE_MODE', $ent, 'FIXED'); $in = self::inBins($ent, $product); $out = []; $seen = [];
        $add = function (int $bin, string $name, string $why, ?int $max) use (&$out, &$seen, $in, $qty) { if (isset($seen[$bin])) return; $seen[$bin] = true; $room = $max === null ? null : max(0, $max - ($in[$bin] ?? 0)); $out[] = ['bin' => $bin, 'name' => $name, 'why' => $why, 'room' => $room, 'fits' => $room === null || $room >= $qty]; };
        if ($mode !== 'CHAOTIC') foreach (DB::table('t_product_locations as h')->join('cat_inventory_locations as l', 'l.id', '=', 'h.fk_id_location')->where('h.fk_id_product', $product)->where('l.fk_id_ent', $ent)->where('l.f_active', 1)->whereIn('h.home_role', ['PRIMARY', 'OVERFLOW'])
            ->orderByRaw("h.home_role = 'PRIMARY' DESC")->orderBy('l.pick_seq')->get(['l.id', 'l.location_name', 'h.home_role', 'h.max_qty']) as $h) $add((int) $h->id, $h->location_name, $h->home_role === 'PRIMARY' ? 'main home' : 'overflow home', $h->max_qty !== null ? (int) $h->max_qty : null);
        if ($in) foreach (DB::table('cat_inventory_locations')->whereIn('id', array_keys($in))->where('f_active', 1)->orderBy('pick_seq')->get(['id', 'location_name']) as $b) $add((int) $b->id, $b->location_name, 'already holds this SKU', null);
        $empty = DB::table('cat_inventory_locations as c')->where('c.fk_id_ent', $ent)->where('c.kind', 'BIN')->where('c.f_active', 1)->whereNotExists(fn ($x) => $x->from('fin_lot_balances as b')->whereColumn('b.fk_id_location', 'c.id')->where('b.qty', '>', 0))
            ->whereNotExists(fn ($x) => $x->from('t_product_locations as h')->whereColumn('h.fk_id_location', 'c.id')->whereIn('h.home_role', ['PRIMARY', 'OVERFLOW']))->orderBy('c.pick_seq')->orderBy('c.location_name')->limit(3)->get(['c.id', 'c.location_name']);
        foreach ($empty as $b) $add((int) $b->id, $b->location_name, 'empty bin', null);
        usort($out, fn ($a, $b) => $b['fits'] <=> $a['fits']);   // stable: a place with room first, the order above otherwise
        return $out;
    }

    /** Main homes below their min, with what could be brought from the SKU's other bins of the same site. */
    public static function refillNeeds(int $ent): array
    {
        $out = [];
        foreach (DB::table('t_product_locations as h')->join('cat_inventory_locations as l', 'l.id', '=', 'h.fk_id_location')->join('t_product_vendors as p', 'p.id', '=', 'h.fk_id_product')->where('l.fk_id_ent', $ent)->where('h.home_role', 'PRIMARY')->whereNotNull('h.min_qty')
            ->get(['h.id', 'h.fk_id_product as product', 'p.SKU_VENDOR as sku', 'h.fk_id_location as bin', 'l.location_name as bin_name', 'l.fk_id_site as site', 'h.min_qty', 'h.max_qty']) as $h) {
            $in = self::inBins($ent, (int) $h->product); $have = $in[$h->bin] ?? 0; if ($have >= (int) $h->min_qty) continue;
            $others = array_diff_key($in, [$h->bin => 1]); if ($others) { $same = DB::table('cat_inventory_locations')->whereIn('id', array_keys($others))->when($h->site, fn ($q) => $q->where('fk_id_site', $h->site), fn ($q) => $q->whereNull('fk_id_site'))->pluck('location_name', 'id')->all(); $others = array_intersect_key($others, $same); } else $same = [];
            $target = (int) ($h->max_qty ?? $h->min_qty); $can = min(max(0, $target - $have), array_sum($others));
            $out[] = ['home' => (int) $h->id, 'product' => (int) $h->product, 'sku' => $h->sku, 'bin' => (int) $h->bin, 'bin_name' => $h->bin_name, 'site' => (int) $h->site, 'in_bin' => $have, 'min' => (int) $h->min_qty, 'target' => $target, 'can_move' => $can,
                'from' => array_map(fn ($id, $q) => ['bin' => (int) $id, 'name' => $same[$id], 'qty' => $q], array_keys($others), $others)];
        }
        return $out;
    }

    /** Bring units from the SKU's other bins into its main home, up to the max (or the min when no max is set). Overflow homes are emptied first. */
    public static function refill(int $ent, int $homeId): array
    {
        self::can();
        return DB::transaction(function () use ($ent, $homeId) {
            $n = collect(self::refillNeeds($ent))->firstWhere('home', $homeId) ?? throw new \InvalidArgumentException('This home is not below its smallest quantity (or it is not a main home with a smallest quantity).');
            if ($n['can_move'] <= 0) throw new \InvalidArgumentException("No other bin of the site holds {$n['sku']}. Buy more or receive the next delivery.");
            $over = DB::table('t_product_locations')->where('fk_id_product', $n['product'])->where('home_role', 'OVERFLOW')->pluck('fk_id_location')->all();
            $from = $n['from']; usort($from, fn ($a, $b) => in_array($b['bin'], $over) <=> in_array($a['bin'], $over)); $left = $n['can_move']; $moved = [];
            foreach ($from as $f) { if ($left <= 0) break; $q = min($left, $f['qty']); StockService::transfer($ent, $n['product'], $q, $f['bin'], $n['bin'], 'Refill of the main home'); $moved[] = "$q from {$f['name']}"; $left -= $q; }
            TaskEngine::close('WH_REPLEN', [$n['site'], $n['sku']], 'Home refilled');
            Audit::log('t_product_locations', $homeId, 'UPDATE', ['refill' => $n['can_move'], 'from' => $moved], 'Refill of the main home', $ent);
            return ['moved' => $n['can_move'], 'detail' => implode(', ', $moved), 'in_bin' => $n['in_bin'] + $n['can_move'], 'bin' => $n['bin_name'], 'sku' => $n['sku']];
        });
    }

    /** Nightly: a task per main home that is below its min while other bins hold the SKU; a task per SKU with stock and no main home (FIXED mode). Tasks close by themselves when the reason is gone. */
    public static function tasks(int $ent): array
    {
        $n = ['replen' => 0, 'no_home' => 0]; $keep = [];
        foreach (self::refillNeeds($ent) as $r) { if ($r['can_move'] <= 0) continue; $keep[$r['site'] . ':' . $r['sku']] = true;
            TaskEngine::open('WH_REPLEN', [$r['site'], $r['sku']], "Refill {$r['sku']} in {$r['bin_name']}: {$r['in_bin']} there, smallest {$r['min']} – {$r['can_move']} can come from " . implode(', ', array_map(fn ($f) => $f['name'], $r['from'])), ['fix_url' => '#/sku-homes', 'ent' => $ent]); $n['replen']++; }
        foreach (DB::table('tasks')->where('rule_code', 'WH_REPLEN')->where('fk_id_ent', $ent)->whereNotIn('status', ['DONE', 'APPROVED', 'CANCELLED'])->pluck('dedupe_key') as $k) { $p = explode(':', (string) $k, 3); if (count($p) === 3 && ! isset($keep[$p[1] . ':' . $p[2]])) TaskEngine::close('WH_REPLEN', [$p[1], $p[2]], 'Home is at its smallest quantity again, or no other stock'); }
        $keep = [];
        foreach (self::page($ent)['no_home'] as $p) { $keep[$p->sku] = true; TaskEngine::open('WH_SKU_NO_HOME', [0, $p->sku], "{$p->sku} has {$p->units} unit(s) in stock but no main home", ['fix_url' => '#/sku-homes', 'ent' => $ent]); $n['no_home']++; }
        foreach (DB::table('tasks')->where('rule_code', 'WH_SKU_NO_HOME')->where('fk_id_ent', $ent)->whereNotIn('status', ['DONE', 'APPROVED', 'CANCELLED'])->pluck('dedupe_key') as $k) { $p = explode(':', (string) $k, 3); if (count($p) === 3 && ! isset($keep[$p[2]])) TaskEngine::close('WH_SKU_NO_HOME', [0, $p[2]], 'Main home set or no stock'); }
        return $n;
    }

    public static function page(int $ent, ?string $q = null): array
    {
        $homes = DB::table('t_product_locations as h')->join('cat_inventory_locations as l', 'l.id', '=', 'h.fk_id_location')->join('t_product_vendors as p', 'p.id', '=', 'h.fk_id_product')->leftJoin('cat_inventory_locations as s', 's.id', '=', 'l.fk_id_site')
            ->where('l.fk_id_ent', $ent)->whereIn('h.home_role', ['PRIMARY', 'OVERFLOW'])->when($q, fn ($x) => $x->where(fn ($w) => $w->where('p.SKU_VENDOR', 'like', "%$q%")->orWhere('p.product_name_vendor', 'like', "%$q%")->orWhere('l.location_name', 'like', "%$q%")))
            ->orderBy('p.SKU_VENDOR')->orderByRaw("h.home_role = 'PRIMARY' DESC")->limit(500)->get(['h.id', 'h.fk_id_product as product', 'p.SKU_VENDOR as sku', 'p.product_name_vendor as name', 'h.fk_id_location as bin_id', 'l.location_name as bin', 's.location_name as site', 'h.home_role as role', 'h.min_qty', 'h.max_qty']);
        $in = DB::table('fin_lot_balances as b')->join('fin_lots as l', 'l.id', '=', 'b.fk_id_lot')->where('l.company_id', $ent)->whereIn('b.fk_id_location', $homes->pluck('bin_id')->unique())->groupBy('l.fk_id_product', 'b.fk_id_location')->selectRaw('l.fk_id_product p, b.fk_id_location loc, SUM(b.qty) q')->get()->keyBy(fn ($r) => $r->p . ':' . $r->loc);
        foreach ($homes as $h) { $h->in_bin = (int) ($in[$h->product . ':' . $h->bin_id]->q ?? 0); $h->below_min = $h->role === 'PRIMARY' && $h->min_qty !== null && $h->in_bin < (int) $h->min_qty; $h->over_max = $h->max_qty !== null && $h->in_bin > (int) $h->max_qty; }
        $mode = (string) Settings::get('WH_STORAGE_MODE', $ent, 'FIXED');
        // FIXED mode: every SKU with stock should have a PRIMARY home
        $noHome = $mode !== 'FIXED' ? collect() : DB::table('fin_lot_balances as b')->join('fin_lots as l', 'l.id', '=', 'b.fk_id_lot')->join('t_product_vendors as p', 'p.id', '=', 'l.fk_id_product')->where('l.company_id', $ent)->where('b.qty', '>', 0)
            ->whereNotExists(fn ($x) => $x->from('t_product_locations as h')->whereColumn('h.fk_id_product', 'p.id')->where('h.home_role', 'PRIMARY'))->groupBy('p.id', 'p.SKU_VENDOR', 'p.product_name_vendor')->orderByDesc(DB::raw('SUM(b.qty)'))->limit(200)->selectRaw('p.id, p.SKU_VENDOR sku, p.product_name_vendor name, SUM(b.qty) units')->get();
        $needs = collect(self::refillNeeds($ent))->keyBy('home'); foreach ($homes as $h) { $h->can_refill = (int) ($needs[$h->id]['can_move'] ?? 0); $h->refill_from = isset($needs[$h->id]) ? implode(', ', array_map(fn ($f) => $f['qty'] . ' in ' . $f['name'], $needs[$h->id]['from'])) : ''; }
        return ['homes' => $homes, 'no_home' => $noHome, 'mode' => $mode, 'bins' => DB::table('cat_inventory_locations')->where('fk_id_ent', $ent)->where('kind', 'BIN')->where('f_active', 1)->orderBy('location_name')->limit(2000)->get(['id', 'location_name as name']),
            'can_edit' => in_array(Context::role(), ['OWNER', 'MANAGER', 'WAREHOUSE'], true)];
    }
}
