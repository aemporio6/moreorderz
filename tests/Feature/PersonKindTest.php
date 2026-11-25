<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\PersonKind;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-C-2 Convert Managed ↔ External: kind log, history, event; sections by kind (shown, kept, not accepted on save); settings. */
class PersonKindTest extends TestCase
{
    private function f(string $t, array $d): int { return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }

    public function test_c2_convert_keeps_data_logs_and_hides_sections(): void
    {
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $p = $this->f('t_account', ['fk_id_ent' => 1, 'account_code' => 'PK' . random_int(1000, 9999), 'first_name' => 'Kind', 'sur_name' => 'Test', 'f_active' => 1, 'l_account_kind' => 'M']);
        for ($i = 1; $i <= 3; $i++) $this->f('t_account_banks', ['fk_id_account' => $p, 'account_no' => '1000000' . $i, 'f_active' => 1]);
        for ($i = 1; $i <= 2; $i++) $this->f('t_account_pc', ['fk_id_account' => $p]);
        $form = RecordService::formOfTable('t_account'); $code = DB::table('t_account')->where('id', $p)->value('account_code');

        $h = $this->getJson("/api/people/$p/header")->assertOk()->json();
        $this->assertSame(['Managed Account', 'E', true], [$h['kind_badge'], $h['convert_to'], $h['can_convert']]);
        $this->assertSame('This person becomes an External Contact. Only the tabs a supplier needs will show. Nothing is deleted.', $h['convert_text']);
        $this->assertContains('pcs', array_column($h['chips'], 'key'));
        $this->assertContains(RecordService::formOfTable('t_account_pc'), array_column(RecordService::page($form, $p)['children'], 'form_key'));

        // to External
        $this->postJson("/api/people/$p/convert", ['to' => 'E', 'note' => str_repeat('x', 256)])->assertStatus(422);
        $ev = DB::table('sys_events')->where('event_code', 'PERSON_KIND_CHANGED')->count();
        $this->postJson("/api/people/$p/convert", ['to' => 'E', 'note' => 'Is a supplier'])->assertOk()->assertJsonPath('message', "Account $code is now an External Contact.");
        $this->assertSame('E', DB::table('t_account')->where('id', $p)->value('l_account_kind'));
        $this->assertSame(1, DB::table('t_account_kind_log')->where('fk_id_account', $p)->where('from_kind', 'M')->where('to_kind', 'E')->where('note', 'Is a supplier')->count(), 'kind log has 1 row');
        $this->assertSame(1, DB::table('sys_audit_log')->where('table_name', 't_account')->where('record_id', $p)->where('reason', 'like', 'Converted to External Contact%')->count(), 'history has 1 line');
        $this->assertSame($ev + 1, DB::table('sys_events')->where('event_code', 'PERSON_KIND_CHANGED')->count());
        $this->assertSame([3, 2], [DB::table('t_account_banks')->where('fk_id_account', $p)->count(), DB::table('t_account_pc')->where('fk_id_account', $p)->count()], 'nothing is deleted');
        $page = RecordService::page($form, $p); $kids = array_column($page['children'], 'form_key');
        $this->assertNotContains(RecordService::formOfTable('t_account_pc'), $kids, 'PCs not shown'); $this->assertContains(RecordService::formOfTable('t_account_banks'), $kids, 'banks shown');
        $this->assertArrayNotHasKey('Access', $page['sections']); $this->assertArrayHasKey('Home', $page['sections']);
        $h = $this->getJson("/api/people/$p/header")->assertOk()->json();
        $this->assertSame('External Contact — Short profile', $h['kind_badge']); $this->assertNotContains('pcs', array_column($h['chips'], 'key')); $this->assertContains('banks', array_column($h['chips'], 'key'));
        $this->assertCount(1, $h['kind_log']);

        // hidden for E: a PC is not accepted, a field of a hidden section is not saved; a bank still is
        try { RecordService::save(RecordService::formOfTable('t_account_pc'), ['fk_id_account' => $p]); $this->fail('PC saved for an External Contact'); }
        catch (\Illuminate\Validation\ValidationException $e) { $this->assertStringContainsString('not used for an External Contact', collect($e->errors())->flatten()->first()); }
        $this->assertSame(2, DB::table('t_account_pc')->where('fk_id_account', $p)->count());
        $acc = collect(\App\Services\Registry::fields($form))->first(fn ($f) => $f['section'] === 'Access' && $f['column_name'] && in_array($f['type'], ['text', 'check', 'number'], true));
        if ($acc) { $d = [$acc['column_name'] => $acc['type'] === 'text' ? 'zz' : 1]; $this->assertSame([], PersonKind::guard('t_account', $form, $d, ['l_account_kind' => 'E'], $p)); $this->assertSame([], $d, 'a field of a hidden section is dropped'); }

        // same kind again does nothing
        $this->postJson("/api/people/$p/convert", ['to' => 'E'])->assertOk()->assertJsonPath('changed', false);
        $this->assertSame(1, DB::table('t_account_kind_log')->where('fk_id_account', $p)->count());

        // settings: show PCs for everyone → shown again for this External Contact; Home cannot be hidden
        $rows = collect($this->getJson('/api/people/sections')->assertOk()->json('rows'));
        $pc = $rows->firstWhere('table', 't_account_pc'); $this->assertSame('M', $pc['visible_kind']); $this->assertSame('ALL', $rows->firstWhere('table', 't_account_banks')['visible_kind']);
        $this->postJson('/api/people/sections/' . $pc['id'], ['visible_kind' => 'ALL'])->assertOk();
        $this->assertContains(RecordService::formOfTable('t_account_pc'), array_column(RecordService::page($form, $p)['children'], 'form_key'));
        $this->postJson('/api/people/sections/' . $pc['id'], ['visible_kind' => 'M'])->assertOk();
        $home = $rows->first(fn ($r) => $r['form_key'] === 't_account' && $r['title'] === 'Home'); $this->postJson('/api/people/sections/' . $home['id'], ['visible_kind' => 'M'])->assertStatus(422);

        // back to Managed; no right → refused
        $this->postJson("/api/people/$p/convert", ['to' => 'M'])->assertOk()->assertJsonPath('message', "Account $code is now a Managed Account.");
        $this->assertContains(RecordService::formOfTable('t_account_pc'), array_column(RecordService::page($form, $p)['children'], 'form_key'));
        $va = $this->makeUser('VA'); $this->actingAs($va)->postJson("/api/people/$p/convert", ['to' => 'E'])->assertStatus(403);
        $this->assertSame('M', DB::table('t_account')->where('id', $p)->value('l_account_kind'));
        PersonKind::forget();
    }
}
