<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Drop-shipping match: the event fired when a supplier-orders file of a shop + month is applied (the not-matched lines are matched again). */
return new class extends Migration {
    public function up(): void
    {
        DB::table('sys_event_types')->updateOrInsert(['event_code' => 'DS_BATCH_APPLIED'], ['area' => 'Sales', 'description' => 'A drop-shipping supplier-orders file was applied for a shop and month (Re-match runs by itself)', 'f_active' => 1]);
        // the company's own phone numbers (comma list): never used for matching, next to the automatic detection (R-A_SALES-20)
        foreach (DB::table('sys_settings')->where('setting_key', 'DS_MIN_MATCH')->pluck('fk_id_ent') as $ent) if (! DB::table('sys_settings')->where('fk_id_ent', $ent)->where('setting_key', 'DS_OWN_PHONES')->exists()) DB::table('sys_settings')->insert(['fk_id_ent' => $ent, 'setting_key' => 'DS_OWN_PHONES', 'setting_value' => '']);
    }
    public function down(): void {}
};
