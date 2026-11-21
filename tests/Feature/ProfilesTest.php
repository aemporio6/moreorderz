<?php

namespace Tests\Feature;

use App\Services\CloseService;
use App\Services\Fulfilment;
use App\Services\MarketplaceFiles;
use App\Services\Profiles;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_A_SALES R-A_SALES-87: a new marketplace works by filling its profile – mappings, buckets, refund kinds, required-for-close – with no code change. */
class ProfilesTest extends TestCase
{
    private int $shop; private int $prof; private string $dir;

    private function world(): void
    {
        $this->actingAs($this->owner); $f = fn (string $t, array $d) => RecordService::fillNotNull($t, $d);
        $mp = DB::table('cat_marketplaces')->insertGetId($f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'Temu', 'marketplace_descr' => 'Temu', 'f_active' => 1]));
        $this->prof = DB::table('mp_profiles')->insertGetId($f('mp_profiles', ['fk_id_ent' => 1, 'code' => 'TEMU_' . uniqid(), 'name' => 'Temu UK', 'f_active' => 1, 'created_at' => now()]));
        $this->shop = DB::table('t_branches')->insertGetId($f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'fk_id_mp_profile' => $this->prof, 'shop_no' => '301T', 'shop_name' => 'Temu shop', 'iso_branch' => 'T1', 'f_active' => 1]));
        foreach ([['FULL', 'Full', 98, 100], ['HALF', 'Half', 48, 52]] as [$k, $l, $a, $b]) DB::table('mp_profile_refund_kinds')->insert(['fk_id_mp_profile' => $this->prof, 'kind_code' => $k, 'label' => $l, 'pct_from' => $a, 'pct_to' => $b, 'f_active' => 1]);
        foreach (['T-1', 'T-2'] as $no) DB::table('t_sales')->insert(RecordService::fillNotNull('t_sales', ['fk_id_ent' => 1, 'fk_id_branch' => $this->shop, 'fk_id_marketplace' => $mp, 'order_no' => $no, 'd_order' => '2026-07-05', 'cust_name' => 'Jo', 'sku' => 'SK-' . $no, 'qty' => 1, 'sale_rate' => 20, 'total_amount' => 20, 'total_amount_gbp' => 20, 'status' => 'Pending', 'sale_source' => 'unmatched', 'created_at' => now()]));
        $this->dir = sys_get_temp_dir() . '/mo_prof_' . uniqid(); mkdir($this->dir);
    }

    private function csv(string $name, array $rows): string { $p = "$this->dir/$name"; $h = fopen($p, 'w'); foreach ($rows as $r) fputcsv($h, $r, ',', '"', ''); fclose($h); return $p; }

    public function test_a_temu_profile_with_only_mappings_buckets_and_refund_kinds_imports_proposes_refunds_and_runs_the_close_checks(): void
    {
        $this->world();
        $file = $this->csv('temu.csv', [['Parent order SN', 'Funds batch', 'Trade time', 'Goods sale', 'Platform deduction', 'Refund deduction', 'Settled amount'],
            ['T-1', 'FB-77', '2026-07-10', '20.00', '2.00', '0', '18.00'], ['T-2', 'FB-77', '2026-07-12', '20.00', '2.00', '10.00', '8.00']]);
        try { MarketplaceFiles::import(1, $this->shop, $file, 'temu.csv', ['profile' => null, 'currency' => 'GBP']); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('No header line with an order number', $e->getMessage()); }      // the built-in names do not know Temu's columns

        // the owner fills the profile on the screen – no code
        Profiles::saveReport($this->prof, ['type' => 'Temu settlement', 'role' => 'MONEY', 'required' => true, 'map' => ['order' => 'Parent order SN', 'statement' => 'Funds batch', 'date' => 'Trade time', 'net' => 'Settled amount']], $this->owner);
        Profiles::saveReport($this->prof, ['type' => 'TEMU_ORDERS', 'role' => 'ORDERS', 'required' => false], $this->owner);
        foreach ([['GOODS', 'NET_SALES', 'Goods sale'], ['PLATFORM', 'MP_COMMISSION', 'Platform deduction'], ['REFUNDS', 'REFUND', 'Refund deduction']] as [$c, $b, $col]) Profiles::saveBucket($this->prof, ['bucket_code' => $c, 'book_as' => $b, 'file_columns' => $col], $this->owner);
        try { Profiles::saveBucket($this->prof, ['bucket_code' => 'X', 'book_as' => 'MP_ADS'], $this->owner); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('file column(s) or the words', $e->getMessage()); }
        try { Profiles::saveReport($this->prof, ['type' => 'BAD', 'role' => 'MONEY', 'map' => ['net' => 'Total']], $this->owner); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('needs the Order number column', $e->getMessage()); }

        $r = MarketplaceFiles::import(1, $this->shop, $file, 'temu.csv', ['currency' => 'GBP']);
        $this->assertSame(['Temu UK', true, 1], [$r['profile'], $r['all_ok'], $r['refund_proposals']]);
        $this->assertStringContainsString('Columns came from the marketplace profile "Temu UK"', collect($r['checks'])->pluck('text')->implode(' | '));
        $this->assertSame('TEMU_SETTLEMENT', DB::table('mo_settlements')->where('fk_id_branch', $this->shop)->value('report_type'));
        $this->assertSame(-4.0, (float) DB::table('fin_settlement_lines')->where('branch_id', $this->shop)->where('amount_type', 'MP_COMMISSION')->sum('amount'));
        $this->assertSame(26.0, (float) DB::table('mo_settlements')->where('fk_id_branch', $this->shop)->value('total'));
        $p = DB::table('t_sale_refund_proposals')->where('fk_id_branch', $this->shop)->first(); $this->assertSame(['HALF', 10.0], [$p->refund_kind, (float) $p->amount]);      // the profile's refund kinds decide Half

        $chk = collect(CloseService::evaluate(1, $this->shop, '2026-07')['checks'])->keyBy('code');
        $this->assertSame('NA', $chk['ORDERS_FILE']['status']); $this->assertStringContainsString('does not require an orders file', $chk['ORDERS_FILE']['detail']);
        $this->assertNotSame('NA', $chk['FILES_PRESENT']['status']);
        $this->assertTrue(Profiles::required($this->shop, 'MONEY')); $this->assertFalse(Profiles::required($this->shop, 'ORDERS'));
        $page = Profiles::page($this->prof, $this->owner); $this->assertSame(['MONEY', 'ORDERS'], array_column($page['reports'], 'role')); $this->assertSame('Parent order SN', $page['reports'][0]['map']['order']);
    }

    public function test_the_daily_orders_report_loads_into_the_queue_by_the_profile_columns(): void
    {
        $this->world();
        try { Fulfilment::importReport($this->shop, '/nope', 'a.csv', $this->owner); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('has no daily orders report', $e->getMessage()); }
        Profiles::saveReport($this->prof, ['type' => 'TEMU_DAILY', 'role' => 'FULFILMENT', 'required' => true, 'detect' => 'Order SN, Order time', 'map' => ['order_no' => 'Order SN', 'd_order' => 'Order time', 'sku' => 'Contribution SKU', 'qty' => 'Units', 'buyer_full_name' => 'Recipient', 'city' => 'Town', 'postcode' => 'Post code', 'country_code' => 'Country']], $this->owner);
        $file = $this->csv('daily.csv', [['Order SN', 'Order time', 'Contribution SKU', 'Units', 'Recipient', 'Town', 'Post code', 'Country'], ['D-1', '2026-11-02', 'SKU-A', '2', 'Ann Lee', 'Leeds', 'ls1 1aa', 'gb'], ['D-2', '2026-11-02', 'SKU-B', '1', 'Bob Day', 'York', 'YO1 1AA', 'GB'], ['D-3', '', 'SKU-C', '1', 'Cy', 'Hull', 'HU1', 'GB']]);
        $r = Fulfilment::importReport($this->shop, $file, 'daily.csv', $this->owner);
        $this->assertSame([2, 0, 1], [$r['new'], $r['already'], count($r['errors'])]); $this->assertStringContainsString('Columns came from the profile "Temu UK"', $r['message']);
        $s = DB::table('t_sales')->where('fk_id_branch', $this->shop)->where('order_no', 'D-1')->first(); $this->assertSame(['SKU-A', 2, 'LS1 1AA', 'GB', 'Ann Lee'], [$s->sku, (int) $s->qty, $s->ship_to_postcode, $s->ship_to_country_code, $s->cust_name]);
        $this->assertSame(1, (int) DB::table('t_sale_imports')->where('id', $s->fk_id_import)->value('f_daily'));      // into the daily batch of the shop + month
        $again = Fulfilment::importReport($this->shop, $file, 'daily.csv', $this->owner); $this->assertSame([0, 2], [$again['new'], $again['already']]);      // the same report twice: nothing doubled
        $bad = $this->csv('other.csv', [['Order SN', 'Something'], ['D-9', 'x']]); try { Fulfilment::importReport($this->shop, $bad, 'other.csv', $this->owner); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('Order time are missing', $e->getMessage()); }
    }
}
