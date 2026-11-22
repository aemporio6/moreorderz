<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R-FIN-70 a bank account is a BANK or a payment PROVIDER (own statement layout, fee lines).
 * R-C-43 roles master: role_code filled (old id 6 = OWNER, old id 5 = SELLER, the others from the ISO code, upper, unique); the rows of the old second
 * roles screen (cat_account_roles) that are not in the master are merged in switched off.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('fin_bank_accounts', 'account_kind')) DB::statement("ALTER TABLE fin_bank_accounts ADD COLUMN account_kind VARCHAR(10) NOT NULL DEFAULT 'BANK'");
        \App\Services\RoleMaster::fillCodes();
        \App\Services\RoleMaster::mergeOldScreen();
        \App\Console\Commands\MoInstall::loadRegistry('cat_account_type');      /* the roles form gets is_ec / is_paid / sort */
    }

    public function down(): void
    {
        if (Schema::hasColumn('fin_bank_accounts', 'account_kind')) DB::statement('ALTER TABLE fin_bank_accounts DROP COLUMN account_kind');
    }
};
