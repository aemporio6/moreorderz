<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Dependent dropdowns of the person forms (R-C-21): cascades_from of every chain field, the device slot as a pick list, the payment bank as a bank. Same change in database/meta/field_registry.csv. */
return new class extends Migration {
    public function up(): void
    {
        foreach (\App\Services\Chains::cascades() as $form => $fields) foreach ($fields as $field => $parents)
            DB::table('sys_registry')->where('form_key', $form)->where('field_name', $field)->update(['cascades_from' => $parents]);
        DB::table('sys_registry')->where('form_key', 't_account_mobile')->where('field_name', 'slotno')->update(['type' => 'select', 'source' => 'chain:device_slots', 'help' => 'The free slots of the device chosen above.']);
        DB::table('sys_registry')->where('form_key', 't_account_marketplace')->where('field_name', 'fk_id_bank_payment')->update(['source' => 'table:cat_banks', 'help' => 'The bank payments are taken from; the account list below shows this person\'s accounts at it.']);
        \App\Services\Registry::forget();
    }
    public function down(): void {}
};
