<?php

namespace Tests\Feature;

use App\Services\Choices;
use App\Services\InvestorDocs;
use App\Services\InvestorSettle;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-D-81 settlement line amounts and R-D-82 month settlement – the spec examples; the documents then carry the per-SKU lines (R-D-83). */
class InvestorSettleTest extends TestCase
{
    private int $mp; private int $shop; private int $imp; private int $p; private int $a1; private int $a2; private int $ownerAcc;

    private function f(string $t, array $d): int { return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }
    private function sale(array $x): int
    {
        return $this->f('t_sales', $x + ['fk_id_ent' => 1, 'fk_id_import' => $this->imp, 'fk_id_branch' => $this->shop, 'fk_id_marketplace' => $this->mp, 'fk_product_id' => $this->p, 'sku' => 'LAMP-01', 'order_no' => 'O' . random_int(1000, 9999), 'd_order' => '2026-10-10', 'status' => 'Confirmed']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->mp = $this->f('cat_marketplaces', ['fk_id_ent' => 1]);
        $this->ownerAcc = $this->f('t_account', ['fk_id_ent' => 1, 'account_code' => 'OWN1', 'first_name' => 'Omar', 'f_active' => 1]);
        $this->shop = $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $this->mp, 'shop_no' => '101', 'iso_branch' => '101', 'shop_name' => 'Shop 101', 'f_active' => 1, 'fk_id_owner' => $this->ownerAcc]);
        $this->p = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'LAMP-01', 'product_name_vendor' => 'Lamp', 'stock' => 0, 'f_active' => 1, 'raw_price' => 4, 'landing_price' => 4.5]);
        $this->f('t_product_marketplace_shipping', ['fk_id_product' => $this->p, 'fk_id_marketplace' => $this->mp, 'packing_fee' => 0.35, 'storage_fee' => 0.10]);
        $this->a1 = $this->f('t_account', ['fk_id_ent' => 1, 'account_code' => 'ALP', 'first_name' => 'Alpha', 'f_active' => 1]);
        $this->a2 = $this->f('t_account', ['fk_id_ent' => 1, 'account_code' => 'BET', 'first_name' => 'Beta', 'f_active' => 1]);
        foreach ([$this->a1, $this->a2] as $a) $this->f('t_product_investors', ['fk_id_product' => $this->p, 'fk_id_account' => $a, 'created_at' => now()]);
        $this->imp = $this->f('t_sale_imports', ['fk_id_ent' => 1, 'fk_id_branch' => $this->shop, 'fk_id_marketplace' => $this->mp, 'period_month' => 10, 'period_year' => 2026, 'marketplace_fee' => 10, 'status' => 'Confirmed', 'imported_at' => now()]);
        $this->f('t_inv_profit_splits', ['fk_id_ent' => 1, 'fk_id_branch' => 0, 'd_effective_from' => '2026-01-01', 'investor_pct' => 40, 'owner_pct' => 20, 'va_pct' => 15, 'handler_pct' => 25, 'owner_mode' => 'SHARE', 'va_mode' => 'SHARE', 'handler_mode' => 'SHARE', 'f_active' => 1, 'created_at' => now()]);
        $this->f('t_inv_parties', ['fk_id_ent' => 1, 'party_type' => 'COMPANY', 'party_code' => 'SBS', 'display_name' => 'Safe Buy Stores', 'company_name' => 'Safe Buy Stores Ltd', 'f_is_sbs' => 1, 'f_active' => 1, 'payment_terms_days' => 0, 'created_at' => now()]);
    }

    public function test_r81_line_amounts_spec_example(): void
    {
        $r = (object) ['qty' => 2, 'status' => 'Confirmed', 'refunded_at' => now(), 'refund_loss_nature' => 'Product_Carrier', 'fk_product_id' => $this->p, 'total_amount' => 20, 'raw_price' => 4, 'carrier_rate' => 3, 'packing_rate' => 0.40, 'profit' => 0];
        $l = InvestorSettle::line(1, $r, $this->mp);
        $this->assertSame([8.0, 3.0, 0.0, 0.35, 0], [$l['capital'], $l['shipping'], $l['packing_material'], $l['packing_fee'], $l['units']], 'refunded qty 2, Product_Carrier → product £8, carrier £3, packing £0, packing fee once');
        $this->assertSame(round(20 - 8 - 3 - 0 - 0, 2), $l['refund_adj'], 'each line before 3PL fees equals its Sales profit');
        $z = InvestorSettle::line(1, (object) ['qty' => 0, 'status' => 'Confirmed', 'refunded_at' => null, 'refund_loss_nature' => null, 'fk_product_id' => $this->p, 'total_amount' => 0, 'raw_price' => 4, 'carrier_rate' => 0, 'packing_rate' => 0, 'profit' => 0], $this->mp);
        $this->assertSame(0.10, $z['storage_fee'], 'qty 0 with storage £0.10 per unit → £0.10 (max(1, qty))');
        Choices::set('PACKING_FEE_PER', 'UNIT', 1);
        $this->assertSame(0.70, InvestorSettle::line(1, (object) ['qty' => 2, 'refunded_at' => null, 'status' => 'Confirmed', 'refund_loss_nature' => null, 'fk_product_id' => $this->p, 'total_amount' => 20, 'raw_price' => 4, 'carrier_rate' => 3, 'packing_rate' => 0.4, 'profit' => 0], $this->mp)['packing_fee']);
    }

    public function test_r82_settle_month_shares_and_documents(): void
    {
        $this->actingAs($this->owner);
        $this->getJson('/api/invoices/settle/' . $this->imp)->assertStatus(422)->assertJsonPath('message', 'This month has no confirmed or refunded sale lines.');
        $this->sale(['qty' => 5, 'total_amount' => 100, 'raw_price' => 4, 'carrier_rate' => 6, 'packing_rate' => 1, 'profit' => 63]);
        $c = $this->getJson('/api/invoices/settle/' . $this->imp)->assertOk()->json();
        $this->assertContains('1 sale line(s) are for products with more than one investor. Each line is divided equally between them.', $c['warnings']);
        // revenue 100 − capital 20 − mp fee 10 − 3PL (6 + 1 + 0.35 + 0.50) = 62.15
        $this->assertSame([62.15, 62.15, 24.86, 12.43, 9.32, 15.54], [$c['gross_profit'], $c['final_net_profit'], $c['shares']['investor'], $c['shares']['owner'], $c['shares']['va'], $c['shares']['handler']], 'the handler gets the rounding remainder');
        $this->assertSame([12.43, 12.43], array_column($c['investors'], 'share'), 'equal investors split the pool');
        $this->assertFalse(DB::table('t_inv_periods')->exists(), 'the preview writes nothing');
        $this->postJson('/api/invoices/settle/' . $this->imp, [])->assertStatus(422)->assertJsonPath('message', 'Tick the box to confirm you have checked the figures.');
        $s = $this->postJson('/api/invoices/settle/' . $this->imp, ['checked' => true, 'advertising' => 2.15])->assertOk()->json();
        $this->assertSame('Month settled. You can now generate the invoices.', $s['message']);
        $p = DB::table('t_inv_periods')->where('id', $s['period'])->first();
        $this->assertSame(['CONFIRMED', 60.0, 24.0], [$p->status, (float) $p->final_net_profit, (float) $p->investor_pool]);
        $this->postJson('/api/invoices/settle/' . $this->imp, ['checked' => true])->assertStatus(422)->assertJsonPath('message', 'This month is already settled for invoicing.');
        InvestorDocs::generate(1, (int) $p->id, $this->owner);
        $inv = DB::table('t_invoices')->where('invoice_type', 'INVESTOR')->where('fk_id_investor_account', $this->a1)->first();
        $this->assertSame([['LAMP-01 Lamp (your share 50%)', 2.5, 10.0], ['Investor profit share 40% of Final Net Profit - Shop 101, October 2026', 1.0, 12.0]],
            DB::table('t_invoice_lines')->where('fk_id_invoice', $inv->id)->orderBy('line_no')->get()->map(fn ($l) => [$l->description, (float) $l->qty, (float) $l->amount])->all(), 'per-SKU item line with the share');
    }

    public function test_r82_loss_carried_forward_and_block_unassigned(): void
    {
        $this->actingAs($this->owner);
        $this->sale(['qty' => 1, 'total_amount' => 5, 'raw_price' => 4, 'carrier_rate' => 6, 'packing_rate' => 0, 'profit' => -5]);
        $c = InvestorSettle::calc(1, $this->imp);
        $this->assertSame([0.0, round(-$c['final_net_profit'], 2)], [$c['shares']['investor'], $c['loss_cf']]);
        $this->assertContains('No profit to share this month. Loss carried forward: £' . number_format($c['loss_cf'], 2) . '.', $c['warnings']);
        DB::table('t_product_investors')->delete();
        try { InvestorSettle::calc(1, $this->imp); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('1 sale line(s) are for products with no investor in Inventory (Vendor tab > Investors). Add the investor before settling.', $e->getMessage()); }
        Choices::set('BLOCK_UNASSIGNED_INVESTOR', 'N', 1);
        $this->assertContains('1 sale line(s) are for products with no investor in Inventory (Vendor tab > Investors). Their investor share will not be invoiced to anyone.', InvestorSettle::calc(1, $this->imp)['warnings']);
        DB::table('t_sale_imports')->where('id', $this->imp)->update(['status' => 'Pending']);
        try { InvestorSettle::calc(1, $this->imp); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('Sales for Shop 101 - October 2026 are not confirmed yet (status: Pending). Confirm them in Sales > Imported months first.', $e->getMessage()); }
    }
    /** R-D-82 / IC-11 the VA salary comes from the job-history row valid on the month end; the old method (latest row) is shown beside it. */
    public function test_r82_va_salary_from_the_row_valid_on_the_month_end(): void
    {
        $this->actingAs($this->owner);
        $va = $this->f('t_account', ['fk_id_ent' => 1, 'account_code' => 'VA1', 'first_name' => 'Vee', 'f_active' => 1]);
        DB::table('t_sale_imports')->where('id', $this->imp)->update(['fk_id_va_account' => $va]);
        DB::table('t_inv_profit_splits')->update(['va_mode' => 'SALARY']);
        $this->f('t_account_job_history', ['fk_id_account' => $va, 'fk_id_marketplace' => $this->mp, 'd_job_start_date' => '2026-01-01', 'salary' => 300, 'pound_exchange_rate' => 0, 'f_active' => 1]);
        $this->f('t_account_job_history', ['fk_id_account' => $va, 'fk_id_marketplace' => $this->mp, 'd_job_start_date' => '2026-12-01', 'salary' => 450, 'pound_exchange_rate' => 0, 'f_active' => 1]);
        $this->sale(['qty' => 5, 'total_amount' => 100, 'raw_price' => 4, 'carrier_rate' => 6, 'packing_rate' => 1, 'profit' => 63]);
        $c = InvestorSettle::calc(1, $this->imp, []);
        $this->assertSame([300.0, 'MONTH_END', 450.0, true], [$c['va_salary'], $c['va_job']['source'], $c['va_job']['old_method_salary'], $c['va_job']['differs']], 'the December raise does not rewrite October');
        $this->assertNotEmpty(array_filter($c['warnings'], fn ($w) => str_contains($w, 'old method (latest row) gave 450.00')));
        Choices::set('INV_VA_JOB_ROW', 'LATEST', 1);
        $this->assertSame(450.0, InvestorSettle::calc(1, $this->imp, [])['va_salary'], 'the old method when chosen');
    }
}
