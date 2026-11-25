<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** R-C-2: which person sections / tabs each kind shows (sys_form_sections.visible_kind ALL | M | E) and the event PERSON_KIND_CHANGED. */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('sys_form_sections', 'visible_kind')) DB::statement("ALTER TABLE sys_form_sections ADD visible_kind ENUM('ALL','M','E') NULL DEFAULT 'ALL'");
        if (! DB::table('sys_event_types')->where('event_code', 'PERSON_KIND_CHANGED')->exists()) DB::table('sys_event_types')->insert(['event_code' => 'PERSON_KIND_CHANGED', 'area' => 'Masters', 'description' => 'A person was converted Managed Account ↔ External Contact', 'f_active' => 1]);
        \App\Services\PersonKind::seed();
    }
    public function down(): void {}
};
