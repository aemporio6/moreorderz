<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Own-item pickers (R-C-20, `own:` source). Every "linked …" picker on a person's sub-list lists ONLY that person's own active items
 * (Chains::options), with the rule's labels, and the server refuses an id that is not the person's own ("This mobile does not belong to this person").
 * Labels: address "Home/Office county country line1"; mobile "code-number device"; bank account "acc(sort) (iban)"; card "acc(sort) •••• last4"
 * – account numbers and IBANs are shown masked (last 4) because they are secrets in this tool.
 * A person saved as a DRAFT (first "+ Add" on a person not yet saved) never appears in pickers of other forms.
 */
class OwnItems
{
    public const THING = ['t_account_mobiles' => 'mobile', 't_account_emails' => 'e-mail', 't_account_addresses' => 'address', 't_account_banks' => 'bank account', 't_account_credit_cards' => 'card',
        't_account_doc' => 'document', 't_account_photo_ids' => 'photo ID', 't_account_company_houses' => 'company', 't_account_ips' => 'IP account'];

    private static function last4(?string $v): string { $d = preg_replace('/[^0-9A-Za-z]/', '', (string) $v); return $d === '' ? '' : '••' . substr($d, -4); }

    private static function listLabel(string $list, $v): string
    {
        if ($v === null || $v === '') return '';
        return (string) (DB::table('sys_field_options')->where('list_code', $list)->where('value', (string) $v)->value('label') ?? $v);
    }

    /** The rule's label of one own item, or null when the table has no special label. */
    public static function label(string $table, object $r): ?string
    {
        $country = fn ($id, $col) => $id ? (string) DB::table('cat_country')->where('id', $id)->value($col) : '';
        return match ($table) {
            't_account_addresses' => trim(implode(' ', array_filter([self::listLabel('ADDRESS_TYPE', $r->l_address_type ?? null), $r->counti ?? '', $country($r->fk_id_country ?? null, 'country_name'), $r->line1 ?? ''], fn ($x) => trim((string) $x) !== ''))),
            't_account_mobiles' => trim(implode('-', array_filter([($cc = ltrim($country($r->fk_id_country ?? null, 'country_code'), '+')) !== '' ? '+' . $cc : '', $r->mobile_no ?? ''], fn ($x) => trim((string) $x) !== '')) . (($d = ($r->fk_id_mobile_device ?? null) ? DB::table('t_mobile_devices')->where('id', $r->fk_id_mobile_device)->value('mobile_name') : '') ? ' ' . $d : '')),
            't_account_credit_cards' => trim(self::last4($r->account_no ?? '') . (! empty($r->sort_branch_code) ? '(' . $r->sort_branch_code . ')' : '') . ' •••• ' . ($r->card_last4 ?: substr(preg_replace('/\D/', '', (string) ($r->front_four_digits ?? '')), -4))),
            default => null,
        };
    }

    /** Which person a save belongs to (null = not a person sub-list). */
    public static function person(string $table, array $data, array $old, ?int $id): ?int
    {
        if ($table === 't_account') return $id ?: null;
        if (! Registry::hasColumn($table, 'fk_id_account')) return null;
        $p = $data['fk_id_account'] ?? $old['fk_id_account'] ?? null;
        return $p ? (int) $p : null;
    }

    /** First "+ Add" of an own item on a person not yet saved: save the person as a DRAFT (required checks wait for the final Save, which sets ACTIVE). */
    public static function draft(string $formKey, array $values): int
    {
        if (Registry::table($formKey) !== 't_account') throw new \InvalidArgumentException('Only a person can be saved as a draft.');
        $row = [];
        foreach (Registry::fields($formKey) as $f) { $k = array_key_exists($f['field_name'], $values) ? $f['field_name'] : (array_key_exists($f['column_name'], $values) ? $f['column_name'] : null);
            if ($k !== null && Registry::hasColumn('t_account', $f['column_name']) && ! is_array($values[$k]) && $values[$k] !== '' && ! str_starts_with((string) $f['source'], 'own:')) $row[$f['column_name']] = $values[$k]; }
        $row += ['fk_id_ent' => Context::ent(), 'f_active' => 1];
        $id = (int) DB::table('t_account')->insertGetId(RecordService::fillNotNull('t_account', $row + ['record_status' => 'DRAFT']));
        Audit::log('t_account', $id, 'INSERT', ['record_status' => 'DRAFT'], 'Saved as a draft by "+ Add" of an own item');
        return $id;
    }

    /** Server check of every own: field: the chosen ids must be the person's own rows. Returns [field => message]. */
    public static function check(string $formKey, array $data, array $old, ?int $id): array
    {
        $table = Registry::table($formKey); if (! $table) return [];
        $own = array_filter(Registry::fields($formKey), fn ($f) => str_starts_with((string) $f['source'], 'own:t_account_'));
        if (! $own) return [];
        $person = self::person($table, $data, $old, $id); $isPersonTable = $table === 't_account' || Registry::hasColumn($table, 'fk_id_account');
        if (! $isPersonTable) return [];
        $e = [];
        foreach ($own as $f) {
            $c = $f['column_name']; if (! array_key_exists($c, $data)) continue;
            if (! in_array(\Illuminate\Support\Facades\Schema::getColumnType($table, $c), ['integer', 'bigint', 'smallint', 'int', 'tinyint', 'mediumint'], true)) continue;      // a text column (e.g. a typed number) is not an id
            $ids = array_filter(array_map('trim', explode(',', (string) $data[$c])), fn ($x) => $x !== '' && $x !== '0');
            if (! $ids) continue;
            $t = substr((string) $f['source'], 4); if (! Registry::hasColumn($t, 'fk_id_account')) continue;
            if (($f['default_rule'] ?? '') === 'session.company') $ids = array_values(array_filter($ids, fn ($x) => (int) $x !== (int) Context::ent()));      // the form's own default (the working company) is allowed
            if (! $ids) continue;
            $mine = $person ? DB::table($t)->whereIn('id', array_map('intval', $ids))->where('fk_id_account', $person)->count() : 0;
            if ($mine !== count(array_unique($ids))) $e[$f['field_name']] = 'This ' . (self::THING[$t] ?? 'item') . ' does not belong to this person';
        }
        return $e;
    }
}
