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
            if ($ok) { $w = \Illuminate\Support\Facades\DB::table('fin_withdrawals')->where('id', $a->entity_id)->first(['investor_id', 'company_id', 'amount']);
                if ($w) \App\Services\FinanceArea::partnerMessage((int) $w->company_id, (int) $w->investor_id, 'WITHDRAWAL_APPROVED', 'Your withdrawal of ' . number_format((float) $w->amount, 2) . ' was approved.', 'wd-ok:' . $a->entity_id);      // R-FIN-10
                \App\Services\PartnerService::payWithdrawal((int) $a->entity_id); }
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
        \App\Services\Approvals::handler('SRC_OVERPAY', function (object $a, bool $ok) {      // R-B_SOURCING-31: booked on approval; refused → the payment is cancelled
            $p = json_decode($a->change_json, true)['pay'] ?? null; if (! $p) return;
            if ($ok) \App\Services\BuyingService::pay($p);
            else \Illuminate\Support\Facades\DB::table('t_src_payments')->where('id', $p['_pending'])->where('status', 'PENDING_APPROVAL')->update(['status' => 'REJECTED', 'f_active' => 0]);
        });
        \App\Services\Approvals::handler('MASTER_CHANGE', fn (object $a, bool $ok, ?string $reason = null) => \App\Services\MasterChanges::decided($a, $ok, $reason));      // D11 maker-checker of master changes
        \App\Services\Approvals::handler('SUP_BANK', fn (object $a, bool $ok, ?string $reason = null) => \App\Services\SupplierBanks::decided($a, $ok, $reason));      // 06_ENGINES §A SUP_BANK
        \App\Services\Approvals::handler('SRC_HISTORICAL', fn (object $a, bool $ok, ?string $reason = null) => \App\Services\HistoricalPurchase::decided($a, $ok, $reason));      // R-B_SOURCING-5: the stock of an old entry after approval
        \App\Services\Approvals::handler('SRC_LC_OVERRIDE', fn (object $a, bool $ok) => \App\Services\LandedVersions::decided($a, $ok));      // R-B_SOURCING-45
        \App\Services\Approvals::handler('EXPENSE', function (object $a, bool $ok) {
            if ($ok) { \Illuminate\Support\Facades\DB::table('fin_expenses')->where('id', $a->entity_id)->update(['f_active' => 1]); \App\Services\MoneyService::postExpense((int) $a->entity_id); }
        });
        // a supplier-orders file of a shop + month was applied: its not-matched sale lines are matched again by themselves (R-A_SALES-25)
        EventBus::listen('DS_BATCH_APPLIED', function (array $e) { $p = $e['payload']; if (! empty($p['month']) && ! empty($p['year'])) \App\Services\DsMatch::afterDsBatch((int) ($p['shop'] ?? $e['entity_id']), (int) $p['month'], (int) $p['year']); });
        foreach (array_keys(\App\Services\TaskTemplates::EVENTS) as $ev) EventBus::listen($ev, fn (array $e) => \App\Services\TaskTemplates::fire($e['code'], (string) $e['entity'], (int) $e['entity_id'], (int) $e['ent']));      // R-G_CORE-25 templates fired by events
        EventBus::listen('STOCK_CONSUMED', function (array $e) { if ((int) ($e['payload']['left'] ?? 1) === 0 && ($po = \Illuminate\Support\Facades\DB::table('fin_lots')->where('id', (int) $e['entity_id'])->first(['po_id', 'company_id'])) && $po->po_id) \App\Services\FinanceArea::poSoldOut((int) $po->company_id, (int) $po->po_id); });      // R-FIN-15 a PO sold out ends its PO agreements the same day
        EventBus::listen('STATEMENT_READY', function (array $e) { $p = (int) ($e['payload']['partner'] ?? 0); if ($p) \App\Services\FinanceArea::partnerMessage((int) ($e['ent'] ?? 0), $p, ! empty($e['payload']['updated']) ? 'STATEMENT_UPDATED' : 'STATEMENT_READY', ! empty($e['payload']['updated']) ? 'Your statement was updated – open My Partner Account to see it.' : 'Your statement is ready – open My Partner Account to read and accept it.', 'st:' . $e['entity_id']); });      // R-FIN-10
        EventBus::listen('PRODUCT_OOS', fn (array $e) => \App\Services\ListingQty::pushZero((int) $e['entity_id']));      // R-H-20 the 0 goes out at once
        EventBus::listen('GRN_POSTED', function (array $e) {
            foreach (DB::table('fin_lots')->whereIn('id', $e['payload']['lots'] ?? [])->pluck('fk_id_product')->unique() as $pid) DispatchService::fillShorts((int) $pid);
        });
        // Found stock or a positive adjustment does the same.
        EventBus::listen('STOCK_ADJUSTED', function (array $e) {
            if (($e['payload']['qty'] ?? 0) > 0 && $e['entity'] === 't_product_vendors') DispatchService::fillShorts((int) $e['entity_id']);
        });
    }
}
