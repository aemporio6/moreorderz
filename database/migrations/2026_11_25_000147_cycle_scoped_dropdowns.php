<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * R-B_SOURCING-22 inside a cycle every parent dropdown (PO, shipment, GRN, payment term, cycle product) lists only the records of that cycle:
 * the field follows fk_id_cycle (cascades_from); the payment term also follows the chosen PO. Outside a cycle (no cycle chosen) the list is not narrowed.
 */
return new class extends Migration {
    public const PARENTS = ['fk_id_po', 'fk_id_shipment', 'fk_id_grn', 'fk_id_term', 'fk_id_cycle_product'];

    public function up(): void
    {
        $forms = DB::table('sys_registry')->where('table_name', 'like', 't_src_%')->where('column_name', 'fk_id_cycle')->distinct()->pluck('form_key');
        foreach ($forms as $form) {
            $hasPo = DB::table('sys_registry')->where('form_key', $form)->where('column_name', 'fk_id_po')->exists();
            foreach (DB::table('sys_registry')->where('form_key', $form)->whereIn('column_name', self::PARENTS)->get(['id', 'column_name', 'cascades_from']) as $f) {
                $now = array_filter(array_map('trim', explode(',', (string) $f->cascades_from)));
                $add = $f->column_name === 'fk_id_term' && $hasPo ? ['fk_id_cycle', 'fk_id_po'] : ['fk_id_cycle'];
                DB::table('sys_registry')->where('id', $f->id)->update(['cascades_from' => implode(',', array_values(array_unique(array_merge($now, $add))))]);
            }
        }
        \App\Services\Registry::forget();
    }
    public function down(): void {}
};
