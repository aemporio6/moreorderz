<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Owner 26-11 22:52: every central list that is empty on this database gets its values from the register (options_full); two reference lists get starting values. */
return new class extends Migration
{
    public function up(): void
    {
        $codes = DB::table('sys_registry')->where('source', 'like', 'list:%')->pluck('source')->map(fn ($s) => substr((string) $s, 5))->unique();
        foreach ($codes as $c) \App\Services\Registry::fillList((string) $c);
        $defaults = ['IMPORTER_OF_RECORD' => ['Our company', 'The forwarder', 'The supplier (DDP)', 'A customs agent'], 'NICE_CLASS' => array_map(fn ($i) => 'Class ' . $i, range(1, 45))];
        foreach ($defaults as $code => $values) {
            $ref = DB::table('cat_references')->where('ref_code', $code)->value('id'); if (! $ref || DB::table('reference_items')->where('fk_reference_id', $ref)->exists()) continue;
            foreach ($values as $i => $v) DB::table('reference_items')->insert(['fk_reference_id' => $ref, 'reference_name' => $v, 'sort_no' => $i + 1, 'f_active' => 1, 'f_pending' => 0, 'fk_id_ent' => 0, 'created_at' => now(), 'updated_at' => now()]);
        }
        \App\Services\Registry::forget();
    }

    public function down(): void {}
};
