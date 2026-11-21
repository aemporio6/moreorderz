<?php

namespace Tests\Feature;

use App\Services\Choices;
use App\Services\CloseService;
use App\Services\CountTriggers;
use App\Services\RecordService;
use App\Services\StockAlerts;
use App\Services\Subscriptions;
use App\Services\ValidationErrors;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Owner rule 22 Nov 03:47 – part C as Settings › Choices: MR-0534 count triggers, MR-1531 waivable close checks, MR-0464 stock update reason, MR-0471 due-soon window. */
class ChoiceWarehouseTest extends TestCase
{
    public function test_mr0534_count_triggers_and_mr1531_waivable_checks(): void
    {
        $bin = DB::table('cat_inventory_locations')->insertGetId(RecordService::fillNotNull('cat_inventory_locations', ['fk_id_ent' => 1, 'kind' => 'BIN', 'location_code' => 'B' . uniqid(), 'location_name' => 'Bin T1', 'f_active' => 1]));
        $this->assertGreaterThan(0, CountTriggers::trigger(1, $bin, null, 'SHORT_PICK'), 'default: a short pick opens a count');
        $bin2 = DB::table('cat_inventory_locations')->insertGetId(RecordService::fillNotNull('cat_inventory_locations', ['fk_id_ent' => 1, 'kind' => 'BIN', 'location_code' => 'B' . uniqid(), 'location_name' => 'Bin T2', 'f_active' => 1]));
        Choices::set('COUNT_TRIGGERS', 'NEGATIVE,PUTAWAY_NO_ROOM', 1);
        $this->assertSame(0, CountTriggers::trigger(1, $bin2, null, 'SHORT_PICK'), 'switched off: no count');
        $this->assertGreaterThan(0, CountTriggers::trigger(1, $bin2, null, 'NEGATIVE'));
        $this->assertSame([true, false], [CloseService::waivable('HUB_TOTALS', 1), CloseService::waivable('FILES_PRESENT', 1)], 'default: the five of the specification');
        Choices::set('CLOSE_WAIVABLE', 'FILES_PRESENT', 1);
        $this->assertSame([false, true], [CloseService::waivable('HUB_TOTALS', 1), CloseService::waivable('FILES_PRESENT', 1)]);
        $c = collect(Choices::all(1))->firstWhere('key', 'CLOSE_WAIVABLE'); $this->assertSame([true, null], [$c['multi'], $c['add_url']]);
        $this->assertSame(count(CloseService::CHECKS) + 1, count($c['options']), 'All + every close check');
    }

    public function test_mr0464_stock_update_reason_and_mr0471_due_window(): void
    {
        $p = DB::table('t_product_vendors')->insertGetId(RecordService::fillNotNull('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'SR-' . uniqid(), 'product_name_vendor' => 'x', 'f_active' => 1]));
        $errs = function () use ($p) { try { StockAlerts::update(1, $p, 999999, 5, '', null, false); } catch (ValidationErrors $e) { return array_keys($e->errors); } return []; };
        $this->assertContains('reason', $errs(), 'default: a reason is needed');
        Choices::set('STOCK_UPDATE_REASON', '0', 1); $this->assertNotContains('reason', $errs(), 'optional now (the bad location still refuses)');
        $this->assertSame(7, Subscriptions::page(1)['due_days']);
        Choices::set('ACCOUNT_DUE_DAYS', '14', 1); $this->assertSame(14, Subscriptions::page(1)['due_days']);
    }
}
