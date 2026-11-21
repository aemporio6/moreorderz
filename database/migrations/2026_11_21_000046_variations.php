<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Variations (R-D-4): a child stores only the values it overrides (empty = the parent's), its marketplace rows are soft-removed with it. */
return new class extends Migration {
    public function up(): void
    {
        foreach (['raw_price', 'sale_price', 'list_price', 'marketplace_fee'] as $c) DB::statement("ALTER TABLE t_variation_marketplace_prices MODIFY $c DECIMAL(12,2) NULL DEFAULT NULL");
        foreach (['t_variation_marketplace_prices', 't_variation_marketplace_shipping'] as $t) if (! Schema::hasColumn($t, 'f_active')) DB::statement("ALTER TABLE $t ADD f_active TINYINT NOT NULL DEFAULT 1");
    }
    public function down(): void {}
};
