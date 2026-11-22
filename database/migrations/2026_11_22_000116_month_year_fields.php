<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** R-G_CORE-71 the new field type month_year (MM/YYYY) for card valid-from / expiry (bank cards and credit cards); the date columns stay (1st / last day of the month). */
return new class extends Migration
{
    private const F = [['t_account_bank', 'valid_from'], ['t_account_bank', 'valid_upto'], ['t_account_credit_card', 'valid_from'], ['t_account_credit_card', 'valid_upto']];

    public function up(): void
    {
        foreach (self::F as [$form, $field]) DB::table('sys_registry')->where('form_key', $form)->where('field_name', $field)->update(['type' => 'month_year', 'help' => 'MM/YYYY – the month on the card.']);
        \App\Services\Registry::forget();
    }

    public function down(): void
    {
        foreach (self::F as [$form, $field]) DB::table('sys_registry')->where('form_key', $form)->where('field_name', $field)->update(['type' => 'date']);
    }
};
