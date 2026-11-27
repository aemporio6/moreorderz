<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Owner 27-11 07:24 "the option is ours": a bank account of a person / company can be linked to one of our companies and to a Money bank account, or kept apart. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('t_account_banks', function (Blueprint $t) {
            if (! Schema::hasColumn('t_account_banks', 'fk_id_company_house')) $t->integer('fk_id_company_house')->nullable();
            if (! Schema::hasColumn('t_account_banks', 'fk_id_fin_bank')) $t->integer('fk_id_fin_bank')->nullable();
            if (! Schema::hasColumn('t_account_banks', 'f_kept_apart')) $t->boolean('f_kept_apart')->default(false);
        });
        $tpl = DB::table('sidebar_modules')->where('module_key', 'M_12_4_COMPANIES')->first();
        if ($tpl && ! DB::table('sidebar_modules')->where('module_key', 'M_OWNER_OPTIONS')->exists()) {
            $row = (array) $tpl; unset($row['id'], $row['id_module']);
            DB::table('sidebar_modules')->insert(array_merge($row, ['module_key' => 'M_OWNER_OPTIONS', 'module_name' => 'Workspaces & bank links', 'url' => '#/owner-options', 'screen_no' => '', 'forms' => '', 'sort_order' => (int) $tpl->sort_order + 1, 'created_at' => now()]));
        }
    }

    public function down(): void { DB::table('sidebar_modules')->where('module_key', 'M_OWNER_OPTIONS')->delete(); }
};
