<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-C-1 Party type and relationship are two columns (K3): t_account.l_party_type P = Person / C = Company ("Party type"),
 * t_account.l_account_kind M = Managed account / E = External contact ("Managed or external"). find / listByRole filter by each one independently.
 */
class Party
{
    public const TYPES = ['P' => 'Person', 'C' => 'Company'];
    public const KINDS = ['M' => 'Managed account', 'E' => 'External contact'];

    /** $f: party_type (P / C), kind (M / E), role (cat_account_types.role_code or id), q (name / code), active (default 1). */
    public static function find(int $ent, array $f = [], int $limit = 200): array
    {
        $type = strtoupper(trim((string) ($f['party_type'] ?? ''))); $kind = strtoupper(trim((string) ($f['kind'] ?? ''))); $q = trim((string) ($f['q'] ?? '')); $role = trim((string) ($f['role'] ?? ''));
        if ($type !== '' && ! isset(self::TYPES[$type])) throw new \InvalidArgumentException('Party type: P (Person) or C (Company).');
        if ($kind !== '' && ! isset(self::KINDS[$kind])) throw new \InvalidArgumentException('Managed or external: M or E.');
        return DB::table('t_account as a')->where('a.fk_id_ent', $ent)->where('a.f_active', (int) ($f['active'] ?? 1))
            ->when($type !== '', fn ($w) => $w->where('a.l_party_type', $type))
            ->when($kind !== '', fn ($w) => $w->whereRaw("COALESCE(NULLIF(a.l_account_kind, ''), 'M') = ?", [$kind]))
            ->when($role !== '', fn ($w) => $w->whereExists(fn ($x) => $x->from('t_account_in_types as t')->join('cat_account_types as c', 'c.id', '=', 't.fk_id_account_type')->whereColumn('t.fk_id_account', 'a.id')->where('t.f_active', 1)
                ->where(fn ($y) => ctype_digit($role) ? $y->where('c.id', (int) $role) : $y->where('c.role_code', $role)->orWhere('c.account_type_name', $role))))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('a.first_name', 'like', "%$q%")->orWhere('a.sur_name', 'like', "%$q%")->orWhere('a.account_code', 'like', "%$q%")))
            ->orderBy('a.first_name')->orderBy('a.id')->limit($limit)
            ->get(['a.id', 'a.account_code', 'a.first_name', 'a.sur_name', 'a.l_party_type', 'a.l_account_kind'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'code' => $r->account_code, 'name' => trim($r->first_name . ' ' . $r->sur_name), 'party_type' => $r->l_party_type, 'party_type_label' => self::TYPES[$r->l_party_type] ?? $r->l_party_type,
                'kind' => $r->l_account_kind ?: 'M', 'kind_label' => self::KINDS[$r->l_account_kind ?: 'M'] ?? $r->l_account_kind])->all();
    }

    public static function listByRole(int $ent, string $role, array $f = []): array { return self::find($ent, ['role' => $role] + $f); }
}
