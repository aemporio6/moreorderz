<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R-ACC-20 the Hub team (is_accountant / can_reveal per login), R-ACC-16 the company UTR is a vault secret like the gateway values
 * (registry of the company form reloaded, values already in the table sealed).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('acc_hub_members')) DB::statement("CREATE TABLE acc_hub_members (id INT AUTO_INCREMENT PRIMARY KEY, fk_id_ent INT NOT NULL, user_id INT NOT NULL, is_accountant TINYINT NOT NULL DEFAULT 0,
            can_reveal TINYINT NOT NULL DEFAULT 0, updated_by INT NOT NULL DEFAULT 0, updated_at DATETIME NULL, UNIQUE KEY acc_hub_member (fk_id_ent, user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        \App\Console\Commands\MoInstall::loadRegistry('cat_company_house');
        \App\Services\Vault::sealExisting(['cat_company_house']);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS acc_hub_members');
    }
};
