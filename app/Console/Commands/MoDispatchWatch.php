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
        foreach (DB::table('t_enterprise')->where('f_active', 1)->pluck('id') as $ent) { Context::set((int) $ent, null); TrackingPush::tasks((int) $ent); }
        return 0;
    }
}
