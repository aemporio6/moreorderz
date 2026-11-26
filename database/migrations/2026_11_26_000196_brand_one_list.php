<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Live 26-11: the product forms read an empty "BRAND" list while the brands (and the products' brand ids) live in cat_brands – one list for every brand field. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('sys_registry')->where('source', 'ref:BRAND')->update(['source' => 'table:cat_brands']);
    }

    public function down(): void {}
};
