<?php

use Illuminate\Database\Migrations\Migration;

/** R-G_CORE-100: new screens join the search – warehouse CSV (WH_LOCATIONS / WH_SKU_HOMES), person sections by kind / Convert, delete or restore a person. */
return new class extends Migration {
    public function up(): void { \App\Services\SearchVocab::load(); }
    public function down(): void {}
};
