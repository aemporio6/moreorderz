<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Task score weights and colour bands as settings (R-G_CORE-8, R-G_CORE-51); task history may be written by the system. */
return new class extends Migration {
    public function up(): void
    {
        \App\Services\AlertRules::seed();
        DB::statement('ALTER TABLE task_history MODIFY user_id INT NULL');
    }
    public function down(): void {}
};
