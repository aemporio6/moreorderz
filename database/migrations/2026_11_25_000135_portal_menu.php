<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** R-INV-15 the investor portal home and my sales in the Invoicing group. */
return new class extends Migration {
    private const ITEMS = [[91057, '10.17', 'M_10_17_MY_INVESTOR_ACCOUNT', 'My investor account', '#/my-portal', 8], [91058, '10.18', 'M_10_18_MY_SALES', 'My sales', '#/my-sales', 9]];

    public function up(): void
    {
        foreach (self::ITEMS as [$id, $no, $key, $name, $url, $sort])
            if (! DB::table('sidebar_modules')->where('module_key', $key)->exists())
                DB::table('sidebar_modules')->insert(['id_module' => $id, 'module_key' => $key, 'module_name' => $name, 'url' => $url, 'parent_id' => 91050, 'sort_order' => $sort, 'e_status' => 'ACTIVE', 'f_new_tab' => 0, 'roles' => 'PARTNER, INVESTOR', 'screen_no' => $no, 'forms' => '', 'created_at' => now()]);
        if (class_exists(\App\Services\Rights::class)) \App\Services\Rights::flush();
    }

    public function down(): void { DB::table('sidebar_modules')->whereIn('module_key', array_column(self::ITEMS, 2))->delete(); }
};
