<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Owner rule 22 Nov 03:47: the director roles (R-F_SETUP-38) are no longer fixed – Directors 1–3 read the Settings › Choices multiple choice DIRECTOR_ROLES. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('sys_registry')->where('source', 'party:OWNER,DIRECTOR,PARTNER')->update(['source' => 'party:@DIRECTOR_ROLES']);
    }

    public function down(): void
    {
        DB::table('sys_registry')->where('source', 'party:@DIRECTOR_ROLES')->update(['source' => 'party:OWNER,DIRECTOR,PARTNER']);
    }
};
