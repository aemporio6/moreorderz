<?php

namespace Tests\Feature;

use App\Services\RecordService;
use App\Services\RefundProposals as RP;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_A_SALES R-A_SALES-86: refund kinds, one row per order + SKU + refund id, refunds without a matching line, To classify, Confirm blocked. */
class RefundProposalsTest extends TestCase
{
    private int $shop; private int $mp;

    private function world(): void
    {
        $this->actingAs($this->owner); $f = fn (string $t, array $d) => RecordService::fillNotNull($t, $d);
        $this->mp = DB::table('cat_marketplaces')->insertGetId($f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'Ebay', 'marketplace_descr' => 'eBay', 'f_active' => 1]));
        $this->shop = DB::table('t_branches')->insertGetId($f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $this->mp, 'shop_no' => '101A', 'shop_name' => 'Pets', 'iso_branch' => 'P1', 'f_active' => 1]));
    }

    private function sale(string $no, string $sku, float $total): int
    {
        return DB::table('t_sales')->insertGetId(RecordService::fillNotNull('t_sales', ['fk_id_ent' => 1, 'fk_id_branch' => $this->shop, 'fk_id_marketplace' => $this->mp, 'order_no' => $no, 'd_order' => '2026-07-05', 'cust_name' => 'Jo', 'sku' => $sku, 'qty' => 1, 'sale_rate' => $total, 'total_amount' => $total, 'total_amount_gbp' => $total, 'status' => 'Pending', 'sale_source' => 'unmatched', 'created_at' => now()]));
    }

    private function refund(string $no, string $sku, float $amt, string $rid, ?float $reportSale = null): string { return RP::fromReport(1, ['shop' => $this->shop, 'order_no' => $no, 'sku' => $sku, 'refund_id' => $rid, 'amount' => $amt, 'date' => '2026-07-20', 'report_sale' => $reportSale]); }

    public function test_kinds_and_the_profile_band(): void
    {
        $this->assertSame(['FULL', 'FULL', 'HALF', 'HALF', 'PARTIAL', 'PARTIAL', 'TO_CLASSIFY'], [RP::kind(20, 20), RP::kind(20, 19.99), RP::kind(20, 10), RP::kind(20, 9.80), RP::kind(20, 9.70), RP::kind(20, 10.30), RP::kind(null, 5)]);
        $prof = DB::table('mp_profiles')->insertGetId(RecordService::fillNotNull('mp_profiles', ['fk_id_ent' => 1, 'code' => 'T_' . uniqid(), 'name' => 'Test', 'f_active' => 1, 'created_at' => now()]));
        DB::table('mp_profile_refund_kinds')->insert(['fk_id_mp_profile' => $prof, 'kind_code' => 'HALF', 'label' => 'Half', 'pct_from' => 45, 'pct_to' => 55, 'f_active' => 1]); $this->assertSame('HALF', RP::kind(20, 9.20, $prof));      // another profile, another band
        DB::table('mp_profile_refund_kinds')->where('fk_id_mp_profile', $prof)->update(['kind_code' => 'FULL']); $this->assertSame('PARTIAL', RP::kind(20, 10, $prof));      // a profile without a Half kind
    }

    public function test_proposals_dedupe_unknown_sku_and_the_confirm_block(): void
    {
        $this->world(); $a = $this->sale('E-1', 'A1', 20); $this->sale('E-2', 'A2', 20); $this->sale('E-3', 'A3', 20); $this->sale('E-4', 'A4', 20);
        $this->assertSame(['proposal', 'proposal', 'proposal', 'proposal'], [$this->refund('E-1', 'A1', 20, 'R1'), $this->refund('E-2', 'A2', 10, 'R2'), $this->refund('E-3', 'A3', 9.80, 'R3'), $this->refund('E-4', 'A4', 9.70, 'R4')]);
        $this->assertSame(['FULL', 'HALF', 'HALF', 'PARTIAL'], DB::table('t_sale_refund_proposals')->orderBy('id')->pluck('refund_kind')->all());
        $this->assertSame('unknown', $this->refund('E-1', 'ZZ-9', 5, 'R5')); $u = DB::table('t_sale_refund_proposals')->where('refund_id', 'R5')->first(); $this->assertSame(['UNKNOWN', 1, null], [$u->status, (int) $u->f_sku_unknown, $u->fk_id_sale]);
        $rows = DB::table('t_sale_charges')->count(); foreach ([['E-1', 'A1', 20, 'R1'], ['E-1', 'ZZ-9', 5, 'R5']] as $x) $this->assertSame('duplicate', $this->refund(...$x)); $this->assertSame([$rows, 5], [DB::table('t_sale_charges')->count(), DB::table('t_sale_refund_proposals')->count()]);      // the same money report twice: no new rows
        $this->assertSame(20.0, (float) DB::table('t_sales')->where('id', $a)->value('total_amount'));
        $p = RP::page($this->owner); $this->assertSame('Sold £20.00 → Refund £10.00 (Half)', $p['rows'][1]['text']); $this->assertSame(['ZZ-9', 5.0], [$p['unknown'][0]['sku'], $p['unknown'][0]['amount']]);
        $block = fn () => RP::confirmBlock($this->shop, '2026-07'); $this->assertStringContainsString('4 refund proposal(s) of this month wait', $block()); $this->assertStringContainsString('1 refund(s) have no matching sale line', $block());
        foreach (DB::table('t_sale_refund_proposals')->where('status', 'PROPOSED')->pluck('id') as $id) \App\Services\SettlementService::decideRefund(1, (int) $id, false, 'buyer kept it');
        $this->assertStringContainsString('link or reject', $block());      // Confirm stays blocked until the unknown refund is linked or rejected
        try { \App\Services\SettlementService::decideRefund(1, (int) $u->id, true); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('has no sale line yet', $e->getMessage()); }
        $this->assertStringContainsString('E-1', RP::candidates((int) $u->id, '', $this->owner)['rows'][0]['label']);
        try { RP::link((int) $u->id, null, 'NOPE', $this->owner); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('has no line with SKU NOPE', $e->getMessage()); }
        RP::link((int) $u->id, $a, null, $this->owner); $l = DB::table('t_sale_refund_proposals')->where('id', $u->id)->first(); $this->assertSame(['PROPOSED', $a, 'PARTIAL'], [$l->status, (int) $l->fk_id_sale, $l->refund_kind]);
        $this->assertSame('OUTSIDE', RP::markOutside((int) $u->id, true, $this->owner)['kind']); $this->assertSame('PARTIAL', RP::markOutside((int) $u->id, false, $this->owner)['kind']);
        \App\Services\SettlementService::decideRefund(1, (int) $u->id, false, 'wrong order'); $this->assertNull($block());
        $this->assertSame('unknown', $this->refund('E-9', 'Q', 3, 'R9')); $q = (int) DB::table('t_sale_refund_proposals')->where('refund_id', 'R9')->value('id'); try { RP::rejectUnknown($q, ' ', $this->owner); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('Give the reason for rejecting.', $e->getMessage()); }
        RP::rejectUnknown($q, 'not ours', $this->owner); $this->assertNull($block());
    }

    public function test_a_line_without_price_uses_the_reports_sale_amount_or_waits_to_be_classified(): void
    {
        $this->world(); $d = $this->sale('T-1', 'D1', 0); $w = $this->sale('T-2', 'D2', 0);
        $this->refund('T-1', 'D1', 15, 'RA', 30.0); $this->refund('T-2', 'D2', 15, 'RB');      // daily-only lines: no price yet
        $pa = DB::table('t_sale_refund_proposals')->where('refund_id', 'RA')->first(); $pb = DB::table('t_sale_refund_proposals')->where('refund_id', 'RB')->first();
        $this->assertSame(['HALF', 'REPORT', 'TO_CLASSIFY'], [$pa->refund_kind, $pa->kind_source, $pb->refund_kind]); $this->assertSame([0.0, 0.0], [(float) DB::table('t_sales')->where('id', $d)->value('total_amount'), (float) DB::table('t_sales')->where('id', $w)->value('sale_rate')]);      // the price stays empty
        $row = collect(RP::page($this->owner)['rows'])->firstWhere('id', (int) $pb->id); $this->assertFalse($row['can_accept']);
        try { \App\Services\SettlementService::decideRefund(1, (int) $pb->id, true); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('has no price yet', $e->getMessage()); }
        DB::table('t_sales')->where('id', $w)->update(['total_amount' => 15, 'sale_rate' => 15]); $row = collect(RP::page($this->owner)['rows'])->firstWhere('id', (int) $pb->id); $this->assertSame(['FULL', true], [$row['refund_kind'], $row['can_accept']]);      // gets its kind when the line gets its price
        $this->getJson('/api/refunds')->assertOk()->assertJsonPath('rows.0.kind_label', 'Half');
    }
}
