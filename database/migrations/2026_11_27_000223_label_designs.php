<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** MR-0134 the Label Designer: own label designs (product, carton, FNSKU, pallet, goods-in) printed through the normal print path; menu entry under Setup › Printers. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('label_designs')) Schema::create('label_designs', function (Blueprint $t) {
            $t->id(); $t->integer('fk_id_ent')->default(0)->index(); $t->string('name', 120); $t->string('design_kind', 20);
            $t->decimal('width_mm', 6, 1); $t->decimal('height_mm', 6, 1); $t->text('fields_json'); $t->string('barcode_type', 10)->default('CODE128'); $t->string('barcode_field', 30)->default('sku');
            $t->string('own_text', 200)->nullable(); $t->boolean('f_active')->default(true); $t->integer('created_by')->nullable(); $t->integer('updated_by')->nullable(); $t->timestamps();
        });
        if (DB::table('print_adapters')->exists()) \App\Services\PrintService::seed();      // the OWN_LABEL template and its route
        $tpl = DB::table('sidebar_modules')->where('module_key', 'M_17_10_LABEL_TEMPLATES_SHEET_LAYOUTS')->first();
        if ($tpl && ! DB::table('sidebar_modules')->where('module_key', 'M_LABEL_DESIGNER')->exists()) {
            $row = (array) $tpl; unset($row['id'], $row['id_module']);
            DB::table('sidebar_modules')->insert(array_merge($row, ['module_key' => 'M_LABEL_DESIGNER', 'module_name' => 'Label designer', 'url' => '#/label-designer', 'sort_order' => 10, 'created_at' => now()]));
        }
    }

    public function down(): void { DB::table('sidebar_modules')->where('module_key', 'M_LABEL_DESIGNER')->delete(); }
};
