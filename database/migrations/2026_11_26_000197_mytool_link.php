<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Owner 26-11 20:08: MY TOOL connects to MoreOrderz directly (MY TOOL docs/moreorderz-link.md): link keys, the review inbox, the history. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mo_link_keys')) Schema::create('mo_link_keys', function (Blueprint $t) {
            $t->increments('id'); $t->integer('fk_id_ent'); $t->string('key_hash', 64)->unique(); $t->string('key_tail', 6); $t->integer('created_by');
            $t->dateTime('created_at'); $t->dateTime('revoked_at')->nullable(); $t->dateTime('last_used_at')->nullable();
        });
        if (! Schema::hasTable('mo_link_inbox')) Schema::create('mo_link_inbox', function (Blueprint $t) {
            $t->increments('id'); $t->integer('fk_id_ent'); $t->string('package_ref', 40); $t->string('kind', 30); $t->string('item_id', 80); $t->string('op', 10);
            $t->longText('data_json'); $t->string('status', 12)->default('QUEUED'); $t->string('note', 300)->nullable(); $t->dateTime('received_at'); $t->dateTime('decided_at')->nullable(); $t->integer('decided_by')->nullable();
            $t->index(['fk_id_ent', 'status']);
        });
        if (! Schema::hasTable('mo_link_history')) Schema::create('mo_link_history', function (Blueprint $t) {
            $t->increments('id'); $t->integer('fk_id_ent'); $t->string('dir', 10); $t->string('how', 10); $t->integer('n')->default(0); $t->integer('applied')->default(0); $t->integer('queued')->default(0); $t->integer('refused')->default(0);
            $t->text('ids')->nullable(); $t->string('who', 120)->nullable(); $t->dateTime('at');
        });
        if (! DB::table('sys_event_types')->where('event_code', 'MYTOOL_RECEIVED')->exists()) DB::table('sys_event_types')->insert(['event_code' => 'MYTOOL_RECEIVED', 'area' => 'Import', 'description' => 'MY TOOL sent records over the direct link (applied / waiting / refused)', 'f_active' => 1]);
        $sec = DB::table('sidebar_modules')->where('module_key', 'M_15_1_IMPORT_A_FILE')->value('parent_id');
        if ($sec && DB::table('sidebar_modules')->where('module_key', 'M_15_MYTOOL_LINK')->doesntExist()) {
            $id = (int) DB::table('sidebar_modules')->where('parent_id', $sec)->max('id_module') + 1; while (DB::table('sidebar_modules')->where('id_module', $id)->exists()) $id++;
            DB::table('sidebar_modules')->insert(['id_module' => $id, 'module_key' => 'M_15_MYTOOL_LINK', 'module_name' => 'MY TOOL link', 'url' => '#/mytool-link', 'parent_id' => $sec,
                'sort_order' => (int) DB::table('sidebar_modules')->where('parent_id', $sec)->max('sort_order') + 1, 'e_status' => 'ACTIVE', 'created_at' => now(), 'f_new_tab' => 0, 'roles' => 'OWNER', 'forms' => '']);
        }
    }

    public function down(): void {}
};
