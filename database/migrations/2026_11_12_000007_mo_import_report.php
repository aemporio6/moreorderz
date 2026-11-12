<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Step 7: one line per old table per import run – what was there, what came over, what did not and why. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mo_import_report')) Schema::create('mo_import_report', function (Blueprint $t) {
            $t->id(); $t->string('run_code', 30); $t->string('old_table', 64); $t->string('plan', 12);   // COPY / LATER / RETIRED / TOOL
            $t->integer('old_rows')->default(0); $t->integer('imported')->default(0); $t->integer('already_there')->default(0); $t->integer('failed')->default(0);
            $t->string('left_out_columns', 500)->nullable(); $t->string('masked_columns', 500)->nullable(); $t->string('note', 500)->nullable(); $t->boolean('f_dry')->default(false);
            $t->dateTime('created_at');
            $t->index(['run_code', 'old_table']);
        });
        if (! Schema::hasColumn('mo_import_report', 'merged')) Schema::table('mo_import_report', fn (Blueprint $t) => $t->integer('merged')->default(0)->after('already_there'));
    }

    public function down(): void { Schema::dropIfExists('mo_import_report'); }
};
