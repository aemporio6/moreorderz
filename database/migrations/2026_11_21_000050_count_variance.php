<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Count variance, recount and approval (R-H-46): COUNT_LOSS / COUNT_FOUND moves, the units waiting in MISSING with their age. */
return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE t_stock_movements MODIFY movement_type ENUM('opening','purchase_in','sale_out','adjustment','return_in','transfer_out','transfer_in','ownership_out','ownership_in','damaged','count_adjust','production','in_transit','reserve','unreserve','written_off','count_loss','count_found') NOT NULL");
        if (! Schema::hasTable('wh_missing_units')) Schema::create('wh_missing_units', function (Blueprint $t) {
            $t->increments('id'); $t->integer('fk_id_ent'); $t->integer('fk_id_product'); $t->integer('fk_id_count')->nullable(); $t->integer('fk_id_from_location')->nullable(); $t->integer('qty'); $t->integer('qty_open');
            $t->string('closed_how', 20)->nullable(); $t->dateTime('created_at'); $t->dateTime('closed_at')->nullable(); $t->index(['fk_id_ent', 'fk_id_product']);
        });
    }
    public function down(): void {}
};
