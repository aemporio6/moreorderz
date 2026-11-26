<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\RecordService;
use App\Services\Settings;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-G_CORE-102 consolidated "All companies (added up)" view for the Owner. */
class AllCompaniesTest extends TestCase
{
    private function f(string $t, array $d): int { return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }

    private function two(): array
    {
        $e2 = (int) (DB::table('t_enterprise')->where('id', '<>', 1)->where('f_active', 1)->value('id') ?: $this->f('t_enterprise', ['ent_name' => 'Second Co Ltd', 'ent_short' => 'SEC', 'f_active' => 1]));
        $mp = $this->f('cat_marketplaces', ['fk_id_ent' => 0, 'marketplace_short' => 'AllCo102', 'f_active' => 1]);
        $sale = function (int $ent, float $amt) use ($mp) {
            $shop = $this->f('t_branches', ['fk_id_ent' => $ent, 'fk_id_marketplace' => $mp, 'shop_no' => 'A' . $ent . substr(uniqid(), -5), 'iso_branch' => 'A' . $ent, 'shop_name' => 'Shop ' . $ent, 'f_active' => 1]);
            $this->f('t_sales', ['fk_id_ent' => $ent, 'fk_id_branch' => $shop, 'fk_id_marketplace' => $mp, 'order_no' => 'AC-' . uniqid(), 'd_order' => date('Y-m-05'), 'sku' => 'S', 'qty' => 1, 'total_amount' => $amt, 'profit' => 0, 'status' => 'Pending', 'sale_source' => 'active', 'f_locked' => 0, 'created_at' => now()]);
        };
        $sale(1, 1000); $sale($e2, 500);
        return [$e2, $mp];
    }

    public function test_g102_home_sales_and_lists_add_up_every_company_with_a_line_per_company(): void
    {
        [$e2, $mp] = $this->two();
        $this->actingAs($this->owner);
        $this->postJson('/api/company', ['ent' => 'all'])->assertOk()->assertJson(['ent' => 'all']);
        $this->assertTrue($this->getJson('/api/boot')->json('all_companies'));
        // the rule's test: sales 1,000 and 500 → Owner Home shows 1,500 with a line per company
        $card = collect($this->getJson('/api/home')->assertOk()->json('cards'))->firstWhere('label', 'Sales this month');
        $this->assertSame('1,500.00', $card['value']);
        $lines = collect($card['lines'])->pluck('value', 'company');
        $this->assertSame('1,000.00', $lines[DB::table('t_enterprise')->where('id', 1)->value('ent_name')]);
        $this->assertSame('500.00', $lines[DB::table('t_enterprise')->where('id', $e2)->value('ent_name')]);
        // the Sales list: rows of both companies, the company first, a total per company and the grand total
        $d = $this->getJson('/api/sales?' . http_build_query(['marketplace' => $mp, 'from' => date('Y-m-01'), 'to' => date('Y-m-t'), 'remember' => 0]))->assertOk()->json();
        $this->assertSame(1500.0, (float) $d['totals']['sale']);
        $this->assertCount(2, $d['rows']); $this->assertSame('company', array_key_first($d['rows'][0]));
        $per = collect($d['all_companies']['companies'])->pluck('figures', 'company_id');
        $this->assertSame(1000.0, (float) $per[1]['sale']); $this->assertSame(500.0, (float) $per[$e2]['sale']);
        // a generic list with a company column: every company's rows, the company as the first column, a total per company
        $l = $this->getJson('/api/list/' . RecordService::formOfTable('t_branches') . '?per_page=250')->assertOk()->json();
        $this->assertSame('company', $l['columns'][0]['name']);
        $this->assertEqualsCanonicalizing([1, $e2], array_values(array_intersect([1, $e2], array_column($l['all_companies']['companies'], 'company_id'))));
        // single company again: only its own figures
        $this->postJson('/api/company', ['ent' => 1])->assertOk();
        $this->assertSame('1,000.00', collect($this->getJson('/api/home')->json('cards'))->firstWhere('label', 'Sales this month')['value']);
        $this->assertNull($this->getJson('/api/list/' . RecordService::formOfTable('t_branches'))->json('all_companies'));
    }

    public function test_g102_create_asks_the_company_and_the_rights(): void
    {
        [$e2] = $this->two();
        $this->actingAs($this->owner); $this->postJson('/api/company', ['ent' => 'all'])->assertOk();
        // "+ Create PO" asks which company first
        $r = $this->postJson('/api/record/t_src_po', ['po_number' => 'X'])->assertStatus(409)->json();
        $this->assertTrue($r['choose_company']); $this->assertContains($e2, array_column($r['companies'], 'id'));
        // with the company chosen the write runs in that company only
        $this->withHeaders(['X-Company' => (string) $e2])->postJson('/api/nav', ['url' => '#/home', 'title' => 'Home'])->assertOk();
        $sup = $this->f('t_src_suppliers', ['fk_id_ent' => $e2, 'supplier_name' => 'Sup102', 'f_active' => 1]);
        $res = $this->withHeaders(['X-Company' => (string) $e2])->postJson('/api/record/t_src_po', ['fk_id_supplier' => $sup, 'po_date' => date('Y-m-d')]);
        if ($res->status() === 200) $this->assertSame($e2, (int) DB::table('t_src_po')->where('id', $res->json('id'))->value('fk_id_ent'));
        else $this->assertNotSame(409, $res->status(), 'with a company chosen the save is not asked again');
        $this->withHeaders(['X-Company' => '999999'])->postJson('/api/record/t_src_po', [])->assertStatus(403);
        // personal writes need no company
        $this->assertNotSame(409, $this->postJson('/api/bell/read', [])->status());
        // only the Owner (right company.all) may add up companies
        $m = $this->makeUser('MANAGER'); $this->actingAs($m)->postJson('/api/company', ['ent' => 'all'])->assertStatus(403);
    }

    public function test_g102_other_base_currency_is_converted_and_a_missing_rate_is_named(): void
    {
        [$e2] = $this->two();
        Settings::set('BASE_CURRENCY', 'USD', $e2);
        DB::table('fin_fx_rates')->where('currency', 'USD')->delete();
        $this->actingAs($this->owner); $this->postJson('/api/company', ['ent' => 'all'])->assertOk();
        $card = collect($this->getJson('/api/home')->json('cards'))->firstWhere('label', 'Sales this month');
        $this->assertSame('1,000.00', $card['value'], 'a company without a rate is not added – never at 1.0');
        $this->assertSame('rate missing', collect($card['lines'])->firstWhere('value', 'rate missing')['value']);
        $this->assertStringContainsString('rate missing', $card['foot']);
        DB::table('fin_fx_rates')->insert(RecordService::fillNotNull('fin_fx_rates', ['currency' => 'USD', 'rate_date' => date('Y-m-01', strtotime('-1 month')), 'rate_to_gbp' => 0.8]));
        $this->assertSame('1,400.00', collect($this->getJson('/api/home')->json('cards'))->firstWhere('label', 'Sales this month')['value'], '500 USD at 0.80 = 400 GBP');
        Settings::set('BASE_CURRENCY', 'GBP', $e2);
    }

    public function test_g102_tasks_of_every_company_once(): void
    {
        [$e2] = $this->two();
        $t = fn (int $ent, string $title) => DB::table('tasks')->insertGetId(RecordService::fillNotNull('tasks', ['fk_id_ent' => $ent, 'title' => $title, 'priority' => 'NORMAL', 'status' => 'TODO', 'created_at' => now(), 'source' => 'MANUAL', 'owner_user_id' => $this->owner->id, 'category_id' => 1, 'due_changes' => 0, 'evidence_required' => 0]));
        $a = $t(1, 'Task of company one'); $b = $t($e2, 'Task of company two'); $s = $t(0, 'Shared task 102');
        $this->actingAs($this->owner); $this->postJson('/api/company', ['ent' => 'all'])->assertOk();
        $ids = array_count_values(array_map('intval', array_filter(collect($this->getJson('/api/tasks?scope=all&chip=open')->json('tasks'))->pluck('id')->all())));
        foreach ([$a, $b, $s] as $id) $this->assertSame(1, $ids[$id] ?? 0, "task $id once");
        Context::set(1, $this->owner->id);
    }
}
