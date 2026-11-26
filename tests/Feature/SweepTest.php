<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\DueEngine;
use App\Services\LostParcels;
use App\Services\RecordService;
use App\Services\Settings;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Register lines closed in 7e-218: MR-0086 due engine (trademark, samples), MR-0551 lost-parcel money code. */
class SweepTest extends TestCase
{
    private function f(string $t, array $d): int { return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }

    public function test_mr0086_due_engine_covers_trademark_and_samples(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $ch = $this->f('cat_company_house', ['fk_id_ent' => 1, 'company_name' => 'TM Ltd']);
        $br = $this->f('cat_brands', ['fk_id_ent' => 1, 'brand_name' => 'Sweepco', 'fk_id_company_house' => $ch, 'f_active' => 1, 'tm_expiry_date' => date('Y-m-d', strtotime('+30 days'))]);
        $sm = $this->f('t_src_samples', ['fk_id_ent' => 1, 'sample_code' => 'SMP-218', 'sample_date' => date('Y-m-d', strtotime('-1 day')), 'f_active' => 1, 'created_at' => now()]);
        DueEngine::run(1);
        $src = fn (string $code) => (int) DB::table('sys_due_sources')->where('code', $code)->value('id');
        $tm = DB::table('sys_due_items')->where('fk_id_source', $src('TM_EXPIRY'))->where('record_id', $br)->first(); $this->assertNotNull($tm); $this->assertStringContainsString('Sweepco', $tm->title);
        $s = DB::table('sys_due_items')->where('fk_id_source', $src('SAMPLE_DUE'))->where('record_id', $sm)->first(); $this->assertNotNull($s); $this->assertStringContainsString('SMP-218', $s->title);
        $this->assertNotNull($s->fk_id_task, 'the sample date has passed → a task');
    }

    public function test_mr0551_a_lost_parcel_claim_above_the_money_limit_is_urgent(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id); Settings::set('TASK_URGENT_MONEY', '100', 1);
        $p = $this->f('wh_parcels', ['fk_id_ent' => 1, 'parcel_code' => 'PCL-218', 'order_no' => 'O-218', 'status' => 'HANDED_OVER', 'created_at' => now()]);
        LostParcels::open(1, $p);
        $this->assertNotSame('URGENT', DB::table('tasks')->where('rule_code', 'WH_PARCEL_LOST')->where('linked_record_id', $p)->value('priority'));
        LostParcels::claim(1, $p, 'CL-1', 250.0);
        $this->assertSame('URGENT', DB::table('tasks')->where('rule_code', 'WH_PARCEL_LOST')->where('linked_record_id', $p)->value('priority'));
    }
}
