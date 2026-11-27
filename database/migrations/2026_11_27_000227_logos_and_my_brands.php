<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Owner 27-11 09:26 / 09:30: a small current logo beside every marketplace, bank, carrier, app and own brand; a My brands page per own brand. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sys_logos')) Schema::create('sys_logos', function (Blueprint $t) {
            $t->id(); $t->integer('fk_id_ent')->default(0)->index(); $t->string('kind', 20); $t->string('name', 120); $t->string('aliases', 400)->nullable(); $t->string('domain', 150)->nullable();
            $t->string('ref_table', 40)->nullable(); $t->integer('ref_id')->nullable(); $t->binary('image')->nullable(); $t->string('mime', 40)->nullable(); $t->binary('image_dark')->nullable();
            $t->boolean('f_own')->default(false); $t->boolean('f_active')->default(true); $t->dateTime('fetched_at')->nullable(); $t->string('fetch_note', 200)->nullable();
            $t->integer('updated_by')->nullable(); $t->timestamps(); $t->unique(['fk_id_ent', 'kind', 'name']);
        });
        DB::statement('ALTER TABLE sys_logos MODIFY image MEDIUMBLOB NULL, MODIFY image_dark MEDIUMBLOB NULL');
        if (! Schema::hasColumn('cat_brands', 'f_own')) {
            Schema::table('cat_brands', fn (Blueprint $t) => $t->boolean('f_own')->default(false));
            DB::table('cat_brands')->whereNotNull('fk_id_company_house')->where('fk_id_company_house', '>', 0)->update(['f_own' => 1]);      // a brand of one of our companies is ours; the owner can change it
        }
        $add = function (string $from, string $key, string $name, string $url, int $sort) {
            $tpl = DB::table('sidebar_modules')->where('module_key', $from)->first();
            if (! $tpl || DB::table('sidebar_modules')->where('module_key', $key)->exists()) return;
            $row = (array) $tpl; unset($row['id'], $row['id_module']);
            DB::table('sidebar_modules')->insert(array_merge($row, ['module_key' => $key, 'module_name' => $name, 'url' => $url, 'screen_no' => '', 'forms' => '', 'sort_order' => $sort, 'created_at' => now()]));
        };
        $add('M_5_1_PRODUCTS', 'M_MY_BRANDS', 'My brands', '#/my-brands', 0);
        $add('M_17_2_COMPANY_FINANCE_SETTINGS', 'M_LOGOS', 'Logos', '#/logos', (int) (DB::table('sidebar_modules')->where('module_key', 'M_17_2_COMPANY_FINANCE_SETTINGS')->value('sort_order') ?? 0) + 1);
    }

    public function down(): void
    {
        DB::table('sidebar_modules')->whereIn('module_key', ['M_MY_BRANDS', 'M_LOGOS'])->delete();
        Schema::dropIfExists('sys_logos');
        if (Schema::hasColumn('cat_brands', 'f_own')) Schema::table('cat_brands', fn (Blueprint $t) => $t->dropColumn('f_own'));
    }
};
