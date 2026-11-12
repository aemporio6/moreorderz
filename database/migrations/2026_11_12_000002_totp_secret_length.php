<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** The 2-step secret is stored encrypted, which is longer than the plain secret. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE t_users MODIFY totp_secret VARCHAR(500) NULL');
    }

    public function down(): void
    {
    }
};
