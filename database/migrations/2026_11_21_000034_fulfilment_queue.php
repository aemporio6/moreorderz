<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Daily fulfilment orders (R-A_SALES-75): the extra fields of a hand-entered order, beside its sale line. */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('mo_sale_fulfilment')) Schema::create('mo_sale_fulfilment', function (Blueprint $t) {
            $t->unsignedBigInteger('fk_id_sale')->primary(); $t->unsignedInteger('fk_id_ent'); $t->string('item_name', 255)->default('');
            foreach (['custom_1', 'custom_2', 'custom_3'] as $c) $t->string($c, 255)->default('');
            foreach (['buyer_full_name', 'buyer_first_name', 'buyer_last_name', 'recipient_first_name', 'recipient_last_name'] as $c) $t->string($c, 150)->default('');
            $t->string('buyer_email', 190)->default(''); $t->string('buyer_phone', 40)->default(''); $t->string('recipient_phone', 40)->default(''); $t->string('state', 100)->default('');
            foreach (['address_1', 'address_2', 'address_3'] as $c) $t->string($c, 255)->default('');
            $t->string('country_name', 100)->default(''); $t->tinyInteger('f_done')->default(0); $t->unsignedBigInteger('done_by')->nullable(); $t->dateTime('done_at')->nullable();
            $t->unsignedBigInteger('created_by')->nullable(); $t->dateTime('created_at')->nullable(); $t->dateTime('updated_at')->nullable(); $t->index(['fk_id_ent', 'f_done']); $t->index('created_by');
        });
    }
    public function down(): void { Schema::dropIfExists('mo_sale_fulfilment'); }
};
