<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Save & Next with carried context (R-B_SOURCING-18): the context of a cycle per user (po, cycle product, shipment, GRN, term, payment, QC, customs, supplier, product). */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('sys_workflow_context')) return;
        Schema::create('sys_workflow_context', function (Blueprint $t) {
            $t->id();
            $t->string('workflow', 30);
            $t->unsignedBigInteger('entity_id');
            $t->unsignedBigInteger('user_id');
            $t->text('context_json')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->unique(['workflow', 'entity_id', 'user_id'], 'uq_wf_ctx');
        });
    }
    public function down(): void { Schema::dropIfExists('sys_workflow_context'); }
};
