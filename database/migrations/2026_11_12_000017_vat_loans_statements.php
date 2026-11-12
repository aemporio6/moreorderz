<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** VAT returns, loan payments, capital-call state, partner answers on statements, settings for VAT. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mo_vat_returns', function (Blueprint $t) {
            $t->id();
            $t->integer('fk_id_ent');
            $t->date('period_from');
            $t->date('period_to');
            foreach (['box1', 'box2', 'box3', 'box4', 'box5', 'box6', 'box7', 'box8', 'box9'] as $b) $t->decimal($b, 14, 2)->default(0);
            $t->text('detail_json')->nullable();
            $t->enum('status', ['DRAFT', 'BOOKED', 'PAID'])->default('DRAFT');
            $t->unsignedBigInteger('journal_id')->nullable();
            $t->unsignedBigInteger('pay_journal_id')->nullable();
            $t->string('reference', 60)->nullable();
            $t->integer('booked_by')->nullable();
            $t->dateTime('booked_at')->nullable();
            $t->dateTime('paid_at')->nullable();
            $t->unique(['fk_id_ent', 'period_from', 'period_to']);
        });
        Schema::table('fin_loans', function (Blueprint $t) {
            $t->decimal('interest_rate', 6, 3)->nullable();          // % a year
            $t->decimal('instalment', 14, 2)->nullable();
            $t->date('next_due_date')->nullable();
            $t->enum('frequency', ['MONTHLY', 'QUARTERLY', 'YEARLY', 'ONE_OFF'])->default('MONTHLY');
            $t->integer('fk_id_bank_account')->nullable();
            $t->integer('fk_id_account')->nullable();                // the lender / borrower as a party
            $t->unsignedBigInteger('journal_id')->nullable();
        });
        Schema::create('fin_loan_payments', function (Blueprint $t) {
            $t->id();
            $t->integer('fk_id_loan');
            $t->date('pay_date');
            $t->decimal('principal', 14, 2)->default(0);
            $t->decimal('interest', 14, 2)->default(0);
            $t->integer('fk_id_bank_account');
            $t->unsignedBigInteger('journal_id')->nullable();
            $t->string('note', 255)->nullable();
            $t->integer('created_by')->nullable();
            $t->dateTime('created_at')->useCurrent();
        });
        Schema::table('fin_capital_calls', function (Blueprint $t) {
            $t->enum('status', ['OPEN', 'CLOSED', 'CANCELLED'])->default('OPEN');
            $t->integer('fk_id_branch')->nullable();
        });
        Schema::table('fin_capital_call_responses', function (Blueprint $t) {
            $t->decimal('amount_asked', 14, 2)->default(0);
            $t->decimal('amount_received', 14, 2)->default(0);
        });
        Schema::table('fin_statements', function (Blueprint $t) {
            $t->enum('partner_status', ['NEW', 'ACCEPTED', 'QUERIED'])->default('NEW');
            $t->dateTime('accepted_at')->nullable();
        });
        foreach ([['VAT_RATE', '20'], ['VAT_QUARTER_START_MONTH', '1']] as [$k, $v])
            if (! DB::table('sys_settings')->where('setting_key', $k)->exists()) DB::table('sys_settings')->insert(['setting_key' => $k, 'fk_id_ent' => 0, 'setting_value' => $v, 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('fin_statements', fn (Blueprint $t) => $t->dropColumn(['partner_status', 'accepted_at']));
        Schema::table('fin_capital_call_responses', fn (Blueprint $t) => $t->dropColumn(['amount_asked', 'amount_received']));
        Schema::table('fin_capital_calls', fn (Blueprint $t) => $t->dropColumn(['status', 'fk_id_branch']));
        Schema::dropIfExists('fin_loan_payments');
        Schema::table('fin_loans', fn (Blueprint $t) => $t->dropColumn(['interest_rate', 'instalment', 'next_due_date', 'frequency', 'fk_id_bank_account', 'fk_id_account', 'journal_id']));
        Schema::dropIfExists('mo_vat_returns');
        DB::table('sys_settings')->whereIn('setting_key', ['VAT_RATE', 'VAT_QUARTER_START_MONTH'])->where('fk_id_ent', 0)->delete();
    }
};
