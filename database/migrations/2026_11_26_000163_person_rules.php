<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** R-C-16 role events; R-C-12 derived defaults of a person's rows as registry rules (firstOwn / businessIfCompany / linkedCompany / personCountry / personName / copy). */
return new class extends Migration {
    public function up(): void
    {
        foreach (['PERSON_ROLE_ADDED' => 'A role was added to a person', 'PERSON_ROLE_REMOVED' => 'A role was removed from a person'] as $c => $d)
            if (! DB::table('sys_event_types')->where('event_code', $c)->exists()) DB::table('sys_event_types')->insert(['event_code' => $c, 'area' => 'Masters', 'description' => $d, 'f_active' => 1]);
        foreach (['t_account', 't_account_mobile', 't_account_email', 't_account_bank', 't_account_paypal'] as $f) \App\Console\Commands\MoInstall::loadRegistry($f);
        \App\Services\Registry::forget();
    }
    public function down(): void {}
};
