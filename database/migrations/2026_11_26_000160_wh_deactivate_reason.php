<?php

use Illuminate\Database\Migrations\Migration;

/** R-H-6: a site and a zone are deactivated with a reason too (the bin form already asks it). */
return new class extends Migration {
    public function up(): void { foreach (['warehouse', 'warehouse_zone'] as $f) \App\Console\Commands\MoInstall::loadRegistry($f); \App\Services\Registry::forget(); }
    public function down(): void {}
};
