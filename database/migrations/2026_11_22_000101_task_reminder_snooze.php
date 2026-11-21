<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** R-G_CORE-32 reminder text of the spec (only when nobody edited it) and R-G_CORE-97 setting rule_snooze_days (7). */
return new class extends Migration {
    public function up(): void
    {
        DB::table('tm_alert_texts')->where('template_key', 'tm_reminder')->where('lang', 'en')->where('body', 'Hi {first_name}, reminder from {by}: {task_ref}: {title}, due {due}. {link}')
            ->update(['body' => 'Reminder from {by}: {task_ref} {title} (due {due}). {link}', 'params_json' => json_encode(['by', 'task_ref', 'title', 'due', 'link'])]);
        \App\Services\AlertRules::seed();
    }

    public function down(): void {}
};
