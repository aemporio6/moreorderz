<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** R-G_CORE-114: what is loaded in each printer, and jobs that wait for their media. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('printer_profiles', 'fk_id_loaded_media')) Schema::table('printer_profiles', fn (Blueprint $t) => $t->unsignedInteger('fk_id_loaded_media')->nullable());
        Schema::table('print_jobs', function (Blueprint $t) {
            if (! Schema::hasColumn('print_jobs', 'fk_id_label_media')) $t->unsignedInteger('fk_id_label_media')->nullable();
            if (! Schema::hasColumn('print_jobs', 'params_json')) $t->text('params_json')->nullable();
        });
    }

    public function down(): void {}
};
