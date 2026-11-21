<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Large exports are prepared in the background and sent to the person (bell + e-mail). */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('mo_export_jobs')) Schema::create('mo_export_jobs', function (Blueprint $t) {
            $t->id(); $t->unsignedInteger('fk_id_ent'); $t->unsignedBigInteger('user_id'); $t->string('kind', 30); $t->string('format', 10); $t->text('params_json'); $t->string('status', 10)->default('QUEUED'); $t->unsignedInteger('lines')->default(0);
            $t->string('file_path', 255)->nullable(); $t->string('file_name', 120)->nullable(); $t->string('error', 255)->nullable(); $t->dateTime('created_at')->nullable(); $t->dateTime('done_at')->nullable(); $t->index(['status', 'id']);
        });
    }
    public function down(): void { Schema::dropIfExists('mo_export_jobs'); }
};
