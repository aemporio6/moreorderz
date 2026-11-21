<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Supplier and SKU inherit in the cycle step forms (R-B_SOURCING-21): SKU read-only "Auto from Product"; supplier = linked:cycle.supplier (not the product master). */
return new class extends Migration {
    public function up(): void
    {
        foreach (\App\Services\SourcingInherit::SKU_TABLES as $t)
            DB::table('sys_registry')->where('form_key', $t)->where('table_name', $t)->where('column_name', 'sku')->update(['readonly' => 1, 'help' => 'Auto from Product – the SKU of the chosen cycle product.']);
        foreach (\App\Services\SourcingInherit::SUPPLIER_TABLES as $t)
            DB::table('sys_registry')->where('form_key', $t)->where('table_name', $t)->where('column_name', 'fk_id_supplier')->where(fn ($w) => $w->whereNull('default_rule')->orWhere('default_rule', '')->orWhere('default_rule', 'linked:product.supplier'))
                ->update(['default_rule' => 'linked:cycle.supplier', 'help' => 'From the cycle: the supplier of its latest PO, else its latest RFQ (filled when empty).']);
        \App\Services\Registry::forget();
    }
    public function down(): void {}
};
