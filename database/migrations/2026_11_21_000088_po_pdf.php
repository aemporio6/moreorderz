<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PO PDF (R-F_SETUP-11): supplier setting "Print Chinese labels", product name in Chinese for the second line of a PO row, PO PDF versions
 * (each print with changed data makes version n+1, kept and linked to the PO), and the two registry rows.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('t_src_suppliers', 'f_print_cn')) DB::statement("ALTER TABLE t_src_suppliers ADD COLUMN f_print_cn TINYINT NOT NULL DEFAULT 0 COMMENT 'Print Chinese labels on the PO PDF'");
        if (! Schema::hasColumn('t_product_vendors', 'product_name_cn')) DB::statement("ALTER TABLE t_product_vendors ADD COLUMN product_name_cn VARCHAR(255) NULL COMMENT 'Product name / spec in Chinese for supplier POs'");
        if (! Schema::hasTable('src_po_pdf_versions')) DB::statement("CREATE TABLE src_po_pdf_versions (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, fk_id_ent INT NOT NULL, fk_id_po INT NOT NULL, version INT NOT NULL, data_hash CHAR(40) NOT NULL,
            file_name VARCHAR(255) NOT NULL, file_path VARCHAR(255) NOT NULL, fk_id_document INT NULL, created_by INT NULL, created_at DATETIME NULL, sent_at DATETIME NULL, sent_via VARCHAR(20) NULL, sent_to VARCHAR(255) NULL,
            UNIQUE KEY uq_po_ver (fk_id_po, version), KEY ix_po_hash (fk_id_po, data_hash))");
        $rows = [
            ['t_src_suppliers', 'Supplier / forwarder', 'module', 'Terms', 5, 8, 'f_print_cn', 'Print Chinese labels', 'check', 'On the PO PDF every row gets a second line with the product name / spec in Chinese.', 't_src_suppliers', 'f_print_cn'],
            ['product', 'Product', 'library', 'Supplier', 3, 8, 'product_name_cn', 'Product name (Chinese)', 'text', 'Printed under the English name on POs of suppliers with "Print Chinese labels".', 't_product_vendors', 'product_name_cn'],
        ];
        foreach ($rows as [$fk, $ft, $kind, $sec, $sno, $fno, $fname, $label, $type, $help, $table, $col]) {
            if (DB::table('sys_registry')->where('form_key', $fk)->where('field_name', $fname)->exists()) continue;
            DB::table('sys_registry')->insert(['form_key' => $fk, 'form_title' => $ft, 'form_kind' => $kind, 'section' => $sec, 'section_no' => $sno, 'field_no' => $fno, 'field_name' => $fname, 'label' => $label, 'type' => $type,
                'required' => 0, 'in_quick' => 0, 'visible_to' => 'all', 'readonly' => 0, 'help' => $help, 'table_name' => $table, 'column_name' => $col, 'is_master' => 1, 'secret' => 0]);
        }
        \App\Services\Registry::forget();
    }
    public function down(): void
    {
        DB::table('sys_registry')->whereIn('field_name', ['f_print_cn', 'product_name_cn'])->whereIn('form_key', ['t_src_suppliers', 'product'])->delete();
        Schema::dropIfExists('src_po_pdf_versions');
        if (Schema::hasColumn('t_src_suppliers', 'f_print_cn')) DB::statement('ALTER TABLE t_src_suppliers DROP COLUMN f_print_cn');
        if (Schema::hasColumn('t_product_vendors', 'product_name_cn')) DB::statement('ALTER TABLE t_product_vendors DROP COLUMN product_name_cn');
    }
};
