<?php

namespace Tests\Feature;

use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** MR-1414 / 1258: one import book – every importer's files in one list with the breakdown by module, shop, source and kind. */
class ImportBookTest extends TestCase
{
    public function test_every_importer_shows_in_one_book(): void
    {
        $mp = (int) DB::table('cat_marketplaces')->insertGetId(RecordService::fillNotNull('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'Ebay', 'marketplace_descr' => 'eBay', 'f_active' => 1]));
        $shop = (int) DB::table('t_branches')->insertGetId(RecordService::fillNotNull('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => '9IB', 'shop_name' => 'Book shop', 'f_active' => 1]));
        DB::table('fin_import_batches')->insert(['report_type' => 'BANK_STATEMENT', 'company_id' => 1, 'branch_id' => null, 'file_name' => 'bank.csv', 'file_hash' => 'h1', 'rows_total' => 10, 'rows_new' => 8, 'rows_duplicate' => 2, 'rows_error' => 0, 'imported_at' => '2026-11-01 10:00:00']);
        DB::table('t_sale_imports')->insert(RecordService::fillNotNull('t_sale_imports', ['fk_id_ent' => 1, 'fk_id_branch' => $shop, 'fk_id_marketplace' => $mp, 'file_name' => 'sales.csv', 'period_month' => 10, 'period_year' => 2026, 'total_rows' => 5, 'saved_rows' => 4, 'error_rows' => 1, 'status' => 'Pending', 'imported_at' => '2026-11-02 10:00:00']));
        DB::table('drp_imports')->insert(RecordService::fillNotNull('drp_imports', ['fk_id_ent' => 1, 'fk_id_branch' => $shop, 'fk_id_marketplace' => $mp, 'file_name' => 'ds.csv', 'total_rows' => 3, 'inserted_rows' => 3, 'uploaded_at' => '2026-11-03 10:00:00']));
        DB::table('imp_batches')->insert(RecordService::fillNotNull('imp_batches', ['batch_code' => 'IMP-T1', 'fk_id_ent' => 1, 'fk_id_branch' => $shop, 'report_type' => 'AMZ_SETTLEMENT', 'source' => 'DRIVE', 'file_name' => 'settle.txt', 'rows_total' => 7, 'status' => 'SAVED', 'created_at' => '2026-11-04 10:00:00']));
        $b = $this->actingAs($this->owner)->getJson('/api/import-history')->assertOk()->json('book');
        $files = collect($b['rows'])->pluck('file')->all();
        foreach (['bank.csv', 'sales.csv', 'ds.csv', 'settle.txt'] as $f) $this->assertContains($f, $files);
        $this->assertSame('settle.txt', $b['rows'][0]['file']);      // newest first
        $mods = collect($b['by_module'])->keyBy('name');
        foreach (['Finance', 'Sales', 'Dropshipping'] as $m) $this->assertArrayHasKey($m, $mods->all());
        $this->assertSame(1, $mods['Sales']['problems']);
        $this->assertContains('Drive folder', collect($b['by_source'])->pluck('name')->all());
        $this->assertContains('Ebay · Book shop', collect($b['by_shop'])->pluck('name')->all());
        $this->assertNotEmpty($b['targets']);
    }
}
