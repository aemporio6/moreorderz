<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Task owner order and priority (R-G_CORE-94, IC-9) – one function for rule tasks and template items.
 * Owner order:
 *   (0) an owner given by the caller (e.g. the shop's TL, the approver);
 *   (1) the user on the record – PO requested_by, cycle requested_by, GRN received_by, import batch importer, or record.created_by / record.requested_by from the rule;
 *   (2) the cycle responsible user (a PO / GRN / term → its cycle's requested_by);
 *   (3) the shop's VA / listing manager (shop.* rules);
 *   (4) the category's default owner (task_categories.default_owner_user_id);
 *   (5) the active, non-absent person with the code's role who has the fewest open tasks (old role names mapped);
 *   nobody → the Owner, with the note "no <ROLE> set".
 * Every candidate must be an active login and not absent today (Tasks › Absence and cover); an absent person hands over to his cover.
 * Priority: P1 → HIGH, P2 → NORMAL, P3 → LOW; URGENT when the rule level is URGENT, the problem is ICC_RED, or the money at risk is above TASK_URGENT_MONEY (default 1,000).
 */
class TaskOwner
{
    /** Old role names (06C) → the 9 roles. */
    public const ROLE_ALIAS = ['SOURCING' => 'MANAGER', 'FINANCE_VA' => 'FINANCE', 'OWNER_HR' => 'OWNER', 'LISTING' => 'TL'];

    /** The user on the record, per table (first column that holds an active, present user wins). */
    public const RECORD_USER = [
        't_src_po' => ['requested_by', 'created_by'], 't_src_cycles' => ['requested_by', 'created_by'], 't_src_grn' => ['received_by', 'created_by'],
        'imp_batches' => ['saved_by', 'created_by'], 't_src_payment_terms' => [],
    ];

    /** A role that nobody holds hands its tasks to the next one (06C: "STOCK role fewest open → WAREHOUSE"). */
    private const ROLE_NEXT = ['STOCK' => 'WAREHOUSE', 'PARTNER' => 'OWNER'];

    public static function role(string $r): string { $r = strtoupper(trim($r)); return self::ROLE_ALIAS[$r] ?? $r; }

    public static function priority(object $rule, array $opt, int $ent): string
    {
        if ($rule->level === 'URGENT' || ! empty($opt['icc_red']) || ($opt['event_code'] ?? null) === 'ICC_RED') return 'URGENT';
        if (isset($opt['amount']) && abs((float) $opt['amount']) > (float) Settings::get('TASK_URGENT_MONEY', $ent, 1000)) return 'URGENT';
        return TaskEngine::priorityOf((string) $rule->priority);
    }

    /** Is this login away today? Absence rows name the person by account (the form lists people with a login). */
    public static function absent(int $userId, ?string $day = null): ?object
    {
        $day ??= date('Y-m-d'); $acc = (int) DB::table('t_users')->where('id', $userId)->value('fk_id_account');
        return DB::table('user_absence')->where('user_id', $acc ?: -1)->where('from_date', '<=', $day)->where(fn ($w) => $w->whereNull('to_date')->orWhere('to_date', '>=', $day))->orderByDesc('id')->first();
    }

    /** The login of an account id (absence cover is chosen as a person). */
    private static function loginOfAccount(int $acc): int { return (int) DB::table('t_users')->where('fk_id_account', $acc)->where('f_active', 1)->orderBy('id')->value('id'); }

    /** An active, present user – or his cover (one hop, the cover must be active and present). 0 = none. */
    public static function usable(int $userId): int
    {
        if ($userId <= 0 || ! DB::table('t_users')->where('id', $userId)->where('f_active', 1)->exists()) return 0;
        $a = self::absent($userId); if (! $a) return $userId;
        $cover = $a->cover_user_id ? self::loginOfAccount((int) $a->cover_user_id) : 0;
        return $cover && $cover !== $userId && ! self::absent($cover) ? $cover : 0;
    }

    /** The active, non-absent person of a role with the fewest open tasks; ties → the lowest id. */
    public static function fewestOpen(string $role, int $ent): int
    {
        $role = self::role($role);
        $custom = DB::table('mo_roles')->where('like_role', $role)->pluck('role_code')->all();
        $ids = DB::table('t_users')->where('f_active', 1)->where(fn ($w) => $w->where('fk_id_ent', $ent)->orWhereNull('fk_id_ent'))->where(fn ($w) => $w->where('role_code', $role)->orWhereIn('custom_role', $custom ?: ['-'])->orWhereIn('id', DB::table('mo_user_roles')->where('role_code', $role)->select('user_id')))->pluck('id')
            ->filter(fn ($id) => ! self::absent((int) $id))->values();
        if ($ids->isEmpty()) return 0;
        $open = DB::table('tasks')->whereIn('owner_user_id', $ids)->whereIn('status', ['TODO', 'DOING', 'STUCK'])->groupBy('owner_user_id')->selectRaw('owner_user_id, COUNT(*) n')->pluck('n', 'owner_user_id');
        return (int) $ids->sortBy(fn ($id) => [(int) ($open[$id] ?? 0), $id])->first();
    }

    /** The record's row (null when the table / id is not known). */
    private static function row(?string $table, $id): ?object
    {
        if (! $table || ! $id || ! preg_match('/^[a-z0-9_]+$/', $table) || ! \Illuminate\Support\Facades\Schema::hasTable($table)) return null;
        return DB::table($table)->where('id', (int) $id)->first();
    }

    /** The cycle of a record (cycle, PO, GRN, payment term, …). */
    private static function cycleOf(?string $table, ?object $row): int
    {
        if (! $row) return 0;
        if ($table === 't_src_cycles') return (int) $row->id;
        if (! empty($row->fk_id_cycle)) return (int) $row->fk_id_cycle;
        if (! empty($row->fk_id_po)) return (int) DB::table('t_src_po')->where('id', $row->fk_id_po)->value('fk_id_cycle');
        return 0;
    }

    /** [user id, note, step] – who gets the task and why. */
    public static function resolve(object $rule, int $ent, array $opt): array
    {
        $assign = (string) $rule->assign_to; $parts = array_filter(array_map('trim', explode('|', $assign)));
        // (0) given by the caller
        if (! empty($opt['owner_user_id']) && ($u = self::usable((int) $opt['owner_user_id']))) return [$u, null, 'given'];
        // (1) the user on the record
        $row = self::row($opt['module'] ?? null, $opt['record_id'] ?? null);
        if ($row) {
            $cols = self::RECORD_USER[$opt['module']] ?? [];
            foreach ($parts as $a) if (str_starts_with($a, 'record.')) array_unshift($cols, substr($a, 7));
            foreach (array_unique($cols) as $c) if (isset($row->$c) && ($u = self::usable((int) $row->$c))) return [$u, null, "record.$c"];
            // (2) the cycle responsible
            if ($cy = self::cycleOf($opt['module'], $row)) { $c = DB::table('t_src_cycles')->where('id', $cy)->first(['requested_by', 'created_by']); foreach (['requested_by', 'created_by'] as $k) if ($c && ($u = self::usable((int) $c->$k))) return [$u, null, 'cycle']; }
        }
        // (3) the shop's person
        foreach ($parts as $a) if (str_starts_with($a, 'shop.') && ! empty($opt['shop'])) {
            $acc = SalesImport::vaAccount((int) $opt['shop'], date('Y-m-d')) ?: (int) DB::table('t_branches')->where('id', $opt['shop'])->value('fk_id_seller');
            if ($acc && ($u = self::usable(self::loginOfAccount($acc)))) return [$u, null, 'shop'];
        }
        // (4) the category's default owner
        if ($rule->category_id && ($u = self::usable((int) DB::table('task_categories')->where('id', $rule->category_id)->value('default_owner_user_id')))) return [$u, null, 'category'];
        // (5) the role with the fewest open tasks
        $role = null;
        foreach ($parts as $a) if (str_starts_with($a, 'role:')) { $role = self::role(substr($a, 5)); for ($r = $role; $r; $r = self::ROLE_NEXT[$r] ?? null) if ($u = self::fewestOpen($r, $ent)) return [$u, null, 'role']; }
        if (! $role && (str_starts_with($assign, 'record.') || str_starts_with($assign, 'shop.'))) { $role = 'VA'; if ($u = self::fewestOpen('VA', $ent)) return [$u, null, 'role']; }
        $owner = (int) (DB::table('t_users')->where('role_code', 'OWNER')->where('f_active', 1)->orderBy('id')->value('id') ?? 0);
        return [$owner, $role && $role !== 'OWNER' ? "no $role set" : null, 'owner'];
    }
}
