<?php

namespace Tests\Feature;

use App\Services\Perm;
use App\Services\PriceService;
use App\Services\RecordService;
use App\Services\SaleLines;
use App\Services\SalesService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_A_SALES §3, §5, §6: the line formula, expected cost, the state table, edit, carrier / packing, refund with nature, delete + bin, the list. */
class SaleLinesTest extends TestCase
{
    private int $shop; private int $mp; private int $product; private int $svc2; private int $svc3; private int $pack;

    private function fill(string $t, array $d): array { $m = new \ReflectionMethod(RecordService::class, 'fillNotNull'); return $m->invoke(null, $t, $d); }

    private function world(): void
    {
        $this->actingAs($this->owner);
        $this->mp = DB::table('cat_marketplaces')->insertGetId($this->fill('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'Ebay', 'marketplace_descr' => 'eBay', 'f_active' => 1]));
        $this->shop = DB::table('t_branches')->insertGetId($this->fill('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $this->mp, 'shop_no' => '101A', 'shop_name' => 'Pets', 'iso_branch' => 'P1', 'f_active' => 1, 'fulfilment_channel' => 'OTHER']));
        $car = DB::table('t_carriers')->insertGetId($this->fill('t_carriers', ['fk_id_ent' => 1, 'carrier_name' => 'RM', 'f_active' => 1]));
        $v = fn (float $rate, string $n) => DB::table('t_carrier_pkg_svc')->insertGetId(['pkg_svc_name' => "Small $n", 'fk_id_carrier' => $car, 'fk_id_car_package' => 0, 'fk_id_car_service' => 0, 'rate' => $rate]);
        $this->svc2 = $v(2.00, '48'); $this->svc3 = $v(3.10, '24');
        $this->pack = DB::table('t_packings')->insertGetId(['fk_id_ent' => 1, 'packing_name' => 'Bag', 'packing_rate' => 0.25, 'f_active' => 1]);
        $this->product = DB::table('t_product_vendors')->insertGetId($this->fill('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'BOWL-01', 'product_name_vendor' => 'Dog bowl', 'f_active' => 1, 'fk_id_car_pkg_svc' => $this->svc2, 'fk_id_packing' => $this->pack, 'raw_price' => 4, 'sale_price' => 9]));
    }

    /** One order line through the normal intake; returns its id. */
    private function order(string $no, float $rate, int $qty = 1, ?string $date = null, string $sku = 'BOWL-01'): int
    {
        return SalesService::intake($this->shop, [['order_no' => $no, 'd_order' => $date ?: date('Y-m-d'), 'sku' => $sku, 'qty' => $qty, 'sale_rate' => $rate, 'cust_name' => 'Buyer One']])['ids'][0];
    }

    public function test_one_line_formula_with_the_examples_of_the_rule(): void
    {
        $this->assertSame(['product_cost' => 6.0, 'postage' => 2.25, 'buy_amount' => 8.25, 'profit' => 4.74], SaleLines::calc(12.99, 6.00, 1, 2.00, 0.25));
        $this->assertSame(['product_cost' => 18.0, 'postage' => 2.25, 'buy_amount' => 20.25, 'profit' => 9.75], SaleLines::calc(30.00, 6.00, 3, 2.00, 0.25));
    }

    public function test_expected_cost_comes_from_the_price_period_of_the_order_date_then_older_sources(): void
    {
        $this->world(); $p = DB::table('t_product_vendors')->where('id', $this->product)->first();
        // no history at all → the product's own price, with today's carrier and packing; a task asks for the price history
        $c = SaleLines::expectedCost($p, $this->mp, '2026-07-10', 1); $this->assertSame(['product', 4.0, 2.0, 0.25], [$c['cost_source'], $c['raw'], $c['carrier_rate'], $c['packing_rate']]);
        $id = $this->order('O-0', 9.00, 1, '2026-07-10'); $this->assertSame('product', DB::table('t_sales')->where('id', $id)->value('cost_source'));
        $this->assertSame(1, DB::table('tasks')->where('rule_code', 'PRICE_HISTORY_MISSING')->whereNotIn('status', ['DONE', 'CANCELLED'])->count());
        // period A (raw 5.00 from 1 June) and period B (raw 5.50 from 15 July)
        PriceService::set($this->product, $this->mp, 5.00, 12.99, '2026-06-01'); PriceService::set($this->product, $this->mp, 5.50, 12.99, '2026-07-15');
        DB::table('t_product_inventory_change_log')->where('fk_product_id', $this->product)->where('from_date', '2026-06-01')->update(['old_price' => 11.00, 'old_raw_price' => 4.50]);
        $c = SaleLines::expectedCost($p, $this->mp, '2026-07-10', 1); $this->assertSame(['period', 5.0], [$c['cost_source'], $c['raw']]);
        $this->assertSame(5.5, SaleLines::expectedCost($p, $this->mp, '2026-07-20', 1)['raw']);
        $old = SaleLines::expectedCost($p, $this->mp, '2026-05-20', 1); $this->assertSame(['period_old', 4.5], [$old['cost_source'], $old['raw']]);        // before the first period: its OLD price
        // split: sale 12.99 − raw 5.00 = 7.99 to share; carrier = today's 2.00, the rest is packing
        $this->assertSame([2.0, 5.99], [$c['carrier_rate'], $c['packing_rate']]);
        // a period that stored its own split uses it
        DB::table('t_product_inventory_change_log')->where('fk_product_id', $this->product)->where('from_date', '2026-07-15')->update(['carrier_rate' => 3.10, 'packing_rate' => 0.25, 'fk_id_car_pkg_svc' => $this->svc3]);
        $b = SaleLines::expectedCost($p, $this->mp, '2026-07-20', 1); $this->assertSame([3.1, 0.25, $this->svc3], [$b['carrier_rate'], $b['packing_rate'], $b['fk_id_car_pkg_svc']]);
        // intake stores everything on the line and uses the one formula
        $id = $this->order('O-1', 12.99, 1, '2026-07-20'); $s = DB::table('t_sales')->where('id', $id)->first();
        $this->assertSame(['period', '5.50', '3.10', '0.25', '5.50', '8.85', '4.14'], [$s->cost_source, $s->raw_price, $s->carrier_rate, $s->packing_rate, $s->buy_rate, $s->buy_amount, $s->profit]);
        // sold at another price than listed → a task, the raw is not changed
        $id2 = $this->order('O-2', 10.00, 1, '2026-07-21'); $this->assertSame('5.50', DB::table('t_sales')->where('id', $id2)->value('raw_price'));
        $this->assertStringContainsString('Sold at 10.00, listed at 12.99', (string) DB::table('tasks')->where('rule_code', 'SAL_PRICE_MISMATCH')->value('title'));
    }

    public function test_a_variation_child_reads_its_own_marketplace_price_and_shipping(): void
    {
        $this->world(); $row = (array) DB::table('t_product_vendors')->where('id', $this->product)->first(); unset($row['id']); foreach (array_keys($row) as $k) { if (strtolower($k) === 'sku_vendor') $row[$k] = 'BOWL-01-RED'; if (strtolower($k) === 'f_parent') $row[$k] = 3; if (strtolower($k) === 'f_id_parent') $row[$k] = $this->product; }
        $child = DB::table('t_product_vendors')->insertGetId($row); $c = DB::table('t_product_vendors')->where('id', $child)->first();
        DB::table('t_product_marketplace_prices')->insert($this->fill('t_product_marketplace_prices', ['fk_id_product' => $child, 'fk_id_marketplace' => $this->mp, 'raw_price' => 9.99, 'sale_price' => 19.99]));      // a row in the parent-type table must not be read for a child
        DB::table('t_variation_marketplace_prices')->insert($this->fill('t_variation_marketplace_prices', ['fk_id_variation' => $child, 'fk_id_marketplace' => $this->mp, 'raw_price' => 3.30, 'sale_price' => 7.00]));
        $x = SaleLines::expectedCost($c, $this->mp, '2026-07-10', 1); $this->assertSame(['current', 3.3, 7.0], [$x['cost_source'], $x['raw'], $x['sale']]);
        $p = DB::table('t_product_vendors')->where('id', $this->product)->first(); $this->assertSame('product', SaleLines::expectedCost($p, $this->mp, '2026-07-10', 1)['cost_source']);      // the parent itself is not affected
    }

    /** A Pending own-stock line: total 12.99, raw 6.00, carrier 2.00, packing 0.25 (the figures of the rule's tests). */
    private function line(int $qty = 1, float $rate = 12.99): int
    {
        $this->world(); $id = $this->order('ORD-1', $rate, $qty);
        $x = SaleLines::calc(round($qty * $rate, 2), 6.00, $qty, 2.00, 0.25);
        DB::table('t_sales')->where('id', $id)->update(['raw_price' => 6.00, 'fk_id_car_pkg_svc' => $this->svc2, 'carrier_rate' => 2.00, 'fk_id_packing' => $this->pack, 'packing_rate' => 0.25, 'buy_rate' => $x['product_cost'], 'buy_amount' => $x['buy_amount'], 'profit' => $x['profit']]);
        return $id;
    }

    public function test_edit_sale_recalculates_logs_every_field_and_keeps_the_rules_messages(): void
    {
        $id = $this->line(1, 10.00);
        $msg = fn (array $d) => $this->postJson("/api/sales/$id/edit", $d)->assertStatus(422)->json('message');
        $this->assertSame('Order Number is required.', $msg(['order_no' => ' ']));
        $this->assertSame('Order Number can be at most 100 characters.', $msg(['order_no' => str_repeat('x', 101)]));
        $this->assertSame('Quantity must be a whole number, 1 or more.', $msg(['qty' => '0'])); $this->assertSame('Quantity must be a whole number, 1 or more.', $msg(['qty' => '1.5']));
        $this->assertSame('Sale Rate must be a number with up to 2 decimals, e.g. 8 or 8.50.', $msg(['sale_rate' => '8.555']));
        $this->assertSame('Total Amount must be a number with up to 2 decimals, e.g. 16 or 16.50.', $msg(['total_amount' => 'abc']));
        $this->assertSame('Nothing changed.', $msg([]));
        $other = $this->order('ORD-2', 5.00);
        $this->assertSame('Order ORD-2 with SKU BOWL-01 already exists in this shop (' . date('M Y') . ' import).', $msg(['order_no' => 'ORD-2']));
        // preview changes nothing
        $p = $this->postJson("/api/sales/$id/edit", ['qty' => 2, 'preview' => true])->assertOk()->json('preview');
        $this->assertSame([10, 20], [$p['now']['total'], $p['after']['total']]); $this->assertSame(1, (int) DB::table('t_sales')->where('id', $id)->value('qty'));
        // qty 1 → 2 at rate 10.00 → total 20.00, buy and profit recalculated, 5 log rows
        $r = $this->postJson("/api/sales/$id/edit", ['qty' => 2])->assertOk()->json();
        $this->assertSame('Sale updated and the line recalculated.', $r['message']); $this->assertSame(5, $r['log_rows']);
        $s = DB::table('t_sales')->where('id', $id)->first();
        $this->assertSame(['2', '20.00', '12.00', '14.25', '5.75', '1'], [(string) $s->qty, $s->total_amount, $s->buy_rate, $s->buy_amount, $s->profit, (string) $s->f_edited]);
        $this->assertSame(['Buy Amount (recalculated)', 'Buy Rate (recalculated)', 'Profit (recalculated)', 'Quantity', 'Total Amount'], DB::table('t_sale_change_log')->where('fk_id_sale', $id)->orderBy('field_label')->pluck('field_label')->all());
        $this->assertCount(5, $this->getJson("/api/sales/$id/log")->assertOk()->json('rows'));
        // a total typed by hand is kept; a line that is no longer Pending cannot be changed
        $this->postJson("/api/sales/$id/edit", ['total_amount' => '19.00'])->assertOk(); $this->assertSame('19.00', DB::table('t_sales')->where('id', $id)->value('total_amount'));
        DB::table('t_sales')->where('id', $other)->update(['status' => 'Confirmed', 'f_locked' => 1]);
        $this->assertSame('Only Pending lines can be changed. This line is Confirmed.', $this->postJson("/api/sales/$other/edit", ['qty' => 3])->assertStatus(422)->json('message'));
    }

    public function test_edit_carrier_and_packing_with_preview_and_the_example_of_the_rule(): void
    {
        $id = $this->line();
        $o = $this->getJson("/api/sales/$id/carrier")->assertOk()->json();
        $this->assertSame('RM-Small 48', collect($o['services'])->firstWhere('id', $this->svc2)['label']); $this->assertSame('No packing', $o['packings'][0]['label']); $this->assertSame('Bag-£0.25', $o['packings'][1]['label']); $this->assertSame($this->svc2, $o['cheapest']);
        $msg = fn (array $d) => $this->postJson("/api/sales/$id/carrier", $d)->assertStatus(422)->json('message');
        $this->assertSame('Select a carrier service.', $msg(['fk_id_car_pkg_svc' => 0]));
        $this->assertSame('That carrier service was not found.', $msg(['fk_id_car_pkg_svc' => 999999]));
        $this->assertSame('That packing was not found.', $msg(['fk_id_car_pkg_svc' => $this->svc3, 'fk_id_packing' => 999999]));
        $this->assertSame('Nothing changed: this line already uses that carrier and packing.', $msg(['fk_id_car_pkg_svc' => $this->svc2, 'fk_id_packing' => $this->pack]));
        $p = $this->postJson("/api/sales/$id/carrier", ['fk_id_car_pkg_svc' => $this->svc3, 'fk_id_packing' => $this->pack, 'preview' => true])->assertOk()->json('preview.rows');
        $this->assertSame(['Profit', '4.74', '3.64', true], $p[3]); $this->assertFalse($p[1][3]);
        // carrier 2.00 → 3.10: profit 4.74 → 3.64
        $this->assertSame('Carrier/packing updated and the line recalculated.', $this->postJson("/api/sales/$id/carrier", ['fk_id_car_pkg_svc' => $this->svc3, 'fk_id_packing' => $this->pack])->assertOk()->json('message'));
        $s = DB::table('t_sales')->where('id', $id)->first(); $this->assertSame(['3.10', '9.35', '3.64', '6.00'], [$s->carrier_rate, $s->buy_amount, $s->profit, $s->raw_price]);
        $log = DB::table('t_sale_change_log')->where('fk_id_sale', $id)->pluck('new_value', 'field_label')->all();
        $this->assertSame('RM-Small 24 (£3.10)', $log['Carrier']); $this->assertArrayHasKey('Buy Amount (recalculated)', $log); $this->assertArrayHasKey('Profit (recalculated)', $log);
        // "No packing" = 0; a not-matched line has no carrier of ours
        $this->postJson("/api/sales/$id/carrier", ['fk_id_car_pkg_svc' => $this->svc3, 'fk_id_packing' => 0])->assertOk(); $this->assertSame('3.89', DB::table('t_sales')->where('id', $id)->value('profit'));
        $nm = $this->order('ORD-9', 5, 1, null, 'UNKNOWN-SKU');
        $this->assertSame('Only active inventory lines have a carrier and packing of ours', $this->postJson("/api/sales/$nm/carrier", ['fk_id_car_pkg_svc' => $this->svc2])->assertStatus(422)->json('message'));
    }

    public function test_refund_of_a_pending_line_with_the_nature_of_the_loss(): void
    {
        $id = $this->line();
        $i = $this->getJson("/api/sales/$id/refund")->assertOk()->json();
        $this->assertSame("This line's costs: product £6.00, carrier £2.00, packing £0.25.", $i['hint']); $this->assertCount(7, $i['natures']);
        $this->assertSame([8, 'Product + Carrier Loss'], [$i['natures']['Product_Carrier']['loss'], $i['natures']['Product_Carrier']['label']]); $this->assertSame(8.25, $i['natures']['Product_Carrier_Packing']['loss']);
        $msg = fn (array $d) => $this->postJson("/api/sales/$id/refund", $d)->assertStatus(422)->json('message');
        $ok = ['sale_rate' => '0', 'total_amount' => '0', 'nature' => 'Product_Carrier', 'loss_amount' => '8.00', 'reason' => 'Arrived broken'];
        $this->assertSame('Sale Rate must be a number with up to 2 decimals (0 for a full refund).', $msg(['sale_rate' => 'x'] + $ok));
        $this->assertSame("Sale Rate can't be more than the current 12.99.", $msg(['sale_rate' => '13.00', 'total_amount' => '13.00'] + $ok));
        $this->assertSame("Total Amount can't be more than the current 12.99.", $msg(['sale_rate' => '12.99', 'total_amount' => '20'] + $ok));
        $this->assertSame('Choose the nature of the loss.', $msg(['nature' => ''] + $ok));
        $this->assertSame('Loss Amount must be a number with up to 2 decimals, e.g. 4.57.', $msg(['loss_amount' => '4.567'] + $ok));
        $this->assertSame('Enter the refund reason.', $msg(['reason' => ' '] + $ok));
        $this->assertSame('Refund reason can be at most 2000 characters.', $msg(['reason' => str_repeat('a', 2001)] + $ok));
        // refund to 0 with nature Product_Carrier, loss 8.00 → profit −8.00, status Refunded
        $r = $this->postJson("/api/sales/$id/refund", $ok)->assertOk()->json();
        $this->assertSame('Sale refunded. Profit is now -8.00.', $r['message']); $this->assertFalse($r['return_expected']);
        $s = DB::table('t_sales')->where('id', $id)->first();
        $this->assertSame(['Refunded', '-8.00', '0.00', 'Product_Carrier', '8.00', 'Arrived broken', 'Owner', '1'], [$s->status, $s->profit, $s->total_amount, $s->refund_loss_nature, $s->refund_loss_amount, $s->refund_reason, $s->refunded_by_name, (string) $s->f_edited]);
        $this->assertSame('Refund Sale', DB::table('t_sale_change_log')->where('fk_id_sale', $id)->value('action'));
        $c = DB::table('t_sale_charges')->where('fk_id_sale', $id)->first(); $this->assertSame(['REFUND_FULL', '12.99'], [$c->kind, $c->amount]);
        $this->assertSame("This line is no longer Pending, so it can't be refunded.", $msg($ok));
        // view mode, and the state table: a Refunded line shows "View Refund" and no Edit
        $v = $this->getJson("/api/sales/$id/refund")->json('view'); $this->assertSame('Refunded by Owner on ' . date('d.m.Y'), $v['text']); $this->assertSame('Product + Carrier Loss', $v['nature']); $this->assertSame(-8, $v['profit']);
        $row = collect($this->getJson('/api/sales?marketplace=' . $this->mp)->assertOk()->json('rows'))->firstWhere('id', $id);
        $this->assertTrue($row['actions']['view_refund']['ok']); $this->assertFalse($row['actions']['edit']['ok']); $this->assertSame('Only Pending lines can be changed', $row['actions']['edit']['why']); $this->assertSame(8, $row['refund']['loss']);
        // a full refund whose loss is not the product: the item is expected back (task), and a part refund is kind PARTIAL
        $b = $this->order('ORD-B', 12.99); $r = $this->postJson("/api/sales/$b/refund", ['nature' => 'Carrier', 'loss_amount' => '2.00'] + $ok)->assertOk()->json(); $this->assertTrue($r['return_expected']);
        $this->assertSame(1, DB::table('tasks')->where('rule_code', 'SAL_RETURN_EXPECTED')->count());
        $c2 = $this->order('ORD-C', 12.99); $this->postJson("/api/sales/$c2/refund", ['sale_rate' => '5.00', 'total_amount' => '5.00', 'nature' => 'Carrier', 'loss_amount' => '2.00', 'reason' => 'late'])->assertOk();
        $this->assertSame('REFUND_PARTIAL', DB::table('t_sale_charges')->where('fk_id_sale', $c2)->value('kind'));
        // which refunded lines take stock at Confirm (R-A_SALES-33)
        $t = fn (int $x) => SaleLines::takesStock(DB::table('t_sales')->where('id', $x)->first());
        $this->assertTrue($t($id));      // total 0, nature with Product → the item is lost
        $this->assertFalse($t($b));      // total 0, nature Carrier → the item came back
        $this->assertTrue($t($c2));      // part refund → deducted
    }

    public function test_delete_a_line_or_a_month_goes_to_the_bin_and_can_be_put_back(): void
    {
        $id = $this->line(); $imp = DB::table('t_sale_imports')->insertGetId($this->fill('t_sale_imports', ['fk_id_ent' => 1, 'fk_id_branch' => $this->shop, 'fk_id_marketplace' => $this->mp, 'file_name' => 'jul.csv', 'period_month' => (int) date('n'), 'period_year' => (int) date('Y'), 'total_rows' => 412, 'saved_rows' => 412, 'status' => 'Pending']));
        DB::table('t_sales')->where('id', $id)->update(['fk_id_import' => $imp]);
        $conf = $this->order('ORD-K', 5); DB::table('t_sales')->where('id', $conf)->update(['status' => 'Confirmed', 'f_locked' => 1]);
        $this->assertSame("This line is confirmed and saved permanently, so it can't be deleted.", $this->postJson("/api/sales/$conf/delete")->assertStatus(422)->json('message'));
        $r = $this->postJson("/api/sales/$id/delete")->assertOk()->json(); $this->assertSame('Sale line deleted.', $r['message']);
        $this->assertSame(411, (int) DB::table('t_sale_imports')->where('id', $imp)->value('saved_rows')); $this->assertFalse(DB::table('t_sales')->where('id', $id)->exists());
        $this->assertSame(1, DB::table('mo_recycle_bin')->where('table_name', 't_sales')->where('row_id', $id)->count());
        $this->assertSame(1, $this->postJson('/api/sales/restore', ['undo' => $r['undo']])->assertOk()->json('back'));
        $this->assertSame('Pending', DB::table('t_sales')->where('id', $id)->value('status')); $this->assertSame(412, (int) DB::table('t_sale_imports')->where('id', $imp)->value('saved_rows'));
        $this->postJson('/api/sales/restore', ['undo' => $r['undo']])->assertStatus(422);
        // a whole month: only while nothing of it is confirmed
        $month = date('Y-m');
        $this->assertSame("This month is confirmed and saved permanently, so it can't be deleted.", $this->postJson('/api/sales/month/delete', ['shop' => $this->shop, 'month' => $month])->assertStatus(422)->json('message'));
        DB::table('t_sales')->where('id', $conf)->update(['status' => 'Pending', 'f_locked' => 0]);
        $info = $this->getJson("/api/sales/month?shop={$this->shop}&month=$month")->assertOk()->json();
        $this->assertSame(2, $info['lines']); $this->assertStringStartsWith('All 2 sale line(s) of 101A - ' . date('F Y'), $info['text']);
        $d = $this->postJson('/api/sales/month/delete', ['shop' => $this->shop, 'month' => $month])->assertOk()->json(); $this->assertSame('Sales for ' . date('F Y') . ' deleted.', $d['message']);
        $this->assertSame(0, DB::table('t_sales')->where('fk_id_branch', $this->shop)->count());
        $this->assertSame(2, $this->postJson('/api/sales/restore', ['undo' => $d['undo']])->assertOk()->json('back'));            // the month can be put back …
        $this->postJson('/api/sales/month/delete', ['shop' => $this->shop, 'month' => $month])->assertOk(); $this->order('ORD-1', 12.99);      // … or imported again
        $this->assertSame(2, DB::table('t_sales')->where('fk_id_branch', $this->shop)->count() + 1);
        // rights: a VA of another shop is refused; only TL and above delete a month
        $va = $this->makeUser('VA'); $o = DB::table('t_branches')->insertGetId($this->fill('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $this->mp, 'shop_no' => '202B', 'shop_name' => 'Other', 'iso_branch' => 'O1', 'f_active' => 1]));
        Perm::setScope($va->id, 'SHOP', [$o], $this->owner->id); $x = DB::table('t_sales')->where('fk_id_branch', $this->shop)->value('id');
        $this->actingAs($va); $this->postJson("/api/sales/$x/edit", ['qty' => 3])->assertStatus(404); $this->postJson("/api/sales/$x/delete")->assertStatus(404);
        $this->postJson('/api/sales/month/delete', ['shop' => $o, 'month' => $month])->assertStatus(403);
        $this->assertSame(1, SaleLines::purgeBin(-1) > 0 ? 1 : 0);
    }

    public function test_the_list_needs_a_marketplace_filters_counts_periods_and_remembers_the_scope(): void
    {
        $this->line(); $this->order('ORD-L', 1.00); $nm = $this->order('ORD-N', 5.00, 1, null, 'UNKNOWN'); $this->order('ORD-OLD', 9.00, 1, date('Y-m-d', strtotime('first day of last month')));
        $this->assertSame('Select a Marketplace (and a Shop, or all shops) to show the sales.', $this->getJson('/api/sales')->assertOk()->json('need'));
        $d = $this->getJson('/api/sales?marketplace=' . $this->mp)->assertOk()->json();
        $this->assertSame(3, $d['total']); $this->assertSame(50, $d['per_page']); $this->assertSame([20, 50, 100, 200], $d['page_sizes']);                 // default = the current month, 50 a page
        $this->assertSame(['ORD-N', 'ORD-L', 'ORD-1'], array_column($d['rows'], 'order_no'));                                                              // newest first
        $this->assertSame(1, $d['counts']['nm']); $this->assertGreaterThanOrEqual(1, $d['counts']['loss']); $this->assertSame(18.99, $d['totals']['sale']);
        $ids = fn (string $q) => array_column($this->getJson('/api/sales?marketplace=' . $this->mp . '&' . $q)->json('rows'), 'order_no');
        $this->assertSame(['ORD-N'], $ids('type=nm')); $this->assertSame(['ORD-L', 'ORD-1'], $ids('type=inventory')); $this->assertContains('ORD-L', $ids('show=loss')); $this->assertNotContains('ORD-1', $ids('show=loss'));
        $this->assertSame(['ORD-1'], $ids('q=ORD-1')); $this->assertSame(['ORD-N', 'ORD-L', 'ORD-1'], $ids('q=Buyer')); $this->assertSame([], $ids('status=Refunded'));
        $carrier = (int) DB::table('t_carrier_pkg_svc')->where('id', $this->svc2)->value('fk_id_carrier');
        $this->assertSame(['ORD-L', 'ORD-1'], $ids("carrier=car:$carrier")); $this->assertSame([], $ids('carrier=svc:' . $this->svc3));                    // every service of the carrier / one preset
        $this->assertSame(['ORD-OLD'], $ids('from=' . date('Y-m-01', strtotime('first day of last month')) . '&to=' . date('Y-m-t', strtotime('first day of last month')) . '&remember=0'));
        $row = collect($d['rows'])->firstWhere('order_no', 'ORD-N'); $this->assertSame(['NM', 'Not matched'], [$row['kind'], $row['visibility']]); $this->assertFalse($row['actions']['carrier']['ok']);
        $own = collect($d['rows'])->firstWhere('order_no', 'ORD-1'); $this->assertSame(['OWN', 4.74, 'RM-Small 48', 'Bag'], [$own['kind'], $own['profit'], $own['carrier_name'], $own['packing_name']]);
        // the six period chips are whole calendar months
        $p = SaleLines::periods('2026-11-06'); $this->assertSame(['2026-09-01', '2026-09-30', 'Sep-2026'], [$p[2]['from'], $p[2]['to'], $p[2]['label']]); $this->assertSame('Current Month (Nov-2026)', $p[0]['label']); $this->assertSame('Prev Month (Oct-2026)', $p[1]['label']); $this->assertCount(6, $p);
        $this->assertSame('2026-02-28', SaleLines::periods('2026-03-31')[1]['to']);                                                                        // the 31st does not skip a month
        // the scope is remembered on the server for the person (not for another), and only clean values are kept
        $this->getJson('/api/sales?marketplace=' . $this->mp . '&shop=' . $this->shop . '&from=2026-07-01&to=2026-07-31')->assertOk();
        $this->assertSame(['marketplace' => (string) $this->mp, 'shop' => (string) $this->shop, 'from' => '2026-07-01', 'to' => '2026-07-31'], $this->getJson('/api/sales')->json('scope'));
        $mgr = $this->makeUser('MANAGER'); $this->assertNull($this->actingAs($mgr)->getJson('/api/sales')->json('scope'));
        // a role without the money right gets no cost columns
        $wh = $this->makeUser('WAREHOUSE'); $r = $this->actingAs($wh)->getJson('/api/sales?marketplace=' . $this->mp)->assertOk()->json();
        $this->assertArrayNotHasKey('profit', $r['rows'][0]); $this->assertArrayNotHasKey('profit', $r['totals']);
    }
}
