<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The field registry is the one source of truth for every form, list and dropdown.
 * Loaded from database/meta/field_registry.csv into sys_registry by `mo:install`.
 */
class Registry
{
    /** Label columns tried, in order, when a dropdown reads another table. */
    private const LABEL_COLS = ['name', 'title', 'label', 'code', 'ent_name', 'module_name', 'reference_name', 'account_type_name', 'description'];

    private static array $colCache = [];

    public static function columns(string $table): array
    {
        return self::$colCache[$table] ??= (Schema::hasTable($table) ? Schema::getColumnListing($table) : []);
    }

    public static function hasColumn(string $table, string $col): bool
    {
        return in_array($col, self::columns($table), true);
    }

    /** All fields of one form, in section / field order. */
    private static ?array $all = null;

    public static function fields(string $formKey): array
    {
        if (! app()->runningUnitTests()) { $all = self::all(); if ($all !== null) return $all[$formKey] ?? []; }
        return Cache::store('array')->rememberForever("reg:$formKey", fn () => DB::table('sys_registry')
            ->where('form_key', $formKey)->orderBy('section_no')->orderBy('field_no')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());
    }

    /** Live 26-11 'slow': the whole registry read once (one query) and kept in the file cache until it changes – a list screen asked the registry of every form (500+ queries). */
    private static function all(): ?array
    {
        if (self::$all !== null) return self::$all;
        try {
            $v = (string) ((array) (DB::select('CHECKSUM TABLE sys_registry')[0] ?? []))['Checksum'] ?? '';      // changes with any edit of any field row
            $c = Cache::store('file')->get('reg:all');
            if (! is_array($c) || ($c['v'] ?? null) !== $v) {
                $data = []; foreach (DB::table('sys_registry')->orderBy('form_key')->orderBy('section_no')->orderBy('field_no')->orderBy('id')->get() as $r) $data[$r->form_key][] = (array) $r;
                $c = ['v' => $v, 'data' => $data]; Cache::store('file')->put('reg:all', $c, 86400);
            }
            return self::$all = $c['data'];
        } catch (\Throwable $e) { report($e); return null; }
    }

    public static function exists(string $formKey): bool
    {
        return count(self::fields($formKey)) > 0;
    }

    /** The table a form saves to: the table most of its fields live in. "t_sales#MANUAL" style variants point at the same table. */
    public static function table(string $formKey): ?string
    {
        $count = [];
        foreach (self::fields($formKey) as $f) {
            $t = self::baseTable($f['table_name']);
            if ($t) $count[$t] = ($count[$t] ?? 0) + 1;
        }
        arsort($count);
        return array_key_first($count);
    }

    public static function baseTable(?string $t): ?string
    {
        return $t ? explode('#', $t)[0] : null;
    }

    /** Primary key column: old tables use `id`, the menu uses `id_module`. */
    public static function pk(string $table): string
    {
        return self::hasColumn($table, 'id') ? 'id' : (self::hasColumn($table, 'id_' . preg_replace('/^(sidebar_|t_|cat_)/', '', rtrim($table, 's'))) ? 'id_' . preg_replace('/^(sidebar_|t_|cat_)/', '', rtrim($table, 's')) : self::columns($table)[0]);
    }

    public static function labelColumn(string $table): ?string
    {
        $cols = self::columns($table);
        foreach (self::LABEL_COLS as $c) if (in_array($c, $cols, true)) return $c;
        foreach ($cols as $c) if (str_ends_with($c, '_name') || str_ends_with($c, '_code') || str_ends_with($c, '_no')) return $c;
        return $cols[1] ?? ($cols[0] ?? null);
    }

    /** Form definition sent to the screen: sections, fields, which are quick, required, defaults. */
    public static function definition(string $formKey, ?string $role = null): array
    {
        $fields = self::fields($formKey);
        if (! $fields) abort(404, "Unknown form $formKey");
        $table = self::table($formKey); $extraReq = FormRules::required($formKey);
        $sections = [];
        foreach ($fields as $f) {
            if (! self::visible($f, $role)) continue;
            $sections[$f['section_no']] ??= ['no' => $f['section_no'], 'title' => $f['section'] ?: 'Details', 'fields' => []];
            $stored = $f['column_name'] && self::baseTable($f['table_name']) === $table && self::hasColumn($table, $f['column_name']);
            $sections[$f['section_no']]['fields'][] = [
                'name' => $f['field_name'], 'col' => $f['column_name'], 'label' => $f['label'], 'type' => $f['type'], 'source' => $f['source'],
                'required' => ((bool) $f['required'] || in_array($f['field_name'], $extraReq, true)) && ! str_starts_with((string) $f['default_rule'], 'numbering:'), 'quick' => (bool) $f['in_quick'], 'readonly' => (bool) $f['readonly'] || ! $stored || Perm::fieldMode(\Illuminate\Support\Facades\Auth::user(), $formKey, (string) $f['field_name']) === 'READONLY',
                'stored' => $stored, 'help' => $f['help'], 'step' => $f['step'], 'min' => $f['min'], 'max' => $f['max'],
                'cascades_from' => $f['cascades_from'], 'show_if' => $f['show_if'], 'required_if' => $f['required_if'],
                'add_form' => ($way = FormAssist::addWay($f))['form'] ?? null, 'add_inline' => ! empty($way['inline']), 'secret' => (bool) $f['secret'], 'auto_correct' => $f['auto_correct'],
                'default_why' => self::defaultWhy($f),
                'has_options' => in_array($f['type'], ['select', 'multiselect'], true),
                'carrier_dd' => FormAssist::sourceTable($f) === 't_carrier_pkg_svc',      /* R-D-65: default of the first offered group for a new row, "(current: x)" for a saved value that is no longer offered */
                'numbered' => str_starts_with((string) $f['default_rule'], 'numbering:'),
                'add_preset' => str_starts_with((string) $f['source'], 'ref:') ? ['fk_reference_id' => DB::table('cat_references')->where('ref_code', substr($f['source'], 4))->value('id')] : (DirectorPickers::addPreset($formKey, (string) $f['field_name']) ?? FormStandard::partyPreset((string) $f['source']) ?? FormStandard::sourcePreset((string) $f['source'])),
                'placeholder' => DirectorPickers::placeholder($formKey, (string) $f['field_name']), 'person_field' => DirectorPickers::personField($formKey, (string) $f['field_name']),
            ];
        }
        ksort($sections);
        return ['form_key' => $formKey, 'title' => $fields[0]['form_title'], 'kind' => $fields[0]['form_kind'], 'table' => $table, 'sections' => array_values($sections),
            'lookalike' => FormAssist::lookalikeFields($formKey), 'live' => FormRules::liveFields($formKey), 'live_typing' => FormRules::liveTyping($formKey), 'calc' => FormRules::calcFields($formKey), 'after_save' => FormAssist::afterSave($formKey), 'workflow' => WorkflowContext::stepOf((string) $table)];
    }

    public static function labelOf(string $formKey, string $col): ?string { foreach (self::fields($formKey) as $f) if ($f['column_name'] === $col || $f['field_name'] === $col) return $f['label']; return null; }

    public static function visible(array $f, ?string $role): bool
    {
        $mode = Perm::fieldMode(\Illuminate\Support\Facades\Auth::user(), (string) ($f['form_key'] ?? ''), (string) ($f['field_name'] ?? ''));
        if ($mode === 'HIDDEN') return false;
        if ($mode === 'EDIT' || $mode === 'READONLY') return true;
        $v = $f['visible_to'] ?: 'all';
        if ($v === 'all' || $role === null || $role === 'OWNER') return true;
        if ($v === 'OWNER_HR') { $u = \Illuminate\Support\Facades\Auth::user(); return $u ? Perm::has($u, 'people.personal_data') : false; }      // D7 / M37 personal data: Owner and the HR right only
        return in_array($role, explode('_', $v), true) || in_array($role, array_map('trim', explode(',', $v)), true);
    }

    /** Dropdown options of one field: [value, label] pairs. $q narrows by text, $ent keeps to the company. */
    /**
     * A central list (sys_field_options) that has no values yet takes them from the fields of the register that use it (options_full) – the
     * values were in the register, but on a database where the register came in after the list step they never reached the list (live 26-11).
     * Returns how many values were added.
     */
    public static function fillList(string $code): int
    {
        if ($code === '' || DB::table('sys_field_options')->where('list_code', $code)->exists()) return 0;
        $n = 0; $seen = [];
        foreach (DB::table('sys_registry')->where('source', 'list:' . $code)->whereNotNull('options_full')->where('options_full', '<>', '')->pluck('options_full') as $raw)
            foreach (json_decode((string) $raw, true) ?: [] as $o) { $v = (string) (is_array($o) ? ($o[0] ?? '') : $o); if ($v === '' || isset($seen[$v])) continue; $seen[$v] = 1;
                DB::table('sys_field_options')->insert(['list_code' => $code, 'value' => mb_substr($v, 0, 120), 'label' => mb_substr((string) (is_array($o) ? ($o[1] ?? $v) : $v), 0, 120), 'sort' => (++$n) * 10, 'f_active' => 1]); }
        return $n;
    }

    public static function options(array $f, ?int $ent = null, ?string $q = null, int $limit = 200): array
    {
        $src = (string) $f['source'];
        [$kind, $arg] = array_pad(explode(':', $src, 2), 2, '');
        if ($kind === 'inline' || ($src === '' && $f['options_full'])) {
            $o = json_decode((string) $f['options_full'], true) ?: [];
            return array_values(array_map(fn ($x) => is_array($x) ? [(string) $x[0], (string) ($x[1] ?? $x[0])] : [(string) $x, (string) $x], $o));
        }
        if ($kind === 'list') {
            if (! DB::table('sys_field_options')->where('list_code', $arg)->exists()) self::fillList($arg);      // owner 26-11 22:52: an empty central list fills itself from the register
            return DB::table('sys_field_options')->where('list_code', $arg)->where('f_active', 1)->orderBy('sort')->orderBy('label')
                ->get(['value', 'label'])->map(fn ($r) => [$r->value, $r->label])->all();
        }
        if ($kind === 'ref') {
            return DB::table('reference_items as i')->join('cat_references as r', 'r.id', '=', 'i.fk_reference_id')->where('r.ref_code', $arg)->where('i.f_active', 1)
                ->when($q, fn ($x) => $x->where('i.reference_name', 'like', "%$q%"))->orderBy('i.sort_no')->orderBy('i.reference_name')->limit($limit)
                ->get(['i.id', 'i.reference_name'])->map(fn ($r) => [(string) $r->id, $r->reference_name])->all();
        }
        if ($kind === 'party') {      // R-C-42: active people with the active role(s), sorted by first name; while nobody carries the role yet (before the roles are filled in) every person is offered
            $roles = str_starts_with($arg, '@') ? Choices::list(substr($arg, 1), $ent) : array_values(array_filter(array_map('trim', explode(',', strtoupper($arg)))));      // party:@CHOICE = the roles of a Settings › Choices multiple choice
            if (in_array('VA', $roles, true) || in_array('SELLER', $roles, true)) $roles = array_values(array_unique(array_merge($roles, ['VA', 'SELLER'])));
            $with = $roles ? DB::table('t_account_in_types as i')->join('cat_account_types as t', 't.id', '=', 'i.fk_id_account_type')->where('i.f_active', 1)->where('t.f_active', 1)->whereIn(DB::raw('UPPER(t.role_code)'), $roles)->distinct()->pluck('i.fk_id_account')->all() : [];
            return self::tableOptions('t_account', $ent, $q, $limit, false, $with ?: null);
        }
        if ($kind === 'buyer') {      // R-C-31 buyer marketplace accounts (side Buyer): "marketplace · person · login"
            return DB::table('t_account_marketplaces as m')->join('t_account as a', 'a.id', '=', 'm.fk_id_account')->leftJoin('cat_marketplaces as c', 'c.id', '=', 'm.fk_id_marketplace')
                ->where('m.market_place_type', 'Buyer')->where(fn ($w) => $w->whereNull('m.f_active')->orWhere('m.f_active', 1))
                ->when($ent, fn ($x) => $x->where(fn ($w) => $w->where('a.fk_id_ent', $ent)->orWhere('a.fk_id_ent', 0)))->orderBy('c.marketplace_short')->orderBy('a.first_name')->limit($limit)
                ->get(['m.id', 'c.marketplace_short', 'a.first_name', 'a.sur_name', 'm.web_user_login_id'])
                ->map(fn ($r) => [(string) $r->id, implode(' · ', array_filter([$r->marketplace_short ?: 'Marketplace', trim($r->first_name . ' ' . $r->sur_name), $r->web_user_login_id]))])
                ->filter(fn ($o) => $q === null || $q === '' || mb_stripos($o[1], $q) !== false)->values()->all();
        }
        if ($kind === 'table' || $kind === 'own') {
            $w = []; if (str_contains($arg, '?')) { parse_str(explode('?', $arg, 2)[1], $w); unset($w['login']); }      // table:x?col=value narrows the list (form standard)
            return self::tableOptions(explode('?', $arg)[0], $ent, $q, $limit, str_contains($arg, 'login=1'), null, $w);
        }
        return [];
    }

    /** Form standard (owner 21 Nov 2026): a long list shows in groups – the group of each row (SQL on alias t). */
    public const GROUPS = [
        't_product_vendors' => "(SELECT g.product_type_name FROM cat_product_type g WHERE g.id = t.fk_id_product_type)",
        't_branches' => "(SELECT COALESCE(NULLIF(g.marketplace_short,''), g.marketplace_descr) FROM cat_marketplaces g WHERE g.id = t.fk_id_marketplace)",
        't_src_suppliers' => "(SELECT g.country_name FROM cat_country g WHERE g.id = t.fk_id_country)",
        'cat_banks' => "(SELECT g.country_name FROM cat_country g WHERE g.id = t.country_id)",
        'cat_inventory_locations' => 't.kind',
        't_account' => "(SELECT MIN(ct.account_type_name) FROM t_account_in_types i JOIN cat_account_types ct ON ct.id = i.fk_id_account_type WHERE i.fk_id_account = t.id AND i.f_active = 1)",
        't_src_cycles' => 't.status', 't_src_po' => 't.status',
    ];
    /** Label of a row where the plain label column is not enough. */
    public const LABELS = ['cat_inventory_locations' => "CONCAT(CONCAT_WS(' › ', (SELECT s.location_name FROM cat_inventory_locations s WHERE s.id = t.fk_id_site AND s.id <> t.id), (SELECT z.location_name FROM cat_inventory_locations z WHERE z.id = t.fk_id_parent AND z.kind = 'ZONE'), t.location_name), CASE WHEN COALESCE(t.bin_type, '') <> '' THEN CONCAT(' (', UPPER(LEFT(REPLACE(t.bin_type, '_', ' '), 1)), LOWER(SUBSTRING(REPLACE(t.bin_type, '_', ' '), 2)), ')') ELSE '' END)",      /* R-H-2 path label WH1 › W3 › W3-B-2 (Shelf) */
        't_product_vendors' => "CONCAT_WS(' · ', NULLIF(t.SKU_VENDOR,''), NULLIF(t.product_name_vendor,''))", 'cat_banks' => "COALESCE(NULLIF(t.bank_name,''), t.bank_short_name)"];

    private static function tableOptions(string $table, ?int $ent, ?string $q, int $limit, bool $loginOnly = false, ?array $onlyIds = null, array $where = []): array
    {
        if (! Schema::hasTable($table)) return [];
        $pk = self::pk($table);
        if ($table === 't_account') {
            $mid = self::hasColumn($table, 'middle_name') ? "NULLIF(middle_name,''), " : '';      // label first + middle + surname (R-F_SETUP-38)
            $label = DB::raw("TRIM(CONCAT_WS(' ', NULLIF(first_name,''), {$mid}NULLIF(sur_name,''))) as lbl");
            $x = DB::table($table . ' as t')->select('t.' . $pk . ' as id', $label);
            if ($q) $x->whereRaw("CONCAT_WS(' ', first_name, " . ($mid ? 'middle_name, ' : '') . "sur_name) like ?", ["%$q%"]);
            if ($loginOnly) $x->where('f_login', 1);
            $x->where(fn ($w) => $w->whereNull('record_status')->orWhere('record_status', '<>', 'DRAFT'));      // a draft person never appears in pickers of other forms (R-C-20)
            if ($onlyIds !== null) $x->whereIn($pk, $onlyIds);
            $x->orderBy('first_name')->orderBy('sur_name');
        } else {
            $lc = self::labelColumn($table);
            $alt = $table === 't_src_po' && $lc === 'po_code' ? 'po_number' : null;      // a PO saved without its auto code is shown by its PO number, never as a bare id
            $expr = self::LABELS[$table] ?? ($alt ? "COALESCE(NULLIF(t.$lc, ''), t.$alt)" : "t.$lc");
            $x = DB::table($table . ' as t')->select('t.' . $pk . ' as id', DB::raw("$expr as lbl"));
            if ($q) $x->whereRaw("$expr like ?", ["%$q%"]);
            $x->orderByRaw($expr);
            if ($table === ProductScope::TABLE) ProductScope::apply($x, \Illuminate\Support\Facades\Auth::user(), 't');      // R-D-21: product dropdowns too
        }
        if (isset(self::GROUPS[$table])) $x->addSelect(DB::raw(self::GROUPS[$table] . ' as grp'));
        foreach ($where as $c => $v) if (is_string($c) && self::hasColumn($table, $c)) $x->where('t.' . $c, $v);
        if ($table === 'fin_bank_accounts' && $ent) $x->where('t.company_id', $ent);      // R-FIN-5 bank accounts of the company only
        if ($table === 'cat_company_house' && ! array_key_exists('f_external', $where) && self::hasColumn($table, 'f_external')) $x->where(fn ($w) => $w->whereNull('t.f_external')->orWhere('t.f_external', 0));      // R-C-13 a supplier company (made from a contact) never shows in the own-company pickers
        if ($ent && self::hasColumn($table, 'fk_id_ent')) $x->where(fn ($w) => $w->where('t.fk_id_ent', $ent)->orWhere('t.fk_id_ent', 0)->orWhereNull('t.fk_id_ent'));
        if (self::hasColumn($table, 'f_active')) $x->where('t.f_active', 1);
        return $x->limit($limit)->get()->map(fn ($r) => isset(self::GROUPS[$table]) ? [(string) $r->id, (string) ($r->lbl ?? $r->id), (string) ($r->grp ?? '') ?: 'Other'] : [(string) $r->id, (string) ($r->lbl ?? $r->id)])->all();
    }

    /** Why a new record already has a value in this field – shown as the "default" tag. Null = no default. */
    public static function defaultWhy(array $f): ?string
    {
        [$k, $a] = array_pad(explode(':', (string) $f['default_rule'], 2), 2, '');
        return match ($k) {
            'const' => 'the usual value', 'today' => 'today', 'session.company' => 'the company you are working in', 'session.user' => 'you',
            'setting' => 'from the setting ' . $a, default => null,
        };
    }

    /** Default value of a field for a new record (registry column default_rule). */
    public static function defaultValue(array $f, array $ctx): mixed
    {
        $rule = (string) $f['default_rule'];
        if ($rule === '') return null;
        [$k, $a] = array_pad(explode(':', $rule, 2), 2, '');
        return match ($k) {
            'const' => $a === 'ZERO' ? 0 : $a,
            'today' => date('Y-m-d'),
            'session.company' => $ctx['ent'] ?? null,
            'session.user' => $ctx['user'] ?? null,
            'setting' => Settings::get($a, $ctx['ent'] ?? null),
            'rule' => $a === 'R-C-4' ? AccountCode::suggest((int) ($ctx['ent'] ?? Context::ent()), in_array($f['form_key'] ?? '', AccountCode::EXTERNAL_FORMS, true)) : null,      // account code suggestion
            default => null,   // rule: / calc: / linked: / history: / numbering: are filled by the module engines
        };
    }

    public static function forget(): void
    {
        self::$all = null; try { Cache::store('file')->forget('reg:all'); } catch (\Throwable $e) {}
        Cache::store('array')->flush();
        self::$colCache = [];
    }
}
