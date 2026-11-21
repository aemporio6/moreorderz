<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** PO line types (R-B_SOURCING-6): FEE / COST lines may leave Qty empty (lump sum in Unit Price); the form hint says so. */
return new class extends Migration {
    public function up(): void
    {
        $forms = ['direct_po_line', 'old_entry_line', 't_src_po_lines', 't_src_direct', 't_src_historical'];
        DB::table('sys_registry')->whereIn('form_key', $forms)->where('table_name', 't_src_po_lines')->where('column_name', 'unit_price')->update(['help' => 'FEE/COST: put the amount in Unit Price (Qty empty or 1). They never create stock.']);
        DB::table('sys_registry')->whereIn('form_key', ['direct_po_line', 'old_entry_line'])->where('column_name', 'qty_ordered')->update(['required' => 0, 'help' => 'Units for a PRODUCT line (above zero). Empty for a FEE / COST lump sum.']);
        DB::table('sys_registry')->whereIn('form_key', $forms)->where('table_name', 't_src_po_lines')->where('column_name', 'line_notes')->update(['help' => 'For a FEE / COST / SAMPLE line: what the amount is for (e.g. Agent fee, Inspection).']);
    }
    public function down(): void {}
};
