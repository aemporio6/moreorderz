<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Company form, one field per fact (R-F_SETUP-35): typed fields, Owner-only gateway codes, read-only confirmation statement date. The same change is in database/meta/field_registry.csv. */
return new class extends Migration {
    public function up(): void
    {
        $ch = [
            'company_name' => ['required' => 1, 'help' => 'Required. Spaces trimmed, the first letter upper case.'],
            'director_1_share' => ['type' => 'number', 'min' => '0', 'max' => '100', 'step' => '0.01', 'help' => 'Share in %, 0–100, 2 decimals. Shares of the three directors together may not exceed 100 %.'],
            'director_2_share' => ['type' => 'number', 'min' => '0', 'max' => '100', 'step' => '0.01', 'help' => 'Share in %, 0–100, 2 decimals.'],
            'director_3_share' => ['type' => 'number', 'min' => '0', 'max' => '100', 'step' => '0.01', 'help' => 'Share in %, 0–100, 2 decimals.'],
            'company_utr' => ['help' => '10 digits; spaces are removed.'],
            'authentication_code' => ['visible_to' => 'OWNER', 'auto_correct' => 'upper', 'help' => 'Companies House authentication code: 6 letters / digits, typed as given. Kept in the vault; Owner only.'],
            'gateway_id' => ['visible_to' => 'OWNER', 'help' => 'Kept in the vault, masked; Owner only, every reveal is logged.'],
            'gateway_pass' => ['visible_to' => 'OWNER', 'help' => 'Kept in the vault, masked; Owner only, every reveal is logged.'],
            'hmrc_activation_code' => ['visible_to' => 'OWNER', 'auto_correct' => 'upper', 'help' => 'Typed as on the HMRC letter: 12 letters / digits. Optional. Kept in the vault; Owner only.'],
            'company_website_link' => ['type' => 'link', 'help' => 'Starts with http:// or https://.'],
            'vat_no' => ['auto_correct' => 'upper', 'help' => 'Optional. GB + 9 or 12 digits, spaces removed.'],
            'eori_no' => ['auto_correct' => 'upper', 'help' => 'Optional. GB + 12 digits, spaces removed.'],
            'iso_code' => ['auto_correct' => 'upper', 'help' => 'Our short company code: letters / digits, upper case, unique.'],
            'registration_no' => ['auto_correct' => 'upper', 'help' => '8 characters, for example 12345678 or SC123456.'],
            'company_email_1' => ['type' => 'email'],
            'company_emial_2' => ['type' => 'email'],
            'incorporate_date' => ['help' => 'Blank until known – never today by itself. Feeds the due dates (accounts, confirmation statement).'],
            'file_return_date' => ['help' => 'Companies House next due date. Blank until known. Wins over the worked-out accounts due date when within 45 days of it.'],
            'confirmation_statement_date' => ['readonly' => 1, 'help' => 'Read-only: kept once, in the Accountancy Hub (review date + 14 days = due).'],
        ];
        foreach ($ch as $field => $set) DB::table('sys_registry')->where('form_key', 'cat_company_house')->where('field_name', $field)->update($set);
        \App\Services\Registry::forget();
    }
    public function down(): void {}
};
