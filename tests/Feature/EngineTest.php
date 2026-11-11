<?php

namespace Tests\Feature;

use App\Services\EventBus;
use App\Services\Numbering;
use App\Services\RecordService;
use App\Services\Registry;
use App\Services\TaskEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EngineTest extends TestCase
{
    public function test_numbering_counts_per_company_type_and_month(): void
    {
        $this->assertSame('PO-TST-2608-0001', Numbering::next('PO', 1, '2026-08-03'));
        $this->assertSame('PO-TST-2608-0002', Numbering::next('PO', 1, '2026-08-30'));
        $this->assertSame('PO-TST-2609-0001', Numbering::next('PO', 1, '2026-09-01'));
        $this->assertSame('GRN-TST-2608-0001', Numbering::next('GRN', 1, '2026-08-03'));
    }

    public function test_registry_is_loaded_and_every_data_form_has_a_table(): void
    {
        $this->assertSame(4084, DB::table('sys_registry')->count());
        $forms = DB::table('sys_registry')->distinct()->pluck('form_key');
        $this->assertCount(322, $forms);
        $noTable = $forms->filter(fn ($k) => ! Registry::table($k) || ! Registry::columns(Registry::table($k)))->values()->all();
        // 20 forms are tools (filters, wizards, upload pages): they save nothing, so they have no table. Every other form must have one.
        $tools = ['acc_settings', 'count_session', 'csv_generator', 'csv_reader', 'drp_inventory', 'invoice_period_pick', 'location_generator', 'orders_audit', 'pack_check', 'sale_import',
            'supplier_order_history', 't_buy', 't_inv_portal', 't_sale', 't_src_dashboard', 't_src_reports', 'task_bulk_action', 'task_import', 'tax_dashboard', 'warehouse_settings'];
        $this->assertSame($tools, $noTable, 'Only the known tool forms may have no table');
    }

    public function test_unknown_event_is_refused_and_known_event_is_stored_and_processed(): void
    {
        $seen = [];
        EventBus::listen('PRODUCT_SAVED', function ($e) use (&$seen) { $seen[] = $e['entity_id']; });
        $id = EventBus::fire('PRODUCT_SAVED', 't_product_vendors', 55);
        $this->assertSame('NEW', DB::table('sys_events')->where('id', $id)->value('status'));   // inside the test transaction: runs after commit
        EventBus::process($id);
        $this->assertSame([55], $seen);
        $this->assertSame('DONE', DB::table('sys_events')->where('id', $id)->value('status'));
        $this->expectExceptionMessage('not in the event catalogue');
        EventBus::fire('MADE_UP_EVENT', 'x', 1);
    }

    public function test_failed_listener_leaves_the_event_for_retry(): void
    {
        $fail = true;
        EventBus::listen('PRODUCT_SAVED', function () use (&$fail) { if ($fail) throw new \RuntimeException('printer offline'); });
        $id = EventBus::fire('PRODUCT_SAVED', 't_product_vendors', 1);
        $this->assertFalse(EventBus::process($id));
        $e = DB::table('sys_events')->where('id', $id)->first();
        $this->assertSame('FAILED', $e->status);
        $this->assertSame('printer offline', $e->last_error);
        $fail = false;
        DB::table('sys_events')->where('id', $id)->update(['available_at' => now()->subMinute()]);
        $this->assertSame(1, EventBus::runQueue());
        $this->assertSame('DONE', DB::table('sys_events')->where('id', $id)->value('status'));
    }

    public function test_rule_task_opens_once_goes_to_the_right_role_and_closes_itself(): void
    {
        $manager = $this->makeUser('MANAGER');
        $a = TaskEngine::open('STK_REORDER', [501], 'Reorder: Dog bowl is below its order-by date');
        $b = TaskEngine::open('STK_REORDER', [501], 'Reorder: Dog bowl is below its order-by date');
        $this->assertSame($a, $b, 'Same problem must not open a second task');
        $t = DB::table('tasks')->where('id', $a)->first();
        $this->assertSame($manager->id, (int) $t->owner_user_id);
        $this->assertSame('STK_REORDER:501', $t->dedupe_key);
        $this->assertSame('RULE', $t->source);
        $this->assertTrue(TaskEngine::close('STK_REORDER', [501]));
        $this->assertSame('DONE', DB::table('tasks')->where('id', $a)->value('status'));
        $c = TaskEngine::open('STK_REORDER', [501], 'Reorder again');
        $this->assertSame($a, $c, 'A problem that comes back reopens its old task');
        $this->assertSame('TODO', DB::table('tasks')->where('id', $a)->value('status'));
    }

    public function test_sync_opens_new_problems_and_closes_the_ones_that_are_gone(): void
    {
        TaskEngine::sync('STK_REORDER', [['key' => [1], 'title' => 'A'], ['key' => [2], 'title' => 'B']]);
        $r = TaskEngine::sync('STK_REORDER', [['key' => [2], 'title' => 'B'], ['key' => [3], 'title' => 'C']]);
        $this->assertSame(['opened' => 1, 'closed' => 1], $r);
        $this->assertFalse(TaskEngine::isOpen('STK_REORDER', [1]));
        $this->assertTrue(TaskEngine::isOpen('STK_REORDER', [2]));
        $this->assertTrue(TaskEngine::isOpen('STK_REORDER', [3]));
    }

    public function test_switched_off_rule_opens_nothing(): void
    {
        DB::table('sys_rules')->where('rule_code', 'STK_REORDER')->update(['f_active' => 0]);
        $this->assertNull(TaskEngine::open('STK_REORDER', [9], 'x'));
    }

    public function test_generic_save_validates_corrects_logs_and_scopes_by_company(): void
    {
        try {
            RecordService::save('mp_profile', ['name' => 'no code given']);
            $this->fail('Required field was not checked');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('code', $e->errors());
        }
        $mp = DB::table('cat_marketplaces')->insertGetId(RecordServiceFill::row('cat_marketplaces', ['fk_id_ent' => 1]));
        $r = RecordService::save('mp_profile', ['code' => ' ebay uk ', 'name' => 'eBay UK', 'fk_id_marketplace' => $mp, 'timezone' => 'Europe/London', 'fulfilment_programme' => 'SELF', 'payout_rule' => 'NET'] + self::requiredDefaults('mp_profile'), null, ['event' => 'PROFILE_SAVED']);
        $this->assertTrue($r['created']);
        $row = RecordService::get('mp_profile', $r['id']);
        $this->assertSame('EBAY UK', $row['code'], 'auto-correct upper + trim');
        $this->assertSame(1, DB::table('sys_audit_log')->where(['table_name' => 'mp_profiles', 'record_id' => $r['id'], 'action' => 'INSERT'])->count());

        RecordService::save('mp_profile', ['name' => 'eBay United Kingdom'], $r['id']);
        $log = DB::table('sys_audit_log')->where(['table_name' => 'mp_profiles', 'record_id' => $r['id'], 'action' => 'UPDATE'])->first();
        $this->assertSame(['eBay UK', 'eBay United Kingdom'], json_decode($log->changes_json, true)['name']);

        $list = RecordService::list('mp_profile', ['q' => 'United']);
        $this->assertSame(1, $list['total']);
    }

    private static function requiredDefaults(string $form): array
    {
        $out = [];
        foreach (Registry::fields($form) as $f) {
            if (! $f['required'] || ! $f['column_name']) continue;
            $out[$f['field_name']] = match ($f['type']) {
                'number', 'money' => 1, 'date' => '2026-08-01', 'check' => 1, 'email' => 'a@b.co', 'link' => 'https://example.com',
                'select', 'multiselect' => (Registry::options($f, 1)[0][0] ?? '1'), default => 'X',
            };
        }
        return $out;
    }
}

/** Test helper: a row with every NOT NULL column filled. */
class RecordServiceFill
{
    public static function row(string $table, array $data): array
    {
        $m = new \ReflectionMethod(RecordService::class, 'fillNotNull');
        return $m->invoke(null, $table, $data);
    }
}
