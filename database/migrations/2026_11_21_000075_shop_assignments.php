<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Shop assignment = shop scope (R-C-41): updated_by kept apart from created_by; the shop form's VA (fk_id_seller) is read-only – a cache of the assignments. */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('t_account_branches', 'updated_by')) Schema::table('t_account_branches', fn ($t) => $t->integer('updated_by')->nullable());
        DB::table('sys_registry')->where('table_name', 't_branches')->where('column_name', 'fk_id_seller')->update(['readonly' => 1]);
        DB::table('t_account_branches')->where('role_code', 'SELLER')->update(['role_code' => 'VA']);
    }
    public function down(): void {}
};
