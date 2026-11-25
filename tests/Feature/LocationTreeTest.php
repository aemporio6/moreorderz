<?php

namespace Tests\Feature;

use App\Services\Registry;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-H-2 one location tree site › zone › bin in cat_inventory_locations; the bin takes its site from the tree; path label in the pickers. */
class LocationTreeTest extends TestCase
{
    private function country(): int { return (int) (DB::table('cat_country')->value('id') ?: DB::table('cat_country')->insertGetId(RecordService::fillNotNull('cat_country', ['country_name' => 'United Kingdom']))); }

    private function save(string $form, array $v): int
    {
        try { return (int) RecordService::save($form, $v)['id']; } catch (\Illuminate\Validation\ValidationException $e) { throw new \RuntimeException($form . ' ' . ($v['name'] ?? '') . ': ' . json_encode($e->errors())); }
    }

    public function test_rule_example_wh1_w3_w3_b_2(): void
    {
        $this->actingAs($this->makeUser('OWNER')); \App\Services\Context::set(1, null);
        $site = (int) RecordService::save('warehouse', ['name' => 'WH1', 'code' => 'WH1', 'kind' => 'WAREHOUSE', 'f_wh_managed' => 0, 'country' => $this->country()])['id'];
        $zone = $this->save('warehouse_zone', ['parent' => $site, 'code' => 'W3', 'name' => 'W3', 'kind' => 'ZONE', 'zone_type' => 'WALL']);
        $bin = $this->save('location', ['name' => 'W3-B-2', 'code' => 'W3-B-2', 'kind' => 'BIN', 'parent' => $zone, 'bin_type' => 'SHELF']);
        $rows = DB::table('cat_inventory_locations')->whereIn('id', [$site, $zone, $bin])->orderBy('id')->get(['kind', 'fk_id_parent', 'fk_id_site']);
        $this->assertSame([['WAREHOUSE', null], ['ZONE', $site], ['BIN', $zone]], $rows->map(fn ($r) => [$r->kind, $r->fk_id_parent ? (int) $r->fk_id_parent : null])->all(), 'three rows, parents WH1 › W3 › W3-B-2');
        $this->assertSame([$site, $site], [(int) $rows[1]->fk_id_site, (int) $rows[2]->fk_id_site], 'the site is filled from the tree');
        $label = collect(Registry::options(['source' => 'table:cat_inventory_locations', 'options_full' => null], 1, 'W3-B-2'))->first(fn ($o) => (int) $o[0] === $bin);
        $this->assertSame('WH1 › W3 › W3-B-2 (Shelf)', $label[1] ?? null);
        try { RecordService::save('location', ['name' => 'W3-B-2', 'code' => 'W3-B-2b', 'kind' => 'BIN', 'parent' => $zone]); $this->fail('same bin twice'); } catch (\Throwable $e) { $this->assertStringContainsString('already exists in this site', json_encode(method_exists($e, 'errors') ? $e->errors() : $e->getMessage())); }
        $old = $this->save('location', ['name' => 'OLD-1', 'code' => 'OLD-1', 'kind' => 'SHELF', 'parent' => $site]);
        $this->assertSame('BIN', DB::table('cat_inventory_locations')->where('id', $old)->value('kind'), 'the old kind SHELF is read as BIN');
    }
}
