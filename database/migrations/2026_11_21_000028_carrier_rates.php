<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Carrier cost model: rates with a start date (a line keeps the rate of its own date), presets can be switched off, services offered per marketplace. */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('mo_rate_history')) Schema::create('mo_rate_history', function (Blueprint $t) {
            $t->id(); $t->string('kind', 10); $t->unsignedInteger('ref_id'); $t->decimal('rate', 10, 2); $t->date('valid_from'); $t->unsignedBigInteger('created_by')->nullable(); $t->dateTime('created_at')->nullable(); $t->unique(['kind', 'ref_id', 'valid_from'], 'mo_rate_hist_key');
        });
        if (! Schema::hasColumn('t_carrier_pkg_svc', 'f_active')) Schema::table('t_carrier_pkg_svc', function (Blueprint $t) { $t->tinyInteger('f_active')->default(1); });
        if (! Schema::hasColumn('t_carrier_services', 'offered_on')) Schema::table('t_carrier_services', function (Blueprint $t) { $t->string('offered_on', 255)->nullable(); });
        // today's rates become the first period of every preset and packing
        foreach (DB::table('t_carrier_pkg_svc')->get(['id', 'rate']) as $r) DB::table('mo_rate_history')->insertOrIgnore(['kind' => 'CARRIER', 'ref_id' => $r->id, 'rate' => (float) $r->rate, 'valid_from' => '2000-01-01', 'created_at' => now()]);
        foreach (DB::table('t_packings')->get(['id', 'packing_rate']) as $r) DB::table('mo_rate_history')->insertOrIgnore(['kind' => 'PACKING', 'ref_id' => $r->id, 'rate' => (float) $r->packing_rate, 'valid_from' => '2000-01-01', 'created_at' => now()]);
    }
    public function down(): void { Schema::dropIfExists('mo_rate_history'); }
};
