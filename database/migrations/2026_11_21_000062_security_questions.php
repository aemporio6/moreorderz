<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Security questions per provider (R-C-22): marketplace questions get their own owner type; an empty question is NULL, never 0. */
return new class extends Migration {
    public function up(): void
    {
        if (! DB::table('sys_field_options')->where('list_code', 'QUESTION_OWNER')->where('value', 'MARKETPLACE')->exists())
            DB::table('sys_field_options')->insert(['list_code' => 'QUESTION_OWNER', 'value' => 'MARKETPLACE', 'label' => 'Marketplace', 'sort' => 25, 'f_active' => 1]);
        foreach (\App\Services\SecurityQuestions::KIT as $t => [, $n, $qp]) for ($i = 1; $i <= $n; $i++)
            if ($qp === 'fk_question_id' && \Illuminate\Support\Facades\Schema::hasColumn($t, $qp . $i)) {
                DB::statement("ALTER TABLE $t MODIFY {$qp}{$i} INT NULL");
                DB::table($t)->where($qp . $i, 0)->update([$qp . $i => null]);
            }
    }
    public function down(): void {}
};
