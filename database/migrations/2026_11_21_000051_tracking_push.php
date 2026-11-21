<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Ready to dispatch and tracking upload (R-H-33): when a parcel's tracking went back to the marketplace, and how. */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('wh_parcels', 'tracking_uploaded_at')) DB::statement('ALTER TABLE wh_parcels ADD tracking_uploaded_at DATETIME NULL, ADD tracking_upload_via VARCHAR(8) NULL, ADD tracking_upload_ref VARCHAR(60) NULL');
    }
    public function down(): void {}
};
