<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** R-A_SALES-87 the TikTok settlement workbook layout as profile data: sheet names ("sheet:<role>") and columns by role on every TIKTOK_SETTLEMENT report type. */
return new class extends Migration {
    public function up(): void
    {
        $L = \App\Services\MarketplaceFiles::LAYOUTS['TIKTOK'];
        foreach (DB::table('mp_profile_report_types')->whereIn('report_type', $L['report_types'])->get() as $r) {
            $map = (array) json_decode((string) $r->column_map_json, true);
            foreach ($L['sheets'] as $role => $name) $map['sheet:' . $role] ??= [$name];
            foreach ($L['columns'] as $c) $map[$c] ??= [$c];
            DB::table('mp_profile_report_types')->where('id', $r->id)->update(['column_map_json' => json_encode($map), 'detect_headers' => $r->detect_headers ?: 'sheet:Order details,sheet:Statements']);
        }
    }

    public function down(): void {}
};
