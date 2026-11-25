<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-H-50 ABC classes and slotting suggestions (never automatic).
 * Nightly: units and pick lines per SKU over WH_ABC_DAYS from the confirmed pick lines; class A = SKUs making the top WH_ABC_A_PCT of picks, B = the next WH_ABC_B_PCT, C = the rest (wh_product_stats).
 * Suggestions: an A-class SKU whose main home is not a prime bin (f_prime) and a C-class SKU sitting in a prime bin of the same site → a swap (one row each way); the manager approves → Move home with the units (R-H-43: two shelf labels each).
 * Task WH_SLOTTING (LOW) per site and week while suggestions wait.
 */
class WarehouseSlotting
{
    public static function compute(int $ent, ?string $now = null): array
    {
        $now ??= date('Y-m-d H:i:s'); $from = date('Y-m-d H:i:s', strtotime($now) - (int) Settings::get('WH_ABC_DAYS', $ent, 90) * 86400);
        $aPct = (float) Settings::get('WH_ABC_A_PCT', $ent, 80); $bPct = (float) Settings::get('WH_ABC_B_PCT', $ent, 15); $made = 0; $classes = [];
        $rows = DB::table('wh_pick_task_lines as l')->join('wh_pick_tasks as t', 't.id', '=', 'l.fk_id_pick_task')->join('cat_inventory_locations as c', 'c.id', '=', 'l.fk_id_from_location')
            ->where('t.fk_id_ent', $ent)->whereIn('l.line_status', ['DONE', 'SHORT'])->whereBetween('l.done_at', [$from, $now])
            ->groupBy('c.fk_id_site', 'l.fk_id_product')->get([DB::raw('COALESCE(c.fk_id_site, 0) site'), 'l.fk_id_product', DB::raw('SUM(l.qty_done) units'), DB::raw('COUNT(*) picks')]);
        foreach ($rows->groupBy('site') as $site => $list) {
            $total = max(1, (int) $list->sum('picks')); $cum = 0;
            foreach ($list->sortByDesc(fn ($r) => sprintf('%09d-%09d', $r->picks, $r->units))->values() as $r) {
                $share = $cum / $total * 100; $cum += (int) $r->picks;
                $cls = $share < $aPct ? 'A' : ($share < $aPct + $bPct ? 'B' : 'C'); $classes[(int) $site][(int) $r->fk_id_product] = $cls;
                DB::table('wh_product_stats')->updateOrInsert(['fk_id_product' => $r->fk_id_product, 'fk_id_site' => (int) $site], ['abc_class' => $cls, 'units_period' => (int) $r->units, 'pick_lines_period' => (int) $r->picks, 'computed_at' => $now]);
            }
        }
        foreach (DB::table('t_product_locations as h')->join('cat_inventory_locations as c', 'c.id', '=', 'h.fk_id_location')->join('t_product_vendors as p', 'p.id', '=', 'h.fk_id_product')
            ->where('p.fk_id_ent', $ent)->where('h.home_role', 'PRIMARY')->get(['h.fk_id_product', 'c.fk_id_site', 'c.id as bin', 'c.f_prime']) as $h) {
            $site = (int) $h->fk_id_site;
            if (! isset($classes[$site][(int) $h->fk_id_product])) { $classes[$site][(int) $h->fk_id_product] = 'C'; DB::table('wh_product_stats')->updateOrInsert(['fk_id_product' => $h->fk_id_product, 'fk_id_site' => $site], ['abc_class' => 'C', 'units_period' => 0, 'pick_lines_period' => 0, 'computed_at' => $now]); }
            $homes[$site][(int) $h->fk_id_product] = ['bin' => (int) $h->bin, 'prime' => (int) $h->f_prime === 1];
        }
        foreach ($homes ?? [] as $site => $list) {
            $fast = array_keys(array_filter($list, fn ($x, $p) => ($classes[$site][$p] ?? 'C') === 'A' && ! $x['prime'], ARRAY_FILTER_USE_BOTH));
            $slow = array_keys(array_filter($list, fn ($x, $p) => ($classes[$site][$p] ?? 'C') === 'C' && $x['prime'], ARRAY_FILTER_USE_BOTH));
            foreach ($fast as $i => $a) {
                if (! isset($slow[$i])) break; $c = $slow[$i];
                if (DB::table('wh_slotting_suggestions')->where('status', 'OPEN')->whereIn('fk_id_product', [$a, $c])->exists()) continue;
                $sku = fn ($p) => DB::table('t_product_vendors')->where('id', $p)->value('SKU_VENDOR'); $name = fn ($b) => DB::table('cat_inventory_locations')->where('id', $b)->value('location_name');
                DB::table('wh_slotting_suggestions')->insert([
                    ['fk_id_site' => $site, 'fk_id_product' => $a, 'fk_id_from_location' => $list[$a]['bin'], 'fk_id_to_location' => $list[$c]['bin'], 'reason' => mb_substr("Swap: {$sku($a)} is A-class (fast) but sits in {$name($list[$a]['bin'])}; prime bin {$name($list[$c]['bin'])} holds C-class {$sku($c)}", 0, 255), 'status' => 'OPEN', 'created_at' => $now],
                    ['fk_id_site' => $site, 'fk_id_product' => $c, 'fk_id_from_location' => $list[$c]['bin'], 'fk_id_to_location' => $list[$a]['bin'], 'reason' => mb_substr("Swap: {$sku($c)} is C-class (slow) and takes prime bin {$name($list[$c]['bin'])} – move it to {$name($list[$a]['bin'])}", 0, 255), 'status' => 'OPEN', 'created_at' => $now]]);
                $made += 2;
            }
        }
        $wk = date('o-W', strtotime($now)); $p = [];
        foreach (DB::table('wh_slotting_suggestions as s')->join('t_product_vendors as v', 'v.id', '=', 's.fk_id_product')->where('v.fk_id_ent', $ent)->where('s.status', 'OPEN')->groupBy('s.fk_id_site')->get(['s.fk_id_site', DB::raw('COUNT(*) n')]) as $r)
            $p[] = ['key' => [(int) $r->fk_id_site, $wk], 'title' => "{$r->n} slotting suggestion(s) wait for a decision", 'opt' => ['module' => 'cat_inventory_locations', 'record_id' => (int) $r->fk_id_site, 'fix_url' => '#/wh/slotting']];
        TaskEngine::sync('WH_SLOTTING', $p, null, $ent);
        return ['classified' => array_sum(array_map('count', $classes)), 'suggestions' => $made];
    }

    /** The manager decides: approve = Move home with the units (two shelf labels), reject = no change. */
    public static function decide(int $ent, int $id, bool $approve, ?object $u): array
    {
        Perm::need($u, 'stock.adjust_owner');
        return DB::transaction(function () use ($ent, $id, $approve, $u) {
            $s = DB::table('wh_slotting_suggestions as s')->join('t_product_vendors as v', 'v.id', '=', 's.fk_id_product')->where('s.id', $id)->where('v.fk_id_ent', $ent)->lockForUpdate()->first(['s.*']) ?? throw new \InvalidArgumentException('Suggestion not found.');
            if ($s->status !== 'OPEN') throw new \InvalidArgumentException('This suggestion is already ' . strtolower($s->status) . '.');
            $r = $approve ? SkuHomes::move($ent, (int) $s->fk_id_product, (int) $s->fk_id_to_location, true) : null;
            DB::table('wh_slotting_suggestions')->where('id', $id)->update(['status' => $approve ? 'APPROVED' : 'REJECTED', 'decided_by' => $u->id ?? null, 'decided_at' => now()]);
            Audit::log('wh_slotting_suggestions', $id, 'UPDATE', ['status' => ['OPEN', $approve ? 'APPROVED' : 'REJECTED']], $s->reason, $ent);
            return ['message' => $approve ? "Home moved {$r['from']} → {$r['to']} ({$r['units_moved']} unit(s)); two shelf labels queued." : 'Suggestion rejected – nothing moved.'];
        });
    }

    public static function list(int $ent): array
    {
        return DB::table('wh_slotting_suggestions as s')->join('t_product_vendors as v', 'v.id', '=', 's.fk_id_product')->leftJoin('wh_product_stats as st', fn ($j) => $j->on('st.fk_id_product', '=', 's.fk_id_product')->on('st.fk_id_site', '=', 's.fk_id_site'))
            ->where('v.fk_id_ent', $ent)->orderByRaw("s.status = 'OPEN' DESC")->orderByDesc('s.id')->limit(200)
            ->get(['s.id', 's.status', 's.reason', 'v.SKU_VENDOR as sku', 'st.abc_class', 'st.pick_lines_period', 's.created_at'])->all();
    }
}
