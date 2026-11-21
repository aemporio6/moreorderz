<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * R-F_SETUP-16: the old "DASH_PARTNER" list (account ids; empty = everyone a partner) is retired. Accounts in the list get the role PARTNER
 * (roles master role_code PARTNER); an empty list gives nobody money rights automatically – the Owner assigns them.
 */
return new class extends Migration {
    public function up(): void
    {
        foreach (DB::table('sys_settings')->where('setting_key', 'DASH_PARTNER')->get(['fk_id_ent', 'setting_value']) as $s) {
            $ids = array_filter(array_map('intval', preg_split('/[^0-9]+/', (string) $s->setting_value) ?: []));
            if (! $ids) continue;
            $role = DB::table('cat_account_types')->whereRaw('UPPER(role_code) = ?', ['PARTNER'])->where(fn ($w) => $w->where('fk_id_ent', $s->fk_id_ent)->orWhere('fk_id_ent', 0))->value('id');
            if (! $role) continue;
            foreach ($ids as $a) if (DB::table('t_account')->where('id', $a)->exists() && ! DB::table('t_account_in_types')->where('fk_id_account', $a)->where('fk_id_account_type', $role)->exists())
                DB::table('t_account_in_types')->insert(['fk_id_account' => $a, 'fk_id_account_type' => $role, 'f_active' => 1]);
            DB::table('t_users')->whereIn('fk_id_account', $ids)->where('role_code', '!=', 'OWNER')->whereNotIn('role_code', ['PARTNER'])->get(['id'])->each(fn ($u) => DB::table('mo_user_roles')->updateOrInsert(['user_id' => $u->id, 'role_code' => 'PARTNER'], []));
        }
    }
    public function down(): void {}
};
