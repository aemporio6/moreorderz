<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** A person may be limited to the inventory of one or more vendors (R-A_SALES-42 "Vendor inventory"): scope kind VENDOR. */
return new class extends Migration {
    public function up(): void { DB::statement("ALTER TABLE mo_user_scopes MODIFY kind ENUM('SHOP','LOCATION','VENDOR') NOT NULL"); }
    public function down(): void {}
};
