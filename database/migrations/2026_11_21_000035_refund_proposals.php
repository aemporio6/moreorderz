<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Refund proposals (R-A_SALES-86): a refund row without a matching sale line is kept (shop, order, SKU), with the money report's own sale amount and the line total used for the kind. */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('t_sale_refund_proposals', 'f_sku_unknown')) Schema::table('t_sale_refund_proposals', function (Blueprint $t) {
            $t->unsignedInteger('fk_id_branch')->nullable(); $t->string('order_no', 100)->nullable(); $t->string('sku', 100)->nullable(); $t->tinyInteger('f_sku_unknown')->default(0);
            $t->decimal('report_sale_amount', 14, 2)->nullable(); $t->decimal('line_total', 14, 2)->nullable(); $t->date('d_refund')->nullable(); $t->string('kind_source', 12)->nullable(); $t->index(['fk_id_branch', 'status']);
        });
        DB::statement('ALTER TABLE t_sale_refund_proposals MODIFY fk_id_sale BIGINT NULL');
    }
    public function down(): void {}
};
