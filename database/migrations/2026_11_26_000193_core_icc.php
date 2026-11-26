<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Batch 7e-221 – Core / Platform:
 * - MR-0031 / 07_ICC: STK_REORDER opens the ICC page of the product (#/icc/<id> on the task, #/icc on the rule).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('sys_rules')->where('rule_code', 'STK_REORDER')->update(['fix_link' => '#/icc']);
    }

    public function down(): void {}
};
