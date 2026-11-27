<?php

namespace Tests\Feature;

use App\Services\Contacts;
use App\Services\RecordService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Owner 27-11: contact list, message through the person's own app with history, replies with files / voice, evidence on a record, extra numbers. */
class ContactsTest extends TestCase
{
    public function test_conversation_with_a_supplier_and_evidence_on_its_po(): void
    {
        Storage::fake('local'); $this->actingAs($this->owner);
        $s = (int) DB::table('t_src_suppliers')->insertGetId(RecordService::fillNotNull('t_src_suppliers', ['fk_id_ent' => 1, 'supplier_code' => 'CT1', 'supplier_name' => 'Chat Factory', 'phone_whatsapp' => '+86 138-0000-0000', 'wechat' => 'chatfactory88', 'email' => 'sales@chat.test', 'f_active' => 1]));
        $po = (int) DB::table('t_src_po')->insertGetId(RecordService::fillNotNull('t_src_po', ['fk_id_ent' => 1, 'po_number' => 'PO-CHAT-1', 'fk_id_supplier' => $s, 'status' => 'CONFIRMED', 'f_active' => 1]));
        $row = collect($this->getJson('/api/contacts?q=chat fac')->assertOk()->json('rows'))->firstWhere('name', 'Chat Factory');
        $this->assertSame(['SUPPLIER', ['WHATSAPP', 'WECHAT', 'EMAIL']], [$row['type'], $row['has']]);
        $out = $this->postJson("/api/contacts/supplier/$s", ['channel' => 'WHATSAPP', 'text' => 'Please send the corrected invoice', 'address' => '+86 138-0000-0000', 'link_table' => 't_src_po', 'link_id' => $po])->assertOk()->json();
        $this->assertSame('https://wa.me/8613800000000?text=Please%20send%20the%20corrected%20invoice', $out['url']);
        $this->assertSame('chatfactory88', $this->postJson("/api/contacts/supplier/$s", ['channel' => 'WECHAT', 'text' => 'ni hao', 'address' => 'chatfactory88'])->assertOk()->json('copy'));
        $in = $this->post("/api/contacts/supplier/$s", ['channel' => 'WHATSAPP', 'direction' => 'IN', 'text' => 'Here is the right one', 'link_table' => 't_src_po', 'link_id' => $po, 'evidence' => 1, 'evidence_note' => 'Supplier agreed the new total',
            'file' => UploadedFile::fake()->create('voice-note.ogg', 40, 'audio/ogg')], ['Accept' => 'application/json'])->assertOk()->json('id');
        $t = $this->getJson("/api/contacts/supplier/$s")->assertOk()->json();
        $this->assertCount(3, $t['lines']); $last = collect($t['lines'])->firstWhere('id', $in);
        $this->assertSame(['voice', true, 'Purchase order PO-CHAT-1'], [$last['file']['kind'], $last['evidence'], $last['link']['label']]);
        $this->get($last['file']['url'])->assertOk()->assertHeader('Content-Type', 'audio/ogg');
        $rec = $this->getJson("/api/contacts/record/t_src_po/$po")->assertOk()->json();
        $this->assertSame([2, 1, 'SUPPLIER', $s], [count($rec['lines']), $rec['evidence'], $rec['party']['type'], $rec['party']['id']]); $this->assertTrue($rec['lines'][0]['evidence']);
        $this->postJson("/api/contacts/evidence/$in", ['on' => false])->assertOk(); $this->assertSame(0, (int) DB::table('comm_log')->where('id', $in)->value('f_evidence'));
        $this->postJson("/api/contacts/evidence/{$out['id']}", ['on' => true, 'note' => 'We asked on this day'])->assertOk(); $this->assertSame(1, (int) DB::table('comm_log')->where('id', $out['id'])->value('f_evidence'));
        $this->postJson("/api/contacts/supplier/$s/contact", ['channel' => 'WHATSAPP', 'address' => '0086 139 1111 2222', 'label' => 'Boss'])->assertOk();
        $this->assertContains('+8613911112222', array_column(Contacts::party(1, 'SUPPLIER', $s)['channels'], 'address'));
        $this->postJson("/api/contacts/supplier/$s", ['channel' => 'WHATSAPP', 'text' => ''])->assertStatus(422);
        $this->post("/api/contacts/supplier/$s", ['channel' => 'NOTE', 'direction' => 'IN', 'file' => UploadedFile::fake()->create('run.exe', 5)], ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertSame(3, DB::table('comm_log')->count(), 'nothing is deleted from a conversation');
        $this->assertSame('mailto:a@b.co?body=Hi', Contacts::url('EMAIL', 'a@b.co', 'Hi')); $this->assertNull(Contacts::url('WECHAT', 'x', 'y'));
    }
}
