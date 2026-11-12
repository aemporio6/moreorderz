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

    public static function formsOf(string $role): array
    {
        if (isset(self::$cache[$role])) return self::$cache[$role];
        $forms = self::OPEN_FORMS;
        foreach (DB::table('sidebar_modules')->where('e_status', 'ACTIVE')->whereNotNull('forms')->get(['roles', 'forms']) as $m) {
            if (! self::sees($m->roles, $role)) continue;
            foreach (preg_split('/[;,\s]+/', (string) $m->forms, -1, PREG_SPLIT_NO_EMPTY) as $f) $forms[] = trim(explode('(', $f)[0], ' `');
        }
        return self::$cache[$role] = array_values(array_unique($forms));
    }

    public static function sees(?string $roles, string $role): bool
    {
        if ($role === 'OWNER' || ! $roles) return true;
        return (bool) preg_match('/\b(all|' . preg_quote($role, '/') . ')\b/i', $roles);
    }

    public static function can(?string $role, string $action, string $formKey): bool
    {
        if (! $role) return false;
        if ($role === 'OWNER') return true;
        if ($action === 'EXPORT') return in_array($role, self::EXPORT, true) && in_array($formKey, self::formsOf($role), true);
        if ($action === 'DELETE') return $role === 'MANAGER' && in_array($formKey, self::formsOf($role), true);
        return in_array($formKey, self::formsOf($role), true);   // VIEW, ADD, EDIT
    }

    public static function check(string $action, string $formKey): void
    {
        abort_unless(self::can(Context::role(), $action, $formKey), 403, 'Your role cannot do this here. Ask the Owner if you need it.');
    }

    public static function flush(): void
    {
        self::$cache = [];
    }
}
