<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\CountService as Count;
use App\Services\Ledger;
use App\Services\LocationGenerator;
use App\Services\PriceService as Price;
use App\Services\StockService as Stock;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Stock2Test extends TestCase
{
    private int $p; private int $wh;

    protected function setUp(): void
    {
        parent::setUp();
        $this->p = DB::table('t_product_vendors')->insertGetId($this->fill('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'BOWL-01', 'product_name_vendor' => 'Dog bowl', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']));
        $this->wh = DB::table('cat_inventory_locations')->insertGetId($this->fill('cat_inventory_locations', ['location_name' => 'Main', 'kind' => 'WAREHOUSE', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1]));
        Stock::receive(1, [['product' => $this->p, 'qty' => 20, 'location' => $this->wh, 'unit_cost' => 4, 'received_date' => '2026-08-01']], 'GRN', 1);
    }

    private function fill(string $table, array $data): array
    {
        $m = new \ReflectionMethod(\App\Services\RecordService::class, 'fillNotNull');
        return $m->invoke(null, $table, $data);
    }

    public function test_count_posts_only_the_difference_and_needs_a_second_person(): void
    {
        $id = Count::open(1, $this->wh);
        $this->assertSame(20, (int) DB::table('t_stock_count_lines')->where('fk_id_count', $id)->value('qty_system'));
        try { Count::submit($id); $this->fail('uncounted submitted'); } catch (\RuntimeException $e) { $this->assertStringContainsString('not counted yet', $e->getMessage()); }
        Count::enter($id, $this->p, 18, 'two missing on shelf');
        Stock::consumeForSale(1, 5001, $this->p, 5, $this->wh);                  // stock moves after the snapshot
        $s = Count::submit($id);
        $this->assertSame(-8.00, $s['variance_value']);
        try { Count::approve($id); $this->fail('self approval'); } catch (\RuntimeException $e) { $this->assertStringContainsString('Somebody else', $e->getMessage()); }
        try { Count::post($id); $this->fail('posted without approval'); } catch (\RuntimeException $e) { $this->assertStringContainsString('SUBMITTED', $e->getMessage()); }
        $mgr = $this->makeUser('MANAGER'); Context::set(1, $mgr->id);
        Count::approve($id);
        $r = Count::post($id);
        $this->assertSame(['lines_posted' => 1, 'value' => -8.00], $r);
        $this->assertSame(13, Stock::balance($this->p), '20 − 5 sold − 2 difference; the counted 18 is never written over the stock');
        $this->assertSame('count_adjust', DB::table('t_stock_movements')->where('source_type', 'COUNT')->value('movement_type'));
        $this->assertSame(Stock::value(1), Ledger::balance(1, '1200'));
        $this->expectExceptionMessage('POSTED');
        Count::post($id);
    }

    public function test_count_finds_a_product_the_system_did_not_expect(): void
    {
        $other = DB::table('t_product_vendors')->insertGetId($this->fill('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'LEAD-02', 'product_name_vendor' => 'Lead', 'stock' => 0, 'f_active' => 1]));
        $id = Count::open(1, $this->wh);
        Count::enter($id, $this->p, 20); Count::enter($id, $other, 3);
        Count::submit($id);
        Context::set(1, $this->makeUser('MANAGER')->id);
        Count::approve($id); Count::post($id);
        $this->assertSame(3, Stock::balance($other, $this->wh));
        $this->assertSame(20, Stock::balance($this->p));
    }

    public function test_price_periods_close_each_other_and_a_sale_date_finds_its_price(): void
    {
        $mp = DB::table('cat_marketplaces')->insertGetId($this->fill('cat_marketplaces', ['fk_id_ent' => 1]));
        Price::set($this->p, $mp, 8.00, 12.99, '2026-08-01');
        Price::set($this->p, $mp, 8.00, 14.99, '2026-09-01');
        Price::set($this->p, $mp, 8.00, 13.49, '2026-08-15');                    // entered late, in the middle
        $this->assertSame(12.99, (float) Price::on($this->p, $mp, '2026-08-10')->sale_price);
        $this->assertSame(13.49, (float) Price::on($this->p, $mp, '2026-08-31')->sale_price);
        $this->assertSame(14.99, (float) Price::on($this->p, $mp, '2026-10-20')->sale_price);
        $this->assertNull(Price::on($this->p, $mp, '2026-07-31'));
        $this->assertSame(14.99, (float) DB::table('t_product_marketplace_prices')->where(['fk_id_product' => $this->p, 'fk_id_marketplace' => $mp])->value('sale_price'), 'current price = newest period');
        $tl = Price::timeline($this->p);
        $this->assertSame(['2026-09-01', '2026-08-15', '2026-08-01'], array_column($tl, 'from_date'));
        $this->assertSame([null, '2026-08-31', '2026-08-14'], array_column($tl, 'to_date'));
        $this->expectExceptionMessage('already starts on 2026-08-15');
        Price::set($this->p, $mp, 8.00, 1.00, '2026-08-15');
    }

    public function test_pool_and_held_views(): void
    {
        $ali = DB::table('t_account')->insertGetId($this->fill('t_account', ['fk_id_ent' => 1, 'first_name' => 'Ali', 'f_active' => 1]));
        Stock::receive(1, [['product' => $this->p, 'qty' => 5, 'location' => $this->wh, 'unit_cost' => 6, 'owner' => $ali, 'visibility' => 'PRIVATE']], 'GRN', 2);
        $pool = collect(Stock::pool(1));
        $this->assertSame([20, 80.0], [(int) $pool->firstWhere('visibility', 'POOL')->units, (float) $pool->firstWhere('visibility', 'POOL')->value]);
        $this->assertSame(['Ali', 5], [$pool->firstWhere('visibility', 'PRIVATE')->owner, (int) $pool->firstWhere('visibility', 'PRIVATE')->units]);
        $this->assertSame([], Stock::held(1));
        Stock::transfer(1, $this->p, 3, $this->wh, Stock::systemLocation(1, 'QUARANTINE'), 'Smells odd');
        $held = Stock::held(1);
        $this->assertSame([3, 'QUARANTINE', 12.0], [(int) $held[0]->qty, $held[0]->kind, (float) $held[0]->value]);
        $this->assertSame(22, Stock::available($this->p));
    }

    public function test_bin_generator_previews_creates_and_never_makes_a_bin_twice(): void
    {
        $pre = LocationGenerator::run(1, ['parent' => $this->wh, 'zone' => 'a', 'levels' => 2, 'positions' => 3], false);
        $this->assertSame(['A-A-01', 'A-A-02', 'A-A-03', 'A-B-01', 'A-B-02', 'A-B-03'], $pre['codes']);
        $this->assertSame(6, LocationGenerator::run(1, ['parent' => $this->wh, 'zone' => 'A', 'levels' => 2, 'positions' => 3], true)['created']);
        $again = LocationGenerator::run(1, ['parent' => $this->wh, 'zone' => 'A', 'levels' => 3, 'positions' => 3], true);
        $this->assertSame([3, 6, 3], [$again['created'], $again['skipped'], count($again['ids'])]);
        $bin = DB::table('cat_inventory_locations')->where('location_code', 'A-C-02')->first();
        $this->assertSame(['BIN', $this->wh, 'A-C-02'], [$bin->kind, (int) $bin->fk_id_parent, $bin->barcode_value]);
        $this->expectExceptionMessage('between 1 and 2,000');
        LocationGenerator::run(1, ['parent' => $this->wh, 'zone' => 'A', 'levels' => 100, 'positions' => 100], false);
    }

    public function test_an_opening_lot_gets_its_cost_and_stock_value_and_cost_of_sales_follow(): void
    {
        $f = fn ($t, $d) => DB::table($t)->insertGetId(\App\Services\RecordService::fillNotNull($t, $d));
        $p = $f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'OPEN-01', 'product_name_vendor' => 'Opening product', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']);
        $loc = $f('cat_inventory_locations', ['location_name' => 'O-1', 'kind' => 'BIN', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1]);
        $lot = \App\Services\StockService::receive(1, [['product' => $p, 'qty' => 10, 'location' => $loc, 'unit_cost' => 0]], 'OPENING', null, ['lot_source' => 'OPENING'])['lots'][0];
        $before = \App\Services\Ledger::balance(1, '1200');
        \App\Services\StockService::adjust(1, $p, $loc, -2, 'Broken');                       // 2 units leave at cost 0
        DB::table('fin_lot_consumption')->insert(\App\Services\RecordService::fillNotNull('fin_lot_consumption', ['lot_id' => $lot, 'sku' => 'OPEN-01', 'qty' => 3, 'unit_cost_gbp' => 0, 'consumed_on' => date('Y-m-d')]));   // 3 units count as sold
        try { \App\Services\StockService::setLotCost(1, $lot, 2.5, ''); $this->fail('reason'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('where this cost comes from', $e->getMessage()); }
        $x = \App\Services\StockService::setLotCost(1, $lot, 2.5, 'Supplier invoice 77');
        $this->assertSame([20.0, 7.5], [$x['stock_value_change'], $x['cost_of_sales_change']]);   // 8 on the shelf, 3 sold
        $this->assertSame([$before + 20.0, \App\Services\StockService::value(1)], [\App\Services\Ledger::balance(1, '1200'), \App\Services\Ledger::balance(1, '1200')]);
        $this->assertSame(2.5, (float) DB::table('fin_lot_consumption')->where('lot_id', $lot)->where('qty', 3)->value('unit_cost_gbp'));
        try { \App\Services\StockService::setLotCost(1, $lot, 2.5, 'again'); $this->fail('same'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('already the cost', $e->getMessage()); }
        \App\Services\StockService::setLotCost(1, $lot, 2.0, 'Corrected');
        $this->assertSame(\App\Services\StockService::value(1), \App\Services\Ledger::balance(1, '1200'));
        $po = \App\Services\StockService::receive(1, [['product' => $p, 'qty' => 1, 'location' => $loc, 'unit_cost' => 1]], 'GRN', 5)['lots'][0];
        try { \App\Services\StockService::setLotCost(1, $po, 3, 'x y z'); $this->fail('po lot'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('purchase order', $e->getMessage()); }
        $tb = \App\Services\Ledger::trialBalance(1); $this->assertSame($tb['debit'], $tb['credit']);
    }
}
