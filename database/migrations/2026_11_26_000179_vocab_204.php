<?php

use Illuminate\Database\Migrations\Migration;

/** R-G_CORE-100: words for the task board (kanban + calendar). */
return new class extends Migration {
    public function up(): void { \App\Services\SearchVocab::load(); }
    public function down(): void {}
};
