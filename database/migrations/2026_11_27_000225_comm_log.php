<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Owner 27-11 06:43 / 07:28 the communication hub: one conversation log per contact (supplier, forwarder, person), every channel, files and voice, evidence on records; extra contact numbers. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('comm_log')) Schema::create('comm_log', function (Blueprint $t) {
            $t->id(); $t->integer('fk_id_ent')->index(); $t->string('party_type', 12); $t->integer('party_id'); $t->string('channel', 12); $t->string('direction', 4);
            $t->text('body')->nullable(); $t->string('file_path', 255)->nullable(); $t->string('file_name', 180)->nullable(); $t->string('file_mime', 100)->nullable(); $t->integer('file_bytes')->nullable();
            $t->string('link_table', 60)->nullable(); $t->integer('link_id')->nullable(); $t->boolean('f_evidence')->default(false); $t->string('evidence_note', 255)->nullable();
            $t->dateTime('said_at'); $t->integer('user_id')->nullable(); $t->timestamps();
            $t->index(['party_type', 'party_id', 'said_at']); $t->index(['link_table', 'link_id']);
        });
        if (! Schema::hasTable('comm_contacts')) Schema::create('comm_contacts', function (Blueprint $t) {      // extra numbers / ids of a contact, beside the ones on its own record
            $t->id(); $t->integer('fk_id_ent')->index(); $t->string('party_type', 12); $t->integer('party_id'); $t->string('channel', 12); $t->string('address', 190); $t->string('label', 80)->nullable();
            $t->boolean('f_active')->default(true); $t->integer('created_by')->nullable(); $t->timestamps(); $t->index(['party_type', 'party_id']);
        });
        $tpl = DB::table('sidebar_modules')->where('module_key', 'M_12_4_COMPANIES')->first();
        if ($tpl && ! DB::table('sidebar_modules')->where('module_key', 'M_CONTACTS_CHAT')->exists()) {
            $row = (array) $tpl; unset($row['id'], $row['id_module']);
            DB::table('sidebar_modules')->insert(array_merge($row, ['module_key' => 'M_CONTACTS_CHAT', 'module_name' => 'Contacts & chat', 'url' => '#/contacts', 'screen_no' => '', 'forms' => '', 'sort_order' => 1, 'created_at' => now()]));
        }
    }

    public function down(): void { DB::table('sidebar_modules')->where('module_key', 'M_CONTACTS_CHAT')->delete(); }
};
