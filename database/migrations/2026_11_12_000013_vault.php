<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vault: secret values (passwords, memorable answers, full bank account numbers, gateway codes) are kept here, encrypted with the
 * application key. The record itself keeps only •••• (and the last 4 digits of an account number). CVV and PIN are never stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sys_vault', function (Blueprint $t) {
            $t->id();
            $t->integer('fk_id_ent')->default(0);
            $t->string('table_name', 64);
            $t->unsignedBigInteger('record_id');
            $t->string('column_name', 64);
            $t->text('cipher');
            $t->string('last4', 4)->nullable();
            $t->integer('updated_by')->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->unique(['table_name', 'record_id', 'column_name'], 'uq_vault_cell');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sys_vault');
    }
};
