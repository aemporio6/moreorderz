<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-G_CORE-34 task CSV import (bosses). Columns in template order: ref, title*, category* (name), owner* (id, full name, first + surname, login, or first name when unique),
 * due_at* (Y-m-d, d/m/Y, d-m-Y, d.m.Y, j/n/Y), priority, status (TODO / DOING / STUCK), assigned_by (empty = me), helpers (; or |), description, linked_module, linked_record_id,
 * reference_id, checklist (|), evidence_required (yes / no), depends_on_ref, repeat_type, repeat_n, repeat_weekday, repeat_day_of_month, repeat_until.
 * ";" or "," detected, BOM removed, ≤ 2 MB, ≤ 1,000 rows. Idempotent: import_ref "csv:{ref}" or "csv:" + sha1(lower(title|owner id|due)) – a row already imported is refused.
 * "Check file" changes nothing; Import runs only when every row is OK and is all-or-nothing on the server. Same rules as New task (TaskCreate). Undo by batch.
 */
class TaskImport
{
    public const COLUMNS = ['ref', 'title', 'category', 'owner', 'due_at', 'priority', 'status', 'assigned_by', 'helpers', 'description', 'linked_module', 'linked_record_id', 'reference_id', 'checklist', 'evidence_required',
        'depends_on_ref', 'repeat_type', 'repeat_n', 'repeat_weekday', 'repeat_day_of_month', 'repeat_until'];
    public const REQUIRED = ['title', 'owner', 'due_at', 'category'];

    public static function template(): string
    {
        $h = fopen('php://temp', 'r+'); fputcsv($h, self::COLUMNS);
        fputcsv($h, ['R1', 'Count bin A1', 'Stock', 'Ali Khan', date('Y-m-d', strtotime('+3 days')), 'NORMAL', 'TODO', '', '', 'Count and write the number on the sheet', '', '', '', 'Count|Photo of the sheet', 'yes', '', '', '', '', '', '']);
        fputcsv($h, ['R2', 'Recount bin A1', 'Stock', 'Ali Khan', date('Y-m-d', strtotime('+4 days')), 'HIGH', 'TODO', '', '', '', '', '', '', '', 'no', 'R1', '', '', '', '', '']);
        rewind($h); return stream_get_contents($h);
    }

    /** [header, rows] of the file (the checks of the file itself). */
    public static function read(string $name, string $bytes): array
    {
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'csv') throw new \InvalidArgumentException('The file must be a .csv file.');
        if (strlen($bytes) > 2 * 1024 * 1024) throw new \InvalidArgumentException('The file is larger than 2 MB.');
        $bytes = preg_replace('/^\xEF\xBB\xBF/', '', $bytes); $first = strtok($bytes, "\n");
        $sep = substr_count((string) $first, ';') > substr_count((string) $first, ',') ? ';' : ',';
        $lines = array_values(array_filter(preg_split('/\r\n|\n|\r/', $bytes), fn ($l) => trim($l) !== ''));
        $head = array_map(fn ($x) => strtolower(trim($x)), str_getcsv((string) array_shift($lines), $sep));
        foreach (self::REQUIRED as $c) if (! in_array($c, $head, true)) throw new \InvalidArgumentException("Column \"$c\" is missing. Download the template to see the format.");
        if (count($lines) > 1000) throw new \InvalidArgumentException('More than 1000 rows - split the file.');
        $rows = []; foreach ($lines as $l) { $v = str_getcsv($l, $sep); $rows[] = array_combine($head, array_pad(array_slice($v, 0, count($head)), count($head), '')); }
        return [$head, $rows];
    }

    public static function date(string $v): ?string
    {
        $v = trim($v);
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'j/n/Y'] as $f) { $d = \DateTime::createFromFormat('!' . $f, $v); if ($d && $d->format($f) === $v) return $d->format('Y-m-d'); }
        return null;
    }

    /** The login a name points to: id, full display name, first + surname of the person, login, or a first name that only one person has. */
    public static function owner(string $v): ?int
    {
        $v = trim($v); if ($v === '') return null;
        $act = fn () => DB::table('t_users as u')->leftJoin('t_account as a', 'a.id', '=', 'u.fk_id_account')->where('u.f_active', 1);
        if (ctype_digit($v) && $act()->where('u.id', (int) $v)->exists()) return (int) $v;
        foreach ([fn ($q) => $q->whereRaw('LOWER(u.display_name) = ?', [mb_strtolower($v)]), fn ($q) => $q->whereRaw("LOWER(TRIM(CONCAT_WS(' ', a.first_name, a.sur_name))) = ?", [mb_strtolower($v)]),
            fn ($q) => $q->whereRaw('LOWER(u.login_id) = ?', [mb_strtolower($v)])] as $w) { $ids = $w($act())->pluck('u.id')->unique(); if ($ids->count() === 1) return (int) $ids->first(); }
        $ids = $act()->where(fn ($q) => $q->whereRaw('LOWER(a.first_name) = ?', [mb_strtolower($v)])->orWhereRaw("LOWER(SUBSTRING_INDEX(u.display_name, ' ', 1)) = ?", [mb_strtolower($v)]))->pluck('u.id')->unique();
        return $ids->count() === 1 ? (int) $ids->first() : null;
    }

    private static function ref(array $r, ?int $owner, ?string $due): string
    {
        return trim((string) ($r['ref'] ?? '')) !== '' ? 'csv:' . trim((string) ($r['ref'] ?? '')) : 'csv:' . sha1(mb_strtolower(trim((string) $r['title']) . '|' . $owner . '|' . $due));
    }

    /** Check ($apply = false, nothing kept) or import (all-or-nothing). Returns per-line results. */
    public static function run(object $u, string $name, string $bytes, bool $apply, bool $notify = false): array
    {
        if (! in_array($u->role_code, TaskScore::BOSS, true)) throw new \InvalidArgumentException('Refused: only a Partner or Manager can import tasks.');
        [, $rows] = self::read($name, $bytes); $ent = (int) Context::ent(); $out = []; $byRef = []; $ok = true; $ids = [];
        DB::beginTransaction();
        try {
            foreach ($rows as $i => $r) {
                $line = $i + 2;
                try {
                    $owner = self::owner((string) $r['owner']); if (! $owner) throw new \InvalidArgumentException('Refused: owner "' . $r['owner'] . '" not found (or more than one person has that name).');
                    $due = self::date((string) $r['due_at']); if (! $due) throw new \InvalidArgumentException('Refused: due date "' . $r['due_at'] . '" is not a date (use 2026-11-30 or 30/11/2026).');
                    $cat = DB::table('task_categories')->whereRaw('LOWER(name) = ?', [mb_strtolower(trim((string) $r['category']))])->where('is_active', 1)->value('id');
                    if (! $cat) throw new \InvalidArgumentException('Refused: category "' . $r['category'] . '" not found.');
                    $st = strtoupper(trim((string) ($r['status'] ?? '')) ?: 'TODO'); if (! in_array($st, ['TODO', 'DOING', 'STUCK'], true)) throw new \InvalidArgumentException('Refused: status must be TODO, DOING or STUCK – finished work is not imported.');
                    $key = self::ref($r, $owner, $due);
                    if (DB::table('tasks')->where('import_ref', $key)->exists()) throw new \InvalidArgumentException("already imported (ref $key)");
                    $dep = null; if (trim((string) ($r['depends_on_ref'] ?? '')) !== '') { $dep = $byRef[trim((string) $r['depends_on_ref'])] ?? null; if (! $dep) throw new \InvalidArgumentException('Refused: depends_on_ref "' . $r['depends_on_ref'] . '" is not a ref of an earlier row.'); }
                    $by = trim((string) ($r['assigned_by'] ?? '')) !== '' ? self::owner((string) $r['assigned_by']) : (int) $u->id; if (! $by) throw new \InvalidArgumentException('Refused: assigned_by "' . $r['assigned_by'] . '" not found.');
                    $assigner = DB::table('t_users')->where('id', $by)->first();
                    $res = TaskCreate::create($ent, ['title' => $r['title'], 'category' => $cat, 'owner' => $owner, 'due' => $due, 'priority' => strtoupper(trim((string) ($r['priority'] ?? '')) ?: 'NORMAL'),
                        'link' => trim((string) ($r['linked_module'] ?? '')) ?: 'NONE', 'record' => trim((string) ($r['linked_record_id'] ?? '')) ?: null, 'depends_on' => $dep, 'detail' => $r['description'] ?? '',
                        'checklist' => str_replace('|', "\n", (string) ($r['checklist'] ?? '')), 'evidence' => strtolower(trim((string) ($r['evidence_required'] ?? 'yes'))) !== 'no', 'system' => true, 'quiet' => ! $notify], $assigner);
                    $id = (int) $res['id']; $set = ['import_ref' => $key, 'reference_id' => trim((string) ($r['reference_id'] ?? '')) ?: null];
                    $rt = strtoupper(trim((string) ($r['repeat_type'] ?? ''))); if ($rt !== '' && $rt !== 'NONE') {
                        if (! in_array($rt, ['DAILY', 'WEEKLY', 'MONTHLY', 'EVERY_N_DAYS'], true)) throw new \InvalidArgumentException('Refused: repeat_type must be DAILY, WEEKLY, MONTHLY or EVERY_N_DAYS.');
                        $set += ['repeat_type' => $rt, 'repeat_n' => (int) ($r['repeat_n'] ?? 0) ?: null, 'repeat_weekday' => (int) ($r['repeat_weekday'] ?? 0) ?: null, 'repeat_day_of_month' => (int) ($r['repeat_day_of_month'] ?? 0) ?: null,
                            'repeat_until' => trim((string) ($r['repeat_until'] ?? '')) !== '' ? (self::date((string) $r['repeat_until']) ?? throw new \InvalidArgumentException('Refused: repeat_until is not a date.')) : null]; }
                    DB::table('tasks')->where('id', $id)->update($set);
                    foreach (preg_split('/[;|]/', (string) ($r['helpers'] ?? '')) as $hn) if (trim($hn) !== '') { $h = self::owner($hn); if (! $h) throw new \InvalidArgumentException("Refused: helper \"$hn\" not found."); DB::table('task_helpers')->insertOrIgnore(['task_id' => $id, 'user_id' => $h]); }
                    if ($st !== 'TODO') {
                        DB::table('tasks')->where('id', $id)->update(['status' => $st, 'start_at' => now()] + ($st === 'STUCK' ? ['stuck_since' => now(), 'stuck_on_type' => 'OTHER', 'stuck_on_text' => mb_substr(trim((string) ($r['description'] ?? '')) ?: 'imported as stuck', 0, 255)] : []));
                        DB::table('task_history')->insert(['task_id' => $id, 'at' => now(), 'user_id' => $u->id, 'field' => 'status', 'old_value' => 'TODO', 'new_value' => $st, 'reason' => 'Imported as ' . $st]);
                    }
                    if (trim((string) ($r['ref'] ?? '')) !== '') $byRef[trim((string) ($r['ref'] ?? ''))] = $id;
                    $ids[] = $id; $out[] = ['line' => $line, 'ok' => true, 'text' => ($apply ? TaskFlow::ref($id) . ' · ' : 'OK · ') . $r['title'], 'id' => $apply ? $id : null] + ($res['warnings'] ? ['warnings' => $res['warnings']] : []);
                } catch (\InvalidArgumentException $e) { $ok = false; $out[] = ['line' => $line, 'ok' => false, 'text' => $e->getMessage()]; }
            }
            if (! $apply || ! $ok) { DB::rollBack(); return ['ok' => $ok, 'rows' => $out, 'imported' => 0, 'message' => $ok ? count($rows) . ' row(s) OK – press Import.' : 'Nothing imported – fix the rows marked red and check again.']; }
            $job = (int) DB::table('bulk_jobs')->insertGetId(['job_no' => 'TI-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5)), 'list_key' => 'task_import', 'fk_id_account' => $u->id,
                'change_json' => json_encode(['action' => 'IMPORT', 'file' => mb_substr($name, 0, 120), 'rows' => $ids, 'ent' => $ent]), 'row_count' => count($ids), 'status' => 'APPLIED', 'created_at' => now(), 'applied_at' => now()]);
            DB::commit();
            return ['ok' => true, 'rows' => $out, 'imported' => count($ids), 'batch' => $job, 'message' => count($ids) . ' task(s) imported.'];
        } catch (\Throwable $e) { if (DB::transactionLevel()) DB::rollBack(); throw $e; }
    }

    /** Undo a batch: its tasks are cancelled with the reason "import undone" (never deleted). */
    public static function undo(int $batch, object $u): array
    {
        $j = DB::table('bulk_jobs')->where('id', $batch)->where('list_key', 'task_import')->first() ?? throw new \InvalidArgumentException('Import batch not found.');
        if (! in_array($u->role_code, TaskScore::BOSS, true)) throw new \InvalidArgumentException('Refused: only a Partner or Manager can undo an import.');
        if ($j->status !== 'APPLIED') throw new \InvalidArgumentException('This import was already undone.');
        $n = 0;
        foreach (json_decode((string) $j->change_json, true)['rows'] ?? [] as $id) {
            $t = DB::table('tasks')->where('id', $id)->first(); if (! $t || ! in_array($t->status, TaskFlow::OPEN, true)) continue;
            DB::table('tasks')->where('id', $id)->update(['status' => 'CANCELLED', 'cancelled_at' => now(), 'cancelled_by_user_id' => $u->id, 'last_update_at' => now()]);
            DB::table('task_history')->insert(['task_id' => $id, 'at' => now(), 'user_id' => $u->id, 'field' => 'status', 'old_value' => $t->status, 'new_value' => 'CANCELLED', 'reason' => 'import undone']); $n++;
        }
        DB::table('bulk_jobs')->where('id', $j->id)->update(['status' => 'UNDONE', 'undone_at' => now()]);
        return ['cancelled' => $n, 'message' => "$n task(s) cancelled – import undone."];
    }
}
