<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * R-FIN-69 the Amazon / eBay money-file layouts become profile data: every profile report type of a known layout gets its columns (role → the file's
 * header name, same as the role at first) and the headers that recognise the file; an Amazon profile also gets the date range report (AMAZON_TRANSACTIONS).
 * Changing a header name later = editing the profile, not the code.
 */
return new class extends Migration {
    public function up(): void
    {
        $L = \App\Services\MarketplaceFiles::LAYOUTS;
        foreach (DB::table('mp_profile_report_types')->get() as $r) foreach ($L as $fmt => $x) {
            if (! in_array($r->report_type, $x['report_types'], true)) continue;
            $map = (array) json_decode((string) $r->column_map_json, true);
            foreach ($x['columns'] as $c) if (! isset($map[$c])) $map[$c] = [$c];
            DB::table('mp_profile_report_types')->where('id', $r->id)->update(['column_map_json' => json_encode($map), 'detect_headers' => $r->detect_headers ?: implode(',', $x['detect'])]);
        }
        foreach (DB::table('mp_profile_report_types')->where('report_type', 'AMAZON_SETTLEMENT_V2')->pluck('fk_id_mp_profile')->unique() as $pid)
            if (! DB::table('mp_profile_report_types')->where('fk_id_mp_profile', $pid)->where('report_type', 'AMAZON_TRANSACTIONS')->exists())
                DB::table('mp_profile_report_types')->insert(['fk_id_mp_profile' => $pid, 'report_type' => 'AMAZON_TRANSACTIONS', 'report_role' => 'MONEY', 'f_required_for_close' => 0, 'detect_headers' => implode(',', $L['AMAZON_TXN']['detect']),
                    'f_active' => 1, 'column_map_json' => json_encode(array_combine($L['AMAZON_TXN']['columns'], array_map(fn ($c) => [$c], $L['AMAZON_TXN']['columns'])))]);
    }

    public function down(): void { DB::table('mp_profile_report_types')->where('report_type', 'AMAZON_TRANSACTIONS')->delete(); }
};
