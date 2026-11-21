<?php

namespace Tests\Feature;

use App\Services\FinanceExports;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_E_FINANCE R-FIN-33 accountant year export, R-FIN-36 stock valuation at a date, R-FIN-25 report status board. */
class FinanceExportsTest extends TestCase
{
    private int $bank;
    protected function setUp(): void { parent::setUp(); $this->actingAs($this->owner); $this->bank = DB::table('fin_bank_accounts')->insertGetId(['company_id' => 1, 'bank_name' => 'Wise', 'account_label' => 'GBP main', 'currency' => 'GBP', 'is_active' => 1]); }
    private function line(string $date, float $amt, array $x = []): int
    {
        return (int) DB::table('fin_bank_transactions')->insertGetId($x + ['batch_id' => 0, 'company_id' => 1, 'bank_account_id' => $this->bank, 'txn_date' => $date, 'description' => 'Line ' . $date, 'amount' => $amt, 'currency' => 'GBP', 'amount_gbp' => $amt, 'match_status' => 'UNMATCHED', 'bank_txn_id' => 'FE' . uniqid()]);
    }
    private function csv(string $body): array { $b = substr($body, 3); return array_map('str_getcsv', array_values(array_filter(explode("\n", $b), fn ($l) => trim($l) !== ''))); }

    public function test_r33_year_export_header_order_one_row_per_line_owner_or_accountant_only(): void
    {
        $a = $this->line('2026-02-01', -40, ['category_code' => 'OFFICE', 'match_status' => 'MANUAL', 'matched_by' => $this->owner->id, 'matched_to_type' => 'EXPENSE', 'matched_to_id' => 9, 'reference_no' => 'REF-1']);
        $this->line('2026-03-01', 100); $this->line('2026-04-01', -5, ['match_status' => 'IGNORED']); $this->line('2025-12-31', -1);
        $x = FinanceExports::ledger(1, '2026-01-01', '2026-12-31', 'csv', $this->owner);
        $this->assertSame('finance_ledger_1_2026.csv', $x['name']); $this->assertStringStartsWith("\xEF\xBB\xBF", $x['body'], 'UTF-8 BOM');
        $rows = $this->csv($x['body']);
        $this->assertSame(FinanceExports::LEDGER_COLS, $rows[0], 'the header in the spec order');
        $this->assertSame(['date', 'bank', 'description', 'counterparty', 'category', 'reference', 'amount', 'currency', 'amount_gbp', 'matched_to', 'proof_link', 'match_status', 'matched_by'], $rows[0]);
        $this->assertCount(3, array_slice($rows, 1), 'one row per bank line of the year, Ignored included');
        $r = $rows[1]; $this->assertSame(['2026-02-01', 'Wise GBP main', 'Office', 'REF-1', '-40', 'EXPENSE 9', 'MANUAL', $this->owner->display_name], [$r[0], $r[1], $r[4], $r[5], $r[6], $r[9], $r[11], $r[12]]);
        $this->assertStringEndsWith("/#/rec/" . RecordService::formOfTable('fin_bank_transactions') . "/$a", $r[10], 'proof link opens the line record (history + documents)');
        $this->assertTrue(DB::table('sys_audit_log')->where('action', 'EXPORT')->where('table_name', 'fin_bank_transactions')->exists(), 'audited');
        try { FinanceExports::ledger(1, '2026-01-01', '2026-12-31', 'csv', $this->makeUser('FINANCE')); $this->fail('Owner or Accountant only'); } catch (\InvalidArgumentException $e) { $this->assertSame('Refused: Owner or Accountant only.', $e->getMessage()); }
        $this->assertNotEmpty(FinanceExports::ledger(1, '2026-01-01', '2026-12-31', 'xlsx', $this->makeUser('ACCOUNTANT'))['body']);
        $this->get('/api/money/export/ledger?company=1&year=2026')->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="finance_ledger_1_2026.csv"');
    }

    public function test_r36_valuation_at_a_past_date_reverses_what_left_the_lot_later(): void
    {
        $lot = (int) DB::table('fin_lots')->insertGetId(RecordService::fillNotNull('fin_lots', ['company_id' => 1, 'sku' => 'VAL-1', 'source' => 'GRN', 'received_date' => '2026-01-10', 'qty' => 100, 'qty_left' => 70, 'unit_cost_gbp' => 2.5, 'created_at' => now()]));
        DB::table('t_stock_movements')->insert(RecordService::fillNotNull('t_stock_movements', ['fk_id_ent' => 1, 'fk_id_lot' => $lot, 'qty_change' => -30, 'movement_type' => 'sale_out', 'created_at' => '2026-02-20 10:00:00']));
        DB::table('fin_lots')->insert(RecordService::fillNotNull('fin_lots', ['company_id' => 1, 'sku' => 'VAL-LATE', 'source' => 'GRN', 'received_date' => '2026-03-05', 'qty' => 10, 'qty_left' => 10, 'unit_cost_gbp' => 1, 'created_at' => now()]));
        DB::table('fin_lots')->insert(RecordService::fillNotNull('fin_lots', ['company_id' => 1, 'sku' => 'VAL-GONE', 'source' => 'GRN', 'received_date' => '2026-01-01', 'qty' => 5, 'qty_left' => 0, 'unit_cost_gbp' => 1, 'created_at' => now()]));
        $at = fn ($d) => collect(FinanceExports::valuationRows(1, $d, $this->owner))->keyBy(0);
        $this->assertEquals(100, $at('2026-01-31')['VAL-1'][4], 'the rule example: 100 units on 31 Jan');
        $this->assertEquals(250.0, $at('2026-01-31')['VAL-1'][6]);
        $this->assertEquals(70, $at('2026-02-28')['VAL-1'][4]);
        $this->assertArrayNotHasKey('VAL-LATE', $at('2026-01-31')->all(), 'received after the date'); $this->assertArrayNotHasKey('VAL-GONE', $at('2026-02-28')->all(), '0 units left out');
        $x = FinanceExports::valuation(1, '2026-01-31', 'csv', $this->owner);
        $this->assertSame('stock_valuation_1_2026-01-31.csv', $x['name']); $this->assertSame(FinanceExports::VALUATION_COLS, $this->csv($x['body'])[0]);
        $this->get('/api/money/export/valuation?company=1&date=2026-01-31')->assertOk();
    }

    public function test_r25_board_settlement_older_than_17_days_and_bank_older_than_8_need_an_upload(): void
    {
        $s = (int) DB::table('t_branches')->insertGetId(RecordService::fillNotNull('t_branches', ['fk_id_ent' => 1, 'shop_no' => 'RB1', 'shop_name' => 'Board shop', 'iso_branch' => 'RB' . random_int(1000, 9999), 'f_active' => 1]));
        DB::table('mo_settlements')->insert(RecordService::fillNotNull('mo_settlements', ['fk_id_ent' => 1, 'fk_id_branch' => $s, 'statement_date' => '2026-11-01', 'created_at' => now()]));
        $this->line('2026-11-12', 10);
        $row = fn ($b, $type) => collect($b['rows'])->first(fn ($r) => $r['type'] === $type && ($type === 'BANK' ? str_contains($r['shop'], 'Wise') : $r['shop_id'] === $s));
        $b = FinanceExports::board(1, '2026-11-21');
        $this->assertSame(['needs upload', 20], [$row($b, 'SETTLEMENT')['state'], $row($b, 'SETTLEMENT')['days']], 'the rule example: last settlement 20 days ago');
        $this->assertSame('needs upload', $row($b, 'BANK')['state'], 'bank 9 days old');
        $b2 = FinanceExports::board(1, '2026-11-15');
        $this->assertSame(['up to date', 'up to date'], [$row($b2, 'SETTLEMENT')['state'], $row($b2, 'BANK')['state']]);
        $this->assertSame('nothing yet', $row($b, 'ORDERS')['state']);
        $this->getJson('/api/money/report-board')->assertOk()->assertJsonStructure(['rows', 'limits', 'needs_upload']);
    }
}
