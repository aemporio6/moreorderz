<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** R-H-32: voided tracking numbers stay on the parcel (blocked at handover); address change event. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('wh_parcels', 'voided_tracking')) DB::statement('ALTER TABLE wh_parcels ADD COLUMN voided_tracking VARCHAR(500) NULL');
        foreach (['LABEL_VOIDED' => 'Parcel label voided', 'ORDER_ADDRESS_CHANGED' => 'Order address changed after the label'] as $code => $name)
            if (! DB::table('sys_event_types')->where('event_code', $code)->exists()) DB::table('sys_event_types')->insert(['event_code' => $code, 'area' => 'Warehouse', 'description' => $name, 'f_active' => 1]);
    }

    public function down(): void { if (Schema::hasColumn('wh_parcels', 'voided_tracking')) DB::statement('ALTER TABLE wh_parcels DROP COLUMN voided_tracking'); DB::table('sys_event_types')->whereIn('event_code', ['LABEL_VOIDED', 'ORDER_ADDRESS_CHANGED'])->delete(); }
};
