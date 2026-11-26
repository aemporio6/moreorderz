<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * R-D-71 local vendor card: company name, portal user id, and the portal password + bank details only in the vault (masked, reveal logged).
 * 27-11 sweep: secrets of the old tool kept in columns no form shows (vendor password / bank details, an old account password, bank question answers)
 * are sealed in the vault too – the record keeps only ••••.
 */
return new class extends Migration
{
    public function up(): void
    {
        $last = DB::table('sys_registry')->where('form_key', 'cat_vendors')->orderByDesc('section_no')->orderByDesc('field_no')->first();
        if ($last) { $no = (int) DB::table('sys_registry')->where('form_key', 'cat_vendors')->max('field_no');
            foreach ([['vendor_company_name', 'Company name', 'text', 0, null], ['vendor_user_id', 'Portal user id', 'text', 0, 'The login name of the supplier\'s portal.'],
                      ['vendor_password', 'Portal password', 'text', 1, 'Kept only in the vault – masked; revealing it is logged.'], ['vendor_bank_details', 'Bank details', 'textarea', 1, 'Kept only in the vault – masked; revealing it is logged.']] as [$c, $l, $t, $sec, $h])
                if (! DB::table('sys_registry')->where('form_key', 'cat_vendors')->where('field_name', $c)->exists())
                    DB::table('sys_registry')->insert(['form_key' => 'cat_vendors', 'form_title' => $last->form_title, 'form_kind' => $last->form_kind, 'section' => $last->section, 'section_no' => $last->section_no, 'field_no' => ++$no,
                        'field_name' => $c, 'label' => $l, 'type' => $t, 'source' => null, 'required' => 0, 'in_quick' => 0, 'visible_to' => $sec ? 'OWNER' : 'all', 'readonly' => 0, 'help' => $h, 'table_name' => 'cat_vendors', 'column_name' => $c, 'is_master' => 0, 'secret' => $sec]);
        }
        \App\Services\Registry::forget();
        \App\Services\Vault::sealExisting(['cat_vendors', 't_account', 't_account_bank_questions']);
    }

    public function down(): void { DB::table('sys_registry')->where('form_key', 'cat_vendors')->whereIn('field_name', ['vendor_company_name', 'vendor_user_id', 'vendor_password', 'vendor_bank_details'])->delete(); }
};
