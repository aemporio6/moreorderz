<?php

namespace App\Services;

/**
 * R-G_CORE-62 one Mask service, driven by the field rights: a field the person may not see (registry visible_to such as OWNER_PARTNER / FINANCE, or a field right HIDDEN)
 * is removed from rows, answers and exports (the key is not there – not empty), shows "(hidden)" in history, and money in message texts "£123.45" becomes "£•••"
 * for anyone without the money right. Used by the List / Form standard (RecordService), exports, the record history, the message log, search and the task answers.
 */
class Mask
{
    /** May this person see money (the money right, or a Partner)? */
    public static function money(?object $u): bool
    {
        if (! $u) return true;      // a job without a person
        return in_array($u->role_code ?? '', ['OWNER', 'PARTNER'], true) || Perm::has($u, 'see_money');
    }

    public static function text(?string $text, ?object $u): string
    {
        return self::money($u) ? (string) $text : (string) preg_replace('/£\s?-?[\d,]+(?:\.\d+)?/u', '£•••', (string) $text);
    }

    /** R-FIN-11 the money keys removed (not blanked) from every answer and export for a person without the money right. */
    public const MONEY_KEYS = ['amount', 'amount_gbp', 'balance', 'free_cash', 'bank_balance', 'committed', 'unit_cost_gbp', 'cogs', 'cogs_fifo', 'landed_cost_per_unit'];

    public static function stripMoney($d)
    {
        if (! is_array($d)) return $d;
        foreach ($d as $k => $v) {
            if (is_string($k) && in_array($k, self::MONEY_KEYS, true)) { unset($d[$k]); continue; }
            if ($k === 'changes' && is_array($v)) { foreach ($v as $ck => $cv) if (in_array($ck, self::MONEY_KEYS, true)) $v[$ck] = [null, '(hidden)']; $d[$k] = $v; continue; }      // history keeps the line, the values read "(hidden)"
            if (is_array($v)) $d[$k] = self::stripMoney($v);
        }
        return $d;
    }

    /** Column names of a form the person may not see. */
    public static function hidden(string $formKey, ?object $u): array
    {
        $role = $u->role_code ?? Context::role(); $out = [];
        foreach (Registry::fields($formKey) as $f) if ($f['column_name'] && empty($f['secret']) && ! Registry::visible($f, $role)) $out[] = $f['column_name'];      // secrets: the Vault shows "••••" + Reveal
        return array_values(array_unique($out));
    }

    /** A row without the hidden keys. */
    public static function row(string $formKey, array $row, ?object $u): array
    {
        $row = array_diff_key($row, array_flip(self::hidden($formKey, $u)));
        if (Registry::table($formKey) === 't_account_banks' && isset($row['fk_id_account']) && ! PersonRules::mayPersonal($u) && PersonRules::isStaff((int) $row['fk_id_account']))
            $row = array_diff_key($row, array_flip(PersonRules::STAFF_BANK));      // D7: a staff member's bank numbers are personal data (HR right)
        return $row;
    }

    /** History changes {column: [old, new]}: a hidden field keeps its line, the values read "(hidden)". */
    public static function history(string $formKey, ?array $changes, ?object $u): ?array
    {
        if (! $changes) return $changes;
        $h = array_flip(self::hidden($formKey, $u));
        foreach ($changes as $k => $v) if (isset($h[$k])) $changes[$k] = is_array($v) ? array_map(fn () => '(hidden)', $v) : '(hidden)';
        return $changes;
    }
}
