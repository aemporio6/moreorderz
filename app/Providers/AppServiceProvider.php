<?php

namespace App\Providers;

use App\Services\DispatchService;
use App\Services\EventBus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    /** Listeners that connect the modules: a button fires an event, the other modules react by themselves. */
    public function boot(): void
    {
        // Stock arrived (GRN posted, opening stock) → orders that were short get their units, oldest dispatch date first.
        \App\Services\Approvals::handler('WITHDRAWAL', function (object $a, bool $ok) {
            if ($ok) \App\Services\PartnerService::payWithdrawal((int) $a->entity_id);
            else \Illuminate\Support\Facades\DB::table('fin_withdrawals')->where('id', $a->entity_id)->where('status', 'REQUESTED')->update(['status' => 'REJECTED']);
        });
        \App\Services\Approvals::handler('PAYMENT', function (object $a, bool $ok) { if ($ok) \App\Services\BuyingService::pay(json_decode($a->change_json, true)['pay']); });
        \App\Services\Approvals::handler('STOCK_ADJUST', function (object $a, bool $ok) {
            if ($ok) { $d = json_decode($a->change_json, true)['adjust']; \App\Services\StockService::adjust((int) $a->fk_id_ent, (int) $d['product'], (int) $d['location'], (int) $d['qty'], $d['reason'], isset($d['unit_cost']) ? ['unit_cost' => $d['unit_cost']] : []); }
        });
        \App\Services\Approvals::handler('SALE_BULK_DELETE', function (object $a, bool $ok) {      // more than 500 sale lines: carried out only after a second person said yes
            if ($ok) { $d = json_decode($a->change_json, true); \App\Services\SaleLines::bulkApply((int) $d['shop'], (string) $d['from'], (string) $d['to'], (string) $d['reason']); }
        });
        \App\Services\Approvals::handler('DS_PLATFORM_ADD', function (object $a, bool $ok) {      // a proposed supplier platform is added only after a manager said yes
            if ($ok) { $d = json_decode($a->change_json, true); if (! \Illuminate\Support\Facades\DB::table('drp_platforms')->whereRaw('LOWER(platform_name) = ?', [mb_strtolower($d['name'])])->exists()) \Illuminate\Support\Facades\DB::table('drp_platforms')->insert(['platform_name' => $d['name'], 'f_active' => 1, 'created_by' => $d['by'] ?? null, 'created_at' => now()]); }
        });
        \App\Services\Approvals::handler('EXPENSE', function (object $a, bool $ok) {
            if ($ok) { \Illuminate\Support\Facades\DB::table('fin_expenses')->where('id', $a->entity_id)->update(['f_active' => 1]); \App\Services\MoneyService::postExpense((int) $a->entity_id); }
        });
        // a supplier-orders file of a shop + month was applied: its not-matched sale lines are matched again by themselves (R-A_SALES-25)
        EventBus::listen('DS_BATCH_APPLIED', function (array $e) { $p = $e['payload']; if (! empty($p['month']) && ! empty($p['year'])) \App\Services\DsMatch::afterDsBatch((int) ($p['shop'] ?? $e['entity_id']), (int) $p['month'], (int) $p['year']); });
        EventBus::listen('GRN_POSTED', function (array $e) {
            foreach (DB::table('fin_lots')->whereIn('id', $e['payload']['lots'] ?? [])->pluck('fk_id_product')->unique() as $pid) DispatchService::fillShorts((int) $pid);
        });
        // Found stock or a positive adjustment does the same.
        EventBus::listen('STOCK_ADJUSTED', function (array $e) {
            if (($e['payload']['qty'] ?? 0) > 0 && $e['entity'] === 't_product_vendors') DispatchService::fillShorts((int) $e['entity_id']);
        });
    }
}
