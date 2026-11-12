<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Old tables use '0000-00-00' as a default date, which a strict database refuses. An empty date is NULL from now on. */
return new class extends Migration
{
    public function up(): void
    {
        $cols = DB::select("SELECT table_name t, column_name c, column_type ct FROM information_schema.columns WHERE table_schema = DATABASE() AND column_default LIKE '%0000-00-00%'");
        DB::statement("SET SESSION sql_mode = ''");
        foreach ($cols as $c) {
            DB::statement("ALTER TABLE `{$c->t}` MODIFY `{$c->c}` {$c->ct} NULL DEFAULT NULL");
            DB::statement("UPDATE `{$c->t}` SET `{$c->c}` = NULL WHERE CAST(`{$c->c}` AS CHAR) LIKE '0000-00-00%'");
        }
    }

    public function down(): void
    {
    }
};
