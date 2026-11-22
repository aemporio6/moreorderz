<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Bulk actions of every list: change one field for the ticked rows, switch rows off ("delete") or on again.
 * Always: preview (old → new per row, and why a row is skipped) → apply → one bulk_jobs row → Undo.
 * Each row is saved through the same record save as the form, so validation, history, search and events are the same.
 * Nothing is ever removed from the database: "delete" switches the record off, and only when nothing uses it.
 */
class BulkJobs
{
    public const MAX = 500;

    /** What a person can do in bulk on a list: the fields that may be set, and whether rows can be switched off / on. */
    public static function actions(string $formKey, ?object $u): array
    {
        $table = Registry::table($formKey); if (! $table) return ['fields' => [], 'can_delete' => false, 'can_edit' => false];
        $edit = $u && Perm::has($u, "form:$formKey:EDIT"); $del = $u && Perm::has($u, "form:$formKey:DELETE");
        $fields = [];
        if ($edit) foreach (Registry::fields($formKey) as $f) {
            if (! self::settable($f, $table, $u)) continue;
            $fields[] = ['name' => $f['field_name'], 'col' => $f['column_name'], 'label' => $f['label'], 'type' => $f['type'], 'has_options' => in_array($f['type'], ['select', 'multiselect'], true)];
        }
        return ['fields' => $fields, 'can_edit' => $edit, 'can_delete' => $del && self::offSwitch($table) !== null, 'max' => self::MAX];
    }

    private static function settable(array $f, string $table, ?object $u): bool
    {
        if (! $f['column_name'] || $f['readonly'] || $f['secret'] || ! Registry::hasColumn($table, $f['column_name']) || Registry::baseTable($f['table_name']) !== $table) return false;
        if (str_starts_with((string) $f['default_rule'], 'numbering:') || in_array($f['type'], ['file'], true) || in_array($f['column_name'], ['fk_id_ent', 'created_by', 'updated_by', 'created_at', 'updated_at'], true)) return false;
        if (Vault::maskRow($table, [$f['column_name'] => 'x'])[$f['column_name']] !== 'x') return false;
        if (! Registry::visible($f, $u->role_code ?? null) || Perm::fieldMode($u, (string) $f['form_key'], (string) $f['field_name']) === 'READONLY') return false;
        return true;
    }

    /** How a table marks a record as removed: [column, off value, on value], or null when it cannot be switched off. */
    public static function offSwitch(string $table): ?array
    {
        if (Registry::hasColumn($table, 'f_deleted')) return ['f_deleted', 1, 0];
        if (Registry::hasColumn($table, 'f_active')) return ['f_active', 0, 1];
        return null;
    }

    /** Where a record is used: [[form title, count, url], …] – every other table whose dropdown points at this one. */
    public static function usedBy(string $table, int $id): array
    {
        $out = [];
        foreach (DB::table('sys_registry')->where('source', 'like', "table:$table%")->whereNotNull('column_name')->get(['form_key', 'form_title', 'table_name', 'column_name', 'source']) as $r) {
            if (! in_array(explode('?', substr((string) $r->source, 6))[0], [$table], true)) continue;
            $ct = Registry::baseTable($r->table_name);
            if (! $ct || $ct === $table || isset($out[$ct]) || ! Registry::hasColumn($ct, $r->column_name)) continue;
            $n = DB::table($ct)->where($r->column_name, $id)->count();
            if ($n) $out[$ct] = ['title' => $r->form_title, 'count' => $n, 'url' => "#/list/{$r->form_key}?f[{$r->column_name}]=$id"];
        }
        return array_values($out);
    }

    private static function ids(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (! $ids) throw new \InvalidArgumentException('Please select at least one checkbox.');
        if (count($ids) > self::MAX) throw new \InvalidArgumentException('At most ' . self::MAX . ' rows at a time – filter the list and do it in parts.');
        return $ids;
    }

    private static function rows(string $formKey, string $table, array $ids)
    {
        $pk = Registry::pk($table); $q = DB::table($table)->whereIn($pk, $ids); Perm::scopeQuery($q, $table, \Illuminate\Support\Facades\Auth::user());
        if (Registry::hasColumn($table, 'fk_id_ent') && Context::ent()) $q->where(fn ($w) => $w->where('fk_id_ent', Context::ent())->orWhere('fk_id_ent', 0)->orWhereNull('fk_id_ent'));
        return $q->get()->keyBy($pk);
    }

    private static function labelOf(string $table, object $row, int $id): string
    {
        if ($table === 't_account') return trim(($row->first_name ?? '') . ' ' . ($row->sur_name ?? '')) ?: "#$id";
        $lc = Registry::labelColumn($table); return $lc && ($row->$lc ?? '') !== '' ? (string) $row->$lc : "#$id";
    }

    /**
     * Preview of a bulk action. $action: SET (field + value), DELETE, RESTORE.
     * Returns rows [{id, label, old, new, old_label, new_label, skip}] – a row with "skip" will not be changed, with the reason.
     */
    public static function preview(string $formKey, ?object $u, string $action, array $ids, ?string $field = null, mixed $value = null): array
    {
        $table = Registry::table($formKey) ?? abort(404); $ids = self::ids($ids); $rows = self::rows($formKey, $table, $ids); $out = [];
        if ($action === 'SET') {
            Perm::need($u, "form:$formKey:EDIT");
            $f = FormAssist::field($formKey, (string) $field);
            if (! $f || ! self::settable($f, $table, $u)) throw new \InvalidArgumentException('This field cannot be changed in bulk.');
            $col = $f['column_name']; $labels = [];
            if (in_array($f['type'], ['select', 'multiselect'], true)) { foreach (Registry::options($f, Context::ent(), null, 2000) as [$v, $l]) $labels[(string) $v] = $l;
                if ($value !== null && $value !== '' && $labels && $f['type'] === 'select' && ! isset($labels[(string) $value])) throw new \InvalidArgumentException('Choose a value from the list of "' . $f['label'] . '".'); }
            if (($value === null || $value === '') && $f['required']) throw new \InvalidArgumentException('"' . $f['label'] . '" is compulsory – it cannot be emptied.');
            if (in_array($f['type'], ['number', 'money'], true) && $value !== null && $value !== '' && ! is_numeric($value)) throw new \InvalidArgumentException('"' . $f['label'] . '" needs a number.');
            $show = fn ($v) => $v === null || $v === '' ? '' : ($f['type'] === 'check' ? ((int) $v ? 'Yes' : 'No') : ($labels[(string) $v] ?? (string) $v));
            foreach ($ids as $id) {
                $r = $rows[$id] ?? null; if (! $r) { $out[] = ['id' => $id, 'label' => "#$id", 'skip' => 'Not found, or not one of your records.']; continue; }
                $old = $r->$col; $same = (string) ($old ?? '') === (string) ($value ?? '') || (is_numeric($old) && is_numeric($value) && (float) $old === (float) $value);
                $def = $col === 'f_active' && ! (int) $value ? ListDefaults::protect($table, (int) $id, self::labelOf($table, $r, $id)) : null;      // R-D-68: the default is never switched off
                $out[] = ['id' => $id, 'label' => self::labelOf($table, $r, $id), 'old' => $old, 'new' => $value, 'old_label' => $show($old), 'new_label' => $show($value), 'skip' => $same ? 'Already has this value.' : ($def ? str_replace('cannot be deleted', 'cannot be switched off', $def) : null)];
            }
            $title = 'Update selected row(s)'; $head = $f['label'];
        } else {
            if (! in_array($action, ['DELETE', 'RESTORE'], true)) throw new \InvalidArgumentException('Unknown action.');
            Perm::need($u, "form:$formKey:DELETE");
            $sw = self::offSwitch($table) ?? throw new \InvalidArgumentException('Records of this list cannot be switched off.');
            [$col, $off, $on] = $sw; $to = $action === 'DELETE' ? $off : $on;
            foreach ($ids as $id) {
                $r = $rows[$id] ?? null; if (! $r) { $out[] = ['id' => $id, 'label' => "#$id", 'skip' => 'Not found, or not one of your records.']; continue; }
                $skip = (int) $r->$col === $to ? ($action === 'DELETE' ? 'Already deleted.' : 'Is not deleted.') : null;
                if (! $skip && $action === 'DELETE') $skip = ListDefaults::protect($table, (int) $id, self::labelOf($table, $r, $id));      // R-D-68: the default item stays
                if (! $skip && $action === 'DELETE' && ($used = self::usedBy($table, $id))) $skip = ListDefaults::inUse($table, $used);      // with Reassign (R-D-68)
                $out[] = ['id' => $id, 'label' => self::labelOf($table, $r, $id), 'old' => (int) $r->$col, 'new' => $to, 'old_label' => $action === 'DELETE' ? 'In use' : 'Deleted', 'new_label' => $action === 'DELETE' ? 'Deleted' : 'In use', 'skip' => $skip, 'used' => $used ?? []];
                unset($used);
            }
            $title = $action === 'DELETE' ? 'Delete selected row(s)' : 'Restore selected row(s)'; $head = 'State';
        }
        $n = count(array_filter($out, fn ($x) => empty($x['skip'])));
        return ['title' => $title, 'field' => $head, 'action' => $action, 'rows' => $out, 'will_change' => $n, 'skipped' => count($out) - $n,
            'message' => "System will " . ($action === 'SET' ? 'change' : strtolower($action)) . " $n selected row(s)" . (count($out) - $n ? ', ' . (count($out) - $n) . ' skipped' : '') . '.'];
    }

    private static function lower(string $s): string { return preg_match('/^[A-Z]{2,}/', $s) ? $s : lcfirst($s); }

    /** Do it (the answer is sent after the work is done). One bulk_jobs row keeps the old value of every changed row for Undo. */
    public static function apply(string $formKey, ?object $u, string $action, array $ids, ?string $field = null, mixed $value = null): array
    {
        $p = self::preview($formKey, $u, $action, $ids, $field, $value); $table = Registry::table($formKey); $done = []; $failed = [];
        $col = $action === 'SET' ? FormAssist::field($formKey, (string) $field)['column_name'] : self::offSwitch($table)[0];
        return DB::transaction(function () use ($p, $formKey, $table, $u, $action, $field, $value, $col, &$done, &$failed) {
            foreach ($p['rows'] as $r) {
                if (! empty($r['skip'])) continue;
                try {
                    if ($action === 'SET') RecordService::save($formKey, [$field => $value], (int) $r['id']);
                    else { DB::table($table)->where(Registry::pk($table), $r['id'])->update([$col => $r['new']]); Audit::log($table, $r['id'], $action === 'DELETE' ? 'DELETE' : 'RESTORE', [$col => [$r['old'], $r['new']]]); SearchIndex::put($formKey, $table, (int) $r['id']); }
                    $done[] = ['id' => (int) $r['id'], 'old' => $r['old']];
                } catch (\Illuminate\Validation\ValidationException $e) { $failed[] = ['id' => $r['id'], 'label' => $r['label'], 'why' => collect($e->errors())->flatten()->first()]; }
            }
            if (! $done) return ['job' => null, 'changed' => 0, 'failed' => $failed, 'skipped' => $p['skipped'], 'message' => 'Nothing was changed.' . ($failed ? ' ' . $failed[0]['why'] : '')];
            $id = (int) DB::table('bulk_jobs')->insertGetId(['job_no' => 'BJ-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5)), 'list_key' => $formKey, 'fk_id_account' => $u->id,
                'change_json' => json_encode(['action' => $action, 'field' => $field, 'col' => $col, 'value' => $action === 'SET' ? $value : $p['rows'][0]['new'], 'label' => $p['field'], 'rows' => $done, 'ent' => Context::ent()]), 'row_count' => count($done), 'status' => 'APPLIED', 'created_at' => now(), 'applied_at' => now()]);
            $word = $action === 'SET' ? 'Updated' : ($action === 'DELETE' ? 'Deleted' : 'Restored');
            return ['job' => $id, 'changed' => count($done), 'failed' => $failed, 'skipped' => $p['skipped'], 'message' => "$word " . count($done) . ' row' . (count($done) === 1 ? '' : 's') . ($p['skipped'] || $failed ? ' · ' . ($p['skipped'] + count($failed)) . ' skipped' : '')];
        });
    }

    /** Put back what a job changed. A row that someone changed again afterwards is left alone and counted. */
    public static function undo(int $jobId, ?object $u): array
    {
        $j = DB::table('bulk_jobs')->where('id', $jobId)->first() ?? abort(404, 'Job not found.');
        if ($j->list_key === 'task_bulk') return TaskBulk::undo($jobId, $u);
        if ($j->list_key === 'user_role') return UserBulk::undo($jobId, $u);      // R-G_CORE-69 bulk role change
        if ($j->list_key === 'task_import') return TaskImport::undo($jobId, $u);      // R-G_CORE-34 batch undo      // R-G_CORE-33: task jobs put back by their own rules
        if ((int) $j->fk_id_account !== (int) $u->id && ! Perm::has($u, 'admin.owner') && ($u->role_code ?? '') !== 'MANAGER') abort(403, 'Only the person who did it, a Manager or the Owner can undo it.');
        if ($j->status !== 'APPLIED') throw new \InvalidArgumentException($j->status === 'UNDONE' ? 'This was already undone.' : 'This job cannot be undone.');
        $c = json_decode((string) $j->change_json, true); $formKey = $j->list_key; $table = Registry::table($formKey); $pk = Registry::pk($table); $back = 0; $kept = 0;
        DB::transaction(function () use ($c, $formKey, $table, $pk, $j, &$back, &$kept) {
            foreach ($c['rows'] as $r) {
                $cur = DB::table($table)->where($pk, $r['id'])->value($c['col']);
                if ((string) ($cur ?? '') !== (string) ($c['value'] ?? '') && ! (is_numeric($cur) && is_numeric($c['value']) && (float) $cur === (float) $c['value'])) { $kept++; continue; }   // changed again since – not ours to undo
                if ($c['action'] === 'SET') RecordService::save($formKey, [$c['field'] => $r['old'] ?? ''], (int) $r['id']);
                else { DB::table($table)->where($pk, $r['id'])->update([$c['col'] => $r['old']]); Audit::log($table, $r['id'], 'RESTORE', [$c['col'] => [$c['value'], $r['old']]], 'Undo of ' . $j->job_no); SearchIndex::put($formKey, $table, (int) $r['id']); }
                $back++;
            }
            DB::table('bulk_jobs')->where('id', $j->id)->update(['status' => 'UNDONE', 'undone_at' => now()]);
        });
        return ['back' => $back, 'kept' => $kept, 'message' => "Undone: $back row" . ($back === 1 ? '' : 's') . ' put back' . ($kept ? " · $kept left alone (changed again since)" : '')];
    }

    /** The jobs a person may see (his own; Owner and Manager all), newest first. */
    public static function history(?object $u, int $limit = 100): array
    {
        $all = Perm::has($u, 'admin.owner') || ($u->role_code ?? '') === 'MANAGER';
        return DB::table('bulk_jobs as j')->leftJoin('t_users as p', 'p.id', '=', 'j.fk_id_account')->when(! $all, fn ($q) => $q->where('j.fk_id_account', $u->id))->orderByDesc('j.id')->limit($limit)->get(['j.*', 'p.display_name'])
            ->map(function ($j) { $c = json_decode((string) $j->change_json, true) ?: []; if (($c['ent'] ?? null) && (int) $c['ent'] !== (int) Context::ent()) return null;
                return ['id' => $j->id, 'no' => $j->job_no, 'list' => $j->list_key, 'list_title' => Registry::exists($j->list_key) ? Registry::fields($j->list_key)[0]['form_title'] : $j->list_key, 'by' => $j->display_name, 'action' => $c['action'] ?? '', 'what' => ($c['action'] ?? '') === 'SET' ? ($c['label'] ?? '') . ' → ' . (($c['value'] ?? '') === '' ? '(empty)' : $c['value']) : ucfirst(strtolower($c['action'] ?? '')),
                    'rows' => (int) $j->row_count, 'status' => $j->status, 'at' => (string) $j->applied_at, 'undone_at' => $j->undone_at]; })->filter()->values()->all();
    }
}
