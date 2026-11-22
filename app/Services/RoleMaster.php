<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-C-43 one roles master (cat_account_types) with fixed codes and flags. Name and ISO role trimmed with the first letter upper (old trim(ucfirst())),
 * the name unique per enterprise (new); role_code upper (A–Z, 0–9, _), unique, locked once a person carries the role; is_ec / is_paid / sort (migration 000085).
 * Records are never hard-deleted in the new app – a role in use can only be switched off. Code reads role codes, never ids (Registry party:SELLER, Choices party_roles …).
 */
class RoleMaster
{
    public static function code(string $s): string { return mb_substr(trim(preg_replace('/_+/', '_', preg_replace('/[^A-Z0-9]+/', '_', strtoupper($s))), '_'), 0, 20); }

    public static function used(int $id): int { return DB::table('t_account_in_types')->where('fk_id_account_type', $id)->count(); }

    /** FormRules hook. */
    public static function check(array &$data, array $old, ?int $id): array
    {
        $e = []; $ent = (int) ($data['fk_id_ent'] ?? $old['fk_id_ent'] ?? Context::ent());
        foreach (['account_type_name', 'iso_account_type'] as $c) if (array_key_exists($c, $data) && $data[$c] !== null) $data[$c] = ucfirst(trim(preg_replace('/\s+/', ' ', (string) $data[$c])));
        $name = (string) ($data['account_type_name'] ?? $old['account_type_name'] ?? '');
        if ($name !== '' && DB::table('cat_account_types')->where('fk_id_ent', $ent)->whereRaw('LOWER(TRIM(account_type_name)) = ?', [mb_strtolower($name)])->when($id, fn ($q) => $q->where('id', '<>', $id))->exists())
            $e['account_type_name'] = "The role \"$name\" already exists.";
        if (array_key_exists('role_code', $data)) {
            $code = self::code((string) $data['role_code']); $was = (string) ($old['role_code'] ?? '');
            if ($id && $was !== '' && $code !== $was && ($n = self::used($id))) $e['role_code'] = "The role code is locked – $n " . ($n === 1 ? 'person carries' : 'people carry') . " this role (the code is what the system reads; rename the role instead).";
            elseif ($code !== '' && DB::table('cat_account_types')->whereRaw('UPPER(role_code) = ?', [$code])->whereIn('fk_id_ent', [$ent, 0])->when($id, fn ($q) => $q->where('id', '<>', $id))->exists()) $e['role_code'] = "The role code $code is already used.";
            $data['role_code'] = $code === '' ? null : $code;
        } elseif (! $id && $name !== '') $data['role_code'] = self::unique(self::code((string) ($data['iso_account_type'] ?? '') ?: $name), $ent);
        foreach (['is_ec', 'is_paid'] as $c) if (array_key_exists($c, $data)) $data[$c] = (int) (bool) $data[$c];
        if (array_key_exists('sort', $data)) $data['sort'] = max(0, min(9999, (int) $data['sort']));
        return $e;
    }

    private static function unique(string $code, int $ent, ?int $id = null): ?string
    {
        if ($code === '') return null; $c = $code; $i = 2;
        while (DB::table('cat_account_types')->whereRaw('UPPER(role_code) = ?', [$c])->whereIn('fk_id_ent', [$ent, 0])->when($id, fn ($q) => $q->where('id', '<>', $id))->exists()) $c = mb_substr($code, 0, 17) . '_' . $i++;
        return $c;
    }

    /** Migration: old id 6 = OWNER, old id 5 = SELLER, the others from the ISO code (else the name). */
    public static function fillCodes(): int
    {
        $n = 0;
        foreach (DB::table('cat_account_types')->where(fn ($w) => $w->whereNull('role_code')->orWhere('role_code', ''))->orderBy('id')->get() as $r) {
            $want = (int) $r->id === 6 ? 'OWNER' : ((int) $r->id === 5 ? 'SELLER' : self::code((string) ($r->iso_account_type ?: $r->account_type_name)));
            DB::table('cat_account_types')->where('id', $r->id)->update(['role_code' => self::unique($want, (int) $r->fk_id_ent, (int) $r->id)]); $n++;
        }
        return $n;
    }

    /** The old second roles screen (Cat_account_roles) is retired: its names missing from the master come in switched off. */
    public static function mergeOldScreen(): int
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('cat_account_roles')) return 0; $n = 0;
        foreach (DB::table('cat_account_roles')->orderBy('id')->get() as $r) {
            $name = ucfirst(trim((string) $r->account_type_name)); if ($name === '') continue;
            if (DB::table('cat_account_types')->where('fk_id_ent', $r->fk_id_ent)->whereRaw('LOWER(TRIM(account_type_name)) = ?', [mb_strtolower($name)])->exists()) continue;
            DB::table('cat_account_types')->insert(['fk_id_ent' => $r->fk_id_ent, 'account_type_name' => $name, 'iso_account_type' => ucfirst(trim((string) $r->iso_account_type)), 'f_active' => 0,
                'role_code' => self::unique(self::code((string) ($r->iso_account_type ?: $name)), (int) $r->fk_id_ent), 'is_ec' => 0, 'is_paid' => 0, 'sort' => 999]); $n++;
        }
        return $n;
    }
}
