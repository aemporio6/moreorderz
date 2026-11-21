<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Pool / Private per marketplace × shop (R-A_SALES-30, K5): a visibility row may name a shop; 0 = every shop of the marketplace. */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('t_product_visibility', 'fk_id_branch')) {
            Schema::table('t_product_visibility', function (Blueprint $t) { $t->unsignedInteger('fk_id_branch')->default(0); });
            $keys = collect(DB::select('show index from t_product_visibility'))->pluck('Key_name')->unique();
            if ($keys->contains('uk_product_marketplace')) DB::statement('ALTER TABLE t_product_visibility DROP INDEX uk_product_marketplace');
            DB::statement('ALTER TABLE t_product_visibility ADD UNIQUE KEY uk_product_marketplace_shop (fk_id_product, fk_id_marketplace, fk_id_branch)');
        }
    }
    public function down(): void {}
};
