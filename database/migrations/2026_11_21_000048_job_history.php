<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Job history is a sub-list (R-C-39): InnoDB, soft delete, salary as money; due sources read only live rows. */
return new class extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE t_account_job_history ENGINE=InnoDB');
        if (Schema::hasTable('mo_import_report') && ! Schema::hasColumn('mo_import_report', 'review_json')) DB::statement('ALTER TABLE mo_import_report ADD review_json MEDIUMTEXT NULL');      // old values that were not numbers, for review
        if (! Schema::hasColumn('t_account_job_history', 'f_active')) DB::statement('ALTER TABLE t_account_job_history ADD f_active TINYINT NOT NULL DEFAULT 1');
        DB::statement('ALTER TABLE t_account_job_history MODIFY salary DECIMAL(12,2) NOT NULL DEFAULT 0');
        DB::table('sys_registry')->where('form_key', 't_account_job_history')->where('field_name', 'fk_id_branch')->update(['cascades_from' => 'fk_id_marketplace', 'help' => 'Optional; only the shops of the chosen marketplace.']);
        DB::table('sys_registry')->where('form_key', 't_account_job_history')->where('field_name', 'pound_exchange_rate')->update(['help' => 'Optional, above 0. Empty = the latest rate of the salary currency; a typed rate is kept (and logged).']);
        DB::table('sys_registry')->where('form_key', 't_account_job_history')->where('field_name', 'fk_id_currency')->update(['help' => "Empty = the currency of the person's country (GBP when none)."]);
        DB::table('sys_registry')->where('form_key', 't_account_job_history')->where('field_name', 'share_percentage')->update(['help' => 'Optional, 0–100 in steps of 0.5; empty shows the chip "No VA share set" (B31).']);
        DB::table('sys_due_sources')->where('source_table', 't_account_job_history')->update(['where_rule' => 'f_active=1 AND d_end_date IS NULL']);
    }
    public function down(): void {}
};
