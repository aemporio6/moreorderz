<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Drop-shipping match: the event fired when a supplier-orders file of a shop + month is applied (the not-matched lines are matched again). */
return new class extends Migration {
    public function up(): void
    {
        DB::table('sys_event_types')->updateOrInsert(['event_code' => 'DS_BATCH_APPLIED'], ['area' => 'Sales', 'description' => 'A drop-shipping supplier-orders file was applied for a shop and month (Re-match runs by itself)', 'f_active' => 1]);
    }
    public function down(): void {}
};
