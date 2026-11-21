<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Account health per shop and month (RULES_A_SALES R-A_SALES-73 / 74).
 * A seller types the figures of his own shops; a TL / manager sees every VA, filters, and may enter for a VA. Every change is kept with who entered it.
 * The metric list (code, label, limits, which direction is good) comes from the health metrics of the shop's marketplace profile; without a profile the three usual ones are used.
 */
class ShopHealth
{
    /** R-G_CORE-83 proposed eBay defaults (owner to confirm): defect rate ≤ 0.5 % green, ≤ 2 % amber; late shipment ≤ 3 % / ≤ 7 %; cases closed without seller resolution ≤ 0.3 %. A company may set its own (setting HEALTH_METRICS, JSON); a marketplace profile's metrics win. */
    public const DEFAULT_METRICS = [['code' => 'TDR', 'label' => 'TDR', 'unit' => '%', 'direction' => 'LOWER', 'amber' => 0.5, 'red' => 2.0], ['code' => 'LDR', 'label' => 'LDR', 'unit' => '%', 'direction' => 'LOWER', 'amber' => 3.0, 'red' => 7.0], ['code' => 'CCWSR', 'label' => 'CCWSR', 'unit' => '%', 'direction' => 'LOWER', 'amber' => null, 'red' => 0.3]];
    public const DEFAULT_LEVELS = [['code' => '1', 'label' => 'Top Rated', 'short' => 'TR', 'colour' => 'green'], ['code' => '2', 'label' => 'Above Standard', 'short' => 'AS', 'colour' => 'amber'], ['code' => '3', 'label' => 'Below Standard', 'short' => 'BS', 'colour' => 'red']];
    private const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    /** Seller levels, best first. A company may set its own list (setting HEALTH_LEVELS, JSON). */
    public static function levels(?int $profileId = null): array
    {
        foreach (array_filter([$profileId ? "HEALTH_LEVELS_PROFILE_$profileId" : null, 'HEALTH_LEVELS']) as $key) { $j = json_decode((string) Settings::get($key, Context::ent(), ''), true); if (is_array($j) && $j) return array_values($j); }      // the profile's own list first, then the company's, then the usual three
        return self::DEFAULT_LEVELS;
    }

    public static function metrics(?int $profileId): array
    {
        $rows = $profileId ? DB::table('mp_profile_health_metrics')->where('fk_id_mp_profile', $profileId)->where('f_active', 1)->orderBy('id')->get() : collect();
        if ($rows->isEmpty()) { $j = json_decode((string) Settings::get('HEALTH_METRICS', Context::ent(), ''), true); return is_array($j) && $j ? array_values($j) : self::DEFAULT_METRICS; }
        return $rows->map(fn ($m) => ['code' => strtoupper($m->metric_code), 'label' => $m->label, 'unit' => $m->unit, 'direction' => str_starts_with(strtoupper((string) $m->direction), 'H') || strtoupper((string) $m->direction) === 'UP' ? 'HIGHER' : 'LOWER', 'amber' => $m->threshold_amber !== null ? (float) $m->threshold_amber : null, 'red' => $m->threshold_red !== null ? (float) $m->threshold_red : null])->all();
    }

    /** green / amber / red / '' (no value or no limit). */
    public static function score(array $metric, mixed $v): string
    {
        if ($v === null || $v === '' || ($metric['red'] === null && $metric['amber'] === null)) return '';
        $v = (float) $v; $worse = fn (?float $limit) => $limit !== null && ($metric['direction'] === 'HIGHER' ? $v < $limit : $v > $limit);
        return $worse($metric['red']) ? 'red' : ($worse($metric['amber']) ? 'amber' : 'green');
    }

    private static function account(?object $u): int { return (int) ($u->fk_id_account ?? 0); }
    public static function any(?object $u): bool { return (bool) ($u && Perm::has($u, 'orders.healthAny')); }

    /** The shops a person fills in: active shops where he is the seller or the owner (and inside his shop scope). With the "any" right: every shop in scope, or those of one VA. */
    public static function shopsFor(?object $u, ?int $va = null, array $f = [])
    {
        $q = DB::table('t_branches as b')->leftJoin('cat_marketplaces as m', 'm.id', '=', 'b.fk_id_marketplace')->leftJoin('t_account as a', 'a.id', '=', 'b.fk_id_seller')->where('b.fk_id_ent', Context::ent())->where('b.f_active', 1);
        $scope = Perm::shops($u); if ($scope !== null) $q->whereIn('b.id', $scope);
        if (! self::any($u)) { $acc = self::account($u); $q->where(fn ($w) => $w->where('b.fk_id_seller', $acc ?: -1)->orWhere('b.fk_id_owner', $acc ?: -1)); }
        elseif ($va) $q->where('b.fk_id_seller', $va);
        if (! empty($f['marketplace'])) $q->where('b.fk_id_marketplace', (int) $f['marketplace']); if (! empty($f['shop'])) $q->where('b.id', (int) $f['shop']);
        return $q->orderBy('m.marketplace_descr')->orderBy('b.shop_no')->get(['b.id', 'b.shop_no', 'b.shop_name', 'b.fk_id_marketplace', 'b.fk_id_seller', 'b.fk_id_mp_profile', 'b.shop_profile_url', DB::raw("COALESCE(NULLIF(m.marketplace_short,''), NULLIF(m.marketplace_descr,''), CONCAT('Marketplace ', m.id)) as marketplace_name"),
            DB::raw("NULLIF(TRIM(CONCAT_WS(' ', NULLIF(a.first_name,''), NULLIF(a.sur_name,''))), '') as va_name")]);
    }

    /** Opening the page makes the 12 month rows of the year for each shop that has none yet. */
    public static function ensure($shops, int $year): void
    {
        $ids = collect($shops)->pluck('id')->all(); if (! $ids) return; $have = DB::table('mo_shop_health')->whereIn('fk_id_branch', $ids)->where('year', $year)->get(['fk_id_branch', 'month'])->groupBy('fk_id_branch'); $new = [];
        foreach ($ids as $id) { $got = isset($have[$id]) ? $have[$id]->pluck('month')->map(fn ($m) => (int) $m)->all() : []; for ($m = 1; $m <= 12; $m++) if (! in_array($m, $got, true)) $new[] = ['fk_id_ent' => (int) Context::ent(), 'fk_id_branch' => $id, 'year' => $year, 'month' => $m, 'created_at' => now()]; }
        foreach (array_chunk($new, 500) as $c) DB::table('mo_shop_health')->insertOrIgnore($c);
    }

    private static function locked(int $year, int $month, ?string $today = null): bool { return sprintf('%04d-%02d', $year, $month) > substr($today ?? date('Y-m-d'), 0, 7); }

    private static function levelOf(?string $code, ?int $profileId = null): ?array { foreach (self::levels($profileId) as $l) if ((string) $l['code'] === (string) $code) return $l; return null; }

    private static function cells(object $shop, ?object $h, ?object $prev, array $metrics): array
    {
        $pf = [(int) ($h->pf_week_1 ?? 0), (int) ($h->pf_week_2 ?? 0), (int) ($h->pf_week_3 ?? 0), (int) ($h->pf_week_4 ?? 0)]; $run = []; $sum = 0; $last = 0; foreach ($pf as $i => $inc) { if ($inc !== 0) $last = $i + 1; $sum += $inc; $run[] = $sum; }
        $vals = (array) json_decode((string) ($h->metrics_json ?? ''), true); $pid = $shop->fk_id_mp_profile ? (int) $shop->fk_id_mp_profile : null; $lv = self::levelOf($h->seller_level ?? null, $pid); $plv = self::levelOf($prev->seller_level ?? null, $pid);
        return ['id' => (int) ($h->id ?? 0), 'shop' => (int) $shop->id, 'shop_label' => trim((string) $shop->shop_no) . ' (' . trim((string) $shop->shop_name) . ')', 'marketplace' => (string) $shop->marketplace_name, 'va' => (string) ($shop->va_name ?? ''), 'url' => trim((string) $shop->shop_profile_url) ?: null,
            'pf' => array_map(fn ($i) => $i < $last ? $run[$i] : null, [0, 1, 2, 3]), 'pf_increments' => $pf, 'positive' => $sum, 'negative' => (int) ($h->nf_total ?? 0), 'cnf' => (string) ($h->cnf_text ?? ''), 'onf' => (string) ($h->onf_text ?? ''),
            'metrics' => array_map(fn ($m) => ['code' => $m['code'], 'value' => $vals[$m['code']] ?? null, 'score' => self::score($m, $vals[$m['code']] ?? null)], $metrics),
            'level' => $lv['code'] ?? null, 'level_label' => $lv['label'] ?? '', 'level_short' => $lv['short'] ?? '', 'level_colour' => $lv['colour'] ?? '', 'prev_level' => $plv ? ['label' => $plv['label'], 'short' => $plv['short'], 'colour' => $plv['colour']] : null,
            'entered' => ! empty($h->updated_at) ? date('d-m-Y', strtotime((string) $h->updated_at)) : '', 'levels' => self::levels($pid)];
    }

    /** The grid of one month. $f: year, month, marketplace, va, shop. */
    public static function page(array $f, ?object $u, ?string $today = null): array
    {
        Perm::need($u, 'orders.healthOwn'); $today ??= date('Y-m-d'); $any = self::any($u); $year = (int) ($f['year'] ?? 0) ?: (int) substr($today, 0, 4); $month = (int) ($f['month'] ?? 0) ?: (int) substr($today, 5, 2); if ($month < 1 || $month > 12) $month = (int) substr($today, 5, 2);
        $va = $any ? ((int) ($f['va'] ?? 0) ?: null) : null; $shops = self::shopsFor($u, $va, $f); if ($year === (int) substr($today, 0, 4)) self::ensure($shops, $year);
        $rows = DB::table('mo_shop_health')->whereIn('fk_id_branch', $shops->pluck('id')->all() ?: [0])->where('year', $year)->where('month', $month)->get()->keyBy('fk_id_branch');
        [$py, $pm] = $month === 1 ? [$year - 1, 12] : [$year, $month - 1]; $prev = DB::table('mo_shop_health')->whereIn('fk_id_branch', $shops->pluck('id')->all() ?: [0])->where('year', $py)->where('month', $pm)->get()->keyBy('fk_id_branch');
        $locked = self::locked($year, $month, $today); $grid = []; $metricSets = [];
        foreach ($shops as $s) { $ms = self::metrics($s->fk_id_mp_profile ? (int) $s->fk_id_mp_profile : null); $c = self::cells($s, $rows[$s->id] ?? null, $prev[$s->id] ?? null, $ms); $c['metric_defs'] = $ms; $grid[] = $c; foreach ($ms as $m) $metricSets[$m['code']] = $m['label']; }
        if (! $grid) $metricSets = array_column(self::DEFAULT_METRICS, 'label', 'code');
        $all = $any ? self::shopsFor($u) : $shops; $years = DB::table('mo_shop_health')->where('fk_id_ent', Context::ent())->distinct()->orderByDesc('year')->pluck('year')->map(fn ($y) => (int) $y)->all(); if (! in_array((int) substr($today, 0, 4), $years, true)) array_unshift($years, (int) substr($today, 0, 4));
        return ['year' => $year, 'month' => $month, 'month_name' => self::MONTHS[$month - 1], 'months' => array_map(fn ($i) => ['no' => $i + 1, 'name' => self::MONTHS[$i], 'locked' => self::locked($year, $i + 1, $today)], range(0, 11)), 'locked' => $locked, 'locked_text' => $locked ? 'Wait for ' . self::MONTHS[$month - 1] . ' to come.' : null,
            'rows' => $grid, 'count' => count($grid), 'result_text' => 'Search Result (' . count($grid) . ')', 'empty_text' => 'No Data Found', 'metric_columns' => $metricSets, 'levels' => self::levels(), 'read_days' => ['level' => (int) Settings::get('HEALTH_LEVEL_READ_DAY', Context::ent(), 21), 'cases' => (int) Settings::get('HEALTH_CASES_READ_DAY', Context::ent(), 23)], 'any' => $any, 'can_edit' => ! $locked,
            'boxes' => ['positive' => array_sum(array_column($grid, 'positive')), 'negative' => array_sum(array_column($grid, 'negative'))], 'years' => $years,
            'lists' => $any ? ['marketplaces' => $all->unique('fk_id_marketplace')->map(fn ($s) => ['id' => (int) $s->fk_id_marketplace, 'name' => $s->marketplace_name])->values()->all(),
                'vas' => $all->filter(fn ($s) => $s->fk_id_seller && (empty($f['marketplace']) || (int) $s->fk_id_marketplace === (int) $f['marketplace']))->unique('fk_id_seller')->map(fn ($s) => ['id' => (int) $s->fk_id_seller, 'name' => $s->va_name ?: 'Account ' . $s->fk_id_seller])->sortBy('name')->values()->all(),
                'shops' => $all->filter(fn ($s) => (empty($f['marketplace']) || (int) $s->fk_id_marketplace === (int) $f['marketplace']) && (! $va || (int) $s->fk_id_seller === $va))->map(fn ($s) => ['id' => (int) $s->id, 'name' => trim($s->shop_no) . '-' . trim((string) $s->shop_name)])->values()->all()] : null];
    }

    /** Only digits and one dot. Anything else keeps the last valid value (null = refuse). */
    public static function number(mixed $v): ?float { $s = trim((string) $v); if ($s === '') return 0.0; return preg_match('/^\d+(\.\d+)?$|^\.\d+$/', $s) ? (float) $s : null; }

    /** Save one cell. $field: pf1 … pf4 (typed as the running total), cnf, onf, nf_total, level, or metric:CODE. */
    public static function save(int $shopId, int $year, int $month, string $field, mixed $value, ?object $u, ?string $today = null): array
    {
        Perm::need($u, 'orders.healthOwn'); $shop = self::shopsFor($u)->firstWhere('id', $shopId) ?? throw new \InvalidArgumentException(self::any($u) ? 'Shop not found or not active.' : 'You can fill in only the shops where you are the seller or the owner.');
        if ($month < 1 || $month > 12) throw new \InvalidArgumentException('Choose a month.'); if (self::locked($year, $month, $today)) throw new \InvalidArgumentException('Wait for ' . self::MONTHS[$month - 1] . ' to come.');
        return DB::transaction(function () use ($shop, $year, $month, $field, $value, $u) {
            DB::table('mo_shop_health')->insertOrIgnore(['fk_id_ent' => (int) Context::ent(), 'fk_id_branch' => $shop->id, 'year' => $year, 'month' => $month, 'created_at' => now()]);
            $h = DB::table('mo_shop_health')->where(['fk_id_branch' => $shop->id, 'year' => $year, 'month' => $month])->lockForUpdate()->first(); $metrics = self::metrics($shop->fk_id_mp_profile ? (int) $shop->fk_id_mp_profile : null); $set = []; $old = null; $new = null; $bad = 'Only digits and a dot are accepted. The last valid value was kept.';
            if (preg_match('/^pf([1-4])$/', $field, $m)) { $w = (int) $m[1]; $n = self::number($value); if ($n === null) throw new \InvalidArgumentException($bad);
                $before = 0; for ($i = 1; $i < $w; $i++) $before += (int) $h->{"pf_week_$i"}; $old = $before + (int) $h->{"pf_week_$w"}; $new = (int) round($n); $inc = trim((string) $value) === '' ? 0 : $new - $before;      // typed as the running total, stored as the increment
                if ($inc < 0) throw new \InvalidArgumentException("Week $w is typed as the running total of the month: it cannot be below the $before of the earlier weeks."); $set["pf_week_$w"] = $inc; }
            elseif ($field === 'nf_total') { $n = self::number($value); if ($n === null) throw new \InvalidArgumentException($bad); $old = (int) $h->nf_total; $new = (int) round($n); $set['nf_total'] = $new; }
            elseif ($field === 'cnf' || $field === 'onf') { $old = (string) $h->{$field . '_text'}; $new = mb_substr(trim((string) $value), 0, 5000); $set[$field . '_text'] = $new; }
            elseif ($field === 'level') { $old = (string) $h->seller_level; $new = trim((string) $value); if ($new !== '' && ! self::levelOf($new, $shop->fk_id_mp_profile ? (int) $shop->fk_id_mp_profile : null)) throw new \InvalidArgumentException('Choose a seller level from the list.'); $set['seller_level'] = $new === '' ? null : $new; }
            elseif (str_starts_with($field, 'metric:')) { $code = strtoupper(substr($field, 7)); if (! in_array($code, array_column($metrics, 'code'), true)) throw new \InvalidArgumentException("This shop's marketplace profile has no health metric $code."); $n = trim((string) $value) === '' ? null : self::number($value); if (trim((string) $value) !== '' && $n === null) throw new \InvalidArgumentException($bad);
                $vals = (array) json_decode((string) $h->metrics_json, true); $old = $vals[$code] ?? null; $new = $n; if ($n === null) unset($vals[$code]); else $vals[$code] = $n; $set['metrics_json'] = json_encode($vals); }
            else throw new \InvalidArgumentException('Unknown field.');
            if ((string) $old !== (string) $new) { DB::table('mo_shop_health')->where('id', $h->id)->update($set + ['updated_by' => $u->id, 'updated_at' => now()]);
                DB::table('mo_shop_health_log')->insert(['fk_id_health' => $h->id, 'field' => $field, 'old_value' => $old === null ? null : mb_substr((string) $old, 0, 500), 'new_value' => $new === null ? null : mb_substr((string) $new, 0, 500), 'user_id' => $u->id, 'user_name' => (string) ($u->display_name ?: $u->login_id), 'user_role' => (string) ($u->custom_role ?: $u->role_code), 'created_at' => now()]); }
            $h = DB::table('mo_shop_health')->where('id', $h->id)->first(); self::alerts($shop, $h, $metrics);
            [$py, $pm] = $month === 1 ? [$year - 1, 12] : [$year, $month - 1]; $prev = DB::table('mo_shop_health')->where(['fk_id_branch' => $shop->id, 'year' => $py, 'month' => $pm])->first();
            return ['message' => 'Saved.', 'row' => self::cells($shop, $h, $prev, $metrics) + ['metric_defs' => $metrics]];
        });
    }

    /** Task HEALTH_ALERT: one per shop + metric + month while the metric is red, or while the seller level is below last month's. Closed when it is fine again. */
    public static function alerts(object $shop, object $h, array $metrics): void
    {
        $tl = self::tlOf((int) $shop->id);      // the task goes to the shop's TL (assignment role SUPERVISOR / TL), else the TL with the fewest open tasks
        $label = trim((string) $shop->shop_no) . ' (' . trim((string) $shop->shop_name) . ')'; $ym = sprintf('%04d-%02d', $h->year, $h->month); $vals = (array) json_decode((string) $h->metrics_json, true); $opt = ['ent' => (int) $h->fk_id_ent, 'module' => 'mo_shop_health', 'record_id' => (int) $h->id, 'fix_url' => '#/shop-health'] + ($tl ? ['owner_user_id' => $tl] : []);
        foreach ($metrics as $m) { $key = [$shop->id, $m['code'], $ym];
            if (self::score($m, $vals[$m['code']] ?? null) === 'red') TaskEngine::open('HEALTH_ALERT', $key, "Shop $label: {$m['label']} is " . rtrim(rtrim(number_format((float) $vals[$m['code']], 2), '0'), '.') . $m['unit'] . ' – ' . ($m['direction'] === 'HIGHER' ? 'below' : 'above') . ' the limit ' . rtrim(rtrim(number_format((float) $m['red'], 2), '0'), '.') . $m['unit'] . " (" . self::MONTHS[$h->month - 1] . " {$h->year})", $opt);
            elseif (array_key_exists($m['code'], $vals)) {      // inside its limit: this month's task and any earlier month's task of the same metric close
                foreach (DB::table('tasks')->where('rule_code', 'HEALTH_ALERT')->where('dedupe_key', 'like', "HEALTH_ALERT:{$shop->id}:{$m['code']}:%")->whereNotIn('status', ['DONE', 'APPROVED', 'CANCELLED'])->pluck('dedupe_key') as $dk)
                    if (substr($dk, -7) <= $ym) TaskEngine::close('HEALTH_ALERT', explode(':', substr($dk, 13)), 'The metric is inside its limit again'); } }
        $pid = $shop->fk_id_mp_profile ? (int) $shop->fk_id_mp_profile : null; $order = array_map(fn ($l) => (string) $l['code'], self::levels($pid)); [$py, $pm] = (int) $h->month === 1 ? [$h->year - 1, 12] : [$h->year, $h->month - 1]; $prev = DB::table('mo_shop_health')->where(['fk_id_branch' => $shop->id, 'year' => $py, 'month' => $pm])->value('seller_level');
        $now = array_search((string) $h->seller_level, $order, true); $was = array_search((string) $prev, $order, true); $key = [$shop->id, 'LEVEL', $ym];
        if ($now !== false && $was !== false && $now > $was) TaskEngine::open('HEALTH_ALERT', $key, "Shop $label: seller level dropped from " . self::levelOf($prev, $pid)['label'] . ' to ' . self::levelOf($h->seller_level, $pid)['label'] . ' (' . self::MONTHS[$h->month - 1] . " {$h->year})", $opt);
        else TaskEngine::close('HEALTH_ALERT', $key, 'The seller level is not below last month');
    }

    /** The shop's TL today: the assignment role SUPERVISOR / TL on the shop → its login. */
    public static function tlOf(int $shopId): ?int
    {
        $d = date('Y-m-d');
        $acc = DB::table('t_account_branches')->where('fk_id_branch', $shopId)->whereIn('role_code', ['SUPERVISOR', 'TL'])->where(fn ($w) => $w->whereNull('f_active')->orWhere('f_active', 1))
            ->where(fn ($w) => $w->whereNull('d_from')->orWhere('d_from', '<=', $d))->where(fn ($w) => $w->whereNull('d_to')->orWhere('d_to', '>=', $d))->orderByDesc('id')->value('fk_id_account');
        return $acc ? ((int) DB::table('t_users')->where('fk_id_account', $acc)->where('f_active', 1)->value('id') ?: null) : null;
    }

    /**
     * Service-metrics report in (Import Center): CSV with the columns shop (shop no or id), month (YYYY-MM), and one column per metric code and / or "level".
     * Every value goes through save() (same checks, history and alerts). Returns what was saved and the lines refused with why.
     */
    public static function import(string $csv, ?object $u): array
    {
        $lines = array_values(array_filter(preg_split('/\r\n|\r|\n/', ltrim($csv, "\xEF\xBB\xBF")), fn ($l) => trim($l) !== ''));
        if (count($lines) < 2) throw new \InvalidArgumentException('The file has no rows.');
        $head = array_map(fn ($h) => strtolower(trim($h)), str_getcsv($lines[0]));
        $si = array_search('shop', $head, true); $mi = array_search('month', $head, true);
        if ($si === false || $mi === false) throw new \InvalidArgumentException('The file needs the columns "shop" and "month" (YYYY-MM), then one column per metric code or "level".');
        $saved = 0; $refused = [];
        foreach (array_slice($lines, 1) as $n => $l) {
            $c = str_getcsv($l); $sv = trim((string) ($c[$si] ?? '')); $mv = trim((string) ($c[$mi] ?? ''));
            $shop = DB::table('t_branches')->where('fk_id_ent', Context::ent())->where(fn ($w) => $w->where('shop_no', $sv)->orWhere('id', ctype_digit($sv) ? (int) $sv : -1))->value('id');
            if (! $shop || ! preg_match('/^(\d{4})-(\d{2})$/', $mv, $ym)) { $refused[] = 'Line ' . ($n + 2) . ': ' . (! $shop ? "shop $sv not found" : "month $mv is not YYYY-MM"); continue; }
            foreach ($head as $i => $h) {
                if ($i === $si || $i === $mi || ! isset($c[$i]) || trim($c[$i]) === '') continue;
                try { self::save((int) $shop, (int) $ym[1], (int) $ym[2], $h === 'level' ? 'level' : 'metric:' . strtoupper($h), trim($c[$i]), $u); $saved++; }
                catch (\InvalidArgumentException $e) { $refused[] = 'Line ' . ($n + 2) . " $h: " . $e->getMessage(); }
            }
        }
        return ['saved' => $saved, 'refused' => $refused, 'message' => "$saved value(s) saved" . ($refused ? ', ' . count($refused) . ' refused' : '') . '.'];
    }

    /** One shop over the 12 months of a year (trend with a colour per metric) and who entered what. */
    public static function trend(int $shopId, int $year, ?object $u): array
    {
        Perm::need($u, 'orders.healthOwn'); $shop = self::shopsFor($u)->firstWhere('id', $shopId) ?? abort(403, "You don't have rights for this shop."); $metrics = self::metrics($shop->fk_id_mp_profile ? (int) $shop->fk_id_mp_profile : null);
        $rows = DB::table('mo_shop_health')->where('fk_id_branch', $shopId)->where('year', $year)->orderBy('month')->get()->keyBy('month'); $out = [];
        for ($m = 1; $m <= 12; $m++) { $c = self::cells($shop, $rows[$m] ?? null, null, $metrics); $out[] = ['month' => self::MONTHS[$m - 1], 'positive' => $c['positive'], 'negative' => $c['negative'], 'metrics' => $c['metrics'], 'level' => $c['level_short'], 'level_colour' => $c['level_colour'], 'entered' => $c['entered']]; }
        $log = DB::table('mo_shop_health_log as l')->join('mo_shop_health as h', 'h.id', '=', 'l.fk_id_health')->where('h.fk_id_branch', $shopId)->where('h.year', $year)->orderByDesc('l.id')->limit(200)->get(['l.*', 'h.month'])
            ->map(fn ($l) => ['when' => date('d-m-Y H:i', strtotime((string) $l->created_at)), 'month' => self::MONTHS[$l->month - 1], 'field' => self::fieldLabel($l->field, $metrics), 'old' => (string) $l->old_value, 'new' => (string) $l->new_value, 'by' => $l->user_name, 'role' => $l->user_role, 'text' => 'entered by ' . ($l->user_role ?: $l->user_name)])->all();
        return ['shop' => trim((string) $shop->shop_no) . ' (' . trim((string) $shop->shop_name) . ')', 'year' => $year, 'metric_defs' => $metrics, 'months' => $out, 'history' => $log];
    }

    private static function fieldLabel(string $f, array $metrics): string
    {
        if (preg_match('/^pf([1-4])$/', $f, $m)) return 'Positive feedback week ' . $m[1]; if (str_starts_with($f, 'metric:')) { foreach ($metrics as $x) if ($x['code'] === substr($f, 7)) return $x['label']; return substr($f, 7); }
        return ['cnf' => 'Current negative feedback', 'onf' => 'Old negative feedback', 'nf_total' => 'Negative feedback (number)', 'level' => 'Seller level'][$f] ?? $f;
    }
}
