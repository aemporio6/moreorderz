<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Landed-cost engine (R-B_SOURCING-44): run lines per product, the formula on the lot, what each run booked (so a re-run books only the difference). */
return new class extends Migration {
    public function up(): void
    {
        \Illuminate\Support\Facades\DB::statement("ALTER TABLE fin_lots MODIFY source ENUM('GRN','OPENING','MANUAL','PIPELINE','QUICK','OLD_ENTRY','RETURN','SPLIT','DAMAGED') NOT NULL");      // damaged-on-arrival units in a lot of their own
        if (! Schema::hasColumn('fin_lots', 'cost_formula')) Schema::table('fin_lots', function (Blueprint $t) { $t->text('cost_formula')->nullable(); $t->string('cost_source', 12)->nullable(); });
        if (! Schema::hasColumn('t_src_landed_cost', 'loss_mode')) Schema::table('t_src_landed_cost', function (Blueprint $t) { $t->string('loss_mode', 10)->nullable(); $t->decimal('shares_total', 14, 2)->default(0); $t->decimal('missing_value', 14, 2)->default(0); $t->decimal('loss_total', 14, 2)->default(0); $t->text('warnings')->nullable(); });
        if (! Schema::hasTable('t_src_landed_lines')) Schema::create('t_src_landed_lines', function (Blueprint $t) {
            $t->increments('id'); $t->integer('fk_id_landed'); $t->integer('fk_id_product'); $t->decimal('product_value', 14, 2); $t->decimal('ordered_qty', 12, 2); $t->decimal('accepted_qty', 12, 2)->default(0); $t->decimal('damaged_qty', 12, 2)->default(0); $t->decimal('missing_qty', 12, 2)->default(0);
            $t->decimal('extra_cost', 14, 2)->default(0); $t->decimal('total_cost', 14, 2); $t->decimal('unit_cost', 14, 4); $t->decimal('loss_amount', 14, 2)->default(0); $t->text('shares_json')->nullable(); $t->text('formula')->nullable(); $t->index('fk_id_landed');
        });
    }
    public function down(): void {}
};
