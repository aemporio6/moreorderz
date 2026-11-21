<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The marketplace's own month figure per fee bucket, typed from its hub (R-A_SALES-88 SAL_HUB_MISMATCH, profiles with hub_totals_available). */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('mo_hub_figures')) Schema::create('mo_hub_figures', function (Blueprint $t) {
            $t->id(); $t->unsignedInteger('fk_id_ent'); $t->unsignedInteger('fk_id_branch'); $t->char('ym', 7); $t->string('bucket', 20); $t->decimal('amount', 14, 2); $t->unsignedBigInteger('typed_by')->nullable(); $t->dateTime('typed_at')->nullable();
            $t->unique(['fk_id_branch', 'ym', 'bucket'], 'uq_hub_figure');
        });
    }
    public function down(): void { Schema::dropIfExists('mo_hub_figures'); }
};
