<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** R-H-39 … 41: units of supplier-fault returns on the GRN line's claim (raises the claim cap) (the return settings WH_RETURN_NEW_TO / _OPENED_TO are seeded already). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('t_src_grn_lines', 'return_claim_qty')) Schema::table('t_src_grn_lines', fn (Blueprint $t) => $t->decimal('return_claim_qty', 12, 2)->default(0));
    }

    public function down(): void
    {
        if (Schema::hasColumn('t_src_grn_lines', 'return_claim_qty')) Schema::table('t_src_grn_lines', fn (Blueprint $t) => $t->dropColumn('return_claim_qty'));
    }
};
