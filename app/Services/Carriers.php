<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Carrier cost model (RULES_A_SALES R-A_SALES-69, 70): carriers, their packages and services, presets (carrier + package + service + group name + rate), packings.
 * Rates carry a start date: a sale line of July keeps July's rate after the rate changes in August. The matrix shows every combination and the gaps.
 */
class Carriers
{
    // ---------- dated rates ----------
    /** The rate valid on a date: the newest period that started on or before it; a date before the first period takes the first one; no history → $current. */
    public static function rateOn(string $kind, int $id, ?string $date, float $current = 0.0): float
    {
        $date = $date ? substr($date, 0, 10) : date('Y-m-d');
        $r = DB::table('mo_rate_history')->where('kind', $kind)->where('ref_id', $id)->where('valid_from', '<=', $date)->orderByDesc('valid_from')->value('rate');
        if ($r === null) $r = DB::table('mo_rate_history')->where('kind', $kind)->where('ref_id', $id)->orderBy('valid_from')->value('rate');
        return $r === null ? $current : (float) $r;
    }

    private static function table(string $kind): array { return $kind === 'PACKING' ? ['t_packings', 'packing_rate'] : ['t_carrier_pkg_svc', 'rate']; }

    private static function money(mixed $v, string $what): float
    {
        if ($v === null || trim((string) $v) === '') throw new \InvalidArgumentException("$what is required.");
        if (! preg_match('/^\d{1,7}(\.\d{1,2})?$/', trim((string) $v))) throw new \InvalidArgumentException("$what must be an amount of 0 or more with up to 2 decimals, for example 2 or 2.20.");
        return (float) $v;
    }

    /** A new rate from a date. The column the lists show is today's rate; a rate that starts later waits in the history until its day (night job). */
    public static function setRate(string $kind, int $id, mixed $rate, ?string $from, ?object $u): array
    {
        Perm::need($u, 'setup.carriers'); if (! in_array($kind, ['CARRIER', 'PACKING'], true)) throw new \InvalidArgumentException('Unknown rate.');
        $rate = self::money($rate, 'Rate'); $from = $from ?: date('Y-m-d'); if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || ! strtotime($from)) throw new \InvalidArgumentException('Choose the date the rate starts.');
        [$t, $col] = self::table($kind); $row = DB::table($t)->where('id', $id)->first() ?? throw new \InvalidArgumentException('Not found.');
        return DB::transaction(function () use ($kind, $id, $rate, $from, $t, $col, $row, $u) {
            if (! DB::table('mo_rate_history')->where('kind', $kind)->where('ref_id', $id)->exists()) DB::table('mo_rate_history')->insert(['kind' => $kind, 'ref_id' => $id, 'rate' => (float) $row->$col, 'valid_from' => '2000-01-01', 'created_at' => now()]);      // what it was until now
            DB::table('mo_rate_history')->updateOrInsert(['kind' => $kind, 'ref_id' => $id, 'valid_from' => $from], ['rate' => $rate, 'created_by' => $u->id ?? null, 'created_at' => now()]);
            $today = self::rateOn($kind, $id, date('Y-m-d'), (float) $row->$col); DB::table($t)->where('id', $id)->update([$col => $today]);
            Audit::log($t, $id, 'UPDATE', ['rate' => [(float) $row->$col, $rate], 'valid_from' => $from]);
            return ['message' => $from > date('Y-m-d') ? 'Saved. The new rate starts on ' . date('d-m-Y', strtotime($from)) . '; until then the old rate is used.' : 'Rate saved from ' . date('d-m-Y', strtotime($from)) . '. Lines dated before keep the old rate.', 'rate_today' => $today];
        });
    }

    public static function history(string $kind, int $id): array
    {
        $today = date('Y-m-d'); $rows = DB::table('mo_rate_history as h')->leftJoin('t_users as u', 'u.id', '=', 'h.created_by')->where('h.kind', $kind)->where('h.ref_id', $id)->orderByDesc('h.valid_from')->get(['h.rate', 'h.valid_from', 'u.display_name as by_name']); $cur = false;
        return $rows->map(function ($r) use ($today, &$cur) { $is = ! $cur && $r->valid_from <= $today; if ($is) $cur = true; return ['rate' => (float) $r->rate, 'from' => $r->valid_from === '2000-01-01' ? 'the start' : date('d-m-Y', strtotime((string) $r->valid_from)), 'valid_from' => $r->valid_from, 'by' => $r->by_name, 'state' => $r->valid_from > $today ? 'starts later' : ($is ? 'now' : 'before')]; })->all();
    }

    /** Night job: a rate whose day has come becomes the rate the lists show. */
    public static function applyDue(): int
    {
        $n = 0;
        foreach (['CARRIER', 'PACKING'] as $kind) { [$t, $col] = self::table($kind);
            foreach (DB::table('mo_rate_history')->where('kind', $kind)->where('valid_from', '<=', date('Y-m-d'))->where('valid_from', '>', '2000-01-01')->distinct()->pluck('ref_id') as $id) { $r = self::rateOn($kind, (int) $id, date('Y-m-d')); if (DB::table($t)->where('id', $id)->where($col, '<>', $r)->update([$col => $r])) $n++; } }
        return $n;
    }

    // ---------- carriers, packages, services, packings ----------
    private static function carrier(int $id): object { return DB::table('t_carriers')->where('id', $id)->whereIn('fk_id_ent', [0, (int) Context::ent()])->first() ?? throw new \InvalidArgumentException('Carrier not found.'); }

    public static function saveCarrier(array $d, ?int $id, ?object $u): array
    {
        Perm::need($u, 'setup.carriers'); $name = trim((string) ($d['name'] ?? '')); if ($name === '') throw new \InvalidArgumentException('Carrier name is required.'); if (mb_strlen($name) > 150) throw new \InvalidArgumentException('The carrier name can have up to 150 characters.');
        $row = ['carrier_name' => $name, 'f_active' => (int) (bool) ($d['f_active'] ?? 1)];
        if ($id) { self::carrier($id); DB::table('t_carriers')->where('id', $id)->update($row); } else $id = (int) DB::table('t_carriers')->insertGetId(RecordService::fillNotNull('t_carriers', $row + ['fk_id_ent' => Context::ent()]));
        Audit::log('t_carriers', $id, 'UPDATE', $row); return ['message' => 'Carrier saved.', 'id' => $id];
    }

    public static function savePackage(array $d, ?int $id, ?object $u): array
    {
        Perm::need($u, 'setup.carriers'); $name = trim((string) ($d['name'] ?? '')); if ($name === '') throw new \InvalidArgumentException('Package name is required.');
        $num = function (string $k, string $label) use ($d) { $v = $d[$k] ?? 0; if ($v === '' || $v === null) $v = 0; if (! is_numeric($v) || (float) $v < 0) throw new \InvalidArgumentException("$label must be a number of 0 or more."); return (float) $v; };
        $row = ['package_name' => mb_substr($name, 0, 150), 'weight' => $num('weight', 'Weight (g)'), 'dim_length' => $num('length', 'Length (cm)'), 'dim_width' => $num('width', 'Width (cm)'), 'dim_height' => $num('height', 'Height (cm)'), 'f_active' => (int) (bool) ($d['f_active'] ?? 1)];
        if ($id) { $old = DB::table('t_carrier_packages')->where('id', $id)->first() ?? throw new \InvalidArgumentException('Package not found.'); self::carrier((int) $old->fk_id_carrier); DB::table('t_carrier_packages')->where('id', $id)->update($row); }
        else { self::carrier((int) ($d['carrier'] ?? 0)); $id = (int) DB::table('t_carrier_packages')->insertGetId(RecordService::fillNotNull('t_carrier_packages', $row + ['fk_id_carrier' => (int) $d['carrier']])); }
        Audit::log('t_carrier_packages', $id, 'UPDATE', $row); return ['message' => 'Package saved.', 'id' => $id];
    }

    public static function saveService(array $d, ?int $id, ?object $u): array
    {
        Perm::need($u, 'setup.carriers'); $name = trim((string) ($d['name'] ?? '')); if ($name === '') throw new \InvalidArgumentException('Service name is required.');
        $on = implode(',', array_values(array_unique(array_filter(array_map('intval', is_array($d['offered_on'] ?? null) ? $d['offered_on'] : explode(',', (string) ($d['offered_on'] ?? '')))))));      // marketplaces the service is offered on; empty = all
        $row = ['service_name' => mb_substr($name, 0, 150), 'f_active' => (int) (bool) ($d['f_active'] ?? 1), 'offered_on' => $on ?: null];
        if ($id) { $old = DB::table('t_carrier_services')->where('id', $id)->first() ?? throw new \InvalidArgumentException('Service not found.'); self::carrier((int) $old->fk_id_carrier); DB::table('t_carrier_services')->where('id', $id)->update($row); }
        else { self::carrier((int) ($d['carrier'] ?? 0)); $id = (int) DB::table('t_carrier_services')->insertGetId(RecordService::fillNotNull('t_carrier_services', $row + ['fk_id_carrier' => (int) $d['carrier']])); }
        Audit::log('t_carrier_services', $id, 'UPDATE', $row); return ['message' => 'Service saved.', 'id' => $id];
    }

    public static function savePacking(array $d, ?int $id, ?object $u): array
    {
        Perm::need($u, 'setup.carriers'); $name = trim((string) ($d['name'] ?? '')); if ($name === '') throw new \InvalidArgumentException('Packing name is required.'); if (mb_strlen($name) > 100) throw new \InvalidArgumentException('The packing name can have up to 100 characters.');
        if ($id) { DB::table('t_packings')->where('id', $id)->whereIn('fk_id_ent', [0, (int) Context::ent()])->first() ?? throw new \InvalidArgumentException('Packing not found.'); DB::table('t_packings')->where('id', $id)->update(['packing_name' => $name, 'f_active' => (int) (bool) ($d['f_active'] ?? 1)]);
            if (array_key_exists('rate', $d) && $d['rate'] !== '' && $d['rate'] !== null) self::setRate('PACKING', $id, $d['rate'], $d['valid_from'] ?? null, $u); }
        else { $rate = self::money($d['rate'] ?? null, 'Packing rate'); $id = (int) DB::table('t_packings')->insertGetId(['fk_id_ent' => Context::ent(), 'packing_name' => $name, 'packing_rate' => $rate, 'f_active' => 1]); DB::table('mo_rate_history')->insert(['kind' => 'PACKING', 'ref_id' => $id, 'rate' => $rate, 'valid_from' => '2000-01-01', 'created_by' => $u->id ?? null, 'created_at' => now()]); }
        Audit::log('t_packings', $id, 'UPDATE', ['packing_name' => $name]); $p = DB::table('t_packings')->where('id', $id)->first();
        return ['message' => 'Packing saved.', 'id' => $id, 'label' => $p->packing_name . '-£' . number_format((float) $p->packing_rate, 2, '.', '')];
    }

    // ---------- presets ----------
    /** Create: carrier + package + service + group name + rate. After that only the name and the rate change (the rate with its start date). */
    public static function savePreset(array $d, ?int $id, ?object $u): array
    {
        Perm::need($u, 'setup.carriers'); $name = trim((string) ($d['name'] ?? '')); if ($name === '') throw new \InvalidArgumentException('Preset Group name is required.'); $name = mb_substr($name, 0, 150);
        if ($id) {
            $old = DB::table('t_carrier_pkg_svc')->where('id', $id)->first() ?? throw new \InvalidArgumentException('Preset not found.'); self::carrier((int) $old->fk_id_carrier);
            foreach (['carrier' => 'fk_id_carrier', 'package' => 'fk_id_car_package', 'service' => 'fk_id_car_service'] as $k => $col) if (isset($d[$k]) && (int) $d[$k] !== (int) $old->$col) throw new \InvalidArgumentException('Carrier, package and service of a preset cannot be changed. Make a new preset instead.');
            DB::table('t_carrier_pkg_svc')->where('id', $id)->update(['pkg_svc_name' => $name] + (array_key_exists('f_active', $d) ? ['f_active' => (int) (bool) $d['f_active']] : []) + (array_key_exists('needs_weight', $d) ? ['needs_weight' => (int) (bool) $d['needs_weight']] : []));
            if (array_key_exists('rate', $d) && $d['rate'] !== '' && $d['rate'] !== null && ((float) $d['rate'] !== (float) $old->rate || ! empty($d['valid_from']))) self::setRate('CARRIER', $id, $d['rate'], $d['valid_from'] ?? null, $u);
            Audit::log('t_carrier_pkg_svc', $id, 'UPDATE', ['pkg_svc_name' => $name]); return ['message' => 'Preset saved.', 'id' => $id];
        }
        $car = self::carrier((int) ($d['carrier'] ?? 0)); $pkg = DB::table('t_carrier_packages')->where('id', (int) ($d['package'] ?? 0))->where('fk_id_carrier', $car->id)->first() ?? throw new \InvalidArgumentException('Choose a package of this carrier.');
        $svc = DB::table('t_carrier_services')->where('id', (int) ($d['service'] ?? 0))->where('fk_id_carrier', $car->id)->first() ?? throw new \InvalidArgumentException('Choose a service of this carrier.'); $rate = self::money($d['rate'] ?? null, 'Rate');
        if (DB::table('t_carrier_pkg_svc')->where(['fk_id_carrier' => $car->id, 'fk_id_car_package' => $pkg->id, 'fk_id_car_service' => $svc->id])->exists()) throw new \InvalidArgumentException('This carrier, package and service already have a preset.');
        $id = (int) DB::table('t_carrier_pkg_svc')->insertGetId(['pkg_svc_name' => $name, 'fk_id_carrier' => $car->id, 'fk_id_car_package' => $pkg->id, 'fk_id_car_service' => $svc->id, 'rate' => $rate, 'f_active' => 1, 'needs_weight' => (int) ! empty($d['needs_weight'])]);
        DB::table('mo_rate_history')->insert(['kind' => 'CARRIER', 'ref_id' => $id, 'rate' => $rate, 'valid_from' => '2000-01-01', 'created_by' => $u->id ?? null, 'created_at' => now()]);
        Audit::log('t_carrier_pkg_svc', $id, 'INSERT', ['pkg_svc_name' => $name, 'rate' => $rate]); return ['message' => 'Preset added.', 'id' => $id];
    }

    /** A preset that products or sale lines use is switched off (they keep it); an unused one is removed. */
    public static function deletePreset(int $id, ?object $u): array
    {
        Perm::need($u, 'setup.carriers'); $p = DB::table('t_carrier_pkg_svc')->where('id', $id)->first() ?? throw new \InvalidArgumentException('Preset not found.'); self::carrier((int) $p->fk_id_carrier);
        $used = DB::table('t_product_vendors')->where('fk_id_car_pkg_svc', $id)->count() + DB::table('t_sales')->where('fk_id_car_pkg_svc', $id)->count();
        if ($used) { DB::table('t_carrier_pkg_svc')->where('id', $id)->update(['f_active' => 0]); Audit::log('t_carrier_pkg_svc', $id, 'UPDATE', ['f_active' => [1, 0]]); return ['message' => "Switched off – $used product(s) / sale line(s) use this preset and keep it.", 'kept' => true]; }
        DB::table('t_carrier_pkg_svc')->where('id', $id)->delete(); DB::table('mo_rate_history')->where('kind', 'CARRIER')->where('ref_id', $id)->delete(); Audit::log('t_carrier_pkg_svc', $id, 'DELETE', ['pkg_svc_name' => $p->pkg_svc_name]);
        return ['message' => 'Preset removed.', 'kept' => false];
    }

    // ---------- R-A_SALES-70: the matrix with gaps ----------
    /** Every carrier × package × service combination, sorted by carrier / package / service. A combination without a preset has preset = null (shown red with "Add"). */
    public static function matrix(?object $u, array $f = []): array
    {
        $ent = (int) Context::ent(); $rows = []; $gaps = 0;
        $carriers = DB::table('t_carriers')->whereIn('fk_id_ent', [0, $ent])->when(! empty($f['carrier']), fn ($q) => $q->where('id', (int) $f['carrier']))->orderBy('carrier_name')->get(['id', 'carrier_name', 'f_active']);
        $pk = DB::table('t_carrier_packages')->whereIn('fk_id_carrier', $carriers->pluck('id'))->orderBy('package_name')->get()->groupBy('fk_id_carrier'); $sv = DB::table('t_carrier_services')->whereIn('fk_id_carrier', $carriers->pluck('id'))->orderBy('service_name')->get()->groupBy('fk_id_carrier');
        $pre = DB::table('t_carrier_pkg_svc')->whereIn('fk_id_carrier', $carriers->pluck('id'))->get()->keyBy(fn ($p) => $p->fk_id_carrier . ':' . $p->fk_id_car_package . ':' . $p->fk_id_car_service);
        foreach ($carriers as $c) foreach ($pk[$c->id] ?? [] as $p) foreach ($sv[$c->id] ?? [] as $s) {
            $x = $pre[$c->id . ':' . $p->id . ':' . $s->id] ?? null; if (! $x && (! $p->f_active || ! $s->f_active || ! $c->f_active)) continue;      // no gap for switched-off parts
            if (! $x) $gaps++;
            $rows[] = ['preset' => $x ? (int) $x->id : null, 'active' => $x ? (bool) ($x->f_active ?? 1) : null, 'needs_weight' => $x ? (bool) ($x->needs_weight ?? 0) : false, 'group' => $x->pkg_svc_name ?? null, 'carrier' => (int) $c->id, 'carrier_name' => $c->carrier_name, 'package' => (int) $p->id, 'package_name' => $p->package_name, 'weight' => (float) $p->weight, 'l' => (float) $p->dim_length, 'w' => (float) $p->dim_width, 'h' => (float) $p->dim_height,
                'service' => (int) $s->id, 'service_name' => $s->service_name, 'rate' => $x ? (float) $x->rate : null, 'later' => $x ? DB::table('mo_rate_history')->where('kind', 'CARRIER')->where('ref_id', $x->id)->where('valid_from', '>', date('Y-m-d'))->orderBy('valid_from')->first(['rate', 'valid_from']) : null];
        }
        return ['rows' => $rows, 'gaps' => $gaps, 'carriers' => $carriers->all(), 'packages' => DB::table('t_carrier_packages')->whereIn('fk_id_carrier', $carriers->pluck('id'))->orderBy('package_name')->get()->all(), 'services' => DB::table('t_carrier_services')->whereIn('fk_id_carrier', $carriers->pluck('id'))->orderBy('service_name')->get()->all(),
            'packings' => DB::table('t_packings')->whereIn('fk_id_ent', [0, $ent])->orderBy('packing_name')->get()->map(fn ($p) => ['id' => $p->id, 'name' => $p->packing_name, 'rate' => (float) $p->packing_rate, 'active' => (bool) $p->f_active, 'label' => $p->packing_name . '-£' . number_format((float) $p->packing_rate, 2, '.', '')])->all(),
            'marketplaces' => DB::table('cat_marketplaces')->whereIn('fk_id_ent', [0, $ent])->orderBy('marketplace_short')->get(['id', DB::raw("COALESCE(NULLIF(marketplace_short,''), NULLIF(marketplace_descr,''), CONCAT('Marketplace ', id)) as name")])->all(), 'can_edit' => $u && Perm::has($u, 'setup.carriers')];
    }

    /** Is a service offered on a marketplace? (empty list on the service = everywhere) */
    public static function offered(?string $offeredOn, ?int $marketplace): bool { return ! $marketplace || trim((string) $offeredOn) === '' || in_array((string) $marketplace, explode(',', (string) $offeredOn), true); }

    /**
     * The cheapest active preset whose package takes this weight (g) and size (cm, any way round) and whose service is offered on the marketplace.
     * Returns ['id', 'label', 'rate'] or null. $date = the date the rate is read for.
     */
    public static function cheapest(float $weightG, float $l = 0, float $w = 0, float $h = 0, ?int $marketplace = null, ?string $date = null): ?array
    {
        $ent = (int) Context::ent(); $need = [$l, $w, $h]; rsort($need); $best = null;
        foreach (DB::table('t_carrier_pkg_svc as v')->join('t_carriers as c', 'c.id', '=', 'v.fk_id_carrier')->join('t_carrier_packages as p', 'p.id', '=', 'v.fk_id_car_package')->join('t_carrier_services as s', 's.id', '=', 'v.fk_id_car_service')->whereIn('c.fk_id_ent', [0, $ent])
            ->where('c.f_active', 1)->where('p.f_active', 1)->where('s.f_active', 1)->where('v.f_active', 1)->get(['v.id', 'v.pkg_svc_name', 'v.rate', 'c.carrier_name', 'p.weight', 'p.dim_length', 'p.dim_width', 'p.dim_height', 's.max_weight_kg', 's.offered_on']) as $x) {
            if (! self::offered($x->offered_on, $marketplace)) continue;
            if ((float) $x->weight > 0 && $weightG > (float) $x->weight) continue; if ((float) $x->max_weight_kg > 0 && $weightG > (float) $x->max_weight_kg * 1000) continue;
            $has = [(float) $x->dim_length, (float) $x->dim_width, (float) $x->dim_height]; rsort($has); $fits = true; foreach ($need as $i => $n) if ($n > 0 && $has[$i] > 0 && $n > $has[$i]) $fits = false; if (! $fits) continue;
            $rate = self::rateOn('CARRIER', (int) $x->id, $date, (float) $x->rate); if ($rate <= 0) continue;
            if (! $best || $rate < $best['rate'] || ($rate === $best['rate'] && $x->id < $best['id'])) $best = ['id' => (int) $x->id, 'label' => $x->carrier_name . '-' . $x->pkg_svc_name, 'rate' => $rate];
        }
        return $best;
    }

    /** R-A_SALES-90 the facts of a preset's carrier service: delivery days, max weight, tracked, signature (empty = not set / no limit). */
    public static function serviceFacts(int $presetId): array
    {
        $x = DB::table('t_carrier_pkg_svc as v')->join('t_carrier_services as s', 's.id', '=', 'v.fk_id_car_service')->where('v.id', $presetId)->first(['s.service_name', 's.delivery_days', 's.max_weight_kg', 's.f_tracked', 's.f_signature']);
        if (! $x) return ['text' => ''];
        $kg = (float) $x->max_weight_kg > 0 ? rtrim(rtrim(number_format((float) $x->max_weight_kg, 3, '.', ''), '0'), '.') : null;
        $t = array_filter([(int) $x->delivery_days > 0 ? (int) $x->delivery_days . ' day' . ((int) $x->delivery_days === 1 ? '' : 's') : null, $kg ? "max $kg kg" : null, (int) $x->f_tracked ? 'tracked' : null, (int) $x->f_signature ? 'signature' : null]);
        return ['service' => $x->service_name, 'delivery_days' => (int) $x->delivery_days ?: null, 'max_weight_kg' => $kg !== null ? (float) $kg : null, 'tracked' => (bool) $x->f_tracked, 'signature' => (bool) $x->f_signature, 'text' => implode(' · ', $t)];
    }

    /** A parcel heavier than the service takes is refused; empty = no limit. */
    public static function weightGuard(?int $presetId, ?int $weightG): void
    {
        if (! $presetId || ! $weightG) return; $f = self::serviceFacts($presetId);
        if (! empty($f['max_weight_kg']) && $weightG > $f['max_weight_kg'] * 1000) throw new \InvalidArgumentException($f['service'] . ' takes at most ' . rtrim(rtrim(number_format($f['max_weight_kg'], 3, '.', ''), '0'), '.') . ' kg – choose another service.');
    }
}
