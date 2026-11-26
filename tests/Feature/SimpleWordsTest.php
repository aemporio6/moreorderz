<?php

namespace Tests\Feature;

use App\Services\SimpleWords as W;
use Tests\TestCase;

/** Owner 26-11: easy everyday words on every screen – one list, applied to what is shown, never to names in the database. */
class SimpleWordsTest extends TestCase
{
    public function test_hard_words_become_easy_words_and_codes_stay(): void
    {
        $this->assertSame('Supplier is required.', W::apply('Vendor is required.')); $this->assertSame('Supplier Inventory', W::apply('Vendor Inventory')); $this->assertSame('SUPPLIERS', W::apply('VENDORS'));
        $this->assertSame('Shop of the company', W::apply('Branch of the enterprise'));
        // owner 26-11 09:54: these words are right and stay exactly as written
        foreach (['Marketplace', 'Inventory', 'Stock', 'Carrier', 'Courier', 'Carrier preset', 'Pending', 'Waiting', 'Required', 'Purchase price', 'Quantity', 'Dispatch', 'Allocation'] as $keep) $this->assertSame($keep, W::apply($keep));
        // names of columns, links and parts of other words are not touched
        foreach (['marketplace_fee', '#/carriers', 'fk_id_vendor', 'sub-inventory', 'vendors.csv', 'Subvendor'] as $keep) $this->assertSame($keep, W::apply($keep));
        $this->assertSame('', W::apply(null));
        // no easy word is itself a hard word (the list never loops), and every key is lower case
        foreach (W::MAP as $hard => $easy) { $this->assertSame(strtolower($hard), $hard); $this->assertSame($easy, W::apply($easy), "\"$easy\" must already be easy"); }
    }

    public function test_the_screen_gets_the_same_list_and_the_font_is_bundled(): void
    {
        $this->actingAs($this->owner); $b = $this->getJson('/api/boot')->assertOk()->json();
        $this->assertSame(W::MAP, $b['words']);
        $css = (string) file_get_contents(public_path('app/design.css'));
        foreach (['font-family:"Inter"', 'font-family:"Manrope"', '--font:"Inter"'] as $k) $this->assertStringContainsString($k, $css);      // owner 26-11 21:02: Inter for text, Manrope for headings and the menu $this->assertStringNotContainsString('fonts.googleapis', $css . file_get_contents(resource_path('views/app.blade.php')));
        preg_match_all('#url\("(/app/fonts/[^"]+)"\)#', $css, $m); $this->assertCount(6, $m[1]); foreach ($m[1] as $f) $this->assertFileExists(public_path(ltrim($f, '/')), 'the font file is inside the app – no outside link');
    }
}
