<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Shipment ↔ PO links (R-B_SOURCING-38): the reason when a PO ships more than it ordered. */
return new class extends Migration {
    public function up(): void { if (! Schema::hasColumn('t_src_shipment_po_link', 'over_reason')) DB::statement('ALTER TABLE t_src_shipment_po_link ADD over_reason VARCHAR(255) NULL'); }
    public function down(): void {}
};
