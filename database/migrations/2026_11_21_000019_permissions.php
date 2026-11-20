<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Permission engine: roles as data, a right per action, per-person extras, data scope, field rules, access requests. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mo_roles', function (Blueprint $t) {
            $t->id(); $t->string('role_code', 30)->unique(); $t->string('label', 80); $t->string('like_role', 20);   // a made role starts as a copy of a built-in one
            $t->boolean('f_system')->default(0); $t->boolean('f_active')->default(1); $t->string('notes', 255)->nullable(); $t->timestamps();
        });
        Schema::create('mo_role_perms', function (Blueprint $t) {
            $t->id(); $t->string('role_code', 30); $t->string('perm_code', 120); $t->boolean('allowed'); $t->unsignedBigInteger('updated_by')->nullable(); $t->timestamps();
            $t->unique(['role_code', 'perm_code']);
        });
        Schema::create('mo_user_perms', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('user_id'); $t->string('perm_code', 120); $t->enum('effect', ['GRANTED', 'REVOKED']); $t->string('reason', 255); $t->date('until')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable(); $t->timestamps(); $t->unique(['user_id', 'perm_code']);
        });
        Schema::create('mo_user_scopes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('user_id'); $t->enum('kind', ['SHOP', 'LOCATION']); $t->unsignedBigInteger('ref_id'); $t->unsignedBigInteger('created_by')->nullable(); $t->timestamps();
            $t->unique(['user_id', 'kind', 'ref_id']);
        });
        Schema::create('mo_field_rules', function (Blueprint $t) {
            $t->id(); $t->string('role_code', 30)->nullable(); $t->unsignedBigInteger('user_id')->nullable(); $t->string('form_key', 80); $t->string('field_name', 80);
            $t->enum('mode', ['EDIT', 'READONLY', 'HIDDEN']); $t->unsignedBigInteger('updated_by')->nullable(); $t->timestamps(); $t->index(['form_key', 'field_name']);
        });
        Schema::create('mo_access_requests', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('fk_id_ent')->nullable(); $t->unsignedBigInteger('user_id'); $t->string('perm_code', 120); $t->string('note', 255)->nullable();
            $t->enum('status', ['OPEN', 'GRANTED', 'REFUSED'])->default('OPEN'); $t->unsignedBigInteger('decided_by')->nullable(); $t->string('decided_note', 255)->nullable(); $t->timestamps();
        });
        Schema::table('t_users', function (Blueprint $t) { $t->string('custom_role', 30)->nullable()->after('role_code'); });
    }

    public function down(): void
    {
        foreach (['mo_roles', 'mo_role_perms', 'mo_user_perms', 'mo_user_scopes', 'mo_field_rules', 'mo_access_requests'] as $x) Schema::dropIfExists($x);
        Schema::table('t_users', function (Blueprint $t) { $t->dropColumn('custom_role'); });
    }
};
