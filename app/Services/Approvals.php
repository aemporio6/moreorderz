<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Maker-checker. A request waits in sys_approvals; a second person decides; the registered handler of the action then does (or drops) the work.
 * Limits are settings: APPROVAL_LIMIT_<ACTION> in base currency (per company). The Owner's own entries need no approval.
 */
class Approvals
{
    private static array $handlers = [];
    public const APPROVERS = ['OWNER', 'MANAGER'];

    public static function handler(string $action, callable $fn): void { self::$handlers[$action] = $fn; }

    public static function limit(int $ent, string $action, float $default): float
    {
        return (float) Settings::get('APPROVAL_LIMIT_' . $action, $ent, $default);
    }

    /** Does this amount need a second person? */
    public static function needed(int $ent, string $action, float $amountBase, float $defaultLimit): bool
    {
        return Context::role() !== 'OWNER' && $amountBase > self::limit($ent, $action, $defaultLimit) + 0.0001;
    }

    public static function request(int $ent, string $action, string $table, int $id, float $amountBase, array $change = [], string $title = ''): int
    {
        $aid = DB::table('sys_approvals')->insertGetId(['fk_id_ent' => $ent, 'action_code' => $action, 'entity' => $table, 'entity_id' => $id, 'entity_table' => $table, 'amount_gbp' => $amountBase,
            'requested_by' => (int) Context::userId(), 'requested_at' => now(), 'status' => 'PENDING', 'change_json' => json_encode($change), 'risk' => 'MEDIUM']);
        $task = TaskEngine::open('FIN_APPROVE', [$aid], $title ?: "Approval needed: $action " . number_format($amountBase, 2), ['ent' => $ent, 'module' => 'sys_approvals', 'record_id' => $aid, 'amount' => $amountBase, 'fix_url' => '#/approvals']);
        if ($task) DB::table('sys_approvals')->where('id', $aid)->update(['fk_id_task' => $task]);
        EventBus::fire('APPROVAL_REQUESTED', 'sys_approvals', $aid, ['action' => $action, 'amount' => $amountBase], $ent);
        return $aid;
    }

    public static function decide(int $approvalId, bool $approve, ?string $reason = null): object
    {
        return DB::transaction(function () use ($approvalId, $approve, $reason) {
            $a = DB::table('sys_approvals')->where('id', $approvalId)->where('fk_id_ent', Context::ent())->lockForUpdate()->first() ?? throw new \InvalidArgumentException('Approval not found.');
            if ($a->status !== 'PENDING') throw new \InvalidArgumentException('This request was already decided.');
            if (! in_array(Context::role(), self::APPROVERS, true)) throw new \InvalidArgumentException('Only the Owner or a Manager can decide this.');
            if ((int) $a->requested_by === (int) Context::userId()) throw new \InvalidArgumentException('You asked for this yourself – a second person must decide it.');
            if (! $approve && ! trim((string) $reason)) throw new \InvalidArgumentException('Give the reason for saying no.');
            DB::table('sys_approvals')->where('id', $a->id)->update(['status' => $approve ? 'APPROVED' : 'REJECTED', 'decided_by' => Context::userId(), 'decided_at' => now(), 'reason' => $reason]);
            if (isset(self::$handlers[$a->action_code])) (self::$handlers[$a->action_code])($a, $approve, $reason);
            TaskEngine::close('FIN_APPROVE', [$a->id], $approve ? 'Approved' : 'Rejected');
            Audit::log('sys_approvals', $a->id, $approve ? 'APPROVE' : 'REJECT', ['action' => $a->action_code, 'amount' => $a->amount_gbp], $reason, (int) $a->fk_id_ent);
            EventBus::fire($approve ? 'APPROVAL_APPROVED' : 'APPROVAL_REJECTED', 'sys_approvals', $a->id, ['action' => $a->action_code], (int) $a->fk_id_ent);
            return DB::table('sys_approvals')->where('id', $a->id)->first();
        });
    }

    public static function pending(int $ent): array
    {
        return DB::table('sys_approvals as a')->leftJoin('t_users as u', 'u.id', '=', 'a.requested_by')->leftJoin('t_users as d', 'd.id', '=', 'a.decided_by')->where('a.fk_id_ent', $ent)
            ->orderByRaw("a.status = 'PENDING' DESC")->orderByDesc('a.id')->limit(200)
            ->get(['a.id', 'a.action_code', 'a.entity_table', 'a.entity_id', 'a.amount_gbp', 'a.status', 'a.requested_at', 'a.requested_by', 'a.decided_at', 'a.reason', 'a.change_json', 'u.display_name as by', 'd.display_name as decided_by_name'])
            ->map(function ($a) { $a->change = json_decode($a->change_json ?: '[]', true); unset($a->change_json); return $a; })->all();
    }
}
