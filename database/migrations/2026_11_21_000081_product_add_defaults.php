<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Product Add defaults as registry rules (R-D-1) and their two settings; the family switch f_family defaults to Alone. */
return new class extends Migration {
    public const RULES = ['f_alert_min_level' => 'const:1', 'f_out_of_stock' => 'const:0', 'fk_id_product_condition' => 'setting:PRODUCT_DEFAULT_CONDITION', 'fk_id_vendor' => 'setting:PRODUCT_DEFAULT_VENDOR',
        'fk_id_branch' => 'const:ZERO', 'fk_id_role' => 'const:ZERO', 'min_level' => 'const:ZERO', 'landing_price' => 'const:0.00', 'd_created' => 'today', 'f_family' => 'const:0'];

    public function up(): void
    {
        foreach (self::RULES as $f => $r) DB::table('sys_registry')->whereIn('form_key', ['t_product_vendor', 'product'])->where('field_name', $f)->update(['default_rule' => $r]);
        foreach (['PRODUCT_DEFAULT_CONDITION' => '1', 'PRODUCT_DEFAULT_VENDOR' => null] as $k => $v)
            if (! DB::table('sys_settings')->where('fk_id_ent', 0)->where('setting_key', $k)->exists()) DB::table('sys_settings')->insert(['fk_id_ent' => 0, 'setting_key' => $k, 'setting_value' => $v, 'updated_at' => now()]);
        \App\Services\Registry::forget();
    }
    public function down(): void {}
};
