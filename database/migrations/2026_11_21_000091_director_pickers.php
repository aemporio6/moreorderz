<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * R-F_SETUP-36 / 38: the four Director 1 pickers follow Director 1 (a changed Director 1 empties them) and the director fields keep
 * the parties with role OWNER, DIRECTOR or PARTNER. The same change is in database/meta/field_registry.csv.
 */
return new class extends Migration {
    public function up(): void
    {
        $help = ['addr' => "Only Director 1's own addresses (Home first). Choose Director 1 first; + Add opens Director 1's addresses.",
            'mobile' => "Only Director 1's own mobiles. Choose Director 1 first; + Add opens Director 1's mobiles.", 'email' => "Only Director 1's own e-mails, A-Z. Choose Director 1 first; + Add opens Director 1's e-mails."];
        foreach (\App\Services\DirectorPickers::FIELDS as $field => [$kind]) DB::table('sys_registry')->where('form_key', 'cat_company_house')->where('field_name', $field)->update(['cascades_from' => 'fk_director_1', 'help' => $help[$kind]]);
        foreach (\App\Services\DirectorPickers::DIRECTORS as $field) DB::table('sys_registry')->where('form_key', 'cat_company_house')->where('field_name', $field)->update(['source' => 'party:OWNER,DIRECTOR,PARTNER',
            'help' => 'A person with the role Owner, Director or Partner. Press + to add a new person – it gets the role Director and is selected here.']);
        \App\Services\Registry::forget();
    }
    public function down(): void {}
};
