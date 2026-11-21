<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** VA statement per shop + month: the message sent to the VA and the columns the approval needs. */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('va_statements', 'after_fee_profit')) Schema::table('va_statements', function (Blueprint $t) { $t->decimal('after_fee_profit', 14, 2)->default(0); $t->tinyInteger('f_expenses_before_share')->default(0); $t->string('reject_reason', 255)->nullable(); $t->dateTime('sent_at')->nullable(); $t->unsignedBigInteger('fk_id_approval')->nullable(); });
        DB::table('wa_templates')->updateOrInsert(['code' => 'VA_STATEMENT'], ['title' => 'VA statement', 'audience' => 'VA', 'body' => 'Your statement for {shop}, {month} is approved: after-fee profit {profit}, your share ({pct} %) {share}. {link}', 'f_active' => 1]);
    }
    public function down(): void {}
};
