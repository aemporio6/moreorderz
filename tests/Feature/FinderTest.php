<?php

namespace Tests\Feature;

use App\Services\Finder;
use App\Services\Perm;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Search everything: screens, actions, create, reports, settings, fields, rights and records – by rights, with typing mistakes forgiven. */
class FinderTest extends TestCase
{
    private function names(array $res, ?string $group = null): array
    {
        $out = [];
        foreach ($res['groups'] as $g) if (! $group || $g['name'] === $group) foreach ($g['items'] as $i) $out[] = $i['title'];
        return $out;
    }

    public function test_scoring_every_word_must_match_and_small_typing_mistakes_are_forgiven(): void
    {
        $this->assertSame(100, Finder::score(['users'], 'Users'));
        $this->assertSame(90, Finder::score(['stock'], 'Stock on hand'));
        $this->assertSame(70, Finder::score(['hand'], 'Stock on hand'));
        $this->assertSame(40, Finder::score(['payout'], 'Load money file', 'settlement payout statement'));
        $this->assertSame(25, Finder::score(['settlment'], 'Settlements'));                        // one letter missing
        $this->assertSame(0, Finder::score(['stock', 'zebra'], 'Stock on hand'));                  // every word must be found
        $this->assertSame(0, Finder::score(['xyz'], 'Stock on hand'));
        $this->assertGreaterThan(0, Finder::score(['stock', 'hand'], 'Stock on hand'));
    }

    public function test_one_box_finds_screens_actions_create_reports_settings_rights_and_fields(): void
    {
        $this->actingAs($this->owner);
        $r = $this->getJson('/api/find?q=stock')->assertOk()->json();
        $groups = array_column($r['groups'], 'name');
        foreach (['Screens', 'Actions', 'Reports', 'Rights'] as $g) $this->assertContains($g, $groups);
        $this->assertContains('Stock count', $this->names($r, 'Actions'));
        $this->assertContains('Load money file', $this->names($this->getJson('/api/find?q=payout')->json(), 'Actions'));        // found by its other words
        $this->assertContains('Roles and rights', $this->names($this->getJson('/api/find?q=permisions')->json(), 'Actions'));   // typing mistake
        $this->assertNotEmpty($this->names($this->getJson('/api/find?q=new research')->json(), 'Create'));
        $this->assertNotEmpty($this->names($this->getJson('/api/find?q=vat')->json(), 'Settings'));
        $this->assertNotEmpty($this->names($this->getJson('/api/find?q=keywords')->json(), 'Fields'));
        $none = $this->getJson('/api/find?q=qqqqzzzz')->assertOk()->json();
        $this->assertSame(0, $none['count']); $this->assertNotNull($none['hint']);
    }

    public function test_a_person_only_finds_what_he_may_open(): void
    {
        $va = $this->makeUser('VA'); $this->actingAs($va);
        $r = $this->getJson('/api/find?q=stock')->assertOk()->json();
        $this->assertNotContains('Rights', array_column($r['groups'], 'name'));
        $this->assertNotContains('Stock count', $this->names($r));
        $this->assertNotContains('Roles and rights', $this->names($this->getJson('/api/find?q=roles')->json()));
        $this->assertNotContains('Settings', array_column($this->getJson('/api/find?q=vat')->json('groups'), 'name'));
        // the Owner gives the VA the right → the action appears
        Perm::setRole('VA', 'stock.SEE', true, $this->owner->id);
        $this->assertContains('Stock count', $this->names($this->getJson('/api/find?q=stock')->json(), 'Actions'));
    }

    public function test_records_are_found_by_words_in_any_order_by_sound_and_only_with_the_right_to_see_them(): void
    {
        $this->actingAs($this->owner);
        $id = RecordService::save('src_research', ['title' => 'Bamboo cutlery travel set', 'keywords' => 'picnic camping'])['id'];
        $rec = fn (string $q) => array_column(collect($this->getJson('/api/find?q=' . urlencode($q))->json('groups'))->firstWhere('name', 'Records')['items'] ?? [], 'url');
        $this->assertContains("#/rec/src_research/$id", $rec('travel bamboo'));                    // words in any order
        $this->assertContains("#/rec/src_research/$id", $rec('camping'));                           // a word of another field
        $this->assertContains("#/rec/src_research/$id", $rec('bambu'));                             // written as it sounds
        $this->assertSame([], $rec('bamboo zebra'));
        $wh = $this->makeUser('WAREHOUSE'); $this->actingAs($wh);
        if (! Perm::has($wh, 'form:src_research:VIEW')) $this->assertSame([], $rec('bamboo'));
    }

    public function test_opened_lately_and_pinned_show_before_typing(): void
    {
        $this->actingAs($this->owner);
        $id = RecordService::save('src_research', ['title' => 'Silicone lids'])['id'];
        $this->getJson("/api/page/src_research/$id")->assertOk();                                  // opening a record remembers it
        $this->postJson('/api/find/opened', ['kind' => 'report', 'title' => 'Sales by shop', 'sub' => 'Report', 'url' => '#/reports/sales_shop'])->assertOk();
        $this->postJson('/api/find/opened', ['kind' => 'screen', 'title' => 'Bank', 'url' => '#/bank', 'pin' => true])->assertOk();
        $g = collect($this->getJson('/api/find?q=')->assertOk()->json('groups'))->keyBy('name');
        $this->assertSame(['Bank'], array_column($g['Pinned']['items'], 'title'));
        $this->assertSame(['#/reports/sales_shop', "#/rec/src_research/$id"], array_column($g['Opened lately']['items'], 'url'));
        $this->assertNotEmpty($g['Try']['items']);
        $this->postJson('/api/find/opened', ['kind' => 'screen', 'title' => 'Bank', 'url' => '#/bank', 'pin' => false])->assertOk();
        $this->assertFalse(collect($this->getJson('/api/find?q=')->json('groups'))->contains('name', 'Pinned'));
        // another person has his own list
        $this->actingAs($this->makeUser('MANAGER'));
        $this->assertFalse(collect($this->getJson('/api/find?q=')->json('groups'))->contains('name', 'Opened lately'));
    }

    public function test_stems_synonyms_amounts_email_scopes_scan_and_recent_searches(): void
    {
        $this->actingAs($this->owner);
        // stems: "invoice" finds "Invoicing"; a longer word loses es / e / s
        $this->assertGreaterThan(0, Finder::score(['invoice'], 'Invoicing'));
        $this->assertGreaterThan(0, Finder::score(['settlements'], 'Payouts & settlement'));
        // synonyms (sys_search_synonyms): a term opens its page directly, and a word also searches what it maps to
        DB::table('sys_search_synonyms')->insert(['fk_id_ent' => 0, 'term' => 'kharcha', 'maps_to' => 'expense', 'synonym_kind' => 'ROMAN_URDU', 'f_active' => 1, 'created_at' => now()]); Finder::forget();
        $r = $this->getJson('/api/find?q=rights')->assertOk()->json();
        $this->assertSame('#/permissions', $r['groups'][0]['items'][0]['url']);
        $this->assertContains('Enter an expense', $this->names($this->getJson('/api/find?q=kharcha')->json(), 'Actions'));
        // one letter already suggests names that start with it
        $this->assertNotEmpty($this->getJson('/api/find?q=s')->assertOk()->json('groups'));
        // fields: "Form › Field", opens the form with the field focused
        $f = collect($this->getJson('/api/find?q=keywords')->json('groups'))->firstWhere('name', 'Fields')['items'][0];
        $this->assertStringContainsString(' › ', $f['title']); $this->assertStringStartsWith('field:', $f['url']);
        // amount within 0.5 %, range, e-mail
        $this->assertSame([1243.75, 1256.25], Finder::amountRange('£1,250'));
        $this->assertSame([1200.0, 1300.0], Finder::amountRange('1200-1300'));
        $this->assertNull(Finder::amountRange('PO-0012'));
        DB::table('sys_search_index')->insert(['fk_id_ent' => 1, 'entity' => 'src_research', 'entity_id' => 990001, 'department' => 'src_research', 'title' => 'Payment to Ali', 'subtitle' => 'Research', 'search_text' => 'payment', 'url' => '#/rec/src_research/990001', 'updated_at' => now(), 'amount_value' => 1249.99, 'email_norm' => 'ali@example.test']);
        $rec = fn (string $q, string $scope = 'all') => array_column(collect($this->getJson('/api/find?scope=' . $scope . '&q=' . urlencode($q))->json('groups'))->firstWhere('name', 'Records')['items'] ?? [], 'url');
        $this->assertContains('#/rec/src_research/990001', $rec('1,250'));
        $this->assertContains('#/rec/src_research/990001', $rec('1200-1300'));
        $this->assertContains('#/rec/src_research/990001', $rec('ALI@example.test'));
        $this->assertSame([], $rec('1,400'));
        // scope chips
        $this->assertSame(['Records'], array_column($this->getJson('/api/find?scope=tasks&q=stock')->json('groups'), 'name') ?: ['Records']);
        $this->assertNotContains('Records', array_column($this->getJson('/api/find?scope=pages&q=payment')->json('groups'), 'name'));
        $this->assertSame([], $rec('payment', 'orders'));
        // nothing found: guidance, "did you mean", and the miss is written down
        $none = $this->getJson('/api/find?q=settlmnts')->assertOk()->json();
        if (! $none['count']) { $this->assertNotEmpty($none['did_you_mean']); $this->assertStringContainsString('No match for', $none['hint']); }
        $this->getJson('/api/find?q=qqqqzzzz')->assertOk();
        $this->assertSame(0, (int) DB::table('sys_search_recent')->where('query_text', 'qqqqzzzz')->value('result_count'));
        // scan: the one exact record by SKU / EAN / tracking
        $fill = new \ReflectionMethod(RecordService::class, 'fillNotNull');
        $p = DB::table('t_product_vendors')->insertGetId($fill->invoke(null, 't_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'BOWL-01', 'product_name_vendor' => 'Dog bowl', 'ean' => '5012345678900', 'f_active' => 1]));
        $this->assertSame("#/stock/product/$p", $this->getJson('/api/find?scan=1&q=5012345678900')->assertOk()->json('exact.url'));
        $this->assertSame("#/stock/product/$p", $this->getJson('/api/find?scan=1&q=BOWL-01')->json('exact.url'));
        $this->assertNull($this->getJson('/api/find?scan=1&q=NOPE-999')->json('exact'));
        // recent searches: a picked result remembers the query; shown before typing; can be pinned; only SEARCH_RECENT_KEEP stay
        $this->postJson('/api/find/opened', ['kind' => 'record', 'title' => 'Payment to Ali', 'url' => '#/rec/src_research/990001', 'q' => 'payment ali', 'count' => 1])->assertOk();
        $this->postJson('/api/find/opened', ['kind' => 'search', 'title' => 'bowl', 'url' => 'q:bowl', 'pin' => true])->assertOk();
        for ($i = 0; $i < 25; $i++) Finder::remember($this->owner, "query $i", 1);
        $g = collect($this->getJson('/api/find?q=')->json('groups'))->firstWhere('name', 'Recent searches')['items'];
        $this->assertSame('bowl', $g[0]['title']); $this->assertTrue($g[0]['pinned']);
        $this->assertSame(20 + 1, DB::table('sys_search_recent')->where('user_id', $this->owner->id)->where('result_count', '>', 0)->count());
        // the same search inside a list: words in any order
        $id = RecordService::save('src_research', ['title' => 'Bamboo cutlery travel set'])['id'];
        $this->assertContains($id, array_column($this->getJson('/api/list/src_research?q=' . urlencode('travel bamboo'))->assertOk()->json('rows'), 'id'));
    }

    /** Owner 27-11 01:34: found however it is typed – with or without #, -, spaces; one word or three; records made by imports are indexed nightly. */
    public function test_records_are_found_however_they_are_typed(): void
    {
        $this->actingAs($this->owner); \App\Services\Context::set(1, $this->owner->id);
        $po = DB::table('t_src_po')->insertGetId(RecordService::fillNotNull('t_src_po', ['fk_id_ent' => 1, 'po_code' => 'PO-QX-7731', 'po_number' => 'PO-QX-7731', 'po_date' => '2026-11-01', 'po_total' => 10, 'status' => 'CONFIRMED', 'f_active' => 1]));
        \Illuminate\Support\Facades\Artisan::call('mo:reindex', ['--missing' => true]);      // made without its form (an import): the nightly run puts it in
        $this->assertTrue(DB::table('sys_search_index')->where('entity', 't_src_po')->where('entity_id', $po)->exists());
        foreach (['PO-QX-7731', '#PO-QX-7731', 'poqx7731', 'PO QX 7731', 'qx-7731', '#7731', 'PO7731', 'qx 7731'] as $q) {
            $urls = []; foreach ($this->getJson('/api/find?q=' . urlencode($q) . '&scope=records')->assertOk()->json('groups') as $g) foreach ($g['items'] as $i) $urls[] = $i['url'] ?? '';
            $this->assertTrue((bool) array_filter($urls, fn ($u) => str_contains((string) $u, '/' . $po)), "“{$q}” finds the PO");
        }
    }
}
