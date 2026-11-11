<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** MoreOrderz core additions on top of database/schema/baseline.sql (run by `php artisan mo:install`). */
return new class extends Migration
{
    public function up(): void
    {
        // Login: the old plain-text `pass` column is never used again. New hashed password + role.
        Schema::table('t_users', function (Blueprint $t) {
            if (! Schema::hasColumn('t_users', 'password_hash')) $t->string('password_hash', 255)->nullable();
            if (! Schema::hasColumn('t_users', 'display_name')) $t->string('display_name', 120)->nullable();
            if (! Schema::hasColumn('t_users', 'role_code')) $t->string('role_code', 20)->default('VA');
            if (! Schema::hasColumn('t_users', 'fk_id_ent')) $t->integer('fk_id_ent')->nullable();
            if (! Schema::hasColumn('t_users', 'totp_secret')) $t->string('totp_secret', 64)->nullable();
            if (! Schema::hasColumn('t_users', 'remember_token')) $t->string('remember_token', 100)->nullable();
            if (! Schema::hasColumn('t_users', 'last_login_at')) $t->dateTime('last_login_at')->nullable();
        });
        DB::statement("ALTER TABLE t_users MODIFY pass VARCHAR(20) NULL, MODIFY fk_id_account INT NULL, MODIFY f_active TINYINT NOT NULL DEFAULT 1");

        // Menu: which roles see an item (from MENU_TREE), and the screen number of the system map.
        Schema::table('sidebar_modules', function (Blueprint $t) {
            if (! Schema::hasColumn('sidebar_modules', 'roles')) $t->string('roles', 200)->nullable();
            if (! Schema::hasColumn('sidebar_modules', 'screen_no')) $t->string('screen_no', 10)->nullable();
            if (! Schema::hasColumn('sidebar_modules', 'forms')) $t->string('forms', 1000)->nullable();
        });

        // Field registry at run time: the one source for forms, lists, validation and + Create.
        if (! Schema::hasTable('sys_registry')) {
            Schema::create('sys_registry', function (Blueprint $t) {
                $t->id();
                $t->string('form_key', 80)->index();
                $t->string('form_title', 160);
                $t->string('form_kind', 20);
                $t->string('section', 120)->nullable();
                $t->smallInteger('section_no')->default(1);
                $t->smallInteger('field_no')->default(1);
                $t->string('field_name', 80);
                $t->string('label', 160);
                $t->string('type', 20);
                $t->string('source', 200)->nullable();
                $t->boolean('required')->default(false);
                $t->boolean('in_quick')->default(false);
                $t->string('default_rule', 200)->nullable();
                $t->string('cascades_from', 80)->nullable();
                $t->string('visible_to', 120)->nullable();
                $t->boolean('readonly')->default(false);
                $t->string('auto_correct', 40)->nullable();
                $t->string('step', 20)->nullable();
                $t->string('min', 30)->nullable();
                $t->string('max', 30)->nullable();
                $t->string('help', 600)->nullable();
                $t->text('options_full')->nullable();
                $t->string('table_name', 64)->nullable();
                $t->string('column_name', 64)->nullable();
                $t->string('add_form', 80)->nullable();
                $t->boolean('is_master')->default(false);
                $t->string('required_if', 200)->nullable();
                $t->string('show_if', 200)->nullable();
                $t->boolean('secret')->default(false);
                $t->index(['form_key', 'section_no', 'field_no']);
            });
        }

        // Double-entry ledger (owner decision 12 Nov 2026): every money movement is a balanced journal.
        if (! Schema::hasTable('gl_accounts')) {
            Schema::create('gl_accounts', function (Blueprint $t) {
                $t->id();
                $t->integer('fk_id_ent')->nullable()->index();   // null = shared chart
                $t->string('code', 20);
                $t->string('name', 120);
                $t->enum('kind', ['ASSET', 'LIABILITY', 'EQUITY', 'INCOME', 'EXPENSE']);
                $t->string('sub_ledger', 30)->nullable();        // BANK / PARTY / SHOP / LOT …: lines must carry that reference
                $t->boolean('f_system')->default(true);
                $t->boolean('f_active')->default(true);
                $t->unique(['fk_id_ent', 'code']);
            });
            Schema::create('gl_journals', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->integer('fk_id_ent')->index();
                $t->string('journal_no', 30)->unique();
                $t->date('journal_date')->index();
                $t->string('period', 7)->index();                // YYYY-MM
                $t->string('source_type', 40);                   // PAYMENT / GRN / SETTLEMENT / EXPENSE / SALE_CONFIRM …
                $t->unsignedBigInteger('source_id')->nullable();
                $t->string('memo', 255)->nullable();
                $t->string('currency', 3)->default('GBP');
                $t->decimal('fx_rate', 18, 8)->default(1);
                $t->unsignedBigInteger('reverses_id')->nullable();
                $t->unsignedBigInteger('reversed_by_id')->nullable();
                $t->integer('created_by')->nullable();
                $t->dateTime('created_at');
                $t->index(['source_type', 'source_id']);
            });
            Schema::create('gl_lines', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('journal_id')->index();
                $t->unsignedBigInteger('account_id')->index();
                $t->decimal('debit', 18, 2)->default(0);         // base currency of the company
                $t->decimal('credit', 18, 2)->default(0);
                $t->decimal('amount_cur', 18, 2)->nullable();    // original currency amount (signed: + debit)
                $t->string('ref_type', 30)->nullable();          // PARTY / BANK / SHOP / LOT / PO …
                $t->unsignedBigInteger('ref_id')->nullable();
                $t->string('memo', 255)->nullable();
                $t->index(['ref_type', 'ref_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('gl_lines');
        Schema::dropIfExists('gl_journals');
        Schema::dropIfExists('gl_accounts');
        Schema::dropIfExists('sys_registry');
    }
};
