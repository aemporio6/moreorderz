<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Generic list / read / save for every registry form. One code path, so every form gets the same
 * company filter, validation, auto-correct, defaults, history and event.
 */
class RecordService
{
    /** Columns never shown or saved through the generic path. */
    private const BLOCKED = ['pass', 'password_hash', 'remember_token', 'totp_secret'];

    public static function list(string $formKey, array $p = []): array
    {
        $table = Registry::table($formKey) ?? abort(404, "Form $formKey has no table.");
        $pk = Registry::pk($table);
        $fields = self::storedFields($formKey, $table);
        $cols = array_slice(array_values(array_unique(array_map(fn ($f) => $f['column_name'], $fields))), 0, (int) ($p['max_cols'] ?? 12));
        $q = DB::table($table);
        self::scope($q, $table);
        if (! empty($p['q'])) {
            $text = array_filter($fields, fn ($f) => in_array($f['type'], ['text', 'textarea', 'email', 'phone', 'link'], true));
            $q->where(function ($w) use ($text, $p) {
                foreach ($text as $f) $w->orWhere($f['column_name'], 'like', '%' . $p['q'] . '%');
            });
        }
        foreach (($p['filter'] ?? []) as $col => $val) {
            if ($val === '' || $val === null || ! Registry::hasColumn($table, $col)) continue;
            if (is_array($val)) $q->whereIn($col, $val);
            elseif (in_array(self::fieldByCol($fields, $col)['type'], ['text', 'textarea', 'email', 'phone', 'link'], true)) $q->where($col, 'like', "%$val%");
            else $q->where($col, $val);
        }
        $total = (clone $q)->count();
        $sort = Registry::hasColumn($table, (string) ($p['sort'] ?? '')) ? $p['sort'] : $pk;
        $per = max(1, min(200, (int) ($p['per_page'] ?? 25)));
        $page = max(1, (int) ($p['page'] ?? 1));
        $rows = $q->orderBy($sort, ($p['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc')->offset(($page - 1) * $per)->limit($per)->get(array_merge([$pk . ' as id'], $cols));
        $labels = self::labels($fields, $rows);
        return [
            'form_key' => $formKey, 'table' => $table, 'total' => $total, 'page' => $page, 'per_page' => $per,
            'columns' => array_map(fn ($c) => ['name' => $c, 'label' => self::fieldByCol($fields, $c)['label'], 'type' => self::fieldByCol($fields, $c)['type']], $cols),
            'rows' => $rows->map(function ($r) use ($labels) {
                $a = (array) $r;
                foreach ($a as $k => $v) if (isset($labels[$k][(string) $v])) $a[$k . '__label'] = $labels[$k][(string) $v];
                return $a;
            })->all(),
        ];
    }

    public static function get(string $formKey, int $id): array
    {
        $table = Registry::table($formKey) ?? abort(404);
        $q = DB::table($table)->where(Registry::pk($table), $id);
        self::scope($q, $table);
        $row = (array) ($q->first() ?? abort(404, 'Record not found.'));
        foreach (self::BLOCKED as $b) unset($row[$b]);
        foreach (Registry::fields($formKey) as $f) if ($f['secret'] && $f['column_name'] && isset($row[$f['column_name']]) && Context::role() !== 'OWNER') $row[$f['column_name']] = '••••';
        return $row;
    }

    /** Values of a new record: registry defaults + anything passed in the context (e.g. the parent record). */
    public static function blank(string $formKey, array $ctx = []): array
    {
        $out = [];
        $c = ['ent' => Context::ent(), 'user' => Context::userId()] + $ctx;
        foreach (Registry::fields($formKey) as $f) {
            $v = Registry::defaultValue($f, $c);
            if ($v !== null && $f['column_name']) $out[$f['column_name']] = $v;
        }
        return array_merge($out, $ctx['values'] ?? []);
    }

    /** Insert or update. Returns ['id' => .., 'created' => bool]. Throws ValidationException with a message per field. */
    public static function save(string $formKey, array $input, ?int $id = null, array $opt = []): array
    {
        $table = Registry::table($formKey) ?? abort(404);
        $pk = Registry::pk($table);
        $quick = ! empty($opt['quick']);
        $data = []; $errors = [];
        $old = $id ? (array) (DB::table($table)->where($pk, $id)->first() ?? abort(404)) : [];
        foreach (self::storedFields($formKey, $table) as $f) {
            $col = $f['column_name'];
            if (in_array($col, self::BLOCKED, true) || $f['readonly']) continue;
            $given = array_key_exists($f['field_name'], $input) || array_key_exists($col, $input);
            $v = $input[$f['field_name']] ?? $input[$col] ?? null;
            if (! $given && $id) continue;                        // edit: untouched fields stay as they are
            if (! $given && ! $id) $v = Registry::defaultValue($f, ['ent' => Context::ent(), 'user' => Context::userId()]);
            $v = self::clean($f, $v);
            $numbered = str_starts_with((string) $f['default_rule'], 'numbering:');
            $must = $f['required'] && (! $quick || $f['in_quick']) && ! $numbered;
            if (! $must && $f['required_if']) $must = self::cond($f['required_if'], $input + $old);
            if ($must && ($v === null || $v === '')) { $errors[$f['field_name']] = $f['label'] . ' is required.'; continue; }
            if ($v !== null && $v !== '') {
                if ($msg = self::invalid($f, $v)) { $errors[$f['field_name']] = $msg; continue; }
            }
            if ($v === '' ) $v = null;
            $data[$col] = is_array($v) ? implode(',', $v) : $v;
        }
        if ($errors) throw ValidationException::withMessages($errors);

        try {
            return self::write($table, $pk, $id, $data, $old, $formKey, $opt);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages(['_form' => 'A record with the same code or name already exists. Change it and save again.']);
        } catch (\Illuminate\Database\QueryException $e) {
            report($e);
            throw ValidationException::withMessages(['_form' => 'This could not be saved because a value does not fit its field. Check the marked values and try again.']);
        }
    }

    private static function write(string $table, string $pk, ?int $id, array $data, array $old, string $formKey, array $opt): array
    {
        return DB::transaction(function () use ($table, $pk, $id, $data, $old, $formKey, $opt) {
            $ent = Context::ent();
            if ($id) {
                $changes = [];
                foreach ($data as $k => $v) if ((string) ($old[$k] ?? '') !== (string) ($v ?? '')) $changes[$k] = [$old[$k] ?? null, $v];
                if ($changes) {
                    if (Registry::hasColumn($table, 'updated_at')) $data['updated_at'] = now();
                    if (Registry::hasColumn($table, 'updated_by')) $data['updated_by'] = Context::userId();
                    DB::table($table)->where($pk, $id)->update($data);
                    Audit::log($table, $id, 'UPDATE', $changes, $opt['reason'] ?? null, $ent);
                }
                $created = false;
            } else {
                if (Registry::hasColumn($table, 'fk_id_ent') && ! isset($data['fk_id_ent'])) $data['fk_id_ent'] = $ent ?? 0;
                if (Registry::hasColumn($table, 'created_at') && ! isset($data['created_at'])) $data['created_at'] = now();
                if (Registry::hasColumn($table, 'created_by') && ! isset($data['created_by'])) $data['created_by'] = Context::userId();
                foreach (Registry::fields($formKey) as $f) {     // own codes: PO-…, RES-…, taken when the record is first saved
                    if ($f['column_name'] && str_starts_with((string) $f['default_rule'], 'numbering:') && empty($data[$f['column_name']]) && Registry::hasColumn($table, $f['column_name'])) {
                        $data[$f['column_name']] = Numbering::next(substr($f['default_rule'], 10), (int) ($data['fk_id_ent'] ?? $ent ?? 0));
                    }
                }
                $data = self::fillNotNull($table, $data);
                $id = (int) DB::table($table)->insertGetId($data, $pk);
                Audit::log($table, $id, 'INSERT', $data, null, $ent);
                $created = true;
            }
            SearchIndex::put($formKey, $table, $id, $ent);
            if (! empty($opt['event'])) EventBus::fire($opt['event'], $table, $id, ['form' => $formKey, 'created' => $created], $ent);
            return ['id' => $id, 'created' => $created];
        });
    }

    /** Record page: values shown as words, parents (links out), children (links in), tasks and history. */
    public static function page(string $formKey, int $id): array
    {
        $table = Registry::table($formKey) ?? abort(404);
        $row = self::get($formKey, $id);
        $sections = []; $parents = [];
        foreach (Registry::fields($formKey) as $f) {
            $c = $f['column_name'];
            if (! $c || ! array_key_exists($c, $row) || ! Registry::visible($f, Context::role())) continue;
            $v = $row[$c]; $label = null;
            if ($v !== null && $v !== '' && in_array($f['type'], ['select', 'multiselect'], true) && $f['source']) {
                foreach (Registry::options($f, Context::ent(), null, 2000) as [$ov, $ol]) if ((string) $ov === (string) $v) { $label = $ol; break; }
                [$kind, $arg] = array_pad(explode(':', (string) $f['source'], 2), 2, '');
                if ($label !== null && in_array($kind, ['table', 'party'], true)) {
                    $pt = $kind === 'party' ? 't_account' : explode('?', $arg)[0];
                    $pf = self::formOfTable($pt);
                    if ($pf) $parents[] = ['label' => $f['label'], 'title' => $label, 'url' => "#/rec/$pf/$v"];
                }
            }
            $sections[$f['section'] ?: 'Details'][] = ['label' => $f['label'], 'type' => $f['type'], 'value' => $v, 'shown' => $label];
        }
        $children = [];
        $pk = Registry::pk($table);
        $refs = DB::table('sys_registry')->where('source', 'like', "table:$table%")->whereNotNull('column_name')->get(['form_key', 'form_title', 'table_name', 'column_name']);
        foreach ($refs as $r) {
            $ct = Registry::baseTable($r->table_name);
            if (! $ct || $ct === $table || isset($children[$ct]) || ! Registry::hasColumn($ct, $r->column_name) || Registry::table($r->form_key) !== $ct) continue;
            $n = DB::table($ct)->where($r->column_name, $id)->count();
            if ($n) $children[$ct] = ['form_key' => $r->form_key, 'title' => $r->form_title, 'count' => $n, 'url' => "#/list/{$r->form_key}?f[{$r->column_name}]=$id"];
        }
        $tasks = DB::table('tasks')->where('linked_module', $table)->where('linked_record_id', $id)->orderByDesc('id')->limit(20)->get(['id', 'title', 'status', 'priority', 'due_at', 'rule_code'])->all();
        $hist = DB::table('sys_audit_log as a')->leftJoin('t_users as u', 'u.id', '=', 'a.user_id')->where('a.table_name', $table)->where('a.record_id', $id)
            ->orderByDesc('a.id')->limit(50)->get(['a.action', 'a.changes_json', 'a.reason', 'a.created_at', 'u.display_name'])
            ->map(fn ($h) => ['action' => $h->action, 'at' => $h->created_at, 'by' => $h->display_name ?: 'System', 'reason' => $h->reason, 'changes' => $h->action === 'UPDATE' ? json_decode($h->changes_json ?: '[]', true) : null])->all();
        $lc = Registry::labelColumn($table);
        return ['form_key' => $formKey, 'id' => $id, 'form_title' => Registry::fields($formKey)[0]['form_title'], 'title' => (string) ($row[$lc] ?? "#$id"),
            'sections' => $sections, 'parents' => $parents, 'children' => array_values($children), 'tasks' => $tasks, 'history' => $hist];
    }

    /** The form that owns a table (the master form with the most fields on it). */
    public static function formOfTable(string $table): ?string
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach (DB::table('sys_registry')->whereNotNull('table_name')->selectRaw('form_key, table_name, COUNT(*) n, MAX(is_master) m')->groupBy('form_key', 'table_name')->orderByDesc('m')->orderByDesc('n')->get() as $r) {
                $map[Registry::baseTable($r->table_name)] ??= $r->form_key;
            }
        }
        return $map[$table] ?? null;
    }

    /** All rows of a list for export (same filters), max 50,000. Returns [header, rows]. */
    public static function export(string $formKey, array $p = []): array
    {
        $out = []; $head = [];
        $p['per_page'] = 200; $page = 1;
        do {
            $d = self::list($formKey, $p + ['page' => $page, 'max_cols' => 60]);
            $head = array_map(fn ($c) => $c['label'], $d['columns']);
            foreach ($d['rows'] as $r) $out[] = array_map(fn ($c) => $r[$c['name'] . '__label'] ?? $r[$c['name']], $d['columns']);
            $page++;
        } while (count($d['rows']) === 200 && count($out) < 50000);
        Audit::log(Registry::table($formKey), 0, 'EXPORT', ['rows' => count($out), 'form' => $formKey]);
        return [$head, $out];
    }

    // ---- helpers ----

    private static function storedFields(string $formKey, string $table): array
    {
        return array_values(array_filter(Registry::fields($formKey), fn ($f) => $f['column_name'] && Registry::baseTable($f['table_name']) === $table
            && Registry::hasColumn($table, $f['column_name']) && ! in_array($f['column_name'], self::BLOCKED, true) && Registry::visible($f, Context::role())));
    }

    private static function fieldByCol(array $fields, string $col): array
    {
        foreach ($fields as $f) if ($f['column_name'] === $col) return $f;
        return ['label' => $col, 'type' => 'text'];
    }

    /** Company filter: a user only sees rows of the company he works in (plus shared rows). */
    private static function scope($q, string $table): void
    {
        $ent = Context::ent();
        if (! $ent) return;
        if (Registry::hasColumn($table, 'fk_id_ent')) $q->where(fn ($w) => $w->where("$table.fk_id_ent", $ent)->orWhere("$table.fk_id_ent", 0)->orWhereNull("$table.fk_id_ent"));
        elseif (Registry::hasColumn($table, 'company_id')) $q->where(fn ($w) => $w->where("$table.company_id", $ent)->orWhereNull("$table.company_id"));
    }

    /** Dropdown values shown as words in lists. */
    private static function labels(array $fields, $rows): array
    {
        $out = [];
        foreach ($fields as $f) {
            if (! in_array($f['type'], ['select'], true) || ! $f['source']) continue;
            $vals = $rows->pluck($f['column_name'])->filter(fn ($v) => $v !== null && $v !== '')->unique();
            if ($vals->isEmpty()) continue;
            foreach (Registry::options($f, Context::ent(), null, 1000) as [$v, $l]) $out[$f['column_name']][$v] = $l;
        }
        return $out;
    }

    private static function clean(array $f, mixed $v): mixed
    {
        if (is_string($v)) {
            $v = trim($v);
            if ($f['auto_correct'] === 'upper') $v = mb_strtoupper($v);
            if ($f['auto_correct'] === 'lower') $v = mb_strtolower($v);
        }
        if ($f['type'] === 'check') return in_array($v, [1, '1', true, 'true', 'on', 'Y'], true) ? 1 : 0;
        if (in_array($f['type'], ['number', 'money'], true) && is_string($v) && $v !== '') $v = str_replace([',', '£', '$', '€', ' '], '', $v);
        return $v;
    }

    private static function invalid(array $f, mixed $v): ?string
    {
        $l = $f['label'];
        switch ($f['type']) {
            case 'number': case 'money':
                if (! is_numeric($v)) return "$l must be a number.";
                if ($f['min'] !== null && $f['min'] !== '' && is_numeric($f['min']) && $v < $f['min']) return "$l cannot be less than {$f['min']}.";
                if ($f['max'] !== null && $f['max'] !== '' && is_numeric($f['max']) && $v > $f['max']) return "$l cannot be more than {$f['max']}.";
                break;
            case 'date':
                if (! preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $v) || ! strtotime((string) $v)) return "$l must be a date (YYYY-MM-DD).";
                break;
            case 'email':
                if (! filter_var($v, FILTER_VALIDATE_EMAIL)) return "$l is not a valid email address.";
                break;
            case 'link':
                if (! preg_match('#^https?://#i', (string) $v)) return "$l must start with http:// or https://.";
                break;
        }
        return null;
    }

    /** Conditions of the registry: "field=VALUE", "field!=VALUE", "field!=" (not empty), "field>1". */
    public static function cond(string $expr, array $vals): bool
    {
        if (! preg_match('/^([\w.]+)\s*(!=|=|>|<)\s*(.*)$/', $expr, $m)) return false;
        $have = (string) ($vals[preg_replace('/^parent\./', '', $m[1])] ?? '');
        return match ($m[2]) {
            '=' => $have === $m[3], '!=' => $have !== $m[3], '>' => (float) $have > (float) $m[3], '<' => (float) $have < (float) $m[3],
        };
    }

    /** Old tables have NOT NULL columns without defaults: give them an empty value so a quick create never fails. */
    public static function fillNotNull(string $table, array $data): array
    {
        static $meta = [];
        $meta[$table] ??= DB::select('SELECT column_name c, data_type t, column_type ct FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND is_nullable = "NO" AND column_default IS NULL AND extra NOT LIKE "%auto_increment%"', [$table]);
        foreach ($meta[$table] as $m) {
            if (array_key_exists($m->c, $data) && $data[$m->c] !== null) continue;
            $data[$m->c] = match (true) {
                in_array($m->t, ['int', 'tinyint', 'smallint', 'bigint', 'mediumint', 'decimal', 'float', 'double'], true) => 0,
                $m->t === 'date' => date('Y-m-d'),
                in_array($m->t, ['datetime', 'timestamp'], true) => now(),
                $m->t === 'enum' => trim(explode(',', substr($m->ct, 5, -1))[0], "'"),
                default => '',
            };
        }
        return $data;
    }
}
