<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-D-68 protected default list items and reassign.
 * - The default item of a list is the value a form pre-selects for it (registry default_rule const:<id> or setting:<KEY> on a field whose source is
 *   that table) – e.g. product condition 1 "New" (PRODUCT_DEFAULT_CONDITION). It cannot be deleted or switched off:
 *   'The default "New" condition cannot be deleted.'
 * - A list item in use is not deleted (old for conditions: "This condition is used by n product(s). Reassign them first."); improved: every list
 *   offers Reassign – every record that uses the item is moved to another item of the same list (audited), then it can be deleted.
 */
class ListDefaults
{
    private const NOUN = ['cat_product_conditions' => 'condition'];

    /** ids of the default items of a table (for the company). */
    public static function ids(string $table, ?int $ent = null): array
    {
        $ent ??= (int) Context::ent(); $out = [];
        foreach (DB::table('sys_registry')->where('source', 'like', "table:$table%")->whereNotNull('default_rule')->where('default_rule', '<>', '')->pluck('default_rule', 'source') as $src => $rule) {
            if (explode('?', substr((string) $src, 6))[0] !== $table) continue;
            [$k, $a] = array_pad(explode(':', (string) $rule, 2), 2, '');
            $v = match ($k) { 'const' => $a, 'setting' => Settings::get($a, $ent, null), default => null };
            if ($v !== null && $v !== '' && ctype_digit((string) $v)) $out[(int) $v] = true;
        }
        return array_keys($out);
    }

    public static function noun(string $table): string { return self::NOUN[$table] ?? 'item'; }

    /** Refusal for deleting / switching off a default item, else null. */
    public static function protect(string $table, int $id, string $label): ?string
    {
        return in_array($id, self::ids($table), true) ? 'The default "' . $label . '" ' . self::noun($table) . ' cannot be deleted.' : null;
    }

    /** The in-use refusal (the old text for conditions). */
    public static function inUse(string $table, array $used): string
    {
        $n = array_sum(array_column($used, 'count'));
        if ($table === 'cat_product_conditions') return "This condition is used by $n product(s). Reassign them first.";
        return 'Not deleted – used by ' . implode(', ', array_map(fn ($x) => $x['count'] . ' ' . (preg_match('/^[A-Z]{2,}/', $x['title']) ? $x['title'] : lcfirst($x['title'])), array_slice($used, 0, 3))) . (count($used) > 3 ? ' and more' : '') . ' – reassign them first.';
    }

    /** Other active items of the same list (targets of Reassign). */
    public static function targets(string $formKey, int $fromId): array
    {
        $table = Registry::table($formKey) ?? throw new \InvalidArgumentException('Unknown list.');
        $pk = Registry::pk($table); $lc = Registry::labelColumn($table) ?: $pk; $ent = (int) Context::ent();
        return DB::table($table)->where($pk, '<>', $fromId)->when(Registry::hasColumn($table, 'f_active'), fn ($q) => $q->where('f_active', 1))
            ->when(Registry::hasColumn($table, 'fk_id_ent'), fn ($q) => $q->where(fn ($w) => $w->where('fk_id_ent', $ent)->orWhere('fk_id_ent', 0)->orWhereNull('fk_id_ent')))
            ->orderBy($lc)->limit(500)->get([$pk . ' as id', $lc . ' as label'])->map(fn ($r) => ['id' => (int) $r->id, 'label' => (string) $r->label])->all();
    }

    /** Move every record that uses $from to $to (same list). Returns how many rows moved per table. */
    public static function reassign(string $formKey, int $from, int $to, object $u): array
    {
        Perm::need($u, "form:$formKey:DELETE");
        $table = Registry::table($formKey) ?? throw new \InvalidArgumentException('Unknown list.');
        if ($from === $to) throw new \InvalidArgumentException('Choose another item to move the records to.');
        if (! in_array($to, array_column(self::targets($formKey, $from), 'id'), true)) throw new \InvalidArgumentException('Choose an active item of the same list.');
        $ent = (int) Context::ent(); $moved = [];
        return DB::transaction(function () use ($table, $from, $to, $ent, &$moved, $formKey) {
            $done = [];
            foreach (DB::table('sys_registry')->where('source', 'like', "table:$table%")->whereNotNull('column_name')->get(['table_name', 'column_name', 'source', 'form_title']) as $r) {
                if (explode('?', substr((string) $r->source, 6))[0] !== $table) continue;
                $ct = Registry::baseTable($r->table_name); if (! $ct || $ct === $table || ! Registry::hasColumn($ct, $r->column_name) || isset($done["$ct.{$r->column_name}"])) continue;
                $done["$ct.{$r->column_name}"] = true;
                $n = DB::table($ct)->where($r->column_name, $from)->when(Registry::hasColumn($ct, 'fk_id_ent'), fn ($q) => $q->where('fk_id_ent', $ent))->update([$r->column_name => $to]);
                if ($n) $moved[] = ['table' => $ct, 'column' => $r->column_name, 'title' => $r->form_title, 'count' => $n];
            }
            Audit::log($table, $from, 'UPDATE', ['reassigned to' => $to, 'moved' => $moved], 'Reassign before delete', $ent);
            return ['moved' => $moved, 'message' => array_sum(array_column($moved, 'count')) . ' record(s) moved – the item can be deleted now.'];
        });
    }
}
