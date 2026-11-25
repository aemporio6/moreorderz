<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * R-G_CORE-100 (owner 25-11): the search knows the trade's words – about 2,100 terms of e-commerce (Amazon, eBay, TikTok, Temu, OnBuy, Vinted, Etsy …),
 * China sourcing, shipping, banks, tax, brands and companies – each opening its screen (database/meta/search_vocabulary.csv, made by tools/make_search_vocabulary.py).
 * They are ordinary search-synonym rows (Settings › Search synonyms): add, edit, switch off; a company row of the same term wins. Existing rows are never changed.
 */
return new class extends Migration {
    public function up(): void
    {
        $f = fopen(database_path('meta/search_vocabulary.csv'), 'r'); $head = fgetcsv($f); $have = DB::table('sys_search_synonyms')->pluck('term')->map(fn ($t) => mb_strtolower($t))->flip()->all(); $rows = [];
        while (($r = fgetcsv($f)) !== false) {
            $a = array_combine($head, $r); $t = mb_strtolower(trim($a['term'])); if ($t === '' || isset($have[$t])) continue; $have[$t] = 1;
            $rows[] = ['fk_id_ent' => 0, 'term' => mb_substr($t, 0, 80), 'maps_to' => mb_substr($a['maps_to'], 0, 120), 'synonym_kind' => 'VOCAB', 'target_url' => $a['target_url'] ?: null, 'f_active' => 1, 'created_by' => 0, 'created_at' => now()];
        }
        fclose($f);
        foreach (array_chunk($rows, 300) as $c) DB::table('sys_search_synonyms')->insert($c);
    }

    public function down(): void { DB::table('sys_search_synonyms')->where('synonym_kind', 'VOCAB')->delete(); }
};
