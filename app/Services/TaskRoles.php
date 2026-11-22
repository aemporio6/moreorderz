<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * RULES_G_CORE R-G_CORE-20 task roles from the rights registry: PARTNER (Owner / Partner), MANAGER (Manager, or a TL with manager rights), VA (everyone else).
 * The boss-only task screens are rights entries (work.TASK_DASH / TASK_ALERTS / TASK_TEAM / TASK_IMPORT – default Owner, Partner, Manager); a person without the right
 * is sent to My tasks with "That page is for Partners and Managers." An empty role is nobody (never "everyone", the old bug) and System › Health says so.
 * Migration: the old cat_src_lov groups DASH_PARTNER / DASH_MANAGER give their members the role PARTNER / MANAGER.
 */
class TaskRoles
{
    public const SCREENS = ['TASK_DASH' => 'Tasks dashboard', 'TASK_ALERTS' => 'Alerts & messages', 'TASK_TEAM' => 'Team & settings', 'TASK_IMPORT' => 'Task import'];
    public const REFUSAL = 'That page is for Partners and Managers.';

    public static function of(?object $u): string
    {
        if (! $u) return 'VA';
        if (in_array($u->role_code, ['OWNER', 'PARTNER'], true)) return 'PARTNER';
        if ($u->role_code === 'MANAGER' || ($u->role_code === 'TL' && Perm::has($u, 'work.TASK_TEAM'))) return 'MANAGER';
        return 'VA';
    }

    /** Refuse a boss-only task screen without its right. */
    public static function need(object $u, string $screen, ?string $refusal = null): void
    {
        if (! Perm::has($u, 'work.' . $screen)) throw new \InvalidArgumentException($refusal ?? self::REFUSAL);
    }

    /** "V-Commerce · Task Management · Sunday 22 November 2026 · viewing as Manager (Sajjad)" – the London day. */
    public static function header(object $u): string
    {
        $co = (string) (DB::table('t_enterprise')->where('id', (int) (Context::ent() ?: $u->fk_id_ent))->value('ent_name') ?: 'MoreOrderz');
        $day = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/London')))->format('l j F Y');
        $role = ['PARTNER' => 'Partner', 'MANAGER' => 'Manager', 'VA' => 'VA'][self::of($u)];
        return "$co · Task Management · $day · viewing as $role (" . (strtok((string) ($u->display_name ?: $u->login_id), ' ') ?: $u->login_id) . ')';
    }

    /** System › Health: a task role with nobody in it. */
    public static function health(): array
    {
        $w = []; $act = DB::table('t_users')->where('f_active', 1);
        if (! (clone $act)->where('role_code', 'PARTNER')->exists()) $w[] = 'No Partner set – Partner steps (system task approvals, delegations, absences) fall to the Owner. Give a person the role Partner under Users and roles.';
        if (! (clone $act)->where('role_code', 'MANAGER')->exists()) $w[] = 'No Manager set – Manager steps (approvals after 48 hours, escalations) fall to the Partners.';
        return $w;
    }

    /** Migration of the old DASH_PARTNER / DASH_MANAGER groups (members = account ids or login ids). Returns how many logins changed. */
    public static function fromOld(): int
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('cat_src_lov')) return 0; $n = 0;
        foreach (['DASH_PARTNER' => 'PARTNER', 'DASH_MANAGER' => 'MANAGER'] as $g => $role)
            foreach (DB::table('cat_src_lov')->where('lov_group', $g)->where(fn ($w) => $w->whereNull('f_active')->orWhere('f_active', 1))->pluck('lov_value') as $v) {
                $u = DB::table('t_users')->where(fn ($w) => $w->where('fk_id_account', (int) $v)->orWhere('login_id', (string) $v))->first();
                if (! $u || $u->role_code === 'OWNER' || $u->role_code === $role) continue;
                if ($role === 'MANAGER' && $u->role_code === 'PARTNER') continue;      // a Partner stays Partner
                DB::table('t_users')->where('id', $u->id)->update(['role_code' => $role, 'custom_role' => null]); Audit::log('t_users', (int) $u->id, 'UPDATE', ['role_code' => [$u->role_code, $role]], "old $g group"); $n++;
            }
        return $n;
    }
}
