<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Owner 26-11 22:28: ONE importer for everything – each thing dropped, pasted or linked is kept here until it has gone to its place. */
return new class extends Migration
{
    public function up(): void
    {
        \Illuminate\Support\Facades\DB::table('sidebar_modules')->where('module_key', 'M_15_1_IMPORT_A_FILE')->update(['module_name' => 'Import anything']);
        if (Schema::hasTable('mo_intake')) return;
        Schema::create('mo_intake', function (Blueprint $t) {
            $t->id(); $t->integer('fk_id_ent')->index(); $t->integer('user_id')->nullable();
            $t->string('name', 200); $t->string('ext', 10)->nullable(); $t->string('mime', 100)->nullable(); $t->integer('size_bytes')->default(0); $t->string('sha256', 64)->nullable()->index();
            $t->string('path', 255)->nullable(); $t->string('source', 20)->default('UPLOAD'); $t->string('url', 500)->nullable();
            $t->mediumText('text')->nullable(); $t->string('read_by', 40)->nullable();
            $t->string('kind', 60)->nullable(); $t->text('facts_json')->nullable(); $t->text('proposals_json')->nullable();
            $t->string('status', 12)->default('NEW')->index(); $t->text('done_json')->nullable(); $t->integer('fk_id_document')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void { Schema::dropIfExists('mo_intake'); }
};
