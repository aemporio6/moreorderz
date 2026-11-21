<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The VA share saved on the import batch (reports read it, they never work it out again) and its basis. */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('t_sale_imports', 'va_share_amount')) Schema::table('t_sale_imports', function (Blueprint $t) { $t->decimal('va_share_amount', 12, 2)->default(0); $t->string('va_share_basis', 20)->default('after_fee'); $t->dateTime('va_refreshed_at')->nullable(); });
    }
    public function down(): void {}
};
