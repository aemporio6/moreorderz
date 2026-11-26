<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** MR-0301 / 0302: a sourcing investor is a partner account; every sourcing investment is the partner's CAPITAL_IN (one partner, not three). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('t_src_investors', 'fk_id_account')) Schema::table('t_src_investors', fn (Blueprint $t) => $t->integer('fk_id_account')->nullable()->index());
        $last = DB::table('sys_registry')->where('table_name', 't_src_investors')->orderByDesc('field_no')->first();
        if ($last && ! DB::table('sys_registry')->where('form_key', $last->form_key)->where('field_name', 'fk_id_account')->exists())
            DB::table('sys_registry')->insert(['form_key' => $last->form_key, 'form_title' => $last->form_title, 'form_kind' => $last->form_kind, 'section' => $last->section, 'section_no' => $last->section_no, 'field_no' => (int) $last->field_no + 1,
                'field_name' => 'fk_id_account', 'label' => 'Partner account', 'type' => 'select', 'source' => 'table:t_account', 'required' => 0, 'in_quick' => 1, 'visible_to' => 'all', 'readonly' => 0,
                'help' => 'The partner this investor is – his sourcing investments count as his capital in Partners › Each partner. Found by name the first time.', 'table_name' => 't_src_investors', 'column_name' => 'fk_id_account', 'is_master' => 0, 'secret' => 0]);
        foreach (DB::table('t_enterprise')->pluck('id') as $ent) \App\Services\SourcingCapital::syncAll((int) $ent);
    }

    public function down(): void
    {
        DB::table('fin_investor_ledger')->where('reference_no', 'like', 'SRC-INV-%')->delete();
        DB::table('sys_registry')->where('table_name', 't_src_investors')->where('field_name', 'fk_id_account')->delete();
        if (Schema::hasColumn('t_src_investors', 'fk_id_account')) Schema::table('t_src_investors', fn (Blueprint $t) => $t->dropColumn('fk_id_account'));
    }
};
