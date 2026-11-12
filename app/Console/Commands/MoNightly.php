<?php

namespace App\Console\Commands;

use App\Services\Context;
use App\Services\EventBus;
use App\Services\StockService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MoNightly extends Command
{
    protected $signature = 'mo:nightly';
    protected $description = 'Nightly self-checks per company (stock = ledger, low stock tasks) and retry of waiting events';

    public function handle(): int
    {
        foreach (DB::table('t_enterprise')->where('f_active', 1)->pluck('id') as $ent) {
            Context::set((int) $ent, null);
            $r = StockService::selfCheck((int) $ent);
            $low = StockService::lowStockTasks((int) $ent);
            \App\Services\BuyingService::staleResearch((int) $ent);
            \App\Services\BuyingService::payDueTasks((int) $ent);
            \App\Services\BuyingService::shipmentTasks((int) $ent);
            \App\Services\MoneyService::unmatchedTasks((int) $ent);
            \App\Services\MoneyService::staleTasks((int) $ent);
            \App\Services\CloseService::openTasks((int) $ent);
            $this->line("Company $ent: stock mismatches {$r['mismatch']}, negative balances {$r['negative_balances']}, value = ledger: " . ($r['value_matches_ledger'] ? 'yes' : 'NO') . ", low stock tasks open {$low['open']}");
        }
        Context::set(null, null);
        $this->line(EventBus::runQueue() . ' waiting events processed');
        return 0;
    }
}
