<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** R-F_SETUP-23 shipments board: forwarder tracking link on the shipment, and every ETA change kept (trigger, so no path can miss it). */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('t_src_shipments', 'tracking_url')) DB::statement("ALTER TABLE t_src_shipments ADD COLUMN tracking_url VARCHAR(500) NULL COMMENT 'Forwarder tracking link'");
        if (! Schema::hasTable('src_shipment_eta_history')) DB::statement('CREATE TABLE src_shipment_eta_history (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, fk_id_shipment INT NOT NULL, old_eta DATE NULL, new_eta DATE NULL, changed_at DATETIME NOT NULL, KEY ix_ship (fk_id_shipment))');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_src_ship_eta');
        DB::unprepared('CREATE TRIGGER trg_src_ship_eta AFTER UPDATE ON t_src_shipments FOR EACH ROW BEGIN IF NOT (OLD.eta <=> NEW.eta) THEN INSERT INTO src_shipment_eta_history (fk_id_shipment, old_eta, new_eta, changed_at) VALUES (NEW.id, OLD.eta, NEW.eta, NOW()); END IF; END');
        if (! DB::table('sys_registry')->where('form_key', 't_src_shipments')->where('field_name', 'tracking_url')->exists())
            DB::table('sys_registry')->insert(['form_key' => 't_src_shipments', 'form_title' => 'Shipments', 'form_kind' => 'module', 'section' => 'More details', 'section_no' => 4, 'field_no' => 2, 'field_name' => 'tracking_url', 'label' => 'Tracking link',
                'type' => 'link', 'required' => 0, 'in_quick' => 0, 'visible_to' => 'all', 'readonly' => 0, 'help' => 'The forwarder\'s tracking page for this shipment – shown on the shipments board.', 'table_name' => 't_src_shipments', 'column_name' => 'tracking_url', 'is_master' => 1, 'secret' => 0]);
        \App\Services\Registry::forget();
    }
    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_src_ship_eta'); Schema::dropIfExists('src_shipment_eta_history');
        DB::table('sys_registry')->where('form_key', 't_src_shipments')->where('field_name', 'tracking_url')->delete();
        if (Schema::hasColumn('t_src_shipments', 'tracking_url')) DB::statement('ALTER TABLE t_src_shipments DROP COLUMN tracking_url');
    }
};
