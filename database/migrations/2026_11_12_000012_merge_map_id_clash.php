<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Old-data import: an old row whose id the new tool already uses for something else gets a new id; the merge map records it as ID_CLASH. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE sys_merge_map MODIFY entity ENUM('PARTY','PRODUCT','LOCATION','LIST_ITEM','SALE','ID_CLASH') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("DELETE FROM sys_merge_map WHERE entity = 'ID_CLASH'");
        DB::statement("ALTER TABLE sys_merge_map MODIFY entity ENUM('PARTY','PRODUCT','LOCATION','LIST_ITEM','SALE') NOT NULL");
    }
};
