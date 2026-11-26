<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Owner 27-11 01:34 "bring everything of the old setup": IP / VPN servers keep their label, place, plan, provider and old id. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('cat_ip_burgers', 'server_label')) Schema::table('cat_ip_burgers', function (Blueprint $t) {
            $t->string('server_label', 120)->nullable(); $t->string('location', 120)->nullable(); $t->string('plan_name', 120)->nullable(); $t->string('provider_name', 120)->nullable();
            $t->text('notes')->nullable(); $t->string('legacy_table', 40)->nullable(); $t->integer('legacy_id')->nullable();
        });
        // the new fields show on their forms (registry), so nothing brought over is hidden
        foreach (['cat_ip_burgers' => [['server_label', 'Label', 'text'], ['location', 'Place', 'text'], ['plan_name', 'Plan', 'text'], ['provider_name', 'Provider', 'text'], ['notes', 'Notes', 'textarea']],
                  'cat_warehouses' => [['postcode', 'Postcode', 'text']]] as $form => $cols) {
            $last = DB::table('sys_registry')->where('form_key', $form)->orderByDesc('section_no')->orderByDesc('field_no')->first(); if (! $last) continue; $no = (int) $last->field_no;
            foreach ($cols as [$c, $label, $type]) if (! DB::table('sys_registry')->where('form_key', $form)->where('field_name', $c)->exists())
                DB::table('sys_registry')->insert(['form_key' => $form, 'form_title' => $last->form_title, 'form_kind' => $last->form_kind, 'section' => $last->section, 'section_no' => $last->section_no, 'field_no' => ++$no,
                    'field_name' => $c, 'label' => $label, 'type' => $type, 'source' => null, 'required' => 0, 'in_quick' => 0, 'visible_to' => 'all', 'readonly' => 0, 'help' => $c === 'provider_name' ? 'Who sells this IP / VPN (the list IP / VPN providers).' : null,
                    'table_name' => $last->table_name, 'column_name' => $c, 'is_master' => 0, 'secret' => 0]);
        }
        if (! Schema::hasColumn('cat_warehouses', 'legacy_id')) Schema::table('cat_warehouses', function (Blueprint $t) { $t->integer('fk_id_location')->nullable(); $t->string('postcode', 20)->nullable(); $t->integer('legacy_id')->nullable(); });
    }

    public function down(): void
    {
        DB::table('sys_registry')->where(fn ($w) => $w->where('form_key', 'cat_ip_burgers')->whereIn('field_name', ['server_label', 'location', 'plan_name', 'provider_name', 'notes']))->orWhere(fn ($w) => $w->where('form_key', 'cat_warehouses')->where('field_name', 'postcode'))->delete();
        Schema::table('cat_ip_burgers', fn (Blueprint $t) => $t->dropColumn(['server_label', 'location', 'plan_name', 'provider_name', 'notes', 'legacy_table', 'legacy_id']));
        Schema::table('cat_warehouses', fn (Blueprint $t) => $t->dropColumn(['fk_id_location', 'postcode', 'legacy_id']));
    }
};
