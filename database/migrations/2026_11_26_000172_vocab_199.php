<?php

use Illuminate\Database\Migrations\Migration;

/** R-G_CORE-100: words for the product family / parent + child imports, void a purchase and the primary marketplace. */
return new class extends Migration {
    public function up(): void { \App\Services\SearchVocab::load(); }
    public function down(): void {}
};
