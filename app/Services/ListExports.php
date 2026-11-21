<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Export of a list in CSV / Excel / PDF (what the user sees: columns, order, filters), saved export templates, and their schedule. */
class ListExports
{
    public const FORMATS = ['CSV' => ['csv', 'text/csv; charset=UTF-8'], 'XLSX' => ['xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], 'PDF' => ['pdf', 'application/pdf']];

    /** A value that a spreadsheet could run as a formula is made safe. */
    private static function safe(mixed $v): mixed { return is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v; }

    /** The file body of an export: [bytes, extension, mime]. */
    public static function build(string $formKey, array $p, string $format = 'CSV', string $title = ''): array
    {
        $format = isset(self::FORMATS[$format]) ? $format : 'CSV';
        [$head, $rows] = RecordService::export($formKey, $p);
        [$ext, $mime] = self::FORMATS[$format];
        if ($format === 'CSV') {
            $o = fopen('php://temp', 'w+'); fwrite($o, "\xEF\xBB\xBF"); fputcsv($o, $head);
            foreach ($rows as $row) fputcsv($o, array_map([self::class, 'safe'], $row));
            rewind($o); $bytes = stream_get_contents($o); fclose($o);
        } elseif ($format === 'XLSX') {
            $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet(); $sh = $book->getActiveSheet(); $sh->setTitle(mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $title ?: $formKey), 0, 31));
            $sh->fromArray([$head], null, 'A1'); $n = 2;
            foreach ($rows as $row) { $c = 1; foreach ($row as $v) { $cell = $sh->getCell([$c++, $n]); is_numeric($v) && ! preg_match('/^0\d|^\+/', (string) $v) && strlen((string) $v) < 15 ? $cell->setValueExplicit($v + 0, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC) : $cell->setValueExplicit((string) $v, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING); } $n++; }
            $sh->getStyle('1:1')->getFont()->setBold(true); $sh->freezePane('A2');
            $tmp = tempnam(sys_get_temp_dir(), 'moxl'); (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($tmp); $bytes = file_get_contents($tmp); @unlink($tmp);
        } else {
            $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
            $html = '<html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:8px}h1{font-size:13px;margin:0 0 2px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #bbb;padding:2px 3px;text-align:left;vertical-align:top}th{background:#eee}</style></head><body>'
                . '<h1>' . $e($title ?: $formKey) . '</h1><div>' . count($rows) . ' rows · ' . date('j M Y H:i') . '</div><table><thead><tr>' . implode('', array_map(fn ($h) => '<th>' . $e($h) . '</th>', $head)) . '</tr></thead><tbody>';
            foreach (array_slice($rows, 0, 5000) as $row) $html .= '<tr>' . implode('', array_map(fn ($v) => '<td>' . $e($v) . '</td>', $row)) . '</tr>';
            $d = new \Dompdf\Dompdf(); $d->loadHtml($html . '</tbody></table></body></html>'); $d->setPaper('A4', 'landscape'); $d->render(); $bytes = $d->output();
        }
        return [$bytes, $ext, $mime, count($rows)];
    }

    // ---------- templates ----------
    public static function templates(object $u, string $listKey): array
    {
        [, $role] = Perm::rolesOf($u);
        return DB::table('mo_export_templates as t')->leftJoin('t_users as o', 'o.id', '=', 't.user_id')->where('t.list_key', $listKey)->whereIn('t.fk_id_ent', [0, (int) Context::ent()])
            ->where(fn ($w) => $w->where('t.user_id', $u->id)->orWhere('t.shared_role', $role)->orWhere('t.shared_role', '*'))->orderBy('t.name')
            ->get(['t.*', 'o.display_name as owner'])->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'columns' => json_decode($t->columns_json, true) ?: [], 'state' => json_decode((string) $t->state_json, true) ?: [], 'format' => $t->format,
                'shared_role' => $t->shared_role, 'schedule' => $t->schedule, 'send_to' => $t->send_to, 'mine' => (int) $t->user_id === (int) $u->id, 'owner' => $t->owner, 'created' => date('jS, F Y h:i A', strtotime((string) $t->created_at)), 'last_run' => $t->last_run_at])->all();
    }

    public static function save(object $u, string $listKey, array $d): int
    {
        $name = trim((string) ($d['name'] ?? '')); $cols = array_values(array_filter(array_map('strval', (array) ($d['columns'] ?? [])), fn ($c) => preg_match('/^[A-Za-z0-9_]{1,64}$/', $c)));
        if ($name === '') throw new \InvalidArgumentException('Enter a name for your export.');
        if (! $cols) throw new \InvalidArgumentException('Select at least one field for the export.');
        if (! Registry::exists($listKey)) throw new \InvalidArgumentException('List not found.');
        $format = strtoupper((string) ($d['format'] ?? 'CSV')); if (! isset(self::FORMATS[$format])) $format = 'CSV';
        $schedule = (string) ($d['schedule'] ?? 'NONE'); if (! in_array($schedule, ['NONE', 'DAILY', 'WEEKLY'], true)) $schedule = 'NONE'; $to = trim((string) ($d['send_to'] ?? ''));
        if ($schedule !== 'NONE' && ! filter_var($to, FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('A scheduled export needs the e-mail address it is sent to.');
        $role = ($d['shared_role'] ?? '') ?: null;
        if ($role && $role !== '*' && ! isset(Perm::roles()[$role])) throw new \InvalidArgumentException('Role not found.');
        if ($role && ! Perm::has($u, 'admin.saveView_may')) throw new \InvalidArgumentException('Only the Owner or a Manager can share an export with a role.');
        $st = (array) ($d['state'] ?? []);
        $row = ['columns_json' => json_encode($cols), 'state_json' => json_encode(['q' => (string) ($st['q'] ?? ''), 'filter' => (array) ($st['filter'] ?? []), 'where' => $st['where'] ?? null, 'sort' => (string) ($st['sort'] ?? ''), 'dir' => (string) ($st['dir'] ?? 'desc')]),
            'format' => $format, 'shared_role' => $role, 'schedule' => $schedule, 'send_to' => $to ?: null, 'updated_at' => now()];
        $key = ['user_id' => $u->id, 'list_key' => $listKey, 'name' => mb_substr($name, 0, 120)];
        $id = DB::table('mo_export_templates')->where($key)->value('id');
        if ($id) DB::table('mo_export_templates')->where('id', $id)->update($row);
        else $id = DB::table('mo_export_templates')->insertGetId($key + $row + ['fk_id_ent' => (int) Context::ent(), 'created_at' => now()]);
        return (int) $id;
    }

    /** The template a person may use (his own, or shared with his role). */
    public static function find(object $u, int $id): object
    {
        [, $role] = Perm::rolesOf($u);
        $t = DB::table('mo_export_templates')->where('id', $id)->first();
        if (! $t || ((int) $t->user_id !== (int) $u->id && ! in_array($t->shared_role, [$role, '*'], true))) abort(404, 'Export not found.');
        return $t;
    }

    /** [bytes, file name, mime, rows] of a template, with the data scope of whoever runs it. */
    public static function run(object $t): array
    {
        $st = json_decode((string) $t->state_json, true) ?: [];
        [$bytes, $ext, $mime, $n] = self::build($t->list_key, ['cols' => json_decode($t->columns_json, true) ?: [], 'q' => $st['q'] ?? '', 'filter' => $st['filter'] ?? [], 'where' => $st['where'] ?? null, 'sort' => $st['sort'] ?? '', 'dir' => $st['dir'] ?? 'desc'], $t->format, $t->name);
        return [$bytes, preg_replace('/[^\w \-\.]+/u', '_', $t->name) . '_' . date('YmdHis') . '.' . $ext, $mime, $n];
    }

    public static function remove(object $u, int $id): void
    {
        $t = DB::table('mo_export_templates')->where('id', $id)->first();
        if (! $t || (int) $t->user_id !== (int) $u->id) abort(403, 'You can only remove your own exports.');
        DB::table('mo_export_templates')->where('id', $id)->delete();
    }

    /** Night job: scheduled exports (daily; weekly on Monday) are made as their owner and e-mailed. Returns how many were queued. */
    public static function runScheduled(?string $today = null): int
    {
        $today = $today ?: date('Y-m-d'); $n = 0;
        foreach (DB::table('mo_export_templates')->whereIn('schedule', ['DAILY', 'WEEKLY'])->whereNotNull('send_to')->get() as $t) {
            if ($t->schedule === 'WEEKLY' && (int) date('N', strtotime($today)) !== 1) continue;
            if ($t->last_run_at && substr((string) $t->last_run_at, 0, 10) >= $today) continue;
            $u = \App\Models\User::find($t->user_id); if (! $u || ! $u->f_active) continue;
            try {
                Context::set((int) ($t->fk_id_ent ?: $u->fk_id_ent), (int) $u->id); \Illuminate\Support\Facades\Auth::setUser($u); Perm::flush();
                if (! Perm::has($u, "form:{$t->list_key}:EXPORT")) continue;
                [$bytes, $name, , $rows] = self::run($t);
                $path = 'exports/' . $t->id . '/' . $name; Storage::disk('local')->put($path, $bytes);
                Connect\Messenger::queueEmail((int) Context::ent(), null, (string) $t->send_to, 'Export: ' . $t->name . ' (' . date('j M Y', strtotime($today)) . ')', "Your scheduled export \"{$t->name}\" is attached. Rows: $rows.", $path, 'EXPORT_SCHEDULED', 'mo_export_templates', (int) $t->id);
                DB::table('mo_export_templates')->where('id', $t->id)->update(['last_run_at' => $today . ' ' . date('H:i:s')]);
                Audit::log(Registry::table($t->list_key), 0, 'EXPORT', ['rows' => $rows, 'template' => $t->name, 'scheduled' => true, 'to' => $t->send_to]); $n++;
            } catch (\Throwable $e) { report($e); }
        }
        return $n;
    }

    // ---------- per-user list preferences (column order, widths, rows per page; density for all lists) ----------
    public static function prefs(object $u, string $page): array
    {
        $rows = DB::table('t_user_column_prefs')->where('fk_id_user', $u->id)->whereIn('page_key', [$page, '*'])->pluck('prefs_json', 'page_key');
        return ['list' => json_decode((string) ($rows[$page] ?? ''), true) ?: [], 'all' => json_decode((string) ($rows['*'] ?? ''), true) ?: []];
    }

    public static function savePrefs(object $u, string $page, array $d): void
    {
        $clean = [];
        if (isset($d['cols'])) $clean['cols'] = array_values(array_filter(array_map('strval', (array) $d['cols']), fn ($c) => preg_match('/^[A-Za-z0-9_]{1,64}$/', $c)));
        if (isset($d['widths'])) foreach ((array) $d['widths'] as $c => $w) if (preg_match('/^[A-Za-z0-9_]{1,64}$/', (string) $c) && (int) $w >= 40 && (int) $w <= 1200) $clean['widths'][$c] = (int) $w;
        if (isset($d['per'])) $clean['per'] = in_array((int) $d['per'], [25, 50, 100, 250], true) ? (int) $d['per'] : 100;
        if (isset($d['hidden'])) $clean['hidden'] = array_values(array_filter(array_map('strval', (array) $d['hidden']), fn ($c) => preg_match('/^[a-z0-9_]{1,40}$/', $c)));
        if (isset($d['density'])) $clean['density'] = $d['density'] === 'compact' ? 'compact' : 'comfortable';
        // a remembered scope (marketplace, shop, dates): only digits and ISO dates are kept
        if (isset($d['scope']) && is_array($d['scope'])) { $sc = []; foreach ($d['scope'] as $k => $v) if (preg_match('/^[a-z_]{1,20}$/', (string) $k) && is_scalar($v) && preg_match('/^(\d{1,10}|\d{4}-\d{2}-\d{2})$/', (string) $v)) $sc[$k] = (string) $v; $clean['scope'] = $sc; }
        $w = ['fk_id_user' => $u->id, 'page_key' => mb_substr($page, 0, 50)];
        $old = json_decode((string) DB::table('t_user_column_prefs')->where($w)->value('prefs_json'), true) ?: [];
        DB::table('t_user_column_prefs')->updateOrInsert($w, ['prefs_json' => json_encode(array_merge($old, $clean)), 'hidden_columns' => '', 'updated_at' => now()]);
    }
}
