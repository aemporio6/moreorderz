<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The marketplace profile carries its own report mappings and fee buckets (R-A_SALES-87): the readers take them from here instead of from code. */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('mp_profile_report_types', 'column_map_json')) Schema::table('mp_profile_report_types', function (Blueprint $t) { $t->text('column_map_json')->nullable(); });
        if (! Schema::hasColumn('mp_profile_fee_buckets', 'book_as')) Schema::table('mp_profile_fee_buckets', function (Blueprint $t) { $t->string('book_as', 30)->nullable(); $t->string('file_columns', 500)->nullable(); $t->string('match_keywords', 500)->nullable(); });
    }
    public function down(): void {}
};
