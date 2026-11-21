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
            \App\Services\CycleAlerts::sweep((int) $ent);      // the sourcing alert registry (R-B_SOURCING-26), also every 15 minutes
            \App\Services\MoneyService::unmatchedTasks((int) $ent);
            \App\Services\MoneyService::staleTasks((int) $ent);
            \App\Services\CloseService::openTasks((int) $ent);
            \App\Services\SkuHomes::tasks((int) $ent);
            \App\Services\SalesWatch::nightly((int) $ent);      // SAL_NO_COST, LST_STATUS, IMP_WAITING
            $this->line("Company $ent: stock mismatches {$r['mismatch']}, negative balances {$r['negative_balances']}, value = ledger: " . ($r['value_matches_ledger'] ? 'yes' : 'NO') . ", low stock tasks open {$low['open']}");
        }
        $subs = \App\Services\Subscriptions::nightly();
        $loans = \App\Services\FinanceExtras::loanTasks(); if ($loans) $this->line("Loan payments due: $loans new task(s)");
        $dig = \App\Services\Connect\Messenger::digests();
        $this->line("Subscriptions overdue: $subs new task(s) · task digests queued: $dig");
        \App\Services\SaleLines::purgeBin();   // the recycle bin keeps deleted sale lines for 30 days
        $nd = \App\Services\DsMatch::digest(); if ($nd) $this->line("Not matched digest: $nd person(s) told");
        \App\Services\Carriers::applyDue();      // carrier / packing rates whose start day has come
        $sr = \App\Services\ShopReminders::monthly(); if ($sr) $this->line("Shop reminders made from templates: $sr");
        $mm = \App\Services\SalesImport::missingMonths(); if ($mm) $this->line("Months without a sales import: $mm new task(s)");
        $exp = \App\Services\ListExports::runScheduled(); if ($exp) $this->line("Scheduled exports queued: $exp");
        Context::set(null, null);
        $this->line(EventBus::runQueue() . ' waiting events processed');
        return 0;
    }
}
