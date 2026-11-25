<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-D-5 variation colour and size lists in the old order; a colour has a hex for its swatch. */
class VariantListsTest extends TestCase
{
    public function test_thirty_colours_eleven_sizes_and_the_swatch(): void
    {
        $c = DB::table('sys_field_options')->where('list_code', 'VARIANT_COLOUR')->where('f_active', 1)->orderBy('sort')->get(['value', 'hex']);
        $this->assertSame([30, 'Red', 'Black'], [$c->count(), $c[0]->value, $c[1]->value]);
        $this->assertSame('#722f37', $c->firstWhere('value', 'Wine Red')->hex);
        $this->assertSame('Aanghori', $c[13]->value, 'the old trailing space is trimmed');
        $s = DB::table('sys_field_options')->where('list_code', 'VARIANT_SIZE')->orderBy('sort')->pluck('label', 'value')->all();
        $this->assertSame(11, count($s)); $this->assertSame('Extra Small', $s['XS']); $this->assertSame('3x3', array_key_last($s));
        $this->assertFalse($c->contains(fn ($x) => empty($x->hex)), 'every colour paints a swatch');
    }
}
