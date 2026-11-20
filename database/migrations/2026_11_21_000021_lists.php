<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** List Standard: per-user list preferences (column order, widths, rows per page, density) and saved export templates. */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('t_user_column_prefs', 'prefs_json')) Schema::table('t_user_column_prefs', fn (Blueprint $t) => $t->text('prefs_json')->nullable());
        Schema::create('mo_export_templates', function (Blueprint $t) {
            $t->id(); $t->unsignedInteger('fk_id_ent')->default(0); $t->unsignedBigInteger('user_id'); $t->string('list_key', 60); $t->string('name', 120);
            $t->text('columns_json'); $t->text('state_json')->nullable(); $t->string('format', 5)->default('CSV'); $t->string('shared_role', 30)->nullable();
            $t->string('schedule', 10)->default('NONE'); $t->string('send_to', 190)->nullable(); $t->dateTime('last_run_at')->nullable(); $t->timestamps();
            $t->unique(['user_id', 'list_key', 'name']);
        });
    }
    public function down(): void { Schema::dropIfExists('mo_export_templates'); }
};
