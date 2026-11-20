<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Search everything: what a person opened lately and what he pinned (shown before he types). */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('mo_recent', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('user_id'); $t->unsignedInteger('fk_id_ent')->default(0);
            $t->string('kind', 20); $t->string('title', 255); $t->string('subtitle', 255)->nullable(); $t->string('url', 255);
            $t->boolean('f_pinned')->default(false); $t->unsignedInteger('hits')->default(1); $t->timestamps();
            $t->unique(['user_id', 'url']); $t->index(['user_id', 'updated_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('mo_recent'); }
};
