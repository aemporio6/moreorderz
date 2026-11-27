<?php

namespace App\Console\Commands;

use App\Services\Logos;
use Illuminate\Console\Command;

/** Owner 27-11 09:26: every marketplace, bank, carrier and app gets its current small logo from its own website (own pictures are never touched). */
class MoLogos extends Command
{
    protected $signature = 'mo:logos {--all : fetch every logo again, not only missing / older than 30 days} {--company=1}';
    protected $description = 'Bring the current logos of marketplaces, banks, carriers and apps from their websites';

    public function handle(): int
    {
        $new = Logos::sync((int) $this->option('company')); $r = Logos::fetchAll((bool) $this->option('all'));
        $this->info("New rows: $new · logos brought: {$r['fetched']} · not found: {$r['not_found']}");
        return 0;
    }
}
