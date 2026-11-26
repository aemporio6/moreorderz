<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\RecordService;
use App\Services\ShareLinks;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** R-B_SOURCING-64 supplier link without login: hash only, expiry, one purpose, only the PO's own fields, audit per use, revoke. */
class ShareLinksTest extends TestCase
{
    private function f(string $t, array $d): int { return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }

    public function test_b64_links_per_purpose_revoke_and_expiry(): void
    {
        Storage::fake('local');
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $sup = $this->f('t_src_suppliers', ['fk_id_ent' => 1, 'supplier_code' => 'S-64', 'supplier_name' => 'Link Supplier Co', 'phone_whatsapp' => '+86 138 0000 0000', 'email' => 'sales@link-supplier.test', 'f_active' => 1]);
        $other = $this->f('t_src_suppliers', ['fk_id_ent' => 1, 'supplier_code' => 'S-65', 'supplier_name' => 'Hidden Rival Ltd', 'f_active' => 1]);
        $cur = DB::table('cat_currency')->where('currency_code', 'USD')->value('id') ?: $this->f('cat_currency', ['currency_code' => 'USD', 'currency_name' => 'US dollar', 'f_active' => 1]);
        $cycle = $this->f('t_src_cycles', ['fk_id_ent' => 1, 'cycle_code' => 'CYC-64', 'product_name' => 'Mug', 'status' => 'OPEN', 'f_active' => 1]);
        $prod = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'MUG-64', 'product_name_vendor' => 'Mug', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']);
        $cp = $this->f('t_src_cycle_products', ['fk_id_ent' => 1, 'fk_id_cycle' => $cycle, 'product_name' => 'Steel mug', 'sku' => 'MUG-64', 'fk_id_product' => $prod, 'f_active' => 1]);
        $po = $this->f('t_src_po', ['fk_id_ent' => 1, 'po_code' => 'PO-64', 'po_number' => 'PO-64', 'po_date' => '2026-11-20', 'fk_id_cycle' => $cycle, 'fk_id_supplier' => $sup, 'fk_id_currency' => $cur, 'po_total' => 2000, 'status' => 'CONFIRMED', 'requested_by' => $this->owner->id, 'f_active' => 1]);
        $this->f('t_src_po_lines', ['fk_id_ent' => 1, 'fk_id_cycle' => $cycle, 'fk_id_po' => $po, 'fk_id_cycle_product' => $cp, 'fk_id_product' => $prod, 'sku' => 'MUG-64', 'qty_ordered' => 1000, 'unit_price' => 2, 'line_total' => 2000, 'line_type' => 'PRODUCT', 'f_active' => 1]);
        $otherPo = $this->f('t_src_po', ['fk_id_ent' => 1, 'po_code' => 'PO-65', 'po_number' => 'PO-65', 'po_date' => '2026-11-20', 'fk_id_cycle' => $cycle, 'fk_id_supplier' => $other, 'fk_id_currency' => $cur, 'po_total' => 777.77, 'status' => 'CONFIRMED', 'f_active' => 1]);

        // right src.share_link; a draft PO is not sent; only the hash is stored
        $va = $this->makeUser('VA'); $this->actingAs($va)->postJson("/api/po/$po/share-links", ['purpose' => 'PO_CONFIRM'])->assertStatus(403); $this->actingAs($this->owner);
        $this->postJson("/api/po/$po/share-links", ['purpose' => 'NOPE'])->assertStatus(422);
        $l = $this->postJson("/api/po/$po/share-links", ['purpose' => 'PO_CONFIRM'])->assertOk()->json();
        $token = substr($l['url'], strrpos($l['url'], '/') + 1); $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        $row = DB::table('sys_share_links')->where('id', $l['id'])->first();
        $this->assertSame([hash('sha256', $token), $sup, 0, 'PO'], [$row->link_hash, (int) $row->fk_id_party, (int) $row->used_count, $row->entity_type]);
        $this->assertStringNotContainsString($token, json_encode(DB::table('sys_share_links')->get()) . json_encode(DB::table('sys_audit_log')->where('table_name', 'sys_share_links')->get()), 'the link itself is stored nowhere');
        $this->assertSame(14, (int) round((strtotime($row->expires_at) - time()) / 86400)); $this->assertStringContainsString('wa.me/8613800000000', $l['whatsapp']);

        // the page needs no login and shows only this PO: its own prices, no other supplier, no other PO; a GET writes nothing
        auth()->logout(); $before = [DB::table('sys_audit_log')->count(), (int) DB::table('sys_share_links')->where('id', $l['id'])->value('used_count')];
        $html = $this->get("/s/$token")->assertOk()->getContent();
        foreach (['PO-64', 'MUG-64 Steel mug', '2,000.00', 'Accept this purchase order'] as $t) $this->assertStringContainsString($t, $html);
        foreach (['Hidden Rival', 'PO-65', '777.77', 'CYC-64'] as $t) $this->assertStringNotContainsString($t, $html);
        $this->assertSame($before, [DB::table('sys_audit_log')->count(), (int) DB::table('sys_share_links')->where('id', $l['id'])->value('used_count')]);
        $this->get('/s/' . str_repeat('a', 64))->assertStatus(410)->assertSee('This link has expired – ask your contact for a new one.');
        // a link does only its own purpose
        $this->post("/s/$token/date", ['date' => date('Y-m-d', strtotime('+10 days'))])->assertRedirect("/s/$token"); $this->assertNull(DB::table('t_src_po')->where('id', $po)->value('supplier_ship_date'));
        // ask a change → a task to the PO owner; accept → the step "Sent / confirmed" is done, audit row with party + IP
        $this->post("/s/$token/change", ['note' => 'Please ship in 2 lots'])->assertRedirect();
        $task = DB::table('tasks')->where('dedupe_key', 'SRC_PO_CHANGE_ASKED:' . $po)->first(); $this->assertNotNull($task); $this->assertSame((int) $this->owner->id, (int) $task->owner_user_id);
        $this->post("/s/$token/accept")->assertRedirect();
        $p = DB::table('t_src_po')->where('id', $po)->first(); $this->assertSame('SENT', $p->status); $this->assertNotNull($p->supplier_confirmed_at);
        $this->assertSame(2, (int) DB::table('sys_share_links')->where('id', $l['id'])->value('used_count'));
        $a = DB::table('sys_audit_log')->where('table_name', 'sys_share_links')->where('record_id', $l['id'])->where('reason', 'Share link used: PO accepted')->first(); $this->assertNotNull($a);
        $ch = json_decode($a->changes_json, true); $this->assertSame($sup, $ch['party']); $this->assertArrayHasKey('ip', $ch);
        $this->assertContains(DB::table('tasks')->where('id', $task->id)->value('status'), ['DONE', 'CLOSED', 'CANCELLED'], 'the change task closes when the PO is accepted');

        // revoke → refused
        $this->actingAs($this->owner); $this->postJson("/api/share-links/{$l['id']}/revoke")->assertOk(); auth()->logout();
        $this->get("/s/$token")->assertStatus(410); $this->post("/s/$token/accept")->assertStatus(410);

        // ship date → proposal + task; the owner accepts it
        $this->actingAs($this->owner); $t2 = substr($x = $this->postJson("/api/po/$po/share-links", ['purpose' => 'SHIP_DATE'])->json('url'), strrpos($x, '/') + 1); auth()->logout();
        $day = date('Y-m-d', strtotime('+12 days')); $this->post("/s/$t2/date", ['date' => $day])->assertRedirect();
        $this->assertSame($day, DB::table('t_src_po')->where('id', $po)->value('supplier_ship_date')); $this->assertNotNull(DB::table('tasks')->where('dedupe_key', 'SRC_SHIP_DATE_PROPOSED:' . $po)->first());
        $this->assertStringNotContainsString('2,000.00', $this->get("/s/$t2")->getContent(), 'no prices on a link that is not the PO confirm');
        $this->actingAs($this->owner); $this->postJson("/api/po/$po/ship-date/accept")->assertOk(); $this->assertSame($day, DB::table('t_src_po')->where('id', $po)->value('required_ship_date'));

        // production photos → documents of the PO + DOC_ADDED; a non-image is refused
        $t3 = substr($x = $this->postJson("/api/po/$po/share-links", ['purpose' => 'PRODUCTION_PHOTO'])->json('url'), strrpos($x, '/') + 1); auth()->logout();
        $docs = DB::table('t_src_documents')->where('fk_id_po', $po)->count(); $ev = DB::table('sys_events')->where('event_code', 'DOC_ADDED')->count();
        $this->post("/s/$t3/photos", ['photos' => [UploadedFile::fake()->create('note.pdf', 10, 'application/pdf')]])->assertRedirect(); $this->assertSame($docs, DB::table('t_src_documents')->where('fk_id_po', $po)->count());
        $this->post("/s/$t3/photos", ['photos' => [UploadedFile::fake()->image('line1.jpg'), UploadedFile::fake()->image('line2.png')]])->assertRedirect();
        $this->assertSame([$docs + 2, $ev + 2], [DB::table('t_src_documents')->where('fk_id_po', $po)->count(), DB::table('sys_events')->where('event_code', 'DOC_ADDED')->count()]);

        // artwork: only APPROVED versions of the PO's products; an expired link is refused
        $this->actingAs($this->owner);
        $d1 = $this->f('sys_documents', ['fk_id_ent' => 1, 'doc_no' => 'D-A1', 'title' => 'Mug label', 'orig_name' => 'label.pdf', 'stored_name' => 'artwork/label.pdf', 'mime' => 'application/pdf', 'status' => 'ACTIVE']);
        $d2 = $this->f('sys_documents', ['fk_id_ent' => 1, 'doc_no' => 'D-A2', 'title' => 'Mug label draft', 'orig_name' => 'draft.pdf', 'stored_name' => 'artwork/draft.pdf', 'mime' => 'application/pdf', 'status' => 'ACTIVE']);
        Storage::disk('local')->put('artwork/label.pdf', 'PDF-A'); Storage::disk('local')->put('artwork/draft.pdf', 'PDF-D');
        $ok = $this->f('product_artwork_files', ['fk_id_ent' => 1, 'fk_id_product' => $prod, 'artwork_kind' => 'LABEL', 'fk_id_document' => $d1, 'version_no' => '2', 'artwork_status' => 'APPROVED']);
        $draft = $this->f('product_artwork_files', ['fk_id_ent' => 1, 'fk_id_product' => $prod, 'artwork_kind' => 'LABEL', 'fk_id_document' => $d2, 'version_no' => '3', 'artwork_status' => 'DRAFT']);
        $l4 = $this->postJson("/api/po/$po/share-links", ['purpose' => 'ARTWORK_DOWNLOAD'])->json(); $t4 = substr($l4['url'], strrpos($l4['url'], '/') + 1); auth()->logout();
        $h = $this->get("/s/$t4")->assertOk()->getContent(); $this->assertStringContainsString('Mug label v2', $h); $this->assertStringNotContainsString('Mug label draft', $h);
        $this->assertSame('PDF-A', $this->get("/s/$t4/file/$ok")->assertOk()->getContent()); $this->get("/s/$t4/file/$draft")->assertStatus(404);
        DB::table('sys_share_links')->where('id', $l4['id'])->update(['expires_at' => now()->subMinute()]);
        $this->get("/s/$t4")->assertStatus(410); $this->get("/s/$t4/file/$ok")->assertStatus(410);
        $this->actingAs($this->owner); $rows = collect($this->getJson("/api/po/$po/share-links")->assertOk()->json('rows'))->keyBy('id');
        $this->assertSame(['Revoked', 'Expired'], [$rows[$l['id']]['state'], $rows[$l4['id']]['state']]);
        $this->assertNull(ShareLinks::resolve('not-a-token'));
    }

    /** Owner 26-11 21:31 (MY TOOL supplier_link): upload-only link per supplier; files filed by themselves; nothing of ours is shown. */
    public function test_supplier_upload_link_files_itself_and_shows_nothing(): void
    {
        Storage::fake('local');
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $sup = $this->f('t_src_suppliers', ['fk_id_ent' => 1, 'supplier_code' => 'S-UP', 'supplier_name' => 'Upload Supplier Co', 'contact_person' => 'Lily', 'phone_whatsapp' => '+86 138 1111 2222', 'f_active' => 1]);
        $rival = $this->f('t_src_suppliers', ['fk_id_ent' => 1, 'supplier_code' => 'S-RV', 'supplier_name' => 'Rival Upload Ltd', 'f_active' => 1]);
        $cycle = $this->f('t_src_cycles', ['fk_id_ent' => 1, 'cycle_code' => 'CYC-UP', 'product_name' => 'Lamp', 'status' => 'OPEN', 'f_active' => 1]);
        $po = $this->f('t_src_po', ['fk_id_ent' => 1, 'po_code' => 'PO-UP-7781', 'po_number' => 'PO-UP-7781', 'po_date' => '2026-11-20', 'fk_id_cycle' => $cycle, 'fk_id_supplier' => $sup, 'po_total' => 4321.5, 'status' => 'CONFIRMED', 'f_active' => 1]);
        $rpo = $this->f('t_src_po', ['fk_id_ent' => 1, 'po_code' => 'PO-RV-9001', 'po_number' => 'PO-RV-9001', 'po_date' => '2026-11-20', 'fk_id_cycle' => $cycle, 'fk_id_supplier' => $rival, 'po_total' => 999, 'status' => 'CONFIRMED', 'f_active' => 1]);
        $va = $this->makeUser('VA'); $this->actingAs($va)->postJson("/api/suppliers/$sup/upload-link")->assertStatus(403); $this->actingAs($this->owner);
        $l = $this->postJson("/api/suppliers/$sup/upload-link")->assertOk()->json();
        $this->assertStringContainsString('您好', $l['text']); $this->assertStringContainsString('wa.me/8613811112222', (string) $l['whatsapp']);
        $t = substr($l['url'], strrpos($l['url'], '/') + 1);
        $this->assertFalse(DB::table('sys_share_links')->where('link_hash', $t)->exists(), 'only the hash is kept');
        auth()->logout();
        $page = $this->get("/s/$t")->assertOk()->getContent();
        foreach (['4,321', '4321', 'Rival', 'PO-UP-7781', 'PO-RV-9001'] as $hidden) $this->assertStringNotContainsString($hidden, $page, "the upload page shows no $hidden");
        $this->assertStringContainsString('上传文件', $page);
        // the PO link actions cannot be used on an upload link, and a PO link cannot upload
        $this->post("/s/$t/accept")->assertRedirect(); $this->assertNull(DB::table('t_src_po')->where('id', $po)->value('supplier_confirmed_at'));
        $this->get("/s/$t/file/1")->assertStatus(410);
        // a packing list naming his PO → filed to that PO; the rival's PO number is never matched; a .exe is refused
        $this->post("/s/$t/upload", ['files' => [UploadedFile::fake()->create('run.exe', 5)]])->assertRedirect(); $this->assertSame(0, DB::table('t_src_documents')->where('uploaded_by', \App\Services\SupplierUploads::VIA)->count());
        $this->post("/s/$t/upload", ['files' => [UploadedFile::fake()->create('Packing List PO-UP-7781.xlsx', 10), UploadedFile::fake()->create('PI for PO-RV-9001.pdf', 10, 'application/pdf'), UploadedFile::fake()->image('carton.jpg')], 'note' => 'first lot'])->assertRedirect();
        $docs = DB::table('t_src_documents')->where('uploaded_by', \App\Services\SupplierUploads::VIA)->orderBy('id')->get();
        $this->assertCount(3, $docs);
        $this->assertSame(['Packing list', 'Proforma invoice (PI)', 'Product photos'], $docs->pluck('doc_category')->all());
        $this->assertSame([$po, null, null], $docs->map(fn ($d) => $d->fk_id_po ? (int) $d->fk_id_po : null)->all(), 'only his own PO is matched');
        // the inbox: attach, refuse another supplier's PO, done
        $this->actingAs($this->owner);
        $in = $this->getJson('/api/supplier-inbox')->assertOk()->json();
        $this->assertCount(3, $in['items']); $this->assertSame('Upload Supplier Co', $in['items'][0]['supplier']);
        $this->postJson('/api/supplier-inbox/' . $docs[1]->id, ['po_id' => $rpo])->assertStatus(422);
        $this->postJson('/api/supplier-inbox/' . $docs[1]->id, ['po_id' => $po, 'kind' => 'Commercial invoice'])->assertOk();
        $this->postJson('/api/supplier-inbox/' . $docs[1]->id, ['done' => true])->assertOk();
        $this->assertCount(2, $this->getJson('/api/supplier-inbox')->json('items'));
        $this->get('/api/supplier-inbox/' . $docs[0]->id . '/file')->assertOk();
        // stop the link → it no longer opens
        $this->postJson('/api/share-links/' . $l['id'] . '/revoke')->assertOk();
        auth()->logout(); $this->get("/s/$t")->assertStatus(410);
    }
}
