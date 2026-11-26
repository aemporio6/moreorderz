<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** R-G_CORE-87: the status lists of the Finance mapping are Reference Register lists (Setup › Lists), not text in a config file. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (\App\Services\OpeningBalances::LISTS as $code => [$name, $items]) {
            $id = DB::table('cat_references')->where('ref_code', $code)->value('id');
            if (! $id) $id = DB::table('cat_references')->insertGetId(['reference_heading' => $name, 'ref_code' => $code, 'f_active' => 1, 'fk_id_ent' => 0, 'department' => 'FINANCE', 'ref_type' => 'LIST', 'description' => 'R-G_CORE-87 – which statuses count', 'f_system' => 1, 'created_at' => now()]);
            $n = 0; foreach ($items as $ic => $label) { $n++;
                if (! DB::table('reference_items')->where('fk_reference_id', $id)->where('item_code', $ic)->exists())
                    DB::table('reference_items')->insert(['fk_reference_id' => $id, 'reference_name' => $label, 'item_code' => $ic, 'sort_no' => $n, 'f_active' => 1, 'fk_id_ent' => 0, 'created_at' => now()]); }
        }
    }

    public function down(): void {}
};
