<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** R-F_SETUP-13: GRN A4 and the 100 × 150 GRN carton label – templates and routes of the two new print kinds. */
return new class extends Migration {
    public function up(): void { if (DB::table('print_adapters')->exists()) \App\Services\PrintService::seed(); }
    public function down(): void
    {
        DB::table('label_templates')->whereIn('code', ['GRN_A4', 'GRN_LABEL_100X150'])->delete(); DB::table('print_routes')->whereIn('job_kind', ['GRN', 'GRN_LABEL'])->delete();
    }
};
