<?php

namespace Tests\Feature;

use App\Services\Brand;
use App\Services\Settings;
use Tests\TestCase;

/** Owner answer 21 Nov 2026: the approved MoreOrderz seal on every A4 PDF; a company's own uploaded logo wins. */
class BrandLogoTest extends TestCase
{
    public function test_the_seal_is_used_and_an_uploaded_logo_wins(): void
    {
        $this->assertFileExists(base_path(Brand::SEAL));
        $this->assertStringStartsWith('data:image/png;base64,', Brand::dataUri(1));
        $this->assertStringContainsString('height:16mm', Brand::img(1, 16));
        $dir = storage_path('app/brand-test'); @mkdir($dir, 0777, true); $f = $dir . '/own.png'; copy(base_path(Brand::SEAL), $f);
        Settings::set('COMPANY_LOGO', 'brand-test/own.png', 1);
        $this->assertSame($f, Brand::file(1));
        Settings::set('COMPANY_LOGO', 'brand-test/missing.png', 1); $this->assertSame(base_path(Brand::SEAL), Brand::file(1), 'a missing file falls back to the seal');
        @unlink($f); @rmdir($dir);
    }

    public function test_every_a4_pdf_page_gets_the_watermark_but_labels_do_not(): void
    {
        $h = Brand::stamp('<html><body><h1>Pick sheet</h1></body></html>', 210, 297, 1);
        $this->assertStringContainsString('data-wm="1"', $h); $this->assertStringContainsString('position:fixed', $h, 'fixed = repeated on every page');
        $this->assertStringContainsString('alt="logo"', $h, 'a document without its own logo gets one on top');
        $this->assertSame(1, substr_count(Brand::stamp($h, 210, 297, 1), 'data-wm="1"'), 'never twice');
        $own = '<html><body>' . Brand::img(1, 15) . '<h1>PO</h1></body></html>'; $this->assertSame(1, substr_count(Brand::stamp($own, 210, 297, 1), 'alt="logo"'), 'its own header logo is kept, not doubled');
        $this->assertSame('<html><body>Label</body></html>', Brand::stamp('<html><body>Label</body></html>', 100, 150, 1), 'a 100 × 150 label stays clean');
        $this->assertStringStartsWith('%PDF', \App\Services\PrintService::pdf('<html><body><h1>x</h1></body></html>', 297, 210));
        $this->assertNotContains('LOCATION', \App\Services\PrintService::DOC_KINDS, 'A4 sticker sheets of bin labels stay clean');
        $this->assertContains('PICK_SHEET', \App\Services\PrintService::DOC_KINDS);
    }

    public function test_an_excel_export_carries_the_logo_and_keeps_its_data_in_row_1(): void
    {
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet(); $sh = $book->getActiveSheet(); $sh->fromArray([['SKU', 'Qty'], ['A-1', 3]]);
        Brand::xlsx($book, 1);
        $tmp = tempnam(sys_get_temp_dir(), 'bx'); (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($tmp);
        $zip = new \ZipArchive(); $zip->open($tmp); $media = []; for ($i = 0; $i < $zip->numFiles; $i++) if (str_starts_with($zip->getNameIndex($i), 'xl/media/')) $media[] = $zip->getNameIndex($i); $zip->close();
        $this->assertNotEmpty($media, 'the picture is inside the file');
        $back = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp)->getActiveSheet(); @unlink($tmp);
        $this->assertSame(['SKU', 'Qty'], [$back->getCell('A1')->getValue(), $back->getCell('B1')->getValue()], 'the data still starts in row 1 (the file can be imported again)');
        $this->assertSame('D1', $back->getDrawingCollection()[0]->getCoordinates(), 'top right, next to the table');
        $this->assertStringContainsString('Vartleaves Limited', $back->getHeaderFooter()->getOddHeader());
    }

    public function test_a_print_header_carries_the_logo(): void
    {
        $m = new \ReflectionMethod(\App\Services\PrintService::class, 'head'); $m->setAccessible(true);
        $this->assertStringContainsString('src="data:image/png;base64,', $m->invoke(null, 1, 'Pick sheet', 'PK-1'));
    }
}
