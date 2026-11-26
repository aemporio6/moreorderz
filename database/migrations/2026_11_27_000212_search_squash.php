<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Owner 27-11 01:34: everything is found however it is typed – with or without #, -, spaces. The search text is kept once more with only letters and digits. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sys_search_index', 'squash')) Schema::table('sys_search_index', fn (Blueprint $t) => $t->text('squash')->nullable());
        foreach (DB::table('sys_search_index')->select('id', 'title', 'search_text')->orderBy('id')->lazyById(1000) as $r)
            DB::table('sys_search_index')->where('id', $r->id)->update(['squash' => \App\Services\SearchIndex::squash($r->title . ' ' . $r->search_text)]);
    }

    public function down(): void { if (Schema::hasColumn('sys_search_index', 'squash')) Schema::table('sys_search_index', fn (Blueprint $t) => $t->dropColumn('squash')); }
};
