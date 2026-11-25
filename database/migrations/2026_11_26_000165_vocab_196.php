<?php

use Illuminate\Database\Migrations\Migration;

/** R-G_CORE-100: words for the sale ↔ buy check, the supplier orders (BUY) file, roles of a person and the external contact quick form. */
return new class extends Migration {
    public function up(): void { \App\Services\SearchVocab::load(); }
    public function down(): void {}
};
