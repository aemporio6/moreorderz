<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** R-H-20: a product that reaches 0 pushes quantity 0 (or END) to its listings at once – a push row per listing; FILE shops get task LIST_OOS_PUSH to upload the file now. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_push_queue')) DB::statement("CREATE TABLE stock_push_queue (id BIGINT AUTO_INCREMENT PRIMARY KEY, fk_id_ent INT NOT NULL, fk_id_branch INT NOT NULL, fk_id_listing INT NOT NULL, qty INT NOT NULL DEFAULT 0, action VARCHAR(8) NOT NULL DEFAULT 'ZERO', via VARCHAR(8) NOT NULL, status VARCHAR(10) NOT NULL DEFAULT 'PENDING', reason VARCHAR(30) NULL, note VARCHAR(255) NULL, created_at DATETIME NOT NULL, done_at DATETIME NULL, KEY ix_shop (fk_id_branch, status))");
        if (! DB::table('sys_rules')->where('rule_code', 'LIST_OOS_PUSH')->exists())
            DB::table('sys_rules')->insert(['rule_code' => 'LIST_OOS_PUSH', 'name' => 'Upload the 0-quantity file now', 'module' => 'Sales', 'level' => 'URGENT', 'assign_to' => 'role:MANAGER', 'due_days' => 0, 'handler' => 'ListingQty::pushZero', 'f_active' => 1,
                'priority' => 'HIGH', 'owner_rule' => 'shop.seller,role:MANAGER', 'dedupe_key' => 'LIST_OOS_PUSH:<shop>', 'auto_close' => 'file downloaded', 'fix_link' => '#/listings-import']);
    }

    public function down(): void { DB::statement('DROP TABLE IF EXISTS stock_push_queue'); DB::table('sys_rules')->where('rule_code', 'LIST_OOS_PUSH')->delete(); }
};
