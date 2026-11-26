<?php

namespace Tests\Feature;

use App\Services\DsMatch;
use App\Services\RecordService;
use App\Services\SaleLines;
use App\Services\SalesImport;
use App\Services\SaleView;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_A_SALES R-A_SALES-19 … 26: the 5-matching rule, own phone numbers, assignment, lock, money, reasons + CSV, Re-match, the explanation with masking. */
class DsMatchTest extends TestCase
{
    /** These tests are about their own rules; the shop scope by role (R-G_CORE-63, Settings › Choices OWN_SHOP_ROLES) is tested in ScopeSecureTest. */
    protected function setUp(): void { parent::setUp(); \App\Services\Choices::set('OWN_SHOP_ROLES', 'OWNER', 1); }
    private int $shop; private int $mp; private int $plat; private array $files = [];

    private function fill(string $t, array $d): array { return RecordService::fillNotNull($t, $d); }

    private function world(): void
    {
        $this->actingAs($this->owner);
        $this->mp = DB::table('cat_marketplaces')->insertGetId($this->fill('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'Ebay', 'marketplace_descr' => 'eBay', 'f_active' => 1]));
        $this->shop = DB::table('t_branches')->insertGetId($this->fill('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $this->mp, 'shop_no' => '101A', 'shop_name' => 'Pets', 'iso_branch' => 'P1', 'f_active' => 1, 'fulfilment_channel' => 'OTHER']));
        $this->plat = DB::table('drp_platforms')->insertGetId($this->fill('drp_platforms', ['platform_name' => 'AliExpress', 'f_active' => 1, 'created_at' => now()]));
        SalesImport::saveColumns($this->mp, 'SALE', ['order_no' => 'Order number', 'order_date' => 'Sale date', 'cust_name' => 'Buyer', 'cust_contacts' => 'Contacts', 'sku' => 'Custom label', 'product_info' => 'Item title', 'qty' => 'Quantity', 'sale_rate' => 'Sold for', 'total_amount' => 'Total']);
    }

    private function item(array $d): int
    {
        return DB::table('drp_items')->insertGetId($this->fill('drp_items', $d + ['fk_id_ent' => 1, 'fk_platform' => $this->plat, 'fk_id_marketplace' => $this->mp, 'fk_id_branch' => $this->shop, 'dup_key' => uniqid('k', true), 'order_no' => 'S-' . uniqid(), 'd_order' => '2026-07-04', 'item_title' => 'Thing', 'qty' => 1,
            'total_cost' => 5, 'currency' => 'GBP', 'period_month' => 7, 'period_year' => 2026, 'ship_name' => '', 'ship_address' => '', 'ship_city' => '', 'ship_postcode' => '', 'ship_phone' => '']));
    }

    private function import(array $lines, array $d = [], string $name = '101A_july26.csv'): array
    {
        $p = tempnam(sys_get_temp_dir(), 'ds'); $this->files[] = $p; $h = fopen($p, 'w'); fputcsv($h, ['Order number', 'Sale date', 'Buyer', 'Contacts', 'Custom label', 'Item title', 'Quantity', 'Sold for', 'Total'], ',', '"', '\\'); foreach ($lines as $l) fputcsv($h, $l, ',', '"', '\\'); fclose($h);
        return SalesImport::run($p, $name, filesize($p), $d + ['nature' => 'Normal', 'marketplace' => $this->mp, 'shop' => $this->shop, 'month' => 7, 'year' => 2026, 'fee' => '0'], $this->owner, true);
    }

    protected function tearDown(): void { foreach ($this->files as $f) @unlink($f); parent::tearDown(); }

    public function test_the_five_fields_with_the_example_of_the_rule(): void
    {
        // sale "John A Smith, 12 High St, Leeds, LS1 4AB" against drp "Smith John, Flat 2 12 High Street, Leeds, LS14AB" → name, address, town, postcode ✓, phone ✗ → 4 of 5
        $sale = ['name' => 'John A Smith', 'address' => null, 'city' => null, 'postcode' => null, 'phone' => null, 'contacts' => '12 High St, Leeds, LS1 4AB', 'address_text' => '12 High St, Leeds, LS1 4AB'];
        $it = (object) ['ship_name' => 'Smith John', 'ship_address' => 'Flat 2 12 High Street', 'ship_city' => 'Leeds', 'ship_postcode' => 'LS14AB', 'ship_phone' => '', 'ship_county' => '', 'ship_country' => ''];
        $this->assertSame(['name' => true, 'address' => true, 'city' => true, 'postcode' => true, 'phone' => false, 'n' => 4], DsMatch::score($sale, $it));
        // name: exact, any order, all words (2 or more) of the shorter inside the longer – one common word is not enough
        $this->assertTrue(DsMatch::nameMatch('Smith, John', 'john smith')); $this->assertTrue(DsMatch::nameMatch('Mary Anne Jones', 'Jones Mary')); $this->assertFalse(DsMatch::nameMatch('John', 'John Smith')); $this->assertFalse(DsMatch::nameMatch('John Smith', 'John Brown'));
        // city: whole word either way; in the contacts text only as a whole word
        $this->assertTrue(DsMatch::cityMatch('Leeds West', null, 'Leeds')); $this->assertTrue(DsMatch::cityMatch(null, 'flat 1, York, YO1', 'York')); $this->assertFalse(DsMatch::cityMatch(null, '5 Yorkshire Road', 'York')); $this->assertFalse(DsMatch::cityMatch('Leeds', null, ''));
        // postcode: compact form, optional spaces in free text, a house number never counts
        $this->assertTrue(DsMatch::postcodeMatch('ls1 4ab', null, 'LS14AB')); $this->assertTrue(DsMatch::postcodeMatch(null, 'Leeds L S1 4AB UK', 'LS1 4AB')); $this->assertFalse(DsMatch::postcodeMatch(null, 'house 12 road', '12')); $this->assertFalse(DsMatch::postcodeMatch(null, 'XLS14ABY', 'LS14AB'));
        $this->assertTrue(DsMatch::postcodeMatch('90210', null, '90210')); $this->assertFalse(DsMatch::postcodeOk('1234'));
        // phone: the last 9 digits
        $this->assertTrue(DsMatch::phoneMatch(null, 'call +44 7700 900123 please', '07700900123')); $this->assertFalse(DsMatch::phoneMatch(null, 'tel 12345', '07700900123')); $this->assertFalse(DsMatch::phoneMatch('07700900123', null, '07700 900123', ['700900123']));
        // address: first real comma part as whole words; else 2 or more tokens and 70 %
        $this->assertTrue(DsMatch::addressMatch('Flat 2, 12 High Street, Leeds', 'Mr X, 12 High Street, Leeds LS1', ['Leeds'])); $this->assertFalse(DsMatch::addressMatch('Leeds, LS1 4AB', 'Leeds LS1 4AB', ['Leeds', 'LS1 4AB']));
        $this->assertFalse(DsMatch::addressMatch('12 High Street', '14 Low Road', [])); $this->assertTrue(DsMatch::addressMatch('PO Box 5, 8 Mill Lane North', '8 mill ln north', []));
    }

    public function test_import_makes_drop_shipping_lines_with_the_money_of_the_rule_and_reasons_for_the_rest(): void
    {
        $this->world();
        DB::table('fin_fx_rates')->insert($this->fill('fin_fx_rates', ['currency' => 'USD', 'rate_date' => '2026-07-01', 'rate_to_gbp' => 0.80]));
        $a = $this->item(['ship_name' => 'Smith John', 'ship_address' => 'Flat 2 12 High Street', 'ship_city' => 'Leeds', 'ship_postcode' => 'LS14AB', 'total_cost' => 10.00, 'currency' => 'USD', 'd_order' => '2026-07-03']);
        $r = $this->import([['D-1', '05/07/2026', 'John A Smith', '12 High St, Leeds, LS1 4AB', 'EB-1', 'Gadget', '1', '14.99', '14.99'], ['D-2', '06/07/2026', 'Zed Nobody', '1 Far Way, Hull, HU1 1AA', '', 'Other', '1', '5.00', '5.00']]);
        $this->assertSame('2 sale line(s) imported: 0 active inventory, 1 drop shipping, 1 not matched.', $r['message']);
        $s = DB::table('t_sales')->where('order_no', 'D-1')->first();      // drp cost USD 10.00 at 0.80 → £8.00; total £14.99 → profit £6.99
        $this->assertSame(['ds', $a, 4, 'dropship', 8.0, 6.99, 0.0, 14.99, '2026-07-03'], [$s->sale_source, (int) $s->fk_drp_item, (int) $s->ds_match_count, $s->cost_source, (float) $s->buy_amount, (float) $s->profit, (float) $s->buy_rate, (float) $s->sale_rate, $s->d_buy]);
        $n = DB::table('t_sales')->where('order_no', 'D-2')->first();
        $this->assertSame(['unmatched', 'unmatched', 0.0, 0.0, 'No SKU; no drop shipping item matched (best 0 of 5, 3 needed)'], [$n->sale_source, $n->cost_source, (float) $n->buy_amount, (float) $n->profit, $n->ds_note]);
        $this->assertSame('0/5', $r['unmatched'][0]['best']); $this->assertCount(1, $r['unmatched']); $this->assertSame([8.0, 6.99], [$r['stats']['buy'], $r['stats']['profit']]);
        // the list: DS cell data, the strip counts the lines without cost, the export writes buyer + platform + DS qty
        $d = SaleLines::list(['batch' => $r['batch']], $this->owner); $row = collect($d['rows'])->firstWhere('order_no', 'D-1');
        $this->assertSame([4, '#20c997', 'Smith John', 'AliExpress', 1, null], [$row['ds']['n'], $row['ds']['colour'], $row['ds']['buyer'], $row['ds']['platform'], $row['ds']['qty'], $row['ds']['differs']]); $this->assertSame('best 0/5', collect($d['rows'])->firstWhere('order_no', 'D-2')['best']);
        $this->assertSame(1, $d['totals']['no_cost']); $this->assertSame("Smith John\nAliExpress\nDS qty: 1", SaleView::cell($row, 'investor')); $this->assertSame('Drop shipping (AliExpress)', SaleView::cell($row, 'visibility'));
        // edit of a DS line keeps the cost and forces rate = total
        SaleLines::edit((int) $s->id, ['qty' => 1, 'sale_rate' => 3, 'total_amount' => 20], true); $e = DB::table('t_sales')->where('id', $s->id)->first(); $this->assertSame([20.0, 20.0, 8.0, 12.0], [(float) $e->sale_rate, (float) $e->total_amount, (float) $e->buy_amount, (float) $e->profit]);
    }

    public function test_a_shop_without_supplier_orders_gets_the_warning(): void
    {
        $this->world(); $r = $this->import([['D-1', '05/07/2026', 'John Smith', 'Leeds', 'EB-1', 'Gadget', '1', '9', '9']]);
        $this->assertContains('Shop 101A has no Dropshipping Inventory items for July 2026, so no drop shipping line could be matched. Upload the drop shipping CSV for this shop and month, then use Re-match on the Sales page.', $r['warnings']);
        $this->assertSame(0, (int) DB::table('t_sales')->value('ds_match_count'));
    }

    public function test_own_phone_numbers_are_not_used(): void
    {
        $this->world(); foreach (['Ann One', 'Bob Two', 'Cy Three', 'Di Four', 'Ed Five'] as $n) $this->item(['ship_name' => $n, 'ship_phone' => '07700900123']);
        [$own, $auto] = DsMatch::ownPhones(DB::table('drp_items')->get()); $this->assertSame([['700900123'], 1], [$own, $auto]);
        $r = $this->import([['D-1', '05/07/2026', 'Ann One', 'tel 07700 900123', 'X', 'Thing', '1', '9', '9']]);
        $this->assertStringContainsString('1 phone number(s)', implode('|', $r['warnings'])); $this->assertStringContainsString('(your own number), so phone was not used', implode('|', $r['warnings']));
        $this->assertSame(1, (int) DB::table('t_sales')->value('ds_match_count'));      // only the name counts
        // numbers listed in the setting are always ignored too
        DB::table('drp_items')->delete(); \App\Services\Settings::set('DS_OWN_PHONES', '+44 7700 900999', 1); \App\Services\Settings::flush();
        $this->item(['ship_name' => 'Zoe', 'ship_phone' => '07700900999']); $this->assertSame(['700900999'], DsMatch::ownPhones(DB::table('drp_items')->get())[0]);
    }

    public function test_one_item_per_line_and_the_same_quantity_wins(): void
    {
        $this->world(); $base = ['ship_name' => 'Jane Buyer', 'ship_address' => '5 Oak Road', 'ship_city' => 'Derby', 'ship_postcode' => 'DE1 1AA'];
        $one = $this->item($base + ['qty' => 1]); $three = $this->item($base + ['qty' => 3]);
        $this->import([['J-1', '05/07/2026', 'Jane Buyer', '5 Oak Road, Derby, DE1 1AA', 'A', 'Thing', '3', '5', '15'], ['J-2', '05/07/2026', 'Jane Buyer', '5 Oak Road, Derby, DE1 1AA', 'B', 'Thing', '1', '5', '5']]);
        $this->assertSame([$three, $one], [(int) DB::table('t_sales')->where('order_no', 'J-1')->value('fk_drp_item'), (int) DB::table('t_sales')->where('order_no', 'J-2')->value('fk_drp_item')]);
        // a third line of the same buyer finds no free item; an item linked in another month's import is named
        $r = $this->import([['J-9', '03/08/2026', 'Jane Buyer', '5 Oak Road, Derby, DE1 1AA', 'C', 'Thing', '1', '5', '5']], ['month' => 8], '101A_aug26.csv'); DB::table('drp_items')->where('id', $one)->update(['period_month' => 8]);
        $m = DsMatch::rematch(['batch' => $r['batch']], $this->owner); $this->assertSame(0, $m['matched']);
        $this->assertSame('SKU not in inventory; its drop shipping item is already linked to order J-2 (Jul 2026 import)', DB::table('t_sales')->where('order_no', 'J-9')->value('ds_note'));
    }

    public function test_rematch_after_the_supplier_file_with_the_message_and_log_of_the_rule(): void
    {
        $this->world(); $lines = []; for ($i = 1; $i <= 10; $i++) $lines[] = ["R-$i", '05/07/2026', "Buyer Number$i", "$i Elm Street, Bath, BA$i 1ZZ", "SKU$i", 'Thing', '1', '10', '10'];
        $r = $this->import($lines); $this->assertSame(10, $r['stats']['unmatched']);
        $this->assertSame('Choose a marketplace first.', (function () { try { DsMatch::rematch([], $this->owner); } catch (\InvalidArgumentException $e) { return $e->getMessage(); } })());
        for ($i = 1; $i <= 8; $i++) $this->item(['ship_name' => "Buyer Number$i", 'ship_address' => "$i Elm Street", 'ship_city' => 'Bath', 'ship_postcode' => "BA$i 1ZZ"]);
        // the event of an applied supplier file runs Re-match by itself
        $m = DsMatch::afterDsBatch($this->shop, 7, 2026); $this->assertSame('8 of 10 not matched line(s) are now matched to drop shipping items. 2 still not matched.', $m['message']);
        $id = (int) DB::table('t_sales')->where('order_no', 'R-1')->value('id'); $log = DB::table('v_sale_change_log')->where('fk_id_sale', $id)->where('action', 'Re-match')->orderBy('id')->get();
        $this->assertSame(['Inventory', 'Drop shipping item', 'Buy Amount', 'Profit (recalculated)'], $log->pluck('field_label')->all()); $this->assertSame(['Not matched', 'Drop shipping (AliExpress)'], [$log[0]->old_value, $log[0]->new_value]); $this->assertStringEndsWith('of 5 columns matched', $log[1]->new_value);
        $this->assertSame('There are no Pending not matched lines in this view.', DsMatch::rematch(['batch' => $r['batch'], 'status' => 'Confirmed'], $this->owner)['message']);
        $again = DsMatch::rematch(['batch' => $r['batch']], $this->owner); $this->assertSame('0 of 2 not matched line(s) are now matched to drop shipping items. 2 still not matched.', $again['message']);
        $this->assertSame(8, DB::table('t_sales')->whereNotNull('fk_drp_item')->distinct()->count('fk_drp_item'));      // no item linked twice
        $this->postJson('/api/sales/rematch', ['batch' => $r['batch']])->assertOk()->assertJsonPath('still', 2);
    }

    public function test_the_lock_reports_busy_instead_of_going_on(): void
    {
        $this->world(); $name = 'ds_match_1';
        $other = new \PDO('mysql:host=' . config('database.connections.mariadb.host') . ';port=' . config('database.connections.mariadb.port') . ';dbname=' . config('database.connections.mariadb.database'), config('database.connections.mariadb.username'), config('database.connections.mariadb.password'));
        $this->assertSame('1', (string) $other->query("SELECT GET_LOCK('$name', 1)")->fetchColumn());
        try { DsMatch::locked(fn () => 1, 0); $this->fail('no error'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('busy', $e->getMessage()); }
        $other->query("SELECT RELEASE_LOCK('$name')"); $this->assertSame(7, DsMatch::locked(fn () => 7, 0));
    }

    public function test_not_matched_csv_name_columns_and_formula_guard(): void
    {
        $this->world(); $r = $this->import([['N-2', '06/07/2026', 'Bo', '=HYPERLINK("x")', 'S2', '+44 7700 900123', '1', '5', '5'], ['N-1', '05/07/2026', 'Al', '-12.50', 'S1', '@cmd', '1', '5', '5']]);
        $c = DsMatch::csv(['batch' => $r['batch']], $this->owner, '2026-08-02'); $this->assertSame('not_matched_101A_jul26_2026-08-02.csv', $c['name']); $this->assertStringStartsWith("\xEF\xBB\xBF", $c['body']);
        $rows = array_map(fn ($l) => str_getcsv($l, ',', '"', '\\'), array_filter(explode("\n", substr($c['body'], 3)))); $this->assertSame('Why not matched', end($rows[0])); $this->assertCount(10, $rows[0]);
        $this->assertSame('N-1', $rows[1][0]);      // sorted by order date
        $this->assertSame(["'=HYPERLINK(\"x\")", '+44 7700 900123', "'@cmd", '-12.50'], [$rows[2][3], $rows[2][5], $rows[1][5], $rows[1][3]]);
        $this->assertSame("'-abc", DsMatch::guard('-abc')); $this->assertSame('(12.50)', DsMatch::guard('(12.50)'));
        $this->assertSame('not_matched_sales_2026-08-02.csv', DsMatch::csv(['marketplace' => $this->mp, 'from' => '2026-07-01', 'to' => '2026-07-31'], $this->owner, '2026-08-02')['name']);
        $this->get('/api/sales/import/' . $r['batch'] . '/unmatched?format=csv')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    public function test_explanation_masks_the_supplier_side_and_link_by_hand(): void
    {
        $this->world(); $a = $this->item(['ship_name' => 'Smith John', 'ship_address' => '12 High Street', 'ship_city' => 'Leeds', 'ship_postcode' => 'LS14AB', 'ship_phone' => '07700 900123']);
        $r = $this->import([['D-1', '05/07/2026', 'John A Smith', '12 High St, Leeds, LS1 4AB', 'EB-1', 'Gadget', '1', '14.99', '14.99'], ['D-2', '06/07/2026', 'Other Person', 'Hull', 'EB-2', 'Gadget', '1', '5', '5']]);
        $id = (int) DB::table('t_sales')->where('order_no', 'D-1')->value('id'); $nm = (int) DB::table('t_sales')->where('order_no', 'D-2')->value('id');
        $x = DsMatch::explain($id, $this->owner); $this->assertSame('Drop shipping match: 4 of 5 columns (order D-1)', $x['title']); $this->assertSame('07700 900123', $x['rows'][4]['drp']); $this->assertSame(['not found', false], [$x['rows'][4]['sale'], $x['rows'][4]['match']]);
        $this->assertSame('At least 3 of the 5 columns must match. City, postcode and phone are looked for inside Cust. Contacts.', $x['note']);
        $va = \App\Models\User::find(DB::table('t_users')->insertGetId($this->fill('t_users', ['fk_id_ent' => 1, 'login_id' => 'va@demo.test', 'display_name' => 'V', 'role_code' => 'VA', 'f_active' => 1])));
        $m = DsMatch::explain($id, $va); $this->assertSame(['•••', '••••••123'], [$m['rows'][1]['drp'], $m['rows'][4]['drp']]); $this->assertSame('12 High St, Leeds, LS1 4AB', $m['rows'][1]['sale']);      // the sale side is not masked
        $this->assertSame('This is not a drop shipping line.', (function () use ($nm) { try { DsMatch::explain($nm, $this->owner); } catch (\InvalidArgumentException $e) { return $e->getMessage(); } })());
        // by hand: unlink with a reason, link again; a VA may not
        DsMatch::unlink($id, 'wrong buyer', $this->owner); $this->assertSame(['unmatched', null], [DB::table('t_sales')->where('id', $id)->value('sale_source'), DB::table('t_sales')->where('id', $id)->value('fk_drp_item')]);
        $this->assertSame($a, DsMatch::candidates($id, $this->owner)['rows'][0]['id']);
        try { DsMatch::link($id, $a, 'checked by phone', $va); $this->fail('no error'); } catch (\Throwable $e) { $this->assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $e); }
        DsMatch::link($id, $a, 'checked by phone', $this->owner); $this->assertSame('ds', DB::table('t_sales')->where('id', $id)->value('sale_source'));
        try { DsMatch::link($nm, $a, 'again', $this->owner); $this->fail('no error'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('already linked to order D-1', $e->getMessage()); }
        $this->assertContains('Link drop shipping', collect(SaleLines::history($id))->pluck('action')->all());
        $this->getJson("/api/sales/$id/match")->assertOk()->assertJsonPath('item.id', $a);
        DB::table('t_sales')->where('id', $id)->update(['fk_drp_item' => 999999]); $this->assertSame('The drop shipping item was deleted from Dropshipping Inventory.', DsMatch::explain($id, $this->owner)['gone']);
    }
}
