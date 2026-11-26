<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 7e-219 – Accounts / People and Security:
 * - D7 / M37 personal data (ID numbers, documents, salary, previous employment) Owner / HR only: registry visible_to OWNER_HR (right people.personal_data);
 * - E8 approval limits per role (VA 500, Finance 2,500, above → Owner): sys_approval_limits.role_code + the two rows; the price-floor rule points at listings.min_price;
 * - R-C-33 subscription item kind IP / COMPANY / TOOL; R-C-37 social media account promotes a shop; R-C-31 "Bought with account" on purchases;
 * - R-C-52 probation end → due item 14 days before; A10 WhatsApp login code template.
 * The registry changes are also in database/meta/field_registry.csv.
 */
return new class extends Migration
{
    public function up(): void
    {
        // R-C-6 person tables in utf8mb4: Urdu / Hindi / Bangla / Chinese names save (the old latin1 columns could not hold them)
        $tables = collect(DB::select("SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' AND (table_name LIKE 't\\_account%' OR table_name IN ('cat_company_house', 'cat_banks'))"))->pluck('t');
        foreach ($tables as $t) if (DB::selectOne("SELECT COUNT(*) n FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND character_set_name IN ('latin1', 'utf8mb3', 'utf8')", [$t])->n > 0)
            DB::statement("ALTER TABLE `$t` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $ct = (string) DB::selectOne("SELECT column_type c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sys_audit_log' AND column_name = 'action'")->c;
        if (str_starts_with($ct, 'enum(') && ! str_contains($ct, "'LOGIN_CODE_SENT'")) DB::statement('ALTER TABLE sys_audit_log MODIFY action ' . substr($ct, 0, -1) . ",'LOGIN_CODE_SENT') NOT NULL");      // A10 a WhatsApp sign-in code was sent

        $hr = ['t_account_photo_id' => ['long_digit_number', 'doc_url'], 't_account_doc' => ['doc_no', 'file_name'],
            't_account_job_history' => ['share_percentage', 'pound_exchange_rate', 'salary', 'fk_id_currency', 'salary_date']];
        foreach ($hr as $form => $fields) DB::table('sys_registry')->where('form_key', $form)->whereIn('field_name', $fields)->update(['visible_to' => 'OWNER_HR']);

        if (! Schema::hasColumn('sys_approval_limits', 'role_code')) Schema::table('sys_approval_limits', fn (Blueprint $t) => $t->string('role_code', 30)->nullable()->after('fk_id_role'));      // a limit is kept per role code (built-in roles have no id)
        $idx = collect(DB::select('SHOW INDEX FROM sys_approval_limits'))->pluck('Key_name')->unique()->all();
        if (in_array('uq_lim', $idx, true)) Schema::table('sys_approval_limits', fn (Blueprint $t) => $t->dropUnique('uq_lim'));
        if (! in_array('uq_lim_role', $idx, true)) Schema::table('sys_approval_limits', fn (Blueprint $t) => $t->unique(['fk_id_ent', 'role_code', 'action_code'], 'uq_lim_role'));
        foreach (['VA' => 500, 'FINANCE' => 2500] as $role => $max)
            if (! DB::table('sys_approval_limits')->where('fk_id_ent', 0)->where('role_code', $role)->where('action_code', '*')->exists())
                DB::table('sys_approval_limits')->insert(['fk_id_ent' => 0, 'fk_id_role' => 0, 'role_code' => $role, 'action_code' => '*', 'max_amount_gbp' => $max]);
        DB::table('sys_approval_rules')->where('rule_code', 'PRICE_FLOOR')->update(['column_pattern' => 'min_price']);
        DB::table('sys_approval_rules')->where('rule_code', 'PARTY_ROLE_OWNER')->update(['f_active' => 0]);      // role OWNER of a shop owner is guarded by R-C-17 (where-used), not by approval

        if (! Schema::hasColumn('cat_subscription_items', 'kind')) Schema::table('cat_subscription_items', fn (Blueprint $t) => $t->string('kind', 10)->default('TOOL'));
        DB::table('cat_subscription_items')->where('id', 9)->update(['kind' => 'IP']);
        DB::table('cat_subscription_items')->where('id', 10)->update(['kind' => 'COMPANY']);
        DB::table('sys_registry')->where('form_key', 't_account_subscription')->where('field_name', 'fk_ipburger_id')->update(['show_if' => 'item_kind=IP', 'label' => 'IP account paid']);
        DB::table('sys_registry')->where('form_key', 't_account_subscription')->where('field_name', 'fk_company_house_id')->update(['show_if' => 'item_kind=COMPANY', 'label' => 'Company paid for']);
        if (! DB::table('sys_registry')->where('form_key', 'cat_subscription_items')->where('field_name', 'kind')->exists() && DB::table('sys_registry')->where('form_key', 'cat_subscription_items')->exists())
            DB::table('sys_registry')->insert($this->field('cat_subscription_items', 'kind', 'Kind', 'select', 'list:SUBSCRIPTION_KIND', 'cat_subscription_items', 'kind', null, 'IP shows "IP account paid", Company shows "Company paid for".', 'const:TOOL'));
        if (! DB::table('sys_field_lists')->where('list_code', 'SUBSCRIPTION_KIND')->exists()) DB::table('sys_field_lists')->insert(['list_code' => 'SUBSCRIPTION_KIND', 'title' => 'Subscription item kind', 'f_fixed' => 1, 'note' => 'R-C-33: the kind decides which picker the subscription form shows', 'created_at' => now()]);
        foreach ([['TOOL', 'Tool / service', 1], ['IP', 'IP account', 2], ['COMPANY', 'Company', 3]] as [$v, $l, $o]) if (! DB::table('sys_field_options')->where('list_code', 'SUBSCRIPTION_KIND')->where('value', $v)->exists())
            DB::table('sys_field_options')->insert(['list_code' => 'SUBSCRIPTION_KIND', 'value' => $v, 'label' => $l, 'sort' => $o, 'f_active' => 1]);

        if (! Schema::hasColumn('t_account_social_media', 'fk_id_branch')) Schema::table('t_account_social_media', fn (Blueprint $t) => $t->integer('fk_id_branch')->nullable());
        if (! DB::table('sys_registry')->where('form_key', 't_account_social_media')->where('field_name', 'fk_id_branch')->exists())
            DB::table('sys_registry')->insert($this->field('t_account_social_media', 'fk_id_branch', 'Promotes shop', 'select', 'table:t_branches', 't_account_social_media', 'fk_id_branch', null, 'Optional: the shop this account promotes – it then shows in the shop\'s Account kit.'));

        if (! Schema::hasColumn('t_src_po', 'fk_id_buyer_account')) Schema::table('t_src_po', fn (Blueprint $t) => $t->integer('fk_id_buyer_account')->nullable());
        if (! DB::table('sys_registry')->where('form_key', 't_src_po')->where('field_name', 'fk_id_buyer_account')->exists())
            DB::table('sys_registry')->insert($this->field('t_src_po', 'fk_id_buyer_account', 'Bought with account', 'select', 'buyer:accounts', 't_src_po', 'fk_id_buyer_account', null, 'Optional: the buyer marketplace account the goods were bought with (People › Marketplace accounts, side Buyer).'));

        if (! Schema::hasColumn('fin_bank_accounts', 'fk_id_card')) Schema::table('fin_bank_accounts', fn (Blueprint $t) => $t->integer('fk_id_card')->nullable());      // R-C-29 the card's money account → spend of the statement
        DB::table('sys_registry')->where('form_key', 't_account')->whereIn('field_name', ['title', 'current_nationality', 'old_nationality'])->update(['required' => 1]);      // R-C-5 required set
        DB::table('sys_registry')->where('form_key', 't_account_email')->where('field_name', 'popup_notification_mobile')->update(['type' => 'select', 'source' => 'own:t_account_mobiles', 'label' => 'Notification mobile', 'help' => 'The mobile that gets the alerts – kept apart from the linked mobile.']);      // R-C-27
        if (! DB::table('sys_due_sources')->where('code', 'PROBATION_END')->exists())
            DB::table('sys_due_sources')->insert(['source_table' => 't_account_employment', 'date_column' => 'd_probation_end', 'title_template' => 'Probation of {job_title} ends – confirm', 'owner_rule' => 'role:OWNER',
                'remind_days' => 14, 'done_rule' => 'probation date cleared or moved', 'where_rule' => 'f_active=1', 'code' => 'PROBATION_END', 'name' => 'Probation end', 'task_code' => 'ACC_DUE_ITEM', 'chip' => 'Probation', 'f_active' => 1]);

        if (! DB::table('wa_templates')->where('code', 'LOGIN_CODE')->exists())
            DB::table('wa_templates')->insert(['code' => 'LOGIN_CODE', 'title' => 'Sign-in code', 'audience' => 'OWNER', 'body' => 'Your MoreOrderz sign-in code is {code}. It works for 5 minutes. Never share it.', 'f_active' => 1, 'language' => 'en', 'approval_status' => 'PENDING', 'params_json' => '["code"]']);
        // R-C-50 the old plain notification e-mail password: emptied, and no field any more
        if (Schema::hasColumn('t_account_emails', 'notification_email_pass')) DB::table('t_account_emails')->whereNotNull('notification_email_pass')->update(['notification_email_pass' => null]);
        DB::table('sys_registry')->where('form_key', 't_account_email')->where('field_name', 'notification_email_pass')->delete();
        // R-C-24 an address not used for all entities is linked to the chosen ones (t_account_addresses_entities)
        if (! DB::table('sys_registry')->where('form_key', 't_account_address')->where('field_name', 'entities[]')->exists() && DB::table('sys_registry')->where('form_key', 't_account_address')->exists()) {
            $r = $this->field('t_account_address', 'entities[]', 'Used for', 'multiselect', 'table:cat_module_entities', 't_account_addresses_entities', 'fk_id_entites', null, 'The modules this address is used for, when it is not used for all of them.');
            DB::table('sys_registry')->insert(['section' => 'Addresses', 'section_no' => 1, 'field_no' => 3, 'show_if' => 'f_used_for_all_entities=0'] + $r);
        }
        // D2 one party list: every supplier is a party (role SUPPLIER) linked to its supplier profile
        if (! Schema::hasColumn('t_src_suppliers', 'fk_id_account')) Schema::table('t_src_suppliers', fn (Blueprint $t) => $t->integer('fk_id_account')->nullable()->index());
        \App\Services\SupplierParty::ensureAll();
        \App\Services\Registry::forget();
    }

    private function field(string $form, string $name, string $label, string $type, ?string $source, string $table, string $col, ?string $options, string $help, ?string $default = null): array
    {
        $last = DB::table('sys_registry')->where('form_key', $form)->orderByDesc('section_no')->orderByDesc('field_no')->first();
        return ['form_key' => $form, 'form_title' => $last->form_title ?? $form, 'form_kind' => $last->form_kind ?? 'module', 'section' => $last->section ?? null, 'section_no' => (int) ($last->section_no ?? 1),
            'field_no' => (int) ($last->field_no ?? 0) + 1, 'field_name' => $name, 'label' => $label, 'type' => $type, 'source' => $source, 'required' => 0, 'in_quick' => 0, 'default_rule' => $default,
            'visible_to' => 'all', 'readonly' => 0, 'help' => $help, 'options_full' => $options, 'table_name' => $table, 'column_name' => $col, 'is_master' => 0, 'secret' => 0];
    }

    public function down(): void {}
};
