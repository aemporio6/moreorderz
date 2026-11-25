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
        try { H::entry(1, ['order_date' => '2025-03-01'], $this->owner); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('Select the Supplier', $e->getMessage()); }
        $n = DB::table('t_src_cycles')->count();
        try { H::entry(1, ['supplier_name' => 'Old Sup', 'order_date' => '2025-03-01', 'lines' => [['product_name' => 'X', 'qty' => 1]]], $this->owner); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('order_ref is REQUIRED', $e->getMessage()); }
        $this->assertSame($n, DB::table('t_src_cycles')->count(), 'R-B_SOURCING-54 rule example: nothing saved');
        try { H::entry(1, ['supplier_name' => 'Old Sup', 'order_date' => '2025-03-01', 'order_ref' => 'R-1'], $this->owner); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('At least one line is required', $e->getMessage()); }
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

    public function test_r5_approval_holds_the_stock(): void
    {
        $src = \App\Models\User::create(['login_id' => 'src' . uniqid() . '@t', 'password_hash' => bcrypt('x-password-1'), 'role_code' => 'SOURCING', 'display_name' => 'Buyer', 'fk_id_ent' => 1, 'f_active' => 1]);
        $this->actingAs($src); Context::set(1, $src->id);
        $r = H::entry(1, $this->entry(['order_ref' => 'PI-APP', 'lines' => [['product_name' => 'Steel mug', 'sku' => 'MUG-AP', 'qty' => 10, 'unit_price' => 2.00, 'qty_accepted' => 10]], 'received' => ['date' => '2025-05-02', 'warehouse' => $this->wh]]), $src);
        $this->assertTrue($r['pending']); $this->assertSame('Saved PENDING APPROVAL - stock unaffected until a Partner approves. Opening cycle...', $r['message']);
        $this->assertSame(0, DB::table('fin_lots')->where('fk_id_cycle', $r['cycle'])->count(), 'stock unaffected until approved');
        $a = DB::table('sys_approvals')->where('action_code', 'SRC_HISTORICAL')->where('entity_id', $r['cycle'])->first();
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        \App\Services\Approvals::decide((int) $a->id, true);
        $this->assertSame(10, (int) DB::table('fin_lots')->where('fk_id_cycle', $r['cycle'])->sum('qty'), 'approved → the lot is made');
        $this->assertSame(['APPROVED', 'COMPLETED'], array_values((array) DB::table('t_src_cycles')->where('id', $r['cycle'])->first(['approval_status', 'status'])));
    }

    public function test_r13_check_screen_writes_nothing_and_force_imports(): void
    {
        H::entry(1, $this->entry(['order_ref' => 'PI-D']), $this->owner);
        $base = ['supplier_name' => 'Ningbo Housewares', 'order_date' => '2025-03-01', 'currency' => 'USD', 'owner_entity' => 'OWN1', 'unit_price' => '2.00', 'qty' => '10', 'product_name' => 'Mug', 'sku' => 'MUG-ST'];
        $file = $this->csv([['order_ref' => 'PI-D'] + $base, ['order_ref' => 'PI-E'] + $base, ['order_ref' => 'PI-F', 'owner_entity' => 'NOBODY'] + $base]);
        $before = DB::table('t_src_cycles')->count();
        $c = H::check(1, $file, $this->owner);
        $this->assertSame($before, DB::table('t_src_cycles')->count(), 'the check writes nothing');
        $by = collect($c['entries'])->keyBy('ref');
        $this->assertNotNull($by['PI-D']['duplicate']); $this->assertSame([], $by['PI-E']['problems']); $this->assertSame(['owner entity "NOBODY" not found'], $by['PI-F']['problems']); $this->assertSame(1, $c['ok']);
        $r = H::import(1, $file, $this->owner, ['PI-D' => 'second shipment of the same PI']);
        $this->assertSame([2, 1], [$r['created'], $r['failed']], 'the duplicate forced with a reason is imported; the one without owner fails');
        $this->assertNotEmpty(array_filter($r['log'], fn ($l) => str_contains($l, 'OVERRIDE duplicate')));
    }

    public function test_r39_trail_produced_qc_in_stock(): void
    {
        $r = H::entry(1, $this->entry(['order_ref' => 'PI-Q', 'lines' => [['product_name' => 'Steel mug', 'sku' => 'MUG-Q', 'qty' => 10, 'unit_price' => 2.00, 'qty_accepted' => 8, 'qty_damaged' => 1, 'qty_missing' => 1]], 'received' => ['date' => '2025-05-02', 'warehouse' => $this->wh]]), $this->owner);
        DB::table('t_src_production')->insert(RecordService::fillNotNull('t_src_production', ['fk_id_ent' => 1, 'fk_id_cycle' => $r['cycle'], 'fk_id_po' => $r['po'], 'produced_qty' => 10, 'f_active' => 1, 'created_at' => now()]));
        DB::table('t_src_qc')->insert(RecordService::fillNotNull('t_src_qc', ['fk_id_ent' => 1, 'fk_id_cycle' => $r['cycle'], 'fk_id_po' => $r['po'], 'qty_passed' => 9, 'f_active' => 1, 'created_at' => now()]));
        $q = \App\Services\CycleAlerts::quantities($r['cycle'])[0];
        $this->assertSame([10.0, 9.0, 8.0], [$q['produced'], $q['qc_passed'], $q['in_stock']], 'the trail adds produced, QC passed and in stock (accepted units on hand)');
    }

    public function test_r10_receiving_section(): void
    {
        $e = $this->entry(['order_ref' => 'PI-R10', 'lines' => [['product_name' => 'Steel mug', 'sku' => 'MUG-R10', 'qty' => 100, 'unit_price' => 2.00, 'qty_accepted' => 96, 'qty_damaged' => 3, 'qty_missing' => 1, 'landed_override' => '1.95', 'override_reason' => 'agreed with the supplier'],
            ['product_name' => 'Steel lid', 'sku' => 'LID-R10', 'qty' => 20, 'unit_price' => 0.50]], 'received' => ['date' => '2025-05-02', 'warehouse' => $this->wh]]);
        try { H::entry(1, ['order_ref' => 'PI-R10X'] + array_replace_recursive($e, ['lines' => [['override_reason' => '']]]), $this->owner); $this->fail(); } catch (\InvalidArgumentException $x) { $this->assertStringContainsString('reason for the landed cost override', $x->getMessage()); }
        $r = H::entry(1, $e, $this->owner);
        $g = DB::table('t_src_grn')->where('fk_id_cycle', $r['cycle'])->first();
        $this->assertSame([119, 3, 1, 'Accepted', 'Historical entry PI-R10'], [(int) $g->units_received, (int) $g->damaged_units, (int) $g->missing_units, $g->receiving_qc_status, $g->notes], '96 + 3 received on the mug, the lid with no row counts as accepted = ordered (20)');
        $mug = DB::table('t_product_vendors')->where('SKU_VENDOR', 'MUG-R10')->value('id');
        $lot = DB::table('fin_lots')->where('fk_id_cycle', $r['cycle'])->where('fk_id_product', $mug)->first();
        $this->assertSame([99, 'HIST-' . $r['cycle'] . '-1', 1.95, 'FINAL/OVERRIDE'], [(int) $lot->qty, $lot->batch_no, (float) $lot->unit_cost_gbp, $lot->landed_status . '/' . $lot->cost_source], 'batch auto HIST-<cycle>-<n>, the override with its reason');
        $dam = \App\Services\StockService::systemLocation(1, 'DAMAGED');
        $this->assertSame(3, (int) DB::table('fin_lot_balances')->where('fk_id_lot', $lot->id)->where('fk_id_location', $dam)->sum('qty'), '3 units on DAMAGED');
        $this->assertTrue(\App\Services\TaskEngine::isOpen('SRC_GRN_SHORT', \App\Services\CycleAlerts::key('SRC_GRN_SHORT', (int) $g->id)), 'missing / damaged → SRC_GRN_SHORT');
        $this->assertContains('GRN + 2 lot(s)', $r['log']);
    }
}
