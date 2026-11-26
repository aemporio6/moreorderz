<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** R-G_CORE-122 one history: sys_history + the cut-over copy of the old logs; v_sale_change_log reads it in the old shape. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sys_history')) Schema::create('sys_history', function (Blueprint $t) {
            $t->increments('id'); $t->unsignedInteger('fk_id_ent')->default(0); $t->string('entity', 64); $t->unsignedInteger('record_id');
            $t->string('save_key', 40)->nullable(); $t->string('action', 60); $t->string('field_name', 100)->nullable(); $t->string('field_label', 150)->nullable();
            $t->text('old_value')->nullable(); $t->mediumText('new_value')->nullable(); $t->unsignedInteger('user_id')->nullable(); $t->string('changed_by', 150)->nullable(); $t->dateTime('changed_at');
            $t->string('legacy_table', 64)->nullable(); $t->unsignedInteger('legacy_id')->nullable();
            $t->index(['entity', 'record_id']); $t->index('save_key'); $t->unique(['legacy_table', 'legacy_id']);
        });
        \App\Services\History::cutover();
        DB::statement("CREATE OR REPLACE VIEW v_sale_change_log AS SELECT id, record_id AS fk_id_sale, save_key, action, field_name, field_label, old_value, new_value, user_id AS fk_id_user, changed_by, changed_at, legacy_table FROM sys_history WHERE entity = 't_sales'");
    }

    public function down(): void {}
};
