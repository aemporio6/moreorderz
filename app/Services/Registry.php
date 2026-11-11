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
    public static function fields(string $formKey): array
    {
        return Cache::store('array')->rememberForever("reg:$formKey", fn () => DB::table('sys_registry')
            ->where('form_key', $formKey)->orderBy('section_no')->orderBy('field_no')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());
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
        $table = self::table($formKey);
        $sections = [];
        foreach ($fields as $f) {
            if (! self::visible($f, $role)) continue;
            $sections[$f['section_no']] ??= ['no' => $f['section_no'], 'title' => $f['section'] ?: 'Details', 'fields' => []];
            $stored = $f['column_name'] && self::baseTable($f['table_name']) === $table && self::hasColumn($table, $f['column_name']);
            $sections[$f['section_no']]['fields'][] = [
                'name' => $f['field_name'], 'col' => $f['column_name'], 'label' => $f['label'], 'type' => $f['type'], 'source' => $f['source'],
                'required' => (bool) $f['required'] && ! str_starts_with((string) $f['default_rule'], 'numbering:'), 'quick' => (bool) $f['in_quick'], 'readonly' => (bool) $f['readonly'] || ! $stored,
                'stored' => $stored, 'help' => $f['help'], 'step' => $f['step'], 'min' => $f['min'], 'max' => $f['max'],
                'cascades_from' => $f['cascades_from'], 'show_if' => $f['show_if'], 'required_if' => $f['required_if'],
                'add_form' => $f['add_form'], 'secret' => (bool) $f['secret'], 'auto_correct' => $f['auto_correct'],
                'has_options' => in_array($f['type'], ['select', 'multiselect'], true),
                'numbered' => str_starts_with((string) $f['default_rule'], 'numbering:'),
            ];
        }
        ksort($sections);
        return ['form_key' => $formKey, 'title' => $fields[0]['form_title'], 'kind' => $fields[0]['form_kind'], 'table' => $table, 'sections' => array_values($sections)];
    }

    public static function visible(array $f, ?string $role): bool
    {
        $v = $f['visible_to'] ?: 'all';
        if ($v === 'all' || $role === null || $role === 'OWNER') return true;
        return in_array($role, explode('_', $v), true) || in_array($role, array_map('trim', explode(',', $v)), true);
    }

    /** Dropdown options of one field: [value, label] pairs. $q narrows by text, $ent keeps to the company. */
    public static function options(array $f, ?int $ent = null, ?string $q = null, int $limit = 200): array
    {
        $src = (string) $f['source'];
        [$kind, $arg] = array_pad(explode(':', $src, 2), 2, '');
        if ($kind === 'inline' || ($src === '' && $f['options_full'])) {
            $o = json_decode((string) $f['options_full'], true) ?: [];
            return array_values(array_map(fn ($x) => is_array($x) ? [(string) $x[0], (string) ($x[1] ?? $x[0])] : [(string) $x, (string) $x], $o));
        }
        if ($kind === 'list') {
            return DB::table('sys_field_options')->where('list_code', $arg)->where('f_active', 1)->orderBy('sort')->orderBy('label')
                ->get(['value', 'label'])->map(fn ($r) => [$r->value, $r->label])->all();
        }
        if ($kind === 'ref') {
            return DB::table('reference_items as i')->join('cat_references as r', 'r.id', '=', 'i.fk_reference_id')->where('r.ref_code', $arg)->where('i.f_active', 1)
                ->when($q, fn ($x) => $x->where('i.reference_name', 'like', "%$q%"))->orderBy('i.sort_no')->orderBy('i.reference_name')->limit($limit)
                ->get(['i.id', 'i.reference_name'])->map(fn ($r) => [(string) $r->id, $r->reference_name])->all();
        }
        if ($kind === 'party') {
            return self::tableOptions('t_account', $ent, $q, $limit);
        }
        if ($kind === 'table' || $kind === 'own') {
            return self::tableOptions(explode('?', $arg)[0], $ent, $q, $limit, str_contains($arg, 'login=1'));
        }
        return [];
    }

    private static function tableOptions(string $table, ?int $ent, ?string $q, int $limit, bool $loginOnly = false): array
    {
        if (! Schema::hasTable($table)) return [];
        $pk = self::pk($table);
        if ($table === 't_account') {
            $label = DB::raw("TRIM(CONCAT_WS(' ', NULLIF(first_name,''), NULLIF(sur_name,''))) as lbl");
            $x = DB::table($table)->select($pk . ' as id', $label);
            if ($q) $x->whereRaw("CONCAT_WS(' ', first_name, sur_name) like ?", ["%$q%"]);
            if ($loginOnly) $x->where('f_login', 1);
        } else {
            $lc = self::labelColumn($table);
            $x = DB::table($table)->select($pk . ' as id', $lc . ' as lbl');
            if ($q) $x->where($lc, 'like', "%$q%");
            $x->orderBy($lc);
        }
        if ($ent && self::hasColumn($table, 'fk_id_ent')) $x->where(fn ($w) => $w->where('fk_id_ent', $ent)->orWhere('fk_id_ent', 0)->orWhereNull('fk_id_ent'));
        if (self::hasColumn($table, 'f_active')) $x->where('f_active', 1);
        return $x->limit($limit)->get()->map(fn ($r) => [(string) $r->id, (string) ($r->lbl ?? $r->id)])->all();
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
            default => null,   // rule: / calc: / linked: / history: / numbering: are filled by the module engines
        };
    }

    public static function forget(): void
    {
        Cache::store('array')->flush();
        self::$colCache = [];
    }
}
