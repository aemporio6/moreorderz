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
        return ['homes' => $homes, 'no_home' => $noHome, 'mode' => $mode, 'bins' => DB::table('cat_inventory_locations')->where('fk_id_ent', $ent)->where('kind', 'BIN')->where('f_active', 1)->orderBy('location_name')->limit(2000)->get(['id', 'location_name as name']),
            'can_edit' => in_array(Context::role(), ['OWNER', 'MANAGER', 'WAREHOUSE'], true)];
    }
}
