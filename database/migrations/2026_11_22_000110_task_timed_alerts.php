<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** R-G_CORE-50 timed alerts: N2 counts from the reassignment (new) – tasks.reassigned_at. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tasks', 'reassigned_at')) DB::statement('ALTER TABLE tasks ADD reassigned_at DATETIME NULL AFTER acknowledged_at');
    }

    public function down(): void
    {
        if (Schema::hasColumn('tasks', 'reassigned_at')) DB::statement('ALTER TABLE tasks DROP COLUMN reassigned_at');
    }
};
