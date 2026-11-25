<?php

use Illuminate\Database\Migrations\Migration;

/** R-G_CORE-100: new screens join the search – the words added to the vocabulary since the last load (carrier file layouts, refund on a confirmed line). */
return new class extends Migration {
    public function up(): void { \App\Services\SearchVocab::load(); }
    public function down(): void {}
};
