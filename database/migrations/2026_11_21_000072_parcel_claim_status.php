<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Lost parcels (R-H-36): claim_status must hold LOST_INVESTIGATING (18 characters). */
return new class extends Migration {
    public function up(): void { DB::statement('ALTER TABLE wh_parcels MODIFY claim_status VARCHAR(20) NULL'); }
    public function down(): void {}
};
