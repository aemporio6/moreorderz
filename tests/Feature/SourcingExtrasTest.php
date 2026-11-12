<?php

namespace Tests\Feature;

use App\Services\BuyingService as Buy;
use App\Services\Context;
use App\Services\Fx;
use App\Services\Ledger;
use App\Services\PriceService;
use App\Services\RecordService;
use App\Services\SalesService as Sales;
use App\Services\SetupWizard;
use App\Services\SourcingExtras as X;
use App\Services\StockService as Stock;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Round 2 part 3b: samples, quality check, customs, quick buy, setup wizard. */
class SourcingExtrasTest extends TestCase
{
    private int $wh; private int $bank; private int $sup; private int $p; private int $cycle; private int $shopA; private int $shopB;

    protected function setUp(): void
    {
        parent::setUp();
        $f = fn ($t, $d) => DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
        $this->actingAs($this->owner); Context::set(1, $this->owner->id); Storage::fake('local');
        $this->wh = $f('cat_inventory_locations', ['location_name' => 'W1-A-1', 'kind' => 'BIN', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1, 'pick_seq' => 1]);
        $this->bank = DB::table('fin_bank_accounts')->insertGetId(['company_id' => 1, 'bank_name' => 'Test Bank', 'account_label' => 'Main GBP', 'currency' => 'GBP', 'is_active' => 1]);
        Fx::set('CNY', '2026-08-01', 0.11); Fx::set('USD', '2026-08-01', 0.80);
        $this->sup = $f('t_src_suppliers', ['fk_id_ent' => 1, 'supplier_name' => 'Shenzhen Petware', 'f_active' => 1]);
        $this->p = $f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'BOWL-01', 'product_name_vendor' => 'Dog bowl', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']);
        $mp = $f('cat_marketplaces', ['fk_id_ent' => 1]);
        [$this->shopA, $this->shopB] = array_map(fn ($n) => $f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => $n, 'iso_branch' => $n, 'shop_name' => "Shop $n", 'f_active' => 1, 'fk_id_main_location' => $this->wh, 'fulfilment_channel' => 'SELF']), ['101A', '102B']);
        $this->cycle = $f('t_src_cycles', ['fk_id_ent' => 1, 'cycle_code' => 'SC-T-1', 'sourcing_date' => '2026-08-01', 'product_name' => 'Dog bowl', 'status' => 'Open', 'f_active' => 1, 'cycle_type' => 'FULL']);
        $f('t_src_cycle_products', ['fk_id_ent' => 1, 'fk_id_cycle' => $this->cycle, 'fk_id_product' => $this->p, 'product_name' => 'Dog bowl', 'target_qty' => 100, 'f_active' => 1]);
        PriceService::set($this->p, $mp, 8, 10.00, '2026-01-01');
    }

    private function po(int $qty = 100, float $price = 5.00): int
    {
        $po = Buy::createPo(['cycle' => $this->cycle, 'supplier' => $this->sup, 'currency' => 'USD', 'date' => '2026-08-01', 'lines' => [['product' => $this->p, 'qty' => $qty, 'unit_price' => $price]]]);
        Buy::confirmPo($po); return $po;
    }

    public function test_sample_cost_in_its_own_currency_is_one_expense_shared_by_the_shops_90_day_sales(): void
    {
        // 90-day sales: shop A 60, shop B 40
        Stock::receive(1, [['product' => $this->p, 'qty' => 20, 'location' => $this->wh, 'unit_cost' => 1.00]], 'GRN', 1);
        $d = date('Y-m-d', strtotime('-10 day'));
        Sales::intake($this->shopA, [['order_no' => 'A1', 'd_order' => $d, 'sku' => 'BOWL-01', 'qty' => 6, 'sale_rate' => 10]]); Sales::intake($this->shopB, [['order_no' => 'B1', 'd_order' => $d, 'sku' => 'BOWL-01', 'qty' => 4, 'sale_rate' => 10]]);
        $this->assertSame([$this->shopA => 0.6, $this->shopB => 0.4], X::shopShares(1, $this->p));
        $id = X::saveSample(1, ['cycle' => $this->cycle, 'sample_date' => '2026-08-01', 'sample_cost' => 700, 'shipping_cost' => 140, 'currency' => 'CNY', 'sample_qty' => 2]);
        $s = DB::table('t_src_samples')->where('id', $id)->first();
        $this->assertSame(['92.40', 'PENDING', 0], [$s->base_amount, $s->approval_status, (int) $s->fk_id_supplier], 'CNY 700 + 140 at 0.11 = £92.40; no PO or quote yet, so no supplier');
        $this->po(); $this->assertSame($this->sup, (int) DB::table('t_src_samples')->where('id', X::saveSample(1, ['cycle' => $this->cycle, 'sample_date' => '2026-08-02']))->value('fk_id_supplier'), 'with a PO the supplier comes from the cycle');
        try { X::decideSample(1, $id, false); $this->fail('reason'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('reason', $e->getMessage()); }
        X::decideSample(1, $id, true);
        $fin = $this->makeUser('FINANCE'); $src = $this->makeUser('SOURCING');
        $this->actingAs($src)->postJson("/api/samples/$id/book", ['bank' => $this->bank])->assertStatus(422);
        $x = $this->actingAs($fin)->postJson("/api/samples/$id/book", ['bank' => $this->bank])->assertOk()->json();
        $this->assertSame([92.4, [55.44, 36.96]], [(float) $x['total'], array_map('floatval', array_column($x['parts'], 'amount'))]);
        $this->assertSame([92.4, -92.4], [Ledger::balance(1, '6000'), Ledger::balance(1, '1000', null, $this->bank)]);
        $this->actingAs($fin)->postJson("/api/samples/$id/book", ['bank' => $this->bank])->assertStatus(422)->assertJsonFragment(['message' => 'The cost of sample ' . $s->sample_code . ' is already booked.']);
        $this->actingAs($this->owner);
        try { X::saveSample(1, ['id' => $id, 'cycle' => $this->cycle, 'sample_date' => '2026-08-01', 'sample_cost' => 1]); $this->fail('booked'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('already booked', $e->getMessage()); }
        // the sample never touches the goods' cost: stock and goods on the way are only the 20 units received above
        $this->assertSame([20.0, -20.0], [Ledger::balance(1, '1200'), Ledger::balance(1, '1210')]);
    }

    public function test_quality_check_needs_photo_and_note_for_a_failed_check_and_a_passed_reinspection_closes_the_task(): void
    {
        $po = $this->po();
        $wh = $this->makeUser('WAREHOUSE'); $this->actingAs($wh); Context::set(1, $wh->id);
        $checks = [['text' => 'Colour as sample', 'result' => 'PASS'], ['text' => 'No scratches', 'result' => 'FAIL', 'note' => '4 scratched'], ['text' => 'Carton label', 'result' => 'NA'], ['text' => '', 'result' => 'PASS']];
        $base = ['po' => $po, 'inspection_date' => '2026-08-10', 'qty_inspected' => 50, 'qty_passed' => 46, 'qty_failed' => 4, 'inspector' => 'QC agent'];
        try { X::saveQc(1, $base + ['checks' => $checks]); $this->fail('photo'); } catch (\InvalidArgumentException $e) { $this->assertSame('Add a photo for each failed check.', $e->getMessage()); }
        try { X::saveQc(1, ['qty_passed' => 50, 'qty_failed' => 4] + $base + ['checks' => []]); $this->fail('qty'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('cannot be more than', $e->getMessage()); }
        $photo = $this->postJson('/api/qc/photo', ['file' => UploadedFile::fake()->image('scratch.jpg', 40, 40)])->assertOk()->json('id');
        $this->postJson('/api/qc/photo', ['file' => UploadedFile::fake()->create('virus.exe', 3)])->assertStatus(422);
        $checks[1]['photo'] = $photo; $checks2 = $checks; unset($checks2[1]['note']);
        try { X::saveQc(1, $base + ['checks' => $checks2]); $this->fail('note'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('note', $e->getMessage()); }
        $q = $this->postJson('/api/qc', $base + ['checks' => $checks])->assertOk()->json();
        $this->assertSame('FAILED', $q['result']);
        $this->assertSame(3, DB::table('t_src_qc_checks')->where('fk_id_qc', $q['id'])->count(), 'the empty line is left out');
        $this->assertSame('8.0000', DB::table('t_src_qc')->where('id', $q['id'])->value('defect_rate'));
        $open = fn () => DB::table('tasks')->where('dedupe_key', 'SRC_QC_FAILED:' . $q['code'])->whereNotIn('status', ['DONE', 'APPROVED', 'CANCELLED'])->count();
        $this->assertSame(1, $open());
        $this->get("/api/qc/photo/$photo")->assertOk();
        // the next QC of the same product starts from this checklist
        $this->assertSame(['Colour as sample', 'No scratches', 'Carton label'], X::qcTemplate(1, null) ?: DB::table('t_src_qc_checks')->where('fk_id_qc', $q['id'])->orderBy('sort_no')->pluck('check_text')->all());
        // passing despite a failed line needs a reason
        try { X::saveQc(1, $base + ['checks' => $checks, 'override_pass' => true]); $this->fail('override'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('write the reason', $e->getMessage()); }
        // re-inspection all PASS → PASSED and the task closes
        $r = X::saveQc(1, ['po' => $po, 'inspection_date' => '2026-08-12', 'qty_inspected' => 50, 'qty_passed' => 50, 'qty_failed' => 0, 're_inspection_of' => $q['id'], 'checks' => [['text' => 'No scratches', 'result' => 'PASS']]]);
        $this->assertSame(['PASSED', 0], [$r['result'], $open()]);
        $this->assertSame('PASSED', X::qcOfPos([$po])[$po]['result'], 'the latest QC of the PO is what the lots show');
        $this->actingAs($this->makeUser('VA'))->postJson('/api/qc', $base + ['checks' => []])->assertStatus(422);
    }

    public function test_customs_duty_goes_to_goods_on_the_way_import_vat_apart_and_a_paid_entry_is_locked(): void
    {
        $po = $this->po(); $ship = Buy::createShipment(['po' => $po, 'eta' => '2026-08-18']);
        try { X::saveCustoms(1, ['shipment' => $ship, 'customs_value' => 100, 'duty_rate' => 150]); $this->fail('rate'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('between 0 and 100', $e->getMessage()); }
        $id = X::saveCustoms(1, ['shipment' => $ship, 'customs_value' => 400, 'currency' => 'GBP', 'duty_rate' => 4, 'import_vat' => 80, 'declaration_number' => 'DEC-1', 'hs_code' => '4202']);
        $c = DB::table('t_src_customs')->where('id', $id)->first();
        $this->assertSame(['16.0000', '16.00', 'UNPAID'], [$c->duty_amount, $c->base_amount, $c->pay_status], 'duty = 400 × 4 %');
        $typed = X::saveCustoms(1, ['shipment' => $ship, 'customs_value' => 400, 'currency' => 'GBP', 'duty_rate' => 4, 'duty_amount' => 17.25]);
        $this->assertSame('17.2500', DB::table('t_src_customs')->where('id', $typed)->value('duty_amount'), 'the amount on the papers wins');
        $this->assertSame(33.25, X::dutyPaidForPo(1, $po));
        $src = $this->makeUser('SOURCING');
        $this->actingAs($src)->postJson("/api/customs/$id/pay", ['bank' => $this->bank, 'date' => '2026-08-19'])->assertStatus(422);
        $x = $this->actingAs($this->owner)->postJson("/api/customs/$id/pay", ['bank' => $this->bank, 'date' => '2026-08-19'])->assertOk()->json();
        $this->assertSame([16.0, 80.0], [(float) $x['duty_base'], (float) $x['vat_base']]);
        $this->assertSame([16.0, 80.0, -96.0], [Ledger::balance(1, '1210'), Ledger::balance(1, '1400'), Ledger::balance(1, '1000', null, $this->bank)]);
        $this->assertSame(['PAID', 'CLEARED'], [DB::table('t_src_customs')->where('id', $id)->value('pay_status'), DB::table('t_src_customs')->where('id', $id)->value('status')]);
        $this->actingAs($this->owner)->postJson("/api/customs/$id/pay", ['bank' => $this->bank, 'date' => '2026-08-19'])->assertStatus(422);
        try { X::saveCustoms(1, ['id' => $id, 'shipment' => $ship, 'customs_value' => 1]); $this->fail('locked'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('paid and cannot be changed', $e->getMessage()); }
        $this->assertTrue(Ledger::trialBalance(1)['balanced']);
    }

    public function test_quick_buy_orders_receives_and_pays_in_one_save_and_runs_through_the_normal_books(): void
    {
        $other = DB::table('t_product_vendors')->insertGetId(RecordService::fillNotNull('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'LEAD-02', 'product_name_vendor' => 'Dog lead', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']));
        $body = ['supplier' => $this->sup, 'location' => $this->wh, 'date' => date('Y-m-d'), 'reference' => 'Receipt 778', 'lines' => [['product' => $this->p, 'qty' => 10, 'unit_price' => 2.50], ['sku' => 'LEAD-02', 'qty' => 4, 'unit_price' => 3.00]]];
        $this->postJson('/api/quick-buy', ['date' => date('Y-m-d', strtotime('+2 day'))] + $body)->assertStatus(422)->assertJsonFragment(['message' => 'A quick buy is a purchase that already happened – the date cannot be in the future.']);
        $this->postJson('/api/quick-buy', ['lines' => [['sku' => 'NOPE', 'qty' => 1, 'unit_price' => 1]]] + $body)->assertStatus(422);
        $src = $this->makeUser('SOURCING');
        $this->actingAs($src)->postJson('/api/quick-buy', $body + ['bank' => $this->bank])->assertStatus(422)->assertJsonFragment(['message' => 'Only the Owner or Finance pays from the bank. Save the quick buy without a bank account; Finance pays it from the purchase order.']);
        $unpaid = $this->actingAs($src)->postJson('/api/quick-buy', $body)->assertOk()->json();
        $this->assertNull($unpaid['paid']); $this->assertStringContainsString('not paid yet', $unpaid['message']);
        $x = $this->actingAs($this->owner)->postJson('/api/quick-buy', $body + ['bank' => $this->bank])->assertOk()->json();
        $this->assertSame([14, 'PAID'], [$x['units'], $x['paid'][0]['status']]);
        $c = DB::table('t_src_cycles')->where('id', $x['cycle'])->first();
        $this->assertSame(['QUICK', 'Completed', 'Receipt 778'], [$c->cycle_type, $c->status, $c->order_ref]);
        $this->assertSame(['RECEIVED', 'PURCHASE'], [DB::table('t_src_po')->where('id', $x['po'])->value('status'), DB::table('t_src_grn')->where('id', $x['grn'])->value('grn_source')]);
        $this->assertSame([20, 8], [Stock::balance($this->p, $this->wh), Stock::balance($other, $this->wh)], 'two quick buys of 10 and 4');
        $this->assertSame(74.0, Stock::value(1)); $this->assertSame(Stock::value(1), Ledger::balance(1, '1200'));
        $this->assertSame(-37.0, Ledger::balance(1, '1000', null, $this->bank), 'only the second one was paid');
        $this->assertTrue(Ledger::trialBalance(1)['balanced']);
    }

    public function test_setup_wizard_steps_turn_green_from_the_data_and_only_optional_ones_can_be_ticked(): void
    {
        $d = $this->getJson('/api/setup')->assertOk()->json();
        $by = collect($d['steps'])->keyBy('key');
        $this->assertSame([11, true, true, true, true], [$d['total'], $by['company']['done'], $by['banks']['done'], $by['shops']['done'], $by['warehouse']['done']]);
        $this->assertSame('#/bank', $by['banks']['open']);
        $this->assertFalse($by['opening']['done']);
        $this->postJson('/api/setup/tick', ['step' => 'banks', 'on' => true])->assertStatus(422);
        $this->postJson('/api/setup/tick', ['step' => 'switch', 'on' => true, 'note' => 'old tool stays for a month'])->assertOk();
        $this->assertTrue(collect($this->getJson('/api/setup')->json('steps'))->firstWhere('key', 'switch')['done']);
        foreach (['carriers', 'printers', 'opening', 'imports', 'switch'] as $k) SetupWizard::tick(1, $k, true);
        DB::table('t_users')->insert(['login_id' => 'x@t', 'password_hash' => 'x', 'role_code' => 'VA', 'fk_id_ent' => 1, 'f_active' => 1]);
        $this->assertFalse(SetupWizard::open(1)); $this->assertFalse($this->getJson('/api/boot')->json('setup_open'));
        SetupWizard::tick(1, 'switch', false); $this->assertTrue($this->getJson('/api/boot')->json('setup_open'));
        $this->actingAs($this->makeUser('MANAGER'))->getJson('/api/setup')->assertStatus(403);
    }
}
