<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-G_CORE-21 health chip – computed, never stored, in this order: CLOSED · WAITING · STUCK · NO_DUE · LATE ("Late n days") · BLOCKED · DUE_TODAY ·
 * DUE_SOON (≤ today + 2; "Due tomorrow" when today + 1) · ON_TRACK. "Today" is the company's today (COMPANY_TIMEZONE, default Europe/London).
 * The same chip on every task list, the record Tasks tab and the task panel.
 */
class TaskHealth
{
    /** code => [label, text colour, background, border] (old colours) */
    public const CHIPS = [
        'CLOSED' => ['Closed', '#3F4448', '#ECEEE9', '#ECEEE9'], 'WAITING' => ['Waiting approval', '#1E3A8A', '#E0ECF9', '#E0ECF9'],
        'STUCK' => ['Stuck', '#FFFFFF', '#9A2E1A', '#9A2E1A'], 'NO_DUE' => ['No due date', '#6B4E00', '#F3E8C9', '#F3E8C9'],
        'LATE' => ['Late', '#7C2D12', '#FDEBDD', '#FDEBDD'], 'BLOCKED' => ['Blocked', '#6B7076', '#FFFFFF', 'dashed #9AA0A6'],
        'DUE_TODAY' => ['Due today', '#F4F5F2', '#16181D', '#16181D'], 'DUE_SOON' => ['Due soon', '#6B4E00', '#FFF4D6', '#FFF4D6'],
        'ON_TRACK' => ['On track', '#0F5E63', '#DDEFE7', '#DDEFE7'],
    ];

    public static function today(?int $ent = null): string
    {
        $tz = (string) (Settings::get('COMPANY_TIMEZONE', $ent ?? (int) Context::ent(), 'Europe/London') ?: 'Europe/London');
        try { return (new \DateTime('now', new \DateTimeZone($tz)))->format('Y-m-d'); } catch (\Throwable) { return date('Y-m-d'); }
    }

    /** $t needs status, due_at, depends_on_task_id (optional). */
    public static function of(object|array $t, ?string $today = null): array
    {
        $t = (object) $t; $today ??= self::today(); $due = $t->due_at ? substr((string) $t->due_at, 0, 10) : null;
        $blocked = fn () => ($t->status ?? '') === 'TODO' && ! empty($t->depends_on_task_id)
            && ! in_array((string) DB::table('tasks')->where('id', $t->depends_on_task_id)->value('status'), ['APPROVED', 'CANCELLED', ''], true);
        $days = $due ? (int) round((strtotime($today) - strtotime($due)) / 86400) : 0;
        $code = match (true) {
            in_array($t->status, ['APPROVED', 'CANCELLED'], true) => 'CLOSED',
            $t->status === 'DONE' => 'WAITING',
            $t->status === 'STUCK' => 'STUCK',
            ! $due => 'NO_DUE',
            $due < $today => 'LATE',
            $blocked() => 'BLOCKED',
            $due === $today => 'DUE_TODAY',
            $days >= -2 => 'DUE_SOON',
            default => 'ON_TRACK',
        };
        [$label, $fg, $bg, $border] = self::CHIPS[$code];
        if ($code === 'LATE') $label = "Late $days day" . ($days === 1 ? '' : 's');
        if ($code === 'DUE_SOON' && $days === -1) $label = 'Due tomorrow';
        return ['code' => $code, 'label' => $label, 'fg' => $fg, 'bg' => $bg, 'border' => $border];
    }
}
