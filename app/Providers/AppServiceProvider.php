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
        EventBus::listen('GRN_POSTED', function (array $e) {
            foreach (DB::table('fin_lots')->whereIn('id', $e['payload']['lots'] ?? [])->pluck('fk_id_product')->unique() as $pid) DispatchService::fillShorts((int) $pid);
        });
        // Found stock or a positive adjustment does the same.
        EventBus::listen('STOCK_ADJUSTED', function (array $e) {
            if (($e['payload']['qty'] ?? 0) > 0 && $e['entity'] === 't_product_vendors') DispatchService::fillShorts((int) $e['entity_id']);
        });
    }
}
