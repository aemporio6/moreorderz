<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-C-2 a person is a Managed Account (M) or an External Contact (E) – "Convert" on the person header, and the sections each kind shows.
 * - convert(): right people.convert; one transaction: t_account.l_account_kind, t_account_kind_log (from, to, note, who, when),
 *   the central history line and the event PERSON_KIND_CHANGED. Converting to the kind the person already has does nothing. Nothing is deleted.
 * - sections: sys_form_sections.visible_kind (ALL | M | E), kept in Settings (not in a config file). A section of the person form is a row of
 *   form_key t_account; a tab (child list: banks, PCs …) is a row of its own form key with section_no 0.
 *   A hidden section keeps its data, is not shown, and is not accepted on save for that kind (server check).
 */
class PersonKind
{
    public const LABEL = ['M' => 'Managed Account', 'E' => 'External Contact'];
    public const BADGE = ['M' => 'Managed Account', 'E' => 'External Contact — Short profile'];
    public const CONFIRM = ['E' => 'This person becomes an External Contact. Only the tabs a supplier needs will show. Nothing is deleted.', 'M' => 'This person becomes a Managed Account. All profile tabs will show. Nothing is deleted.'];
    /** The tabs an External Contact keeps (old ec_tabs) – the first fill of the settings; everything else starts as M only. */
    public const E_TABLES = ['t_account_addresses', 't_account_mobiles', 't_account_emails', 't_account_banks', 't_account_company_houses', 't_account_paypal', 't_account_social_media', 't_account_in_types', 't_account_job_history'];
    public const E_SECTIONS = ['Home', 'Profile'];
    private static ?array $rows = null;

    public static function of(int $id): string { return strtoupper(substr((string) DB::table('t_account')->where('id', $id)->value('l_account_kind'), 0, 1)) === 'E' ? 'E' : 'M'; }
    public static function forget(): void { self::$rows = null; }

    /** The first fill (migration) – one row per section of the person form and per tab; never overwrites a row that is there. */
    public static function seed(): int
    {
        $n = 0; $add = function (string $form, int $no, string $title, string $kind) use (&$n) {
            if (DB::table('sys_form_sections')->where('form_key', $form)->where('section_no', $no)->exists()) return;
            DB::table('sys_form_sections')->insert(RecordService::fillNotNull('sys_form_sections', ['form_key' => $form, 'section_no' => $no, 'title' => $title, 'visible_kind' => $kind])); $n++; };
        $seen = [];
        foreach (DB::table('sys_registry')->where('form_key', 't_account')->orderBy('section_no')->get(['section_no', 'section']) as $r) { if (isset($seen[$r->section_no])) continue; $seen[$r->section_no] = 1; $add('t_account', (int) $r->section_no, (string) $r->section, in_array($r->section, self::E_SECTIONS, true) ? 'ALL' : 'M'); }
        foreach (DB::table('sys_registry')->where('table_name', 'like', 't\_account\_%')->where('column_name', 'fk_id_account')->orderBy('form_key')->get(['form_key', 'form_title', 'table_name']) as $r) {
            if (Registry::table($r->form_key) !== $r->table_name || RecordService::formOfTable($r->table_name) !== $r->form_key) continue;
            $add($r->form_key, 0, (string) $r->form_title, in_array($r->table_name, self::E_TABLES, true) ? 'ALL' : 'M');
        }
        self::forget(); return $n;
    }

    public static function rows(): array
    {
        return self::$rows ??= DB::table('sys_form_sections')->whereNotNull('visible_kind')->orderByRaw("form_key <> 't_account'")->orderBy('title')->orderBy('section_no')->get(['id', 'form_key', 'section_no', 'title', 'visible_kind'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'form_key' => $r->form_key, 'section_no' => (int) $r->section_no, 'title' => $r->title, 'visible_kind' => $r->visible_kind, 'what' => $r->form_key === 't_account' ? 'Section of the person form' : 'Tab', 'table' => $r->form_key === 't_account' ? null : Registry::table($r->form_key)])->all();
    }

    private static function shows(string $visible, string $kind): bool { return $visible === 'ALL' || $visible === $kind; }
    /** Section titles of the person form that this kind does not show. */
    public static function hiddenSections(string $kind): array { return array_values(array_map(fn ($r) => $r['title'], array_filter(self::rows(), fn ($r) => $r['form_key'] === 't_account' && ! self::shows($r['visible_kind'], $kind)))); }
    /** Tables (tabs) that this kind does not show. */
    public static function hiddenTables(string $kind): array { return array_values(array_filter(array_map(fn ($r) => $r['table'], array_filter(self::rows(), fn ($r) => $r['form_key'] !== 't_account' && ! self::shows($r['visible_kind'], $kind))))); }

    /** Settings: who sees which section. Owner / Manager. */
    public static function setVisible(int $rowId, string $visible, object $u): array
    {
        Perm::need($u, 'people.convert');
        if (! in_array($visible, ['ALL', 'M', 'E'], true)) throw new \InvalidArgumentException('Shown for: ALL, M (Managed only) or E (External only).');
        $r = DB::table('sys_form_sections')->where('id', $rowId)->first() ?? throw new \InvalidArgumentException('Section not found.');
        if ($r->form_key === 't_account' && in_array($r->title, self::E_SECTIONS, true) && $visible !== 'ALL') throw new \InvalidArgumentException("{$r->title} is shown for every person – it holds the name and code.");
        DB::table('sys_form_sections')->where('id', $rowId)->update(['visible_kind' => $visible]);
        Audit::log('sys_form_sections', $rowId, 'UPDATE', ['visible_kind' => [$r->visible_kind, $visible]], null, Context::ent());
        self::forget();
        return ['message' => "{$r->title}: shown for " . ($visible === 'ALL' ? 'everyone' : self::LABEL[$visible] . ' only') . '.', 'rows' => self::rows()];
    }

    /** RecordService hook: what a save may not touch for this person's kind. Returns errors (child tab) or drops the fields (person form). */
    public static function guard(string $table, string $formKey, array &$data, array $old, ?int $id): array
    {
        if ($table === 't_account') {
            if (! $id) return [];
            $kind = strtoupper(substr((string) ($old['l_account_kind'] ?? 'M'), 0, 1)) === 'E' ? 'E' : 'M';
            if (! ($hidden = self::hiddenSections($kind))) return [];
            foreach (Registry::fields($formKey) as $f) if ($f['column_name'] && in_array($f['section'], $hidden, true)) unset($data[$f['column_name']]);
            return [];
        }
        if (! str_starts_with($table, 't_account_')) return [];
        $person = (int) ($data['fk_id_account'] ?? $old['fk_id_account'] ?? 0); if (! $person) return [];
        $kind = self::of($person);
        if (in_array($table, self::hiddenTables($kind), true)) return ['fk_id_account' => 'This section is not used for ' . ($kind === 'E' ? 'an External Contact' : 'a Managed Account') . '. Convert the person first (person header › Convert).'];
        return [];
    }

    public static function convert(int $id, string $to, ?string $note, object $u): array
    {
        Perm::need($u, 'people.convert');
        $to = strtoupper(trim($to)); if (! isset(self::LABEL[$to])) throw new \InvalidArgumentException('Could not change the account type.');
        $note = trim((string) $note); if (mb_strlen($note) > 255) throw new \InvalidArgumentException('The note is 255 characters at most.');
        $p = DB::table('t_account')->where('id', $id)->where('fk_id_ent', Context::ent())->first(['id', 'account_code', 'l_account_kind']) ?? throw new \InvalidArgumentException('Could not change the account type.');
        $from = self::of($id); $a = fn ($k) => ($k === 'E' ? 'an ' : 'a ') . self::LABEL[$k];
        if ($from === $to) return ['changed' => false, 'kind' => $to, 'badge' => self::BADGE[$to], 'message' => "Account {$p->account_code} is already " . $a($to) . '.'];
        DB::transaction(function () use ($id, $from, $to, $note, $u) {
            DB::table('t_account')->where('id', $id)->update(['l_account_kind' => $to]);
            DB::table('t_account_kind_log')->insert(['fk_id_account' => $id, 'from_kind' => $from, 'to_kind' => $to, 'note' => $note, 'changed_by' => $u->id, 'changed_at' => now()]);
            Audit::log('t_account', $id, 'UPDATE', ['l_account_kind' => [$from, $to]], 'Converted to ' . self::LABEL[$to] . ($note !== '' ? ": $note" : ''), Context::ent());
            EventBus::fire('PERSON_KIND_CHANGED', 't_account', $id, ['from' => $from, 'to' => $to, 'note' => $note], Context::ent());
        });
        return ['changed' => true, 'kind' => $to, 'badge' => self::BADGE[$to], 'message' => "Account {$p->account_code} is now " . $a($to) . '.'];
    }

    public static function log(int $id): array
    {
        return DB::table('t_account_kind_log as l')->leftJoin('t_users as u', 'u.id', '=', 'l.changed_by')->where('l.fk_id_account', $id)->orderByDesc('l.id')->get(['l.from_kind', 'l.to_kind', 'l.note', 'l.changed_at', 'u.display_name'])
            ->map(fn ($r) => ['from' => self::LABEL[$r->from_kind] ?? $r->from_kind, 'to' => self::LABEL[$r->to_kind] ?? $r->to_kind, 'note' => $r->note, 'at' => $r->changed_at, 'by' => $r->display_name ?: 'System'])->all();
    }
}
