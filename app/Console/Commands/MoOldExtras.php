<?php

namespace App\Console\Commands;

use App\Services\OldExtras;
use Illuminate\Console\Command;

/** Owner 27-11 01:34: the last old setup tables go to their place (IP / VPN servers and providers, warehouses with addresses, old lookup lists). */
class MoOldExtras extends Command
{
    protected $signature = 'mo:old-extras {--from= : the old database (same server)} {--ent=1 : the company} {--dry : count only, write nothing}';
    protected $description = 'Bring the last old setup tables to their place';

    public function handle(): int
    {
        if (! $this->option('from')) { $this->error('Give --from=<old database name>.'); return 1; }
        try { $x = OldExtras::bring((string) $this->option('from'), (int) $this->option('ent'), (bool) $this->option('dry')); } catch (\Throwable $e) { $this->error($e->getMessage()); return 1; }
        $this->line(($x['dry'] ? 'Trial (nothing written): ' : 'Brought: ') . "IP providers {$x['vpn_providers']}, IP servers {$x['vpn_servers']}, warehouses {$x['loc_warehouses']}, list values {$x['ota_lookups']}");
        return 0;
    }
}
