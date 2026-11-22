<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\Fx;
use App\Services\HistoricalPurchase as H;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-B_SOURCING-5 Old entry, R-B_SOURCING-8 payments, R-B_SOURCING-13 historical CSV – the spec tests. */
class HistoricalPurchaseTest extends TestCase
{
    private int $owner_acc; private int $wh;

    protected function setUp(): void
    {
        parent::setUp(); $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        Fx::set('USD', '2025-03-01', 0.80); Fx::set('USD', '2025-05-01', 0.80);
        $this->owner_acc = (int) DB::table('t_account')->insertGetId(RecordService::fillNotNull('t_account', ['fk_id_ent' => 1, 'account_code' => 'OWN1', 'first_name' => 'Main', 'sur_name' => 'Owner', 'f_active' => 1]));
        $this->wh = (int) DB::table('cat_inventory_locations')->insertGetId(RecordService::fillNotNull('cat_inventory_locations', ['location_name' => 'Main WH', 'kind' => 'WAREHOUSE', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1]));
    }

    private function entry(array $x = []): array
    {
        return $x + ['supplier_name' => 'Ningbo Housewares', 'order_date' => '2025-03-01', 'order_ref' => 'PI-778', 'currency' => 'USD', 'owner' => $this->owner_acc,
            'lines' => [['product_name' => 'Steel mug', 'sku' => 'MUG-ST', 'qty' => 1000, 'unit_price' => 2.00]]];
    }

    public function test_r5_r8_entry_payments_open_balance_and_status(): void
    {
        try { H::entry(1, ['order_date' => '2025-03-01'], $this->owner); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('Section 1: Supplier, Order Date, Order Reference and at least one line are required', $e->getMessage()); }
        try { H::entry(1, $this->entry(['owner' => null]), $this->owner); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertStringStartsWith('Owner Entity (buyer) is required', $e->getMessage()); }
        $r = H::entry(1, $this->entry(['payments' => [['amount' => 600, 'date' => '2025-03-01'], ['amount' => 900, 'date' => '2025-05-01']]]), $this->owner);
        $terms = DB::table('t_src_payment_terms')->where('fk_id_po', $r['po'])->orderBy('term_no')->get();
        $this->assertSame([['PAID', 30.0, 600.0], ['PAID', 45.0, 900.0], ['DUE', 25.0, 500.0]], $terms->map(fn ($t) => [$t->status, (float) $t->pct, (float) $t->planned_amount])->all(), 'total 2,000 USD, paid 600 + 900 → 2 Paid terms (30 %, 45 %), open term 500.00');
        $this->assertSame(['Deposit / Payment 1', 'Payment 2', 'Balance (open)', '2025-03-31'], [$terms[0]->milestone, $terms[1]->milestone, $terms[2]->milestone, $terms[2]->due_date]);
        $this->assertSame(500.0, (float) DB::table('t_src_po')->where('id', $r['po'])->value('balance_amount'), 'PO balance 500.00');
        $this->assertSame(['HISTORICAL', 'OPEN', 'rfq,samples,production,qc'], array_values((array) DB::table('t_src_cycles')->where('id', $r['cycle'])->first(['cycle_type', 'status', 'skipped_steps'])));
        $this->assertContains('Balance OPEN: 500.00 USD still due (shows on the dashboard)', $r['log']);
        $this->assertSame(2, DB::table('t_src_payments')->where('fk_id_po', $r['po'])->where('status', 'PAID')->count());
        try { H::entry(1, $this->entry(['order_ref' => ' pi-778 ']), $this->owner); $this->fail('duplicate saved'); } catch (\App\Services\SrcDuplicateException $e) { $this->assertStringContainsString('already exists as', $e->getMessage()); }
    }

    public function test_r5_received_completes_and_makes_lots(): void
    {
        $e = $this->entry(['order_ref' => 'PI-900', 'lines' => [['product_name' => 'Steel mug', 'sku' => 'MUG-ST', 'qty' => 100, 'unit_price' => 2.00, 'qty_accepted' => 95, 'qty_damaged' => 3, 'qty_missing' => 2]],
            'shipping' => ['shipped_date' => '2025-04-01', 'arrived_date' => '2025-05-01', 'freight' => [['cost' => 50]], 'duty' => 20], 'received' => ['date' => '2025-05-02', 'warehouse' => $this->wh]]);
        $r = H::entry(1, $e, $this->owner);
        $this->assertTrue($r['completed']); $this->assertContains('Cycle CLOSED (every PRODUCT line received)', $r['log']);
        $lot = DB::table('fin_lots')->where('fk_id_cycle', $r['cycle'])->first();
        $this->assertSame([98, $this->owner_acc], [(int) $lot->qty, (int) $lot->fk_id_owner], 'one lot of the received units, owner = the owner entity');
        $this->assertSame(1, DB::table('t_src_freight_costs')->where('fk_id_cycle', $r['cycle'])->count()); $this->assertSame(1, DB::table('t_src_customs')->where('fk_id_cycle', $r['cycle'])->count());
        $this->assertSame(1, (int) DB::table('t_product_vendors')->where('SKU_VENDOR', 'MUG-ST')->value('f_active'), 'the draft product is live once stock arrived');
    }

    private function csv(array $rows): string
    {
        $out = [implode(',', H::COLUMNS)];
        foreach ($rows as $r) { $a = array_fill_keys(H::COLUMNS, ''); foreach ($r as $k => $v) $a[$k] = $v; $out[] = implode(',', $a); }
        return implode("\n", $out) . "\n";
    }

    public function test_r13_csv_double_count_and_undo(): void
    {
        try { H::import(1, "order_ref,supplier_name,qty\nA,B,1\n", $this->owner); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('Missing required column: product_name', $e->getMessage()); }
        $base = ['order_ref' => 'A', 'supplier_name' => 'Ningbo Housewares', 'order_date' => '2025-03-01', 'currency' => 'USD', 'owner_entity' => 'OWN1', 'unit_price' => '2.00', 'qty' => '100'];
        $file = $this->csv([$base + ['product_name' => 'Mug', 'sku' => 'M1', 'paid_amount' => '600', 'paid_date' => '2025-03-02'], $base + ['product_name' => 'Lid', 'sku' => 'L1', 'paid_amount' => '600'], $base + ['product_name' => 'Box', 'sku' => 'B1'],
            ['order_ref' => '', 'product_name' => 'x'], ['order_ref' => 'Z', 'supplier_name' => 'S', 'product_name' => '', 'line_type' => '']]);
        $r = H::import(1, $file, $this->owner);
        $this->assertSame('Done: 1 entries created (0 pending approval) · 0 failed', $r['message']);
        $cy = DB::table('t_src_cycles')->where('order_ref', 'A')->value('id');
        $this->assertSame(1, DB::table('t_src_payments')->where('fk_id_cycle', $cy)->count(), 'paid_amount 600 on rows 1 and 2 → 1 payment (old: 2, 1,200 paid)');
        $this->assertNotEmpty(array_filter($r['log'], fn ($l) => str_contains($l, 'possible double count')), 'WARN possible double count');
        $this->assertNotEmpty(array_filter($r['log'], fn ($l) => str_contains($l, 'skipped: order_ref is REQUIRED'))); $this->assertNotEmpty(array_filter($r['log'], fn ($l) => str_contains($l, 'skipped: product_name empty')));
        $this->assertSame(3, DB::table('t_src_po_lines')->where('fk_id_cycle', $cy)->count(), 'rows with the same order_ref = one entry');
        $r2 = H::import(1, $this->csv([['order_ref' => 'B'] + $base + ['product_name' => 'Mug', 'sku' => 'M1', 'paid_amount' => '600', 'paid_amount_2' => '600', 'paid_date_2' => '2025-04-01']]), $this->owner);
        $this->assertSame(2, DB::table('t_src_payments')->where('fk_id_cycle', DB::table('t_src_cycles')->where('order_ref', 'B')->value('id'))->count(), 'with paid_amount_2 = 600 → 2 payments');
        $dup = H::import(1, $this->csv([$base + ['product_name' => 'Mug', 'sku' => 'M1']]), $this->owner);
        $this->assertSame(1, $dup['failed'], 'a duplicate entry is not imported');
        $u = H::undo($r['job'], $this->owner);
        $this->assertSame(1, $u['removed']); $this->assertFalse(DB::table('t_src_cycles')->where('id', $cy)->exists());
        $this->assertSame(0, DB::table('t_src_payments')->where('fk_id_cycle', $cy)->count() + DB::table('t_src_po_lines')->where('fk_id_cycle', $cy)->count());
        $this->assertStringStartsWith('order_ref,order_ref_note,supplier_name', H::template());
    }
}
