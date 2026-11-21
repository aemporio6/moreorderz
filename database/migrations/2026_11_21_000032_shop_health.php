<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Account health per shop and month (R-A_SALES-73 / 74): one row per shop × month, its metric values, and who entered what. */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('mo_shop_health')) Schema::create('mo_shop_health', function (Blueprint $t) {
            $t->id(); $t->unsignedInteger('fk_id_ent'); $t->unsignedInteger('fk_id_branch'); $t->unsignedSmallInteger('year'); $t->unsignedTinyInteger('month');
            foreach ([1, 2, 3, 4] as $w) $t->integer("pf_week_$w")->default(0);      // positive feedback: the increment of each week
            $t->text('cnf_text')->nullable(); $t->text('onf_text')->nullable(); $t->integer('nf_total')->default(0); $t->text('metrics_json')->nullable(); $t->string('seller_level', 30)->nullable();
            $t->unsignedBigInteger('updated_by')->nullable(); $t->dateTime('updated_at')->nullable(); $t->dateTime('created_at')->nullable();
            $t->unique(['fk_id_branch', 'year', 'month'], 'uq_shop_health');
        });
        if (! Schema::hasTable('mo_shop_health_log')) Schema::create('mo_shop_health_log', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('fk_id_health'); $t->string('field', 40); $t->string('old_value', 500)->nullable(); $t->string('new_value', 500)->nullable(); $t->unsignedBigInteger('user_id')->nullable(); $t->string('user_name', 150)->default(''); $t->string('user_role', 30)->default(''); $t->dateTime('created_at');
            $t->index('fk_id_health');
        });
    }
    public function down(): void { Schema::dropIfExists('mo_shop_health_log'); Schema::dropIfExists('mo_shop_health'); }
};
