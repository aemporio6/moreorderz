<?php

namespace Tests\Feature;

use App\Services\Choices;
use App\Services\RecordService;
use App\Services\SaleLines;
use App\Services\SaleView;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Owner rule 22 Nov 03:47 – part C as Settings › Choices: MR-0412 / 0415 recycle bin days, MR-0418 bulk delete second approval, MR-0416 export rows. */
class ChoiceSalesTest extends TestCase
{
    public function test_recycle_bin_days_bulk_limit_and_export_rows(): void
    {
        $this->actingAs($this->makeUser('OWNER'));
        $bin = fn (int $daysAgo) => DB::table('mo_recycle_bin')->insertGetId(RecordService::fillNotNull('mo_recycle_bin', ['fk_id_ent' => 1, 'table_name' => 't_sales', 'row_id' => random_int(1, 999999), 'group_key' => 'G' . uniqid(), 'row_json' => '{}', 'deleted_at' => now()->subDays($daysAgo)]));
        $old = $bin(40); $mid = $bin(10);
        SaleLines::purgeBin();
        $this->assertSame([false, true], [DB::table('mo_recycle_bin')->where('id', $old)->exists(), DB::table('mo_recycle_bin')->where('id', $mid)->exists()], 'default 30 days');
        Choices::set('SALE_RECYCLE_DAYS', '7', 1); SaleLines::purgeBin();
        $this->assertFalse(DB::table('mo_recycle_bin')->where('id', $mid)->exists(), '7 days → the 10-day-old line goes too');
        $this->assertSame(500, SaleLines::bulkLimit()); Choices::set('SALE_BULK_APPROVAL_LINES', '100', 1); $this->assertSame(100, SaleLines::bulkLimit());
        $this->assertSame([20000, 1500], [SaleView::limit('csv'), SaleView::limit('pdf')]);
        Choices::set('SALE_EXPORT_MAX_ROWS', '50000', 1); $this->assertSame([50000, 50000, 1500], [SaleView::limit('csv'), SaleView::limit('xlsx'), SaleView::limit('pdf')], 'PDF keeps its own limit');
    }
}
