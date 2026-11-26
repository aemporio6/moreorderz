<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * D2 one party list: a supplier is a party (t_account, External Contact, party type Company) with the role SUPPLIER, plus its supplier profile
 * (t_src_suppliers, linked by fk_id_account). Made when the supplier is saved; the party's name follows the supplier's name.
 * So a supplier shows in People / External Contacts with the role Supplier, and its banks, mobiles and e-mails are the party's own lists.
 */
class SupplierParty
{
    public static function role(int $ent): int
    {
        $id = DB::table('cat_account_types')->whereRaw('UPPER(role_code) = ?', ['SUPPLIER'])->whereIn('fk_id_ent', [$ent, 0])->orderByDesc('fk_id_ent')->value('id');
        if ($id) return (int) $id;
        return (int) DB::table('cat_account_types')->insertGetId(RecordService::fillNotNull('cat_account_types', ['fk_id_ent' => 0, 'account_type_name' => 'Supplier', 'iso_account_type' => 'SUPPLIER', 'role_code' => 'SUPPLIER', 'is_ec' => 1, 'is_paid' => 0, 'f_active' => 1]));
    }

    /** The supplier's party (made when missing); returns its id. */
    public static function ensure(int $supplierId): int
    {
        $s = DB::table('t_src_suppliers')->where('id', $supplierId)->first(['id', 'fk_id_ent', 'supplier_name', 'fk_id_account', 'fk_id_country', 'f_active']);
        if (! $s) return 0;
        $name = trim((string) $s->supplier_name) ?: 'Supplier ' . $s->id;
        if ($s->fk_id_account && DB::table('t_account')->where('id', $s->fk_id_account)->exists()) {
            DB::table('t_account')->where('id', $s->fk_id_account)->where('first_name', '<>', mb_substr($name, 0, 200))->update(['first_name' => mb_substr($name, 0, 200)]);
            return (int) $s->fk_id_account;
        }
        $ent = (int) $s->fk_id_ent;
        $acc = (int) DB::table('t_account')->insertGetId(RecordService::fillNotNull('t_account', ['fk_id_ent' => $ent, 'account_code' => AccountCode::suggest($ent, true), 'first_name' => mb_substr($name, 0, 200), 'sur_name' => '',
            'l_account_kind' => 'E', 'l_party_type' => 'C', 'current_nationality' => $s->fk_id_country, 'f_active' => (int) $s->f_active, 'record_status' => 'ACTIVE']));
        DB::table('t_account_in_types')->insert(['fk_id_account' => $acc, 'fk_id_account_type' => self::role($ent), 'f_active' => 1]);
        DB::table('t_src_suppliers')->where('id', $s->id)->update(['fk_id_account' => $acc]);
        Audit::log('t_account', $acc, 'INSERT', ['supplier' => $s->id, 'party_type' => 'C', 'role' => 'SUPPLIER'], 'Party of supplier ' . $name, $ent);
        return $acc;
    }

    public static function ensureAll(): int
    {
        $n = 0;
        foreach (DB::table('t_src_suppliers')->where(fn ($w) => $w->whereNull('fk_id_account')->orWhere('fk_id_account', 0))->orderBy('id')->pluck('id') as $id) { self::ensure((int) $id); $n++; }
        return $n;
    }
}
