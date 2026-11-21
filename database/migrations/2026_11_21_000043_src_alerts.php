<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Sourcing alert registry (R-B_SOURCING-26): which documents each step needs (SRC_DOC_MISSING), the reason of a GRN "no claim". */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('src_doc_requirements')) Schema::create('src_doc_requirements', function (Blueprint $t) {
            $t->increments('id'); $t->integer('fk_id_ent'); $t->string('step_key', 20); $t->string('doc_type', 100); $t->tinyInteger('f_active')->default(1); $t->string('note', 255)->nullable(); $t->dateTime('created_at')->nullable();
            $t->unique(['fk_id_ent', 'step_key', 'doc_type'], 'uq_src_doc_req');
        });
        if (! Schema::hasColumn('t_src_grn', 'claim_reason')) Schema::table('t_src_grn', function (Blueprint $t) { $t->string('claim_reason', 255)->nullable(); });
        // suggested defaults (owner to confirm): every company starts with them switched off – the Owner switches on the ones he wants (Setup › Sourcing documents)
        foreach (DB::table('t_enterprise')->pluck('id') as $ent) foreach ([['po', 'Proforma invoice'], ['shipment', 'Bill of lading'], ['shipment', 'Packing list'], ['customs', 'Import declaration'], ['qc', 'QC report']] as [$k, $d])
            DB::table('src_doc_requirements')->insertOrIgnore(['fk_id_ent' => $ent, 'step_key' => $k, 'doc_type' => $d, 'f_active' => 0, 'created_at' => now()]);
    }
    public function down(): void {}
};
