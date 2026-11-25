<?php

use Illuminate\Database\Migrations\Migration;

/** R-G_CORE-100: words for the supplier link without login and the name matching of sourcing imports. */
return new class extends Migration {
    public function up(): void { \App\Services\SearchVocab::load(); }
    public function down(): void {}
};
