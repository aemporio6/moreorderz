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
        \App\Services\Approvals::handler('EXPENSE', function (object $a, bool $ok) {
            if ($ok) { \Illuminate\Support\Facades\DB::table('fin_expenses')->where('id', $a->entity_id)->update(['f_active' => 1]); \App\Services\MoneyService::postExpense((int) $a->entity_id); }
        });
        EventBus::listen('GRN_POSTED', function (array $e) {
            foreach (DB::table('fin_lots')->whereIn('id', $e['payload']['lots'] ?? [])->pluck('fk_id_product')->unique() as $pid) DispatchService::fillShorts((int) $pid);
        });
        // Found stock or a positive adjustment does the same.
        EventBus::listen('STOCK_ADJUSTED', function (array $e) {
            if (($e['payload']['qty'] ?? 0) > 0 && $e['entity'] === 't_product_vendors') DispatchService::fillShorts((int) $e['entity_id']);
        });
    }
}
