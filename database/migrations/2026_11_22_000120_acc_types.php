<?php

use Illuminate\Database\Migrations\Migration;

/** R-ACC-9: the filing types of the compliance calendar (company 0 = for every company; switched off per type in the list). */
return new class extends Migration
{
    public function up(): void { \App\Services\AccCompliance::seedTypes(); }
    public function down(): void {}
};
