<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R-C-48 WhatsApp number chosen from the person's own mobiles: t_account.fk_id_whatsapp_mobile; task ACC_WA_NUMBER when the chosen mobile is switched off.
 * Old typed numbers that equal one of the person's mobiles are linked; any other stays as it is and shows on the review list (PersonChannels::waReview).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('t_account', 'fk_id_whatsapp_mobile')) DB::statement('ALTER TABLE t_account ADD COLUMN fk_id_whatsapp_mobile INT NULL');
        if (! DB::table('sys_rules')->where('rule_code', 'ACC_WA_NUMBER')->exists())
            DB::table('sys_rules')->insert(['rule_code' => 'ACC_WA_NUMBER', 'name' => 'Choose a new WhatsApp number', 'module' => 'Accounts', 'level' => 'NORMAL', 'assign_to' => 'role:MANAGER', 'due_days' => 2, 'handler' => 'PersonChannels::waLost', 'f_active' => 1,
                'priority' => 'NORMAL', 'owner_rule' => 'record.person,role:MANAGER', 'dedupe_key' => 'ACC_WA_NUMBER:<person>', 'auto_close' => 'a number chosen', 'fix_link' => '#/people']);
        $norm = fn ($x) => preg_replace('/[^\d]/', '', (string) $x);
        foreach (DB::table('t_account')->whereNotNull('whatsapp_number')->where('whatsapp_number', '<>', '')->whereNull('fk_id_whatsapp_mobile')->get(['id', 'whatsapp_number']) as $a)
            foreach (DB::table('t_account_mobiles')->where('fk_id_account', $a->id)->where(fn ($w) => $w->whereNull('f_active')->orWhere('f_active', 1))->get(['id', 'mobile_no']) as $m)
                if ($norm($m->mobile_no) !== '' && $norm($m->mobile_no) === $norm($a->whatsapp_number)) { DB::table('t_account')->where('id', $a->id)->update(['fk_id_whatsapp_mobile' => $m->id]); break; }
    }

    public function down(): void { if (Schema::hasColumn('t_account', 'fk_id_whatsapp_mobile')) DB::statement('ALTER TABLE t_account DROP COLUMN fk_id_whatsapp_mobile'); DB::table('sys_rules')->where('rule_code', 'ACC_WA_NUMBER')->delete(); }
};
