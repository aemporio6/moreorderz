<?php

namespace Tests\Feature;

use App\Services\ListExports;
use App\Services\ListFilter;
use App\Services\Perm;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** List Standard: filters on every field (operators per type, AND / OR groups, presets, "(empty)"), totals row, values picker, column prefs, exports and export templates. */
class ListStandardTest extends TestCase
{
    private array $ids = [];

    private function fillRows(): void
    {
        $this->actingAs($this->owner);
        foreach ([['Bamboo cutlery', 'camping', 120, 5.5, 'GB'], ['Steel bottle', '', 300, 12, 'GB'], ['Silicone lids', 'kitchen', 80, 3, 'US'], ['Bamboo straws', 'kitchen', 500, 9.25, 'DE']] as [$t, $k, $u, $low, $c])
            $this->ids[$t] = RecordService::save('src_research', ['title' => $t, 'keywords' => $k, 'est_monthly_units' => $u, 'competitor_price_low' => $low, 'target_country' => $c])['id'];
    }

    private function titles(array $params): array
    {
        $r = $this->getJson('/api/list/src_research?' . http_build_query($params + ['sort' => 'title', 'dir' => 'asc', 'cols' => 'title,keywords,est_monthly_units,competitor_price_low']))->assertOk()->json();
        return array_column($r['rows'], 'title');
    }

    public function test_filter_rules_per_type_and_or_groups_and_the_words_of_a_filter(): void
    {
        $this->fillRows();
        $w = fn (array $rules, string $op = 'AND') => ['where' => json_encode(['op' => $op, 'rules' => $rules])];
        $this->assertSame(['Bamboo cutlery', 'Bamboo straws'], $this->titles($w([['f' => 'title', 'o' => 'starts', 'v' => 'bamboo']])));
        $this->assertSame(['Bamboo cutlery'], $this->titles($w([['f' => 'title', 'o' => 'is', 'v' => 'Bamboo cutlery']])));
        $this->assertSame(['Silicone lids', 'Steel bottle'], $this->titles($w([['f' => 'title', 'o' => 'not_contains', 'v' => 'bamboo']])));
        $this->assertSame(['Steel bottle'], $this->titles($w([['f' => 'keywords', 'o' => 'empty']])));
        $this->assertSame(['Bamboo straws', 'Steel bottle'], $this->titles($w([['f' => 'est_monthly_units', 'o' => 'gte', 'v' => '300']])));
        $this->assertSame(['Bamboo cutlery', 'Steel bottle'], $this->titles($w([['f' => 'est_monthly_units', 'o' => 'between', 'v' => ['100', '300']]])));
        $this->assertSame(['Bamboo straws', 'Silicone lids'], $this->titles($w([['f' => 'keywords', 'o' => 'in', 'v' => ['kitchen']]])));
        $this->assertSame(['Silicone lids', 'Steel bottle'], $this->titles($w([['f' => 'keywords', 'o' => 'in', 'v' => ['', 'kitchen']], ['f' => 'est_monthly_units', 'o' => 'lt', 'v' => 400]])));   // "" = (empty)
        // (title starts bamboo AND units > 200) OR keywords empty
        $this->assertSame(['Bamboo straws', 'Steel bottle'], $this->titles($w([['op' => 'AND', 'rules' => [['f' => 'title', 'o' => 'starts', 'v' => 'bamboo'], ['f' => 'est_monthly_units', 'o' => 'gt', 'v' => 200]]], ['f' => 'keywords', 'o' => 'empty']], 'OR')));
        // unknown columns / operators / unfinished rules are dropped, never an error
        $this->assertCount(4, $this->titles($w([['f' => 'password_hash', 'o' => 'is', 'v' => 'x'], ['f' => 'title', 'o' => 'DROP', 'v' => 'x'], ['f' => 'est_monthly_units', 'o' => 'gt', 'v' => 'abc'], ['f' => 'title', 'o' => 'contains', 'v' => '']])));
        $r = $this->getJson('/api/list/src_research?' . http_build_query($w([['f' => 'title', 'o' => 'starts', 'v' => 'bamboo'], ['f' => 'est_monthly_units', 'o' => 'gte', 'v' => '300']])))->json();
        $this->assertSame('Product idea starts with bamboo AND Estimated units sold per month ≥ 300', $r['where_text']);
        // quick filters: (empty), ^starts, =exact; created today by the date preset
        $this->assertSame(['Steel bottle'], $this->titles(['filter' => ['keywords' => '(empty)']]));
        $this->assertSame(['Bamboo cutlery', 'Bamboo straws'], $this->titles(['filter' => ['title' => '^Bamboo']]));
        $this->assertSame(['Steel bottle'], $this->titles(['filter' => ['title' => '=Steel bottle']]));
    }

    public function test_date_presets_and_from_to_in_the_address(): void
    {
        $this->assertSame(['2026-11-01', '2026-11-30'], ListFilter::preset('this_month', '2026-11-21'));
        $this->assertSame(['2026-10-01', '2026-10-31'], ListFilter::preset('last_month', '2026-11-21'));
        $this->assertSame(['2026-10-01', '2026-12-31'], ListFilter::preset('this_quarter', '2026-11-21'));
        $this->assertSame(['2026-11-16', '2026-11-22'], ListFilter::preset('this_week', '2026-11-21'));
        $this->assertSame(['2026-11-15', '2026-11-21'], ListFilter::preset('last_7', '2026-11-21'));
        $this->assertNull(ListFilter::preset('nope'));
        $this->actingAs($this->owner);
        $form = 't_src_po'; $date = null;
        foreach (\App\Services\Registry::fields('src_research') as $f) if ($f['type'] === 'date' && $f['column_name']) { $date = $f['column_name']; break; }
        if (! $date) { $this->markTestIncomplete('src_research has no date field'); }
        $a = RecordService::save('src_research', ['title' => 'Old idea', $date => '2026-01-10'])['id']; $b = RecordService::save('src_research', ['title' => 'New idea', $date => date('Y-m-d')])['id'];
        $ids = fn (array $p) => array_column($this->getJson('/api/list/src_research?' . http_build_query($p))->assertOk()->json('rows'), 'id');
        $this->assertSame([$b], $ids(['where' => json_encode(['op' => 'AND', 'rules' => [['f' => $date, 'o' => 'preset', 'v' => 'today']]])]));
        $this->assertSame([$a], $ids([$date . '_start' => '2026-01-01', $date . '_end' => '2026-01-31']));
        $this->assertSame([$a], $ids(['where' => json_encode(['op' => 'AND', 'rules' => [['f' => $date, 'o' => 'before', 'v' => '2026-02-01']]])]));
    }

    public function test_totals_row_counts_all_filtered_rows_and_the_values_picker_follows_the_other_filters(): void
    {
        $this->fillRows();
        $r = $this->getJson('/api/list/src_research?per_page=2&cols=title,est_monthly_units,competitor_price_low&filter[title]=Bamboo')->assertOk()->json();
        $this->assertSame(2, $r['total']); $this->assertEquals(620, $r['totals']['est_monthly_units']); $this->assertEquals(14.75, $r['totals']['competitor_price_low']);
        $all = $this->getJson('/api/list/src_research?per_page=2&cols=title,est_monthly_units')->json();
        $this->assertCount(2, $all['rows']); $this->assertEquals(1000, $all['totals']['est_monthly_units']);            // the total is of all 4 rows, not of the 2 on the page
        $this->assertSame(250, $this->getJson('/api/list/src_research?per_page=9999')->json('per_page'));
        $this->assertSame(100, $this->getJson('/api/list/src_research')->json('per_page'));
        $v = $this->getJson('/api/list-values/src_research?col=keywords')->assertOk()->json('values');
        $this->assertSame([['v' => '', 'label' => '(empty)', 'n' => 1], ['v' => 'camping', 'label' => 'camping', 'n' => 1], ['v' => 'kitchen', 'label' => 'kitchen', 'n' => 2]], $v);
        $v = $this->getJson('/api/list-values/src_research?col=keywords&filter[title]=Bamboo')->json('values');
        $this->assertSame(['camping', 'kitchen'], array_column($v, 'v'));
        $this->getJson('/api/list-values/src_research?col=nope')->assertStatus(404);
        $this->assertArrayHasKey('text', $this->getJson('/api/filter-meta')->assertOk()->json('ops'));
    }

    public function test_a_see_only_field_still_shows_in_the_list_and_a_hidden_one_cannot_be_filtered(): void
    {
        $this->fillRows(); $mgr = $this->makeUser('MANAGER');
        Perm::setField('MANAGER', null, 'src_research', 'keywords', 'READONLY', $this->owner->id);
        $this->actingAs($mgr);
        $this->assertContains('keywords', array_column($this->getJson('/api/list/src_research')->json('all_columns'), 'name'));
        Perm::setField('MANAGER', null, 'src_research', 'keywords', 'HIDDEN', $this->owner->id);
        $r = $this->getJson('/api/list/src_research?' . http_build_query(['where' => json_encode(['op' => 'AND', 'rules' => [['f' => 'keywords', 'o' => 'is', 'v' => 'camping']]])]))->json();
        $this->assertNotContains('keywords', array_column($r['all_columns'], 'name')); $this->assertNull($r['where']); $this->assertSame(4, $r['total']);
    }

    public function test_views_keep_filters_columns_widths_and_a_person_keeps_his_column_order_per_list(): void
    {
        $this->fillRows();
        $tree = ['op' => 'OR', 'rules' => [['f' => 'title', 'o' => 'starts', 'v' => 'bamboo']]];
        $id = $this->postJson('/api/views', ['page' => 'list:src_research', 'name' => 'Bamboo', 'state' => ['q' => '', 'sort' => 'title', 'dir' => 'asc', 'filter' => [], 'cols' => ['title', 'keywords'], 'where' => $tree, 'per' => 50, 'widths' => ['title' => 240, 'x;drop' => 5]], 'default' => true])->assertOk()->json('id');
        $v = collect($this->getJson('/api/views/list:src_research')->json('views'))->firstWhere('id', $id);
        $this->assertSame($tree, $v['state']['where']); $this->assertSame(50, $v['state']['per']); $this->assertSame(['title' => 240], $v['state']['widths']);
        $this->postJson('/api/prefs/list:src_research', ['cols' => ['keywords', 'title', 'bad name'], 'widths' => ['title' => 300, 'keywords' => 5], 'per' => 250])->assertOk();
        $this->postJson('/api/prefs/*', ['density' => 'compact'])->assertOk();
        $p = $this->getJson('/api/prefs/list:src_research')->assertOk()->json();
        $this->assertSame(['keywords', 'title'], $p['list']['cols']); $this->assertSame(['title' => 300], $p['list']['widths']); $this->assertSame(250, $p['list']['per']); $this->assertSame('compact', $p['all']['density']);
        $this->actingAs($this->makeUser('MANAGER'));
        $this->assertSame([], $this->getJson('/api/prefs/list:src_research')->json('list'));                           // another person has his own
        $this->assertSame(['keywords', 'title'], array_column($this->actingAs($this->owner)->getJson('/api/list/src_research?cols=keywords,title')->json('columns'), 'name'));   // the order asked is the order shown
    }

    public function test_export_in_csv_excel_pdf_follows_columns_order_and_filters(): void
    {
        $this->fillRows();
        $q = http_build_query(['cols' => 'keywords,title', 'sort' => 'title', 'dir' => 'asc', 'where' => json_encode(['op' => 'AND', 'rules' => [['f' => 'title', 'o' => 'starts', 'v' => 'bamboo']]])]);
        $csv = $this->get("/api/export/src_research?$q")->assertOk()->getContent();
        $lines = array_values(array_filter(explode("\n", trim(str_replace("\xEF\xBB\xBF", '', $csv)))));
        $this->assertCount(3, $lines); $this->assertStringStartsWith('camping,"Bamboo cutlery"', $lines[1]);
        $x = $this->get("/api/export/src_research?$q&format=XLSX")->assertOk(); $this->assertStringStartsWith('PK', $x->getContent()); $this->assertStringContainsString('.xlsx', $x->headers->get('Content-Disposition'));
        $tmp = tempnam(sys_get_temp_dir(), 'x') . '.xlsx'; file_put_contents($tmp, $x->getContent());
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp)->getActiveSheet()->toArray(); @unlink($tmp);
        $this->assertSame('Bamboo straws', $sheet[2][1]); $this->assertCount(3, $sheet);
        $this->assertStringStartsWith('%PDF', $this->get("/api/export/src_research?$q&format=PDF")->assertOk()->getContent());
        $this->assertCount(2, $this->getJson("/api/export/src_research?$q&format=JSON")->assertOk()->json('rows'));
        $this->actingAs($this->makeUser('VA'))->get('/api/export/src_research')->assertStatus(403);
    }

    public function test_saved_export_templates_private_shared_download_and_schedule(): void
    {
        $this->fillRows(); $mgr = $this->makeUser('MANAGER'); $fin = $this->makeUser('FINANCE');
        $this->postJson('/api/export-templates/src_research', ['name' => '', 'columns' => ['title']])->assertStatus(422)->assertJsonPath('message', 'Enter a name for your export.');
        $this->postJson('/api/export-templates/src_research', ['name' => 'Stock check', 'columns' => []])->assertStatus(422)->assertJsonPath('message', 'Select at least one field for the export.');
        $this->postJson('/api/export-templates/src_research', ['name' => 'Sched', 'columns' => ['title'], 'schedule' => 'DAILY'])->assertStatus(422);
        $state = ['where' => ['op' => 'AND', 'rules' => [['f' => 'title', 'o' => 'starts', 'v' => 'bamboo']]], 'sort' => 'title', 'dir' => 'asc'];
        $id = $this->postJson('/api/export-templates/src_research', ['name' => 'Stock check', 'columns' => ['est_monthly_units', 'title'], 'state' => $state, 'shared_role' => 'MANAGER', 'schedule' => 'DAILY', 'send_to' => 'boss@example.test'])->assertOk()->json('id');
        $t = $this->getJson('/api/export-templates/src_research')->assertOk()->json('templates.0');
        $this->assertSame(['est_monthly_units', 'title'], $t['columns']); $this->assertTrue($t['mine']); $this->assertMatchesRegularExpression('/^\d+(st|nd|rd|th), \w+ \d{4} \d\d:\d\d [AP]M$/', $t['created']);
        $res = $this->get("/api/export-template/$id/download")->assertOk();
        $this->assertMatchesRegularExpression('/filename="Stock check_\d{14}\.csv"/', $res->headers->get('Content-Disposition'));
        $lines = array_values(array_filter(explode("\n", trim($res->getContent())))); $this->assertCount(3, $lines); $this->assertStringEndsWith('"Bamboo cutlery"', trim($lines[1]));
        // shared with MANAGER: he sees and downloads it, FINANCE does not, and only the owner of the export removes it
        $this->actingAs($mgr); $this->assertCount(1, $this->getJson('/api/export-templates/src_research')->json('templates')); $this->get("/api/export-template/$id/download")->assertOk();
        $this->postJson("/api/export-template/$id/remove")->assertStatus(403);
        $this->postJson('/api/export-templates/src_research', ['name' => 'Mine', 'columns' => ['title'], 'shared_role' => 'VA'])->assertOk();           // a Manager may share
        if (Perm::has($fin, 'form:src_research:EXPORT')) { $this->actingAs($fin); $this->assertSame([], $this->getJson('/api/export-templates/src_research')->json('templates')); $this->get("/api/export-template/$id/download")->assertStatus(404); }
        // schedule: queued once per day as an e-mail with the file
        $this->assertSame(1, ListExports::runScheduled('2026-11-23')); $this->assertSame(0, ListExports::runScheduled('2026-11-23'));
        $mail = DB::table('tm_messages')->where('channel', 'EMAIL')->where('link_table', 'mo_export_templates')->where('link_id', $id)->first();      // one queue (R-G_CORE-46)
        $this->assertSame('boss@example.test', $mail->to_address); $this->assertStringContainsString("exports/$id/Stock check_", (string) $mail->attachment);
        DB::table('mo_export_templates')->where('id', $id)->update(['schedule' => 'WEEKLY', 'last_run_at' => null]);
        $this->assertSame(0, ListExports::runScheduled('2026-11-24')); $this->assertSame(1, ListExports::runScheduled('2026-11-30'));                   // Tuesday no, Monday yes
        $this->actingAs($this->owner)->postJson("/api/export-template/$id/remove")->assertOk();
    }
}
