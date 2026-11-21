<?php

namespace Tests\Feature;

use App\Services\DashCards;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_F_SETUP R-F_SETUP-25 last lots + cost source, R-F_SETUP-31 buying card, R-F_SETUP-32 sourcing glance, R-F_SETUP-34 deep links. */
class OverviewCardsTest extends TestCase
{
    private function f(string $t, array $d): int
    {
        foreach (['fk_id_ent' => 1, 'f_active' => 1, 'created_at' => now()] as $k => $v) if (! array_key_exists($k, $d) && \Illuminate\Support\Facades\Schema::hasColumn($t, $k)) $d[$k] = $v;
        return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
    }

    public function test_lots_cost_source_per_lot_and_money_gate(): void
    {
        $this->actingAs($this->owner);
        $a = $this->f('t_account', ['account_code' => 'OW' . substr(uniqid(), -5), 'first_name' => 'Olga', 'sur_name' => 'Owner']);
        $mk = fn ($code, $cost, $src, $st = 'PROVISIONAL') => $this->f('fin_lots', ['company_id' => 1, 'lot_code' => $code, 'sku' => 'S-' . $code, 'qty' => 10, 'qty_left' => 10, 'unit_cost_gbp' => $cost, 'cost_source' => $src, 'landed_status' => $st, 'fk_id_owner' => $a, 'received_date' => '2026-11-01']);
        $mk('L-FIN', 4.5, 'FINAL', 'FINAL'); $mk('L-PRO', 4.0, 'PROVISIONAL'); $mk('L-MAN', 3.0, 'MANUAL', 'FINAL'); $mk('L-MIS', 0, null);
        $x = DashCards::lots(1, true);
        $chips = collect($x['rows'])->pluck('chip', 'lot');
        $this->assertSame(['Calculated', 'Provisional', 'Entered by hand', 'Empty'], [$chips['L-FIN'], $chips['L-PRO'], $chips['L-MAN'], $chips['L-MIS']], 'each lot its own source – no global flag');
        $this->assertSame('Olga Owner', collect($x['rows'])->firstWhere('lot', 'L-FIN')['owner']);
        $this->assertEquals(4.5, collect($x['rows'])->firstWhere('lot', 'L-FIN')['cost']); $this->assertSame('Landed Unit Cost', $x['header']);
        $n = DashCards::lots(1, false); $this->assertNull($n['rows'][0]['cost']); $this->assertSame('Cost source', $n['header'], 'chip only without see money');
        $this->assertLessThanOrEqual(10, count($x['rows']));
    }

    public function test_buying_card_glance_and_deep_links(): void
    {
        $this->actingAs($this->owner);
        DB::table('t_src_cycles')->update(['f_active' => 0]);
        $mkBuy = function (int $items) { $cy = $this->f('t_src_cycles', ['cycle_code' => 'QB-' . uniqid(), 'product_name' => 'X', 'status' => 'Completed', 'cycle_type' => 'QUICK', 'created_by' => $this->owner->id]);
            $po = $this->f('t_src_po', ['fk_id_cycle' => $cy, 'po_number' => 'PO-QB-' . uniqid(), 'status' => 'RECEIVED', 'po_date' => date('Y-m-d'), 'po_total' => $items * 10, 'exchange_rate' => 1]);
            $this->f('t_src_po_lines', ['fk_id_cycle' => $cy, 'fk_id_po' => $po, 'qty_ordered' => $items, 'line_total' => $items * 10]); };
        $mkBuy(3); $mkBuy(5);
        $x = $this->getJson('/api/buying/card/buyingcard')->assertOk()->json('data');
        $this->assertSame(['2 · 8 items', date('d M Y'), 'Buying Management'], [$x['purchases'], $x['last'], $x['title']], 'the rule example');
        $this->assertEquals(80, $x['value']);

        $cy = $this->f('t_src_cycles', ['cycle_code' => 'CYC-GL-' . uniqid(), 'product_name' => 'X', 'status' => 'Open', 'cycle_type' => 'FULL', 'created_by' => $this->owner->id]);
        $po = $this->f('t_src_po', ['fk_id_cycle' => $cy, 'po_number' => 'PO-GL', 'status' => 'CONFIRMED', 'po_total' => 12900, 'exchange_rate' => 1, 'po_date' => '2026-11-01']);
        $this->f('t_src_payments', ['fk_id_cycle' => $cy, 'fk_id_po' => $po, 'payment_code' => 'P-GL', 'payment_amount' => 13000, 'base_amount' => 13000]);
        $sh = $this->f('t_src_shipments', ['fk_id_cycle' => $cy, 'fk_id_po' => $po, 'shipment_code' => 'SHP-GL-LATE', 'eta' => date('Y-m-d', strtotime('-4 days'))]);
        $g = collect(DashCards::glance(1, true)['rows'])->pluck('value', 'label');
        $this->assertSame(['1', '0.00 GBP'], [(string) $g['Open cycles'], $g['PO outstanding']], 'open PO 12,900, paid 13,000 → 0.00');
        $this->assertSame(1, $g['Shipments overdue']);
        $this->assertArrayNotHasKey('PO outstanding', collect(DashCards::glance(1, false)['rows'])->pluck('value', 'label')->all());
        $link = collect(DashCards::needs(1)['rows'])->firstWhere('code', 'SRC_SHIP_OVERDUE')['link'];
        $this->assertSame("#/cycle/$cy?tab=shipments&hl=SHP-GL-LATE", $link, 'the cycle opens on the Shipments tab with the row highlighted');
    }
}
