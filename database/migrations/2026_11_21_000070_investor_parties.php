<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/** Investor ids are company parties (R-D-80, K4): product investors point at the company party; a company party knows its Companies House row and its directors with share %. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('t_product_investors', function ($t) {
            if (! Schema::hasColumn('t_product_investors', 'fk_id_party')) $t->integer('fk_id_party')->nullable()->after('fk_id_account');
            if (! Schema::hasColumn('t_product_investors', 'map_status')) $t->string('map_status', 12)->nullable();
            if (! Schema::hasColumn('t_product_investors', 'map_note')) $t->string('map_note', 255)->nullable();
        });
        Schema::table('t_party_profile', function ($t) {
            if (! Schema::hasColumn('t_party_profile', 'fk_id_company_house')) $t->integer('fk_id_company_house')->nullable()->index();
        });
        if (! Schema::hasTable('t_party_directors')) Schema::create('t_party_directors', function ($t) {
            $t->increments('id'); $t->integer('fk_id_party')->index(); $t->integer('fk_id_director'); $t->decimal('share_pct', 6, 2)->nullable(); $t->tinyInteger('position')->default(1); $t->dateTime('created_at')->nullable();
            $t->unique(['fk_id_party', 'position']);
        });
    }
    public function down(): void {}
};
