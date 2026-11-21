<?php

namespace Tests\Feature;

use App\Services\DueEngine;
use App\Services\RecordService;
use App\Services\TaskEngine;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_C_ACCOUNTS R-C-46: due sources point to real columns, every stored date reminds, one item per date, closes when the date moves. */
class DueEngineTest extends TestCase
{
    private int $acc;

    private function world(): void
    {
        $this->actingAs($this->owner);
        $this->acc = DB::table('t_account')->insertGetId(RecordService::fillNotNull('t_account', ['fk_id_ent' => 1, 'account_code' => 'D' . random_int(1000, 9999), 'first_name' => 'Asha', 'sur_name' => 'Khan', 'f_active' => 1]));
    }

    private function items(string $table): \Illuminate\Support\Collection { return DB::table('sys_due_items as i')->join('sys_due_sources as s', 's.id', '=', 'i.fk_id_source')->where('s.source_table', $table)->get(['i.*', 's.date_column']); }

    public function test_the_rule_example_sim_recharge(): void
    {
        $this->world(); $u = $this->makeUser('VA'); DB::table('t_users')->where('id', $u->id)->update(['fk_id_account' => $this->acc]);
        $sim = DB::table('t_account_mobiles')->insertGetId(RecordService::fillNotNull('t_account_mobiles', ['fk_id_account' => $this->acc, 'mobile_no' => '07700900123', 'l_recharge_contract' => 'R', 'next_recharge_date' => '2026-11-09', 'f_active' => 1]));
        $con = DB::table('t_account_mobiles')->insertGetId(RecordService::fillNotNull('t_account_mobiles', ['fk_id_account' => $this->acc, 'mobile_no' => '07700900999', 'l_recharge_contract' => 'C', 'next_recharge_date' => '2026-11-09', 'f_active' => 1]));
        DueEngine::run(1, '2026-11-06');
        $it = $this->items('t_account_mobiles'); $this->assertCount(1, $it); $this->assertSame([(int) $sim, 'Recharge SIM 07700900123', 'REMINDED', $u->id], [(int) $it[0]->record_id, $it[0]->title, $it[0]->status, (int) $it[0]->owner_user_id]);      // a Contract SIM gets no item
        $this->assertSame(1, DB::table('tm_notifications')->where('user_id', $u->id)->where('type', 'DUE')->where('text', 'like', 'Recharge SIM 07700900123%')->count());      // the reminder
        DueEngine::run(1, '2026-11-06'); $this->assertCount(1, $this->items('t_account_mobiles'));      // run again → no second item
        DueEngine::run(1, '2026-11-09'); $key = 't_account_mobiles:' . $sim . ':next_recharge_date:2026-11-09'; $this->assertTrue(TaskEngine::isOpen('ACC_DUE_ITEM', [$key]));      // on the date, not done → the task
        DB::table('t_account_mobiles')->where('id', $sim)->update(['next_recharge_date' => '2026-12-09']); DueEngine::run(1, '2026-11-10');
        $it = $this->items('t_account_mobiles')->first(); $this->assertSame(['DONE', 'date moved forward'], [$it->status, $it->done_how]); $this->assertFalse(TaskEngine::isOpen('ACC_DUE_ITEM', [$key]));
    }

    public function test_new_sources_titles_owners_recurring_and_company_hub(): void
    {
        $this->world(); $fin = $this->makeUser('FINANCE'); $own = (int) DB::table('t_users')->where('role_code', 'OWNER')->value('id');
        $bank = DB::table('t_account_banks')->insertGetId(RecordService::fillNotNull('t_account_banks', ['fk_id_account' => $this->acc, 'card_last4' => '4242', 'valid_upto' => '2026-12-01', 'f_active' => 1]));
        $cc = DB::table('t_account_credit_cards')->insertGetId(RecordService::fillNotNull('t_account_credit_cards', ['fk_id_account' => $this->acc, 'card_last4' => '1111', 'f_card_billing_payment_date' => '2026-11-10', 'f_active' => 1]));
        $mp = DB::table('cat_marketplaces')->insertGetId(RecordService::fillNotNull('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'Ebay', 'f_active' => 1]));
        $job = DB::table('t_account_job_history')->insertGetId(RecordService::fillNotNull('t_account_job_history', ['fk_id_account' => $this->acc, 'fk_id_marketplace' => $mp, 'd_contract_renewal_date' => '2026-12-01', 'salary_date' => '2026-11-08', 'salary' => 900]));
        $co = DB::table('cat_company_house')->insertGetId(RecordService::fillNotNull('cat_company_house', ['fk_id_ent' => 1, 'company_name' => 'Acme Ltd', 'iso_code' => 'ACM', 'f_active' => 1, 'confirmation_statement_date' => '2026-11-20', 'file_return_date' => '2026-11-25']));
        DB::table('acc_obligations')->insert(RecordService::fillNotNull('acc_obligations', ['fk_id_ent' => 1, 'entity_type' => 'COMPANY', 'entity_id' => $co, 'due_date' => '2026-11-25', 'title' => 'Accounts', 'status' => 'PENDING', 'created_at' => now()]));
        $r = DueEngine::run(1, '2026-11-06');
        $t = fn ($tbl, $col) => DB::table('sys_due_items as i')->join('sys_due_sources as s', 's.id', '=', 'i.fk_id_source')->where('s.source_table', $tbl)->where('s.date_column', $col)->first(['i.*']);
        $this->assertSame('Bank card ending 4242 expires', $t('t_account_banks', 'valid_upto')->title);      // 30 days before
        $this->assertSame(['Pay card ending 1111', $fin->id], [$t('t_account_credit_cards', 'f_card_billing_payment_date')->title, (int) $t('t_account_credit_cards', 'f_card_billing_payment_date')->owner_user_id]);
        $this->assertSame(['Renew contract – Asha Khan – Ebay', $own], [$t('t_account_job_history', 'd_contract_renewal_date')->title, (int) $t('t_account_job_history', 'd_contract_renewal_date')->owner_user_id]);
        $this->assertSame(['Pay salary – Asha Khan', $fin->id, 900.0], [$t('t_account_job_history', 'salary_date')->title, (int) $t('t_account_job_history', 'salary_date')->owner_user_id, (float) $t('t_account_job_history', 'salary_date')->amount]);
        $this->assertSame('Confirmation statement – Acme Ltd', $t('cat_company_house', 'confirmation_statement_date')->title);
        $this->assertNull($t('cat_company_house', 'file_return_date')); $this->assertSame(1, $r['skipped']);      // tracked in the Accountancy Hub: no double task
        // recurring card bill: marked done → the date moves one month on, the item closes
        $item = $t('t_account_credit_cards', 'f_card_billing_payment_date'); $this->actingAs($fin);
        $x = DueEngine::done(1, (int) $item->id, $fin); $this->assertSame('2026-12-10', $x['next']); $this->assertSame('2026-12-10', DB::table('t_account_credit_cards')->where('id', $cc)->value('f_card_billing_payment_date'));
        $this->assertSame('DONE', DB::table('sys_due_items')->where('id', $item->id)->value('status'));
        $exp = DB::table('fin_expenses')->insertGetId(RecordService::fillNotNull('fin_expenses', ['fk_id_ent' => 1, 'd_expense' => '2026-11-08', 'category_code' => 'SALARY', 'fk_id_payee' => $this->acc, 'amount' => 900, 'f_active' => 1, 'created_at' => now()]));
        DueEngine::run(1, '2026-11-08'); $this->assertSame('2026-12-08', DB::table('t_account_job_history')->where('id', $job)->value('salary_date'));      // salary booked → done, date + 1 month
        $this->assertStringContainsString("expense #$exp", (string) DB::table('sys_due_items')->where('id', $t('t_account_job_history', 'salary_date')->id)->value('done_how'));
        $l = DueEngine::list(1, $fin); $this->assertContains('Bank card ending 4242 expires', array_column($l['rows'], 'title')); $this->assertContains('Card bill', $l['chips']);      // Finance sees the card items
        $va = $this->makeUser('VA'); $this->assertSame([], DueEngine::list(1, $va)['rows']);
    }

    public function test_sources_point_to_real_columns(): void
    {
        foreach (DB::table('sys_due_sources')->where('f_active', 1)->get() as $s) {
            $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn($s->source_table, $s->date_column), "{$s->source_table}.{$s->date_column}");
            if ($s->where_rule) DB::table($s->source_table)->whereRaw('(' . $s->where_rule . ')')->count();      // every where rule runs
        }
        $this->assertFalse(DB::table('sys_due_sources')->where('source_table', 't_account_company_houses')->exists());
        $this->assertSame('ACC_SUBSCRIPTION_DUE', DB::table('sys_due_sources')->where('source_table', 't_account_subscriptions')->where('date_column', 'next_due_date')->value('task_code'));
    }
}
