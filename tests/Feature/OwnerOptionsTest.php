<?php

namespace Tests\Feature;

use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Owner 27-11 07:24: workspaces keep / off / delete-if-empty; a bank account → our company, a Money bank account (made from it), or kept apart. */
class OwnerOptionsTest extends TestCase
{
    public function test_workspaces_and_bank_links_are_the_owners_choice(): void
    {
        $this->actingAs($this->owner);
        $empty = (int) DB::table('t_enterprise')->insertGetId(RecordService::fillNotNull('t_enterprise', ['ent_name' => 'Empty test space', 'iso_ent' => 'ETS', 'f_active' => 1]));
        $full = (int) DB::table('t_enterprise')->insertGetId(RecordService::fillNotNull('t_enterprise', ['ent_name' => 'Full space', 'iso_ent' => 'FSP', 'f_active' => 1]));
        DB::table('t_product_vendors')->insert(RecordService::fillNotNull('t_product_vendors', ['fk_id_ent' => $full, 'SKU_VENDOR' => 'OO-' . uniqid(), 'product_name_vendor' => 'x']));
        $this->postJson('/api/owner-options/workspace/1', ['action' => 'off'])->assertStatus(422);      // the one you are in
        $this->postJson("/api/owner-options/workspace/$full", ['action' => 'delete'])->assertStatus(422)->assertJsonFragment(['message' => 'Full space is not empty: 1 product(s). Switch it off instead – nothing is lost.']);
        $this->postJson("/api/owner-options/workspace/$full", ['action' => 'off'])->assertOk(); $this->assertSame(0, (int) DB::table('t_enterprise')->where('id', $full)->value('f_active'));
        $this->postJson("/api/owner-options/workspace/$full", ['action' => 'on'])->assertOk();
        $this->postJson("/api/owner-options/workspace/$empty", ['action' => 'delete'])->assertOk(); $this->assertNull(DB::table('t_enterprise')->find($empty));

        $acc = (int) DB::table('t_account')->insertGetId(RecordService::fillNotNull('t_account', ['fk_id_ent' => 1, 'first_name' => 'Bank', 'sur_name' => 'Holder', 'f_active' => 1]));
        $bank = (int) DB::table('t_account_banks')->insertGetId(RecordService::fillNotNull('t_account_banks', ['fk_id_account' => $acc, 'account_no' => '12345678', 'atm_pin' => '9999', 'f_active' => 1]));
        $co = (int) DB::table('cat_company_house')->insertGetId(RecordService::fillNotNull('cat_company_house', ['fk_id_ent' => 1, 'company_name' => 'Opt Co Ltd', 'f_active' => 1]));
        $p = $this->getJson('/api/owner-options')->assertOk(); $row = collect($p->json('banks'))->firstWhere('id', $bank);
        $this->assertSame(['••••5678', 'OPEN'], [$row['number'], $row['state']]); $this->assertStringNotContainsString('9999', $p->getContent()); $this->assertStringNotContainsString('12345678', $p->getContent());
        $this->postJson("/api/owner-options/bank/$bank", ['company' => $co])->assertOk();
        $this->postJson("/api/owner-options/bank/$bank", ['money' => 'new'])->assertOk();
        $b = DB::table('t_account_banks')->find($bank); $this->assertSame($co, (int) $b->fk_id_company_house); $m = DB::table('fin_bank_accounts')->find($b->fk_id_fin_bank);
        $this->assertSame([1, 'Bank Holder ••••5678'], [(int) $m->company_id, $m->account_label]);
        $this->postJson("/api/owner-options/bank/$bank", ['apart' => true])->assertOk();
        $b = DB::table('t_account_banks')->find($bank); $this->assertSame([null, null, 1], [$b->fk_id_company_house, $b->fk_id_fin_bank, (int) $b->f_kept_apart]);
        $this->assertNotNull(DB::table('fin_bank_accounts')->find($m->id), 'the Money account itself is never deleted by unlinking');
        $this->actingAs($this->makeUser('MANAGER'))->getJson('/api/owner-options')->assertStatus(403);
    }
}
