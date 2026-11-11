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
            'priority' => $rule->level === 'URGENT' ? 'URGENT' : (in_array($rule->priority, ['HIGH', 'NORMAL', 'LOW'], true) ? $rule->priority : 'NORMAL'),
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

    private static function assignee(?string $assignTo, int $ent, array $opt): int
    {
        if (! empty($opt['owner_user_id'])) return (int) $opt['owner_user_id'];
        if ($assignTo && str_starts_with($assignTo, 'role:')) {
            $role = substr($assignTo, 5);
            $u = DB::table('t_users')->where('role_code', $role)->where('f_active', 1)->where(fn ($w) => $w->where('fk_id_ent', $ent)->orWhereNull('fk_id_ent'))->orderBy('id')->value('id');
            if ($u) return (int) $u;
        }
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
