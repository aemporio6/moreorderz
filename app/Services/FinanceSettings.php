<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-FIN-4 finance settings per company (fin_company_settings) and R-FIN-6 finance system settings (sys_settings), Owner only, every change in
 * the history (who, when, old → new). A company without its own row uses the system defaults (free cash limit, payout tolerance, slow / dead days).
 * Every finance service reads its thresholds through value(), so one place decides (alerts, matching, snapshot, profit list, statements).
 */
class FinanceSettings
{
    public const OWNER_ONLY = 'Refused: only the Owner changes settings.';
    public const SCHEDULES = ['PER_PERIOD', 'MONTHLY', 'QUARTERLY'];

    /** R-FIN-6 system keys: key => [label, type, default, help, extra]. */
    public static function system(): array
    {
        $days = fn (string $l, string $d, string $h) => [$l, 'int', $d, $h, ['min' => 0, 'max' => 3650]];
        return [
            'free_cash_alert_limit' => ['Free cash alert limit', 'decimal', '10000', 'Alert FA1 when free cash (bank − committed) is below this amount.', ['min' => 0, 'max' => 100000000]],
            'payout_tolerance' => ['Payout tolerance', 'decimal', '1', 'A payout and its bank deposit count as equal up to this difference (matching and alert FA2).', ['min' => 0, 'max' => 10000]],
            'slow_days' => $days('Slow stock after (days without a sale)', '90', 'A SKU with stock value and no sale for this many days is SLOW.'),
            'dead_days' => $days('Dead stock after (days without a sale)', '180', 'A SKU with stock value and no sale for this many days (or never sold) is DEAD.'),
            'alert_repeat_days' => ['Send an open alert again every (days)', 'int', '2', 'While a finance alert stays true it is sent again after this many days (at least 1).', ['min' => 1, 'max' => 365]],
            'period_open_days' => $days('Month still open alert after (days)', '10', 'Alert FA10 when a month is still open this many days after it ended.'),
            'settlement_overdue_days' => $days('Settlement report overdue after (days)', '17', 'Task / alert when a shop has no settlement for this many days.'),
            'bank_overdue_days' => $days('Bank file overdue after (days)', '8', 'Task / alert when a bank account has no new lines for this many days.'),
            'large_fee_gbp' => ['Large fee from', 'decimal', '250', 'Alert FA6 for a fee line of this size or more in the last 30 days.', ['min' => 0, 'max' => 1000000]],
            'agreement_ending_days' => $days('Agreement ending alert (days before)', '30', 'Alert FA8 this many days before a partner agreement ends.'),
            'supplier_due_days' => $days('Supplier payment alert (days before due)', '3', 'Alert FA9 this many days before a supplier payment is due (late ones too).'),
            'match_payout_days' => $days('Match payouts within (± days)', '3', 'A payout is matched to a bank deposit within this many days of its payout date.'),
            'match_supplier_days' => $days('Match supplier payments within (± days)', '5', 'A supplier payment is matched to a bank line within this many days.'),
            'match_supplier_pct' => ['Match supplier payments within (± %)', 'pct', '2', 'A supplier payment in another currency is matched when the amounts differ by at most this %.'],
            'match_withdrawal_days' => $days('Match withdrawals within (± days)', '5', 'A partner withdrawal is matched to a bank line within this many days.'),
            'match_intercompany_days' => $days('Match transfers between companies within (± days)', '2', 'Two lines of a transfer between own accounts are paired within this many days.'),
            'statement_folder' => ['Statement folder', 'text', 'statements', 'Folder for statement PDFs in the private file store – never inside the web root.', ['max' => 120, 'pattern' => '/^(?!\/)(?!.*\.\.)(?!public\b)[A-Za-z0-9_\/-]+$/', 'hint' => 'A folder name in the private file store, e.g. statements (no "..", no leading "/", not public).']],
            'app_url' => ['Address of the system', 'text', '', 'Used in links in messages and exports. Empty = the address of this site.', ['max' => 190, 'optional' => true, 'pattern' => '/^https?:\/\/[^\s]+$/', 'hint' => 'A full address starting with https://']],
        ];
    }

    /** R-FIN-4 company fields: field => [label, type, column default, help]. */
    public static function company(): array
    {
        return [
            'display_name' => ['Display name', 'text', '', 'Used as the company name where the company record is not linked.'],
            'vat_rate' => ['VAT rate (%) for the tax reserve', 'pct', '0', 'Part of the tax reserve taken before profit is shared.'],
            'corp_tax_rate' => ['Corporation tax rate (%) for the tax reserve', 'pct', '19', 'Part of the tax reserve taken before profit is shared.'],
            'free_cash_alert_limit' => ['Free cash alert limit', 'decimal', null, 'Empty row = the system setting.'],
            'payout_tolerance' => ['Payout tolerance', 'decimal', null, 'Empty row = the system setting.'],
            'distribution_schedule' => ['Distribution schedule', 'enum', 'PER_PERIOD', 'When partner profit is shared out.'],
            'slow_days' => ['Slow stock after (days)', 'int', null, 'Empty row = the system setting.'],
            'dead_days' => ['Dead stock after (days)', 'int', null, 'Empty row = the system setting.'],
            'fx_source' => ['Exchange rate source', 'text', 'daily table', 'Where the daily rates come from.'],
            'is_active' => ['Active', 'yn', '1', 'Finance runs for this company.'],
        ];
    }

    /** One finance setting for a company: its own row first (company fields), then the system setting, then the code default. */
    public static function value(int $ent, string $key): string
    {
        if (isset(self::company()[$key]) && $ent) {
            $row = (array) (DB::table('fin_company_settings')->where('company_id', $ent)->first() ?? []);
            if ($row && $row[$key] !== null && $row[$key] !== '') return (string) $row[$key];
        }
        $def = self::system()[$key] ?? null;
        $v = Settings::get($key, $ent, null);
        if ($v === null || $v === '') return (string) ($def[2] ?? self::company()[$key][2] ?? '');
        if ($key === 'alert_repeat_days') return (string) max(1, (int) $v);
        return (string) $v;
    }

    public static function int(int $ent, string $key): int { return (int) self::value($ent, $key); }
    public static function num(int $ent, string $key): float { return (float) self::value($ent, $key); }

    public static function appUrl(): string { return rtrim((string) (self::value(0, 'app_url') ?: Settings::get('APP_URL', null, '') ?: config('app.url') ?: url('/')), '/'); }

    public static function page(int $ent): array
    {
        $row = (array) (DB::table('fin_company_settings')->where('company_id', $ent)->first() ?? []);
        $company = [];
        foreach (self::company() as $k => [$label, $type, $def, $help])
            $company[] = ['key' => $k, 'label' => $label, 'type' => $type, 'help' => $help, 'value' => $row ? (string) ($row[$k] ?? '') : (string) ($def ?? self::value($ent, $k)), 'options' => $type === 'enum' ? self::SCHEDULES : null];
        $system = [];
        foreach (self::system() as $k => $d) $system[] = ['key' => $k, 'label' => $d[0], 'type' => $d[1], 'default' => $d[2], 'help' => $d[3], 'value' => self::value(0, $k), 'min' => $d[4]['min'] ?? null, 'max' => $d[4]['max'] ?? null];
        return ['company_saved' => (bool) $row, 'company' => $company, 'system' => $system,
            'history' => array_merge(TypedSettings::history(0, array_keys(self::system()), 'FINANCE_SETTING'), self::companyHistory($ent))];
    }

    private static function companyHistory(int $ent): array
    {
        return DB::table('sys_audit_log as a')->leftJoin('t_users as u', 'u.id', '=', 'a.user_id')->where('a.table_name', 'fin_company_settings')->where('a.record_id', $ent)->orderByDesc('a.id')->limit(50)
            ->get(['a.changes_json', 'a.created_at', 'u.display_name'])->flatMap(function ($r) { $out = []; foreach (json_decode((string) $r->changes_json, true) ?: [] as $k => $c) $out[] = ['key' => $k, 'from' => $c[0] ?? null, 'to' => $c[1] ?? null, 'by' => $r->display_name, 'at' => (string) $r->created_at]; return $out; })->all();
    }

    /** R-FIN-6: one system key (global), Owner only, history. */
    public static function saveSystem(string $key, $value): array
    {
        if (Context::role() !== 'OWNER') throw new \InvalidArgumentException(self::OWNER_ONLY);
        $d = self::system()[$key] ?? throw new \InvalidArgumentException("Unknown setting $key");
        [$err, $v] = TypedSettings::check(0, $key, $d, $value);
        if ($err) throw new ValidationErrors(['value' => $err]);
        return TypedSettings::store(0, $key, $d[0], self::value(0, $key), $v, 'FINANCE_SETTING');
    }

    /** R-FIN-4: the company's finance settings, Owner only, old texts for the checks, audited (old → new per field). */
    public static function saveCompany(int $ent, array $d): array
    {
        if (Context::role() !== 'OWNER') throw new \InvalidArgumentException(self::OWNER_ONLY);
        if (! $ent) throw new ValidationErrors(['company' => 'Company is required.']);
        $e = [];
        foreach (['vat_rate', 'corp_tax_rate'] as $k) if (array_key_exists($k, $d) && $d[$k] !== '' && $d[$k] !== null && (! is_numeric($d[$k]) || (float) $d[$k] < 0 || (float) $d[$k] > 100)) $e[$k] = "$k must be between 0 and 100.";
        if (array_key_exists('distribution_schedule', $d) && ! in_array(strtoupper((string) $d['distribution_schedule']), self::SCHEDULES, true)) $e['distribution_schedule'] = 'Distribution schedule must be PER_PERIOD, MONTHLY or QUARTERLY.';
        foreach (['free_cash_alert_limit', 'payout_tolerance'] as $k) if (array_key_exists($k, $d) && $d[$k] !== '' && $d[$k] !== null && (! is_numeric($d[$k]) || (float) $d[$k] < 0)) $e[$k] = 'Enter an amount of 0 or more.';
        foreach (['slow_days', 'dead_days'] as $k) if (array_key_exists($k, $d) && $d[$k] !== '' && $d[$k] !== null && ! preg_match('/^\d+$/', (string) $d[$k])) $e[$k] = 'Enter a whole number of days.';
        if ($e) throw new ValidationErrors($e);
        $old = (array) (DB::table('fin_company_settings')->where('company_id', $ent)->first() ?? []);
        $set = [];
        foreach (self::company() as $k => [, $type, $def]) {
            if (! array_key_exists($k, $d)) continue;
            $v = $d[$k] === '' ? null : $d[$k];
            if ($type === 'enum') $v = strtoupper((string) $v); if ($type === 'yn') $v = (int) (bool) $v; if ($type === 'text' && $v !== null) $v = mb_substr(trim((string) $v), 0, $k === 'display_name' ? 120 : 60);
            if ($v === null && in_array($k, ['free_cash_alert_limit', 'payout_tolerance', 'slow_days', 'dead_days'], true)) $v = self::value(0, $k);      // the row keeps a number; empty = today's system value
            $set[$k] = $v;
        }
        $changes = [];
        foreach ($set as $k => $v) if ((string) ($old[$k] ?? '') !== (string) ($v ?? '')) $changes[$k] = [$old[$k] ?? null, $v];
        if ($old) { if ($set) DB::table('fin_company_settings')->where('company_id', $ent)->update($set); }
        else DB::table('fin_company_settings')->insert(['company_id' => $ent] + $set);
        if ($changes) Audit::log('fin_company_settings', $ent, $old ? 'UPDATE' : 'INSERT', $changes, 'Finance settings', $ent);
        return ['message' => $changes ? 'Finance settings saved.' : 'No change.', 'changes' => count($changes)];
    }
}
