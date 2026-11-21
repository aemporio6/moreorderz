<?php

namespace Tests\Feature;

use App\Services\DocxWriter;
use App\Services\PrintService;
use App\Services\SaleView;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_G_CORE R-G_CORE-72: DOCX of lists (landscape, 1 cm margins, equal widths, header repeats, rows not split, refund / loss colours, no php-zip) and "PDF maker missing". */
class DocxExportTest extends TestCase
{
    private function part(string $docx, string $name): string
    {
        $p = tempnam(sys_get_temp_dir(), 'dz'); file_put_contents($p, $docx); $z = new \ZipArchive(); $this->assertTrue($z->open($p) === true); $x = $z->getFromName($name); $z->close(); @unlink($p);
        $this->assertNotFalse($x, "$name in the package"); return (string) $x;
    }

    public function test_the_rule_example_refunded_row_red_and_header_repeats(): void
    {
        $cols = [['key' => 'order_no', 'label' => 'Order No'], ['key' => 'qty', 'label' => 'Qty'], ['key' => 'total_amount', 'label' => 'Total']];
        $rows = []; $grid = [];
        for ($i = 1; $i <= 80; $i++) { $rows[] = ['status' => $i === 3 ? 'Refunded' : 'Completed', 'loss' => $i === 5 ? 2.5 : 0, 'kind' => 'S']; $grid[] = ["A-$i", '1', '9.99']; }
        $doc = $this->part(SaleView::docx('Sales', 'Ebay | 2 line(s)', [['Total qty', '80']], $cols, $grid, $rows), 'word/document.xml');
        $this->assertNotFalse(simplexml_load_string($doc));
        $this->assertStringContainsString('<w:tblHeader/>', $doc, 'the header row repeats on every page (80 rows run onto page 2)');
        $this->assertSame(81, substr_count($doc, '<w:cantSplit/>'), 'no row is split across pages');
        $this->assertSame(3, substr_count($doc, 'w:fill="F5B7B1"'), 'the refunded row is red (3 cells)');
        $this->assertSame(3, substr_count($doc, 'w:fill="FDECEA"'), 'the loss row (3 cells)');
        $this->assertStringContainsString('w:orient="landscape"', $doc);
        $this->assertStringContainsString('w:top="567" w:right="567" w:bottom="567" w:left="567"', $doc, 'margins 1 cm');
        $w = intdiv(DocxWriter::PAGE_W - 2 * 567, 3); $this->assertSame(3, substr_count($doc, "<w:gridCol w:w=\"$w\"/>"), 'equal column widths');
        $this->assertStringContainsString('<w:sz w:val="32"/>', $doc, 'title 16 pt');
        $this->assertStringContainsString('Total qty: 80', $doc);
        $empty = $this->part(SaleView::docx('Sales', 'none', [], $cols, [], []), 'word/document.xml');
        $this->assertStringContainsString('No sales match these filters.', $empty);
        $this->assertStringNotContainsString('ZipArchive', file_get_contents(app_path('Services/DocxWriter.php')), 'built without the php-zip extension');
    }

    public function test_list_export_as_word_and_pdf_maker_missing(): void
    {
        $this->actingAs($this->owner);
        $r = $this->get('/api/export/t_carriers?format=DOCX')->assertOk();
        $this->assertSame('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $r->headers->get('Content-Type'));
        $this->assertStringStartsWith('PK', $r->getContent());
        PrintService::$maker = 'Missing\\Dompdf';
        try { PrintService::pdf('<p>x</p>', 210, 297); $this->fail('PDF made without a maker'); }
        catch (\RuntimeException $e) { $this->assertSame('PDF maker missing', $e->getMessage()); }
        finally { PrintService::$maker = \Dompdf\Dompdf::class; }
        $this->assertTrue(DB::table('sys_error_log')->where('fingerprint', sha1('pdf_maker_missing'))->exists(), 'System › Health warning');
        $this->assertTrue(\App\Services\TaskEngine::isOpen('SYS_ERROR', ['pdf_maker_missing']));
    }
}
