<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** R-D-60 the old per-shop product rows become listings: the listing keeps the expected cost and the old vendor facts. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('t_product_listings', function (Blueprint $t) {
            foreach (['expected_cost' => fn () => $t->decimal('expected_cost', 12, 2)->nullable(), 'listing_url' => fn () => $t->string('listing_url', 500)->nullable(), 'fk_id_product_type' => fn () => $t->integer('fk_id_product_type')->nullable(),
                'fk_id_vendor' => fn () => $t->integer('fk_id_vendor')->nullable(), 'vendor_sku' => fn () => $t->string('vendor_sku', 100)->nullable(), 'vendor_url' => fn () => $t->string('vendor_url', 500)->nullable(),
                'vendor_ref' => fn () => $t->string('vendor_ref', 100)->nullable(), 'fk_id_carrier' => fn () => $t->integer('fk_id_carrier')->nullable(), 'legacy_branch_row' => fn () => $t->integer('legacy_branch_row')->nullable()->index()] as $c => $add)
                if (! Schema::hasColumn('t_product_listings', $c)) $add();
        });
    }

    public function down(): void {}
};
