<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Bug fix: the unique key was shipment + PO, so a PO with two product lines could not travel in one shipment. It is now shipment + PO line. */
return new class extends Migration
{
    public function up(): void
    {
        $idx = collect(DB::select("SHOW INDEX FROM t_src_shipment_po_link"))->pluck('Key_name')->unique();
        if ($idx->contains('uq_link')) DB::statement('ALTER TABLE t_src_shipment_po_link DROP INDEX uq_link');
        if (! $idx->contains('uq_link_line')) DB::statement('ALTER TABLE t_src_shipment_po_link ADD UNIQUE KEY uq_link_line (fk_id_shipment, fk_id_po_line)');
    }
    public function down(): void {}
};
