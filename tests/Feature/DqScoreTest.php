<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\DqScore as DQ;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-G_CORE-108 data-quality score per master record. */
class DqScoreTest extends TestCase
{
    public function test_g108_score_counts_required_double_and_skips_calc_fields(): void
    {
        $f = fn (string $c, array $x = []) => $x + ['column_name' => $c, 'field_name' => $c, 'label' => ucfirst($c), 'type' => 'text', 'required' => '', 'readonly' => '', 'secret' => ''];
        // the rule: a product with 6 of 10 counted fields → 60 %; fill 2 → 80 %
        $fields = []; $row = []; for ($i = 1; $i <= 10; $i++) { $fields[] = $f("c$i"); $row["c$i"] = $i <= 6 ? 'x' : ''; }
        $fields[] = $f('calc1', ['readonly' => 'calc']); $fields[] = $f('auto1', ['readonly' => 'auto']); $fields[] = $f('param1', ['readonly' => 'param']); $fields[] = $f('sec', ['secret' => 'Y']); $fields[] = $f('tick', ['type' => 'check']); $fields[] = $f('not_in_table');
        $row += ['calc1' => null, 'auto1' => null, 'param1' => null, 'sec' => null, 'tick' => 0];
        $r = DQ::calc($fields, $row); $this->assertSame([60, 10, 6, ['c7' => 'C7', 'c8' => 'C8', 'c9' => 'C9', 'c10' => 'C10']], [$r['score'], $r['counted'], $r['filled'], $r['missing']]);
        $this->assertSame(80, DQ::calc($fields, ['c7' => 'x', 'c8' => 'x'] + $row)['score']);
        // a required field counts double; an empty choice (0) is not filled
        $r2 = DQ::calc([$f('a', ['required' => 'Y']), $f('b'), $f('c', ['type' => 'select'])], ['a' => '', 'b' => 'x', 'c' => 0]); $this->assertSame([25, 4, 1], [$r2['score'], $r2['counted'], $r2['filled']]);
    }

    public function test_g108_low_record_gets_a_task_that_closes_when_filled(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id); $this->assertArrayHasKey('t_product_vendor', DQ::masterForms());
        $id = (int) DB::table('t_product_vendors')->insertGetId(RecordService::fillNotNull('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'DQ-108', 'product_name_vendor' => 'Thin record', 'f_active' => 1, 'e_product_type' => 'pool']));
        $chip = $this->getJson("/api/page/t_product_vendor/$id")->assertOk()->json('dq'); $this->assertStringContainsString('% complete', $chip['text']); $this->assertNotEmpty($chip['missing']);
        $this->assertSame(0, DB::table('sys_dq_scores')->count(), 'opening a record writes nothing');
        \App\Services\Choices::set('DQ_TASK_BELOW_PCT', '80', 1); $score = DQ::put('t_product_vendor', $id)['score']; $this->assertLessThan(80, $score);
        $r = DQ::run('t_product_vendor'); $this->assertGreaterThanOrEqual(1, $r['opened']);
        $row = DB::table('sys_dq_scores')->where('form_key', 't_product_vendor')->where('record_id', $id)->first(); $this->assertSame($score, (int) $row->score_pct); $this->assertNotEmpty(json_decode($row->missing_fields, true));
        $key = "DQ_ITEM_NOTE:score:t_product_vendor:$id"; $t = DB::table('tasks')->where('dedupe_key', $key)->get(); $this->assertCount(1, $t); $this->assertStringContainsString("is $score % complete – fill:", $t[0]->title);
        DQ::run('t_product_vendor'); $this->assertSame(1, DB::table('tasks')->where('dedupe_key', $key)->count(), 'one task per record');
        // the limit lowered below the score (as if filled) → the task closes by itself; "No tasks" opens none
        \App\Services\Choices::set('DQ_TASK_BELOW_PCT', '0', 1); $this->assertGreaterThanOrEqual(1, DQ::run('t_product_vendor')['closed']); $this->assertSame('DONE', DB::table('tasks')->where('id', $t[0]->id)->value('status'));
        $this->assertNull(DQ::chip('no_such_form', 1));
    }
}
