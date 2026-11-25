<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * R-B_SOURCING-62 "Requested by" on the sourcing forms is the login that created the record (the code always stored the user id);
 * the form showed it through the list of people, which could name another person. It is now read from the logins and is read-only.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::table('sys_registry')->whereIn('form_key', ['src_cycle', 'quick_buy', 'old_entry', 't_src_cycles', 't_src_historical', 't_src_po'])->where('field_name', 'requested_by')
            ->update(['source' => 'table:t_users', 'readonly' => 1, 'help' => 'The person logged in when it was created – set by the system.']);
        \App\Services\Registry::forget();
    }
    public function down(): void {}
};
