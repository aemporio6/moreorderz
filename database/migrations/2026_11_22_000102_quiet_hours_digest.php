<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** R-G_CORE-47 quiet hours note + "waits for next working day" per rule; R-G_CORE-45 digest texts and settings. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tm_messages', function (Blueprint $t) { if (! Schema::hasColumn('tm_messages', 'note')) $t->string('note', 120)->nullable(); });
        Schema::table('tm_alert_rules', function (Blueprint $t) { if (! Schema::hasColumn('tm_alert_rules', 'wait_workday')) $t->tinyInteger('wait_workday')->default(0); });
        foreach (['tm_digest' => ['Your tasks today', 'Good morning {first_name}: {late} late, {today} due today, {soon} due soon, {stuck_on_me} stuck on you, {to_approve} to approve. {link}', 'Your tasks for {date}'],
                  'tm_team_digest' => ['Your team today', 'Team today, {first_name}: {late} late, {stuck} stuck, {waiting} waiting approval, {over_limit} over the weekly limit. {link}', 'Your team for {date}']] as $k => [$s, $b, $ns])
            DB::table('tm_alert_texts')->where('template_key', $k)->where('lang', 'en')->where('body', $b)->update(['subject' => $ns, 'body' => '{body}', 'params_json' => json_encode(['body'])]);
        \App\Services\AlertRules::seed();
    }

    public function down(): void {}
};
