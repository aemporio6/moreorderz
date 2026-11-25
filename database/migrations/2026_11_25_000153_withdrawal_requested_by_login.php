<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** The withdrawal form: "Requested by" is the login that asked (the stored id is the login) – read from the logins and read-only, as on the sourcing forms (000149). */
return new class extends Migration {
    public function up(): void
    {
        DB::table('sys_registry')->where('form_key', 'fin_withdrawal')->where('field_name', 'requested_by')->update(['source' => 'table:t_users', 'readonly' => 1, 'help' => 'The person logged in when it was asked – set by the system.']);
        \App\Services\Registry::forget();
    }
    public function down(): void {}
};
