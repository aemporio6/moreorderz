<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-G_CORE-108 data-quality score per record of every master form (registry is_master = Y):
 * filled fields ÷ fields that count – a required field counts double; calc / param / auto / read-only / secret fields and tick boxes do not count.
 * Kept in sys_dq_scores (form, record, score %, missing field names) by the nightly job and on demand for the record that is opened.
 * A record below Settings › Choices DQ_TASK_BELOW_PCT raises task DQ_ITEM_NOTE (key score : form : record); it closes when the score reaches the limit.
 */
class DqScore
{
    public const TASK_CAP = 200;      /* new tasks per night, so a first run on old data does not flood the task list */

    /** Pure: [score, counted weight, filled weight, missing [field => label]] of one row under a field list. */
    public static function calc(array $fields, array $row): array
    {
        $total = 0; $filled = 0; $missing = [];
        foreach ($fields as $f) {
            $c = $f['column_name'] ?? null; if (! $c || ! array_key_exists($c, $row)) continue;
            if (in_array((string) ($f['readonly'] ?? ''), ['auto', 'param', 'calc', 'Y', '1'], true) || in_array((string) ($f['secret'] ?? ''), ['Y', '1'], true) || ($f['type'] ?? '') === 'check') continue;
            $w = in_array((string) ($f['required'] ?? ''), ['Y', '1'], true) ? 2 : 1; $v = $row[$c]; $total += $w;
            $empty = $v === null || trim((string) $v) === '' || (in_array($f['type'] ?? '', ['select', 'link', 'multiselect'], true) && (string) $v === '0') || (in_array($f['type'] ?? '', ['date', 'month_year'], true) && str_starts_with((string) $v, '0000'));
            $empty ? $missing[(string) ($f['field_name'] ?? $c)] = (string) ($f['label'] ?? $c) : $filled += $w;
        }
        return ['score' => $total ? (int) round($filled / $total * 100) : 100, 'counted' => $total, 'filled' => $filled, 'missing' => $missing];
    }

    public static function masterForms(): array
    {
        return DB::table('sys_registry')->where('is_master', 'Y')->whereNotNull('table_name')->where('table_name', '<>', '')->groupBy('form_key')->selectRaw('form_key, MIN(table_name) as t')->pluck('t', 'form_key')->all();
    }

    /** Score one record now and keep it; null when the form is not a master form. */
    public static function put(string $form, int $id, ?array $row = null): ?array
    {
        static $master = null; $master ??= self::masterForms(); if (! isset($master[$form])) return null; $t = $master[$form];
        $row ??= (array) DB::table($t)->where(Registry::pk($t), $id)->first(); if (! $row) return null;
        $r = self::calc(Registry::fields($form), $row); $ent = (int) ($row['fk_id_ent'] ?? 0);
        DB::table('sys_dq_scores')->updateOrInsert(['form_key' => $form, 'record_id' => $id], ['fk_id_ent' => $ent, 'score_pct' => $r['score'], 'missing_fields' => json_encode($r['missing']), 'computed_at' => now()]);
        return $r + ['ent' => $ent];
    }

    /** What the record page shows: "82 % complete – 3 missing" with the list. Reads only. */
    public static function chip(string $form, int $id): ?array
    {
        $master = self::masterForms(); if (! isset($master[$form])) return null; $t = $master[$form]; $row = (array) DB::table($t)->where(Registry::pk($t), $id)->first(); if (! $row) return null;
        $r = self::calc(Registry::fields($form), $row); $n = count($r['missing']);
        return ['score' => $r['score'], 'text' => $r['score'] . ' % complete' . ($n ? " – $n missing" : ''), 'missing' => array_map(fn ($k, $l) => ['field' => $k, 'label' => $l], array_keys($r['missing']), $r['missing'])];
    }

    /** Nightly: every record of every master form (or of one form); tasks for the low ones, closed when they rise. */
    public static function run(?string $onlyForm = null, int $perForm = 5000): array
    {
        $n = 0; $opened = 0; $closed = 0; $master = self::masterForms(); $titles = [];
        foreach ($master as $form => $t) {
            if ($onlyForm !== null && $form !== $onlyForm) continue; if (! \Illuminate\Support\Facades\Schema::hasTable($t)) continue; $pk = Registry::pk($t); $lc = Registry::labelColumn($t);
            foreach (DB::table($t)->orderBy($pk)->limit($perForm)->get() as $row) {
                $row = (array) $row; $id = (int) $row[$pk]; if (array_key_exists('f_active', $row) && ! (int) $row['f_active']) { DB::table('sys_dq_scores')->where('form_key', $form)->where('record_id', $id)->delete(); if (TaskEngine::close('DQ_ITEM_NOTE', ['score', $form, $id], 'Record is inactive')) $closed++; continue; }
                $r = self::put($form, $id, $row); if (! $r) continue; $n++;
                $ent = $r['ent'] ?: 1; $limit = (int) Choices::get('DQ_TASK_BELOW_PCT', $ent);
                if ($limit > 0 && $r['score'] < $limit) {
                    if (! TaskEngine::isOpen('DQ_ITEM_NOTE', ['score', $form, $id]) && $opened < self::TASK_CAP) {
                        $titles[$form] ??= (string) (Registry::fields($form)[0]['form_title'] ?? $form);
                        TaskEngine::open('DQ_ITEM_NOTE', ['score', $form, $id], "{$titles[$form]} \"" . mb_substr((string) ($lc ? ($row[$lc] ?? "#$id") : "#$id"), 0, 80) . "\" is {$r['score']} % complete – fill: " . mb_substr(implode(', ', $r['missing']), 0, 150), ['ent' => $ent, 'module' => $t, 'record_id' => $id, 'fix_url' => "#/rec/$form/$id"]); $opened++;
                    }
                } elseif (TaskEngine::close('DQ_ITEM_NOTE', ['score', $form, $id], 'The record is complete enough now')) $closed++;
            }
        }
        return ['scored' => $n, 'opened' => $opened, 'closed' => $closed];
    }
}
