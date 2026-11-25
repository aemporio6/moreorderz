<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** R-D-87 the fulfilment channel is set on the listing (empty = the shop's default) and copied to each sale line; old text FBA → MARKETPLACE_FC, FBM → SELF. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('t_product_listings', 'fulfilment_channel')) DB::statement('ALTER TABLE t_product_listings ADD COLUMN fulfilment_channel VARCHAR(16) NULL');
        foreach (['t_sales', 't_branches'] as $t) if (Schema::hasColumn($t, 'fulfilment_channel')) { DB::table($t)->where('fulfilment_channel', 'FBA')->update(['fulfilment_channel' => 'MARKETPLACE_FC']); DB::table($t)->where('fulfilment_channel', 'FBM')->update(['fulfilment_channel' => 'SELF']); }
    }

    public function down(): void { if (Schema::hasColumn('t_product_listings', 'fulfilment_channel')) DB::statement('ALTER TABLE t_product_listings DROP COLUMN fulfilment_channel'); }
};
