<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R-B_SOURCING-43 ten cost buckets per landed line; R-B_SOURCING-45 override with reason (calc kept, approval above the limit);
 * R-B_SOURCING-60 versions (SUPERSEDED) and COGS corrections for months already LOCKED (written in the shop's next open month).
 */
return new class extends Migration
{
    private const BUCKETS = ['packaging_cost', 'inspection_cost', 'origin_cost', 'freight_cost', 'insurance_cost', 'customs_duty', 'destination_cost', 'last_mile_cost', 'other_cost'];

    public function up(): void
    {
        Schema::table('t_src_landed_lines', function (Blueprint $t) {
            foreach (self::BUCKETS as $c) if (! Schema::hasColumn('t_src_landed_lines', $c)) $t->decimal($c, 14, 2)->default(0);
            if (! Schema::hasColumn('t_src_landed_lines', 'calc_unit_cost')) $t->decimal('calc_unit_cost', 14, 4)->nullable();
            if (! Schema::hasColumn('t_src_landed_lines', 'override_unit_cost')) $t->decimal('override_unit_cost', 14, 4)->nullable();
            if (! Schema::hasColumn('t_src_landed_lines', 'override_reason')) $t->string('override_reason', 255)->nullable();
            if (! Schema::hasColumn('t_src_landed_lines', 'override_status')) $t->string('override_status', 12)->nullable();      // APPLIED / PENDING / REJECTED
            if (! Schema::hasColumn('t_src_landed_lines', 'override_by')) $t->integer('override_by')->nullable();
            if (! Schema::hasColumn('t_src_landed_lines', 'override_at')) $t->dateTime('override_at')->nullable();
            if (! Schema::hasColumn('t_src_landed_lines', 'fk_id_approval')) $t->integer('fk_id_approval')->nullable();
        });
        DB::table('t_src_landed_lines')->whereNull('calc_unit_cost')->update(['calc_unit_cost' => DB::raw('unit_cost')]);
        // older versions that were replaced are SUPERSEDED (they stay readable)
        DB::table('t_src_landed_cost')->whereNotNull('superseded_by')->update(['landed_status' => 'SUPERSEDED']);
        if (! Schema::hasTable('fin_cogs_corrections')) Schema::create('fin_cogs_corrections', function (Blueprint $t) {
            $t->increments('id'); $t->integer('fk_id_ent'); $t->integer('fk_id_branch')->nullable(); $t->integer('fk_id_product')->nullable(); $t->integer('lot_id');
            $t->char('source_month', 7); $t->char('target_month', 7); $t->decimal('units', 14, 4); $t->decimal('unit_delta', 14, 4); $t->decimal('amount', 14, 2);
            $t->string('reason', 255); $t->string('source_type', 30); $t->integer('source_id')->nullable(); $t->integer('created_by')->nullable(); $t->dateTime('created_at')->nullable();
            $t->index(['fk_id_ent', 'target_month']); $t->index('lot_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_cogs_corrections');
        Schema::table('t_src_landed_lines', fn (Blueprint $t) => $t->dropColumn(array_merge(self::BUCKETS, ['calc_unit_cost', 'override_unit_cost', 'override_reason', 'override_status', 'override_by', 'override_at', 'fk_id_approval'])));
    }
};
