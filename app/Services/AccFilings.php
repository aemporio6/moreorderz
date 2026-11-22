<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * RULES_E_FINANCE the Tax & filings area around the schedule of AccCompliance:
 * R-ACC-11 filing types (per enterprise copy, reminder days cleaned), R-ACC-12 reminder digest (N17), R-ACC-13 overview / filters / summary / month calendar,
 * R-ACC-14 manual items (only those can be deleted; an auto item is set to Not required with a reason), R-ACC-15 proof documents (allow-list, MIME = extension,
 * size from acc_hub_upload_max_mb, <enterprise>/<md5>.<ext> outside the web root, served after a rights check with no-store, soft delete logged),
 * R-ACC-19 companies and personal tax lists.
 */
class AccFilings
{
    public const AREAS = ['VAT', 'CT', 'CS', 'ACCOUNTS', 'PAYROLL', 'PERSONAL', 'OTHER'];
    public const AUTHORITIES = ['HMRC', 'CH', 'OTHER'];
    public const COMPLETIONS = ['FILE', 'PAY', 'FILE_PAY'];
    public const TABS = ['open' => 'All open', 'overdue' => 'Overdue', 'week' => 'Due in 7 days', 'soon' => 'Due soon', 'later' => 'Later', 'done' => 'Done', 'all' => 'Everything'];

    /** Allowed proof files: extension → the MIME types that may come with it. */
    public const FILES = [
        'pdf' => ['application/pdf'], 'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'webp' => ['image/webp'],
        'csv' => ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
    ];

    public static function setting(int $ent, string $key, string $default): string
    {
        $v = DB::table('acc_settings')->whereIn('fk_id_ent', [$ent, 0])->where('setting_key', $key)->orderByDesc('fk_id_ent')->value('setting_value');
        return $v === null || $v === '' ? $default : (string) $v;
    }

    /* ---------------- R-ACC-11 filing types ---------------- */

    /** "7,30,7,400" → "30,7": each 0–365, de-duplicated, high → low; nothing left → the default. */
    public static function days(string $s): string
    {
        $d = array_values(array_unique(array_map('intval', array_filter(preg_split('/[^0-9]+/', $s) ?: [], fn ($x) => $x !== '' && (int) $x <= 365))));
        rsort($d);
        return $d ? implode(',', $d) : '30,14,7,1';
    }

    /** The types of an enterprise: its own copy wins over the seeded row (fk_id_ent 0). */
    public static function types(int $ent): array
    {
        AccCompliance::seedTypes();
        return DB::table('acc_obligation_types')->whereIn('fk_id_ent', [0, $ent])->orderBy('fk_id_ent')->get()->keyBy('type_code')->sortBy('sort_order')->all();
    }

    public static function saveType(int $ent, string $code, array $d, object $u): array
    {
        $code = strtoupper($code); $t = self::types($ent)[$code] ?? throw new \InvalidArgumentException('Unknown filing type.');
        $name = trim((string) ($d['type_name'] ?? $t->type_name)); if ($name === '') throw new \InvalidArgumentException('Write the name.');
        $row = ['type_name' => mb_substr($name, 0, 120), 'reminder_days' => self::days((string) ($d['reminder_days'] ?? $t->reminder_days)), 'sort_order' => (int) ($d['sort_order'] ?? $t->sort_order),
            'area' => strtoupper((string) ($d['area'] ?? $t->area)), 'authority' => strtoupper((string) ($d['authority'] ?? $t->authority)), 'completion' => strtoupper((string) ($d['completion'] ?? $t->completion)),
            'f_active' => $code === 'OTHER' ? 1 : (int) (bool) ($d['f_active'] ?? $t->f_active)];
        foreach (['area' => self::AREAS, 'authority' => self::AUTHORITIES, 'completion' => self::COMPLETIONS] as $k => $ok) if (! in_array($row[$k], $ok, true)) throw new \InvalidArgumentException("Choose the $k from the list.");
        if ((int) $t->fk_id_ent === $ent && $ent) DB::table('acc_obligation_types')->where('id', $t->id)->update($row);
        else DB::table('acc_obligation_types')->insert($row + ['fk_id_ent' => $ent, 'type_code' => $code]);
        Audit::log('acc_obligation_types', (int) $t->id, 'UPDATE', $row, 'filing type ' . $code, $ent);
        return ['message' => 'Saved.', 'reminder_days' => $row['reminder_days'], 'f_active' => $row['f_active']];
    }

    /* ---------------- people of the Hub ---------------- */

    /** Hub accountants: active logins of the enterprise who may change filings (money.EDIT). */
    public static function accountants(int $ent): array
    {
        $marked = AccHubAdmin::accountantIds($ent);      /* R-ACC-20: the Hub team's accountants once anyone is marked */
        return DB::table('t_users')->where('fk_id_ent', $ent)->where('f_active', 1)->orderBy('display_name')->get()->filter(fn ($u) => $marked ? in_array((int) $u->id, $marked, true) : Perm::has($u, 'money.EDIT'))
            ->map(fn ($u) => ['id' => (int) $u->id, 'name' => trim((string) ($u->display_name ?: $u->login_id))])->values()->all();
    }

    private static function names(array $rows): array
    {
        $co = DB::table('cat_company_house')->whereIn('id', array_map(fn ($o) => $o->entity_id, array_filter($rows, fn ($o) => $o->entity_type === 'COMPANY')) ?: [0])->pluck('company_name', 'id')->all();
        $pe = DB::table('t_account')->whereIn('id', array_map(fn ($o) => $o->entity_id, array_filter($rows, fn ($o) => $o->entity_type === 'PERSON')) ?: [0])->get(['id', 'first_name', 'sur_name'])->mapWithKeys(fn ($a) => [$a->id => trim($a->first_name . ' ' . $a->sur_name)])->all();
        return ['COMPANY' => $co, 'PERSON' => $pe];
    }

    /* ---------------- R-ACC-12 reminder digest ---------------- */

    /** How the line of one item reads in the digest. */
    public static function line(string $entity, string $title, string $due, int $left): string
    {
        return "$entity: $title – " . date('d-m-Y', strtotime($due)) . ' (' . ($left === 0 ? 'TODAY' : ($left < 0 ? 'OVERDUE by ' . -$left . ' day' . (-$left === 1 ? '' : 's') : "due in $left day" . ($left === 1 ? '' : 's'))) . ')';
    }

    /**
     * Daily (mo:nightly) per enterprise: generate, then open items due ≤ 120 days ahead – a reminder when days left equals one of the type's reminder days (key D<n>),
     * every day while overdue (key OD<date>). One message (N17: e-mail + WhatsApp + bell) per accountant; items with no accountant go to the Owner
     * (Settings › Choices ACC_REMINDERS_UNASSIGNED). The reminder log stops repeats. Returns the messages sent.
     */
    public static function reminders(int $ent, ?string $today = null): int
    {
        $today ??= date('Y-m-d');
        AccCompliance::generate($ent, null, $today);
        if (self::setting($ent, 'reminders_enabled', '1') === '0') return 0;
        $types = self::types($ent); $toOwner = Choices::get('ACC_REMINDERS_UNASSIGNED', $ent) !== 'NOBODY';
        $owners = DB::table('t_users')->where('fk_id_ent', $ent)->where('f_active', 1)->where('role_code', 'OWNER')->pluck('id')->map(fn ($x) => (int) $x)->all();
        $items = DB::table('acc_obligations')->where('fk_id_ent', $ent)->where('f_done', 0)->whereNotIn('status', ['FILED', 'PAID', 'NOT_REQUIRED'])
            ->where('due_date', '<=', date('Y-m-d', strtotime("$today +120 days")))->orderBy('due_date')->get()->all();
        $names = self::names($items); $by = [];
        foreach ($items as $o) {
            $left = (int) round((strtotime($o->due_date) - strtotime($today)) / 86400);
            $days = array_map('intval', explode(',', (string) ($types[$o->type_code]->reminder_days ?? '30,14,7,1')));
            $key = $left < 0 ? 'OD' . $today : (in_array($left, $days, true) ? 'D' . $left : null);
            if (! $key || DB::table('acc_reminder_log')->where('fk_id_obligation', $o->id)->where('remind_key', $key)->exists()) continue;
            $to = (int) $o->fk_assigned ? [(int) $o->fk_assigned] : ($toOwner ? $owners : []);
            foreach ($to as $uid) $by[$uid][] = [$o, $key, self::line($names[$o->entity_type][$o->entity_id] ?? '#' . $o->entity_id, (string) $o->title, (string) $o->due_date, $left)];
        }
        $sent = 0;
        foreach ($by as $uid => $lines) {
            $ids = AlertRules::send('N17', (int) $uid, ['body' => implode("\n", array_column($lines, 2)), 'date' => date('D d M', strtotime($today))], null,
                "N17:$uid:$today:" . substr(md5(implode(',', array_map(fn ($l) => $l[0]->id . $l[1], $lines))), 0, 8));
            if (! $ids) continue;
            $sent++; $login = (string) DB::table('t_users')->where('id', $uid)->value('login_id');
            foreach ($lines as [$o, $key]) DB::table('acc_reminder_log')->insertOrIgnore(['fk_id_obligation' => $o->id, 'remind_key' => $key, 'sent_to' => mb_substr($login, 0, 200), 'sent_at' => now()]);
        }
        return $sent;
    }

    /* ---------------- R-ACC-13 overview ---------------- */

    private static function base(int $ent, array $f)
    {
        $areas = array_values(array_filter(is_array($f['area'] ?? null) ? $f['area'] : explode(',', (string) ($f['area'] ?? ''))));
        $q = DB::table('acc_obligations as o')->leftJoin('cat_company_house as c', fn ($j) => $j->on('c.id', '=', 'o.entity_id')->where('o.entity_type', 'COMPANY'))
            ->leftJoin('t_account as a', fn ($j) => $j->on('a.id', '=', 'o.entity_id')->where('o.entity_type', 'PERSON'))->where('o.fk_id_ent', $ent)
            ->when($areas, fn ($q) => $q->whereIn('o.area', $areas))->when(! empty($f['type']), fn ($q) => $q->where('o.type_code', $f['type']))
            ->when(($f['assigned'] ?? '') === 'none', fn ($q) => $q->where('o.fk_assigned', 0))->when(ctype_digit((string) ($f['assigned'] ?? '')), fn ($q) => $q->where('o.fk_assigned', (int) $f['assigned']))
            ->when(! empty($f['company']), fn ($q) => $q->where('o.entity_type', 'COMPANY')->where('o.entity_id', (int) $f['company']))
            ->when(preg_match('/^([CP])(\d+)$/', (string) ($f['entity'] ?? ''), $m), fn ($q) => $q->where('o.entity_type', $m[1] === 'C' ? 'COMPANY' : 'PERSON')->where('o.entity_id', (int) $m[2]))
            ->when(! empty($f['from']), fn ($q) => $q->where('o.due_date', '>=', $f['from']))->when(! empty($f['to']), fn ($q) => $q->where('o.due_date', '<=', $f['to']));
        if (($t = trim((string) ($f['q'] ?? ''))) !== '') $q->where(fn ($w) => $w->where('c.company_name', 'like', "%$t%")->orWhere('o.title', 'like', "%$t%")->orWhere('o.submission_ref', 'like', "%$t%")
            ->orWhereRaw("TRIM(CONCAT_WS(' ', a.first_name, a.sur_name)) like ?", ["%$t%"]));
        return $q;
    }

    public static function shown(object $o, string $today, string $soonDate): string
    {
        return (int) $o->f_done ? $o->status : ($o->due_date < $today ? 'OVERDUE' : ($o->due_date <= $soonDate ? 'DUE_SOON' : ($o->status === 'IN_PROGRESS' ? 'IN_PROGRESS' : 'UPCOMING')));
    }

    /** Tabs, filters, summary per area, month calendar (?ym=YYYY-MM), page size 10–100 (default 25). */
    public static function overview(int $ent, array $f, ?string $today = null): array
    {
        $today ??= date('Y-m-d'); $soon = max(1, (int) self::setting($ent, 'due_soon_days', '30'));
        $week = date('Y-m-d', strtotime("$today +7 days")); $soonDate = date('Y-m-d', strtotime("$today +$soon days")); $month = substr($today, 0, 7);
        $tab = isset(self::TABS[$f['tab'] ?? '']) ? $f['tab'] : 'open';
        $cols = ['o.*', 'c.company_name', DB::raw("TRIM(CONCAT_WS(' ', a.first_name, a.sur_name)) as person")];
        $all = self::base($ent, $f)->limit(5000)->get(['o.id', 'o.area', 'o.due_date', 'o.f_done', 'o.status', 'o.title', 'o.updated_at', 'o.filed_date', 'o.paid_date']);
        $sum = [];
        foreach (self::AREAS as $a) $sum[$a] = ['area' => $a, 'overdue' => 0, 'week' => 0, 'soon' => 0, 'open' => 0, 'done_month' => 0, 'next_due' => null, 'next_title' => null];
        foreach ($all as $o) {
            $s = &$sum[$o->area]; if (! $s) $s = ['area' => $o->area, 'overdue' => 0, 'week' => 0, 'soon' => 0, 'open' => 0, 'done_month' => 0, 'next_due' => null, 'next_title' => null];
            if ((int) $o->f_done) { if (substr((string) ($o->paid_date ?: $o->filed_date ?: $o->updated_at), 0, 7) === $month) $s['done_month']++; unset($s); continue; }
            $s['open']++; if ($o->due_date < $today) $s['overdue']++; elseif ($o->due_date <= $week) $s['week']++;
            if ($o->due_date >= $today && $o->due_date <= $soonDate) $s['soon']++;
            if ($o->due_date >= $today && (! $s['next_due'] || $o->due_date < $s['next_due'])) { $s['next_due'] = $o->due_date; $s['next_title'] = $o->title; }
            unset($s);
        }
        $q = self::base($ent, $f);
        match ($tab) {
            'open' => $q->where('o.f_done', 0), 'overdue' => $q->where('o.f_done', 0)->where('o.due_date', '<', $today),
            'week' => $q->where('o.f_done', 0)->whereBetween('o.due_date', [$today, $week]), 'soon' => $q->where('o.f_done', 0)->whereBetween('o.due_date', [$today, $soonDate]),
            'later' => $q->where('o.f_done', 0)->where('o.due_date', '>', $soonDate), 'done' => $q->where('o.f_done', 1), default => $q,
        };
        $per = max(10, min(100, (int) ($f['per'] ?? 25) ?: 25)); $page = max(1, (int) ($f['page'] ?? 1)); $total = (clone $q)->count();
        $rows = $q->orderBy('o.due_date')->orderBy('o.id')->offset(($page - 1) * $per)->limit($per)->get($cols);
        $docs = DB::table('acc_documents')->whereIn('fk_id_obligation', $rows->pluck('id')->all() ?: [0])->where('f_deleted', 0)->selectRaw('fk_id_obligation, count(*) n')->groupBy('fk_id_obligation')->pluck('n', 'fk_id_obligation');
        $acc = collect(self::accountants($ent))->pluck('name', 'id');
        $map = fn ($o) => ['id' => (int) $o->id, 'entity' => ($o->entity_type === 'COMPANY' ? 'C' : 'P') . $o->entity_id, 'company' => $o->company_name ?: $o->person, 'auto' => (bool) $o->f_auto, 'docs' => (int) ($docs[$o->id] ?? 0),
            'type' => $o->type_code, 'area' => $o->area, 'title' => $o->title, 'period' => $o->period_start ? $o->period_start . ' → ' . $o->period_end : '', 'due' => $o->due_date, 'status' => $o->status,
            'assigned' => (int) $o->fk_assigned, 'assigned_name' => $acc[(int) $o->fk_assigned] ?? '', 'notes' => (string) $o->notes, 'ref' => (string) $o->submission_ref, 'shown' => self::shown($o, $today, $soonDate)];
        $cal = null;
        if (preg_match('/^(\d{4})-(\d{2})$/', (string) ($f['ym'] ?? ''), $m) && (int) $m[2] >= 1 && (int) $m[2] <= 12) {
            $first = "$m[1]-$m[2]-01"; $last = date('Y-m-t', strtotime($first)); $cal = ['ym' => "$m[1]-$m[2]", 'first' => $first, 'days' => (int) date('t', strtotime($first)), 'start_dow' => (int) date('N', strtotime($first)), 'items' => []];
            foreach (self::base($ent, $f)->whereBetween('o.due_date', [$first, $last])->orderBy('o.due_date')->limit(1000)->get($cols) as $o) $cal['items'][(int) substr($o->due_date, 8, 2)][] = $map($o);
        }
        return ['tab' => $tab, 'tabs' => array_merge(self::TABS, ['soon' => "Due in $soon days"]), 'total' => $total, 'page' => $page, 'per' => $per, 'rows' => $rows->map($map)->values()->all(),
            'summary' => array_values(array_filter($sum, fn ($s) => $s['open'] || $s['done_month'])), 'calendar' => $cal, 'accountants' => self::accountants($ent),
            'types' => array_values(array_map(fn ($t) => ['code' => $t->type_code, 'name' => $t->type_name, 'area' => $t->area, 'active' => (int) $t->f_active], self::types($ent)))];
    }

    /* ---------------- R-ACC-14 manual items ---------------- */

    private static function checkAssignee(int $ent, int $id): int
    {
        if ($id && ! in_array($id, array_column(self::accountants($ent), 'id'), true)) throw new \InvalidArgumentException('Choose an accountant of the Hub (a login that may change filings).');
        return $id;
    }

    public static function create(int $ent, array $d, object $u): array
    {
        $e = []; $et = strtoupper((string) ($d['entity_type'] ?? 'COMPANY')); $eid = (int) ($d['entity_id'] ?? 0);
        if (! in_array($et, ['COMPANY', 'PERSON'], true)) $e['entity_type'] = 'Choose a company or a person.';
        elseif (! $eid || ! DB::table($et === 'COMPANY' ? 'cat_company_house' : 't_account')->where('id', $eid)->exists()) $e['entity_id'] = $et === 'COMPANY' ? 'Choose the company.' : 'Choose the person.';
        $t = self::types($ent)[strtoupper((string) ($d['type_code'] ?? ''))] ?? null; if (! $t || ! (int) $t->f_active) $e['type_code'] = 'Choose the filing type.';
        $title = trim((string) ($d['title'] ?? '')); if ($title === '') $e['title'] = 'Write the title.';
        $due = Parse::date((string) ($d['due_date'] ?? '')); if (! $due) $e['due_date'] = 'Write the due date.';
        $ps = trim((string) ($d['period_start'] ?? '')) !== '' ? Parse::date($d['period_start']) : null; $pe = trim((string) ($d['period_end'] ?? '')) !== '' ? Parse::date($d['period_end']) : null;
        if ($ps === false) $e['period_start'] = 'Write a date.'; if ($pe === false) $e['period_end'] = 'Write a date.';
        if ($ps && $pe && $pe < $ps) $e['period_end'] = 'The period end is before its start.';
        try { $as = self::checkAssignee($ent, (int) ($d['fk_assigned'] ?? 0)); } catch (\InvalidArgumentException $x) { $e['fk_assigned'] = $x->getMessage(); }
        if ($e) throw new \InvalidArgumentException(reset($e));
        $id = (int) DB::table('acc_obligations')->insertGetId(['fk_id_ent' => $ent, 'entity_type' => $et, 'entity_id' => $eid, 'fk_type' => $t->id, 'type_code' => $t->type_code, 'area' => $t->area, 'completion' => $t->completion,
            'period_start' => $ps ?: null, 'period_end' => $pe ?: null, 'due_date' => $due, 'status' => 'PENDING', 'f_done' => 0, 'f_auto' => 0, 'gen_key' => null, 'title' => mb_substr($title, 0, 150),
            'fk_assigned' => $as ?? 0, 'notes' => trim((string) ($d['notes'] ?? '')) ?: null, 'created_by' => $u->id, 'created_at' => now()]);
        DB::table('acc_obligation_log')->insert(['fk_id_obligation' => $id, 'action' => 'CREATE', 'details' => 'added by hand', 'fk_id_account' => $u->id, 'created_at' => now()]);
        return ['id' => $id, 'message' => 'Added.'];
    }

    public static function delete(int $ent, int $id, object $u): array
    {
        $o = DB::table('acc_obligations')->where('id', $id)->where('fk_id_ent', $ent)->first() ?? throw new \InvalidArgumentException('Item not found.');
        if ((int) $o->f_auto) throw new \InvalidArgumentException('This item was made by the rules – it cannot be deleted; set it to Not required with a reason.');
        DB::transaction(function () use ($o, $u) {
            DB::table('acc_documents')->where('fk_id_obligation', $o->id)->update(['f_deleted' => 1]);
            DB::table('acc_obligation_log')->insert(['fk_id_obligation' => $o->id, 'action' => 'DELETE', 'details' => 'deleted: ' . $o->title . ' due ' . $o->due_date, 'fk_id_account' => $u->id, 'created_at' => now()]);
            DB::table('acc_obligations')->where('id', $o->id)->delete();
        });
        Audit::log('acc_obligations', $id, 'DELETE', ['title' => $o->title, 'due_date' => $o->due_date], 'manual filing item', $ent);
        return ['message' => 'Deleted.'];
    }

    /* ---------------- R-ACC-15 documents ---------------- */

    /** A proof document on an item ($obligation) or on a company / person. */
    public static function upload(int $ent, string $et, int $eid, int $obligation, UploadedFile $f, object $u, ?string $title = null): int
    {
        $ext = strtolower($f->getClientOriginalExtension()); $mime = strtolower((string) $f->getMimeType()); $max = max(1, (int) self::setting($ent, 'acc_hub_upload_max_mb', '10'));
        if (! isset(self::FILES[$ext])) throw new \InvalidArgumentException('Use a PDF, JPG, PNG, WEBP, CSV, XLSX or DOCX file.');
        if (! in_array($mime, self::FILES[$ext], true)) throw new \InvalidArgumentException("The file is not a real .$ext (it is $mime) – refused.");
        if ($f->getSize() > $max * 1024 * 1024) throw new \InvalidArgumentException("The file is larger than $max MB.");
        $name = $ent . '/' . md5_file($f->getRealPath()) . '.' . $ext;
        Storage::disk('local')->put('acc_documents/' . $name, (string) file_get_contents($f->getRealPath()));      /* storage/app/private: outside the web root, never linked directly */
        $orig = mb_substr($f->getClientOriginalName(), 0, 200);
        $doc = (int) DB::table('acc_documents')->insertGetId(['fk_id_ent' => $ent, 'entity_type' => $et, 'entity_id' => $eid, 'fk_id_obligation' => $obligation, 'doc_title' => mb_substr(trim((string) $title) ?: $orig, 0, 150),
            'stored_name' => $name, 'orig_name' => $orig, 'mime' => mb_substr($mime, 0, 100), 'size_bytes' => (int) $f->getSize(), 'f_deleted' => 0, 'uploaded_by' => $u->id, 'created_at' => now()]);
        if ($obligation) DB::table('acc_obligation_log')->insert(['fk_id_obligation' => $obligation, 'action' => 'DOCUMENT', 'details' => 'proof added: ' . mb_substr($orig, 0, 150), 'fk_id_account' => $u->id, 'created_at' => now()]);
        return $doc;
    }

    public static function uploadFor(int $ent, array $d, UploadedFile $f, object $u): int
    {
        $et = strtoupper((string) ($d['entity_type'] ?? '')); $eid = (int) ($d['entity_id'] ?? 0);
        if (! in_array($et, ['COMPANY', 'PERSON'], true) || ! DB::table($et === 'COMPANY' ? 'cat_company_house' : 't_account')->where('id', $eid)->exists()) throw new \InvalidArgumentException('Choose the company or the person.');
        return self::upload($ent, $et, $eid, 0, $f, $u, $d['doc_title'] ?? null);
    }

    public static function removeDocument(int $ent, int $id, object $u): array
    {
        $doc = DB::table('acc_documents')->where('id', $id)->where('fk_id_ent', $ent)->where('f_deleted', 0)->first() ?? throw new \InvalidArgumentException('Document not found.');
        DB::table('acc_documents')->where('id', $id)->update(['f_deleted' => 1]);
        if ((int) $doc->fk_id_obligation) DB::table('acc_obligation_log')->insert(['fk_id_obligation' => $doc->fk_id_obligation, 'action' => 'DOCUMENT', 'details' => 'proof removed: ' . mb_substr($doc->orig_name, 0, 150), 'fk_id_account' => $u->id, 'created_at' => now()]);
        Audit::log('acc_documents', $id, 'DELETE', ['orig_name' => $doc->orig_name], 'proof document', $ent);
        return ['message' => 'Removed.'];
    }

    /** Served after the rights check (the controller): inline for PDF / images, otherwise a download; never cached. */
    public static function serve(int $ent, int $id)
    {
        $doc = DB::table('acc_documents')->where('id', $id)->where('fk_id_ent', $ent)->where('f_deleted', 0)->first() ?? abort(404, 'Document not found.');
        $path = 'acc_documents/' . $doc->stored_name; if (! Storage::disk('local')->exists($path)) abort(404, 'The file is missing.');
        $inline = $doc->mime === 'application/pdf' || str_starts_with((string) $doc->mime, 'image/');
        return response(Storage::disk('local')->get($path), 200, ['Content-Type' => $doc->mime, 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace(['"', "\r", "\n"], '', $doc->orig_name) . '"']);
    }

    public static function documents(int $ent, array $f): array
    {
        $q = DB::table('acc_documents as d')->leftJoin('acc_obligations as o', 'o.id', '=', 'd.fk_id_obligation')->where('d.fk_id_ent', $ent)->where('d.f_deleted', 0)
            ->when(preg_match('/^([CP])(\d+)$/', (string) ($f['entity'] ?? ''), $m), fn ($q) => $q->where('d.entity_type', $m[1] === 'C' ? 'COMPANY' : 'PERSON')->where('d.entity_id', (int) $m[2]));
        if (($t = trim((string) ($f['q'] ?? ''))) !== '') $q->where(fn ($w) => $w->where('d.doc_title', 'like', "%$t%")->orWhere('d.orig_name', 'like', "%$t%")->orWhere('o.title', 'like', "%$t%"));
        $rows = $q->orderByDesc('d.id')->limit(200)->get(['d.*', 'o.title as item']); $n = self::names($rows->all());
        return ['rows' => $rows->map(fn ($d) => ['id' => (int) $d->id, 'entity' => $n[$d->entity_type][$d->entity_id] ?? '#' . $d->entity_id, 'title' => $d->doc_title, 'file' => $d->orig_name, 'item' => $d->item,
            'size_kb' => (int) ceil($d->size_bytes / 1024), 'date' => substr((string) $d->created_at, 0, 10)])->all()];
    }

    /* ---------------- R-ACC-19 companies and personal tax lists ---------------- */

    private static function counts(int $ent, string $et, string $today, string $soonDate): array
    {
        $out = [];
        foreach (DB::table('acc_obligations')->where('fk_id_ent', $ent)->where('entity_type', $et)->where('f_done', 0)->orderBy('due_date')->get(['entity_id', 'due_date', 'title']) as $o) {
            $c = &$out[(int) $o->entity_id]; $c ??= ['overdue' => 0, 'soon' => 0, 'next_due' => null, 'next_title' => null];
            if ($o->due_date < $today) $c['overdue']++; elseif ($o->due_date <= $soonDate) $c['soon']++;
            if ($o->due_date >= $today && ! $c['next_due']) { $c['next_due'] = $o->due_date; $c['next_title'] = $o->title; }
            unset($c);
        }
        return $out;
    }

    public static function companies(int $ent, array $f, ?string $today = null): array
    {
        $today ??= date('Y-m-d'); $soonDate = date('Y-m-d', strtotime("$today +" . max(1, (int) self::setting($ent, 'due_soon_days', '30')) . ' days'));
        $cnt = self::counts($ent, 'COMPANY', $today, $soonDate); $acc = collect(self::accountants($ent))->pluck('name', 'id');
        $q = DB::table('cat_company_house as c')->leftJoin('acc_company_settings as s', fn ($j) => $j->on('s.fk_id_company', '=', 'c.id')->where('s.fk_id_ent', $ent))
            ->where(fn ($w) => $w->whereNull('c.f_active')->orWhere('c.f_active', 1));
        if (($t = trim((string) ($f['q'] ?? ''))) !== '') $q->where(fn ($w) => $w->where('c.company_name', 'like', "%$t%")->orWhere('c.registration_no', 'like', "%$t%")->orWhere('c.vat_no', 'like', "%$t%")->orWhere('c.id', ctype_digit($t) ? (int) $t : -1));
        $rows = $q->orderBy('c.company_name')->limit(500)->get(['c.id', 'c.company_name', 'c.registration_no', 'c.vat_no', 's.f_tracked', 's.vat_registered', 's.paye_registered', 's.fk_accountant'])
            ->map(fn ($c) => ['id' => (int) $c->id, 'name' => $c->company_name, 'reg_no' => $c->registration_no, 'vat_no' => $c->vat_no, 'tracked' => $c->f_tracked === null ? true : (bool) $c->f_tracked,
                'vat' => (bool) $c->vat_registered, 'paye' => (bool) $c->paye_registered, 'accountant' => $acc[(int) $c->fk_accountant] ?? '', ] + ($cnt[(int) $c->id] ?? ['overdue' => 0, 'soon' => 0, 'next_due' => null, 'next_title' => null]));
        if (($f['filter'] ?? '') === 'overdue') $rows = $rows->filter(fn ($r) => $r['overdue'] > 0);
        if (($f['filter'] ?? '') === 'untracked') $rows = $rows->filter(fn ($r) => ! $r['tracked']);
        return ['rows' => $rows->values()->all()];
    }

    /** People = directors 1–3 of active companies + anyone with Self Assessment settings; one row per person with every company name. */
    public static function people(int $ent, array $f, ?string $today = null): array
    {
        $today ??= date('Y-m-d'); $soonDate = date('Y-m-d', strtotime("$today +" . max(1, (int) self::setting($ent, 'due_soon_days', '30')) . ' days'));
        $cos = [];
        foreach (DB::table('cat_company_house')->where(fn ($w) => $w->whereNull('f_active')->orWhere('f_active', 1))->get(['company_name', 'fk_director_1', 'fk_director_2', 'fk_director_3']) as $c)
            foreach ([1, 2, 3] as $i) if ((int) $c->{"fk_director_$i"} > 0) $cos[(int) $c->{"fk_director_$i"}][] = (string) $c->company_name;
        $ps = DB::table('acc_person_settings')->whereIn('fk_id_ent', [$ent, 0])->get()->keyBy('fk_id_account');
        $ids = array_unique(array_merge(array_keys($cos), $ps->keys()->map(fn ($x) => (int) $x)->all()));
        $cnt = self::counts($ent, 'PERSON', $today, $soonDate); $t = mb_strtolower(trim((string) ($f['q'] ?? '')));
        $rows = DB::table('t_account')->whereIn('id', $ids ?: [0])->get(['id', 'account_code', 'first_name', 'sur_name'])->map(function ($a) use ($cos, $ps, $cnt) {
            $s = $ps[$a->id] ?? null; $n = array_values(array_unique($cos[$a->id] ?? [])); sort($n);
            return ['id' => (int) $a->id, 'code' => $a->account_code, 'name' => trim($a->first_name . ' ' . $a->sur_name), 'companies' => $n, 'sa' => (bool) ($s->sa_registered ?? 0), 'utr_last4' => $s->utr_last4 ?? null]
                + ($cnt[(int) $a->id] ?? ['overdue' => 0, 'soon' => 0, 'next_due' => null, 'next_title' => null]);
        })->filter(fn ($r) => $t === '' || str_contains(mb_strtolower($r['name'] . ' ' . $r['code'] . ' ' . implode(' ', $r['companies'])), $t))->sortBy('name')->values()->all();
        return ['rows' => $rows];
    }
}
