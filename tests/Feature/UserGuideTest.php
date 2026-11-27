<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Owner 27-11 08:00: the user guide is built by itself – map, every screen with steps, every form and box, search; "?" opens exactly its place. */
class UserGuideTest extends TestCase
{
    public function test_guide_map_screen_form_box_and_search(): void
    {
        $this->actingAs($this->owner);
        $d = $this->getJson('/api/guide')->assertOk()->json();
        $this->assertSame(['Buy', 'Receive', 'Stock', 'Sell', 'Money', 'Partners'], array_column($d['map'], 'stage'));
        $this->assertNotEmpty($d['index']); $first = $d['index'][0]['sections'][0]['items'][0];
        $s = $this->getJson('/api/guide?s=' . rawurlencode($first['route']))->assertOk()->json('screen');
        $this->assertTrue($s['found']); $this->assertStringStartsWith('Open it: menu › ', $s['steps'][0]);
        $list = collect($d['index'])->pluck('sections')->flatten(1)->pluck('items')->flatten(1)->first(fn ($i) => str_starts_with($i['route'], '#/list/'));
        $s = $this->getJson('/api/guide?s=' . rawurlencode($list['route']))->assertOk()->json('screen');
        $this->assertNotEmpty($s['forms']); $this->assertContains(\App\Services\UserGuide::LIST_STEPS[0], $s['steps']);
        $f = $this->getJson('/api/guide?form=t_product_vendor&field=landing_price')->assertOk()->json('form');
        $box = collect($f['sections'])->flatten(1)->firstWhere('name', 'landing_price');
        $this->assertSame('Landing Price (product level)', $box['label']); $this->assertSame(1, count(\App\Services\UserGuide::form('t_product_vendor', 'landing_price')['sections']['Cost / Price'] ?? []));
        $r = $this->getJson('/api/guide?q=landing')->assertOk()->json('results');
        $this->assertContains('Box', array_column($r, 'kind'));
        $this->assertStringNotContainsString('vendor_password', json_encode($this->getJson('/api/guide?form=cat_vendors')->json()));      // secrets never in the guide
    }
}
