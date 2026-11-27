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
        // Rule 5 "never stuck" (live 27-11: one old record with an empty date stopped every nightly step after it): each step runs on its own;
        // a step that fails is named in the run log (Health, the Owner's digest) while all the others still do their work.
        $err = []; $step = function (string $name, callable $fn) use (&$err) { try { return $fn(); } catch (\Throwable $e) { $err[] = $name . ': ' . mb_substr($e->getMessage(), 0, 160); report($e); return null; } };
        try { \Illuminate\Support\Facades\Artisan::call('mo:reindex', ['--missing' => true]); } catch (\Throwable $e) {}      // owner 27-11: every record can be found, also the ones made by imports
        foreach (DB::table('t_enterprise')->where('f_active', 1)->pluck('id') as $ent) {
            Context::set((int) $ent, null);
            $r = StockService::selfCheck((int) $ent);
            $step('WarehouseSlotting::compute', fn () => \App\Services\WarehouseSlotting::compute((int) $ent));      /* R-H-50 ABC classes + slotting suggestions */
            $step('AccHubAdmin::job', fn () => \App\Services\AccHubAdmin::job((int) $ent));      // R-ACC-9 the daily schedule run, then R-ACC-12 the reminder digest – its own job row (R-ACC-22)
            $low = StockService::lowStockTasks((int) $ent);
            $step('BuyingService::staleResearch', fn () => \App\Services\BuyingService::staleResearch((int) $ent));
            $step('CycleAlerts::sweep', fn () => \App\Services\CycleAlerts::sweep((int) $ent));
            $step('DueEngine::run', fn () => \App\Services\DueEngine::run((int) $ent));
            $step('CountService::missingTasks', fn () => \App\Services\CountService::missingTasks((int) $ent));
            $step('TrackingPush::tasks', fn () => \App\Services\TrackingPush::tasks((int) $ent));
            $step('LostParcels::sweep', fn () => \App\Services\LostParcels::sweep((int) $ent));
            $step('CountTriggers::schedule', fn () => \App\Services\CountTriggers::schedule((int) $ent));      // ABC cycle-count list of the day (R-H-47)      // parcels not delivered after the carrier's claim days (R-H-36)      // tracking upload / handover cut-offs (R-H-33), also every 15 minutes      // units missing too long (R-H-46)      // every stored renewal / expiry / payment date reminds (R-C-46)
            $step('MoneyService::unmatchedTasks', fn () => \App\Services\MoneyService::unmatchedTasks((int) $ent));
            $step('MoneyService::staleTasks', fn () => \App\Services\MoneyService::staleTasks((int) $ent));
            $step('CloseService::openTasks', fn () => \App\Services\CloseService::openTasks((int) $ent));
            $step('SkuHomes::tasks', fn () => \App\Services\SkuHomes::tasks((int) $ent));
            $step('ProductCerts::sweep', fn () => \App\Services\ProductCerts::sweep((int) $ent));      // MR-0139 certificate expiry tasks
            $step('ThreePlTariff::missingTasks', fn () => \App\Services\ThreePlTariff::missingTasks((int) $ent));
            $step('NotListed::closeDone', fn () => \App\Services\NotListed::closeDone((int) $ent));      // LST_CREATE closes when the listing exists (R-D-58)      // PRD_FEE_MISSING (R-D-23)
            $icc = \App\Services\Features::on('ICC', (int) $ent) ? \App\Services\Icc::recalcAll((int) $ent) : ['run' => 0]; if ($icc['run']) $this->line("ICC: {$icc['run']} product(s) – order now {$icc['RED']}, this week {$icc['YELLOW']}, covered {$icc['GREEN']}");      /* 07_ICC §6 (MR-0031) */
            if (\App\Services\Features::on('FEE_CHECK', (int) $ent)) \App\Services\FeeCheck::tasks((int) $ent);      /* MR-1536 last month's money files */
            if (\App\Services\Features::on('RETURN_ABUSE', (int) $ent)) \App\Services\ReturnAbuse::tasks((int) $ent);      /* MR-1544 */
            $step('SalesWatch::nightly', fn () => \App\Services\SalesWatch::nightly((int) $ent));      // SAL_NO_COST, LST_STATUS, IMP_WAITING
            $this->line("Company $ent: stock mismatches {$r['mismatch']}, negative balances {$r['negative_balances']}, value = ledger: " . ($r['value_matches_ledger'] ? 'yes' : 'NO') . ", low stock tasks open {$low['open']}");
        }
        if ($miss = \App\Services\SchemaCheck::missing()) \App\Services\EventBus::sysError(['schema', date('Y-m-d')], 'Database not up to date – ' . count($miss, COUNT_RECURSIVE) . ' item(s)', 'Run php artisan mo:install (migrations that did not run: see System › Health).');      /* MR-0941 nightly schema self-check */
        $subs = \App\Services\Subscriptions::nightly();
        $loans = \App\Services\FinanceExtras::loanTasks(); if ($loans) $this->line("Loan payments due: $loans new task(s)");
        foreach (\Illuminate\Support\Facades\DB::table('t_enterprise')->pluck('id') as $e) \App\Services\TaskLists::dataQuality((int) $e);      // R-G_CORE-38 data-quality rule tasks (one a day)
        $this->line("Subscriptions overdue: $subs new task(s) · task digests go every minute at each person's digest time (mo:events)");
        $step('SaleLines::purgeBin', fn () => \App\Services\SaleLines::purgeBin());   // the recycle bin keeps deleted sale lines for 30 days
        $nd = \App\Services\DsMatch::digest(); if ($nd) $this->line("Not matched digest: $nd person(s) told");
        $step('Carriers::applyDue', fn () => \App\Services\Carriers::applyDue()); \App\Services\CarrierPresets::closeRateTasks(); foreach (\Illuminate\Support\Facades\DB::table('t_enterprise')->pluck('id') as $dqEnt) \App\Services\DataQuality::run((int) $dqEnt);      /* R-G_CORE-82 */
        $dq = \App\Services\DqScore::run(); $this->line("Data-quality scores: {$dq['scored']} record(s), {$dq['opened']} task(s) opened, {$dq['closed']} closed");      /* R-G_CORE-108 */      // carrier / packing rates whose start day has come
        $sr = \App\Services\ShopReminders::monthly(); if ($sr) $this->line("Shop reminders made from templates: $sr");
        $mm = \App\Services\SalesImport::missingMonths(); if ($mm) $this->line("Months without a sales import: $mm new task(s)");
        $exp = \App\Services\ListExports::runScheduled(); if ($exp) $this->line("Scheduled exports queued: $exp");
        if ($err) { Context::set(null, null); try { EventBus::runQueue(); } catch (\Throwable $e) {} throw new \RuntimeException(count($err) . ' step(s) could not finish (the others ran): ' . implode(' · ', array_slice($err, 0, 6))); }
        Context::set(null, null);
        $this->line(EventBus::runQueue() . ' waiting events processed');
        return 0;
    }
}
