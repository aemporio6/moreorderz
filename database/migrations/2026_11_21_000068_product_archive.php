<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Hide, delete, restore products (R-D-9): a row state (ACTIVE / HIDDEN / DELETED), the hide reason and the delete batch. Old "Disappear" rows (f_active 0) stay ACTIVE-state inactive rows. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('t_product_vendors', function ($t) {
            if (! Schema::hasColumn('t_product_vendors', 'row_state')) $t->enum('row_state', ['ACTIVE', 'HIDDEN', 'DELETED'])->default('ACTIVE');
            if (! Schema::hasColumn('t_product_vendors', 'hidden_reason')) $t->string('hidden_reason', 255)->nullable();
            if (! Schema::hasColumn('t_product_vendors', 'hidden_at')) $t->dateTime('hidden_at')->nullable();
            if (! Schema::hasColumn('t_product_vendors', 'hidden_by')) $t->integer('hidden_by')->nullable();
            if (! Schema::hasColumn('t_product_vendors', 'delete_group')) $t->string('delete_group', 80)->nullable();
        });
    }
    public function down(): void {}
};
