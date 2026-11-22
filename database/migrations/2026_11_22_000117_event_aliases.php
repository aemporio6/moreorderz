<?php

use Illuminate\Database\Migrations\Migration;

/** R-G_CORE-95: stored rows that still use a retired event name (task template triggers) are mapped once to the canonical name. */
return new class extends Migration
{
    public function up(): void { \App\Services\EventCatalogue::migrateStored(); }
    public function down(): void {}
};
