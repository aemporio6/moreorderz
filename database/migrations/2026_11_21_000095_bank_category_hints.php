<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** R-FIN-30: the keyword seed of the bank-line category suggestion – a central list the Owner can edit (value = word, label = rule). */
return new class extends Migration {
    public const SEED = [['amazon', 'IN:SALES_PAYOUT'], ['amzn', 'IN:SALES_PAYOUT'], ['payoneer', 'IN:SALES_PAYOUT|OUT:PLATFORM_FEE'], ['ebay', 'IN:SALES_PAYOUT|OUT:PLATFORM_FEE'],
        ['alibaba', 'OUT:SUPPLIER_PAYMENT'], ['supplier', 'OUT:SUPPLIER_PAYMENT'], ['ltd', 'OUT<-500:SUPPLIER_PAYMENT'], ['hmrc', 'OUT:DUTY_VAT'], ['vat', 'OUT:DUTY_VAT'], ['duty', 'OUT:DUTY_VAT'],
        ['freight', 'OUT:FREIGHT'], ['shipping', 'OUT:FREIGHT'], ['dhl', 'OUT:FREIGHT'], ['maersk', 'OUT:FREIGHT'], ['salary', 'OUT:SALARY_VA'], ['upwork', 'OUT:SALARY_VA']];

    public function up(): void
    {
        foreach (self::SEED as $i => [$w, $rule]) if (! DB::table('sys_field_options')->where('list_code', 'BANK_CATEGORY_HINT')->where('value', $w)->exists())
            DB::table('sys_field_options')->insert(['list_code' => 'BANK_CATEGORY_HINT', 'value' => $w, 'label' => $rule, 'sort' => ($i + 1) * 10, 'f_active' => 1]);
        if (! DB::table('sys_field_lists')->where('list_code', 'BANK_CATEGORY_HINT')->exists())
            DB::table('sys_field_lists')->insert(['list_code' => 'BANK_CATEGORY_HINT', 'title' => 'Bank line category hints (word → category)', 'f_fixed' => 0, 'note' => 'Label = DIRECTION:CATEGORY, several joined by |; OUT<-500 = only below −500.', 'created_at' => now(), 'updated_at' => now()]);
    }
    public function down(): void {}
};
