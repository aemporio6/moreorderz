<?php

namespace Tests\Feature;

use App\Http\Controllers\AppController;
use Tests\TestCase;

/** R-G_CORE-55 menu: a screen not built yet is a muted "Soon" item that only the Owner sees; R-G_CORE-60 top bar assets are served. */
class MenuSoonTest extends TestCase
{
    private function items(string $role): \Illuminate\Support\Collection
    {
        $this->actingAs($this->makeUser($role));
        return collect(AppController::menu($role))->flatMap(fn ($s) => $s['items']);
    }

    public function test_soon_items_owner_only(): void
    {
        $o = $this->items('OWNER');
        $soon = $o->where('soon', true);
        $this->assertGreaterThan(0, $soon->count(), 'unbuilt screens show as Soon for the Owner');
        foreach ($soon as $i) $this->assertStringStartsWith('#/screen/', $i['route']);
        $this->assertTrue($o->where('soon', false)->every(fn ($i) => ! str_starts_with($i['route'], '#/screen/')), 'built items are never Soon');
        foreach (['MANAGER', 'VA'] as $r) $this->assertSame(0, $this->items($r)->where('soon', true)->count(), "$r never sees Soon items");
    }

    public function test_ui_has_current_item_collapse_and_back_to_top(): void
    {
        $js = file_get_contents(public_path('app/app.js'));
        foreach (['function currentItem()', "'mo_side_collapsed_' + b.user.id", "t.id = 'toTop'", 'scrollY > 50', 'id="btnFull"', 'Soon</span>'] as $s) $this->assertStringContainsString($s, $js);
    }
}
