<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Who may do what. The menu decides what a role sees (roles of each screen in the system map);
 * a role may add and edit only the forms that sit on its screens. A few actions are kept to named roles.
 */
class Rights
{
    public const EXPORT = ['OWNER', 'MANAGER', 'FINANCE', 'ACCOUNTANT'];
    public const ADMIN = ['OWNER'];
    /** Forms every signed-in role may use (own tasks, notes …). */
    private const OPEN_FORMS = ['task', 'task_edit', 'task_comment'];

    private static array $cache = [];

    /** Forms of the menu items a role sees. $key = the made role (its menu changes count); empty = the built-in role. */
    public static function formsOf(string $role, string $key = ''): array
    {
        $ck = $role . '|' . $key;
        if (isset(self::$cache[$ck])) return self::$cache[$ck];
        $forms = self::OPEN_FORMS;
        foreach (DB::table('sidebar_modules')->where('e_status', 'ACTIVE')->whereNotNull('forms')->get(['roles', 'forms', 'module_key']) as $m) {
            if (! Perm::roleCan($role, $key ?: $role, 'menu:' . $m->module_key)) continue;
            foreach (preg_split('/[;,\s]+/', (string) $m->forms, -1, PREG_SPLIT_NO_EMPTY) as $f) $forms[] = trim(explode('(', $f)[0], ' `');
        }
        return self::$cache[$ck] = array_values(array_unique($forms));
    }

    public static function sees(?string $roles, string $role): bool
    {
        if ($role === 'OWNER' || ! $roles) return true;
        return (bool) preg_match('/\b(all|' . preg_quote($role, '/') . ')\b/i', $roles);
    }

    /** Default of a role for a form action, from its menu screens. */
    public static function defaultCan(?string $role, string $action, string $formKey, string $key = ''): bool
    {
        if (! $role) return false;
        if ($role === 'OWNER' && ($key === '' || $key === 'OWNER')) return true;
        if ($action === 'EXPORT') return in_array($role, self::EXPORT, true) && in_array($formKey, self::formsOf($role, $key), true);
        if ($action === 'DELETE') return $role === 'MANAGER' && in_array($formKey, self::formsOf($role, $key), true);
        return in_array($formKey, self::formsOf($role, $key), true);   // VIEW, ADD, EDIT
    }

    /** May the signed-in person do it (role, the role's changes, his own changes). With no one signed in: the plain role default. */
    public static function can(?string $role, string $action, string $formKey): bool
    {
        $u = \Illuminate\Support\Facades\Auth::user();
        if ($u && $u->role_code === $role) return Perm::has($u, "form:$formKey:$action");
        return Perm::roleCan($role, $role, "form:$formKey:$action");
    }

    public static function check(string $action, string $formKey): void
    {
        $u = \Illuminate\Support\Facades\Auth::user();
        if ($u) { Perm::need($u, "form:$formKey:$action"); return; }
        abort_unless(self::can(Context::role(), $action, $formKey), 403, 'Your role cannot do this here. Ask the Owner if you need it.');
    }

    public static function flush(): void
    {
        self::$cache = [];
    }
}
