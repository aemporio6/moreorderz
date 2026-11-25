<?php

use Illuminate\Database\Migrations\Migration;

/** R-B_SOURCING-66: the supplier bank form has the beneficiary name (as on the bank letter) – required, shown on the payment screen. */
return new class extends Migration {
    public function up(): void { \App\Console\Commands\MoInstall::loadRegistry('supplier_bank'); \App\Services\Registry::forget(); }
    public function down(): void {}
};
