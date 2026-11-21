<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Owner's form standard: the money categories (fin_categories) had no form, so their dropdowns (expense category, bank line category …)
 * had no "+ Add". Form fin_category: code, name, direction, profit role, VAT card, order, active. System rows keep code / direction / role.
 * The same rows are in database/meta/field_registry.csv.
 */
return new class extends Migration {
    public const FIELDS = [
        // section, no, field, label, type, source, required, quick, default, help
        ['Details', 1, 1, 'f_active', 'Active', 'check', null, 0, 1, 'const:1', 'Yes / No.'],
        ['Details', 1, 2, 'code', 'Code', 'text', null, 1, 1, null, 'Short code in capitals, for example OFFICE_RENT. Unique; a system category keeps its code.'],
        ['Details', 1, 3, 'name', 'Name', 'text', null, 1, 1, null, 'What people see in the lists, for example Office rent.'],
        ['Details', 1, 4, 'direction', 'Money in / out', 'select', 'list:FIN_DIRECTION', 1, 1, null, 'IN = money that comes in, OUT = money that goes out.'],
        ['Details', 1, 5, 'profit_role', 'Place in the profit', 'select', 'list:FIN_PROFIT_ROLE', 1, 1, 'const:FIXED', 'Where it counts in the profit and loss (fixed cost, variable cost, cost of goods, tax, transfer …).'],
        ['Details', 1, 6, 'f_vat_card', 'On the VAT card', 'check', null, 0, 0, 'const:0', 'Yes when the VAT of this category is claimed.'],
        ['Details', 1, 7, 'sort_no', 'Order in lists', 'number', null, 0, 0, null, 'Smaller numbers first.'],
    ];
    public const LISTS = ['FIN_DIRECTION' => ['IN' => 'Money in', 'OUT' => 'Money out'],
        'FIN_PROFIT_ROLE' => ['REVENUE' => 'Revenue', 'VARIABLE' => 'Variable cost', 'FIXED' => 'Fixed cost', 'COGS' => 'Cost of goods', 'TAX' => 'Tax', 'PARTNER' => 'Partner money', 'TRANSFER' => 'Transfer', 'BALANCE_SHEET' => 'Balance sheet', 'NONE' => 'Not in the profit']];

    public function up(): void
    {
        foreach (self::LISTS as $code => $vals) {
            $i = 0; foreach ($vals as $v => $l) { $i += 10; if (! DB::table('sys_field_options')->where('list_code', $code)->where('value', $v)->exists()) DB::table('sys_field_options')->insert(['list_code' => $code, 'value' => $v, 'label' => $l, 'sort' => $i, 'f_active' => 1]); }
            if (! DB::table('sys_field_lists')->where('list_code', $code)->exists()) DB::table('sys_field_lists')->insert(['list_code' => $code, 'title' => $code === 'FIN_DIRECTION' ? 'Money in / out' : 'Place in the profit', 'f_fixed' => 1, 'note' => 'Values drive the program – changed by the developer only.', 'created_at' => now(), 'updated_at' => now()]);
        }
        if (! DB::table('sys_registry')->where('form_key', 'fin_category')->exists())
            foreach (self::FIELDS as [$sec, $sn, $fn, $field, $label, $type, $src, $req, $quick, $def, $help])
                DB::table('sys_registry')->insert(\App\Services\RecordService::fillNotNull('sys_registry', ['form_key' => 'fin_category', 'form_title' => 'Money category', 'form_kind' => 'library', 'section' => $sec, 'section_no' => $sn, 'field_no' => $fn, 'field_name' => $field, 'label' => $label,
                    'type' => $type, 'source' => $src, 'required' => $req, 'in_quick' => $quick, 'default_rule' => $def, 'visible_to' => 'all', 'readonly' => 0, 'auto_correct' => $field === 'code' ? 'upper' : null, 'help' => $help,
                    'table_name' => 'fin_categories', 'column_name' => $field, 'is_master' => 1, 'secret' => 0]));
        $m = DB::table('sidebar_modules')->where('module_key', 'M_9_5_EXPENSES')->first();
        if ($m && ! str_contains((string) $m->forms, 'fin_category(')) DB::table('sidebar_modules')->where('id_module', $m->id_module)->update(['forms' => trim($m->forms . ' fin_category(C)')]);
        \App\Services\Registry::forget(); \App\Services\Rights::flush();
    }
    public function down(): void {}
};
