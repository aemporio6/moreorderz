<?php

namespace Tests\Feature;

use App\Services\SimpleWords as W;
use Tests\TestCase;

/** Owner 26-11: easy everyday words on every screen – one list, applied to what is shown, never to names in the database. */
class SimpleWordsTest extends TestCase
{
    public function test_hard_words_become_easy_words_and_codes_stay(): void
    {
        $this->assertSame('Supplier is needed.', W::apply('Vendor is required.'));
        $this->assertSame('Supplier stock', W::apply('Vendor Inventory')); $this->assertSame('SELLING SITE', W::apply('MARKETPLACE'));
        $this->assertSame('Courier option', W::apply('Carrier preset')); $this->assertSame('Waiting approval', W::apply('Pending approval'));
        $this->assertSame('Buying price / selling price', W::apply('Purchase price / sale price'));
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
        $this->assertStringContainsString('font-family:"Plus Jakarta Sans"', $css); $this->assertStringNotContainsString('fonts.googleapis', $css . file_get_contents(resource_path('views/app.blade.php')));
        preg_match_all('#url\("(/app/fonts/[^"]+)"\)#', $css, $m); $this->assertCount(2, $m[1]); foreach ($m[1] as $f) $this->assertFileExists(public_path(ltrim($f, '/')), 'the font file is inside the app – no outside link');
    }
}
