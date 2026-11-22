<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * RULES_G_CORE R-G_CORE-69 bulk "change role" on the users list (needs the right to manage rights): tick users → pick the role →
 * preview "System will change n selected row(s)" old → new with skip reasons → one bulk_jobs row (list_key user_role) with Undo.
 * Never: an Owner login, your own login, or the Owner role handed out in bulk. A made role keeps its built-in role in role_code (as on the user form).
 */
class UserBulk
{
    private static function label(object $u): string { return trim((string) ($u->display_name ?: $u->login_id)); }
    private static function roleOf(object $u): string { return (string) ($u->custom_role ?: $u->role_code); }

    public static function preview(object $by, array $ids, string $role): array
    {
        Perm::need($by, 'admin.permissions');
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (! $ids) throw new \InvalidArgumentException('Please select at least one checkbox');
        $roles = Perm::roles();
        if (! isset($roles[$role]) || ! $roles[$role]['active']) throw new \InvalidArgumentException('Choose a role from the list.');
        if ($role === 'OWNER') throw new \InvalidArgumentException('Refused: the Owner role is never given in bulk – open the user to do it.');
        $rows = DB::table('t_users')->whereIn('id', $ids)->get()->keyBy('id'); $out = [];
        foreach ($ids as $id) {
            $u = $rows[$id] ?? null;
            if (! $u) { $out[] = ['id' => $id, 'label' => "#$id", 'skip' => 'Not found.']; continue; }
            $old = self::roleOf($u);
            $skip = (int) $u->id === (int) $by->id ? 'Your own login – change it on the user form.' : ($u->role_code === 'OWNER' ? 'An Owner login is never changed in bulk.' : ($old === $role ? 'Already has this role.' : null));
            $out[] = ['id' => $id, 'label' => self::label($u), 'old' => $old, 'new' => $role, 'old_label' => $roles[$old]['label'] ?? $old, 'new_label' => $roles[$role]['label'], 'skip' => $skip];
        }
        $n = count(array_filter($out, fn ($x) => empty($x['skip'])));
        return ['title' => 'Change role of selected row(s)', 'field' => 'Role', 'action' => 'SET', 'rows' => $out, 'will_change' => $n, 'skipped' => count($out) - $n,
            'message' => "System will change $n selected row(s)" . (count($out) - $n ? ', ' . (count($out) - $n) . ' skipped' : '') . '.'];
    }

    private static function put(int $userId, string $role): void
    {
        $made = Perm::roles()[$role] ?? null; if (! $made) return;
        DB::table('t_users')->where('id', $userId)->update(['role_code' => $made['like'], 'custom_role' => $made['system'] ? null : $made['code']]);
    }

    public static function apply(object $by, array $ids, string $role): array
    {
        $p = self::preview($by, $ids, $role);
        return DB::transaction(function () use ($p, $by, $role) {
            $done = [];
            foreach ($p['rows'] as $r) {
                if (! empty($r['skip'])) continue;
                self::put((int) $r['id'], $role); Audit::log('t_users', (int) $r['id'], 'UPDATE', ['role' => [$r['old'], $role]], 'bulk role change');
                $done[] = ['id' => (int) $r['id'], 'old' => $r['old']];
            }
            Perm::flush();
            if (! $done) return ['job' => null, 'changed' => 0, 'skipped' => $p['skipped'], 'message' => 'Nothing was changed.'];
            $id = (int) DB::table('bulk_jobs')->insertGetId(['job_no' => 'BJ-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5)), 'list_key' => 'user_role', 'fk_id_account' => $by->id,
                'change_json' => json_encode(['action' => 'SET', 'field' => 'role', 'value' => $role, 'label' => 'Role', 'rows' => $done]), 'row_count' => count($done), 'status' => 'APPLIED', 'created_at' => now(), 'applied_at' => now()]);
            return ['job' => $id, 'changed' => count($done), 'skipped' => $p['skipped'], 'message' => 'Updated ' . count($done) . ' row' . (count($done) === 1 ? '' : 's') . ($p['skipped'] ? " · {$p['skipped']} skipped" : '') . '.'];
        });
    }

    /** Put the old roles back; a user whose role was changed again since is left alone. */
    public static function undo(int $jobId, object $by): array
    {
        Perm::need($by, 'admin.permissions');
        $j = DB::table('bulk_jobs')->where('id', $jobId)->where('list_key', 'user_role')->first() ?? abort(404, 'Job not found.');
        if ($j->status !== 'APPLIED') throw new \InvalidArgumentException($j->status === 'UNDONE' ? 'This was already undone.' : 'This job cannot be undone.');
        $c = json_decode((string) $j->change_json, true); $back = 0; $kept = 0;
        DB::transaction(function () use ($c, $j, &$back, &$kept) {
            foreach ($c['rows'] as $r) {
                $u = DB::table('t_users')->where('id', $r['id'])->first();
                if (! $u || self::roleOf($u) !== $c['value']) { $kept++; continue; }
                self::put((int) $r['id'], (string) $r['old']); Audit::log('t_users', (int) $r['id'], 'UPDATE', ['role' => [$c['value'], $r['old']]], 'Undo of ' . $j->job_no); $back++;
            }
            DB::table('bulk_jobs')->where('id', $j->id)->update(['status' => 'UNDONE', 'undone_at' => now()]);
        });
        Perm::flush();
        return ['back' => $back, 'kept' => $kept, 'message' => "Undone: $back row" . ($back === 1 ? '' : 's') . ' put back' . ($kept ? " · $kept left alone (changed again since)" : '')];
    }
}
