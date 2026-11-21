<?php

namespace Tests\Feature;

use App\Services\Choices;
use App\Services\FinanceAlerts;
use App\Services\RecordService;
use App\Services\StockHealth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Owner rule 22 Nov 03:47: B9 the Private rule and B12 the free-cash formula are Settings › Choices (default = my suggestion). */
class ChoiceMoneyTest extends TestCase
{
    public function test_b9_private_on_any_or_every_shop(): void
    {
        DB::table('t_product_vendors')->where('fk_id_ent', 1)->update(['stock' => 0]);
        $p = DB::table('t_product_vendors')->insertGetId(RecordService::fillNotNull('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'PRV-' . uniqid(), 'product_name_vendor' => 'Lamp', 'stock' => 10, 'f_active' => 1]));
        foreach (['PRIVATE', 'POOL'] as $m) DB::table('t_product_visibility')->insert(RecordService::fillNotNull('t_product_visibility', ['fk_id_product' => $p, 'visibility_mode' => $m, 'fk_id_marketplace' => $m === 'POOL' ? 1 : 2]));
        $this->assertSame([10, 0], [StockHealth::split(1)['private'], StockHealth::split(1)['pool']], 'default: private on any shop → Private');
        Choices::set('PRIVATE_RULE', 'ALL', 1);
        $this->assertSame([0, 10], [StockHealth::split(1)['private'], StockHealth::split(1)['pool']], 'private everywhere only → this one is Pool');
    }

    public function test_b12_free_cash_counts_open_supplier_payments(): void
    {
        $po = DB::table('t_src_po')->insertGetId(RecordService::fillNotNull('t_src_po', ['fk_id_ent' => 1, 'po_code' => 'PO-FC', 'po_number' => 'PO-FC', 'po_date' => date('Y-m-d'), 'status' => 'CONFIRMED', 'f_active' => 1]));
        $base = FinanceAlerts::freeCash(1)['committed'];
        DB::table('t_src_payment_terms')->insert(RecordService::fillNotNull('t_src_payment_terms', ['fk_id_ent' => 1, 'fk_id_po' => $po, 'term_no' => 2, 'milestone' => 'Balance', 'planned_amount' => 1000, 'paid_amount' => 0, 'remaining_amount' => 1000, 'status' => 'DUE', 'f_active' => 1, 'due_date' => date('Y-m-d', strtotime('+60 days'))]));
        $this->assertEqualsWithDelta($base + 1000, FinanceAlerts::freeCash(1)['committed'], 0.01, 'default: every open supplier payment');
        Choices::set('FREE_CASH_COMMITTED', 'DUE_30', 1);
        $this->assertEqualsWithDelta($base, FinanceAlerts::freeCash(1)['committed'], 0.01, 'only due in 30 days → the payment in 60 days is left out');
    }
}
