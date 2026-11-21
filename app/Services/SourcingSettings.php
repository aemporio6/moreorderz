<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Settings › Sourcing (R-B_SOURCING-51): one typed list at company level, every change with history (who, when, old → new).
 * Types: currency (an active currency code), int (whole number in a range), decimal, pct (0 – 100), choice (the landed-cost choices of Choices::LIST),
 * people (a list of active people – old DASH_PARTNER / DASH_MANAGER account ids), role (a role code of the roles master).
 * VOLUMETRIC_DIVISOR ≤ 0 is never stored (old data ≤ 0 → 5000); per shipping mode VOLUMETRIC_DIVISOR_<MODE> may be left empty (= the general one).
 */
class SourcingSettings
{
    public const MODES = ['SEA', 'AIR', 'ROAD', 'RAIL', 'COURIER'];

    /** key => [label, type, default, help, extra] */
    public static function defs(): array
    {
        $d = [
            'BASE_CURRENCY' => ['Base currency', 'currency', 'GBP', 'All money of sourcing is shown and booked in this currency.'],
            'VOLUMETRIC_DIVISOR' => ['Volumetric divisor', 'int', '5000', 'Chargeable weight = L × W × H (cm) ÷ this number. Old default 5000.', ['min' => 1, 'max' => 100000]],
        ];
        foreach (self::MODES as $m) $d['VOLUMETRIC_DIVISOR_' . $m] = ['Volumetric divisor – ' . ucfirst(strtolower($m)), 'int', '', 'Empty = the general divisor. A carrier\'s own divisor wins over this.', ['min' => 1, 'max' => 100000, 'optional' => true]];
        return $d + [
            'LC_FREIGHT_METHOD' => ['Landed cost: freight spread by', 'choice', 'QUANTITY', 'As the old tool: by quantity.'],
            'LC_DUTY_METHOD' => ['Landed cost: customs duty spread by', 'choice', 'VALUE', 'As the old tool: by value.'],
            'LC_LOSS_UNITS' => ['Landed cost: damaged and missing units', 'choice', 'ABSORB', 'ABSORB = the accepted units carry them (old behaviour).'],
            'LC_OVERRIDE_APPROVAL_PCT' => ['Landed cost override needs Owner approval above (%)', 'pct', '10', 'A manual landed cost further than this from the computed cost waits for the Owner (owner to confirm).'],
            'SRC_MONEY_TOL' => ['Money tolerance', 'decimal', '0.009', 'Differences up to this amount count as equal (old hard-coded 0.009).', ['min' => 0, 'max' => 1]],
            'SRC_QC_PASS_GREEN' => ['QC pass rate shown green from (%)', 'pct', '95', 'A quality check with this pass rate or more is shown green (old hard-coded 95).'],
            'SRC_PAY_REMIND_DAYS' => ['Supplier payment reminder (days before due)', 'int', '3', 'Task SRC_PAY_DUE opens this many days before a payment term is due (owner to confirm).', ['min' => 0, 'max' => 60]],
            'SRC_SHIP_ARRIVING_DAYS' => ['Shipment arriving alert (days before ETA)', 'int', '3', 'Task SRC_SHIP_ARRIVING opens this many days before the ETA (owner to confirm).', ['min' => 0, 'max' => 60]],
            'DASH_PARTNER' => ['Partners on the sourcing dashboard', 'people', '', 'Old list of partner account ids; the PARTNER role members are the source from now on.'],
            'DASH_MANAGER' => ['Managers on the sourcing dashboard', 'people', '', 'Old list of manager account ids used by Tasks; the MANAGER role is the source from now on.'],
            'INVESTOR_ROLE' => ['Investor role', 'role', '', 'Which role marks a person as an investor in sourcing (0 / empty = none).'],
        ];
    }

    private static function value(string $key, int $ent, array $def)
    {
        if ($def[1] === 'choice') { $v = strtoupper((string) Choices::get($key, $ent)); return isset(Choices::LIST[$key][3][$v]) ? $v : $def[2]; }
        $v = Settings::get($key, $ent, null);
        return $v === null || $v === '' ? $def[2] : (string) $v;
    }

    public static function page(int $ent): array
    {
        $rows = [];
        foreach (self::defs() as $k => $d) {
            $opt = match ($d[1]) {
                'currency' => DB::table('cat_currency')->where('f_active', 1)->orderBy('currency_code')->pluck('currency_code')->map(fn ($c) => ['value' => $c, 'label' => $c])->all(),
                'choice' => array_map(fn ($v, $o) => ['value' => (string) $v, 'label' => $o[0]], array_keys(Choices::LIST[$k][3]), Choices::LIST[$k][3]),
                'role' => DB::table('cat_account_types')->where('f_active', 1)->whereNotNull('role_code')->where('role_code', '<>', '')->orderBy('account_type_name')->get(['role_code', 'account_type_name'])->map(fn ($r) => ['value' => $r->role_code, 'label' => $r->account_type_name . ' (' . $r->role_code . ')'])->all(),
                'people' => DB::table('t_account')->where('fk_id_ent', $ent)->where('f_active', 1)->orderBy('first_name')->limit(500)->get(['id', DB::raw("TRIM(CONCAT_WS(' ', first_name, sur_name)) as n")])->map(fn ($r) => ['value' => (string) $r->id, 'label' => $r->n])->all(),
                default => null,
            };
            $rows[] = ['key' => $k, 'label' => $d[0], 'type' => $d[1], 'default' => $d[2], 'help' => $d[3], 'value' => self::value($k, $ent, $d), 'options' => $opt, 'min' => $d[4]['min'] ?? null, 'max' => $d[4]['max'] ?? null, 'optional' => ! empty($d[4]['optional'])];
        }
        return ['rows' => $rows, 'history' => self::history($ent)];
    }

    /** History of the sourcing settings: who, when, old → new. */
    public static function history(int $ent, ?string $key = null, int $limit = 50): array
    {
        return TypedSettings::history($ent, $key ? [$key] : array_keys(self::defs()), 'SOURCING_SETTING', $limit);
    }

    /** Check and save one setting at company level. */
    public static function save(int $ent, string $key, $value): array
    {
        $d = self::defs()[$key] ?? throw new \InvalidArgumentException('Unknown sourcing setting.');
        [$err, $v] = TypedSettings::check($ent, $key, $d, $value);
        if ($err) throw new ValidationErrors(['value' => $err]);
        return TypedSettings::store($ent, $key, $d[0], (string) self::value($key, $ent, $d), $v, 'SOURCING_SETTING');
    }
}
