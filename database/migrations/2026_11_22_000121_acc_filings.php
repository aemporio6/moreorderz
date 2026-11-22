<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** R-ACC-11 the OTHER filing type, R-ACC-12 the N17 filing reminders rule and its text (from AlertRules::seed()). */
return new class extends Migration
{
    public function up(): void
    {
        \App\Services\AccCompliance::seedTypes();
        \App\Services\AlertRules::seed();
    }

    public function down(): void
    {
        DB::table('tm_alert_rules')->where('code', 'N17')->delete();
        DB::table('acc_obligation_types')->where('type_code', 'OTHER')->where('fk_id_ent', 0)->delete();
    }
};
