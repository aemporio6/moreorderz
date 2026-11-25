<?php

namespace App\Console\Commands;

use App\Services\Context;
use App\Services\TrackingPush;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Every 15 minutes (R-H-33): tracking not sent back by the profile cut-off, Ready parcels not handed over by the carrier cut-off. */
class MoDispatchWatch extends Command
{
    protected $signature = 'mo:dispatch-watch';
    protected $description = 'Tracking upload and handover cut-off tasks';

    public function handle(): int
    {
        foreach (DB::table('t_enterprise')->where('f_active', 1)->pluck('id') as $ent) { Context::set((int) $ent, null); \App\Services\JobLog::run('DISPATCH_WATCH', (int) $ent, fn () => TrackingPush::tasks((int) $ent)); \App\Services\JobLog::run('PICK_ABANDON', (int) $ent, fn () => \App\Services\PickLists::sweep((int) $ent));      /* R-H-28 */ \App\Services\JobLog::run('WH_ALERTS', (int) $ent, fn () => \App\Services\WarehouseAlerts::run((int) $ent));      /* R-H-55 */ }      // R-FIN-32 lock + run log
        return 0;
    }
}
