<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\ProductCerts;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Owner 27-11 01:30: what of the old tool is left (screens + data); MR-0139 certificate expiry tasks. */
class OldToolCheckTest extends TestCase
{
    public function test_old_tool_check_lists_screens_and_refuses_this_database(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $d = $this->getJson('/api/old-tool-check')->assertOk()->json();
        $this->assertGreaterThanOrEqual(130, $d['controllers'], 'every old controller is on the list');
        $this->assertSame($d['screens_open'], array_sum(array_map(fn ($c) => count($c['open']), $d['screens'])));
        $this->assertNotEmpty(collect($d['screens'])->firstWhere('controller', 'T_tasks'), 'the old task screens are checked too');
        $this->assertNull($d['counted_at']);
        try { \App\Services\OldToolCheck::count(DB::getDatabaseName()); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('old database', $e->getMessage()); }
        $va = $this->makeUser('VA'); $this->actingAs($va); $this->getJson('/api/old-tool-check')->assertStatus(403);
    }

    public function test_certificate_expiry_task_opens_and_closes(): void
    {
        Context::set(1, $this->owner->id);
        $p = DB::table('t_product_vendors')->insertGetId(RecordService::fillNotNull('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'CERT-1', 'product_name_vendor' => 'Lamp', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']));
        $soon = DB::table('product_certificates')->insertGetId(RecordService::fillNotNull('product_certificates', ['fk_id_ent' => 1, 'fk_id_product' => $p, 'cert_no' => 'UKCA-77', 'expiry_date' => now()->addDays(20)->toDateString(), 'f_active' => 1]));
        $far = DB::table('product_certificates')->insertGetId(RecordService::fillNotNull('product_certificates', ['fk_id_ent' => 1, 'fk_id_product' => $p, 'cert_no' => 'CE-1', 'expiry_date' => now()->addDays(300)->toDateString(), 'f_active' => 1]));
        ProductCerts::sweep(1);
        $t = DB::table('tasks')->where('dedupe_key', 'PRD_CERT_EXPIRY:' . $soon)->first();
        $this->assertNotNull($t); $this->assertStringContainsString('UKCA-77 of CERT-1 expires on', $t->title);
        $this->assertFalse(DB::table('tasks')->where('dedupe_key', 'PRD_CERT_EXPIRY:' . $far)->exists(), 'not before the 60 days');
        ProductCerts::sweep(1); $this->assertSame(1, DB::table('tasks')->where('dedupe_key', 'PRD_CERT_EXPIRY:' . $soon)->count(), 'one task per certificate');
        DB::table('product_certificates')->where('id', $soon)->update(['expiry_date' => now()->addYears(2)->toDateString()]);
        ProductCerts::sweep(1);
        $this->assertSame('DONE', DB::table('tasks')->where('id', $t->id)->value('status'), 'renewed → the task closes by itself');
    }

    /** Owner 27-11 01:36: Create comes from the root – every form, and every menu screen that makes something, by itself. */
    public function test_create_is_built_from_the_root(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $c = collect($this->getJson('/api/boot')->assertOk()->json('create'));
        $this->assertNotNull($c->firstWhere('title', 'Import anything'), 'a menu screen that makes something is in Create');
        $this->assertSame('#/import', $c->firstWhere('title', 'Import anything')['screen']);
        $this->assertNull($c->firstWhere('title', 'Import history'), 'a list is not');
        // a new menu item that makes something appears by itself
        $sec = DB::table('sidebar_modules')->whereNotNull('parent_id')->where('url', '#/import')->value('parent_id');
        DB::table('sidebar_modules')->insert(['module_key' => 'M_TEST_NEW_X', 'module_name' => 'New gadget order', 'url' => '#/gadget', 'parent_id' => $sec, 'sort_order' => 99, 'e_status' => 'ACTIVE', 'roles' => 'all (by rights)', 'created_at' => now()]);
        \App\Services\Perm::flush();
        $this->assertNotNull(collect($this->getJson('/api/boot')->json('create'))->firstWhere('title', 'New gadget order'));
        $this->assertGreaterThan(50, $c->whereNull('screen')->count(), 'the registry forms are there too');
    }
}
