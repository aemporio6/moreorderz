<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Step 5 Money: one row per marketplace statement (header of fin_settlement_lines) and the marketplace's own period totals. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mo_settlements')) Schema::create('mo_settlements', function (Blueprint $t) {
            $t->id(); $t->integer('fk_id_ent'); $t->integer('fk_id_branch'); $t->integer('fk_id_batch')->nullable();
            $t->string('report_type', 30); $t->string('statement_id', 40); $t->string('payout_id', 40)->nullable();
            $t->date('statement_date'); $t->date('payout_date')->nullable(); $t->string('currency', 3)->default('GBP'); $t->string('file_status', 20)->nullable();
            foreach (['total', 'net_sales', 'shipping', 'fees', 'adjustments', 'reserve', 'payable', 'rows_total'] as $c) $t->decimal($c, 14, 2)->default(0);
            $t->integer('rows_count')->default(0); $t->boolean('f_rows_complete')->default(false); $t->string('bank_mask', 20)->nullable();
            $t->string('status', 12)->default('LOADED');   // LOADED → POSTED → PAID (bank line matched)
            $t->unsignedBigInteger('journal_id')->nullable(); $t->unsignedBigInteger('payout_journal_id')->nullable(); $t->unsignedBigInteger('fk_id_bank_line')->nullable();
            $t->integer('created_by')->nullable(); $t->timestamps();
            $t->unique(['fk_id_branch', 'statement_id'], 'ux_mo_settlement');
        });
        if (! Schema::hasTable('mo_settlement_periods')) Schema::create('mo_settlement_periods', function (Blueprint $t) {
            $t->id(); $t->integer('fk_id_ent'); $t->integer('fk_id_branch'); $t->integer('fk_id_batch')->nullable(); $t->string('report_type', 30);
            $t->date('period_from'); $t->date('period_to'); $t->string('currency', 3)->default('GBP');
            foreach (['total', 'net_sales', 'shipping', 'fees', 'adjustments'] as $c) $t->decimal($c, 14, 2)->default(0);
            $t->text('totals_json')->nullable(); $t->text('checks_json')->nullable(); $t->string('hub_check', 12)->default('PENDING');   // OK / INCOMPLETE / DIFF
            $t->timestamps();
            $t->unique(['fk_id_branch', 'report_type', 'period_from', 'period_to'], 'ux_mo_settlement_period');
        });
    }

    public function down(): void { Schema::dropIfExists('mo_settlement_periods'); Schema::dropIfExists('mo_settlements'); }
};
