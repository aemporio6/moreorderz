<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Task rules for loan payments due and partner questions on statements. */
return new class extends Migration
{
    private const RULES = [
        ['FIN_LOAN_DUE', 'Loan payment due', 'role:FINANCE', 'FinanceExtras::loanTasks', 'FIN_LOAN_DUE:<loan>', 'payment recorded', '#/loans'],
        ['FIN_STATEMENT_QUERY', 'Partner asked about a statement', 'role:FINANCE', 'FinanceExtras::statementAnswer', 'FIN_STATEMENT_QUERY:<statement>:<time>', 'answered', '#/partners'],
    ];

    public function up(): void
    {
        foreach (self::RULES as [$code, $name, $to, $handler, $key, $close, $link])
            if (! DB::table('sys_rules')->where('rule_code', $code)->exists())
                DB::table('sys_rules')->insert(['rule_code' => $code, 'name' => $name, 'module' => 'Finance', 'level' => 'NORMAL', 'assign_to' => $to, 'due_days' => 1, 'handler' => $handler, 'f_active' => 1, 'priority' => 'HIGH', 'category_id' => 10, 'dedupe_key' => $key, 'auto_close' => $close, 'fix_link' => $link]);
    }

    public function down(): void
    {
        DB::table('sys_rules')->whereIn('rule_code', array_column(self::RULES, 0))->delete();
    }
};
