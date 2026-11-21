<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A person may hold more roles than his main one (R-A_SALES-82): his rights are the union of all of them. */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('mo_user_roles')) Schema::create('mo_user_roles', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('user_id'); $t->string('role_code', 40); $t->unsignedBigInteger('created_by')->nullable(); $t->dateTime('created_at')->nullable(); $t->unique(['user_id', 'role_code']);
        });
    }
    public function down(): void { Schema::dropIfExists('mo_user_roles'); }
};
