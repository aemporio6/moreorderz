<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Temporary file retention (R-G_CORE-75): the two retention settings and the company time zone used by the nightly job. */
return new class extends Migration {
    public function up(): void
    {
        foreach (['retention_import_upload_days' => '1', 'retention_generator_days' => '0', 'COMPANY_TIMEZONE' => 'Europe/London'] as $k => $v)
            if (! DB::table('sys_settings')->where('fk_id_ent', 0)->where('setting_key', $k)->exists()) DB::table('sys_settings')->insert(['fk_id_ent' => 0, 'setting_key' => $k, 'setting_value' => $v, 'updated_at' => now()]);
    }
    public function down(): void { DB::table('sys_settings')->where('fk_id_ent', 0)->whereIn('setting_key', ['retention_import_upload_days', 'retention_generator_days'])->delete(); }
};
