<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * R-G_CORE-17 / 23: delegations (from / to) and a category's default owner are LOGINS (t_users) – the code compares user ids; the form picked persons before.
 * Values already stored as a person are turned into that person's login.
 */
return new class extends Migration {
    public function up(): void
    {
        foreach ([['task_delegation', 'from_user_id'], ['task_delegation', 'to_user_id'], ['task_category', 'default_owner_user_id']] as [$form, $field])
            DB::table('sys_registry')->where('form_key', $form)->where('field_name', $field)->update(['source' => 'table:t_users?f_active=1']);
        $login = fn ($acc) => DB::table('t_users')->where('fk_id_account', $acc)->orderBy('id')->value('id');
        foreach (DB::table('tm_delegations')->get() as $d) foreach (['from_user_id', 'to_user_id'] as $c)
            if ($d->$c && ! DB::table('t_users')->where('id', $d->$c)->exists() && ($l = $login($d->$c))) DB::table('tm_delegations')->where('id', $d->id)->update([$c => $l]);
        \App\Services\Registry::forget();
    }

    public function down(): void {}
};
