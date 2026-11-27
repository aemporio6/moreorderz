<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Owner 27-11 06:43: every tracking number a link to the carrier's own page – couriers, sea lines, road, air; + Add carrier where one is missing. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cat_tracking_carriers')) Schema::create('cat_tracking_carriers', function (Blueprint $t) {
            $t->id(); $t->integer('fk_id_ent')->default(0)->index(); $t->string('name', 80); $t->string('mode', 10)->default('COURIER');
            $t->string('url_pattern', 300); $t->string('number_rule', 160)->nullable(); $t->string('aliases', 200)->nullable(); $t->integer('sort_no')->default(100);
            $t->boolean('f_active')->default(true); $t->boolean('f_system')->default(false); $t->integer('created_by')->nullable(); $t->timestamps();
        });
        foreach (\App\Services\TrackingLinks::BUILT_IN as $i => [$name, $mode, $url, $rule, $aliases]) {
            if (DB::table('cat_tracking_carriers')->where('name', $name)->where('fk_id_ent', 0)->exists()) continue;
            DB::table('cat_tracking_carriers')->insert(['fk_id_ent' => 0, 'name' => $name, 'mode' => $mode, 'url_pattern' => $url, 'number_rule' => $rule, 'aliases' => $aliases, 'sort_no' => $i, 'f_active' => 1, 'f_system' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void {}
};
