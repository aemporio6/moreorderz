<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * RULES_G_CORE R-G_CORE-57 menu builder (the sidebar comes from sidebar_modules): the Owner drags sections and items into order (saved at once),
 * renames an item, moves an item to another section, and switches an item on or off per role. Per role = the menu right "menu:<key>" of that role,
 * the same right Rights › Roles shows – so a role that does not see a screen cannot use its forms either (one rights list). Every change is in the audit log.
 */
class MenuBuilder
{
    private static function owner(object $u): void { if ($u->role_code !== 'OWNER') throw new \InvalidArgumentException('Refused: only the Owner can change the menu.'); }

    public static function tree(): array
    {
        $roles = array_values(array_filter(array_keys(Perm::roles()), fn ($r) => $r !== 'OWNER' && Perm::roles()[$r]['active']));
        $rows = DB::table('sidebar_modules')->where('e_status', 'ACTIVE')->orderBy('sort_order')->orderBy('id_module')->get();
        $out = [];
        foreach ($rows->whereNull('parent_id') as $s) $out[$s->id_module] = ['id' => (int) $s->id_module, 'name' => $s->module_name, 'items' => []];
        foreach ($rows->whereNotNull('parent_id') as $m) {
            if (! isset($out[$m->parent_id])) continue;
            $sees = []; foreach ($roles as $r) $sees[$r] = Perm::roleCan(Perm::roles()[$r]['like'], $r, 'menu:' . $m->module_key);
            $out[$m->parent_id]['items'][] = ['id' => (int) $m->id_module, 'key' => $m->module_key, 'name' => $m->module_name, 'no' => $m->screen_no, 'roles' => $sees];
        }
        return ['sections' => array_values($out), 'roles' => $roles];
    }

    /** New order of the items of one section (or of the sections, parent = null). Ids of another parent are moved into this one. */
    public static function order(object $u, ?int $parent, array $ids): string
    {
        self::owner($u); $ids = array_values(array_unique(array_map('intval', $ids)));
        if (! $ids) throw new \InvalidArgumentException('Nothing to order.');
        if ($parent !== null && ! DB::table('sidebar_modules')->where('id_module', $parent)->whereNull('parent_id')->exists()) throw new \InvalidArgumentException('Section not found.');
        $found = DB::table('sidebar_modules')->whereIn('id_module', $ids)->get()->keyBy('id_module');
        if ($found->count() !== count($ids)) throw new \InvalidArgumentException('An item was not found – reload the page.');
        foreach ($found as $m) if (($parent === null) !== ($m->parent_id === null)) throw new \InvalidArgumentException('A section and an item cannot swap places.');
        DB::transaction(function () use ($ids, $parent, $found) {
            foreach ($ids as $i => $id) {
                $ch = ['sort_order' => ($i + 1) * 10, 'updated_at' => now()]; if ($parent !== null) $ch['parent_id'] = $parent;
                DB::table('sidebar_modules')->where('id_module', $id)->update($ch);
            }
            $moved = $parent === null ? [] : $found->filter(fn ($m) => (int) $m->parent_id !== $parent)->keys()->all();
            Audit::log('sidebar_modules', $parent ?? 0, 'UPDATE', ['order' => $ids] + ($moved ? ['moved_in' => $moved] : []), 'menu builder');
        });
        return 'Order saved.';
    }

    public static function rename(object $u, int $id, string $name): string
    {
        self::owner($u); $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) throw new \InvalidArgumentException('A menu name has 1 to 120 characters.');
        $old = DB::table('sidebar_modules')->where('id_module', $id)->value('module_name') ?? throw new \InvalidArgumentException('Item not found.');
        DB::table('sidebar_modules')->where('id_module', $id)->update(['module_name' => $name, 'updated_at' => now()]);
        Audit::log('sidebar_modules', $id, 'UPDATE', ['module_name' => [$old, $name]], 'menu builder');
        return 'Renamed.';
    }

    /** Show or hide an item for one role (the role's menu right). */
    public static function role(object $u, int $id, string $role, bool $on): string
    {
        self::owner($u);
        $key = DB::table('sidebar_modules')->where('id_module', $id)->whereNotNull('parent_id')->value('module_key') ?? throw new \InvalidArgumentException('Item not found.');
        if ($role === 'OWNER') throw new \InvalidArgumentException('The Owner always sees every item.');
        Perm::setRole($role, 'menu:' . $key, $on, (int) $u->id);
        return $on ? "Shown to $role." : "Hidden from $role – and its forms with it.";
    }
}
