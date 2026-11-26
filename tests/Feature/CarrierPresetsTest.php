<?php

namespace Tests\Feature;

use App\Services\CarrierPresets as CP;
use App\Services\Context;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-D-63 combination browser + add, R-D-65 the carrier dropdown of every form, R-D-66 old virtual preset ids. */
class CarrierPresetsTest extends TestCase
{
    private function f(string $t, array $d): int { return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }
    private array $x = [];

    private function world(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $c = $this->f('t_carriers', ['fk_id_ent' => 1, 'carrier_name' => 'RM', 'carrier_code' => 'RMX', 'f_active' => 1]);
        $p1 = $this->f('t_carrier_packages', ['fk_id_carrier' => $c, 'package_name' => 'Small Parcel', 'f_active' => 1]); $p2 = $this->f('t_carrier_packages', ['fk_id_carrier' => $c, 'package_name' => 'Large Letter', 'f_active' => 1]);
        $s1 = $this->f('t_carrier_services', ['fk_id_carrier' => $c, 'service_name' => '2nd Class', 'f_active' => 1]); $s2 = $this->f('t_carrier_services', ['fk_id_carrier' => $c, 'service_name' => 'Tracked 24', 'f_active' => 1]);
        $pre = fn ($n, $p, $s, $rate, $act = 1) => $this->f('t_carrier_pkg_svc', ['pkg_svc_name' => $n, 'fk_id_carrier' => $c, 'fk_id_car_package' => $p, 'fk_id_car_service' => $s, 'rate' => $rate, 'f_active' => $act]);
        $this->x = ['c' => $c, 'p1' => $p1, 'p2' => $p2, 's1' => $s1, 's2' => $s2, 'a' => $pre('2nd Class', $p1, $s1, 3.35), 'b' => $pre('', $p2, $s1, 1.55), 'dead' => $pre('Old preset', $p1, $s2, 4.00, 0)];
        $this->x['g'] = $this->f('car_preset_groups', ['fk_id_ent' => 1, 'group_name' => 'Everyday', 'group_code' => 'EVD', 'scope_mode' => 'ALL', 'f_favourite' => 0, 'f_active' => 1, 'sort_order' => 10]);
    }

    public function test_d63_combination_browser_and_add(): void
    {
        $this->world(); $g = $this->x['g'];
        $d = $this->getJson("/api/preset-groups/$g/combos?carrier={$this->x['c']}")->assertOk()->json();
        $this->assertCount(4, $d['rows'], 'carrier × 2 packages × 2 services'); $rows = collect($d['rows']);
        $this->assertSame(2, $rows->where('saved', true)->count()); $this->assertFalse($d['limited']);
        $this->assertSame('2nd Class Large Letter', $rows->firstWhere('preset', $this->x['b'])['name'], 'the name falls back to "Service Package"');
        $this->assertCount(2, $this->getJson("/api/preset-groups/$g/combos?show=not_saved&carrier={$this->x['c']}")->json('rows'));
        $this->postJson("/api/preset-groups/$g/presets", ['items' => []])->assertStatus(422)->assertJsonPath('message', 'Tick at least one preset.');
        // the rule: add 3 combinations, 1 unsaved → 1 preset created at rate 0 + 1 task
        $tasks = DB::table('tasks')->where('rule_code', 'CAR_RATE_MISSING')->count();
        $r = $this->postJson("/api/preset-groups/$g/presets", ['items' => [$this->x['a'], $this->x['b'], ['carrier' => $this->x['c'], 'package' => $this->x['p2'], 'service' => $this->x['s2']]]])->assertOk()->json();
        $this->assertSame([3, 1, 0], [$r['added'], $r['new'], $r['already']]);
        $this->assertSame('3 preset(s) added. 1 were new and were created with rate 0 – set their rate in Carriers Preset.', $r['message']);
        $new = DB::table('t_carrier_pkg_svc')->where('fk_id_car_package', $this->x['p2'])->where('fk_id_car_service', $this->x['s2'])->first();
        $this->assertSame(['Tracked 24 Large Letter', 0.0], [$new->pkg_svc_name, (float) $new->rate]);
        $this->assertSame($tasks + 1, DB::table('tasks')->where('rule_code', 'CAR_RATE_MISSING')->count());
        $this->assertSame('3 preset(s) added (1 new preset(s) created with rate 0)', DB::table('car_preset_log')->where('fk_id_group', $g)->where('action', 'ADD')->orderByDesc('id')->value('detail'));
        // "2 were already in the group" when re-adding
        $again = $this->postJson("/api/preset-groups/$g/presets", ['items' => [$this->x['a'], $this->x['b']]])->assertOk()->json(); $this->assertSame('0 preset(s) added. 2 were already in the group.', $again['message']);
        // a package of another carrier is refused; in-this-group marker; the task closes when the rate is set
        $c2 = $this->f('t_carriers', ['fk_id_ent' => 1, 'carrier_name' => 'Other', 'f_active' => 1]); $px = $this->f('t_carrier_packages', ['fk_id_carrier' => $c2, 'package_name' => 'Box', 'f_active' => 1]);
        $bad = $this->postJson("/api/preset-groups/$g/presets", ['items' => [['carrier' => $this->x['c'], 'package' => $px, 'service' => $this->x['s1']]]])->assertOk()->json(); $this->assertSame(0, $bad['added']); $this->assertNotEmpty($bad['errors']);
        $this->assertSame(3, collect($this->getJson("/api/preset-groups/$g/combos?carrier={$this->x['c']}")->json('rows'))->where('in_this_group', true)->count());
        $open = fn () => DB::table('tasks')->where('dedupe_key', 'CAR_RATE_MISSING:' . $new->id)->whereNotIn('status', ['DONE', 'CANCELLED', 'CLOSED'])->count();
        $this->assertSame(1, $open()); DB::table('t_carrier_pkg_svc')->where('id', $new->id)->update(['rate' => 2.10]); $this->assertSame(1, CP::closeRateTasks()); $this->assertSame(0, $open());
        $va = $this->makeUser('VA'); $this->actingAs($va)->postJson("/api/preset-groups/$g/presets", ['items' => [$this->x['a']]])->assertStatus(403);
    }

    public function test_d65_dropdown_order_default_other_and_current(): void
    {
        $this->world();
        $ebay = $this->f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'eBay65', 'f_active' => 1]); $amz = $this->f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'Amz65', 'f_active' => 1]);
        $shop = $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $ebay, 'shop_no' => 'D65', 'iso_branch' => 'D65', 'shop_name' => 'eBay shop', 'f_active' => 1]);
        // group A LIMITED to eBay (default "RM 2nd Class"), group B ALL starred
        $A = $this->f('car_preset_groups', ['fk_id_ent' => 1, 'group_name' => 'A eBay only', 'scope_mode' => 'LIMITED', 'f_favourite' => 0, 'f_active' => 1, 'sort_order' => 50, 'fk_id_default_preset' => $this->x['a'], 'color' => '#007bff']);
        $this->f('car_preset_group_scope', ['fk_id_group' => $A, 'fk_id_marketplace' => $ebay, 'fk_id_branch' => 0]);
        $this->f('car_preset_group_items', ['fk_id_group' => $A, 'fk_id_pkg_svc' => $this->x['a'], 'f_pinned' => 0, 'sort_order' => 20]); $this->f('car_preset_group_items', ['fk_id_group' => $A, 'fk_id_pkg_svc' => $this->x['b'], 'f_pinned' => 1, 'sort_order' => 30]);
        DB::table('car_preset_groups')->where('id', $this->x['g'])->update(['group_name' => 'B all', 'f_favourite' => 1]);
        $this->f('car_preset_group_items', ['fk_id_group' => $this->x['g'], 'fk_id_pkg_svc' => $this->x['a'], 'f_pinned' => 0, 'sort_order' => 10]);
        $free = $this->f('t_carrier_pkg_svc', ['pkg_svc_name' => 'Loose one', 'fk_id_carrier' => $this->x['c'], 'fk_id_car_package' => $this->x['p1'], 'fk_id_car_service' => $this->x['s2'], 'rate' => 0, 'f_active' => 1]);
        $d = $this->getJson("/api/carrier-dropdown?shop=$shop")->assertOk()->json();
        $this->assertSame(['A eBay only', '★ B all [EVD]', 'Other presets'], array_column($d['groups'], 'header'), 'limited-and-matched first, then starred, last Other presets');
        $this->assertSame($this->x['a'], $d['default'], 'a new eBay row pre-selects the default of the first offered group');
        $this->assertSame(['RM - 2nd Class Large Letter (1.55)', 'RM - 2nd Class (3.35)'], array_column($d['groups'][0]['items'], 'label'), 'pinned first; label Carrier - name (rate); the name falls back to Service Package');
        $this->assertSame(['RM - Loose one'], array_column($d['groups'][2]['items'], 'label'), 'rate shown only when above 0; active presets in no group');
        // another marketplace: the limited group is not offered; no marketplace and no shop: every group
        $this->assertSame(['★ B all [EVD]', 'Other presets'], array_column($this->getJson("/api/carrier-dropdown?marketplace=$amz")->json('groups'), 'header'));
        $this->assertCount(3, CP::dropdown()['groups']);
        // a preset in two groups is selected only in the first; an inactive saved value is kept as "(current: …)"
        $cur = CP::dropdown($ebay, null, $this->x['a']); $sel = []; foreach ($cur['groups'] as $g) foreach ($g['items'] as $i) if ($i['selected']) $sel[] = $g['name'];
        $this->assertSame(['A eBay only'], $sel); $this->assertNull($cur['current']); $this->assertNull($cur['default']);
        $old = CP::dropdown($ebay, null, $this->x['dead']);
        $this->assertSame(['id' => $this->x['dead'], 'label' => '(current: RM - Old preset (4.00))', 'rate' => 4.0, 'selected' => true, 'current' => true], $old['current']);
        // every registry carrier field (product, shop default, sale line …) gets the same list: group order, a preset once, the default, "(current)" label for a saved value
        $shopField = collect(\App\Services\Registry::fields('t_branch'))->firstWhere('column_name', 'fk_id_default_carrier')['field_name']; $mpField = collect(\App\Services\Registry::fields('t_branch'))->firstWhere('column_name', 'fk_id_marketplace')['field_name'];
        $fo = $this->getJson("/api/options/t_branch/$shopField?" . http_build_query(['parent' => [$mpField => $ebay]]))->assertOk()->json();
        $this->assertSame(['A eBay only › RM - 2nd Class Large Letter (1.55)', 'A eBay only › RM - 2nd Class (3.35)', 'Other presets › RM - Loose one'], array_column($fo['options'], 1), 'the preset that is also in group B is listed once, in the first group');
        $this->assertSame([$this->x['a'], 'current'], [$fo['default'], $fo['saved_as']]);
        $this->assertSame(['★ B all [EVD] › RM - 2nd Class (3.35)', 'Other presets › RM - 2nd Class Large Letter (1.55)', 'Other presets › RM - Loose one'], array_column($this->getJson("/api/options/t_branch/$shopField?" . http_build_query(['parent' => [$mpField => $amz]]))->json('options'), 1));
        $this->assertSame('RM - Old preset (4.00)', $this->getJson("/api/options/t_branch/$shopField/saved?ids=" . $this->x['dead'])->json('labels')[(string) $this->x['dead']], 'a saved inactive preset keeps its name');
        $pf = collect(\App\Services\Registry::definition('t_product_vendor')['sections'])->flatMap(fn ($x) => $x['fields'])->firstWhere('col', 'fk_id_car_pkg_svc'); $this->assertTrue($pf['carrier_dd']);
        $this->assertSame([$this->x['b'], $this->x['a'], $free], array_slice(array_keys(CP::rank($ebay)), 0, 3));
        // an inactive group is not offered
        DB::table('car_preset_groups')->where('id', $A)->update(['f_active' => 0]); $this->assertNotContains('A eBay only', array_column(CP::dropdown($ebay)['groups'], 'name'));
    }

    public function test_d66_virtual_ids_converted_or_listed_as_ambiguous(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        // the rule: "1123" fits (ent 1, carrier 1, pkg 2, svc 3) and (ent 1, carrier 12, pkg 3, svc …) → ambiguous, not converted. Built here from a hand-made map of the same shape.
        $map = ['1123' => ['1|1|2|3' => 40, '1|12|3|' => 77], '1456' => ['1|4|5|6' => 51]];
        $this->assertSame(['preset' => 51], CP::convert('1456', $map)); $this->assertSame(['unknown' => true], CP::convert('9999', $map));
        $amb = CP::convert('1123', $map); $this->assertSame([40, 77], array_column($amb['ambiguous'], 'preset'));
        // with real rows: one clear value is converted, an unknown one goes to the Owner's list and stays as it was
        $c = $this->f('t_carriers', ['fk_id_ent' => 1, 'carrier_name' => 'V carrier', 'f_active' => 1]); $p = $this->f('t_carrier_packages', ['fk_id_carrier' => $c, 'package_name' => 'P', 'f_active' => 1]); $s = $this->f('t_carrier_services', ['fk_id_carrier' => $c, 'service_name' => 'S', 'f_active' => 1]);
        $low = $this->f('t_carrier_pkg_svc', ['pkg_svc_name' => 'first', 'fk_id_carrier' => $c, 'fk_id_car_package' => $p, 'fk_id_car_service' => $s, 'rate' => 1, 'f_active' => 1]);
        $this->f('t_carrier_pkg_svc', ['pkg_svc_name' => 'second', 'fk_id_carrier' => $c, 'fk_id_car_package' => $p, 'fk_id_car_service' => $s, 'rate' => 1, 'f_active' => 1]);
        $virtual = '1' . $c . $p . $s; $real = CP::convert($virtual);
        if (isset($real['preset'])) $this->assertSame($low, $real['preset'], 'the lowest preset id of the combination');
        $mk = fn ($v) => $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'VI-' . uniqid(), 'product_name_vendor' => 'V', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool', 'fk_id_car_pkg_svc' => $v]);
        $a = $mk((int) $virtual); $b = $mk(99999999);
        $dry = CP::migrate('t_product_vendors', 'fk_id_car_pkg_svc', [$a => $virtual, $b => '99999999'], false);
        $this->assertSame([false, 0], [$dry['applied'], DB::table('mo_carrier_value_review')->count()]); $this->assertSame((int) $virtual, (int) DB::table('t_product_vendors')->where('id', $a)->value('fk_id_car_pkg_svc'), 'a dry run writes nothing');
        $mgr = $this->makeUser('MANAGER'); try { CP::migrate('t_product_vendors', 'fk_id_car_pkg_svc', [$a => $virtual], true, $mgr); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertSame('Only the Owner runs this conversion.', $e->getMessage()); }
        $r = CP::migrate('t_product_vendors', 'fk_id_car_pkg_svc', [$a => $virtual, $b => '99999999'], true, $this->owner);
        if (isset($real['preset'])) { $this->assertSame(1, $r['converted']); $this->assertSame($low, (int) DB::table('t_product_vendors')->where('id', $a)->value('fk_id_car_pkg_svc')); }
        $this->assertSame(99999999, (int) DB::table('t_product_vendors')->where('id', $b)->value('fk_id_car_pkg_svc'), 'an unclear value is not converted');
        $list = collect($this->getJson('/api/carrier-values/review')->assertOk()->json('rows')); $row = $list->firstWhere('record', $b); $this->assertSame('99999999', $row['old_value']); $this->assertSame('V', $row['name'], 'the record is shown by its name');
        $routes = []; foreach (\App\Http\Controllers\AppController::menu('OWNER') as $sec) foreach ($sec['items'] as $it) $routes[] = $it['route']; $this->assertContains('#/carrier-values', $routes);
        $this->postJson('/api/carrier-values/review/' . $row['id'], ['preset' => $low])->assertOk(); $this->assertSame($low, (int) DB::table('t_product_vendors')->where('id', $b)->value('fk_id_car_pkg_svc'));
        $this->actingAs($mgr)->getJson('/api/carrier-values/review')->assertStatus(422);
        try { CP::migrate('t_users', 'id', [1 => '1'], false); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('does not hold carrier presets', $e->getMessage()); }
    }

    /** R-D-64 presets inside a group: pin, order, default (must be in the group; cleared when removed), clean-up of deleted presets, where offered. */
    public function test_d64_presets_inside_a_group(): void
    {
        $this->world(); $g = $this->x['g']; ['a' => $a, 'b' => $b, 'dead' => $dead] = $this->x;
        foreach ([$a, $b, $dead] as $k => $p) DB::table('car_preset_group_items')->insert(['fk_id_group' => $g, 'fk_id_pkg_svc' => $p, 'f_pinned' => 0, 'sort_order' => ($k + 1) * 10]);
        $ghost = $this->f('t_carrier_pkg_svc', ['pkg_svc_name' => 'Gone', 'fk_id_carrier' => $this->x['c'], 'fk_id_car_package' => $this->x['p1'], 'fk_id_car_service' => $this->x['s1'], 'rate' => 1, 'f_active' => 1]);
        DB::table('car_preset_group_items')->insert(['fk_id_group' => $g, 'fk_id_pkg_svc' => $ghost, 'f_pinned' => 0, 'sort_order' => 40]); DB::table('t_carrier_pkg_svc')->where('id', $ghost)->delete();
        $x = $this->getJson("/api/preset-groups/$g/items")->assertOk()->json();
        $this->assertSame([$a, $b, $dead], array_column($x['items'], 'preset'), 'the deleted preset is cleaned out');
        $this->assertSame('CLEAN', DB::table('car_preset_log')->where('fk_id_group', $g)->orderByDesc('id')->value('action'));
        $this->assertTrue(collect($x['items'])->firstWhere('preset', $dead)['inactive']);
        $this->postJson("/api/preset-groups/$g/items/$b/pin")->assertOk()->assertJson(['pinned' => true]);
        $this->postJson("/api/preset-groups/$g/items/order", ['presets' => [$dead, $a, $b]])->assertOk()->assertJson(['message' => 'Order saved.']);
        $this->assertSame([$b, $dead, $a], array_column($this->getJson("/api/preset-groups/$g/items")->json('items'), 'preset'), 'pinned first, then the order');
        $other = $this->f('t_carrier_pkg_svc', ['pkg_svc_name' => 'Elsewhere', 'fk_id_carrier' => $this->x['c'], 'fk_id_car_package' => $this->x['p2'], 'fk_id_car_service' => $this->x['s2'], 'rate' => 2, 'f_active' => 1]);
        $this->postJson("/api/preset-groups/$g/items/default", ['preset' => $other])->assertStatus(422)->assertJson(['message' => 'The default must be a preset of this group.']);
        $this->postJson("/api/preset-groups/$g/items/default", ['preset' => $a])->assertOk()->assertJson(['message' => 'Default preset set.']);
        $this->postJson("/api/preset-groups/$g/items/$a", [], [])->assertStatus(405);
        $this->deleteJson("/api/preset-groups/$g/items/$a")->assertOk()->assertJson(['message' => 'Removed from the group.']);
        $this->assertEmpty(DB::table('car_preset_groups')->where('id', $g)->value('fk_id_default_preset'), 'removing the default clears it');
        $this->deleteJson("/api/preset-groups/$g/items/$a")->assertStatus(422)->assertJson(['message' => 'It was not in the group.']);
        $this->postJson("/api/preset-groups/$g/scope", ['mode' => 'LIMITED'])->assertStatus(422);
        $mp = (int) DB::table('cat_marketplaces')->value('id') ?: $this->f('cat_marketplaces', ['fk_id_ent' => 1]);
        $this->postJson("/api/preset-groups/$g/scope", ['mode' => 'LIMITED', 'marketplaces' => [$mp]])->assertOk();
        $this->assertSame(['LIMITED', [$mp]], [DB::table('car_preset_groups')->where('id', $g)->value('scope_mode'), $this->getJson("/api/preset-groups/$g/items")->json('marketplaces')]);
        $this->assertEqualsCanonicalizing(['CLEAN', 'EDIT', 'DEFAULT', 'REMOVE', 'SCOPE'], DB::table('car_preset_log')->where('fk_id_group', $g)->distinct()->pluck('action')->all());
    }

    /** R-D-45: a location never sits under itself or under one of its own children. */
    public function test_d45_location_parent_cannot_loop(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $a = $this->f('cat_inventory_locations', ['fk_id_ent' => 1, 'location_name' => 'Loop A', 'f_active' => 1]);
        $b = $this->f('cat_inventory_locations', ['fk_id_ent' => 1, 'location_name' => 'Loop B', 'fk_id_parent' => $a, 'f_active' => 1]);
        try { \App\Services\RecordService::save('location', ['fk_id_parent' => $b], $a); $this->fail('loop'); }
        catch (\Throwable $e) { $this->assertStringContainsString('inside this location', json_encode(method_exists($e, 'errors') ? $e->errors() : $e->getMessage())); }
        try { \App\Services\RecordService::save('location', ['fk_id_parent' => $a], $a); $this->fail('self'); }
        catch (\Throwable $e) { $this->assertStringContainsString('its own parent', json_encode(method_exists($e, 'errors') ? $e->errors() : $e->getMessage())); }
        $this->assertSame(0, (int) DB::table('cat_inventory_locations')->where('id', $a)->value('fk_id_parent'));
    }

    /** R-D-12: product manager role → product manager: only the active people of that role. */
    public function test_d12_product_manager_follows_the_role(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $role = $this->f('cat_account_types', ['account_type_name' => 'Listing team', 'role_code' => 'LISTX', 'f_active' => 1]);
        $p1 = $this->f('t_account', ['first_name' => 'Zara', 'sur_name' => 'Lister', 'f_active' => 1]); $p2 = $this->f('t_account', ['first_name' => 'Omar', 'sur_name' => 'Other', 'f_active' => 1]);
        DB::table('t_account_in_types')->insert(['fk_id_account' => $p1, 'fk_id_account_type' => $role, 'f_active' => 1]);
        $o = \App\Services\FormAssist::options('t_product_vendor', 'fk_id_role_account', null, ['fk_id_role' => $role]);
        $this->assertSame([(string) $p1], array_column($o['options'], 0));
        $this->assertSame('Product manager', DB::table('sys_registry')->where('form_key', 't_product_vendor')->where('column_name', 'fk_id_role_account')->value('label'));
        $this->assertSame('fk_id_role', DB::table('sys_registry')->where('form_key', 't_product_vendor')->where('column_name', 'fk_id_role_account')->value('cascades_from'));
    }

    /** R-F_SETUP-40: pinned countries first in every country list, active only. */
    public function test_f40_pinned_countries_first(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        DB::table('cat_country')->update(['f_order' => 2]);
        $uk = $this->f('cat_country', ['country_name' => 'Zz Kingdom', 'iso_code' => 'ZK', 'country_code' => '44', 'f_order' => 0, 'f_active' => 1]);
        $pk = $this->f('cat_country', ['country_name' => 'Zz Pak', 'iso_code' => 'ZP', 'country_code' => '92', 'f_order' => 1, 'f_active' => 1]);
        $off = $this->f('cat_country', ['country_name' => 'Aa Closed', 'iso_code' => 'AQ', 'country_code' => '1', 'f_order' => 0, 'f_active' => 0]);
        $ids = array_map(fn ($o) => (int) $o[0], \App\Services\Registry::options(['source' => 'table:cat_country', 'type' => 'select'], 1, null, 50));
        $this->assertSame([$uk, $pk], array_slice($ids, 0, 2)); $this->assertNotContains($off, $ids);
    }
}
