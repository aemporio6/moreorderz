<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** R-D-63 task CAR_RATE_MISSING for a preset created at rate 0; R-D-66 the Owner's review list "Ambiguous carrier values". */
return new class extends Migration {
    public function up(): void
    {
        if (! DB::table('sys_rules')->where('rule_code', 'CAR_RATE_MISSING')->exists())
            DB::table('sys_rules')->insert(['rule_code' => 'CAR_RATE_MISSING', 'name' => 'Set the rate of a carrier preset', 'module' => 'Setup', 'level' => 'NORMAL', 'assign_to' => 'role:MANAGER', 'due_days' => 2, 'handler' => 'CarrierPresets::add', 'f_active' => 1,
                'priority' => 'NORMAL', 'owner_rule' => 'role:MANAGER', 'dedupe_key' => 'CAR_RATE_MISSING:<preset>', 'auto_close' => 'rate above 0', 'fix_link' => '#/carriers']);
        if (! Schema::hasTable('mo_carrier_value_review')) DB::statement("CREATE TABLE mo_carrier_value_review (id INT AUTO_INCREMENT PRIMARY KEY, fk_id_ent INT NOT NULL DEFAULT 0, table_name VARCHAR(64) NOT NULL, column_name VARCHAR(64) NOT NULL, record_id BIGINT NOT NULL,
            old_value VARCHAR(60) NOT NULL, candidates_json TEXT NULL, status VARCHAR(8) NOT NULL DEFAULT 'OPEN', fk_id_preset INT NULL, decided_by INT NULL, decided_at DATETIME NULL, created_at DATETIME NOT NULL, UNIQUE KEY uq_rec (table_name, column_name, record_id))");
    }
    public function down(): void {}
};
