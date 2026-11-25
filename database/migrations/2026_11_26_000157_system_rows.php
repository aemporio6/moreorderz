<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R-F_SETUP-42 every master has f_system (locked row). Set for the rows the code depends on:
 * subscription items 9 and 10 (the old tool hid their Delete button), the roles that have a role code, and the location kinds.
 */
return new class extends Migration {
    public function up(): void
    {
        $tables = array_map(fn ($r) => array_values((array) $r)[0], DB::select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"));
        foreach ($tables as $t) if (str_starts_with($t, 'cat_') && ! Schema::hasColumn($t, 'f_system')) Schema::table($t, fn (Blueprint $b) => $b->tinyInteger('f_system')->default(0));
        if (Schema::hasTable('cat_subscription_items')) DB::table('cat_subscription_items')->whereIn('id', [9, 10])->update(['f_system' => 1]);
        if (Schema::hasTable('cat_account_types') && Schema::hasColumn('cat_account_types', 'role_code')) DB::table('cat_account_types')->whereNotNull('role_code')->where('role_code', '<>', '')->update(['f_system' => 1]);
        if (Schema::hasTable('cat_location_types')) DB::table('cat_location_types')->update(['f_system' => 1]);
    }

    public function down(): void {}
};
