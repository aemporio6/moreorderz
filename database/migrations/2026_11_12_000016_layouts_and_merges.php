<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tracking-upload layouts kept as data (a marketplace profile names one in tracking_upload_layout), and the undo list of merges made by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mo_tracking_layouts', function (Blueprint $t) {
            $t->id();
            $t->string('code', 40)->unique();
            $t->string('name', 120);
            $t->text('columns_json');                // [[header, field], …]  field: order_no | item_id | sku | qty | tracking_no | carrier | service | ship_date | blank | text:<fixed>
            $t->string('delimiter', 4)->default(',');
            $t->string('note', 255)->nullable();
            $t->tinyInteger('f_active')->default(1);
        });
        $rows = [
            ['GENERIC', 'Common layout', [['order-id', 'order_no'], ['tracking-number', 'tracking_no'], ['carrier-service', 'service'], ['ship-date', 'ship_date']], ',', 'Works for any marketplace that lets you map columns.'],
            ['AMAZON_SHIP_CONFIRM', 'Amazon shipping confirmation (example)', [['order-id', 'order_no'], ['order-item-id', 'item_id'], ['quantity', 'qty'], ['ship-date', 'ship_date'], ['carrier-code', 'carrier'], ['carrier-name', 'blank'], ['tracking-number', 'tracking_no'], ['ship-method', 'service']], "\t", 'Example built on Amazon\'s shipping-confirmation flat file – compare its headers with the template in Seller Central before the first upload.'],
            ['EBAY_TRACKING_CSV', 'eBay upload tracking (example)', [['Order number', 'order_no'], ['Item number', 'item_id'], ['Shipping carrier', 'carrier'], ['Tracking number', 'tracking_no'], ['Shipped date', 'ship_date']], ',', 'Example – compare its headers with the "upload tracking" template in Seller Hub before the first upload.'],
            ['TIKTOK_TRACKING_CSV', 'TikTok Shop bulk ship (example)', [['Order ID', 'order_no'], ['Shipping provider', 'carrier'], ['Tracking ID', 'tracking_no']], ',', 'Example – compare its headers with the bulk-ship template in Seller Center before the first upload.'],
        ];
        foreach ($rows as [$c, $n, $cols, $d, $note]) DB::table('mo_tracking_layouts')->insert(['code' => $c, 'name' => $n, 'columns_json' => json_encode($cols), 'delimiter' => $d, 'note' => $note, 'f_active' => 1]);
        Schema::create('mo_merge_moves', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('fk_id_merge');      // sys_merge_map row
            $t->string('table_name', 64);
            $t->string('column_name', 64);
            $t->unsignedBigInteger('row_id');
            $t->index('fk_id_merge');
        });
        DB::statement("ALTER TABLE sys_merge_map MODIFY entity ENUM('PARTY','PRODUCT','LOCATION','LIST_ITEM','SALE','ID_CLASH','SUPPLIER') NOT NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('mo_merge_moves');
        Schema::dropIfExists('mo_tracking_layouts');
        DB::statement("DELETE FROM sys_merge_map WHERE entity = 'SUPPLIER'");
        DB::statement("ALTER TABLE sys_merge_map MODIFY entity ENUM('PARTY','PRODUCT','LOCATION','LIST_ITEM','SALE','ID_CLASH') NOT NULL");
    }
};
