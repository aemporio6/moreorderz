<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** R-D-69: tag name and colour required; on the legal-text form the brand follows the chosen company. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('sys_registry')->where('table_name', 'cat_tags')->whereIn('column_name', ['tag_name', 'tag_color'])->update(['required' => 1]);
        DB::table('sys_registry')->where('form_key', 'label_legal_text')->where('column_name', 'fk_id_brand')->update(['cascades_from' => 'fk_id_company_house']);
    }

    public function down(): void {}
};
