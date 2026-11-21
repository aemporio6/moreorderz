<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Alert rules catalogue N1–N14 + MANUAL + APPROVED (R-G_CORE-49) and alert thresholds with history (R-G_CORE-51).
 * The catalogue rows and their texts are seeded by App\Services\AlertRules::seed() so code and data stay one list.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('tm_alert_rules', 'name')) DB::statement("ALTER TABLE tm_alert_rules
            ADD name VARCHAR(80) NOT NULL DEFAULT '' AFTER code,
            ADD module VARCHAR(20) NOT NULL DEFAULT 'TASK' AFTER name,
            ADD template_key VARCHAR(60) NULL AFTER to_role_or_person,
            ADD extra_template_key VARCHAR(60) NULL AFTER template_key,
            ADD is_locked TINYINT(1) NOT NULL DEFAULT 0,
            ADD skip_blocked TINYINT(1) NOT NULL DEFAULT 0,
            ADD sort INT NOT NULL DEFAULT 0,
            ADD updated_by INT NULL, ADD updated_at DATETIME NULL");

        if (! Schema::hasTable('tm_alert_texts')) DB::statement("CREATE TABLE tm_alert_texts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            template_key VARCHAR(60) NOT NULL,
            lang VARCHAR(5) NOT NULL DEFAULT 'en',
            subject VARCHAR(200) NULL,
            body TEXT NOT NULL,
            params_json VARCHAR(400) NOT NULL DEFAULT '[]' COMMENT 'ordered parameters, the same for every language',
            updated_by INT NULL, updated_at DATETIME NULL,
            UNIQUE KEY uq_key_lang (template_key, lang)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        if (! Schema::hasColumn('tm_settings', 'help')) DB::statement("ALTER TABLE tm_settings ADD help VARCHAR(300) NOT NULL DEFAULT '' AFTER label, ADD kind VARCHAR(10) NOT NULL DEFAULT 'NUMBER' AFTER help");
        if (! Schema::hasTable('tm_settings_history')) DB::statement("CREATE TABLE tm_settings_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(60) NOT NULL,
            old_value VARCHAR(120) NULL, new_value VARCHAR(120) NOT NULL,
            changed_by INT NULL, changed_at DATETIME NOT NULL,
            KEY idx_key (setting_key, changed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        \App\Services\AlertRules::seed();
        DB::table('sidebar_modules')->where('module_key', 'M_13_6_MESSAGES_WHATSAPP')->update(['url' => '#/alerts']);
    }
    public function down(): void {}
};
