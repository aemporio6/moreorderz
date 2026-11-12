<?php

namespace Tests\Feature;

use App\Services\Ledger;
use App\Services\RecordService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MoneyHttpTest extends TestCase
{
    public function test_bank_expenses_and_approvals_through_the_api_with_roles(): void
    {
        $fin = $this->makeUser('FINANCE'); $mgr = $this->makeUser('MANAGER'); $wh = $this->makeUser('WAREHOUSE'); $acc = $this->makeUser('ACCOUNTANT');
        $profile = DB::table('bank_statement_profiles')->where('code', 'EX_BANK_SIGNED')->value('id');
        $this->actingAs($wh)->getJson('/api/bank')->assertStatus(403);
        $this->actingAs($acc)->getJson('/api/bank')->assertOk()->assertJsonPath('can_edit', false);
        $this->postJson('/api/bank', ['bank_name' => 'B', 'account_label' => 'Main', 'currency' => 'GBP'])->assertStatus(403);       // the accountant looks, does not change
        $bank = $this->actingAs($fin)->postJson('/api/bank', ['bank_name' => 'Bank One', 'account_label' => 'Main ••1234', 'currency' => 'GBP', 'opening_balance' => 500, 'opening_date' => '2026-08-01'])->assertOk()->json('id');
        $csv = "Date,Description,Reference,Amount,Balance,Transaction ID\n05/08/2026,SOFTWARE LTD,,-20.00,,T1\n06/08/2026,TO SAVINGS,,-100,,T2\n07/08/2026,OLD,,-1,,T3\n";
        $this->postJson("/api/bank/$bank/import", ['profile' => $profile, 'csv' => 'x,y'])->assertStatus(422);
        $this->postJson("/api/bank/$bank/import", ['profile' => $profile, 'csv' => $csv, 'file_name' => 'aug.csv'])->assertOk()->assertJsonPath('new', 3);
        $b = $this->getJson('/api/bank')->assertOk()->json();
        $this->assertSame([3, true], [$b['banks'][0]['unmatched'], $b['banks'][0]['explained']]);
        $id = fn ($t) => collect($b['lines'])->firstWhere('description', $t)['id'];
        $this->postJson('/api/bank-lines/' . $id('SOFTWARE LTD') . '/categorise', ['category' => 'SALES_PAYOUT'])->assertStatus(422);
        $this->postJson('/api/bank-lines/' . $id('SOFTWARE LTD') . '/categorise', ['category' => 'SOFTWARE'])->assertOk();
        $save = $this->postJson('/api/bank', ['bank_name' => 'Bank One', 'account_label' => 'Savings', 'currency' => 'GBP'])->json('id');
        $this->postJson('/api/bank-lines/' . $id('TO SAVINGS') . '/transfer', ['other' => $save])->assertOk();
        $this->postJson('/api/bank-lines/' . $id('OLD') . '/ignore', [])->assertStatus(422);
        $this->postJson('/api/bank-lines/' . $id('OLD') . '/ignore', ['reason' => 'Before opening'])->assertOk();
        $this->assertSame(0, $this->getJson('/api/bank')->json('banks.0.unmatched'));
        $this->assertSame(3, count($this->getJson('/api/bank?show=ALL')->json('lines')));
        $this->postJson('/api/bank-lines/' . $id('SOFTWARE LTD') . '/unmatch')->assertOk();
        $this->assertSame(0.0, Ledger::balance(1, '6000'));

        // expense over the limit waits; finance cannot approve; manager can
        $this->actingAs($wh)->postJson('/api/expenses', ['bank' => $bank, 'category' => 'OFFICE', 'amount' => 10, 'date' => '2026-08-10'])->assertStatus(403);
        $this->actingAs($fin)->postJson('/api/expenses', ['bank' => $bank, 'category' => 'OFFICE', 'amount' => 30, 'date' => '2026-08-10'])->assertOk()->assertJsonPath('status', 'BOOKED');
        $big = $this->postJson('/api/expenses', ['bank' => $bank, 'category' => 'PROFESSIONAL', 'amount' => 800, 'date' => '2026-08-10', 'note' => 'Accountant'])->assertOk()->assertJsonPath('status', 'WAITING')->json();
        $e = $this->getJson('/api/expenses?month=2026-08')->assertOk()->json();
        $this->assertSame([30.0, 'WAITING'], [(float) $e['total'], collect($e['rows'])->firstWhere('id', $big['id'])['status']]);
        $this->postJson("/api/approvals/{$big['approval']}", ['approve' => true])->assertStatus(403);
        $a = $this->actingAs($mgr)->getJson('/api/approvals')->assertOk()->json();
        $this->assertSame([1, true], [$a['waiting'], $a['rows'][0]['can_decide']]);
        $this->postJson("/api/approvals/{$big['approval']}", ['approve' => false])->assertStatus(422);
        $this->postJson("/api/approvals/{$big['approval']}", ['approve' => true])->assertOk();
        $this->assertSame(830.0, (float) $this->getJson('/api/expenses?month=2026-08')->json('total'));
        $this->postJson("/api/expenses/{$big['id']}/cancel", ['reason' => 'Wrong amount'])->assertOk();
        $this->assertSame(30.0, Ledger::balance(1, '6000'));
    }

    public function test_marketplace_file_upload_book_and_take_back_through_the_api(): void
    {
        $dir = getenv('MO_TT_FILES') ?: '/home/claude/rebuild/VCommerce_Build_Pack_v1.7/23_Test_Data/tiktok_settlement'; $file = "$dir/shopB_settlement_2026-08-03_2026-10-31.xlsx";
        if (! is_file($file)) $this->markTestSkipped('Owner test files are not on this machine (set MO_TT_FILES).');
        $f = fn ($t, $d) => DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
        $shop = $f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $f('cat_marketplaces', ['fk_id_ent' => 1]), 'shop_no' => 'TB', 'iso_branch' => 'TB', 'shop_name' => 'TikTok B', 'f_active' => 1]);
        $fin = $this->makeUser('FINANCE'); $acc = $this->makeUser('ACCOUNTANT');
        $up = fn () => new UploadedFile($file, 'shopB.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $this->actingAs($acc)->post('/api/settlements/import', ['shop' => $shop, 'file' => $up()], ['Accept' => 'application/json'])->assertStatus(403);
        $this->actingAs($fin)->post('/api/settlements/import', ['shop' => $shop, 'file' => UploadedFile::fake()->create('x.pdf', 5)], ['Accept' => 'application/json'])->assertStatus(422);
        $r = $this->post('/api/settlements/import', ['shop' => $shop, 'file' => $up()], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertSame([20, 'OK', 315.19], [$r['statements_new'], $r['hub_check'], (float) $r['report_total']]);
        $s = $this->getJson('/api/settlements')->assertOk()->json();
        $this->assertSame([20, 'OK', 4], [(int) $s['counts']['LOADED']['n'], $s['periods'][0]['hub_check'], count($s['periods'][0]['checks'])]);
        $this->postJson('/api/settlements/post-all', ['shop' => $shop])->assertOk()->assertJsonPath('booked', 20);
        $bal = collect($this->getJson('/api/settlements')->json('balances'))->firstWhere('shop_id', $shop);
        $this->assertSame([-310.09, 315.19], [(float) $bal['unsettled'], (float) $bal['on_the_way']]);
        $one = $s['rows'][0]['id'];
        $this->postJson("/api/settlements/$one/unpost")->assertOk();
        $this->postJson("/api/settlements/$one/unpost")->assertStatus(422);
        $this->postJson("/api/settlements/$one/post")->assertOk();
    }
}
