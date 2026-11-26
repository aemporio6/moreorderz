<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** R-D-12: on the product form the pair Role → User reads "Product manager role / Product manager"; the user list follows the role and is cleared when the role changes. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['', '2', '3'] as $n) {
            DB::table('sys_registry')->where('form_key', 't_product_vendor')->where('column_name', 'fk_id_role' . $n)->update(['label' => 'Product manager role' . ($n ? " $n" : ''), 'help' => 'Which role looks after this product (e.g. VA, Listing team).']);
            DB::table('sys_registry')->where('form_key', 't_product_vendor')->where('column_name', 'fk_id_role_account' . $n)->update(['label' => 'Product manager' . ($n ? " $n" : ''), 'cascades_from' => 'fk_id_role' . $n, 'help' => 'Only the active people of the chosen role are offered; changing the role clears this.']);
        }

        // R-F_SETUP-40 pin order is a number (0 / 1 on top, others 2), not a tick; the country code is the dial code
        DB::table('sys_registry')->where('form_key', 'cat_country')->where('field_name', 'f_order')->update(['type' => 'number', 'min' => '0', 'max' => '9', 'help' => 'Pin order: 0 or 1 puts the country on top of every country list (e.g. UK 0, Pakistan 1); all others 2.']);
        DB::table('sys_registry')->where('form_key', 'cat_country')->where('field_name', 'country_code')->update(['label' => 'Dial code', 'help' => 'The phone dial code, digits only (44 for the UK – shown as +44).']);
    }

    public function down(): void {}
};
