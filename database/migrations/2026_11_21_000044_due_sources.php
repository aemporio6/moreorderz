<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Due sources point to real columns, the missing ones are added (R-C-46). */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('sys_due_sources', function (Blueprint $t) {
            if (! Schema::hasColumn('sys_due_sources', 'where_rule')) $t->string('where_rule', 300)->nullable();
            if (! Schema::hasColumn('sys_due_sources', 'copy_role')) $t->string('copy_role', 30)->nullable();
            if (! Schema::hasColumn('sys_due_sources', 'recur_months')) $t->tinyInteger('recur_months')->nullable();
            if (! Schema::hasColumn('sys_due_sources', 'chip')) $t->string('chip', 20)->nullable();
        });
        if (! Schema::hasColumn('sys_due_items', 'source_key')) Schema::table('sys_due_items', function (Blueprint $t) { $t->string('source_key', 200)->nullable(); $t->dateTime('reminded_at')->nullable(); $t->dateTime('done_at')->nullable(); $t->string('done_how', 120)->nullable(); $t->integer('owner_user_id')->nullable(); });
        $rows = [
            ['t_account_subscriptions', 'next_due_date', 'Pay subscription #{id}', 'record.fk_id_account', 5, 'date moved forward or bank line matched', 'f_active=1', null, null, 'Subscription'],
            ['t_account_subscriptions', 'billing_date', 'Billing date subscription #{id}', 'record.fk_id_account', 5, 'date moved forward', 'f_active=1', null, null, 'Subscription'],
            ['t_account_ipburgers', 'renewal_due', 'Renew IP account #{id}', 'record.fk_id_account', 7, 'date moved forward', 'f_active=1', null, null, 'IP'],
            ['t_account_ipburgers', 'ipb_expires', 'IP expires #{id}', 'record.fk_id_account', 7, 'date moved forward', 'f_active=1', null, null, 'IP'],
            ['t_account_credit_cards', 'valid_upto', 'Card ending {card_last4} expires', 'record.fk_id_account', 30, 'date moved forward', 'f_active=1', null, null, 'Bank card'],
            ['t_account_banks', 'valid_upto', 'Bank card ending {card_last4} expires', 'record.fk_id_account', 30, 'date moved forward', 'f_active=1 AND valid_upto IS NOT NULL', 'FINANCE', null, 'Bank card'],
            ['t_account_credit_cards', 'f_card_billing_payment_date', 'Pay card ending {card_last4}', 'role:FINANCE', 5, 'date moved forward or bank line to the provider matched; then +1 month', 'f_active=1', null, 1, 'Card bill'],
            ['t_account_mobiles', 'next_recharge_date', 'Recharge SIM {mobile_no}', 'record.fk_id_account', 3, 'date moved forward', "f_active=1 AND l_recharge_contract='R'", null, null, 'SIM'],
            ['t_account_job_history', 'd_contract_renewal_date', 'Renew contract – {person} – {marketplace}', 'role:OWNER', 30, 'date moved forward', 'd_end_date IS NULL', null, null, 'Contract'],
            ['t_account_job_history', 'salary_date', 'Pay salary – {person}', 'role:FINANCE', 3, 'salary payment booked; then +1 month', 'd_end_date IS NULL', null, 1, 'Salary'],
            ['t_account_photo_ids', 'photo_expiry_date', 'Photo ID expires #{id}', 'record.fk_id_account', 45, 'date moved forward', 'f_active=1', null, null, 'Photo ID'],
            ['t_account_doc', 'd_expire', 'Document expires #{id}', 'record.fk_id_account', 30, 'date moved forward', 'f_active=1', null, null, 'Document'],
            ['cat_company_house', 'confirmation_statement_date', 'Confirmation statement – {company_name}', 'role:ACCOUNTANT', 30, 'date moved forward', 'f_active=1 AND f_external=0', null, null, 'Company'],
            ['cat_company_house', 'file_return_date', 'Accounts filing – {company_name}', 'role:ACCOUNTANT', 30, 'date moved forward', 'f_active=1 AND f_external=0', null, null, 'Company'],
        ];
        // the old seed read the person link table, which never stores the dates: the company table is the source (one item per company)
        foreach (['confirmation_statement_date', 'file_return_date'] as $c) {
            $old = DB::table('sys_due_sources')->where('source_table', 't_account_company_houses')->where('date_column', $c)->first();
            if ($old && ! DB::table('sys_due_sources')->where('source_table', 'cat_company_house')->where('date_column', $c)->exists()) DB::table('sys_due_sources')->where('id', $old->id)->update(['source_table' => 'cat_company_house']);
        }
        foreach ($rows as [$t, $c, $title, $owner, $days, $done, $where, $copy, $recur, $chip]) {
            $set = ['title_template' => $title, 'owner_rule' => $owner, 'remind_days' => $days, 'done_rule' => $done, 'where_rule' => $where, 'copy_role' => $copy, 'recur_months' => $recur, 'chip' => $chip, 'f_active' => 1];
            $set['task_code'] = DB::table('sys_due_sources')->where('source_table', $t)->where('date_column', $c)->value('task_code') ?: 'ACC_DUE_ITEM';
            DB::table('sys_due_sources')->updateOrInsert(['source_table' => $t, 'date_column' => $c], $set);
        }
        DB::table('sys_due_sources')->where('source_table', 't_account_subscriptions')->where('date_column', 'next_due_date')->update(['task_code' => 'ACC_SUBSCRIPTION_DUE']);      // the subscription's own task (pay it: the date moves on and the task closes)
        DB::table('sys_due_sources')->where('source_table', 'acc_obligations')->update(['chip' => 'Filing']);
        DB::table('sys_due_sources')->where('source_table', 't_src_payments')->update(['chip' => 'Supplier']);
        DB::table('sys_due_sources')->where('source_table', 'product_certificates')->update(['chip' => 'Certificate', 'where_rule' => 'f_active=1']);
        DB::table('sys_due_sources')->where('source_table', 't_src_payments')->update(['where_rule' => "f_active=1 AND status <> 'Paid' AND status <> 'PAID'"]);
        // subscriptions: next_due_date filled from the last payment rolled forward by the payment mode to the first date ≥ today
        $months = ['MONTHLY' => 1, 'QUARTERLY' => 3, 'YEARLY' => 12, 'ANNUAL' => 12, 'WEEKLY' => 0];
        foreach (DB::table('t_account_subscriptions')->where('f_active', 1)->whereNull('next_due_date')->whereNotNull('payment_date')->get(['id', 'payment_date', 'payment_mode', 'billing_cycle']) as $s) {
            $m = $months[strtoupper(trim((string) ($s->payment_mode ?: $s->billing_cycle)))] ?? null; if ($m === null) continue; $d = new \DateTime($s->payment_date); $today = date('Y-m-d');
            for ($i = 0; $i < 600 && $d->format('Y-m-d') < $today; $i++) $m ? $d->modify("+$m months") : $d->modify('+7 days');
            DB::table('t_account_subscriptions')->where('id', $s->id)->update(['next_due_date' => $d->format('Y-m-d')]);
        }
        // IP accounts: renewal_due = ipb_expires; the old renewal_date text is a cycle, it goes to billing_cycle when it is one
        DB::table('t_account_ipburgers')->whereNull('renewal_due')->whereNotNull('ipb_expires')->update(['renewal_due' => DB::raw('ipb_expires')]);
        foreach (['WEEKLY', 'MONTHLY', 'QUARTERLY', 'YEARLY'] as $cy) DB::table('t_account_ipburgers')->whereNull('billing_cycle')->whereRaw('UPPER(TRIM(renewal_date)) = ?', [$cy])->update(['billing_cycle' => $cy]);
        DB::table('sidebar_modules')->where('module_key', 'M_12_8_DUE_DATES')->update(['url' => '#/due']);      // the Due soon screen
        // the unused date columns of the person ↔ company link: never written by the old code; dropped when they hold nothing (owner to confirm – kept if any row has a value)
        foreach (['confirmation_statement_date', 'file_return_date'] as $c) if (Schema::hasColumn('t_account_company_houses', $c) && ! DB::table('t_account_company_houses')->whereNotNull($c)->exists()) DB::table('sys_registry')->where('table_name', 't_account_company_houses')->where('column_name', $c)->delete();
        foreach (['confirmation_statement_date', 'file_return_date'] as $c) if (Schema::hasColumn('t_account_company_houses', $c) && ! DB::table('t_account_company_houses')->whereNotNull($c)->exists()) Schema::table('t_account_company_houses', fn (Blueprint $t) => $t->dropColumn($c));
    }
    public function down(): void {}
};
