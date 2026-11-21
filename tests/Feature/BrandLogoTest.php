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

    public function test_a_print_header_carries_the_logo(): void
    {
        $m = new \ReflectionMethod(\App\Services\PrintService::class, 'head'); $m->setAccessible(true);
        $this->assertStringContainsString('src="data:image/png;base64,', $m->invoke(null, 1, 'Pick sheet', 'PK-1'));
    }
}
