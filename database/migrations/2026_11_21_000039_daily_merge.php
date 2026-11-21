<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Daily orders and the monthly merge (R-A_SALES-84): a daily line not in the monthly file is flagged; Close daily orders remembers when it ran and which price it wrote. */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('t_sales', 'f_not_in_file')) Schema::table('t_sales', function (Blueprint $t) { $t->tinyInteger('f_not_in_file')->default(0); });
        if (! Schema::hasColumn('mo_sale_fulfilment', 'closed_price')) Schema::table('mo_sale_fulfilment', function (Blueprint $t) { $t->decimal('closed_price', 14, 2)->nullable(); $t->dateTime('closed_at')->nullable(); $t->string('merged_file', 255)->nullable(); });
        if (! Schema::hasColumn('t_sale_imports', 'f_daily')) Schema::table('t_sale_imports', function (Blueprint $t) { $t->tinyInteger('f_daily')->default(0); });
    }
    public function down(): void {}
};
