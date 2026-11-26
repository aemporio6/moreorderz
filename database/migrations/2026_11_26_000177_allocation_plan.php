<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** R-F_SETUP-47 allocations drive the stock plan: proposed transfers after a GRN (nothing moves until confirmed). */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('src_transfer_proposals')) Schema::create('src_transfer_proposals', function (Blueprint $t) {
            $t->increments('id'); $t->integer('fk_id_ent'); $t->integer('fk_id_grn')->index(); $t->integer('fk_id_allocation')->index(); $t->integer('fk_id_product'); $t->integer('qty'); $t->integer('fk_id_from'); $t->integer('fk_id_to');
            $t->string('status', 10)->default('PROPOSED'); $t->string('note', 255)->nullable(); $t->integer('done_by')->nullable(); $t->dateTime('done_at')->nullable(); $t->dateTime('created_at');
        });
        if (! DB::table('sys_rules')->where('rule_code', 'SRC_ALLOC_TRANSFER')->exists())
            DB::table('sys_rules')->insert(['rule_code' => 'SRC_ALLOC_TRANSFER', 'name' => 'Confirm the transfer an allocation proposes', 'module' => 'Sourcing', 'level' => 'NORMAL', 'assign_to' => 'role:WAREHOUSE', 'due_days' => 2, 'handler' => 'AllocationPlan::propose', 'f_active' => 1,
                'priority' => 'NORMAL', 'owner_rule' => 'role:WAREHOUSE', 'dedupe_key' => 'SRC_ALLOC_TRANSFER:<proposal>', 'auto_close' => 'confirmed or dismissed', 'fix_link' => '#/allocation-plan']);
        \App\Services\SearchVocab::load();
    }

    public function down(): void {}
};
