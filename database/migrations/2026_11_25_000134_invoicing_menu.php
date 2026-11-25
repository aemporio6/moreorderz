<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** R-G_CORE-86 the Invoicing menu as one group: Invoices, Settle a month, Settled months, Outstanding, Hidden margin, Profit split rules, My invoices (portal). */
return new class extends Migration {
    private const SEC = 91050;
    private const ITEMS = [
        ['10.11', 'M_10_11_SETTLE_A_MONTH', 'Settle a month', '#/invoices?show=settle', 'FINANCE, OWNER', 2],
        ['10.12', 'M_10_12_SETTLED_MONTHS', 'Settled months', '#/invoices?show=months', 'FINANCE, OWNER', 3],
        ['10.13', 'M_10_13_INVOICE_OUTSTANDING', 'Outstanding (who owes whom)', '#/invoice-outstanding', 'FINANCE, OWNER', 4],
        ['10.14', 'M_10_14_HIDDEN_MARGIN', 'Hidden margin', '#/invoice-margin', 'OWNER', 5],
        ['10.15', 'M_10_15_PROFIT_SPLIT_RULES', 'Profit split rules', '#/waterfall', 'OWNER, FINANCE', 6],
        ['10.16', 'M_10_16_MY_INVOICES', 'My invoices', '#/my-invoices', 'PARTNER, INVESTOR', 7],
    ];

    public function up(): void
    {
        if (! DB::table('sidebar_modules')->where('id_module', self::SEC)->exists())
            DB::table('sidebar_modules')->insert(['id_module' => self::SEC, 'module_key' => 'SEC_INV', 'module_name' => 'Invoicing', 'parent_id' => null, 'sort_order' => 105, 'e_status' => 'ACTIVE', 'f_new_tab' => 0, 'created_at' => now()]);
        DB::table('sidebar_modules')->where('module_key', 'M_10_7_INVOICES_CREDIT_NOTES')->update(['parent_id' => self::SEC, 'sort_order' => 1, 'roles' => 'FINANCE, OWNER', 'url' => '#/invoices', 'updated_at' => now()]);
        foreach (self::ITEMS as $i => [$no, $key, $name, $url, $roles, $sort])
            if (! DB::table('sidebar_modules')->where('module_key', $key)->exists())
                DB::table('sidebar_modules')->insert(['id_module' => self::SEC + 1 + $i, 'module_key' => $key, 'module_name' => $name, 'url' => $url, 'parent_id' => self::SEC, 'sort_order' => $sort, 'e_status' => 'ACTIVE', 'f_new_tab' => 0, 'roles' => $roles, 'screen_no' => $no, 'forms' => '', 'created_at' => now()]);
        if (class_exists(\App\Services\Rights::class)) \App\Services\Rights::flush();
    }

    public function down(): void
    {
        DB::table('sidebar_modules')->whereIn('module_key', array_column(self::ITEMS, 1))->delete();
        DB::table('sidebar_modules')->where('module_key', 'M_10_7_INVOICES_CREDIT_NOTES')->update(['parent_id' => 91000, 'sort_order' => 7]);
        DB::table('sidebar_modules')->where('id_module', self::SEC)->delete();
    }
};
