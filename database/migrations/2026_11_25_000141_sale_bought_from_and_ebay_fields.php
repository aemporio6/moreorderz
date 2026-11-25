<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** R-A_SALES-61 structured "Bought from" fields on each sale line (pre-filled from the buy note); R-A_SALES-16 the named eBay fields (promoted, taxes, dispatched). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('t_sales', function (Blueprint $t) {
            if (! Schema::hasColumn('t_sales', 'bought_vendor_id')) {
                $t->integer('bought_vendor_id')->nullable(); $t->string('bought_ref', 50)->nullable(); $t->string('bought_ref_hold', 50)->nullable(); $t->date('bought_date')->nullable();
                $t->integer('bought_qty')->nullable(); $t->decimal('bought_rate', 12, 2)->nullable(); $t->decimal('bought_total', 12, 2)->nullable(); $t->string('bought_reason', 200)->nullable(); $t->tinyInteger('bought_local')->default(0);
            }
            if (! Schema::hasColumn('t_sales', 'f_promoted')) {
                $t->tinyInteger('f_promoted')->default(0); $t->decimal('vat_amount', 12, 2)->nullable(); $t->decimal('mp_tax_amount', 12, 2)->nullable(); $t->string('tax_status', 40)->nullable(); $t->date('d_dispatched')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('t_sales', fn (Blueprint $t) => $t->dropColumn(['bought_vendor_id', 'bought_ref', 'bought_ref_hold', 'bought_date', 'bought_qty', 'bought_rate', 'bought_total', 'bought_reason', 'bought_local', 'f_promoted', 'vat_amount', 'mp_tax_amount', 'tax_status', 'd_dispatched']));
    }
};
