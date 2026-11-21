<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Products not listed per shop (R-D-58): the menu opens the summary screen. */
return new class extends Migration {
    public function up(): void { DB::table('sidebar_modules')->where('module_key', 'M_5_8_PRODUCTS_NOT_LISTED')->update(['url' => '#/not-listed']); }
    public function down(): void {}
};
