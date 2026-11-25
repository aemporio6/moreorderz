<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** R-G_CORE-100 loads database/meta/search_vocabulary.csv into sys_search_synonyms: only terms not there yet (a person's own rows are never changed). */
class SearchVocab
{
    public static function load(): int
    {
        $f = fopen(database_path('meta/search_vocabulary.csv'), 'r'); $head = fgetcsv($f); $have = DB::table('sys_search_synonyms')->pluck('term')->map(fn ($t) => mb_strtolower($t))->flip()->all(); $rows = [];
        while (($r = fgetcsv($f)) !== false) {
            $a = array_combine($head, $r); $t = mb_strtolower(trim($a['term'])); if ($t === '' || isset($have[$t])) continue; $have[$t] = 1;
            $rows[] = ['fk_id_ent' => 0, 'term' => mb_substr($t, 0, 80), 'maps_to' => mb_substr($a['maps_to'], 0, 120), 'synonym_kind' => 'VOCAB', 'target_url' => $a['target_url'] ?: null, 'f_active' => 1, 'created_by' => 0, 'created_at' => now()];
        }
        fclose($f);
        foreach (array_chunk($rows, 300) as $c) DB::table('sys_search_synonyms')->insert($c);
        return count($rows);
    }
}
