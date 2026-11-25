<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** R-B_SOURCING-11: a refused old entry stays PENDING with the reason; the maker fixes it and sends it again. */
return new class extends Migration {
    public function up(): void { if (! Schema::hasColumn('t_src_cycles', 'approval_reason')) Schema::table('t_src_cycles', fn (Blueprint $t) => $t->string('approval_reason', 300)->nullable()); }
    public function down(): void { if (Schema::hasColumn('t_src_cycles', 'approval_reason')) Schema::table('t_src_cycles', fn (Blueprint $t) => $t->dropColumn('approval_reason')); }
};
