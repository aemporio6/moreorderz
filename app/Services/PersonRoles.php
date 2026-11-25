<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-C-16 the roles of a person are saved as a difference: added roles are inserted (or switched on again), removed roles are switched off,
 * the others keep their row. At least one role stays. Each change = one history line on the person + event PERSON_ROLE_ADDED / PERSON_ROLE_REMOVED.
 * A role that is still used (owner / seller of a shop – R-C-17) cannot be removed.
 * "Add role to selected" on the People list = addToMany().
 */
class PersonRoles
{
    public const KEYS = ['account_role[]', 'account_role'];

    /** The roles sent with a person form, or null when the form did not send the field. */
    public static function given(array $input): ?array
    {
        foreach (self::KEYS as $k) if (array_key_exists($k, $input)) return array_values(array_unique(array_filter(array_map('intval', is_array($input[$k]) ? $input[$k] : explode(',', (string) $input[$k])))));
        return null;
    }

    public static function current(int $person): array { return DB::table('t_account_in_types')->where('fk_id_account', $person)->where('f_active', 1)->orderBy('id')->pluck('fk_id_account_type')->map(fn ($x) => (int) $x)->unique()->values()->all(); }

    /** What would stop this set – asked before anything is saved. */
    public static function check(?int $person, array $ids): ?string
    {
        if (! $ids) return 'Choose at least one role.';
        $known = DB::table('cat_account_types')->whereIn('id', $ids)->pluck('id')->map(fn ($x) => (int) $x)->all();
        if (array_diff($ids, $known)) return 'Choose the roles from the list.';
        if ($person) foreach (array_diff(self::current($person), $ids) as $gone) if ($why = PartyUse::roleGuard($person, $gone)) return $why;
        return null;
    }

    public static function sync(int $person, array $ids): array
    {
        if ($why = self::check($person, $ids)) throw new \InvalidArgumentException($why);
        return DB::transaction(function () use ($person, $ids) {
            $now = self::current($person); $add = array_values(array_diff($ids, $now)); $del = array_values(array_diff($now, $ids));
            $codes = DB::table('cat_account_types')->whereIn('id', array_merge($add, $del) ?: [0])->get(['id', 'role_code', 'account_type_name'])->keyBy('id');
            $name = fn ($r) => (string) (($codes[$r]->role_code ?? '') ?: ($codes[$r]->account_type_name ?? $r));
            foreach ($add as $r) {
                $row = DB::table('t_account_in_types')->where('fk_id_account', $person)->where('fk_id_account_type', $r)->orderBy('id')->value('id');
                if ($row) DB::table('t_account_in_types')->where('id', $row)->update(['f_active' => 1]); else DB::table('t_account_in_types')->insert(RecordService::fillNotNull('t_account_in_types', ['fk_id_account' => $person, 'fk_id_account_type' => $r, 'f_active' => 1]));
                Audit::log('t_account', $person, 'UPDATE', ['role' => [null, $name($r)]], 'Role added: ' . $name($r), Context::ent());
                EventBus::fire('PERSON_ROLE_ADDED', 't_account', $person, ['role_id' => $r, 'role_code' => $name($r), 'by' => Context::userId(), 'at' => now()->toDateTimeString()], Context::ent());
            }
            foreach ($del as $r) {
                DB::table('t_account_in_types')->where('fk_id_account', $person)->where('fk_id_account_type', $r)->update(['f_active' => 0]);
                Audit::log('t_account', $person, 'UPDATE', ['role' => [$name($r), null]], 'Role removed: ' . $name($r), Context::ent());
                EventBus::fire('PERSON_ROLE_REMOVED', 't_account', $person, ['role_id' => $r, 'role_code' => $name($r), 'by' => Context::userId(), 'at' => now()->toDateTimeString()], Context::ent());
            }
            return ['added' => count($add), 'removed' => count($del), 'kept' => count(array_intersect($now, $ids))];
        });
    }

    /** People list › "Add role to selected": the role is added to every ticked person who does not have it. */
    public static function addToMany(array $people, int $role, object $u): array
    {
        Perm::need($u, 'form:' . RecordService::formOfTable('t_account') . ':EDIT');
        $r = DB::table('cat_account_types')->where('id', $role)->where('f_active', 1)->first(['id', 'account_type_name']) ?? throw new \InvalidArgumentException('Choose a role from the list.');
        $people = DB::table('t_account')->where('fk_id_ent', Context::ent())->whereIn('id', array_slice(array_unique(array_map('intval', $people)), 0, 500))->pluck('id')->map(fn ($x) => (int) $x)->all();
        if (! $people) throw new \InvalidArgumentException('Tick at least one person.');
        $n = 0; $had = 0;
        foreach ($people as $p) { $cur = self::current($p); if (in_array($role, $cur, true)) { $had++; continue; } self::sync($p, array_merge($cur, [$role])); $n++; }
        return ['added' => $n, 'already' => $had, 'message' => "{$r->account_type_name}: added to $n person(s)" . ($had ? ", $had already had it" : '') . '.'];
    }
}
