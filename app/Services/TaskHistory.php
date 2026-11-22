<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * RULES_G_CORE R-G_CORE-30 task history with readable labels: one insert-only row per changed field (old → new, reason);
 * user ids → names (0 / empty = System), category → name, 0/1 → No / Yes, status codes → labels (new), special entries in words;
 * money values "(hidden)" for anyone who is not a Partner (R-G_CORE-62). The task page shows the newest 12 and "Show all n changes".
 */
class TaskHistory
{
    public const SHOW = 12;
    private const USERS = ['owner_user_id', 'assigned_by_user_id', 'approved_by_user_id', 'cancelled_by_user_id', 'evidence_waived_by', 'absence_cover', 'helper_added', 'helper_removed', 'watcher_added'];
    private const YES_NO = ['evidence_required', 'is_confidential'];
    private const MONEY = ['amount_gbp', 'amount'];
    private const NAMES = [
        'status' => 'Status', 'owner_user_id' => 'Owner', 'assigned_by_user_id' => 'Assigned by', 'due_at' => 'Due date', 'due_changes' => 'Due date moves', 'priority' => 'Priority',
        'category_id' => 'Category', 'evidence_required' => 'Proof required', 'is_confidential' => 'Confidential', 'amount_gbp' => 'Amount £', 'acknowledged_at' => 'Acknowledged',
        'title' => 'Title', 'description' => 'Detail', 'absence_cover' => 'Given to the cover', 'helper_added' => 'Helper added', 'helper_removed' => 'Helper removed', 'watcher_added' => 'Watcher added',
        'checklist' => 'Checklist', 'checklist_added' => 'Checklist item added', 'comment' => 'Comment', 'evidence' => 'Proof added', 'evidence_hidden' => 'Proof hidden', 'unblocked' => 'Unblocked',
        'auto-approved' => 'Approved by itself', 'imported' => 'Imported', 'reminder_sent' => 'Reminder sent', 'score' => 'Score', 'subtasks' => 'Subtasks', 'depends_on_task_id' => 'Waits for',
        'REOPENED' => 'Opened again', 'due_request' => 'New due date asked',
    ];

    /** Readable rows, newest first. $all = false → the newest SHOW rows. */
    public static function rows(int $taskId, ?object $viewer, bool $all = true): array
    {
        $partner = $viewer && in_array(strtoupper((string) ($viewer->role_code ?? '')), ['OWNER', 'PARTNER'], true);
        $q = DB::table('task_history')->where('task_id', $taskId)->orderByDesc('id');
        $total = (clone $q)->count();
        $rows = ($all ? $q : $q->limit(self::SHOW))->get(['id', 'at', 'user_id', 'field', 'old_value', 'new_value', 'reason']);
        $users = DB::table('t_users')->pluck('display_name', 'id'); $cats = DB::table('task_categories')->pluck('name', 'id');
        $val = function (?string $field, $v) use ($users, $cats, $partner) {
            if ($v === null || $v === '') return '';
            if (in_array($field, self::MONEY, true)) return $partner ? (string) $v : '(hidden)';
            if (in_array($field, self::USERS, true) && ctype_digit((string) $v)) return (int) $v === 0 ? 'System' : (string) ($users[(int) $v] ?? '#' . $v);
            if ($field === 'category_id' && ctype_digit((string) $v)) return (string) ($cats[(int) $v] ?? '#' . $v);
            if (in_array($field, self::YES_NO, true) && in_array((string) $v, ['0', '1'], true)) return $v === '1' || $v === 1 ? 'Yes' : 'No';
            if ($field === 'status') return TaskFlow::LABELS[(string) $v] ?? ucfirst(strtolower(str_replace('_', ' ', (string) $v)));
            if ($field === 'depends_on_task_id' && ctype_digit((string) $v)) return TaskFlow::ref((int) $v);
            return (string) $v;
        };
        return ['total' => $total, 'shown' => $rows->count(), 'rows' => $rows->map(fn ($h) => ['at' => (string) $h->at, 'by' => $h->user_id ? (string) ($users[(int) $h->user_id] ?? '#' . $h->user_id) : 'System',
            'field' => $h->field, 'label' => self::NAMES[$h->field] ?? ucfirst(str_replace('_', ' ', (string) $h->field)), 'old' => $val($h->field, $h->old_value), 'new' => $val($h->field, $h->new_value),
            'reason' => in_array($h->field, self::MONEY, true) && ! $partner ? null : $h->reason])->all()];
    }
}
