<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** My Partner Account (R-FIN-19): both menu rows open #/my-account (the Owner sees the explanation and "view as partner"). */
return new class extends Migration {
    public function up(): void
    {
        DB::table('sidebar_modules')->whereIn('module_key', ['M_1_2_PARTNER_HOME_MY_PARTNER_ACCOUNT_', 'M_11_5_MY_PARTNER_ACCOUNT'])->update(['url' => '#/my-account']);
        DB::table('sidebar_modules')->where('module_key', 'M_11_5_MY_PARTNER_ACCOUNT')->update(['roles' => 'PARTNER, OWNER']);
    }
    public function down(): void {}
};
