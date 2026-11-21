<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** R-G_CORE-65 forgot password: one-time reset links (only the hash of the token is kept). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sys_password_resets')) DB::statement("CREATE TABLE sys_password_resets (
            id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, token_hash CHAR(64) NOT NULL, created_at DATETIME NOT NULL, expires_at DATETIME NOT NULL,
            used_at DATETIME NULL, ip VARCHAR(45) NULL, UNIQUE KEY uq_token (token_hash), KEY idx_user (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // audit actions of R-G_CORE-65 / 98
        DB::statement("ALTER TABLE sys_audit_log MODIFY action ENUM('INSERT','UPDATE','DELETE','RESTORE','MERGE','UNMERGE','REVEAL','LOGIN','LOGIN_FAIL','EXPORT','APPROVE','REJECT','LOCK','UNLOCK','LOGOUT','FORCE_LOGOUT','IP_BLOCKED','KEY_ROTATED','CUTOVER','ROLLBACK','DUPLICATE_FORCED','RESET_REQUEST','SECURE_CODE_OK','SECURE_CODE_FAIL') NOT NULL");
    }

    public function down(): void { Schema::dropIfExists('sys_password_resets'); }
};
