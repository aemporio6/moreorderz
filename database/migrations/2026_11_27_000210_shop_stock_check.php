<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Inventory (d) rest: the quantity each shop showed in its last listings report, and the Shop quantity check in the Inventory menu. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('t_product_listings', 'listed_qty')) Schema::table('t_product_listings', function (Blueprint $t) { $t->integer('listed_qty')->nullable(); $t->dateTime('listed_qty_at')->nullable(); });
        $sec = DB::table('sidebar_modules')->where('module_key', 'SEC_STOCKINV')->first(); $tpl = DB::table('sidebar_modules')->where('module_key', 'M_INV_FBA_CHECK')->first();
        if ($sec && $tpl && ! DB::table('sidebar_modules')->where('module_key', 'M_INV_SHOP_CHECK')->exists()) {
            $row = (array) $tpl; unset($row['id'], $row['id_module']); $row['created_at'] = now();
            $row = array_merge($row, ['module_key' => 'M_INV_SHOP_CHECK', 'module_name' => 'Shop quantity check']);
            foreach (['route', 'url', 'module_url', 'href'] as $c) if (array_key_exists($c, $row)) $row[$c] = '#/shop-stock-check';
            if (array_key_exists('sort_order', $row)) $row['sort_order'] = (int) $row['sort_order'] + 1;
            DB::table('sidebar_modules')->insert($row);
        }
    }

    public function down(): void
    {
        DB::table('sidebar_modules')->where('module_key', 'M_INV_SHOP_CHECK')->delete();
        if (Schema::hasColumn('t_product_listings', 'listed_qty')) Schema::table('t_product_listings', fn (Blueprint $t) => $t->dropColumn(['listed_qty', 'listed_qty_at']));
    }
};
