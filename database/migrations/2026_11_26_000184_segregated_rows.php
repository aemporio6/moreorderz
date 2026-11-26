<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** R-H-11: a segregated owner's units have their own physical row (SKU, bin, owner) next to the shared row (owner 0) – the unique key is product + bin + owner. */
return new class extends Migration
{
    public function up(): void
    {
        $idx = collect(DB::select("SHOW INDEX FROM t_product_locations"))->pluck('Key_name')->unique()->all();
        if (in_array('uk_product_location', $idx, true) && in_array('uq_prodloc_owner', $idx, true)) DB::statement('ALTER TABLE t_product_locations DROP INDEX uk_product_location');
        if (! in_array('uq_prodloc_owner', $idx, true)) DB::statement('ALTER TABLE t_product_locations ADD UNIQUE KEY uq_prodloc_owner (fk_id_product, fk_id_location, fk_id_owner_seg)');
    }

    public function down(): void {}
};
