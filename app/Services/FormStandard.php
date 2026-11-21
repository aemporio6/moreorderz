<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The owner's form standard (21 Nov 2026) in one place:
 *  (a) every dropdown takes its values from one central master (a table, a person role, a reference heading or a central list) – no list kept inside a form;
 *  (b) every dropdown has "+ Add" (the new item is saved in its own module and selected), All / None when it takes several values, a default where the
 *      registry has one, and "used lately" suggestions; a list whose values drive the program is fixed (no Add, changed by the developer only);
 *  (c) a long list shows in groups (Registry::GROUPS) with search inside the dropdown;
 *  (d) a wrong value gets a red mark and, where the server knows the right one, a "Use …" link that puts it into the field (FormRules::live 'suggest');
 *  (e) the Owner hides / shows a field per role or person from the form itself (Perm field rules);
 *  (f) the Create menu is built from the registry: every main form of a module appears by itself (child lines are made inside their parent).
 */
class FormStandard
{
    public const CENTRAL = ['table', 'list', 'party', 'ref', 'own', 'chain'];
    /** Dropdowns whose items only the system makes – no "+ Add" by hand, with the reason. */
    public const SYSTEM_SOURCES = ['t_sale_orders' => 'orders come from the sales import', 'fin_statements' => 'statements come from the bank import',
        'print_adapters' => 'the printer drivers the tool can talk to', 'chain:device_slots' => 'the free slots of the chosen device'];

    public static function fixedList(string $code): bool
    {
        return Schema::hasTable('sys_field_lists') && (bool) DB::table('sys_field_lists')->where('list_code', $code)->value('f_fixed');
    }

    /** "+ Add" on a person dropdown makes the new person with the dropdown's first role. */
    public static function partyPreset(string $source): ?array
    {
        if (! str_starts_with($source, 'party:')) return null;
        $role = strtoupper(trim(explode(',', substr($source, 6))[0] ?? ''));
        if ($role === '') return null;
        $id = DB::table('cat_account_types')->whereRaw('UPPER(role_code) = ?', [$role])->where('f_active', 1)->where(fn ($w) => $w->where('fk_id_ent', Context::ent())->orWhere('fk_id_ent', 0)->orWhereNull('fk_id_ent'))->orderByDesc('fk_id_ent')->value('id');
        return $id ? ['roles' => (string) $id] : null;
    }

    /** "+ Add" on a narrowed list (table:fin_categories?direction=OUT) fills the same value on the new record. */
    public static function sourcePreset(string $source): ?array
    {
        if (! str_starts_with($source, 'table:') || ! str_contains($source, '?')) return null;
        parse_str(explode('?', $source, 2)[1], $q); unset($q['login']);
        return $q ? array_map('strval', $q) : null;
    }

    /** Department of a form for the Create menu: sys_forms, else the menu section that lists it, else by its table. */
    private static function dept(string $form, ?string $table, array $menu): string
    {
        if (isset($menu[$form])) return $menu[$form];
        return match (true) {
            str_starts_with((string) $table, 't_src_') => 'Buying', str_starts_with((string) $table, 'fin_') || str_starts_with((string) $table, 'acc_') || str_starts_with((string) $table, 'tax_') => 'Money',
            str_starts_with((string) $table, 'cat_') || str_starts_with((string) $table, 'sys_') => 'Setup & lists', str_starts_with((string) $table, 't_account') => 'People & Accounts',
            str_starts_with((string) $table, 't_product') => 'Products & Listings', default => 'Other',
        };
    }

    /**
     * (f) The Create menu: every registry form of kind module / library that has a table, minus the ones switched off in sys_forms (f_in_create = 0);
     * sys_forms gives title, department, order and "quick" where it has a row. Only what the role may add.
     */
    public static function createCatalogue(string $role, ?callable $can = null): array
    {
        $can ??= fn ($form) => Rights::can($role, 'ADD', $form);
        $sf = DB::table('sys_forms')->get(['form_key', 'title', 'department', 'module', 'f_quick', 'f_in_create', 'f_active', 'sort_no'])->keyBy('form_key');
        $menu = [];
        $mods = DB::table('sidebar_modules')->where('e_status', 'ACTIVE')->get(['id_module', 'parent_id', 'forms', 'module_name', 'department'])->keyBy('id_module');
        $top = function ($m) use ($mods) { for ($i = 0; $i < 6 && $m->parent_id && isset($mods[$m->parent_id]); $i++) $m = $mods[$m->parent_id]; return (string) ($m->department ?: $m->module_name); };      // the menu section, not the leaf
        foreach ($mods as $m) if ($m->forms) foreach (preg_split('/[;,\s]+/', (string) $m->forms, -1, PREG_SPLIT_NO_EMPTY) as $f) $menu[trim(explode('(', $f)[0], ' `')] ??= $top($m);
        $forms = DB::table('sys_registry')->whereIn('form_kind', ['module', 'library'])->whereNotNull('table_name')->where('table_name', '<>', '')
            ->select('form_key', DB::raw('MIN(form_title) as title'), DB::raw('MIN(table_name) as tbl'))->groupBy('form_key')->get();
        $out = [];
        foreach ($forms as $f) {
            $s = $sf[$f->form_key] ?? null;
            if ($s && (! $s->f_active || ! $s->f_in_create)) continue;      // switched off on purpose
            if (! Registry::exists($f->form_key) || ! $can($f->form_key)) continue;
            $out[] = ['key' => $f->form_key, 'title' => $s->title ?? $f->title, 'dept' => ($s ? ($s->department ?: $s->module) : null) ?: self::dept($f->form_key, $f->tbl, $menu), 'quick' => (bool) ($s->f_quick ?? false), 'sort' => $s ? (int) $s->sort_no : 100000];
        }
        usort($out, fn ($a, $b) => [$a['sort'], $a['dept'], $a['title']] <=> [$b['sort'], $b['dept'], $b['title']]);
        return array_map(fn ($x) => array_diff_key($x, ['sort' => 1]), $out);
    }

    /** Audit of every dropdown field: [rows, summary]. Used by docs/FORM_STANDARD_AUDIT.md and FormStandardTest. */
    public static function audit(): array
    {
        $rows = [];
        foreach (DB::table('sys_registry')->whereIn('type', ['select', 'multiselect'])->orderBy('form_key')->orderBy('field_no')->get() as $r) {
            $f = (array) $r; [$kind, $arg] = array_pad(explode(':', (string) $f['source'], 2), 2, '');
            $way = FormAssist::addWay($f);
            $fixed = $kind === 'list' && self::fixedList($arg);
            $table = FormAssist::sourceTable($f);
            $sys = self::SYSTEM_SOURCES[$table ?? ''] ?? self::SYSTEM_SOURCES[(string) $f['source']] ?? null;
            $rows[] = ['form' => $f['form_key'], 'field' => $f['field_name'], 'type' => $f['type'], 'source' => (string) $f['source'], 'central' => in_array($kind, self::CENTRAL, true),
                'add' => $fixed ? 'fixed' : ($way ? (isset($way['form']) ? 'form:' . $way['form'] : 'inline') : ($sys ? 'system' : 'none')), 'why' => $fixed ? 'values drive the program' : $sys, 'default' => (bool) $f['default_rule'],
                'grouped' => $table !== null && isset(Registry::GROUPS[$table]), 'table' => $table];
        }
        $sum = ['fields' => count($rows), 'central' => count(array_filter($rows, fn ($x) => $x['central'])), 'add' => count(array_filter($rows, fn ($x) => str_starts_with($x['add'], 'form:') || $x['add'] === 'inline')),
            'fixed' => count(array_filter($rows, fn ($x) => $x['add'] === 'fixed')), 'system' => count(array_filter($rows, fn ($x) => $x['add'] === 'system')), 'no_add' => count(array_filter($rows, fn ($x) => $x['add'] === 'none')), 'multi' => count(array_filter($rows, fn ($x) => $x['type'] === 'multiselect')),
            'with_default' => count(array_filter($rows, fn ($x) => $x['default'])), 'grouped' => count(array_filter($rows, fn ($x) => $x['grouped']))];
        return [$rows, $sum];
    }
}
