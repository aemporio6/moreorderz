<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 7e-220 – Finance:
 * - Taxation (R-TAX-1 … 6): the date fields are dates, the line item's category is the tax category list, required fields; seeds for categories (GAE, DEC, LPC, PRC …),
 *   line items (turnover, cost of sales, expenses, balance sheet, adjustments) and the CT rate table FY2017 … FY2026 (19 % flat to FY2022; 19 / 25 % with marginal relief from FY2023);
 * - R-FIN-72 a bank / provider account names its payment-provider profile;
 * - R-FIN-10 the one partner message template PARTNER_NOTICE.
 * The registry changes are also in database/meta/field_registry.csv.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['period_start', 'period_end', 'accounts_filed_date', 'ct600_filed_date', 'tax_paid_date'] as $f) DB::table('sys_registry')->where('form_key', 'tax_period')->where('field_name', $f)->update(['type' => 'date']);
        DB::table('sys_registry')->where('form_key', 'tax_period')->whereIn('field_name', ['period_start', 'period_end', 'fk_company_house'])->update(['required' => 1]);
        DB::table('sys_registry')->where('form_key', 'tax_period')->where('field_name', 'period_start')->update(['help' => 'Empty on a new period: the day after the last period of the company (the first one starts on the incorporation date).']);
        DB::table('sys_registry')->where('form_key', 'tax_entry')->where('field_name', 'entry_date')->update(['type' => 'date', 'help' => 'Empty = the end of the period.']);
        DB::table('sys_registry')->where('form_key', 'tax_entry')->whereIn('field_name', ['fk_period', 'fk_line_item', 'amount'])->update(['required' => 1]);
        DB::table('sys_registry')->where('form_key', 'tax_line_item')->where('field_name', 'fk_category')->update(['source' => 'table:tax_categories']);
        DB::table('sys_registry')->where('form_key', 'tax_line_item')->where('field_name', 'name')->update(['required' => 1]);

        $cats = [['SAL', 'Sales and other income', 'PL', 1], ['COS', 'Cost of sales', 'PL', 2], ['GAE', 'General administrative expenses', 'PL', 3], ['DEC', 'Depreciation', 'PL', 4], ['LPC', 'Legal and professional costs', 'PL', 5],
            ['PRC', 'Premises costs', 'PL', 6], ['FA', 'Fixed assets', 'BS', 10], ['CA', 'Current assets', 'BS', 11], ['CL', 'Creditors and provisions', 'BS', 12], ['CAP', 'Capital and reserves', 'BS', 13], ['ADJ', 'Tax adjustments', 'ADJ', 20]];
        foreach ($cats as [$c, $n, $s, $o]) if (! DB::table('tax_categories')->where('code', $c)->exists()) DB::table('tax_categories')->insert(['fk_id_ent' => 0, 'code' => $c, 'name' => $n, 'section' => $s, 'sort_order' => $o, 'f_active' => 1]);
        $cat = fn ($c) => (int) DB::table('tax_categories')->where('code', $c)->value('id');
        $items = [['TO01', 'Turnover', 'TURNOVER', 'NA', 'SAL'], ['OI01', 'Other income', 'OTHER_INCOME', 'NA', 'SAL'], ['CS01', 'Cost of sales', 'COST_OF_SALES', 'ALLOW', 'COS'],
            ['GAE01', 'General and admin expenses', 'EXPENSE', 'ALLOW', 'GAE'], ['GAE02', 'Client entertaining', 'EXPENSE', 'DISALLOW', 'GAE'], ['DEC01', 'Depreciation', 'EXPENSE', 'DISALLOW', 'DEC'],
            ['LPC01', 'Accountancy and legal fees', 'EXPENSE', 'ALLOW', 'LPC'], ['PRC01', 'Rent and premises', 'EXPENSE', 'ALLOW', 'PRC'],
            ['FA01', 'Fixed assets (net book value)', 'FIXED_ASSET', 'NA', 'FA'], ['CA01', 'Stock', 'CURRENT_ASSET', 'NA', 'CA'], ['CA02', 'Debtors', 'CURRENT_ASSET', 'NA', 'CA'], ['CA03', 'Cash at bank', 'CURRENT_ASSET', 'NA', 'CA'],
            ['PP01', 'Prepayments', 'PREPAYMENT', 'NA', 'CA'], ['CRS01', 'Creditors due within one year', 'CREDITOR_SHORT', 'NA', 'CL'], ['CRL01', 'Creditors due after one year', 'CREDITOR_LONG', 'NA', 'CL'],
            ['PRV01', 'Provisions', 'PROVISION', 'NA', 'CL'], ['ACR01', 'Accruals', 'ACCRUAL', 'NA', 'CL'], ['CAP01', 'Share capital', 'CAPITAL', 'NA', 'CAP'], ['CAP02', 'Profit and loss reserve', 'CAPITAL', 'NA', 'CAP'],
            ['TADD01', 'Other add-backs', 'TAX_ADD', 'NA', 'ADJ'], ['TDED01', 'Capital allowances', 'TAX_DEDUCT', 'NA', 'ADJ']];
        foreach ($items as $o => [$code, $name, $role, $tr, $c]) if (! DB::table('tax_line_items')->where('code', $code)->exists())
            DB::table('tax_line_items')->insert(['fk_id_ent' => 0, 'fk_category' => $cat($c), 'code' => $code, 'name' => $name, 'line_role' => $role, 'tax_treatment' => $tr, 'sort_order' => $o + 1, 'f_active' => 1,
                'guidance' => $tr === 'DISALLOW' ? 'Disallowable – added back for Corporation Tax.' : null]);
        for ($fy = 2017; $fy <= 2026; $fy++) if (! DB::table('tax_rates')->where('fy_start_year', $fy)->exists())
            DB::table('tax_rates')->insert($fy < 2023 ? ['fy_start_year' => $fy, 'small_rate' => 19, 'main_rate' => 19, 'lower_limit' => 0, 'upper_limit' => 0, 'mr_fraction_num' => 0, 'mr_fraction_den' => 1, 'f_active' => 1]
                : ['fy_start_year' => $fy, 'small_rate' => 19, 'main_rate' => 25, 'lower_limit' => 50000, 'upper_limit' => 250000, 'mr_fraction_num' => 3, 'mr_fraction_den' => 200, 'f_active' => 1]);

        DB::table('sys_registry')->where('form_key', 't_account_paypal')->where('field_name', 'sort_branch_code')->update(['readonly' => 1, 'help' => 'Read-only: the sort code of linked debit card 1.']);      // R-C-32
        DB::statement('ALTER TABLE payment_provider_fee_buckets MODIFY sign_rule VARCHAR(20) NULL');      // NEGATIVE_ONLY / POSITIVE_ONLY did not fit in 12
        if (! Schema::hasColumn('fin_bank_accounts', 'fk_id_provider_profile')) Schema::table('fin_bank_accounts', fn (Blueprint $t) => $t->integer('fk_id_provider_profile')->nullable());
        if (! DB::table('sys_registry')->where('form_key', 'fin_bank_account')->where('field_name', 'fk_id_provider_profile')->exists() && ($last = DB::table('sys_registry')->where('form_key', 'fin_bank_account')->orderByDesc('section_no')->orderByDesc('field_no')->first()))
            DB::table('sys_registry')->insert(['form_key' => 'fin_bank_account', 'form_title' => $last->form_title, 'form_kind' => $last->form_kind, 'section' => $last->section, 'section_no' => $last->section_no, 'field_no' => $last->field_no + 1,
                'field_name' => 'fk_id_provider_profile', 'label' => 'Payment-provider profile', 'type' => 'select', 'source' => 'table:payment_provider_profiles', 'required' => 0, 'in_quick' => 0, 'visible_to' => 'all', 'readonly' => 0,
                'help' => 'For a wallet, card acquirer or money-transfer account: its profile decides the fee buckets and where the payouts land.', 'table_name' => 'fin_bank_accounts', 'column_name' => 'fk_id_provider_profile', 'is_master' => 0, 'secret' => 0]);

        if (! DB::table('wa_templates')->where('code', 'PARTNER_NOTICE')->exists())
            DB::table('wa_templates')->insert(['code' => 'PARTNER_NOTICE', 'title' => 'Partner notice', 'audience' => 'PARTNER', 'body' => '{text}', 'f_active' => 1, 'language' => 'en', 'approval_status' => 'PENDING', 'params_json' => '["text"]']);
        \App\Services\Registry::forget();
    }

    public function down(): void {}
};
