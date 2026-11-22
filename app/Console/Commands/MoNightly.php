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
        $r = \App\Services\JobLog::run('NIGHTLY', null, fn () => $this->nightly());      // R-FIN-32: one lock, one run-log line
        if ($r['status'] !== 'OK') $this->line('Nightly: ' . $r['status'] . (isset($r['error']) ? ' – ' . $r['error'] : ''));
        return 0;
    }

    private function nightly(): int
    {
        foreach (DB::table('t_enterprise')->where('f_active', 1)->pluck('id') as $ent) {
            Context::set((int) $ent, null);
            $r = StockService::selfCheck((int) $ent);
            \App\Services\AccFilings::reminders((int) $ent);      // R-ACC-9 the daily schedule run, then R-ACC-12 the reminder digest
            $low = StockService::lowStockTasks((int) $ent);
            \App\Services\BuyingService::staleResearch((int) $ent);
            \App\Services\CycleAlerts::sweep((int) $ent);
            \App\Services\DueEngine::run((int) $ent);
            \App\Services\CountService::missingTasks((int) $ent);
            \App\Services\TrackingPush::tasks((int) $ent);
            \App\Services\LostParcels::sweep((int) $ent);
            \App\Services\CountTriggers::schedule((int) $ent);      // ABC cycle-count list of the day (R-H-47)      // parcels not delivered after the carrier's claim days (R-H-36)      // tracking upload / handover cut-offs (R-H-33), also every 15 minutes      // units missing too long (R-H-46)      // every stored renewal / expiry / payment date reminds (R-C-46)
            \App\Services\MoneyService::unmatchedTasks((int) $ent);
            \App\Services\MoneyService::staleTasks((int) $ent);
            \App\Services\CloseService::openTasks((int) $ent);
            \App\Services\SkuHomes::tasks((int) $ent);
            \App\Services\ThreePlTariff::missingTasks((int) $ent);
            \App\Services\NotListed::closeDone((int) $ent);      // LST_CREATE closes when the listing exists (R-D-58)      // PRD_FEE_MISSING (R-D-23)
            \App\Services\SalesWatch::nightly((int) $ent);      // SAL_NO_COST, LST_STATUS, IMP_WAITING
            $this->line("Company $ent: stock mismatches {$r['mismatch']}, negative balances {$r['negative_balances']}, value = ledger: " . ($r['value_matches_ledger'] ? 'yes' : 'NO') . ", low stock tasks open {$low['open']}");
        }
        $subs = \App\Services\Subscriptions::nightly();
        $loans = \App\Services\FinanceExtras::loanTasks(); if ($loans) $this->line("Loan payments due: $loans new task(s)");
        foreach (\Illuminate\Support\Facades\DB::table('t_enterprise')->pluck('id') as $e) \App\Services\TaskLists::dataQuality((int) $e);      // R-G_CORE-38 data-quality rule tasks (one a day)
        $this->line("Subscriptions overdue: $subs new task(s) · task digests go every minute at each person's digest time (mo:events)");
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
