<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** R-H-4: a bin made by the generator remembers its zone, so "Rename all to the new format" can build its new code. Bins typed by hand have none and are never renamed. */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('cat_inventory_locations', 'zone_code_gen')) DB::statement('ALTER TABLE cat_inventory_locations ADD zone_code_gen VARCHAR(6) NULL');
    }

    public function down(): void {}
};
