<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Who changed what and when (sys_audit_log). Secret values are never written to the log. */
class Audit
{
    public static function log(string $table, int|string|null $id, string $action, array $changes = [], ?string $reason = null, ?int $ent = null): void
    {
        foreach ($changes as $k => $v) {
            if (preg_match('/pass|secret|token|cvv|pin|api_key/i', (string) $k)) $changes[$k] = '***';
        }
        DB::table('sys_audit_log')->insert([
            'fk_id_ent' => $ent ?? (Context::ent() ?: 0), 'table_name' => $table, 'record_id' => (int) $id, 'action' => $action,
            'changes_json' => $changes ? json_encode($changes, JSON_UNESCAPED_UNICODE) : null, 'reason' => $reason,
            'user_id' => Context::userId() ?? 0, 'ip' => request()?->ip(), 'created_at' => now(),
        ]);
    }
}
