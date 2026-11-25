<?php

namespace Tests\Feature;

use App\Services\Parse;
use App\Services\RecordService;
use App\Services\SalesImport;
use App\Services\SalesService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_A_SALES §1 (R-A_SALES-1 … 18) and R-D-78: the monthly sales import, with the examples of the rules. */
class SalesImportTest extends TestCase
{
    protected const MARKETPLACE = 'eBay';

    private int $shop; private int $mp; private int $product; private array $files = [];

    private function fill(string $t, array $d): array { return RecordService::fillNotNull($t, $d); }

    private function world(): void
    {
        $this->actingAs($this->owner);
        $this->mp = DB::table('cat_marketplaces')->insertGetId($this->fill('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => static::MARKETPLACE, 'marketplace_descr' => static::MARKETPLACE, 'f_active' => 1]));
        $this->shop = DB::table('t_branches')->insertGetId($this->fill('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $this->mp, 'shop_no' => '101A', 'shop_name' => 'Pets', 'iso_branch' => 'P1', 'f_active' => 1, 'fulfilment_channel' => 'OTHER']));
        DB::table('t_branches')->where('id', $this->shop)->update(['fk_id_mp_profile' => \App\Services\Profiles::ensureForMarketplace($this->mp)]);      /* R-H-60 / IC-0.10: eBay profile here, TikTok in the subclass */
        $this->product = DB::table('t_product_vendors')->insertGetId($this->fill('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'BOWL-01', 'product_name_vendor' => 'Dog bowl', 'f_active' => 1, 'raw_price' => 4, 'sale_price' => 9]));
        SalesImport::saveColumns($this->mp, 'SALE', ['order_no' => 'Order number', 'order_date' => 'Sale date', 'cust_name' => 'Buyer first name, Buyer last name', 'cust_contacts' => 'Buyer email,Buyer phone', 'sku' => 'Custom label', 'product_info' => 'Item title', 'qty' => 'Quantity', 'sale_rate' => 'Sold for', 'total_amount' => 'Total price']);
    }

    private const HEAD = 'Order number,Sale date,Buyer first name,Buyer last name,Buyer email,Buyer phone,Custom label,Item title,Quantity,Sold for,Total price';

    private function file(array $lines, string $head = self::HEAD): string
    {
        $p = tempnam(sys_get_temp_dir(), 'si'); file_put_contents($p, $head . "\n" . implode("\n", $lines) . "\n"); return $this->files[] = $p;
    }

    private function row(string $no, string $date = '05/07/2026', string $sku = 'BOWL-01', string $qty = '1', string $rate = '£9.00', string $total = '', string $title = 'Dog bowl'): string
    {
        return "$no,$date,Ann,Lee,a@b.com,,$sku,$title,$qty,$rate,$total";
    }

    private function go(string $path, array $d = [], bool $apply = true, string $name = '101A_july26.csv'): array
    {
        return SalesImport::run($path, $name, filesize($path), $d + ['nature' => 'Normal', 'marketplace' => $this->mp, 'shop' => $this->shop, 'month' => 7, 'year' => 2026, 'fee' => '12.50'], $this->owner, $apply);
    }

    protected function tearDown(): void { foreach ($this->files as $f) @unlink($f); parent::tearDown(); }

    public function test_pre_checks_run_in_order_each_with_its_own_message(): void
    {
        $this->world(); $ok = ['nature' => 'Normal', 'marketplace' => $this->mp, 'shop' => $this->shop, 'month' => 7, 'year' => 2026, 'fee' => '12.50']; $file = ['name' => 'a.csv', 'size' => 100];
        $msg = function (array $d, ?array $f = null) use ($file) { try { SalesImport::preCheck($d, $f ?? $file, $this->owner); return 'ok'; } catch (\InvalidArgumentException $e) { return $e->getMessage(); } };
        $this->assertSame('ok', $msg($ok));
        $this->assertSame("Manual import isn't available yet. Choose Normal sale.", $msg(['nature' => 'Manual'] + $ok));
        $this->assertSame('Select a marketplace.', $msg(['marketplace' => ''] + $ok));
        $this->assertSame('Select a shop.', $msg(['shop' => ''] + $ok));
        $this->assertSame('Enter the marketplace fee. Use 0 if there is no fee.', $msg(['fee' => ''] + $ok));
        $this->assertSame('ok', $msg(['fee' => '0'] + $ok));
        $this->assertSame('Marketplace fee must be a number with up to 2 decimals, for example 12 or 12.50.', $msg(['fee' => '12.505'] + $ok));
        $this->assertSame('The file must be a .csv file.', $msg($ok, ['name' => 'a.xlsx', 'size' => 5]));
        $this->assertSame('The file is larger than 20 MB.', $msg($ok, ['name' => 'a.csv', 'size' => 21 * 1024 * 1024]));
        DB::table('t_branches')->where('id', $this->shop)->update(['f_active' => 0]);
        $this->assertSame("Shop 101A (Pets) is inactive, so sales can't be imported for it. Activate the shop first, or choose another shop.", $msg($ok));
    }

    public function test_shop_and_month_come_from_the_file_name(): void
    {
        $this->world();
        $r = SalesImport::fileName('101A_july26.csv'); $this->assertSame([true, $this->shop, 7, 2026], [$r['ok'], $r['shop'], $r['month'], $r['year']]); $this->assertSame('Detected from file name 101A_july26 ✓', $r['detected']);
        $this->assertSame([9, 2025], array_values(array_intersect_key(SalesImport::fileName('101a_Sept-2025.csv'), ['month' => 1, 'year' => 1])));
        $this->assertFalse(SalesImport::fileName('orders.csv')['ok']);
        $r = SalesImport::fileName('101A_july26 (1).csv'); $this->assertSame([$this->shop, 7, 2026], [$r['shop'], $r['month'], $r['year']]);
        $this->assertSame('September 2025', SalesImport::fileName('202_Sept2025.csv')['month_name']); $this->assertArrayNotHasKey('month', SalesImport::fileName('X_ju26.csv'));      // only 2 letters → no month
        // two active shops with the same shop no: never guessed; the chosen marketplace narrows it
        $mp2 = DB::table('cat_marketplaces')->insertGetId($this->fill('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'Temu', 'marketplace_descr' => 'Temu', 'f_active' => 1]));
        $s2 = DB::table('t_branches')->insertGetId($this->fill('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp2, 'shop_no' => '101A', 'shop_name' => 'Toys', 'iso_branch' => 'P2', 'f_active' => 1]));
        $r = SalesImport::fileName('101A_july26.csv'); $this->assertArrayNotHasKey('shop', $r); $this->assertStringStartsWith('2 shops use shop no 101A: 101A (Pets), 101A (Toys). Select the right shop', $r['message']); $this->assertStringEndsWith('Month set to July 2026.', $r['message']);
        $this->assertSame($s2, SalesImport::fileName('101A_july26.csv', $mp2)['shop']);
        // the server warns when the choice differs from the file name; "I'm sure" continues
        $f = $this->file([$this->row('A-1', '05/06/2026')]);
        $r = $this->go($f, ['month' => 6], true, '101A_july26.csv'); $this->assertSame(['The file name says July 2026, but you selected June 2026'], $r['name_mismatch']); $this->assertSame(0, DB::table('t_sales')->count());
        $this->assertSame(1, $this->go($f, ['month' => 6, 'sure' => true], true, '101A_july26.csv')['stats']['saved']);
    }

    public function test_the_seller_id_inside_the_file_comes_before_the_file_name(): void
    {
        $this->world(); SalesImport::saveColumns($this->mp, 'SALE', ['order_no' => 'Order number', 'order_date' => 'Sale date', 'cust_name' => 'Buyer first name, Buyer last name', 'cust_contacts' => 'Buyer email,Buyer phone', 'sku' => 'Custom label', 'product_info' => 'Item title', 'qty' => 'Quantity', 'sale_rate' => 'Sold for', 'total_amount' => 'Total price', 'seller_id' => 'Seller ID']);
        $other = DB::table('t_branches')->insertGetId($this->fill('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $this->mp, 'shop_no' => '202B', 'shop_name' => 'Toys', 'iso_branch' => 'P2', 'f_active' => 1, 'fulfilment_channel' => 'OTHER', 'seller_id' => 'toys-uk']));
        DB::table('t_branches')->where('id', $this->shop)->update(['seller_id' => 'PETS-UK']); $h = self::HEAD . ',Seller ID'; $row = fn (string $no, string $sid) => $this->row($no) . ",$sid";
        $p = $this->file([$row('S-1', 'Toys-UK'), $row('S-2', 'toys-uk')], $h);
        // on the screen: the file is named for 101A, but the seller id says 202B – the seller id wins, the month still comes from the name
        $d = SalesImport::detect($p, '101A_july26.csv', null); $this->assertSame([$other, 'seller_id', 7, 2026], [$d['shop'], $d['source'], $d['month'], $d['year']]); $this->assertSame('Detected from seller id Toys-UK in the file ✓', $d['detected']); $this->assertStringContainsString('the seller id in the file wins', $d['note']);
        // on the server: choosing 101A for a file of 202B asks first
        $r = $this->go($p); $this->assertSame(["The seller id Toys-UK in the file belongs to shop 202B (Toys), but you selected shop 101A (Pets)"], $r['name_mismatch']); $this->assertSame(0, DB::table('t_sales')->count());
        // the file of 101A named for 202B: no question about the shop (the seller id matches), only about nothing else
        $p2 = $this->file([$row('S-3', 'pets-uk')], $h); $r = $this->go($p2, [], false, '202B_july26.csv'); $this->assertArrayNotHasKey('name_mismatch', $r); $this->assertStringContainsString('Seller id pets-uk in the file matches shop 101A (Pets) ✓', $r['detected']);
        // two seller ids in one file: not used, a warning; an unknown seller id: a warning, the file name decides
        $p3 = $this->file([$row('S-4', 'pets-uk'), $row('S-5', 'toys-uk')], $h); $this->assertStringContainsString('more than one seller id', SalesImport::detect($p3, '101A_july26.csv', $this->mp)['seller_note']); $this->assertSame($this->shop, SalesImport::detect($p3, '101A_july26.csv', $this->mp)['shop']);
        $p4 = $this->file([$row('S-6', 'nobody')], $h); $r = $this->go($p4, [], false); $this->assertStringContainsString('Seller id nobody in the file is not set on any of your shops', implode(' ', $r['warnings']));
        // without a Seller ID column the file name alone decides, as before
        $p5 = $this->file([$this->row('S-7')]); $this->assertSame([$this->shop, 'file_name'], [SalesImport::detect($p5, '101A_july26.csv', null)['shop'], SalesImport::detect($p5, '101A_july26.csv', null)['source']]);
    }

    public function test_mapping_joins_columns_and_names_a_missing_required_header(): void
    {
        $this->world();
        $r = $this->go($this->file([$this->row('A-1')])); $s = DB::table('t_sales')->where('order_no', 'A-1')->first();
        $this->assertSame(['Ann Lee', 'a@b.com'], [$s->cust_name, $s->cust_contacts]);      // joined with a space / empty phone left out
        try { $this->go($this->file(['B-1,05/07/2026,Ann,Lee,a@b.com,,BOWL-01,Dog bowl,£9.00,'], str_replace(',Quantity', '', self::HEAD))); $this->fail('no error'); }
        catch (\InvalidArgumentException $e) { $this->assertStringContainsString('These columns are missing from the CSV: Qty (Quantity)', $e->getMessage()); }
        // an optional column that is not in the file is a warning, not an error
        $r = $this->go($this->file(['C-1,05/08/2026,Ann,Lee,a@b.com,BOWL-01,Dog bowl,1,£9.00,'], str_replace(',Buyer phone', '', self::HEAD)), ['month' => 8], true, '101A_aug26.csv');
        $this->assertContains('Column not found for Cust. Contacts: Buyer phone', $r['warnings']);
        // Sale Columns not set → the import says which and where to set them
        DB::table('marketplace_sale_columns')->where('sale_field', 'qty')->update(['csv_columns' => '']);
        $pf = SalesImport::profileOf($this->mp); $sets = json_decode((string) $pf->sale_sets_json, true); $sets['SALE']['qty'] = []; DB::table('mp_profiles')->where('id', $pf->id)->update(['sale_sets_json' => json_encode($sets)]);      /* the profile holds the sets (R-A_SALES-87) */
        try { $this->go($this->file([$this->row('D-1')]), ['month' => 9, 'sure' => true]); $this->fail('no error'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('Sale Columns are not set for this marketplace: Qty', $e->getMessage()); }
    }

    public function test_r66_a_missing_header_gets_a_proposal_and_learning_it_reads_the_file(): void
    {
        $this->world();
        $head = str_replace(',Quantity', ',Units', self::HEAD);
        try { $this->go($this->file([$this->row('L-1')], $head)); $this->fail('no error'); }
        catch (\InvalidArgumentException $e) { $this->assertStringContainsString('"units" for Qty', $e->getMessage()); }
        $this->assertSame('qty', SalesImport::propose(['units', 'order number'], ['qty' => ['Quantity'], 'order_no' => ['Order number']])[0]['field']);
        $this->assertArrayHasKey('qty', SalesImport::FIELD_NOTES);
        $r = SalesImport::learn($this->mp, 'qty', 'Units'); $this->assertContains('Units', $r['columns']); $this->assertContains('Quantity', $r['columns']);
        $this->go($this->file([$this->row('L-1')], $head)); $this->assertTrue(DB::table('t_sales')->where('order_no', 'L-1')->exists());
        $u = $this->owner;
        $o = $this->actingAs($u)->postJson("/api/sales/columns/{$this->mp}/propose", ['line' => "Order number,Sale date,Buyer phone,Item price"])->json(); $this->assertIsArray($o['proposals'] ?? null);
        $this->assertArrayHasKey('qty', $this->actingAs($u)->getJson("/api/sales/columns/{$this->mp}")->json('notes'));
        try { SalesImport::learn($this->mp, 'nope', 'X'); $this->fail('no error'); } catch (\InvalidArgumentException $e) { $this->assertSame('Unknown field.', $e->getMessage()); }
    }

    public function test_reader_finds_the_header_under_a_preamble_with_semicolons_and_cp1252(): void
    {
        $this->world(); $p = tempnam(sys_get_temp_dir(), 'si'); $this->files[] = $p;
        file_put_contents($p, "Report;;\nSeller;x\n\n" . str_replace(',', ';', self::HEAD) . "\nE-1;05/07/2026;Ann;Lee;a@b.com;;BOWL-01;Dog bowl;1;\xA39.00;\n");
        $c = Parse::csv($p, ['Order number', 'Sale date', 'Quantity', 'Sold for', 'Custom label']); $this->assertStringContainsString('semicolon', $c['detected']); $this->assertStringContainsString('header on line 4', $c['detected']); $this->assertStringContainsString('1252', $c['detected']);
        $r = $this->go($p); $this->assertSame(1, $r['stats']['saved']); $this->assertSame(9.0, (float) DB::table('t_sales')->where('order_no', 'E-1')->value('sale_rate'));
    }

    public function test_row_rules_with_the_proof_line_of_the_rule(): void
    {
        $this->world(); $lines = [];
        for ($i = 1; $i <= 96; $i++) $lines[] = $this->row("R-$i");
        $lines[] = $this->row('R-97', '05/07/2026', 'BOWL-01', '1.5');      // qty not whole
        $lines[] = $this->row('R-1');                                        // duplicate in the file
        $lines[] = ',,,,,,,Total,,£864.00,'; $lines[] = ',,,,,,,,,,';        // two footers
        $check = $this->go($this->file($lines), [], false);
        $this->assertSame([96, 2, 2], [$check['stats']['saved'], $check['stats']['errors'], $check['stats']['skipped']]); $this->assertSame(0, DB::table('t_sales')->count()); $this->assertSame(0, DB::table('t_sale_imports')->count());      // Check keeps nothing
        $r = $this->go($this->file($lines));
        $this->assertSame([96, 2, 2], [$r['stats']['saved'], $r['stats']['errors'], $r['stats']['skipped']]); $this->assertTrue($r['proof']['ok']); $this->assertSame('file rows 100 = saved 96 + errors 2 + skipped 2', $r['proof']['text']);
        $this->assertSame('96 sale line(s) imported: 96 active inventory, 0 drop shipping, 0 not matched.', $r['message']);
        $reasons = array_column($r['errors'], 'reason'); $this->assertContains("Qty '1.5' is not a whole number above 0", $reasons); $this->assertStringContainsString('appears twice in this file (line 2)', implode('|', $reasons));
        $b = DB::table('t_sale_imports')->where('id', $r['batch'])->first(); $this->assertSame([100, 96, 2, 2], [(int) $b->total_rows, (int) $b->saved_rows, (int) $b->error_rows, (int) $b->skipped_rows]);      // the result equals the batch row counts
        $this->assertSame(96, DB::table('t_sales')->where('fk_id_import', $r['batch'])->count());
        $this->assertSame(2, count(SalesImport::errors($r['batch'])['rows'])); $this->assertSame('Lines not imported: 101A - July 2026', SalesImport::errors($r['batch'])['title']);
        // total derived from rate × qty; every line Pending, nothing deducted, no stock movement
        $s = DB::table('t_sales')->where('order_no', 'R-5')->first(); $this->assertSame([9.0, 'Pending', 0, 'active'], [(float) $s->total_amount, $s->status, (int) $s->f_stock_deducted, $s->sale_source]);
        $this->assertSame(0, DB::table('t_stock_movements')->count());
    }

    public function test_money_and_dates_of_the_rules(): void
    {
        $this->assertSame([1234.56, -12.5, 1234.0, 12.5], [Parse::money('£1.234,56'), Parse::money('(12.50)'), Parse::money('1,234'), Parse::money('12,5')]);
        $this->assertSame(['2026-07-05', '2026-07-13', '2026-07-05', '2026-07-05'], [Parse::date('05/07/2026'), Parse::date('13/07/2026'), Parse::date('Sat, 5th Jul 2026 14:02 BST'), Parse::date(46208)]);
        $this->assertFalse(Parse::date('32-Jul-26'));
    }

    public function test_no_sku_line_gets_a_stable_key_and_a_reimport_is_a_duplicate(): void
    {
        $this->world();
        $r = $this->go($this->file([$this->row('N-1', '05/07/2026', '', '1', '£5.00', '', 'Mystery item')]));
        $s = DB::table('t_sales')->where('order_no', 'N-1')->first(); $this->assertMatchesRegularExpression('/^NOSKU-[0-9a-f]{12}$/', $s->sku); $this->assertSame(['unmatched', 'No SKU; no drop shipping item matched (best 0 of 5, 3 needed)'], [$s->sale_source, $s->ds_note]);
        $this->assertSame(SalesImport::noSku('N-1', 'Mystery item', 1, 5.0), SalesImport::noSku('n-1', 'MYSTERY item', 1, 5.0));
        // the same line in the August file → duplicate error that names the month, not a second line
        $r2 = $this->go($this->file([$this->row('N-1', '05/07/2026', '', '1', '£5.00', '', 'Mystery item'), $this->row('Z-9', '03/08/2026')]), ['month' => 8], true, '101A_aug26.csv');
        $this->assertSame('Already imported in the Jul 2026 import', $r2['errors'][0]['reason']); $this->assertSame(1, DB::table('t_sales')->where('order_no', 'N-1')->count());
    }

    public function test_out_of_period_lines_are_kept_and_flagged_or_moved(): void
    {
        $this->world();
        $r = $this->go($this->file([$this->row('P-1', '30/06/2026'), $this->row('P-2', '02/07/2026')]));
        $this->assertSame(1, (int) DB::table('t_sale_imports')->where('id', $r['batch'])->value('out_of_period_rows')); $this->assertSame(1, (int) DB::table('t_sales')->where('order_no', 'P-1')->value('f_out_of_period')); $this->assertSame('1 lines dated outside July 2026', $r['out_of_period']['text']);
        SalesImport::deleteBatch($r['batch'], $this->owner);
        $r = $this->go($this->file([$this->row('P-1', '30/06/2026'), $this->row('P-2', '02/07/2026')]), ['out_mode' => 'move']);
        $june = SalesImport::batchOf($this->shop, 6, 2026); $this->assertNotNull($june); $this->assertSame((int) $june->id, (int) DB::table('t_sales')->where('order_no', 'P-1')->value('fk_id_import')); $this->assertSame(0, (int) DB::table('t_sales')->where('order_no', 'P-1')->value('f_out_of_period'));
        $this->assertSame([1, 1], [(int) $june->saved_rows, (int) DB::table('t_sale_imports')->where('id', $r['batch'])->value('saved_rows')]);
    }

    public function test_replace_asks_first_shows_the_diff_and_a_confirmed_month_is_refused(): void
    {
        $this->world(); $a = []; for ($i = 1; $i <= 12; $i++) $a[] = $this->row("M-$i");
        $first = $this->go($this->file($a));
        $ask = $this->go($this->file(array_slice($a, 0, 5)));
        $this->assertSame([12, 0, '101A_july26.csv'], [$ask['confirm_overwrite']['lines'], $ask['confirm_overwrite']['deducted'], $ask['confirm_overwrite']['file']]); $this->assertSame('Shop 101A (Pets) already has sales imported for July 2026.', $ask['message']);
        $this->assertSame(12, DB::table('t_sales')->count());      // "Keep" = nothing changes
        $r = $this->go($this->file(array_slice($a, 0, 5)), ['replace' => true]);
        $this->assertSame(5, DB::table('t_sales')->count()); $this->assertSame(['added' => 0, 'removed' => 7, 'changed' => 0, 'net' => -7], $r['diff']); $this->assertSame(12, (int) DB::table('t_sale_imports')->where('id', $r['batch'])->value('replaced_rows'));
        $this->assertSame(12, DB::table('mo_recycle_bin')->where('group_key', 'batch:' . $first['batch'])->count()); $this->assertSame(1, DB::table('t_sale_imports')->count());
        // confirmed → refused
        DB::table('t_sale_imports')->where('id', $r['batch'])->update(['status' => 'Confirmed']);
        try { $this->go($this->file($a), ['replace' => true]); $this->fail('no error'); } catch (\InvalidArgumentException $e) { $this->assertSame("Shop 101A (Pets) - July 2026 is confirmed and saved permanently, so it can't be imported again.", $e->getMessage()); }
    }

    public function test_daily_intake_lines_are_merged_never_doubled(): void
    {
        $this->world();
        $id = SalesService::intake($this->shop, [['order_no' => 'D-1', 'd_order' => '2026-07-04', 'sku' => 'BOWL-01', 'qty' => 1, 'sale_rate' => 9, 'cust_name' => 'Ann']])['ids'][0];
        $r = $this->go($this->file([$this->row('D-1', '04/07/2026'), $this->row('D-2', '04/07/2026')]));
        $this->assertSame([2, 1], [$r['stats']['saved'], $r['stats']['merged']]); $this->assertSame(1, DB::table('t_sales')->where('order_no', 'D-1')->count()); $this->assertSame($r['batch'], (int) DB::table('t_sales')->where('id', $id)->value('fk_id_import'));
        // a replace (and a delete of the import) never removes the line of the daily orders
        DB::table('t_sales')->where('id', $id)->update(['created_at' => now()->subDay()]);
        $r2 = $this->go($this->file([$this->row('D-2', '04/07/2026')]), ['replace' => true]); $this->assertTrue(DB::table('t_sales')->where('id', $id)->exists()); $this->assertSame(0, (int) DB::table('t_sales')->where('id', $id)->value('fk_id_import'));
        $r3 = $this->go($this->file([$this->row('D-1', '04/07/2026'), $this->row('D-2', '04/07/2026')]), ['replace' => true]); $this->assertSame(1, $r3['stats']['merged']);
        $del = SalesImport::deleteBatch($r3['batch'], $this->owner); $this->assertSame([1, 1], [$del['lines'], $del['kept']]); $this->assertTrue(DB::table('t_sales')->where('id', $id)->exists());
    }

    public function test_not_matched_lines_raise_one_task_and_the_fix_up_teaches_the_next_import(): void
    {
        $this->world(); DB::table('t_product_vendors')->insert($this->fill('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'GRP-2-BR', 'product_name_vendor' => 'Group bowl brown', 'f_active' => 1, 'raw_price' => 3]));
        $r = $this->go($this->file([$this->row('U-1', '05/07/2026', 'EBAY-XX', '1', '£7.00', '', 'Brown bowl pack'), $this->row('U-2', '06/07/2026', '', '1', '£7.00', '', 'Bowl without sku'), $this->row('U-3')]));
        $this->assertSame('3 sale line(s) imported: 1 active inventory, 0 drop shipping, 2 not matched.', $r['message']); $this->assertCount(2, $r['unmatched']);
        $task = DB::table('tasks')->where('dedupe_key', "SAL_UNMATCHED:{$this->shop}:2026-07")->first(); $this->assertSame('2 lines of 101A Jul 2026 not matched', $task->title); $this->assertSame(1, DB::table('tasks')->where('rule_code', 'SAL_UNMATCHED')->count());
        $this->assertSame('✗ SKU not found', SalesImport::skuCheck('NOPE')['message']); $this->assertSame('✓ Valid SKU', SalesImport::skuCheck('grp-2-br')['message']); $this->assertSame('GRP-2-BR', SalesImport::skuSearch('brown')[0]['sku']);
        $rows = SalesImport::unmatched($r['batch']); $this->assertSame(['EBAY-XX', ''], array_column($rows, 'sku'));
        $this->assertSame(1, SalesImport::link($rows[0]['id'], 'GRP-2-BR', $this->owner)['left']); $this->assertSame('TODO', DB::table('tasks')->where('id', $task->id)->value('status'));
        SalesImport::link($rows[1]['id'], 'GRP-2-BR', $this->owner); $this->assertSame('DONE', DB::table('tasks')->where('id', $task->id)->value('status'));      // the task closes by itself at 0
        $s = DB::table('t_sales')->where('order_no', 'U-1')->first(); $this->assertSame(['active', 3.0], [$s->sale_source, (float) $s->raw_price]);
        // next month: the same SKU text and the same title map by themselves
        $r2 = $this->go($this->file([$this->row('V-1', '05/08/2026', 'EBAY-XX', '1', '£7.00', '', 'Brown bowl pack'), $this->row('V-2', '06/08/2026', '', '1', '£7.00', '', 'Bowl without sku')]), ['month' => 8], true, '101A_aug26.csv');
        $this->assertSame([2, 0], [$r2['stats']['active'], $r2['stats']['unmatched']]);
    }

    public function test_schema_self_check_names_what_is_missing(): void
    {
        $this->assertSame([], SalesImport::selfCheck()); $this->assertNull(SalesImport::selfCheckMessage());
        $needs = new \ReflectionClassConstant(SalesImport::class, 'NEEDS'); $this->assertContains('sale_source', $needs->getValue()['t_sales']);
    }

    public function test_daily_totals_are_one_line_per_shop_and_date(): void
    {
        $this->world();
        $s2 = DB::table('t_branches')->insertGetId($this->fill('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $this->mp, 'shop_no' => '202', 'shop_name' => 'Home', 'iso_branch' => 'P3', 'f_active' => 1]));
        $p = tempnam(sys_get_temp_dir(), 'si'); $this->files[] = $p; file_put_contents($p, "Date,Sale,Purchase,Qty\n05/07/2026,100.00,60.00,10\n06/07/2026,50,30,5\n");
        $this->go($p, ['nature' => 'Totals']); $this->go($p, ['nature' => 'Totals', 'shop' => $s2], true, '202_july26.csv');
        $this->assertSame(2, DB::table('t_sales')->where('d_order', '2026-07-05')->where('sale_source', 'summary')->count());      // 101A 05-Jul and 202 05-Jul are two lines, not one
        $l = DB::table('t_sales')->where('fk_id_branch', $this->shop)->where('d_order', '2026-07-05')->first(); $this->assertSame([100.0, 60.0, 40.0], [(float) $l->total_amount, (float) $l->buy_amount, (float) $l->profit]);
        // the same day again (replace confirmed) updates the line, it does not add one
        file_put_contents($p, "Date,Sale,Purchase,Qty\n05/07/2026,120.00,60.00,10\n"); $this->go($p, ['nature' => 'Totals', 'replace' => true]);
        $this->assertSame(1, DB::table('t_sales')->where('fk_id_branch', $this->shop)->where('d_order', '2026-07-05')->count()); $this->assertSame(120.0, (float) DB::table('t_sales')->where('fk_id_branch', $this->shop)->where('d_order', '2026-07-05')->value('total_amount'));
    }

    public function test_register_lists_pending_months_and_the_grid_and_the_missing_month_task(): void
    {
        $this->world(); $m = (int) date('n', strtotime('first day of last month')); $y = (int) date('Y', strtotime('first day of last month')); $d = date('d/m/Y', mktime(0, 0, 0, $m, 5, $y)); $key = sprintf('%04d-%02d', $y, $m);
        $this->assertSame('Nothing imported yet.', SalesImport::register($this->owner)['empty']); $this->assertSame([], SalesImport::register($this->owner)['rows']);
        $r = $this->go($this->file([$this->row('G-1', $d)]), ['month' => $m, 'year' => $y], true, 'x.csv'); $reg = SalesImport::register($this->owner);
        $this->assertSame([1, '101A', 12.5], [$reg['rows'][0]['lines'], $reg['rows'][0]['shop_no'], $reg['rows'][0]['fee']]);
        $cell = fn ($reg) => collect($reg['grid'][0]['cells'])->firstWhere('key', $key)['status']; $this->assertSame('Pending', $cell($reg));
        DB::table('t_sale_imports')->where('id', $r['batch'])->update(['status' => 'Confirmed']); $reg = SalesImport::register($this->owner); $this->assertSame([], $reg['rows']); $this->assertSame('Confirmed', $cell($reg));      // leaves the Pending list, the cell turns green
        // a shop that imported before, with nothing for last month after day 5 → one task; the import closes it
        DB::table('t_sale_imports')->where('id', $r['batch'])->update(['period_year' => $y - 1]); DB::table('t_sales')->update(['d_order' => ($y - 1) . '-01-05']);
        $this->assertSame(0, SalesImport::missingMonths(date('Y-m-03'))); $this->assertSame(1, SalesImport::missingMonths(date('Y-m-09'))); $this->assertSame(0, SalesImport::missingMonths(date('Y-m-10')));
        $this->go($this->file([$this->row('G-2', $d)]), ['month' => $m, 'year' => $y], true, 'x.csv');
        $this->assertSame('DONE', DB::table('tasks')->where('dedupe_key', "SAL_MONTH_MISSING:{$this->shop}:$key")->value('status'));
    }

    public function test_an_import_can_be_undone_and_put_back(): void
    {
        $this->world(); $r = $this->go($this->file([$this->row('X-1'), $this->row('X-2')]));
        $del = SalesImport::deleteBatch($r['batch'], $this->owner); $this->assertSame(['Sales for July 2026 deleted.', 2], [$del['message'], $del['lines']]); $this->assertSame([0, 0], [DB::table('t_sales')->count(), DB::table('t_sale_imports')->count()]);
        SalesImport::restoreBatch($del['undo'], $this->owner); $this->assertSame([2, 1, 2], [DB::table('t_sales')->count(), DB::table('t_sale_imports')->count(), (int) DB::table('t_sale_imports')->value('saved_rows')]);
        DB::table('t_sale_imports')->update(['status' => 'Confirmed']);
        try { SalesImport::deleteBatch($r['batch'], $this->owner); $this->fail('no error'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('confirmed and saved permanently', $e->getMessage()); }
    }

    public function test_rights_and_the_endpoints(): void
    {
        $this->world();
        $this->getJson('/api/sales/import')->assertOk()->assertJsonPath('empty', 'Nothing imported yet.')->assertJsonPath('shops.0.name', '101A · Pets');
        $this->getJson('/api/sales/import/name?name=101A_july26.csv')->assertOk()->assertJsonPath('month', 7);
        $this->getJson("/api/sales/columns/{$this->mp}")->assertOk()->assertJsonPath('columns.qty', 'Quantity');
        $this->postJson("/api/sales/columns/{$this->mp}", ['set' => 'SALE', 'columns' => ['order_no' => ' Order number ,, order number', 'order_date' => 'Sale date', 'sku' => 'Custom label', 'qty' => 'Quantity', 'sale_rate' => 'Sold for']])->assertOk();
        $this->assertSame('Order number', DB::table('marketplace_sale_columns')->where('sale_field', 'order_no')->value('csv_columns'));      // trimmed, empties and repeats dropped
        $f = \Illuminate\Http\UploadedFile::fake()->createWithContent('101A_july26.csv', self::HEAD . "\n" . $this->row('H-1') . "\n");
        $this->post('/api/sales/import', ['file' => $f, 'marketplace' => $this->mp, 'shop' => $this->shop, 'month' => 7, 'year' => 2026, 'fee' => '0', 'apply' => 1], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('stats.saved', 1);
        $this->post('/api/sales/import', ['marketplace' => $this->mp, 'shop' => $this->shop, 'month' => 7, 'year' => 2026, 'fee' => '0'], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('message', 'Choose a CSV file to upload.');
        // a person without the right is refused with the shop named
        $va = \App\Models\User::find(DB::table('t_users')->insertGetId($this->fill('t_users', ['fk_id_ent' => 1, 'login_id' => 'pack@demo.test', 'display_name' => 'Packer', 'role_code' => 'WAREHOUSE', 'f_active' => 1])));
        try { SalesImport::preCheck(['nature' => 'Normal', 'marketplace' => $this->mp, 'shop' => $this->shop, 'month' => 7, 'year' => 2026, 'fee' => '0'], ['name' => 'a.csv', 'size' => 1], $va); $this->fail('no error'); }
        catch (\InvalidArgumentException $e) { $this->assertSame("You don't have rights to import sales for shop 101A (Pets).", $e->getMessage()); }
    }
    /** R-A_SALES-87 the profile's time zone and buy-note column are read by the import; its payout rule / money lag set the expected payout day. */
    public function test_profile_time_zone_buy_note_and_payout_rule(): void
    {
        $this->world();
        $pid = (int) DB::table('t_branches')->where('id', $this->shop)->value('fk_id_mp_profile');
        DB::table('mp_profiles')->where('id', $pid)->update(['timezone' => 'America/Los_Angeles', 'buy_note_field' => 'My item note']);
        \App\Services\Settings::set('COMPANY_TIMEZONE', 'Europe/London', 1);
        $head = self::HEAD . ',My item note';
        $this->go($this->file(["A-1,2026-07-30 23:30,Ann,Lee,a@b.com,,BOWL-01,Dog bowl,1,£9.00,,AMZ : 204-1 : 30-07-26 : 1 : 4.00", "A-2,2026-07-15,Ann,Lee,a@b.com,,BOWL-01,Dog bowl,1,£9.00,,"], $head));
        $d = fn ($o) => DB::table('t_sales')->where('order_no', $o)->first();
        $this->assertSame(['2026-07-31', 'AMZ : 204-1 : 30-07-26 : 1 : 4.00'], [substr((string) $d('A-1')->d_order, 0, 10), $d('A-1')->ds_note], '23:30 in Los Angeles is the next morning in London; the buy note comes from the profile\'s column');
        $this->assertSame('2026-07-15', substr((string) $d('A-2')->d_order, 0, 10), 'a date without a time stays');
        $p = DB::table('mp_profiles')->where('id', $pid)->first();
        $this->assertSame('2026-08-07', \App\Services\CashForecast::expected('2026-07-31', (object) ['money_lag_days' => null, 'payout_rule' => 'WEEKLY'], 3));
        $this->assertSame('2026-08-01', \App\Services\CashForecast::expected('2026-07-31', (object) ['money_lag_days' => null, 'payout_rule' => 'MONTHLY'], 3));
        $this->assertSame('2026-08-05', \App\Services\CashForecast::expected('2026-07-31', (object) ['money_lag_days' => 5, 'payout_rule' => 'WEEKLY'], 3), 'the money lag wins');
        $this->assertSame('2026-08-03', \App\Services\CashForecast::expected('2026-07-31', null, 3), 'no profile: the setting');
    }
}
