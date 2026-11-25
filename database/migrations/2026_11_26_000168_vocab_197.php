<?php

use Illuminate\Database\Migrations\Migration;

/** R-G_CORE-100: words for the Etsy month header and its sale files, case channel / reply by, carrier service facts, returns window and seller portal. */
return new class extends Migration {
    public function up(): void { \App\Services\SearchVocab::load(); }
    public function down(): void {}
};
