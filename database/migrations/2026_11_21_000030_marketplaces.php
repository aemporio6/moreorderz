<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Marketplace master: typical fee % (a hint for the import fee) and the questions of a marketplace as a checklist per shop. */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('cat_marketplaces', 'fee_pct')) Schema::table('cat_marketplaces', function (Blueprint $t) { $t->decimal('fee_pct', 5, 2)->nullable(); });
        if (! Schema::hasTable('mo_question_answers')) Schema::create('mo_question_answers', function (Blueprint $t) {
            $t->id(); $t->unsignedInteger('fk_id_branch'); $t->unsignedInteger('fk_id_question'); $t->tinyInteger('f_done')->default(0); $t->string('note', 255)->nullable(); $t->unsignedBigInteger('done_by')->nullable(); $t->dateTime('done_at')->nullable(); $t->unique(['fk_id_branch', 'fk_id_question'], 'mo_qa_key');
        });
    }
    public function down(): void { Schema::dropIfExists('mo_question_answers'); }
};
