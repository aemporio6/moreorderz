<?php

namespace Tests\Feature;

use App\Services\Approvals;
use App\Services\BuyingService as Buy;
use App\Services\Context;
use App\Services\LandedEngine as LE;
use App\Services\LandedVersions;
use App\Services\Ledger;
use App\Services\ProfitService;
use App\Services\RecordService;
use App\Services\StockService as Stock;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_B_SOURCING R-B_SOURCING-43 ten buckets, R-45 override with reason, R-46 run lifecycle (preview, PROVISIONAL, FINAL), R-60 versions and COGS correction after a LOCKED month. */
class LandedVersionsTest extends TestCase
{
    private int $wh; private int $po; private int $p; private int $cy; private int $sup;
    private function f(string $t, array $d): int { return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }

    /** One product, 400 ordered at £2.50 (value 1,000), received 400. */
    private function cycle(float $price = 2.5, int $qty = 400): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $this->wh = $this->f('cat_inventory_locations', ['location_name' => 'LV main', 'kind' => 'WAREHOUSE', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1]);
        $this->sup = $this->f('t_src_suppliers', ['fk_id_ent' => 1, 'supplier_code' => 'S-LV' . random_int(100, 999), 'supplier_name' => 'LV Co', 'f_active' => 1]);
        $this->p = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'LV-A' . random_int(100, 999), 'product_name_vendor' => 'LV item', 'f_active' => 1]);
        $this->cy = $this->f('t_src_cycles', ['fk_id_ent' => 1, 'cycle_code' => 'CYC-LV' . random_int(100, 999), 'product_name' => 'LV', 'status' => 'Open', 'cycle_type' => 'FULL', 'f_active' => 1, 'created_at' => now()]);
        $cp = $this->f('t_src_cycle_products', ['fk_id_cycle' => $this->cy, 'fk_id_product' => $this->p, 'product_name' => 'x']);
        $this->f('t_src_allocations', ['fk_id_ent' => 1, 'fk_id_cycle' => $this->cy, 'fk_id_cycle_product' => $cp, 'allocated_qty' => $qty, 'f_active' => 1]);      // planned = ordered, so the quantity check reads the GRN lines
        $this->po = Buy::createPo(['cycle' => $this->cy, 'supplier' => $this->sup, 'currency' => 'GBP', 'date' => '2026-08-01', 'lines' => [['product' => $this->p, 'qty' => $qty, 'unit_price' => $price]]]);
        Buy::confirmPo($this->po); $ship = Buy::createShipment(['po' => $this->po, 'eta' => '2026-08-18']);
        $l = DB::table('t_src_po_lines')->where('fk_id_po', $this->po)->value('id');
        Buy::postGrn(Buy::createGrn(['shipment' => $ship, 'location' => $this->wh, 'date' => '2026-08-20', 'lines' => [['po_line' => $l, 'received' => $qty, 'damaged' => 0, 'location' => $this->wh]]]));
    }
    private function lotCost(): float { return (float) DB::table('fin_lots')->where('po_id', $this->po)->where('source', 'GRN')->value('unit_cost_gbp'); }

    public function test_r43_ten_buckets_sum_and_per_unit_manual_line_and_run_lines(): void
    {
        // the rule's test: 1,000 + 20 + 15 + 30 + 180 + 5 + 41 + 25 + 12 + 0 = 1,328.00, accepted 400 → 3.3200
        $b = array_combine(array_keys(LE::BUCKETS), [1000, 20, 15, 30, 180, 5, 41, 25, 12, 0]);
        $this->assertSame([1328.0, 3.32], LE::bucketTotal($b, 400)); $this->assertSame([1328.0, 0.0], LE::bucketTotal($b, 0));
        // a manual line (no run): total and per unit worked out on save, cost source MANUAL
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $id = RecordService::save('t_src_landed_cost', $b + ['accepted_qty' => 400])['id'];
        $row = DB::table('t_src_landed_cost')->where('id', $id)->first();
        $this->assertSame([1328.0, 3.32, 'MANUAL'], [(float) $row->total_cost, (float) $row->landed_cost_per_unit, $row->landed_status]);
        // a run puts each cost type in its bucket; import VAT never
        $this->cycle();
        foreach ([['Ocean Freight', 180], ['Insurance', 5], ['Origin charges', 30], ['Destination charges', 25], ['Last mile', 12], ['Inspection', 15], ['Packaging', 20], ['Agent Fee', 0.01]] as [$t, $a])
            $this->f('t_src_freight_costs', ['fk_id_ent' => 1, 'fk_id_cycle' => $this->cy, 'freight_code' => 'FRT' . random_int(1000, 9999), 'cost_type' => $t, 'amount' => $a, 'base_amount' => $a, 'exchange_rate' => 1, 'f_active' => 1, 'created_at' => now()]);
        $r = Buy::landedCost($this->po, ['duty' => 41]);
        $line = DB::table('t_src_landed_lines')->where('fk_id_landed', $r['run'])->first();
        $this->assertSame([1000.0, 20.0, 15.0, 30.0, 180.0, 5.0, 41.0, 25.0, 12.0, 0.01], array_map(fn ($c) => (float) ($c === 'product_cost' ? $line->product_value : $line->{$c}), array_keys(LE::BUCKETS)));
        $this->assertSame(3.32, (float) $line->unit_cost, '1,328.01 ÷ 400'); $this->assertSame(180.0, (float) DB::table('t_src_landed_cost')->where('id', $r['run'])->value('freight_cost'));
        try { RecordService::save('t_src_landed_cost', ['other_cost' => 1], $r['run']); $this->fail('a run line is not edited by hand'); }
        catch (\Illuminate\Validation\ValidationException $e) { $this->assertStringContainsString('made by a landed-cost run', json_encode($e->errors())); }
    }

    public function test_r45_override_reason_limit_approval_and_survives_a_rerun(): void
    {
        $this->cycle(2.4);      // calc 2.4000
        $r = Buy::landedCost($this->po, []); $line = (int) DB::table('t_src_landed_lines')->where('fk_id_landed', $r['run'])->value('id');
        $this->assertSame(2.4, $this->lotCost());
        try { LandedVersions::override($line, 2.5, ''); $this->fail('reason'); } catch (\InvalidArgumentException $e) { $this->assertSame('Give the reason for the override.', $e->getMessage()); }
        // the rule's test: 3.0000 → 25 % → Pending approval, lots stay 2.4000
        $p = LandedVersions::override($line, 3.0, 'supplier invoice higher');
        $this->assertSame(['PENDING', 25.0], [$p['status'], $p['pct']]); $this->assertSame(2.4, $this->lotCost());
        $this->assertSame('SRC_LC_OVERRIDE', DB::table('sys_approvals')->where('id', $p['approval'])->value('action_code'));
        // 2.5000 with reason "agent rebate missing" → 4.2 % → applied at once, history line written, lots MANUAL
        $a = LandedVersions::override($line, 2.5, 'agent rebate missing');
        $this->assertSame(['APPLIED', 4.2], [$a['status'], $a['pct']]); $this->assertSame(2.5, $this->lotCost());
        $this->assertSame('MANUAL', DB::table('fin_lots')->where('po_id', $this->po)->where('source', 'GRN')->value('cost_source'));
        $this->assertTrue(DB::table('sys_audit_log')->where('table_name', 't_src_cycles')->where('record_id', $this->cy)->where('changes_json', 'like', '%LANDED_COST_OVERRIDE%calc 2.4000 -> 2.5000 reason: agent rebate missing%')->exists());
        $l = DB::table('t_src_landed_lines')->where('id', $line)->first(); $this->assertSame([2.4, 2.5], [(float) $l->calc_unit_cost, (float) $l->override_unit_cost], 'the calculated value stays');
        $this->assertSame(Stock::value(1), Ledger::balance(1, '1200')); $this->assertTrue(Ledger::trialBalance(1)['balanced']);
        // a re-run keeps the applied override and shows the new calc next to it
        $r2 = Buy::landedCost($this->po, ['freight' => 40]);
        $l2 = DB::table('t_src_landed_lines')->where('fk_id_landed', $r2['run'])->first();
        $this->assertSame([2.5, 2.5, 'APPLIED'], [(float) $l2->unit_cost, $this->lotCost(), $l2->override_status]); $this->assertSame(2.5, (float) $l2->calc_unit_cost, '(960 + 40) ÷ 400');
        // the old pending approval no longer changes anything once decided
        $this->actingAs($m = $this->makeUser('MANAGER')); Context::set(1, $m->id);
        Approvals::decide($p['approval'], true); $this->assertSame(2.5, $this->lotCost());
    }

    public function test_r46_preview_saves_nothing_reruns_keep_one_line_and_final_is_guarded(): void
    {
        $this->cycle();
        $runs = DB::table('t_src_landed_cost')->count(); $ledger = DB::table('gl_journals')->count();
        $pv = $this->postJson("/api/po/{$this->po}/landed/preview", ['freight' => 328])->assertOk()->json();
        $this->assertSame([3.32, 2.5], [$pv['rows'][0]['unit'], $pv['rows'][0]['old_unit']]); $this->assertSame('QUANTITY', $pv['rows'][0]['shares'][0]['method']);
        $this->assertSame([$runs, $ledger, 2.5], [DB::table('t_src_landed_cost')->count(), DB::table('gl_journals')->count(), $this->lotCost()], 'the preview saves nothing');
        // the rule's test: "Auto calculate" twice → still 1 current line per product (older version SUPERSEDED)
        $this->postJson("/api/po/{$this->po}/landed", ['freight' => 328])->assertOk(); $this->postJson("/api/po/{$this->po}/landed", ['freight' => 328])->assertOk();
        $cur = DB::table('t_src_landed_cost')->where('fk_id_cycle', $this->cy)->whereNull('superseded_by')->get();
        $this->assertCount(1, $cur); $this->assertSame(1, DB::table('t_src_landed_lines')->where('fk_id_landed', $cur[0]->id)->count());
        $this->assertSame(['SUPERSEDED', 'PROVISIONAL'], DB::table('t_src_landed_cost')->where('fk_id_cycle', $this->cy)->orderBy('id')->pluck('landed_status')->all());
        // a new freight row re-runs PROVISIONAL by the worker: not within 5 minutes of the change, once after, typed amounts kept
        DB::table('t_src_landed_cost')->where('id', $cur[0]->id)->update(['created_at' => date('Y-m-d H:i:s', strtotime('-30 minutes'))]);
        DB::table('t_src_grn')->where('fk_id_cycle', $this->cy)->update(['created_at' => date('Y-m-d H:i:s', strtotime('-40 minutes')), 'updated_at' => null]);      // the GRN came before the run
        $fr = $this->f('t_src_freight_costs', ['fk_id_ent' => 1, 'fk_id_cycle' => $this->cy, 'freight_code' => 'FRT-LV', 'cost_type' => 'Ocean Freight', 'amount' => 40, 'base_amount' => 40, 'exchange_rate' => 1, 'f_active' => 1, 'created_at' => date('Y-m-d H:i:s', strtotime('-2 minutes'))]);
        $this->assertSame(0, LandedVersions::autoRerun(1), 'debounce: changed 2 minutes ago');
        DB::table('t_src_freight_costs')->where('id', $fr)->update(['created_at' => date('Y-m-d H:i:s', strtotime('-10 minutes'))]);
        \App\Services\Choices::set('LC_AUTO_RERUN_MINUTES', 'OFF', 1); $this->assertSame(0, LandedVersions::autoRerun(1), 'Choices: never by itself');
        \App\Services\Choices::set('LC_AUTO_RERUN_MINUTES', '15', 1); $this->assertSame(0, LandedVersions::autoRerun(1), 'Choices: 15 minutes – changed 10 minutes ago');
        \App\Services\Choices::set('LC_AUTO_RERUN_MINUTES', '5', 1);      // the default
        $this->assertSame(1, LandedVersions::autoRerun(1)); $this->assertSame(3.42, $this->lotCost(), '(1,000 + 328 typed + 40 new) ÷ 400');
        $this->assertSame(0, LandedVersions::autoRerun(1), 'once per change');
        DB::table('t_src_freight_costs')->where('id', $fr)->update(['f_active' => 0]); Buy::landedCost($this->po, ['freight' => 328]);
        // the rule's test: Make FINAL with a Mismatch → refused with the reason
        DB::table('t_src_grn_lines')->where('fk_id_grn', DB::table('t_src_grn')->where('fk_id_cycle', $this->cy)->value('id'))->update(['missing_qty' => 7]);
        $this->postJson("/api/po/{$this->po}/landed", ['freight' => 328, 'final' => true])->assertStatus(422)->assertJsonPath('message', 'The quantity check of this cycle shows a Mismatch. Make the GRN lines add up before the landed cost is FINAL.');
        DB::table('t_src_grn_lines')->where('fk_id_grn', DB::table('t_src_grn')->where('fk_id_cycle', $this->cy)->value('id'))->update(['missing_qty' => 0]);
        // FINAL needs the right (old "Partner / Manager only")
        $this->actingAs($this->makeUser('FINANCE'))->postJson("/api/po/{$this->po}/landed", ['freight' => 328, 'final' => true])->assertStatus(403)->assertJsonPath('message', 'Partner / Manager only');
        $this->actingAs($this->owner)->postJson("/api/po/{$this->po}/landed", ['freight' => 328, 'final' => true])->assertOk()->assertJsonPath('status', 'FINAL');
        $this->assertSame(['FINAL', 3.32], [DB::table('fin_lots')->where('po_id', $this->po)->where('source', 'GRN')->value('landed_status'), $this->lotCost()]);
        $this->getJson('/api/landed/' . DB::table('t_src_landed_cost')->where('fk_id_cycle', $this->cy)->max('id'))->assertOk()->assertJsonCount(5, 'versions');      // every run is a version: 2 saves, 1 automatic, 1 save, 1 FINAL
    }

    public function test_r60_later_version_final_by_itself_and_cogs_correction_in_the_next_open_month(): void
    {
        $this->cycle();
        $v1 = Buy::landedCost($this->po, ['freight' => 328, 'final' => true]);
        $this->assertSame(['FINAL', 3.32], [$v1['status'], $this->lotCost()]);
        // 100 units sold in October; October is LOCKED
        $mp = $this->f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'LVmp', 'f_active' => 1]);
        $shop = $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => 'LV1', 'iso_branch' => 'LV' . random_int(100, 999), 'shop_name' => 'LV shop', 'f_active' => 1]);
        $sale = $this->f('t_sales', ['fk_id_ent' => 1, 'fk_id_branch' => $shop, 'fk_product_id' => $this->p, 'order_no' => 'LV-' . uniqid(), 'd_order' => '2026-10-15', 'qty' => 100, 'total_amount_gbp' => 900, 'status' => 'Confirmed', 'sale_source' => 'active']);
        Stock::consumeForSale(1, $sale, $this->p, 100, $this->wh);
        $this->f('aud_runs', ['run_code' => 'LV-OCT', 'fk_id_ent' => 1, 'fk_id_branch' => $shop, 'period_month' => '2026-10', 'status' => 'LOCKED', 'created_at' => now()]);
        $octBefore = collect(ProfitService::view(1, 'shop', '2026-10-01', '2026-10-31')['rows'])->firstWhere('key', 'S' . $shop)['cost'];
        // the rule's test: late freight £40 on 400 accepted → v2 3.4200, Balanced and rates present → FINAL by itself, v1 SUPERSEDED
        $v2 = Buy::landedCost($this->po, ['freight' => 368]);
        $this->assertSame(['FINAL', 2, true], [$v2['status'], $v2['version'], $v2['applied']]); $this->assertSame(3.42, $this->lotCost());
        $this->assertSame('SUPERSEDED', DB::table('t_src_landed_cost')->where('id', $v1['run'])->value('landed_status'));
        // 100 units sold in locked October → COGS adjustment +£10.00 in November; October untouched
        $c = DB::table('fin_cogs_corrections')->where('lot_id', DB::table('fin_lots')->where('po_id', $this->po)->where('source', 'GRN')->value('id'))->first();
        $this->assertSame(['2026-10', '2026-11', 10.0], [$c->source_month, $c->target_month, (float) $c->amount]); $this->assertSame('Landed cost v2 (' . DB::table('t_src_cycles')->where('id', $this->cy)->value('cycle_code') . ')', $c->reason);
        $this->assertSame(3.32, (float) DB::table('fin_lot_consumption')->where('fk_id_sale', $sale)->value('unit_cost_gbp'), 'the locked month keeps its cost');
        $this->assertSame($octBefore, collect(ProfitService::view(1, 'shop', '2026-10-01', '2026-10-31')['rows'])->firstWhere('key', 'S' . $shop)['cost']);
        $this->assertSame(10.0, collect(ProfitService::view(1, 'shop', '2026-11-01', '2026-11-30')['rows'])->firstWhere('key', 'S' . $shop)['cost']);
        $this->assertSame(Stock::value(1), Ledger::balance(1, '1200')); $this->assertTrue(Ledger::trialBalance(1)['balanced']);
        // a later version that cannot be FINAL (a Mismatch) waits: lots keep the FINAL cost, the Partner is told
        DB::table('t_src_grn_lines')->where('fk_id_grn', DB::table('t_src_grn')->where('fk_id_cycle', $this->cy)->value('id'))->update(['missing_qty' => 3]);
        $mgr = $this->makeUser('MANAGER');      // holds buying.LANDED_FINAL – the one who is told
        $v3 = Buy::landedCost($this->po, ['freight' => 400]);
        $this->assertSame(['PROVISIONAL', false], [$v3['status'], $v3['applied']]); $this->assertSame(3.42, $this->lotCost());
        $this->assertTrue(DB::table('tm_notifications')->where('user_id', $mgr->id)->where('text', 'like', 'New cost after FINAL:%v3 waits%')->exists());
        $this->assertNull(DB::table('t_src_landed_cost')->where('id', $v2['run'])->value('superseded_by'), 'the FINAL version stays in force');
    }
}
