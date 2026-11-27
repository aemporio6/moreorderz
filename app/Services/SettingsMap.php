<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * MR-1129 (old Cat_module / Cat_module_param / Cat_module_params_lov / T_ent_param / T_branch_param): the old tool kept a module list, its
 * parameters, their allowed values and a value per company and per shop – four bare forms, and nothing in the old code read them.
 * Here every parameter is read from the place that uses it, so the map builds itself: a new setting of any module shows up here by itself.
 *   module → parameter (name, help, type, allowed values, default) → value for all companies → value for this company → own values per shop
 *   → what is in force now → who changed it last → the page where it is changed (with its checks).
 * Secrets are never shown (only "set").
 */
class SettingsMap
{
    public const SECRET = '/SECRET|TOKEN|PASSWORD|API_KEY|PASS$|_KEY$/i';

    /** module => [page, [key => [label, type, default, help, allowed values]]] – read from the modules themselves. */
    public static function modules(): array
    {
        $choices = [];
        foreach (Choices::LIST as $k => $c) $choices[$k] = [$c[0], 'choice', (string) $c[2], (string) $c[1], array_map(fn ($o) => (string) $o[0], (array) $c[3])];
        $typed = fn (array $defs) => array_map(fn ($d) => [$d[0], $d[1], (string) ($d[2] ?? ''), (string) ($d[3] ?? ''), (array) (($d[4] ?? [])['options'] ?? [])], $defs);
        return [
            'How the company works' => ['#/choices', $choices],
            'Sourcing' => ['#/settings/sourcing', $typed(SourcingSettings::defs())],
            'Warehouse' => ['#/settings/warehouse', $typed(WarehouseSettings::defs())],
            'Finance' => ['#/settings/finance', $typed(FinanceSettings::system())],
        ];
    }

    public static function secret(string $key): bool { return (bool) preg_match(self::SECRET, $key); }

    /** Every parameter with its values at each level. */
    public static function page(int $ent): array
    {
        $rows = DB::table('sys_settings as s')->leftJoin('t_users as u', 'u.id', '=', 's.updated_by')->whereIn('s.fk_id_ent', [0, $ent])
            ->get(['s.fk_id_ent', 's.setting_key', 's.setting_value', 's.updated_at', 'u.display_name']);
        $val = []; $shops = []; $last = [];
        foreach ($rows as $r) {
            $k = (string) $r->setting_key;
            if (str_contains($k, '@')) { [$base, $shop] = explode('@', $k, 2); if ((int) $r->fk_id_ent === $ent && $r->setting_value !== '') $shops[$base][(int) $shop] = (string) $r->setting_value; $k = $base; }
            else $val[$k][(int) $r->fk_id_ent ? 'company' : 'global'] = (string) $r->setting_value;
            if ($r->updated_at && (! isset($last[$k]) || $r->updated_at > $last[$k]['at'])) $last[$k] = ['at' => (string) $r->updated_at, 'by' => $r->display_name];
        }
        $shopName = DB::table('t_branches')->where('fk_id_ent', $ent)->pluck('shop_name', 'id')->all();
        $out = []; $known = [];
        foreach (self::modules() as $module => [$href, $defs]) foreach ($defs as $k => $d) {
            if (isset($known[$k])) continue; $known[$k] = 1;
            $out[] = self::row($k, $module, $href, $d, $val[$k] ?? [], $shops[$k] ?? [], $last[$k] ?? null, $shopName);
        }
        foreach ($val + $shops as $k => $_) if (! isset($known[$k])) {
            $known[$k] = 1;
            $out[] = self::row($k, 'Other', null, [ucfirst(strtolower(str_replace(['_', '.'], ' ', $k))), 'text', '', '', []], $val[$k] ?? [], $shops[$k] ?? [], $last[$k] ?? null, $shopName);
        }
        $mods = []; foreach ($out as $o) $mods[$o['module']] = ($mods[$o['module']] ?? 0) + 1;
        return ['settings' => $out, 'modules' => $mods];
    }

    private static function row(string $k, string $module, ?string $href, array $d, array $v, array $shops, ?array $last, array $shopName): array
    {
        $secret = self::secret($k); $mask = fn ($x) => $x === null ? null : ($secret ? ($x === '' ? '' : '••••') : $x);
        $label = fn ($x) => $x !== null && isset($d[4][$x]) && is_string($d[4][$x]) ? $d[4][$x] : $x;
        $now = $v['company'] ?? null; $now = $now === null || $now === '' ? ($v['global'] ?? null) : $now; $from = isset($v['company']) && $v['company'] !== '' ? 'company' : (isset($v['global']) ? 'global' : 'default');
        if ($now === null || $now === '') { $now = $d[2]; $from = 'default'; }
        return ['key' => $k, 'module' => $module, 'label' => $d[0], 'help' => $d[3], 'type' => $d[1], 'default' => $mask($d[2]), 'allowed' => $secret ? [] : array_values(array_map('strval', $d[4])),
            'global' => $mask($v['global'] ?? null), 'company' => $mask($v['company'] ?? null), 'now' => $mask((string) $now), 'now_label' => $secret ? null : $label((string) $now), 'from' => $from,
            'shops' => $secret ? [] : array_map(fn ($s, $x) => ['shop' => $shopName[$s] ?? ('Shop #' . $s), 'value' => $label($x)], array_keys($shops), $shops),
            'changed_at' => $last['at'] ?? null, 'changed_by' => $last['by'] ?? null, 'href' => $href, 'secret' => $secret];
    }
}
