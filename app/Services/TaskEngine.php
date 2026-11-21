<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Rule tasks (06C task codes, table sys_rules). A task is opened once per problem (dedupe_key) and closes itself
 * when the problem is gone. Manual tasks use the same table with source MANUAL.
 */
class TaskEngine
{
    /** rule_code => callable(array $event): list of ['key' => [...], 'title' => ..., 'link' => [...]] problems that exist now */
    private static array $checks = [];

    public static function rule(string $code): ?object
    {
        return DB::table('sys_rules')->where('rule_code', $code)->where('f_active', 1)->first();
    }

    /** Open the task of a rule for one problem. Returns the task id (existing or new), or null when the rule is switched off. */
    public static function open(string $ruleCode, array $keyParts, string $title, array $opt = []): ?int
    {
        $rule = self::rule($ruleCode);
        if (! $rule) return null;
        $ent = $opt['ent'] ?? Context::ent() ?? 0;
        $key = $ruleCode . ':' . implode(':', $keyParts);
        $open = DB::table('tasks')->where('dedupe_key', $key)->first();
        if ($open && ! in_array($open->status, ['DONE', 'APPROVED', 'CANCELLED'], true)) return $open->id;
        $data = [
            'fk_id_ent' => $ent, 'title' => mb_substr($title, 0, 255), 'description' => $opt['description'] ?? null,
            'category_id' => $rule->category_id ?: 1, 'owner_user_id' => self::assignee($rule->assign_to, $ent, $opt),
            'priority' => $rule->level === 'URGENT' || (isset($opt['amount']) && abs((float) $opt['amount']) > (float) Settings::get('TASK_URGENT_MONEY', $ent, 1000)) ? 'URGENT' : self::priorityOf((string) $rule->priority),      // money at risk above TASK_URGENT_MONEY → URGENT (IC-9)
            'status' => 'TODO', 'created_at' => now(), 'due_at' => now()->addDays((int) $rule->due_days)->toDateString(),
            'source' => 'RULE', 'rule_code' => $ruleCode, 'dedupe_key' => $key,
            'linked_module' => $opt['module'] ?? null, 'linked_record_id' => $opt['record_id'] ?? null,
            'amount_gbp' => $opt['amount'] ?? null, 'fix_url' => $opt['fix_url'] ?? $rule->fix_link, 'fk_id_event' => $opt['event_id'] ?? null,
            'last_update_at' => now(),
        ];
        if ($open) {   // the same problem came back: reopen the old task instead of a second one
            DB::table('tasks')->where('id', $open->id)->update(['status' => 'TODO', 'done_at' => null, 'due_at' => $data['due_at'], 'last_update_at' => now()]);
            self::history($open->id, 'REOPENED', 'Problem is back');
            return $open->id;
        }
        $id = DB::table('tasks')->insertGetId($data);
        self::history($id, 'CREATED', "Opened by rule $ruleCode");
        if ($data['owner_user_id']) Notify::push((int) $data['owner_user_id'], 'TASK', $data['title'], $data['fix_url'] ?: '#/tasks', (int) $id);
        return $id;
    }

    /** Close the task of a rule when its problem is gone. */
    public static function close(string $ruleCode, array $keyParts, string $why = 'Problem is gone'): bool
    {
        $key = $ruleCode . ':' . implode(':', $keyParts);
        $t = DB::table('tasks')->where('dedupe_key', $key)->whereNotIn('status', ['DONE', 'APPROVED', 'CANCELLED'])->first();
        if (! $t) return false;
        DB::table('tasks')->where('id', $t->id)->update(['status' => 'DONE', 'done_at' => now(), 'last_update_at' => now()]);
        self::history($t->id, 'AUTO_CLOSED', $why);
        return true;
    }

    public static function isOpen(string $ruleCode, array $keyParts): bool
    {
        return DB::table('tasks')->where('dedupe_key', $ruleCode . ':' . implode(':', $keyParts))->whereNotIn('status', ['DONE', 'APPROVED', 'CANCELLED'])->exists();
    }

    /** A module registers how to find the problems of a rule. sync() opens what exists and closes what is gone. */
    public static function check(string $ruleCode, callable $fn): void
    {
        self::$checks[$ruleCode] = $fn;
    }

    /** Run one rule check: $problems = list of ['key'=>[..],'title'=>..] ; anything open under $scopeKey that is not in the list closes. */
    public static function sync(string $ruleCode, array $problems, ?string $scopePrefix = null, ?int $ent = null): array
    {
        $opened = 0;
        $keys = [];
        foreach ($problems as $p) {
            $keys[] = $ruleCode . ':' . implode(':', $p['key']);
            $before = self::isOpen($ruleCode, $p['key']);
            self::open($ruleCode, $p['key'], $p['title'], ($p['opt'] ?? []) + ['ent' => $ent]);
            $opened += $before ? 0 : 1;
        }
        $q = DB::table('tasks')->where('rule_code', $ruleCode)->whereNotIn('status', ['DONE', 'APPROVED', 'CANCELLED'])->whereNotIn('dedupe_key', $keys ?: ['-']);
        if ($scopePrefix !== null) $q->where('dedupe_key', 'like', $ruleCode . ':' . $scopePrefix . '%');
        if ($ent) $q->where('fk_id_ent', $ent);
        $closed = 0;
        foreach ($q->get(['id']) as $t) {
            DB::table('tasks')->where('id', $t->id)->update(['status' => 'DONE', 'done_at' => now(), 'last_update_at' => now()]);
            self::history($t->id, 'AUTO_CLOSED', 'Problem is gone');
            $closed++;
        }
        return ['opened' => $opened, 'closed' => $closed];
    }

    /** After every event: run the registered checks of rules triggered by it. */
    public static function onEvent(array $event): void
    {
        $codes = DB::table('sys_rules')->where('f_active', 1)->where('trigger_event', $event['code'])->pluck('rule_code');
        foreach ($codes as $code) {
            if (isset(self::$checks[$code])) (self::$checks[$code])($event);
        }
    }

    /** P1 → HIGH, P2 → NORMAL, P3 → LOW (IC-9); the plain words stay as they are. */
    public static function priorityOf(string $p): string { $p = strtoupper(trim($p)); return ['P1' => 'HIGH', 'P2' => 'NORMAL', 'P3' => 'LOW'][$p] ?? (in_array($p, ['URGENT', 'HIGH', 'NORMAL', 'LOW'], true) ? $p : 'NORMAL'); }

    /** A role that nobody holds hands its tasks to the next one (06C: "STOCK role fewest open → WAREHOUSE"). */
    private const ROLE_NEXT = ['STOCK' => 'WAREHOUSE', 'PARTNER' => 'OWNER'];

    /** The active person of a role with the fewest open tasks (main role, a custom role built on it, or an extra role); ties → the lowest id. */
    public static function fewestOpen(string $role, int $ent): int
    {
        $custom = DB::table('mo_roles')->where('like_role', $role)->pluck('role_code')->all();
        $ids = DB::table('t_users')->where('f_active', 1)->where(fn ($w) => $w->where('fk_id_ent', $ent)->orWhereNull('fk_id_ent'))->where(fn ($w) => $w->where('role_code', $role)->orWhereIn('custom_role', $custom ?: ['-'])->orWhereIn('id', DB::table('mo_user_roles')->where('role_code', $role)->select('user_id')))->pluck('id');
        if ($ids->isEmpty()) return 0;
        $open = DB::table('tasks')->whereIn('owner_user_id', $ids)->whereIn('status', ['TODO', 'DOING', 'STUCK'])->groupBy('owner_user_id')->selectRaw('owner_user_id, COUNT(*) n')->pluck('n', 'owner_user_id');
        return (int) $ids->sortBy(fn ($id) => [(int) ($open[$id] ?? 0), $id])->first();
    }

    /** Owner order (IC-9): the record's user → the shop's VA / TL → the role with the fewest open tasks → the Owner. */
    private static function assignee(?string $assignTo, int $ent, array $opt): int
    {
        if (! empty($opt['owner_user_id'])) return (int) $opt['owner_user_id'];
        foreach (array_filter(array_map('trim', explode('|', (string) $assignTo))) as $a) {
            if ($a === 'record.created_by' && ! empty($opt['module']) && ! empty($opt['record_id']) && \Illuminate\Support\Facades\Schema::hasColumn($opt['module'], 'created_by')) { $u = (int) DB::table($opt['module'])->where('id', $opt['record_id'])->value('created_by'); if ($u && DB::table('t_users')->where('id', $u)->where('f_active', 1)->exists()) return $u; continue; }
            if (str_starts_with($a, 'shop.') && ! empty($opt['shop'])) { $acc = SalesImport::vaAccount((int) $opt['shop'], date('Y-m-d')) ?: (int) DB::table('t_branches')->where('id', $opt['shop'])->value('fk_id_seller'); $u = $acc ? (int) DB::table('t_users')->where('fk_id_account', $acc)->where('f_active', 1)->value('id') : 0; if ($u) return $u; continue; }
            if (str_starts_with($a, 'role:')) { $role = substr($a, 5); while ($role) { if ($u = self::fewestOpen($role, $ent)) return $u; $role = self::ROLE_NEXT[$role] ?? null; } }
        }
        if (str_starts_with((string) $assignTo, 'record.') || str_starts_with((string) $assignTo, 'shop.')) { if ($u = self::fewestOpen('VA', $ent)) return $u; }      // the record's / shop's person is not known: the VA with the fewest open tasks
        return (int) (DB::table('t_users')->where('role_code', 'OWNER')->where('f_active', 1)->orderBy('id')->value('id') ?? 0);
    }

    private static function history(int $taskId, string $action, string $note): void
    {
        DB::table('task_history')->insert(['task_id' => $taskId, 'at' => now(), 'user_id' => Context::userId() ?? 0, 'field' => 'status', 'old_value' => null, 'new_value' => $action, 'reason' => $note]);
    }

    public static function reset(): void
    {
        self::$checks = [];
    }
}
