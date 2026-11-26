<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** R-G_CORE-108 data-quality score per master record. */
return new class extends Migration {
    public function up(): void
    {
        /* the table sys_dq_scores (form, record, score %, missing fields, computed at) already exists from the first schema */
        /* the sale-line data-quality tasks (R-G_CORE-82) get their own key part, the code is shared with the score */
        DB::table('tasks')->where('rule_code', 'DQ_ITEM_NOTE')->whereRaw("dedupe_key REGEXP '^DQ_ITEM_NOTE:[0-9]+$'")->update(['dedupe_key' => DB::raw("CONCAT('DQ_ITEM_NOTE:sale:', SUBSTRING(dedupe_key, 14))")]);
    }

    public function down(): void {}
};
