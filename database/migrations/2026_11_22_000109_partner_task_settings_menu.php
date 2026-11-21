<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** R-G_CORE-16 / 17 / 23: a Partner sets absences, delegations and the SLA categories – the Team settings menu gets the PARTNER role (the forms still refuse the rest by rule). */
return new class extends Migration {
    public function up(): void
    {
        foreach (DB::table('sidebar_modules')->where('forms', 'like', '%task_delegation%')->get() as $m) {
            $roles = array_values(array_filter(array_map('trim', explode(',', (string) $m->roles))));
            if (! in_array('PARTNER', $roles, true)) { $roles[] = 'PARTNER'; DB::table('sidebar_modules')->where('id_module', $m->id_module)->update(['roles' => implode(', ', $roles)]); }
        }
        \App\Services\Rights::flush();
    }

    public function down(): void {}
};
