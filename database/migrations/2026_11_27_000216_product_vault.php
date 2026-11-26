<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Owner 27-11 02:31: the product's vault – each file of a product in its place (images, listing images per marketplace, A+, labels, artwork, dielines …). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_assets')) return;
        Schema::create('product_assets', function (Blueprint $t) {
            $t->id(); $t->integer('fk_id_ent')->index(); $t->integer('fk_id_product')->index(); $t->integer('fk_id_document'); $t->string('kind', 30)->default('OTHER');
            $t->integer('fk_id_marketplace')->nullable(); $t->tinyInteger('f_main')->default(0); $t->integer('sort_no')->default(0); $t->integer('created_by')->nullable(); $t->timestamps();
            $t->unique(['fk_id_product', 'fk_id_document']);
        });
    }

    public function down(): void { Schema::dropIfExists('product_assets'); }
};
