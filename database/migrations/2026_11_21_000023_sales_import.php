<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Sales import: summary lines (daily totals), SKU aliases learned from the fix-up grid, the import that replaced another. */
return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE t_sales MODIFY sale_source ENUM('active','ds','unmatched','history','summary') NOT NULL DEFAULT 'active'");
        Schema::create('mo_sku_aliases', function (Blueprint $t) {
            $t->id(); $t->unsignedInteger('fk_id_ent'); $t->unsignedInteger('fk_id_marketplace')->default(0); $t->string('kind', 10); $t->string('alias_key', 190); $t->unsignedBigInteger('fk_id_product');
            $t->unsignedBigInteger('created_by')->nullable(); $t->timestamps(); $t->unique(['fk_id_ent', 'fk_id_marketplace', 'kind', 'alias_key'], 'mo_sku_alias_key');
        });
        if (! Schema::hasColumn('t_sale_imports', 'replaced_rows')) Schema::table('t_sale_imports', function (Blueprint $t) { $t->integer('replaced_rows')->default(0); $t->string('fee_source', 20)->nullable(); $t->text('warnings_json')->nullable(); $t->text('stats_json')->nullable(); });
        DB::table('sys_event_types')->updateOrInsert(['event_code' => 'SALES_BATCH_STAGED'], ['area' => 'Sales', 'description' => 'A monthly sales file was imported (lines are Pending, nothing is deducted)', 'f_active' => 1]);
    }
    public function down(): void { Schema::dropIfExists('mo_sku_aliases'); }
};
