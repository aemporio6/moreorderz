<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Recycle bin: a row taken out of a table is kept here (30 days) and can be put back. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('mo_recycle_bin', function (Blueprint $t) {
            $t->id(); $t->unsignedInteger('fk_id_ent')->default(0); $t->string('table_name', 64); $t->unsignedBigInteger('row_id'); $t->string('label', 255)->nullable();
            $t->string('group_key', 80)->nullable(); $t->longText('row_json'); $t->unsignedBigInteger('deleted_by')->nullable(); $t->dateTime('deleted_at'); $t->dateTime('restored_at')->nullable(); $t->string('note', 255)->nullable();
            $t->index(['table_name', 'row_id']); $t->index(['fk_id_ent', 'deleted_at']); $t->index('group_key');
        });
    }
    public function down(): void { Schema::dropIfExists('mo_recycle_bin'); }
};
