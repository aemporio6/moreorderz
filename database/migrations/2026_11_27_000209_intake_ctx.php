<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Next #3 "File a document" from anywhere: the record a file was filed from – it is offered first and the window lists only its files. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('mo_intake', 'ctx_entity')) return;
        Schema::table('mo_intake', function (Blueprint $t) { $t->string('ctx_entity', 60)->nullable(); $t->integer('ctx_id')->nullable(); $t->index(['ctx_entity', 'ctx_id']); });
    }

    public function down(): void { Schema::table('mo_intake', function (Blueprint $t) { $t->dropIndex(['ctx_entity', 'ctx_id']); $t->dropColumn(['ctx_entity', 'ctx_id']); }); }
};
