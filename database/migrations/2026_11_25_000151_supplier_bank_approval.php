<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SUP_BANK (06_ENGINES §A, R-FIN-70, R-B_SOURCING-57): bank details of a supplier / forwarder / payee are approved before they can be paid.
 * A new or changed row is PENDING: a second person records the phone check with the supplier, then the Owner approves.
 * Rows that are not of a supplier / forwarder stay APPROVED (a staff or partner bank change is a normal history entry).
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('t_account_banks', 'approval_status')) Schema::table('t_account_banks', function (Blueprint $t) {
            $t->string('approval_status', 12)->default('APPROVED'); $t->integer('bank_changed_by')->nullable(); $t->dateTime('bank_changed_at')->nullable();
            $t->integer('phone_checked_by')->nullable(); $t->dateTime('phone_checked_at')->nullable(); $t->string('phone_check_note', 250)->nullable();
            $t->integer('bank_approved_by')->nullable(); $t->dateTime('bank_approved_at')->nullable(); $t->integer('fk_id_bank_approval')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('t_account_banks', fn (Blueprint $t) => $t->dropColumn(['approval_status', 'bank_changed_by', 'bank_changed_at', 'phone_checked_by', 'phone_checked_at', 'phone_check_note', 'bank_approved_by', 'bank_approved_at', 'fk_id_bank_approval']));
    }
};
