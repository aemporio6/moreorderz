<?php

namespace Tests\Feature;

use App\Services\RecordService;
use App\Services\TaskEngine;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-C-48 WhatsApp number chosen from the person's own mobiles, kept by the system, unique. */
class WhatsAppNumberTest extends TestCase
{
    private function err(string $form, array $v, ?int $id): array
    {
        try { RecordService::save($form, $v, $id); return []; } catch (\Illuminate\Validation\ValidationException $e) { return $e->errors(); }
    }

    public function test_rule_example(): void
    {
        $this->actingAs($this->makeUser('OWNER')); \App\Services\Context::set(1, null);
        $f = fn ($t, $d) => (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
        $country = (int) (DB::table('cat_country')->value('id') ?: $f('cat_country', ['country_name' => 'United Kingdom'])); $net = $f('cat_mobile_network', ['network_name' => 'N1', 'fk_id_country' => $country, 'f_active' => 1]);
        $p = $f('t_account', ['fk_id_ent' => 1, 'account_code' => 'WAP1', 'first_name' => 'Pia', 'sur_name' => 'P', 'f_active' => 1]);
        $q = $f('t_account', ['fk_id_ent' => 1, 'account_code' => 'WAQ1', 'first_name' => 'Quin', 'sur_name' => 'Q', 'f_active' => 1]);
        $sim = fn ($acc, $no, $s) => $f('t_account_mobiles', ['fk_id_account' => $acc, 'mobile_no' => $no, 'simno' => $s, 'l_office_home' => 'O', 'fk_id_country' => $country, 'fk_id_mobile_network' => $net, 'mobile_capacity' => '1', 'f_active' => 1]);
        $m1 = $sim($p, '+447700900123', 'P1'); $m2 = $sim($p, '+923001234567', 'P2'); $qm = $sim($q, '+447700900999', 'Q1');
        $own = \App\Services\OwnItems::class;
        $this->assertSame([], $this->err('t_account', ['whatsapp_number' => (string) $m1], $p));
        $this->assertSame(['+447700900123', $m1], [DB::table('t_account')->where('id', $p)->value('whatsapp_number'), (int) DB::table('t_account')->where('id', $p)->value('fk_id_whatsapp_mobile')]);
        $this->assertSame([], $this->err('t_account_mobile', ['mobile_no' => '+447700900888'], $m1));
        $this->assertSame('+447700900888', DB::table('t_account')->where('id', $p)->value('whatsapp_number'), 'the WhatsApp number follows its mobile');
        DB::table('t_account_mobiles')->where('id', $qm)->update(['mobile_no' => '+447700900888']);
        $this->assertSame(['This number is already the WhatsApp number of WAP1 – Pia P.'], $this->err('t_account', ['whatsapp_number' => (string) $qm], $q)['whatsapp_number'] ?? null);
        $this->assertSame([], $this->err('t_account_mobile', ['f_active' => 0], $m1));
        $this->assertSame([null, null], [DB::table('t_account')->where('id', $p)->value('whatsapp_number'), DB::table('t_account')->where('id', $p)->value('fk_id_whatsapp_mobile')], 'switched off → cleared');
        $this->assertTrue(TaskEngine::isOpen('ACC_WA_NUMBER', [$p]));
        $this->assertSame([], $this->err('t_account', ['whatsapp_number' => (string) $m2], $p));
        $this->assertFalse(TaskEngine::isOpen('ACC_WA_NUMBER', [$p]), 'choosing a new number closes the task');
    }
}
