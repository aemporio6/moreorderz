<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\Intake;
use App\Services\RecordService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Owner 26-11 22:28: one importer for everything – kept, read, understood, offered to its place, nothing done before the yes. */
class IntakeTest extends TestCase
{
    private function f(string $t, array $d): int { return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }

    public function test_one_importer_reads_understands_and_places(): void
    {
        Storage::fake('local'); $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $sup = $this->f('t_src_suppliers', ['fk_id_ent' => 1, 'supplier_code' => 'S-IX', 'supplier_name' => 'Ningbo Bright Housewares', 'f_active' => 1]);
        $cy = $this->f('t_src_cycles', ['fk_id_ent' => 1, 'cycle_code' => 'CYC-IX', 'product_name' => 'Kettle', 'status' => 'OPEN', 'f_active' => 1]);
        $po = $this->f('t_src_po', ['fk_id_ent' => 1, 'po_code' => 'PO-IX-4410', 'po_number' => 'PO-IX-4410', 'po_date' => '2026-11-01', 'fk_id_cycle' => $cy, 'fk_id_supplier' => $sup, 'po_total' => 5230.4, 'status' => 'CONFIRMED', 'f_active' => 1]);
        $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'KET-IX-01', 'product_name_vendor' => 'Steel kettle', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']);
        // 1. a PDF read by the browser: a commercial invoice naming the supplier, the PO and a product
        $pdf = UploadedFile::fake()->createWithContent('scan 0091', "%PDF-1.4\nbinary");
        $r = $this->post('/api/intake', ['files' => [$pdf], 'texts' => ["COMMERCIAL INVOICE\nSeller: Ningbo Bright Housewares\nOrder no PO-IX-4410\nKET-IX-01 Steel kettle 1000 pcs\nTotal USD 5,230.40\nDate 2026-11-20"], 'readers' => ['PDF text']])->assertOk()->json('items.0');
        $this->assertSame('pdf', $r['ext'], 'the kind comes from the bytes, not the name'); $this->assertSame('scan 0091.pdf', $r['name']);
        $this->assertSame('Commercial invoice', $r['facts']['kind']); $this->assertSame('Ningbo Bright Housewares', $r['facts']['supplier']);
        $this->assertSame(['PO-IX-4410'], $r['facts']['pos']); $this->assertSame(['KET-IX-01'], $r['facts']['products']); $this->assertSame(5230.4, $r['facts']['amount']); $this->assertSame('USD', $r['facts']['currency']); $this->assertSame('2026-11-20', $r['facts']['date']);
        $best = $r['proposals'][0]; $this->assertSame(['attach', 't_src_po', $po], [$best['action'], $best['entity'], $best['entity_id']]);
        $this->assertSame(0, DB::table('sys_documents')->count(), 'nothing is done before the yes');
        $this->postJson('/api/intake/' . $r['id'], ['action' => 'attach', 'entity' => 't_src_po', 'entity_id' => $po, 'category' => 'Commercial invoice'])->assertOk();
        $doc = DB::table('sys_documents')->first(); $this->assertStringContainsString('Commercial invoice', $doc->title);
        $this->assertTrue(DB::table('sys_document_links')->where(['fk_id_document' => $doc->id, 'entity' => 't_src_po', 'entity_id' => $po])->exists());
        $this->get('/api/intake/' . $r['id'] . '/file')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        // 2. a receipt screenshot (OCR text) → record the payment, with the amount and date filled in
        $png = UploadedFile::fake()->createWithContent('image.png', "\x89PNG\r\n\x1a\nxx");
        $x = $this->post('/api/intake', ['files' => [$png], 'texts' => ["Transfer successful\nPaid to Ningbo Bright Housewares\nAmount ¥ 12,000.00\n2026/11/22 10:31\nTransaction ID 88231190"], 'sources' => ['SCREENSHOT']])->assertOk()->json('items.0');
        $this->assertSame('png', $x['ext']); $this->assertSame('CNY', $x['facts']['currency']); $this->assertEquals(12000, $x['facts']['amount']); $this->assertSame('2026-11-22', $x['facts']['date']);
        $pay = collect($x['proposals'])->firstWhere('action', 'form'); $this->assertNotNull($pay); $this->assertEquals(12000, $pay['preset']['payment_amount']); $this->assertSame($sup, $pay['preset']['fk_id_supplier']);
        // 3. an unknown seller on a PI → "New supplier" with the name filled in
        $y = $this->post('/api/intake', ['files' => [UploadedFile::fake()->createWithContent('PI.pdf', '%PDF-1.4')], 'texts' => ["PROFORMA INVOICE\nYiwu Sunrise Trading Co., Ltd\nTotal USD 900.00"]])->json('items.0');
        $this->assertSame('Proforma invoice (PI)', $y['facts']['kind']); $new = collect($y['proposals'])->firstWhere('action', 'form'); $this->assertSame('Yiwu Sunrise Trading Co., Ltd', $new['preset']['supplier_name']);
        // 4. a pasted table (from Excel) → the screen whose importer takes it; open = handed over
        $t = $this->postJson('/api/intake', ['text' => "Date\tDescription\tAmount\tBalance\n2026-11-01\tCard payment\t-12.50\t100.00"])->assertOk()->json('items.0');
        $this->assertSame('open', $t['proposals'][0]['action']); $this->assertSame('#/bank', $t['proposals'][0]['screen']);
        $this->postJson('/api/intake/' . $t['id'], ['action' => 'open', 'screen' => '#/bank'])->assertOk();
        // 5. a Word file is read on the server; the same file twice is noticed; set aside stays listed
        $z = new \ZipArchive(); $tmp = tempnam(sys_get_temp_dir(), 'dx'); $z->open($tmp, \ZipArchive::OVERWRITE); $z->addFromString('word/document.xml', '<w:document><w:body><w:p><w:r><w:t>Packing List PO-IX-4410</w:t></w:r></w:p></w:body></w:document>'); $z->close();
        $w = $this->post('/api/intake', ['files' => [new UploadedFile($tmp, 'packing.docx', null, null, true)]])->json('items.0');
        $this->assertSame('docx', $w['ext']); $this->assertSame('Packing list', $w['facts']['kind']); $this->assertSame(['PO-IX-4410'], $w['facts']['pos']);
        $w2 = $this->post('/api/intake', ['files' => [new UploadedFile($tmp, 'packing again.docx', null, null, true)]])->json('items.0'); $this->assertStringContainsString('same file came in before', $w2['again']);
        $this->postJson('/api/intake/' . $w2['id'], ['action' => 'skip'])->assertOk();
        $this->assertSame(1, $this->getJson('/api/intake?status=SKIPPED')->json('counts.SKIPPED'));
        // rights: a role without any of the work areas cannot import; a link inside the server is refused
        $this->postJson('/api/intake', ['link' => 'https://127.0.0.1/secret.csv'])->assertStatus(422);
        $this->assertSame('xlsx', Intake::sniff("PK\x03\x04....xl/workbook.xml", 'noending'));
    }

    /** Next #3: "File a document" from a record – that record is offered first with its guessed kind, the window lists only its files, and the PO / cycle documents list shows it. */
    public function test_file_a_document_from_a_record(): void
    {
        Storage::fake('local'); $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $cy = $this->f('t_src_cycles', ['fk_id_ent' => 1, 'cycle_code' => 'CYC-FD', 'product_name' => 'Lamp', 'status' => 'OPEN', 'f_active' => 1]);
        $po = $this->f('t_src_po', ['fk_id_ent' => 1, 'po_code' => 'PO-FD-1', 'po_number' => 'PO-FD-1', 'po_date' => '2026-11-01', 'fk_id_cycle' => $cy, 'po_total' => 10, 'status' => 'CONFIRMED', 'f_active' => 1]);
        $other = $this->post('/api/intake', ['files' => [UploadedFile::fake()->createWithContent('other.txt', 'hello')]])->assertOk()->json('items.0');
        $pdf = UploadedFile::fake()->createWithContent('packing list march', "%PDF-1.4\nbinary");
        $r = $this->post('/api/intake', ['files' => [$pdf], 'texts' => ["PACKING LIST\nCartons 12\nGross weight 140 kg"], 'ctx_entity' => 't_src_po', 'ctx_id' => $po])->assertOk()->json('items.0');
        $best = $r['proposals'][0]; $this->assertSame(['attach', 't_src_po', $po, 99], [$best['action'], $best['entity'], $best['entity_id'], $best['score']]);
        $this->assertSame('Packing list', $best['category']); $this->assertStringContainsString('PO-FD-1', $best['title']); $this->assertSame(['entity' => 't_src_po', 'id' => $po], $r['ctx']);
        $mine = $this->getJson("/api/intake?status=NEW&ctx_entity=t_src_po&ctx_id=$po")->assertOk()->json('items');
        $this->assertSame([$r['id']], array_column($mine, 'id'), 'the window shows only the files of this record');
        $this->assertNull($this->post('/api/intake', ['files' => [UploadedFile::fake()->createWithContent('x.txt', 'x')], 'ctx_entity' => 't_src_po', 'ctx_id' => 999999])->json('items.0.ctx'), 'a record that does not exist is not taken');
        $this->postJson('/api/intake/' . $r['id'], ['action' => 'attach', 'entity' => 't_src_po', 'entity_id' => $po, 'category' => 'Packing list'])->assertOk();
        $rows = $this->getJson("/api/po/$po/documents")->assertOk()->json('rows');
        $this->assertCount(1, $rows); $this->assertSame('Packing list', $rows[0]['type']); $this->assertTrue($rows[0]['filed']); $this->assertStringContainsString('/api/intake/', $rows[0]['url']);
        $this->assertCount(1, $this->getJson("/api/cycle/$cy/documents")->json('rows'), 'the cycle shows its PO\'s documents too');
        $this->assertNotEquals($other['id'], $r['id']);
    }
}
