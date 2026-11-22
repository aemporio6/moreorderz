<?php

namespace Tests\Feature;

use App\Http\Controllers\AppController;
use App\Services\Finder;
use App\Services\MenuBuilder;
use App\Services\Perm;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-G_CORE-57 menu builder, R-G_CORE-99 search finds fields first + help topics, R-G_CORE-100 record buttons "Create PO for …" / "Print label for …". */
class MenuSearchTest extends TestCase
{
    public function test_r57_menu_builder_order_rename_role(): void
    {
        $sec = DB::table('sidebar_modules')->whereNull('parent_id')->where('e_status', 'ACTIVE')->orderBy('sort_order')->first();
        $items = DB::table('sidebar_modules')->where('parent_id', $sec->id_module)->where('e_status', 'ACTIVE')->orderBy('sort_order')->pluck('id_module')->map(fn ($x) => (int) $x)->all();
        $this->assertGreaterThan(1, count($items));
        $this->actingAs($this->makeUser('MANAGER'))->postJson('/api/menu-builder/order', ['parent' => $sec->id_module, 'ids' => $items])->assertStatus(422)->assertJsonPath('message', 'Refused: only the Owner can change the menu.');
        $this->actingAs($this->owner);
        $rev = array_reverse($items);
        $this->postJson('/api/menu-builder/order', ['parent' => $sec->id_module, 'ids' => $rev])->assertOk()->assertJsonPath('message', 'Order saved.');
        $this->assertSame($rev, DB::table('sidebar_modules')->where('parent_id', $sec->id_module)->where('e_status', 'ACTIVE')->orderBy('sort_order')->pluck('id_module')->map(fn ($x) => (int) $x)->all());
        $this->postJson('/api/menu-builder/order', ['parent' => null, 'ids' => [$items[0]]])->assertStatus(422)->assertJsonPath('message', 'A section and an item cannot swap places.');
        $this->postJson('/api/menu-builder/' . $items[0] . '/rename', ['name' => 'My renamed screen'])->assertOk();
        $this->assertContains('My renamed screen', collect(AppController::menu('OWNER'))->flatMap(fn ($s) => $s['items'])->pluck('name')->all(), 'the sidebar shows the new name');
        $this->postJson('/api/menu-builder/' . $items[0] . '/rename', ['name' => ''])->assertStatus(422);
        $key = DB::table('sidebar_modules')->where('id_module', $items[0])->value('module_key');
        $va = $this->makeUser('VA');
        $this->postJson('/api/menu-builder/' . $items[0] . '/role', ['role' => 'VA', 'on' => true])->assertOk();
        Perm::flush(); $this->assertTrue(Perm::has($va, 'menu:' . $key));
        $this->postJson('/api/menu-builder/' . $items[0] . '/role', ['role' => 'VA', 'on' => false])->assertOk()->assertJsonPath('message', 'Hidden from VA – and its forms with it.');
        Perm::flush(); $this->assertFalse(Perm::has($va, 'menu:' . $key), 'the same right as Rights › Roles');
        $t = $this->getJson('/api/menu-builder')->assertOk()->json();
        $this->assertFalse(collect($t['sections'])->flatMap(fn ($s) => $s['items'])->firstWhere('id', $items[0])['roles']['VA']);
    }

    public function test_r99_fields_first_and_help_topics(): void
    {
        $this->actingAs($this->owner);
        $g = collect(Finder::find('sku', $this->owner)['groups']);
        $this->assertSame('Fields', $g[0]['name'], '"sku" → the fields named SKU come before the screens that only mention it');
        $this->assertContains('Products', collect($g->firstWhere('name', 'Screens')['items'])->pluck('title')->all());
        $h = collect(Finder::find('acknowledge', $this->owner)['groups'])->firstWhere('name', 'Help');
        $this->assertNotNull($h, 'help topics are found');
        $this->assertSame('#/help/tasks', $h['items'][0]['url']);
        DB::table('sys_playbooks')->insert(RecordService::fillNotNull('sys_playbooks', ['fk_id_ent' => 0, 'workflow_code' => 'IMPORT', 'step_code' => 'undo', 'title' => 'Undo a wrong import', 'checklist_text' => "Open Import history\nPress Undo", 'f_active' => 1]));
        $p = collect(Finder::find('wrong import', $this->owner)['groups'])->firstWhere('name', 'Help');
        $this->assertSame('How to: Undo a wrong import', $p['items'][0]['title']);
    }

    public function test_r100_create_po_and_print_label_buttons(): void
    {
        $this->actingAs($this->owner);
        $m = (new \ReflectionClass(Finder::class))->getMethod('recordActions');
        $a = collect($m->invoke(null, $this->owner, 't_product_vendors', 7, 'Garden lights 10m'));
        $this->assertSame('#/direct-po?product=7', $a->firstWhere('label', 'Create PO for Garden lights 10m')['url']);
        $this->assertSame('print:PACKING_SLIP:5', collect($m->invoke(null, $this->owner, 'wh_parcels', 5, 'WEB-1001'))->firstWhere('label', 'Print label for WEB-1001')['url']);
        $this->assertSame('print:PO:3', collect($m->invoke(null, $this->owner, 't_src_po', 3, 'PO-1'))->firstWhere('label', 'Print PO')['url']);
        $this->assertSame([], $m->invoke(null, $this->makeUser('VA'), 'wh_parcels', 5, 'WEB-1001'), 'buttons only by right');
        $js = file_get_contents(public_path('app/app.js'));
        $this->assertStringContainsString("act.dataset.act.startsWith('print:')", $js); $this->assertStringContainsString('async function pageDirectPo(sup, rep, prod)', $js);
    }
}
