<?php

namespace App\Console\Commands;

use App\Services\RecordService;
use App\Services\Registry;
use App\Services\SearchIndex;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MoReindex extends Command
{
    protected $signature = 'mo:reindex {--form= : only this form}';
    protected $description = 'Rebuild "search everything" for all records (run after an import)';

    public function handle(): int
    {
        $tables = [];
        foreach (DB::table('sys_registry')->distinct()->pluck('form_key') as $key) {
            $t = Registry::table($key);
            if ($t && Registry::columns($t) && RecordService::formOfTable($t) === $key) $tables[$t] = $key;
        }
        if ($f = $this->option('form')) $tables = array_filter($tables, fn ($k) => $k === $f);
        $n = 0;
        foreach ($tables as $table => $key) {
            $pk = Registry::pk($table);
            DB::table($table)->orderBy($pk)->select($pk)->chunk(500, function ($rows) use ($key, $table, $pk, &$n) {
                foreach ($rows as $r) { SearchIndex::put($key, $table, (int) $r->$pk); $n++; }
            });
        }
        $this->info("$n records indexed from " . count($tables) . ' tables');
        return 0;
    }
}
