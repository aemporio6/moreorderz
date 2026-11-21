<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/** R-B_SOURCING-4: a repeated cycle keeps the cycle it was copied from (old did not save it). */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('t_src_cycles', 'source_cycle_id')) Schema::table('t_src_cycles', fn ($t) => $t->integer('source_cycle_id')->nullable()->index());
    }
    public function down(): void {}
};
