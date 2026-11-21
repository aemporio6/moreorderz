<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Settings › Sourcing (R-B_SOURCING-51): menu row; old values cleaned (VOLUMETRIC_DIVISOR ≤ 0 → 5000; the landed-cost choices stored as their codes). */
return new class extends Migration {
    public function up(): void
    {
        if (! DB::table('sidebar_modules')->where('module_key', 'M_17_30_SOURCING_SETTINGS')->exists())
            DB::table('sidebar_modules')->insert(['module_key' => 'M_17_30_SOURCING_SETTINGS', 'module_name' => 'Sourcing settings', 'url' => '#/settings/sourcing', 'parent_id' => 91700, 'sort_order' => 30, 'e_status' => 'ACTIVE', 'created_at' => now(), 'f_new_tab' => 0, 'roles' => 'OWNER', 'screen_no' => '17.30']);
        DB::table('sys_settings')->where('setting_key', 'VOLUMETRIC_DIVISOR')->where(fn ($w) => $w->whereNull('setting_value')->orWhere('setting_value', '')->orWhereRaw('CAST(setting_value AS DECIMAL(12,2)) <= 0'))->update(['setting_value' => '5000']);
        foreach (['LC_FREIGHT_METHOD', 'LC_DUTY_METHOD', 'LC_LOSS_UNITS'] as $k) DB::table('sys_settings')->where('setting_key', $k)->whereNotNull('setting_value')->update(['setting_value' => DB::raw("UPPER(TRIM(setting_value))")]);
    }
    public function down(): void { DB::table('sidebar_modules')->where('module_key', 'M_17_30_SOURCING_SETTINGS')->delete(); }
};
