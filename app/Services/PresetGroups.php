<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-D-62 Preset group form and group actions (Setup › Carrier profiles & presets).
 * Name* (spaces collapsed, ≤ 100, unique), short code (upper case, ^[A-Z0-9][A-Z0-9_-]{0,19}$), colour from 11 swatches (other → default),
 * description ≤ 255, active. New: sort = max + 10, scope ALL. Edit logs "field: old → new". Star, drag sort, Copy (with items, pins, scope, default), Delete (group, items, scope only).
 */
class PresetGroups
{
    public const COLORS = ['#17a2b8', '#007bff', '#6f42c1', '#e83e8c', '#dc3545', '#fd7e14', '#ffc107', '#28a745', '#20c997', '#6c757d', '#343a40'];

    private static function name(mixed $v): string { return trim(preg_replace('/\s+/u', ' ', (string) $v)); }
    private static function ent(): int { return (int) Context::ent(); }
    private static function group(int $id): object { return DB::table('car_preset_groups')->where('id', $id)->where('fk_id_ent', self::ent())->first() ?? throw new \InvalidArgumentException('Group not found.'); }

    private static function log(int $group, string $action, string $detail, ?object $u): void
    {
        DB::table('car_preset_log')->insert(['fk_id_group' => $group, 'action' => $action, 'detail' => mb_substr($detail, 0, 500), 'user_id' => $u->id ?? Context::userId(), 'user_name' => mb_substr((string) ($u->name ?? ''), 0, 150), 'created_at' => now()]);
    }

    /** The group list: search, status filter (all / active / inactive), ★ first, preset count, presets in no group. */
    public static function list(array $f, ?object $u): array
    {
        Perm::need($u, 'setup.carriers'); $ent = self::ent(); $q = trim((string) ($f['q'] ?? '')); $st = (string) ($f['status'] ?? 'all');
        $rows = DB::table('car_preset_groups as g')->where('g.fk_id_ent', $ent)
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('g.group_name', 'like', "%$q%")->orWhere('g.group_code', 'like', "%$q%")))
            ->when($st === 'active', fn ($w) => $w->where('g.f_active', 1))->when($st === 'inactive', fn ($w) => $w->where('g.f_active', 0))
            ->orderByDesc('g.f_favourite')->orderBy('g.sort_order')->orderBy('g.id')
            ->get(['g.id', 'g.group_name', 'g.group_code', 'g.color', 'g.description', 'g.f_active', 'g.f_favourite', 'g.sort_order', 'g.scope_mode', DB::raw('(SELECT COUNT(*) FROM car_preset_group_items i WHERE i.fk_id_group = g.id) as presets')]);
        $inGroup = DB::table('car_preset_group_items as i')->join('car_preset_groups as g', 'g.id', '=', 'i.fk_id_group')->where('g.fk_id_ent', $ent)->distinct()->pluck('i.fk_id_pkg_svc')->all();
        $loose = DB::table('t_carrier_pkg_svc')->where('f_active', 1)->whereNotIn('id', $inGroup ?: [0])->count();
        return ['rows' => $rows->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->group_name, 'code' => $r->group_code ?: null, 'color' => $r->color ?: self::COLORS[0], 'description' => $r->description, 'active' => (bool) $r->f_active, 'star' => (bool) $r->f_favourite, 'sort' => (int) $r->sort_order, 'scope' => $r->scope_mode, 'presets' => (int) $r->presets])->all(),
            'loose' => $loose, 'loose_text' => $loose ? "$loose saved preset(s) are in no group" : '', 'colors' => self::COLORS, 'filtered' => $q !== '' || $st !== 'all'];
    }

    public static function save(array $d, ?object $u, ?int $id = null): array
    {
        Perm::need($u, 'setup.carriers'); $ent = self::ent();
        $name = self::name($d['name'] ?? '');
        if ($name === '') throw new \InvalidArgumentException('Group name is required.');
        if (mb_strlen($name) > 100) throw new \InvalidArgumentException('Group name: 100 characters maximum.');
        if (DB::table('car_preset_groups')->where('fk_id_ent', $ent)->whereRaw('LOWER(group_name) = ?', [mb_strtolower($name)])->when($id, fn ($w) => $w->where('id', '<>', $id))->exists()) throw new \InvalidArgumentException("A group called \"$name\" already exists.");
        $code = mb_strtoupper(trim((string) ($d['code'] ?? '')));
        if ($code !== '' && ! preg_match('/^[A-Z0-9][A-Z0-9_-]{0,19}$/', $code)) throw new \InvalidArgumentException('Code: up to 20 letters, numbers, - or _ (e.g. RM-UNTR).');
        $color = in_array(strtolower((string) ($d['color'] ?? '')), self::COLORS, true) ? strtolower((string) $d['color']) : self::COLORS[0];
        $desc = trim((string) ($d['description'] ?? '')); if (mb_strlen($desc) > 255) throw new \InvalidArgumentException('Description: 255 characters maximum.');
        $row = ['group_name' => $name, 'group_code' => $code, 'color' => $color, 'description' => $desc, 'f_active' => array_key_exists('active', $d) ? (int) (bool) $d['active'] : 1];
        if ($id) {
            $old = self::group($id); $chg = [];
            foreach (['group_name' => 'Name', 'group_code' => 'Code', 'color' => 'Colour', 'description' => 'Description', 'f_active' => 'Active'] as $c => $l) if ((string) $old->$c !== (string) $row[$c]) $chg[] = "$l: " . ($old->$c ?? '') . ' → ' . ($row[$c] ?? '');
            DB::table('car_preset_groups')->where('id', $id)->update($row + ['updated_by' => (int) ($u->id ?? 0), 'updated_at' => now()]);
            if ($chg) { self::log($id, 'EDIT', implode('; ', $chg), $u); Audit::log('car_preset_groups', $id, 'UPDATE', $chg); }
        } else {
            $sort = (int) DB::table('car_preset_groups')->where('fk_id_ent', $ent)->max('sort_order') + 10;
            $id = (int) DB::table('car_preset_groups')->insertGetId($row + ['fk_id_ent' => $ent, 'scope_mode' => 'ALL', 'f_favourite' => 0, 'sort_order' => $sort, 'created_by' => (int) ($u->id ?? 0), 'created_at' => now()]);
            self::log($id, 'CREATE', "Group created: $name", $u); Audit::log('car_preset_groups', $id, 'INSERT', $row);
        }
        return ['message' => 'Group saved.', 'id' => $id];
    }

    public static function star(int $id, ?object $u): array
    {
        Perm::need($u, 'setup.carriers'); $g = self::group($id); $on = $g->f_favourite ? 0 : 1;
        DB::table('car_preset_groups')->where('id', $id)->update(['f_favourite' => $on, 'updated_at' => now()]); self::log($id, $on ? 'STAR' : 'UNSTAR', $on ? 'Starred (shown first)' : 'Unstarred', $u);
        return ['message' => $on ? 'Starred – shown first.' : 'Unstarred.', 'star' => (bool) $on];
    }

    /** Drag sort: the new order of ids, re-numbered ×10 – only when the list is unfiltered. */
    public static function sort(array $ids, bool $filtered, ?object $u): array
    {
        Perm::need($u, 'setup.carriers');
        if ($filtered) throw new \InvalidArgumentException('Clear the search and the status filter to sort the groups.');
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $mine = DB::table('car_preset_groups')->where('fk_id_ent', self::ent())->whereIn('id', $ids ?: [0])->pluck('id')->map(fn ($x) => (int) $x)->all();
        if (! $mine) return ['message' => 'Nothing to sort.'];
        DB::transaction(function () use ($ids, $mine) { $n = 0; foreach ($ids as $id) if (in_array($id, $mine, true)) DB::table('car_preset_groups')->where('id', $id)->update(['sort_order' => (++$n) * 10]); });
        return ['message' => 'Order saved.'];
    }

    /** Copy → name cut to 88 + " (copy)", then " (copy) 2"…; items, pins, scope and default go with it. */
    public static function copy(int $id, ?object $u): array
    {
        Perm::need($u, 'setup.carriers'); $g = self::group($id); $ent = self::ent();
        $base = mb_substr($g->group_name, 0, 88) . ' (copy)'; $name = $base; $n = 1;
        while (DB::table('car_preset_groups')->where('fk_id_ent', $ent)->whereRaw('LOWER(group_name) = ?', [mb_strtolower($name)])->exists()) $name = $base . ' ' . (++$n);
        return DB::transaction(function () use ($g, $name, $ent, $u, $id) {
            $row = (array) $g; unset($row['id']);
            $new = (int) DB::table('car_preset_groups')->insertGetId(array_merge($row, ['group_name' => $name, 'group_code' => '', 'f_favourite' => 0, 'legacy_id' => 0, 'sort_order' => (int) DB::table('car_preset_groups')->where('fk_id_ent', $ent)->max('sort_order') + 10, 'created_by' => (int) ($u->id ?? 0), 'created_at' => now(), 'updated_by' => 0, 'updated_at' => null]));
            foreach (DB::table('car_preset_group_items')->where('fk_id_group', $id)->get() as $i) DB::table('car_preset_group_items')->insert(['fk_id_group' => $new, 'fk_id_pkg_svc' => $i->fk_id_pkg_svc, 'f_pinned' => $i->f_pinned, 'sort_order' => $i->sort_order, 'created_by' => (int) ($u->id ?? 0), 'created_at' => now()]);
            foreach (DB::table('car_preset_group_scope')->where('fk_id_group', $id)->get() as $s) DB::table('car_preset_group_scope')->insert(['fk_id_group' => $new, 'fk_id_marketplace' => $s->fk_id_marketplace, 'fk_id_branch' => $s->fk_id_branch]);
            self::log($new, 'COPY', "Copied from {$g->group_name}", $u); Audit::log('car_preset_groups', $new, 'INSERT', ['copied from' => $id]);
            return ['message' => "Copy created: $name", 'id' => $new];
        });
    }

    /** Delete removes the group, its items and its scope only – the presets themselves, products and sales keep their carrier. */
    public static function delete(int $id, ?object $u): array
    {
        Perm::need($u, 'setup.carriers'); $g = self::group($id);
        DB::transaction(function () use ($id) { DB::table('car_preset_group_items')->where('fk_id_group', $id)->delete(); DB::table('car_preset_group_scope')->where('fk_id_group', $id)->delete(); DB::table('car_preset_groups')->where('id', $id)->delete(); });
        Audit::log('car_preset_groups', $id, 'DELETE', ['group' => $g->group_name]);
        return ['message' => 'Group deleted. The presets themselves were not touched.'];
    }

    public static function history(int $id, ?object $u): array
    {
        Perm::need($u, 'setup.carriers'); self::group($id);
        return DB::table('car_preset_log')->where('fk_id_group', $id)->orderByDesc('id')->limit(200)->get(['action', 'detail', 'user_name', 'created_at'])->all();
    }

    // ---------- R-D-64 presets inside a group ----------

    /** The presets of a group: pinned first, then order; inactive badge; on open, items whose preset no longer exists are removed (CLEAN). Where it is offered. */
    public static function items(int $id, ?object $u): array
    {
        Perm::need($u, 'setup.carriers'); $g = self::group($id);
        $gone = DB::table('car_preset_group_items as i')->leftJoin('t_carrier_pkg_svc as p', 'p.id', '=', 'i.fk_id_pkg_svc')->where('i.fk_id_group', $id)->whereNull('p.id')->pluck('i.id')->all();
        if ($gone) { DB::table('car_preset_group_items')->whereIn('id', $gone)->delete(); self::log($id, 'CLEAN', count($gone) . ' deleted preset(s) removed from the group', $u);
            if ($g->fk_id_default_preset && ! DB::table('t_carrier_pkg_svc')->where('id', $g->fk_id_default_preset)->exists()) DB::table('car_preset_groups')->where('id', $id)->update(['fk_id_default_preset' => 0]); }
        $rows = DB::table('car_preset_group_items as i')->join('t_carrier_pkg_svc as p', 'p.id', '=', 'i.fk_id_pkg_svc')->leftJoin('t_carriers as c', 'c.id', '=', 'p.fk_id_carrier')
            ->leftJoin('t_carrier_packages as k', 'k.id', '=', 'p.fk_id_car_package')->leftJoin('t_carrier_services as v', 'v.id', '=', 'p.fk_id_car_service')->where('i.fk_id_group', $id)
            ->orderByDesc('i.f_pinned')->orderBy('i.sort_order')->orderBy('i.id')
            ->get(['i.fk_id_pkg_svc as preset', 'i.f_pinned', 'i.sort_order', 'p.pkg_svc_name', 'p.rate', 'p.f_active as p_on', 'c.carrier_name', 'c.f_active as c_on', 'k.package_name', 'k.f_active as k_on', 'v.service_name', 'v.f_active as v_on']);
        $def = DB::table('car_preset_groups')->where('id', $id)->value('fk_id_default_preset');
        $scope = DB::table('car_preset_group_scope')->where('fk_id_group', $id)->get(['fk_id_marketplace', 'fk_id_branch']);
        return ['group' => ['id' => $id, 'name' => $g->group_name, 'scope' => $g->scope_mode ?: 'ALL', 'default' => $def ? (int) $def : null],
            'items' => $rows->map(fn ($r) => ['preset' => (int) $r->preset, 'label' => CarrierPresets::label($r), 'pinned' => (bool) $r->f_pinned, 'default' => (int) $def === (int) $r->preset,
                'inactive' => ! ((int) $r->p_on && (int) ($r->c_on ?? 1) && (int) ($r->k_on ?? 1) && (int) ($r->v_on ?? 1))])->all(),
            'marketplaces' => $scope->where('fk_id_branch', 0)->pluck('fk_id_marketplace')->map(fn ($x) => (int) $x)->filter()->values()->all(),
            'shops' => $scope->where('fk_id_branch', '>', 0)->pluck('fk_id_branch')->map(fn ($x) => (int) $x)->values()->all(),
            'pick' => ['marketplaces' => DB::table('cat_marketplaces')->where(fn ($w) => $w->where('fk_id_ent', self::ent())->orWhere('fk_id_ent', 0))->orderBy('marketplace_short')->get(['id', 'marketplace_short as name'])->all(),
                'shops' => DB::table('t_branches')->where('fk_id_ent', self::ent())->where('f_active', 1)->orderBy('shop_name')->get(['id', 'shop_name as name'])->all()]];
    }

    private static function item(int $id, int $preset): object
    {
        return DB::table('car_preset_group_items')->where('fk_id_group', $id)->where('fk_id_pkg_svc', $preset)->first() ?? throw new \InvalidArgumentException('It was not in the group.');
    }

    public static function pin(int $id, int $preset, ?object $u): array
    {
        Perm::need($u, 'setup.carriers'); self::group($id); $i = self::item($id, $preset); $on = ! (int) $i->f_pinned;
        DB::table('car_preset_group_items')->where('id', $i->id)->update(['f_pinned' => $on ? 1 : 0]);
        self::log($id, 'EDIT', ($on ? 'Pinned: ' : 'Unpinned: ') . (CarrierPresets::labelsOf([$preset])[$preset] ?? "#$preset"), $u);
        return ['message' => $on ? 'Pinned – shown first in the dropdown.' : 'Unpinned.', 'pinned' => $on];
    }

    /** New order of the presets (ids in order), re-numbered ×10. */
    public static function order(int $id, array $presets, ?object $u): array
    {
        Perm::need($u, 'setup.carriers'); self::group($id); $n = 0;
        foreach (array_values(array_map('intval', $presets)) as $k => $p) $n += DB::table('car_preset_group_items')->where('fk_id_group', $id)->where('fk_id_pkg_svc', $p)->update(['sort_order' => ($k + 1) * 10]);
        if ($n) self::log($id, 'EDIT', 'Order of the presets changed', $u);
        return ['message' => $n ? 'Order saved.' : 'Nothing to sort.'];
    }

    /** The preset pre-selected for new products on the marketplaces / shops of this group; null clears it. */
    public static function setDefault(int $id, ?int $preset, ?object $u): array
    {
        Perm::need($u, 'setup.carriers'); self::group($id);
        if ($preset) { try { self::item($id, $preset); } catch (\InvalidArgumentException $e) { throw new \InvalidArgumentException('The default must be a preset of this group.'); } }
        DB::table('car_preset_groups')->where('id', $id)->update(['fk_id_default_preset' => $preset ?: 0, 'updated_at' => now()]);
        self::log($id, 'DEFAULT', $preset ? 'Default: ' . (CarrierPresets::labelsOf([$preset])[$preset] ?? "#$preset") : 'Default cleared', $u);
        return ['message' => $preset ? 'Default preset set.' : 'Default cleared.'];
    }

    /** Take a preset out of the group (the preset itself stays); the default is cleared when it was this one. */
    public static function remove(int $id, int $preset, ?object $u): array
    {
        Perm::need($u, 'setup.carriers'); $g = self::group($id); $i = self::item($id, $preset);
        DB::table('car_preset_group_items')->where('id', $i->id)->delete();
        self::log($id, 'REMOVE', 'Removed: ' . (CarrierPresets::labelsOf([$preset])[$preset] ?? "#$preset"), $u);
        if ((int) $g->fk_id_default_preset === $preset) { DB::table('car_preset_groups')->where('id', $id)->update(['fk_id_default_preset' => 0]); self::log($id, 'DEFAULT', 'Default cleared (its preset was removed)', $u); }
        return ['message' => 'Removed from the group.'];
    }

    /** Where the group is offered: Everywhere (ALL) or only on ticked marketplaces (all their shops) and / or shops. */
    public static function scope(int $id, string $mode, array $marketplaces, array $shops, ?object $u): array
    {
        Perm::need($u, 'setup.carriers'); self::group($id); $mode = strtoupper($mode) === 'LIMITED' ? 'LIMITED' : 'ALL';
        $mps = array_values(array_unique(array_filter(array_map('intval', $marketplaces)))); $sh = array_values(array_unique(array_filter(array_map('intval', $shops))));
        if ($mode === 'LIMITED' && ! $mps && ! $sh) throw new \InvalidArgumentException('Tick at least one marketplace or shop, or choose "Everywhere".');
        DB::transaction(function () use ($id, $mode, $mps, $sh) {
            DB::table('car_preset_groups')->where('id', $id)->update(['scope_mode' => $mode, 'updated_at' => now()]); DB::table('car_preset_group_scope')->where('fk_id_group', $id)->delete();
            if ($mode === 'LIMITED') { foreach ($mps as $m) DB::table('car_preset_group_scope')->insert(['fk_id_group' => $id, 'fk_id_marketplace' => $m, 'fk_id_branch' => 0]);
                foreach ($sh as $b) DB::table('car_preset_group_scope')->insert(['fk_id_group' => $id, 'fk_id_marketplace' => (int) DB::table('t_branches')->where('id', $b)->value('fk_id_marketplace'), 'fk_id_branch' => $b]); }
        });
        self::log($id, 'SCOPE', $mode === 'ALL' ? 'Everywhere' : 'Only on ' . count($mps) . ' marketplace(s), ' . count($sh) . ' shop(s)', $u);
        return ['message' => $mode === 'ALL' ? 'Offered everywhere.' : 'Offered only where ticked.'];
    }
}
