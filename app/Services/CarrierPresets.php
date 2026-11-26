<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Carrier presets around the preset groups (R-D-62).
 * - R-D-63 combination browser ("Add presets" of a group): every carrier × its packages × its services with the saved preset (lowest id) when one exists;
 *   filters carrier → service / package, Saved / Not saved / In no group, text; "in this group" marker and the names of the other groups; favourites first;
 *   limit 3,000 rows. Adding: tick up to 500; an unsaved combination is first checked (package and service belong to the carrier) and then created as a
 *   preset "Service Package" with rate 0 (rate 0 = "not set") + task CAR_RATE_MISSING that closes when the rate is set.
 * - R-D-65 one carrier dropdown for every form: groups offered for a marketplace / shop (ALL, or LIMITED with a matching shop or marketplace row), only
 *   active things, group order limited-and-matched → starred → sort → name, inside pinned → sort → label, label "Carrier - Service Package (rate)",
 *   last group "Other presets", a saved value not in the list kept as "(current: x)", default of a new row = default of the first offered group.
 * - R-D-66 old "virtual" preset ids (ent + carrier + package + service written together without a separator) → the real preset id (lowest id);
 *   values that fit 2+ combinations or none go to the Owner's review list "Ambiguous carrier values" and are not converted.
 */
class CarrierPresets
{
    public const LIMIT = 3000;
    public const MAX_ADD = 500;

    private static function ent(): int { return (int) Context::ent(); }
    private static function group(int $id): object { return DB::table('car_preset_groups')->where('id', $id)->where('fk_id_ent', self::ent())->first() ?? throw new \InvalidArgumentException('Group not found.'); }
    public static function presetName(?string $name, string $service, string $package): string { return trim((string) $name) !== '' ? trim((string) $name) : trim("$service $package"); }

    /* ---------------- R-D-63 ---------------- */
    public static function combos(int $groupId, array $f, ?object $u): array
    {
        Perm::need($u, 'orders.SEE'); $g = self::group($groupId); $ent = self::ent();
        $q = DB::table('t_carriers as c')->join('t_carrier_packages as p', 'p.fk_id_carrier', '=', 'c.id')->join('t_carrier_services as s', 's.fk_id_carrier', '=', 'c.id')
            ->whereIn('c.fk_id_ent', [0, $ent])->where('c.f_active', 1)->where('p.f_active', 1)->where('s.f_active', 1);
        if (! empty($f['carrier'])) $q->where('c.id', (int) $f['carrier']);
        if (! empty($f['service'])) $q->where('s.id', (int) $f['service']);
        if (! empty($f['package'])) $q->where('p.id', (int) $f['package']);
        if (($t = trim((string) ($f['q'] ?? ''))) !== '') { $like = '%' . addcslashes($t, '%_\\') . '%'; $q->where(fn ($w) => $w->where('c.carrier_name', 'like', $like)->orWhere('c.carrier_code', 'like', $like)->orWhere('s.service_name', 'like', $like)->orWhere('p.package_name', 'like', $like)); }
        $rows = $q->orderBy('c.carrier_name')->orderBy('s.service_name')->orderBy('p.package_name')->limit(self::LIMIT + 1)->get(['c.id as carrier', 'c.carrier_name', 'p.id as package', 'p.package_name', 's.id as service', 's.service_name']);
        $limited = $rows->count() > self::LIMIT; $rows = $rows->take(self::LIMIT);
        $presets = []; foreach (DB::table('t_carrier_pkg_svc')->where('f_active', 1)->orderBy('id')->get(['id', 'pkg_svc_name', 'fk_id_carrier', 'fk_id_car_package', 'fk_id_car_service', 'rate']) as $p) $presets[$p->fk_id_carrier . '|' . $p->fk_id_car_package . '|' . $p->fk_id_car_service] ??= $p;      /* the lowest id */
        $in = []; foreach (DB::table('car_preset_group_items as i')->join('car_preset_groups as gr', 'gr.id', '=', 'i.fk_id_group')->where('gr.fk_id_ent', $ent)->get(['i.fk_id_pkg_svc', 'gr.id', 'gr.group_name', 'gr.f_favourite']) as $x) $in[(int) $x->fk_id_pkg_svc][(int) $x->id] = $x;
        $out = [];
        foreach ($rows as $r) {
            $p = $presets[$r->carrier . '|' . $r->package . '|' . $r->service] ?? null; $groups = $p ? ($in[(int) $p->id] ?? []) : [];
            $row = ['key' => $p ? 'p' . $p->id : "c{$r->carrier}-{$r->package}-{$r->service}", 'carrier' => (int) $r->carrier, 'carrier_name' => $r->carrier_name, 'package' => (int) $r->package, 'package_name' => $r->package_name, 'service' => (int) $r->service, 'service_name' => $r->service_name,
                'preset' => $p ? (int) $p->id : null, 'name' => self::presetName($p->pkg_svc_name ?? null, (string) $r->service_name, (string) $r->package_name), 'rate' => $p ? (float) $p->rate : null, 'saved' => (bool) $p,
                'in_this_group' => isset($groups[(int) $g->id]), 'other_groups' => array_values(array_map(fn ($x) => $x->group_name, array_filter($groups, fn ($x) => (int) $x->id !== (int) $g->id))), 'favourite' => (bool) array_filter($groups, fn ($x) => (int) $x->f_favourite === 1)];
            $show = (string) ($f['show'] ?? '');
            if ($show === 'saved' && ! $row['saved'] || $show === 'not_saved' && $row['saved'] || $show === 'no_group' && (! $row['saved'] || $groups)) continue;
            $out[] = $row;
        }
        usort($out, fn ($a, $b) => [$a['favourite'] ? 0 : 1, $a['carrier_name'], $a['service_name'], $a['package_name']] <=> [$b['favourite'] ? 0 : 1, $b['carrier_name'], $b['service_name'], $b['package_name']]);
        return ['group' => ['id' => (int) $g->id, 'name' => $g->group_name], 'rows' => $out, 'limited' => $limited, 'limit_note' => $limited ? 'limited – narrow the filter' : null,
            'carriers' => DB::table('t_carriers')->whereIn('fk_id_ent', [0, $ent])->where('f_active', 1)->orderBy('carrier_name')->get(['id', 'carrier_name as name']),
            'services' => DB::table('t_carrier_services')->where('f_active', 1)->when(! empty($f['carrier']), fn ($w) => $w->where('fk_id_carrier', (int) $f['carrier']))->orderBy('service_name')->get(['id', 'service_name as name', 'fk_id_carrier as carrier']),
            'packages' => DB::table('t_carrier_packages')->where('f_active', 1)->when(! empty($f['carrier']), fn ($w) => $w->where('fk_id_carrier', (int) $f['carrier']))->orderBy('package_name')->get(['id', 'package_name as name', 'fk_id_carrier as carrier'])];
    }

    /** $items: preset ids, or [carrier, package, service] triples of unsaved combinations. */
    public static function add(int $groupId, array $items, ?object $u): array
    {
        Perm::need($u, 'setup.carriers'); $g = self::group($groupId); $ent = self::ent();
        if (! $items) throw new \InvalidArgumentException('Tick at least one preset.');
        if (count($items) > self::MAX_ADD) throw new \InvalidArgumentException('Tick at most ' . self::MAX_ADD . ' presets at a time.');
        return DB::transaction(function () use ($g, $items, $u, $ent) {
            $added = 0; $new = 0; $already = 0; $errors = []; $sort = (int) DB::table('car_preset_group_items')->where('fk_id_group', $g->id)->max('sort_order');
            foreach ($items as $it) {
                $pid = null;
                if (is_array($it)) {
                    [$c, $p, $s] = [(int) ($it['carrier'] ?? $it[0] ?? 0), (int) ($it['package'] ?? $it[1] ?? 0), (int) ($it['service'] ?? $it[2] ?? 0)];
                    $car = DB::table('t_carriers')->where('id', $c)->whereIn('fk_id_ent', [0, $ent])->where('f_active', 1)->first();
                    $pk = DB::table('t_carrier_packages')->where('id', $p)->where('fk_id_carrier', $c)->where('f_active', 1)->first(); $sv = DB::table('t_carrier_services')->where('id', $s)->where('fk_id_carrier', $c)->where('f_active', 1)->first();
                    if (! $car || ! $pk || ! $sv) { $errors[] = 'A package or service does not belong to its carrier – not added.'; continue; }
                    $pid = (int) (DB::table('t_carrier_pkg_svc')->where('fk_id_carrier', $c)->where('fk_id_car_package', $p)->where('fk_id_car_service', $s)->where('f_active', 1)->min('id') ?? 0);
                    if (! $pid) {
                        $name = mb_substr(trim($sv->service_name . ' ' . $pk->package_name), 0, 150);
                        $pid = (int) DB::table('t_carrier_pkg_svc')->insertGetId(RecordService::fillNotNull('t_carrier_pkg_svc', ['pkg_svc_name' => $name, 'fk_id_carrier' => $c, 'fk_id_car_package' => $p, 'fk_id_car_service' => $s, 'rate' => 0, 'f_active' => 1]));
                        $new++; Audit::log('t_carrier_pkg_svc', $pid, 'INSERT', ['pkg_svc_name' => $name, 'rate' => 0, 'from' => 'preset group ' . $g->group_name], null, $ent);
                        TaskEngine::open('CAR_RATE_MISSING', [$pid], "Set rate for preset $name", ['ent' => $ent, 'module' => 't_carrier_pkg_svc', 'record_id' => $pid, 'fix_url' => '#/carriers']);
                    }
                } else { $pid = (int) $it; if (! DB::table('t_carrier_pkg_svc')->where('id', $pid)->where('f_active', 1)->exists()) { $errors[] = "Preset #$pid is not active – not added."; continue; } }
                if (DB::table('car_preset_group_items')->where('fk_id_group', $g->id)->where('fk_id_pkg_svc', $pid)->exists()) { $already++; continue; }
                DB::table('car_preset_group_items')->insert(['fk_id_group' => $g->id, 'fk_id_pkg_svc' => $pid, 'f_pinned' => 0, 'sort_order' => $sort += 10, 'created_by' => $u->id ?? null, 'created_at' => now()]); $added++;
            }
            $msg = "$added preset(s) added." . ($new ? " $new were new and were created with rate 0 – set their rate in Carriers Preset." : '') . ($already ? " $already were already in the group." : '') . ($errors ? ' ' . implode(' ', array_unique($errors)) : '');
            if ($added) DB::table('car_preset_log')->insert(['fk_id_group' => $g->id, 'action' => 'ADD', 'detail' => "$added preset(s) added" . ($new ? " ($new new preset(s) created with rate 0)" : ''), 'user_id' => $u->id ?? null, 'user_name' => mb_substr((string) ($u->display_name ?? ''), 0, 150), 'created_at' => now()]);
            return ['added' => $added, 'new' => $new, 'already' => $already, 'errors' => array_values(array_unique($errors)), 'message' => $msg];
        });
    }

    /** CAR_RATE_MISSING closes by itself when the preset has a rate (called after a rate is saved and at night). */
    public static function closeRateTasks(): int
    {
        $n = 0;
        foreach (DB::table('tasks')->where('rule_code', 'CAR_RATE_MISSING')->whereNotIn('status', ['DONE', 'CANCELLED', 'CLOSED'])->get(['dedupe_key']) as $t) {
            $pid = (int) substr((string) $t->dedupe_key, strlen('CAR_RATE_MISSING:'));
            $p = DB::table('t_carrier_pkg_svc')->where('id', $pid)->first(['rate', 'f_active']);
            if (! $p || (float) $p->rate > 0 || ! (int) $p->f_active) { TaskEngine::close('CAR_RATE_MISSING', [$pid], $p && (float) $p->rate > 0 ? 'The rate is set' : 'The preset is gone'); $n++; }
        }
        return $n;
    }

    /* ---------------- R-D-65 ---------------- */
    public static function label(object $p): string
    {
        $name = self::presetName($p->pkg_svc_name ?? null, (string) ($p->service_name ?? ''), (string) ($p->package_name ?? ''));
        return trim((string) $p->carrier_name) . ' - ' . $name . ((float) $p->rate > 0 ? ' (' . number_format((float) $p->rate, 2, '.', '') . ')' : '');
    }

    public static function dropdown(?int $marketplace = null, ?int $shop = null, ?int $current = null): array
    {
        $ent = self::ent();
        if ($shop && ! $marketplace) $marketplace = (int) DB::table('t_branches')->where('id', $shop)->value('fk_id_marketplace') ?: null;
        $presets = DB::table('t_carrier_pkg_svc as v')->join('t_carriers as c', 'c.id', '=', 'v.fk_id_carrier')->leftJoin('t_carrier_packages as p', 'p.id', '=', 'v.fk_id_car_package')->leftJoin('t_carrier_services as s', 's.id', '=', 'v.fk_id_car_service')
            ->whereIn('c.fk_id_ent', [0, $ent])->where('v.f_active', 1)->where('c.f_active', 1)->where(fn ($w) => $w->whereNull('p.id')->orWhere('p.f_active', 1))->where(fn ($w) => $w->whereNull('s.id')->orWhere('s.f_active', 1))
            ->get(['v.id', 'v.pkg_svc_name', 'v.rate', 'c.carrier_name', 'p.package_name', 's.service_name'])->keyBy('id');
        $scope = DB::table('car_preset_group_scope')->get()->groupBy('fk_id_group'); $groups = [];
        foreach (DB::table('car_preset_groups')->where('fk_id_ent', $ent)->where('f_active', 1)->get() as $g) {
            $matched = false;
            if ($g->scope_mode === 'LIMITED' && ($marketplace || $shop)) {
                foreach ($scope[$g->id] ?? [] as $r) if (($shop && (int) $r->fk_id_branch === $shop) || ($marketplace && (int) $r->fk_id_marketplace === $marketplace && ! (int) $r->fk_id_branch)) $matched = true;
                if (! $matched) continue;
            }
            $groups[] = [$g, $matched];
        }
        usort($groups, fn ($a, $b) => [$a[1] ? 0 : 1, (int) $a[0]->f_favourite ? 0 : 1, (int) $a[0]->sort_order, mb_strtolower((string) $a[0]->group_name)] <=> [$b[1] ? 0 : 1, (int) $b[0]->f_favourite ? 0 : 1, (int) $b[0]->sort_order, mb_strtolower((string) $b[0]->group_name)]);
        $out = []; $inAny = []; $default = null; $selected = false;
        foreach ($groups as [$g, $matched]) {
            $items = DB::table('car_preset_group_items')->where('fk_id_group', $g->id)->get()->filter(fn ($i) => isset($presets[$i->fk_id_pkg_svc]))
                ->map(fn ($i) => ['id' => (int) $i->fk_id_pkg_svc, 'label' => self::label($presets[$i->fk_id_pkg_svc]), 'rate' => (float) $presets[$i->fk_id_pkg_svc]->rate, 'pinned' => (bool) $i->f_pinned, 'sort' => (int) $i->sort_order, 'default' => (int) $g->fk_id_default_preset === (int) $i->fk_id_pkg_svc])->all();
            usort($items, fn ($a, $b) => [$a['pinned'] ? 0 : 1, $a['sort'], mb_strtolower($a['label'])] <=> [$b['pinned'] ? 0 : 1, $b['sort'], mb_strtolower($b['label'])]);
            foreach ($items as &$i) { $inAny[$i['id']] = true; $i['selected'] = ! $selected && $current && $i['id'] === $current; if ($i['selected']) $selected = true; } unset($i);
            if ($default === null && ! $out) { $d = collect($items)->firstWhere('default', true); $default = $d['id'] ?? null; }
            $out[] = ['id' => (int) $g->id, 'name' => $g->group_name, 'header' => ((int) $g->f_favourite ? '★ ' : '') . $g->group_name . ($g->group_code ? ' [' . $g->group_code . ']' : ''), 'code' => $g->group_code, 'color' => $g->color, 'star' => (bool) $g->f_favourite, 'matched' => $matched, 'items' => $items];
        }
        $other = []; foreach ($presets as $p) if (! isset($inAny[(int) $p->id])) { $sel = ! $selected && $current && (int) $p->id === $current; if ($sel) $selected = true; $other[] = ['id' => (int) $p->id, 'label' => self::label($p), 'rate' => (float) $p->rate, 'pinned' => false, 'sort' => 0, 'default' => false, 'selected' => $sel]; }
        usort($other, fn ($a, $b) => mb_strtolower($a['label']) <=> mb_strtolower($b['label']));
        if ($other) $out[] = ['id' => 0, 'name' => 'Other presets', 'header' => 'Other presets', 'code' => null, 'color' => null, 'star' => false, 'matched' => false, 'items' => $other];
        $kept = null;
        if ($current && ! $selected) {      /* a saved value not in the list is kept, so editing never loses it */
            $c = DB::table('t_carrier_pkg_svc as v')->leftJoin('t_carriers as c', 'c.id', '=', 'v.fk_id_carrier')->leftJoin('t_carrier_packages as p', 'p.id', '=', 'v.fk_id_car_package')->leftJoin('t_carrier_services as s', 's.id', '=', 'v.fk_id_car_service')->where('v.id', $current)->first(['v.id', 'v.pkg_svc_name', 'v.rate', 'c.carrier_name', 'p.package_name', 's.service_name']);
            $kept = ['id' => $current, 'label' => '(current: ' . ($c ? self::label($c) : '#' . $current) . ')', 'rate' => $c ? (float) $c->rate : 0.0, 'selected' => true, 'current' => true];
        }
        return ['groups' => $out, 'current' => $kept, 'default' => $current ? null : $default, 'marketplace' => $marketplace, 'shop' => $shop];
    }

    /* ---------------- R-D-66 ---------------- */
    /** Every (ent, carrier, package, service) written together, as the old tool stored it → the combinations that give that text. */
    public static function virtualMap(): array
    {
        $map = [];
        foreach (DB::table('t_carrier_pkg_svc as v')->join('t_carriers as c', 'c.id', '=', 'v.fk_id_carrier')->orderBy('v.id')->get(['v.id', 'c.fk_id_ent', 'v.fk_id_carrier', 'v.fk_id_car_package', 'v.fk_id_car_service']) as $p) {
            $key = $p->fk_id_ent . $p->fk_id_carrier . $p->fk_id_car_package . $p->fk_id_car_service; $combo = $p->fk_id_ent . '|' . $p->fk_id_carrier . '|' . $p->fk_id_car_package . '|' . $p->fk_id_car_service;
            $map[$key][$combo] ??= (int) $p->id;      /* the lowest preset id of each combination */
        }
        return $map;
    }

    /** One old value → ['preset' => id] or ['ambiguous' => candidates] / ['unknown' => true]. */
    public static function convert(string $virtual, ?array $map = null): array
    {
        $map ??= self::virtualMap(); $hits = $map[trim($virtual)] ?? [];
        if (count($hits) === 1) return ['preset' => (int) reset($hits)];
        return $hits ? ['ambiguous' => array_map(fn ($combo, $id) => ['preset' => $id, 'combination' => $combo], array_keys($hits), $hits)] : ['unknown' => true];
    }

    /**
     * Convert the old values of a table column. $rows = [record id => old value]. Without $apply nothing is written.
     * Unclear values go to the review list (mo_carrier_value_review) – never guessed.
     */
    public static function migrate(string $table, string $column, array $rows, bool $apply, ?object $u = null): array
    {
        if (! in_array("$table.$column", ['t_product_vendors.fk_id_car_pkg_svc', 't_sales.fk_id_car_pkg_svc', 't_product_marketplace_shipping.fk_id_car_pkg_svc'], true)) throw new \InvalidArgumentException('This column does not hold carrier presets.');
        if ($apply && (($u->role_code ?? '') !== 'OWNER')) throw new \InvalidArgumentException('Only the Owner runs this conversion.');
        $map = self::virtualMap(); $done = 0; $review = [];
        foreach ($rows as $id => $old) {
            $r = self::convert((string) $old, $map);
            if (isset($r['preset'])) { if ($apply) DB::table($table)->where('id', (int) $id)->update([$column => $r['preset']]); $done++; continue; }
            $review[] = ['record' => (int) $id, 'old_value' => (string) $old, 'candidates' => $r['ambiguous'] ?? []];
            if ($apply) DB::table('mo_carrier_value_review')->updateOrInsert(['table_name' => $table, 'column_name' => $column, 'record_id' => (int) $id], ['fk_id_ent' => self::ent(), 'old_value' => mb_substr((string) $old, 0, 60), 'candidates_json' => json_encode($r['ambiguous'] ?? []), 'status' => 'OPEN', 'created_at' => now()]);
        }
        return ['converted' => $done, 'review' => $review, 'applied' => $apply, 'message' => ($apply ? 'Converted' : 'Would convert') . " $done value(s); " . count($review) . ' unclear value(s) ' . ($apply ? 'are on' : 'would go to') . ' the review list "Ambiguous carrier values".'];
    }

    public static function reviewList(?object $u): array
    {
        if (($u->role_code ?? '') !== 'OWNER') throw new \InvalidArgumentException('Only the Owner sees this list.');
        return DB::table('mo_carrier_value_review')->where('fk_id_ent', self::ent())->where('status', 'OPEN')->orderBy('id')->limit(500)->get()->map(function ($r) {
            $row = (array) DB::table($r->table_name)->where('id', $r->record_id)->first(); $name = ''; foreach ([Registry::labelColumn($r->table_name), 'product_name_vendor', 'SKU_VENDOR', 'listing_title', 'marketplace_sku', 'order_no'] as $lc) if ($lc && trim((string) ($row[$lc] ?? '')) !== '') { $name = (string) $row[$lc]; break; }      /* the record by its name – nobody reads an id */
            $cand = array_map(fn ($c) => $c + ['label' => ($p = DB::table('t_carrier_pkg_svc')->where('id', (int) ($c['preset'] ?? 0))->first()) ? self::label($p) : ''], json_decode((string) $r->candidates_json, true) ?: []);
            return ['id' => (int) $r->id, 'table' => $r->table_name, 'record' => (int) $r->record_id, 'name' => $name, 'old_value' => $r->old_value, 'candidates' => $cand];
        })->all();
    }

    /**
     * R-D-65 the options of a carrier field on any registry form: the same dropdown (groups offered for the form's marketplace / shop, in the rule's order),
     * flattened to "Group › Carrier - Service Package (rate)"; a preset in two groups is listed once (the first); 'default' = default of the first offered group.
     */
    public static function formOptions(string $formKey, array $parents, ?string $q = null): array
    {
        $mp = null; $shop = null;
        foreach (Registry::fields($formKey) as $p) { $v = (int) ($parents[$p['field_name']] ?? 0); if (! $v) continue;
            if ($p['column_name'] === 'fk_id_marketplace') $mp = $v; elseif (in_array($p['column_name'], ['fk_id_branch', 'fk_id_shop'], true)) $shop = $v; }
        $d = self::dropdown($mp, $shop); $opts = []; $seen = [];
        foreach ($d['groups'] as $g) foreach ($g['items'] as $i) { if (isset($seen[$i['id']])) continue; $seen[$i['id']] = 1; $l = $g['header'] . ' › ' . $i['label']; if ($q !== null && $q !== '' && mb_stripos($l, $q) === false) continue; $opts[] = [(string) $i['id'], $l]; }
        return ['options' => $opts, 'default' => $d['default'], 'saved_as' => 'current'];
    }

    /** R-D-65 preset id => its place in the dropdown (group order, then order inside the group) – lists that are not registry fields sort by it. */
    public static function rank(?int $marketplace = null, ?int $shop = null): array
    {
        $out = []; $n = 0; foreach (self::dropdown($marketplace, $shop)['groups'] as $g) foreach ($g['items'] as $i) $out[(int) $i['id']] ??= $n++;
        return $out;
    }

    /** id => "Carrier - Service Package (rate)" for any preset, active or not. */
    public static function labelsOf(array $ids): array
    {
        $out = []; foreach (DB::table('t_carrier_pkg_svc as p')->leftJoin('t_carriers as c', 'c.id', '=', 'p.fk_id_carrier')->leftJoin('t_carrier_packages as k', 'k.id', '=', 'p.fk_id_car_package')->leftJoin('t_carrier_services as v', 'v.id', '=', 'p.fk_id_car_service')
            ->whereIn('p.id', array_map('intval', $ids) ?: [0])->get(['p.*', 'c.carrier_name', 'k.package_name', 'v.service_name']) as $p) $out[(int) $p->id] = self::label($p);
        return $out;
    }

    /** The Owner picks the preset for one unclear value. */
    public static function resolve(int $reviewId, int $preset, ?object $u): array
    {
        if (($u->role_code ?? '') !== 'OWNER') throw new \InvalidArgumentException('Only the Owner decides this.');
        $r = DB::table('mo_carrier_value_review')->where('id', $reviewId)->where('fk_id_ent', self::ent())->where('status', 'OPEN')->first() ?? throw new \InvalidArgumentException('Not on the list any more.');
        if (! DB::table('t_carrier_pkg_svc')->where('id', $preset)->exists()) throw new \InvalidArgumentException('Choose a preset.');
        DB::table($r->table_name)->where('id', $r->record_id)->update([$r->column_name => $preset]); DB::table('mo_carrier_value_review')->where('id', $reviewId)->update(['status' => 'DONE', 'fk_id_preset' => $preset, 'decided_by' => $u->id, 'decided_at' => now()]);
        Audit::log($r->table_name, (int) $r->record_id, 'UPDATE', [$r->column_name => [$r->old_value, $preset]], 'Ambiguous carrier value decided', self::ent());
        return ['message' => 'Saved.'];
    }
}
