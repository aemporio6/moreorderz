<?php

namespace Tests\Feature;

use App\Services\BuyingService as Buy;
use App\Services\Context;
use App\Services\Fx;
use App\Services\PaymentTerms;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-B_SOURCING-30 term paid / remaining / status from its payments, summed in PO currency, re-synced on every change. */
class PaymentTermsTest extends TestCase
{
    public function test_r30_paid_is_summed_in_po_currency_and_resynced_on_change_move_and_reversal(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id); $f = fn ($t, $d) => DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
        Fx::set('USD', '2026-11-01', 0.80); Fx::set('EUR', '2026-11-01', 0.88);
        $usd = Buy::currencyId('USD'); $eur = Buy::currencyId('EUR');
        $po = $f('t_src_po', ['fk_id_ent' => 1, 'po_number' => 'PO-PT-1', 'fk_id_currency' => $usd, 'status' => 'CONFIRMED', 'f_active' => 1]);
        $t1 = $f('t_src_payment_terms', ['fk_id_ent' => 1, 'fk_id_po' => $po, 'term_no' => 1, 'milestone' => 'Deposit', 'planned_amount' => 1000, 'paid_amount' => 0, 'remaining_amount' => 1000, 'status' => 'DUE', 'f_active' => 1]);
        $t2 = $f('t_src_payment_terms', ['fk_id_ent' => 1, 'fk_id_po' => $po, 'term_no' => 2, 'milestone' => 'Balance', 'planned_amount' => 0, 'paid_amount' => 0, 'remaining_amount' => 0, 'status' => 'DUE', 'f_active' => 1]);
        $pay = fn ($term, $amt, $cur, $rate, $st = 'PAID') => $f('t_src_payments', ['fk_id_ent' => 1, 'fk_id_po' => $po, 'fk_id_term' => $term, 'payment_amount' => $amt, 'fk_id_currency' => $cur, 'exchange_rate' => $rate, 'payment_date' => '2026-11-01', 'status' => $st, 'f_active' => 1, 'payment_code' => uniqid('P')]);
        $a = $pay($t1, 400, $usd, 0.80); $b = $pay($t1, 100, $eur, 0.88); $pay($t1, 999, $usd, 0.80, 'PENDING_APPROVAL');
        $r = PaymentTerms::sync($t1);
        $this->assertSame([510.0, 490.0, 51.0, 'PART_PAID'], [$r['paid_amount'], $r['remaining_amount'], $r['completion_pct'], $r['status']], '100 EUR × 0.88 ÷ 0.80 = 110 USD; the payment waiting for approval is not counted');
        $this->assertSame([0.0, 'DUE'], [PaymentTerms::sync($t2)['completion_pct'], PaymentTerms::sync($t2)['status']], 'planned 0: 0 %, never Paid');
        RecordService::save('t_src_payments', ['fk_id_term' => $t2], $b);      // moved to another term: both terms are re-synced
        $this->assertSame(['400.0000', '110.0000'], [DB::table('t_src_payment_terms')->where('id', $t1)->value('paid_amount'), DB::table('t_src_payment_terms')->where('id', $t2)->value('paid_amount')]);
        RecordService::save('t_src_payments', ['payment_amount' => 1000], $a); $this->assertSame('PAID', DB::table('t_src_payment_terms')->where('id', $t1)->value('status'));
        RecordService::save('t_src_payments', ['f_active' => 0], $a); $this->assertSame(['0.0000', 'DUE'], [DB::table('t_src_payment_terms')->where('id', $t1)->value('paid_amount'), DB::table('t_src_payment_terms')->where('id', $t1)->value('status')], 'reversed: re-synced');
    }

    /** R-B_SOURCING-32 the rule example: USD 1,000 on 05/11, table 0.7812 → base 781.20; typed 0.7790 → base 779.00, difference −2.20. */
    public function test_r32_rate_table_line_and_typed_rate_on_money_rows(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id); Fx::set('USD', '2026-11-05', 0.7812);
        $a = Fx::rateNote('USD', '2026-11-05', 1000); $this->assertSame([781.2, 0.0], [$a['base'], $a['difference']]);
        $b = Fx::rateNote('USD', '2026-11-05', 1000, 0.7790); $this->assertSame([779.0, -2.2, 'Rate table 0.7812 · difference -2.20 (base 779.00)'], [$b['base'], $b['difference'], $b['text']]);
        $this->getJson('/api/fx/note?currency=USD&date=2026-11-05&amount=1000&rate=0.779')->assertOk()->assertJsonPath('difference', -2.2);
        $po = DB::table('t_src_po')->insertGetId(RecordService::fillNotNull('t_src_po', ['fk_id_ent' => 1, 'po_number' => 'PO-PT-2', 'fk_id_currency' => Buy::currencyId('USD'), 'status' => 'CONFIRMED', 'f_active' => 1]));
        $fc = RecordService::save('t_src_freight_costs', ['fk_id_po' => $po, 'cost_type' => 'FREIGHT', 'amount' => 1000, 'fk_id_currency' => Buy::currencyId('USD'), 'cost_date' => '2026-11-05']);
        $row = DB::table('t_src_freight_costs')->where('id', $fc['id'])->first(); $this->assertSame([0.7812, 781.2], [(float) $row->exchange_rate, (float) $row->base_amount], 'an empty rate takes the table of the row date');
        Fx::set('JPY', '2026-12-01', 0.005); $this->expectException(\Illuminate\Validation\ValidationException::class);
        RecordService::save('t_src_freight_costs', ['fk_id_po' => $po, 'cost_type' => 'FREIGHT', 'amount' => 10, 'fk_id_currency' => Buy::currencyId('JPY'), 'cost_date' => '2026-11-05']);      // no rate on or before the date: never a guess
    }


    /** R-B_SOURCING-52 system values of the sourcing lists exist, cannot be deleted or moved, may be renamed; other values stay free. */
    public function test_r52_system_values_of_sourcing_lists_are_locked(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        \App\Services\SrcLists::ensure(1); DB::table('cat_src_lov')->where('fk_id_ent', 1)->where('lov_group', 'PO_STATUS')->delete();
        $this->assertSame(['PO_STATUS: Confirmed', 'PO_STATUS: Completed'], \App\Services\SrcLists::ensure(1));
        $this->assertSame([], \App\Services\SrcLists::ensure(1), 'a second run adds nothing');
        $id = (int) DB::table('cat_src_lov')->where('fk_id_ent', 1)->where('lov_group', 'PO_STATUS')->where('lov_value', 'Confirmed')->value('id');
        try { RecordService::save('cat_src_lov', ['f_active' => 0], $id); $this->fail(); } catch (\Illuminate\Validation\ValidationException $e) { $this->assertSame('This value is used by the rules – it cannot be deleted. You may rename it.', $e->errors()['f_active'][0]); }
        try { RecordService::save('cat_src_lov', ['lov_group' => 'RFQ_STATUS'], $id); $this->fail(); } catch (\Illuminate\Validation\ValidationException $e) { $this->assertStringContainsString('stays in its list', $e->errors()['f_active'][0]); }
        RecordService::save('cat_src_lov', ['lov_value' => 'Confirmed (supplier)'], $id); $this->assertSame('Confirmed (supplier)', DB::table('cat_src_lov')->where('id', $id)->value('lov_value'));
        $free = RecordService::save('cat_src_lov', ['lov_group' => 'PO_STATUS', 'lov_value' => 'On hold', 'lov_order' => 5, 'f_active' => 1])['id'];
        RecordService::save('cat_src_lov', ['f_active' => 0], $free); $this->assertSame(0, (int) DB::table('cat_src_lov')->where('id', $free)->value('f_active'));
    }

}
