<?php

namespace Tests\Feature;

use App\Services\Ledger;
use App\Services\StockService as Stock;
use App\Services\TaskEngine;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StockTest extends TestCase
{
    private int $p; private int $wh; private int $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->p = $this->product('BOWL-01');
        $this->wh = $this->loc('Main warehouse', 'WAREHOUSE');
        $this->shop = $this->loc('Marketplace FC', 'MARKETPLACE_FULFILMENT');
    }

    private function fill(string $table, array $data): array
    {
        $m = new \ReflectionMethod(\App\Services\RecordService::class, 'fillNotNull');
        return $m->invoke(null, $table, $data);
    }

    private function product(string $sku, string $type = 'pool', int $ent = 1): int
    {
        return DB::table('t_product_vendors')->insertGetId($this->fill('t_product_vendors', ['fk_id_ent' => $ent, 'SKU_VENDOR' => $sku, 'product_name_vendor' => "Product $sku", 'e_product_type' => $type, 'stock' => 0, 'f_active' => 1]));
    }

    private function loc(string $name, string $kind, int $sellable = 1): int
    {
        return DB::table('cat_inventory_locations')->insertGetId($this->fill('cat_inventory_locations', ['location_name' => $name, 'kind' => $kind, 'fk_id_ent' => 1, 'f_sellable' => $sellable, 'f_active' => 1]));
    }

    private function party(string $name): int
    {
        return DB::table('t_account')->insertGetId($this->fill('t_account', ['fk_id_ent' => 1, 'first_name' => $name, 'f_active' => 1]));
    }

    private function in(int $qty, float $cost, string $date, array $x = []): int
    {
        return Stock::receive(1, [['product' => $this->p, 'qty' => $qty, 'location' => $this->wh, 'unit_cost' => $cost, 'received_date' => $date] + $x], 'GRN', 1)['lots'][0];
    }

    public function test_receive_makes_a_lot_a_ledger_line_the_cached_number_and_a_balanced_journal(): void
    {
        $lot = $this->in(100, 4.25, '2026-08-01');
        $this->assertSame(100, Stock::balance($this->p));
        $this->assertSame(100, (int) DB::table('t_product_vendors')->where('id', $this->p)->value('stock'));
        $m = DB::table('t_stock_movements')->where('fk_id_lot', $lot)->first();
        $this->assertSame(['purchase_in', 100, 100], [$m->movement_type, (int) $m->qty_change, (int) $m->stock_after]);
        $this->assertMatchesRegularExpression('/^LOT-TST-2608-0001$/', DB::table('fin_lots')->where('id', $lot)->value('lot_code'));
        $this->assertSame(425.00, Stock::value(1));
        $this->assertSame(425.00, Ledger::balance(1, '1200'), 'stock value in the ledger = units × lot cost');
        $this->assertTrue(Ledger::trialBalance(1)['balanced']);
    }

    public function test_fifo_takes_the_oldest_lot_first_across_three_lots_with_different_costs(): void
    {
        $c = $this->in(10, 6.00, '2026-08-20');       // newest, entered first on purpose
        $a = $this->in(10, 4.00, '2026-08-01');
        $b = $this->in(10, 5.00, '2026-08-10');
        $r = Stock::consumeForSale(1, 9001, $this->p, 25, $this->wh);
        $this->assertSame(10 * 4.00 + 10 * 5.00 + 5 * 6.00, $r['cost']);
        $this->assertSame([[$a, 10], [$b, 10], [$c, 5]], array_map(fn ($x) => [$x['lot'], $x['qty']], $r['rows']));
        $this->assertSame(5, Stock::balance($this->p));
        $this->assertSame(3, DB::table('fin_lot_consumption')->where('fk_id_sale', 9001)->count());
        $this->assertSame(5, (int) DB::table('fin_lots')->where('id', $c)->value('qty_left'));
        $this->assertSame(120.00, Ledger::balance(1, '5000'), 'cost of goods sold is posted with the sale');
        $this->assertSame(Stock::value(1), Ledger::balance(1, '1200'), 'what is left in stock = the stock account');
    }

    public function test_same_received_date_takes_the_lower_lot_id_first(): void
    {
        $x = $this->in(5, 9.00, '2026-08-01');
        $y = $this->in(5, 1.00, '2026-08-01');
        $r = Stock::consumeForSale(1, 9002, $this->p, 5, $this->wh);
        $this->assertSame($x, $r['rows'][0]['lot']);
        $this->assertSame(45.00, $r['cost'], 'cost never changes the order');
    }

    public function test_never_oversells_and_refuses_in_plain_words(): void
    {
        $this->in(3, 4.00, '2026-08-01');
        try { Stock::consumeForSale(1, 9003, $this->p, 4, $this->wh); $this->fail('oversold'); }
        catch (\RuntimeException $e) { $this->assertSame('Not enough stock of BOWL-01 at Main warehouse: need 4, have 3.', $e->getMessage()); }
        $this->assertSame(3, Stock::balance($this->p), 'a refused sale changes nothing');
        $this->assertSame(0, DB::table('fin_lot_consumption')->count());
    }

    public function test_private_sale_takes_only_its_owner_and_pool_sale_never_takes_private_lots(): void
    {
        $ali = $this->party('Ali'); $sara = $this->party('Sara');
        $this->in(5, 4.00, '2026-08-01', ['owner' => $ali, 'visibility' => 'PRIVATE']);
        $this->in(5, 5.00, '2026-08-02', ['owner' => $sara, 'visibility' => 'PRIVATE']);
        $this->in(5, 6.00, '2026-08-03', ['visibility' => 'POOL']);
        $r = Stock::consumeForSale(1, 9004, $this->p, 5, $this->wh, $sara);
        $this->assertSame([$sara], array_unique(array_column($r['rows'], 'owner')));
        $this->assertSame(25.00, $r['cost']);
        $this->expectExceptionMessage('need 6, have 5');
        Stock::consumeForSale(1, 9005, $this->p, 6, $this->wh);       // pool has only 5; private lots are not touched
    }

    public function test_transfer_moves_the_lot_with_its_cost_and_changes_no_value(): void
    {
        $lot = $this->in(20, 4.50, '2026-08-01');
        Stock::transfer(1, $this->p, 8, $this->wh, $this->shop, 'Send to fulfilment centre');
        $this->assertSame(12, Stock::balance($this->p, $this->wh));
        $this->assertSame(8, Stock::balance($this->p, $this->shop));
        $this->assertSame(20, Stock::balance($this->p));
        $this->assertSame(90.00, Ledger::balance(1, '1200'));
        $pair = DB::table('t_stock_movements')->where('movement_type', 'transfer_in')->first();
        $this->assertSame([$lot, 4.5], [(int) $pair->fk_id_lot, (float) $pair->unit_cost_gbp]);
        $this->assertNotNull($pair->pair_id);
        $r = Stock::consumeForSale(1, 9006, $this->p, 8, $this->shop);
        $this->assertSame(36.00, $r['cost'], 'sale at the fulfilment centre carries the lot cost');
    }

    public function test_return_goes_back_into_the_same_lot_and_cannot_return_more_than_sold(): void
    {
        $lot = $this->in(10, 4.00, '2026-08-01');
        Stock::consumeForSale(1, 9007, $this->p, 4, $this->wh);
        $r = Stock::returnIn(1, 9007, 3, 'SELLABLE');
        $this->assertSame(12.00, $r['cost_back']);
        $this->assertSame(9, Stock::balance($this->p, $this->wh));
        $this->assertSame(9, (int) DB::table('fin_lots')->where('id', $lot)->value('qty_left'));
        $this->assertSame(1, (int) DB::table('fin_lot_consumption')->where('fk_id_sale', 9007)->sum('qty'), 'net consumption = 4 sold − 3 returned');
        Stock::returnIn(1, 9007, 1, 'DAMAGED');
        $this->assertSame(1, Stock::balance($this->p, Stock::systemLocation(1, 'DAMAGED')));
        $this->assertSame(Stock::value(1), Ledger::balance(1, '1200'), 'returns put their cost back into the stock account');
        $this->assertSame(0.00, Ledger::balance(1, '5000'), '4 sold, 4 came back: no cost of sale left');
        $this->assertSame(9, Stock::available($this->p), 'damaged units are in stock but not sellable');
        $this->expectExceptionMessage('Only 0 unit(s) of this sale can still come back');
        Stock::returnIn(1, 9007, 1);
    }

    public function test_adjustment_needs_a_reason_posts_the_loss_and_keeps_stock_value_equal_to_the_ledger(): void
    {
        $this->in(10, 4.00, '2026-08-01');
        try { Stock::adjust(1, $this->p, $this->wh, -2, ' '); $this->fail('no reason'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('reason', $e->getMessage()); }
        Stock::adjust(1, $this->p, $this->wh, -2, 'Broken in the warehouse');
        $this->assertSame(8, Stock::balance($this->p));
        $this->assertSame(8.00, Ledger::balance(1, '5500'));
        Stock::adjust(1, $this->p, $this->wh, 1, 'Found one behind the shelf');
        $this->assertSame(Stock::value(1), Ledger::balance(1, '1200'));
        $this->assertSame(36.00, Stock::value(1));
        $this->expectExceptionMessage('Not enough stock');
        Stock::adjust(1, $this->p, $this->wh, -50, 'Typing mistake');
    }

    public function test_ownership_transfer_splits_the_lot_and_keeps_the_fifo_date(): void
    {
        $ali = $this->party('Ali'); $sara = $this->party('Sara');
        $lot = $this->in(10, 4.00, '2026-08-01', ['owner' => $ali]);
        $new = Stock::transferOwnership(1, $lot, 4, $sara);
        $n = DB::table('fin_lots')->where('id', $new)->first();
        $this->assertSame(['SPLIT', '2026-08-01', 4, 4.0, $sara], [$n->source, $n->received_date, (int) $n->qty_left, (float) $n->unit_cost_gbp, (int) $n->fk_id_owner]);
        $this->assertSame(6, Stock::balance($this->p, null, $ali));
        $this->assertSame(4, Stock::balance($this->p, null, $sara));
        $this->assertSame(10, Stock::balance($this->p));
        $this->assertSame(40.00, Stock::value(1), 'no value is created or lost');
        $this->expectExceptionMessage('Only 6 unit(s) are left');
        Stock::transferOwnership(1, $lot, 7, $sara);
    }

    public function test_damaged_on_arrival_is_kept_apart_and_opens_a_claim_task(): void
    {
        $lot = Stock::receive(1, [['product' => $this->p, 'qty' => 10, 'damaged_qty' => 2, 'location' => $this->wh, 'unit_cost' => 4]], 'GRN', 7)['lots'][0];
        $this->assertSame(8, Stock::balance($this->p, $this->wh));
        $this->assertSame(8, Stock::available($this->p));
        $this->assertSame(10, Stock::balance($this->p));
        $this->assertTrue(TaskEngine::isOpen('SRC_GRN_SHORT', [7, 'BOWL-01']));
    }

    public function test_reserved_units_are_not_available(): void
    {
        $this->in(10, 4.00, '2026-08-01');
        DB::table('wh_reservations')->insert($this->fill('wh_reservations', ['fk_id_ent' => 1, 'fk_id_site' => $this->wh, 'fk_id_product' => $this->p, 'owner_rule' => 'POOL', 'qty' => 3, 'qty_picked' => 0, 'status' => 'OPEN']));
        $this->assertSame(7, Stock::available($this->p));
    }

    public function test_self_check_finds_a_typed_stock_number_and_closes_the_task_when_it_is_fixed(): void
    {
        $this->in(10, 4.00, '2026-08-01');
        $this->assertSame(0, Stock::selfCheck(1)['mismatch']);
        $this->assertTrue(Stock::selfCheck(1)['value_matches_ledger']);
        DB::table('t_product_vendors')->where('id', $this->p)->update(['stock' => 99]);      // somebody typed a number
        $this->assertSame(1, Stock::selfCheck(1)['mismatch']);
        $this->assertTrue(TaskEngine::isOpen('STK_LEDGER_MISMATCH', [$this->p]));
        DB::table('t_product_vendors')->where('id', $this->p)->update(['stock' => 10]);
        Stock::selfCheck(1);
        $this->assertFalse(TaskEngine::isOpen('STK_LEDGER_MISMATCH', [$this->p]));
    }

    public function test_another_company_cannot_touch_this_stock(): void
    {
        DB::table('t_enterprise')->insert(['id' => 2, 'fk_id_module' => 0, 'iso_ent' => 'TS2', 'ent_name' => 'Second Ltd', 'ent_address' => '-', 'f_active' => 1, 'ent_short' => 'TS2']);
        $this->in(10, 4.00, '2026-08-01');
        $this->expectExceptionMessage('Product not found in this company');
        Stock::adjust(2, $this->p, $this->wh, -1, 'wrong company');
    }

    public function test_two_pickers_at_the_same_moment_never_take_the_same_units(): void
    {
        DB::rollBack();                                   // this test needs real, committed rows: two separate processes
        $p = $this->product('RACE-' . uniqid()); $wh = $this->loc('Race warehouse ' . uniqid(), 'WAREHOUSE');
        try {
            Stock::receive(1, [['product' => $p, 'qty' => 10, 'location' => $wh, 'unit_cost' => 2]], 'GRN', 1, ['post' => false]);
            $procs = [];
            for ($i = 0; $i < 6; $i++) $procs[] = proc_open(['php', base_path('tests/Support/race_worker.php'), (string) $p, (string) $wh, '3', (string) (7000 + $i)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
            $ok = 0; $refused = 0; $all = '';
            foreach ($procs as $i => $pr) { $out = stream_get_contents($pipes[$i][1]) . stream_get_contents($pipes[$i][2]); proc_close($pr); $all .= $out; str_starts_with($out, 'OK') ? $ok++ : $refused++; }
            $this->assertSame(3, $ok, '10 units, 6 pickers of 3 each: exactly 3 succeed. Output: ' . mb_substr($all, 0, 600));
            $this->assertSame(3, $refused);
            $this->assertSame(1, Stock::balance($p));
            $this->assertSame(0, DB::table('fin_lot_balances as b')->join('fin_lots as l', 'l.id', '=', 'b.fk_id_lot')->where('l.fk_id_product', $p)->where('b.qty', '<', 0)->count());
        } finally {
            $lots = DB::table('fin_lots')->where('fk_id_product', $p)->pluck('id');
            DB::table('fin_lot_consumption')->whereIn('lot_id', $lots)->delete(); DB::table('fin_lot_balances')->whereIn('fk_id_lot', $lots)->delete();
            DB::table('t_stock_movements')->where('fk_id_product', $p)->delete(); DB::table('fin_lots')->whereIn('id', $lots)->delete();
            DB::table('sys_events')->where('entity', 'fin_lots')->whereIn('entity_id', $lots)->delete(); DB::table('sys_events')->where('entity', 't_src_grn')->delete();
            DB::table('t_product_vendors')->where('id', $p)->delete(); DB::table('cat_inventory_locations')->where('id', $wh)->delete();
            $j = DB::table('gl_journals')->where('source_type', 'SALE_CONFIRM')->whereBetween('source_id', [7000, 7005])->pluck('id');
            DB::table('gl_lines')->whereIn('journal_id', $j)->delete(); DB::table('gl_journals')->whereIn('id', $j)->delete();
            DB::table('sys_sequences')->whereIn('doc_type', ['LOT', 'JNL'])->delete();
            DB::table('t_users')->where('login_id', 'owner@test')->delete(); DB::table('t_enterprise')->where('id', 1)->where('iso_ent', 'TST')->delete();
            DB::beginTransaction();                       // so the normal tearDown has a transaction to roll back
        }
    }
}
