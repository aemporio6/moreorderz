<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** R-B_SOURCING-52 system values of the sourcing lists: a flag, and every company gets the missing ones (listed in the migration log). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('cat_src_lov', 'f_system')) Schema::table('cat_src_lov', fn (Blueprint $t) => $t->tinyInteger('f_system')->default(0));
        $ents = DB::table('t_users')->where('fk_id_ent', '>', 0)->distinct()->pluck('fk_id_ent')->merge(DB::table('cat_src_lov')->where('fk_id_ent', '>', 0)->distinct()->pluck('fk_id_ent'))->unique();
        foreach ($ents as $e) { $a = \App\Services\SrcLists::ensure((int) $e); if ($a) echo '  company ' . $e . ': added system values ' . implode('; ', $a) . PHP_EOL; }
    }

    public function down(): void { if (Schema::hasColumn('cat_src_lov', 'f_system')) Schema::table('cat_src_lov', fn (Blueprint $t) => $t->dropColumn('f_system')); }
};
