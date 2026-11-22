<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * RULES_G_CORE R-G_CORE-61 dry-run report of the migrated rights. The old program resolved rights as
 * rights of the person's roles (t_role_rights × t_controller_rights) ∪ his GRANTED − his REVOKED (t_account_rights);
 * per page: read = any of read / insert / update; insert; update; delete; all shops (f_all) – each = SUM > 0.
 * The report compares, per role and per person with own changes, every page / action of the old rights with the new rights
 * (Perm: role defaults + role changes + personal changes). "Must be equal": apply() writes the differences as role / personal
 * changes in the new rights, so a second run shows every role equal. Old pages that match no form are listed, never guessed.
 */
class RightsMigration
{
    public const ACTIONS = ['read' => 'VIEW', 'insert' => 'ADD', 'update' => 'EDIT', 'delete' => 'DELETE'];

    private static function ent(): int { return (int) (Context::ent() ?: 1); }

    /** Old page name → new form key: a form with that key, else the form whose fields sit on that table. */
    public static function formOf(string $controller): ?string
    {
        $c = trim($controller);
        if ($c === '') return null;
        if (DB::table('sys_forms')->where('form_key', $c)->exists() || DB::table('sys_registry')->where('form_key', $c)->exists()) return $c;
        return DB::table('sys_registry')->where('table_name', $c)->orderBy('id')->value('form_key');
    }

    /** Old role → new role code (the old name or code, upper case, when the new program knows it). */
    public static function roleOf(object $r): ?string
    {
        $known = array_keys(Perm::roles());
        foreach ([$r->iso_account_type ?? '', $r->account_type_name ?? ''] as $c) {
            $c = strtoupper(str_replace([' ', '-'], '_', trim((string) $c)));
            if ($c !== '' && in_array($c, $known, true)) return $c;
        }
        return null;
    }

    /** Old page rights of a list of old right ids: [page => [read, insert, update, delete, all]]. */
    private static function pages(array $rightIds): array
    {
        $out = [];
        if (! $rightIds) return $out;
        $rows = DB::table('t_controller_rights as cr')->join('cat_module_controllers as c', 'c.id', '=', 'cr.fk_id_controller')
            ->whereIn('cr.fk_id_right', $rightIds)->where('c.f_active', 1)->get(['c.controller_name', 'cr.f_read', 'cr.f_insert', 'cr.f_update', 'cr.f_delete', 'cr.f_all']);
        foreach ($rows->groupBy('controller_name') as $page => $g) {
            $s = fn ($f) => $g->sum($f) > 0;
            $out[$page] = ['read' => $s('f_read') || $s('f_insert') || $s('f_update'), 'insert' => $s('f_insert'), 'update' => $s('f_update'), 'delete' => $s('f_delete'), 'all' => $s('f_all')];
        }
        return $out;
    }

    /** Active old rights of an old role. */
    private static function roleRights(int $oldRoleId): array
    {
        return DB::table('t_role_rights as rr')->join('cat_rights as r', 'r.id', '=', 'rr.fk_id_right')
            ->where('rr.fk_id_role', $oldRoleId)->where('rr.f_active', 1)->where('r.f_active', 1)->pluck('r.id')->map(fn ($x) => (int) $x)->all();
    }

    /** The report. Returns roles, people, unmapped pages and totals. */
    public static function report(): array
    {
        $roles = []; $unmapped = []; $diffs = 0; $checked = 0;
        $compare = function (array $old, callable $now) use (&$unmapped, &$diffs, &$checked) {
            $lines = [];
            foreach ($old as $page => $acts) {
                $fk = self::formOf($page);
                if (! $fk) { $unmapped[$page] = true; continue; }
                foreach (self::ACTIONS as $a => $new) {
                    $n = $now("form:$fk:$new"); $checked++;
                    if ($n !== $acts[$a]) { $diffs++; $lines[] = ['page' => $page, 'form' => $fk, 'action' => $new, 'old' => $acts[$a], 'new' => $n, 'code' => "form:$fk:$new"]; }
                }
            }
            return $lines;
        };
        foreach (DB::table('cat_account_roles')->where('f_active', 1)->orderBy('id')->get() as $r) {
            $code = self::roleOf($r);
            $old = self::pages(self::roleRights((int) $r->id));
            if (! $code) { $roles[] = ['old_role' => $r->account_type_name, 'role' => null, 'pages' => count($old), 'equal' => false, 'note' => 'No new role with this name – make the role first (Rights › Roles).', 'diff' => []]; $diffs++; continue; }
            if ($code === 'OWNER') { $roles[] = ['old_role' => $r->account_type_name, 'role' => $code, 'pages' => count($old), 'equal' => true, 'note' => 'The Owner always has every right.', 'diff' => []]; continue; }
            $diff = $compare($old, fn ($c) => Perm::roleCan($code, $code, $c));
            $own = $old && ! collect($old)->every(fn ($a) => $a['all']);      // f_all 0 on a page = own shops only (now the data scope R-G_CORE-63)
            $ownNow = in_array($code, Choices::list('OWN_SHOP_ROLES', self::ent()), true);
            if ($old && $own !== $ownNow) { $diffs++; $diff[] = ['page' => 'all pages', 'form' => null, 'action' => 'ALL_SHOPS', 'old' => ! $own, 'new' => ! $ownNow, 'code' => 'scope:OWN_SHOP_ROLES']; }
            $roles[] = ['old_role' => $r->account_type_name, 'role' => $code, 'pages' => count($old), 'equal' => ! $diff, 'note' => null, 'diff' => $diff];
        }
        $people = [];
        $acc = DB::table('t_account_rights')->whereIn('e_status', ['GRANTED', 'REVOKED'])->get()->groupBy('fk_id_account');
        foreach ($acc as $accountId => $rows) {
            $u = \App\Models\User::where('fk_id_account', $accountId)->first();
            if (! $u) { $people[] = ['account' => (int) $accountId, 'user' => null, 'equal' => false, 'note' => 'No login for this person – his own changes are not moved.', 'diff' => []]; continue; }
            $ids = [];      // his base = the old role of his login's role
            foreach (DB::table('cat_account_roles')->get() as $or) if (self::roleOf($or) === strtoupper((string) $u->role_code)) $ids = array_merge($ids, self::roleRights((int) $or->id));
            $mine = fn (string $st) => $rows->where('e_status', $st)->pluck('fk_id_right')->map(fn ($x) => (int) $x)->all();
            $ids = array_values(array_diff(array_unique(array_merge($ids, $mine('GRANTED'))), $mine('REVOKED')));
            $touched = array_keys(self::pages($rows->pluck('fk_id_right')->map(fn ($x) => (int) $x)->all()));      // only the pages his own changes touch
            $old = self::pages($ids);
            foreach ($touched as $pg) $old[$pg] ??= ['read' => false, 'insert' => false, 'update' => false, 'delete' => false, 'all' => false];
            $old = array_intersect_key($old, array_flip($touched));
            Perm::flush();
            $diff = $compare($old, fn ($c) => Perm::has($u, $c));
            $people[] = ['account' => (int) $accountId, 'user' => $u->display_name ?: $u->login_id, 'user_id' => (int) $u->id, 'equal' => ! $diff, 'note' => null, 'diff' => $diff];
        }
        $old = DB::table('t_role_rights')->count() + DB::table('t_account_rights')->count();
        return ['old_rows' => $old, 'checked' => $checked, 'differences' => $diffs, 'equal' => $old > 0 && $diffs === 0, 'roles' => $roles, 'people' => $people, 'unmapped' => array_keys($unmapped),
            'message' => $old === 0 ? 'No old rights were loaded – nothing to compare yet.' : ($diffs === 0 ? 'All equal: the new rights give every role and person exactly the old rights.' : "$diffs difference(s) – Apply writes them as changes in the new rights.")];
    }

    /** Owner only: write every difference into the new rights (role changes, personal changes, own-shops scope). Returns the new report. */
    public static function apply(int $by): array
    {
        $r = self::report(); $n = 0;
        DB::transaction(function () use ($r, $by, &$n) {
            foreach ($r['roles'] as $role) foreach ($role['diff'] as $d) {
                if (! $role['role']) continue;
                if ($d['code'] === 'scope:OWN_SHOP_ROLES') {
                    $list = Choices::list('OWN_SHOP_ROLES', self::ent()); $list = $d['old'] ? array_diff($list, [$role['role']]) : array_merge($list, [$role['role']]);
                    Choices::set('OWN_SHOP_ROLES', implode(',', array_values(array_unique($list))) ?: 'OWNER', self::ent()); $n++; continue;
                }
                Perm::setRole($role['role'], $d['code'], $d['old'], $by); $n++;
            }
            foreach ($r['people'] as $p) foreach ($p['diff'] as $d) { if (! empty($p['user_id']) && $p['user_id'] && strtoupper((string) DB::table('t_users')->where('id', $p['user_id'])->value('role_code')) !== 'OWNER') { Perm::setUser($p['user_id'], $d['code'], $d['old'] ? 'GRANTED' : 'REVOKED', 'old rights migrated (R-G_CORE-61)', null, $by); $n++; } }
        });
        Perm::flush(); Rights::flush();
        Audit::log('mo_role_perms', null, 'UPDATE', ['migrated_differences' => $n], 'old rights migrated');
        return ['written' => $n] + self::report();
    }
}
