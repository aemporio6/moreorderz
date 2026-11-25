<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** R-A_SALES-58 / 54: the Etsy-style month header keeps its supplier and the rows set aside as out of month. */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('t_sale_buy_main', 'fk_id_vendor')) DB::statement('ALTER TABLE t_sale_buy_main ADD fk_id_vendor INT NULL');
        if (! Schema::hasColumn('t_sale_buy_main', 'set_aside_json')) DB::statement('ALTER TABLE t_sale_buy_main ADD set_aside_json MEDIUMTEXT NULL');
    }
    public function down(): void {}
};
