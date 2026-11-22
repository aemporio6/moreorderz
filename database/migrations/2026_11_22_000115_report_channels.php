<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * R-G_CORE-44 the weekly report also as a WhatsApp document (N14 + WHATSAPP when the channels were still the old default),
 * R-G_CORE-40 the monthly leaderboard N15, R-G_CORE-45 the Owner exceptions digest N16 – rows and texts from AlertRules::seed().
 */
return new class extends Migration
{
    public function up(): void
    {
        \App\Services\AlertRules::seed();
        DB::table('tm_alert_rules')->where('code', 'N14')->where('channels_json', json_encode(['EMAIL', 'INAPP']))->update(['channels_json' => json_encode(['EMAIL', 'WHATSAPP', 'INAPP'])]);
    }

    public function down(): void
    {
        DB::table('tm_alert_rules')->whereIn('code', ['N15', 'N16'])->delete();
        DB::table('tm_alert_rules')->where('code', 'N14')->where('channels_json', json_encode(['EMAIL', 'WHATSAPP', 'INAPP']))->update(['channels_json' => json_encode(['EMAIL', 'INAPP'])]);
    }
};
