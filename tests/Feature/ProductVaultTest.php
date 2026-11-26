<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\ProductVault;
use App\Services\RecordService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Owner 27-11 02:31: every product file goes to its place in the product's vault by itself; the vault says what is missing. */
class ProductVaultTest extends TestCase
{
    private function f(string $t, array $d): int { return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }

    public function test_places_are_read_from_the_file(): void
    {
        $this->assertSame('IMAGE_MAIN', ProductVault::guess('LAMP-01_main.jpg'));
        $this->assertSame('LIFESTYLE', ProductVault::guess('LAMP-01 lifestyle 2.png'));
        $this->assertSame('A_PLUS', ProductVault::guess('LAMP-01 A+ module 3.jpg'));
        $this->assertSame('ARTWORK', ProductVault::guess('LAMP-01 box.ai'));
        $this->assertSame('DIELINE', ProductVault::guess('LAMP-01 dieline.pdf'));
        $this->assertSame('LABEL_FNSKU', ProductVault::guess('labels.pdf', 'pdf', 'FNSKU X001ABCDEF Lamp'));
        $this->assertSame('CARTON_MARK', ProductVault::guess('LAMP-01 carton mark.pdf'));
        $this->assertSame('CERTIFICATE', ProductVault::guess('LAMP-01 UKCA certificate.pdf'));
        $this->assertSame('LISTING', ProductVault::guess('LAMP-01 amazon 4.jpg'));
        // an old tool image file name is shown from the old tool's image folder (setting), a link as it is, anything else not at all
        $this->assertNull(ProductVault::imageUrl('abc123.webp'), 'no folder set yet');
        DB::table('sys_settings')->insert(['setting_key' => 'OLD_IMAGE_BASE', 'setting_value' => 'https://old.example.test/uploads/product_images/', 'fk_id_ent' => 0]); \App\Services\Settings::flush();
        $this->assertSame('https://old.example.test/uploads/product_images/abc123.webp', ProductVault::imageUrl('abc123.webp'));
        $this->assertSame('https://cdn.test/x.jpg', ProductVault::imageUrl('https://cdn.test/x.jpg'));
        $this->assertNull(ProductVault::imageUrl('../../etc/passwd'));
    }

    public function test_a_dropped_picture_goes_to_its_product_vault(): void
    {
        Storage::fake('local'); $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $p = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'VLT-LAMP-01', 'product_name_vendor' => 'Desk lamp', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']);
        $png = UploadedFile::fake()->createWithContent('VLT-LAMP-01_main.png', "\x89PNG\r\n\x1a\nxx");
        $r = $this->post('/api/intake', ['files' => [$png]])->assertOk()->json('items.0');
        $best = $r['proposals'][0]; $this->assertSame(['vault', $p, 'IMAGE_MAIN'], [$best['action'], $best['entity_id'], $best['kind']]);
        $this->postJson('/api/intake/' . $r['id'], ['action' => 'vault', 'entity' => $best['entity'], 'entity_id' => $p, 'kind' => 'IMAGE_MAIN'])->assertOk()->assertJson(['message' => "Put in the product's vault."]);
        $v = $this->getJson("/api/vault/$p")->assertOk()->json();
        $main = collect($v['places'])->firstWhere('kind', 'IMAGE_MAIN');
        $this->assertCount(1, $main['files']); $this->assertTrue($main['files'][0]['main']); $this->assertTrue($main['files'][0]['image']);
        // listed on Amazon FBA: the vault says what is missing
        $mp = $this->f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'AMAZON_UK']); $shop = $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => 'V1', 'iso_branch' => 'V1', 'shop_name' => 'V shop', 'f_active' => 1]);
        $this->f('t_product_listings', ['fk_id_ent' => 1, 'fk_id_product' => $p, 'fk_id_branch' => $shop, 'fk_id_marketplace' => $mp, 'marketplace_sku' => 'VLT-A', 'status' => 'ACTIVE', 'f_active' => 1, 'fulfilment' => 'FBA']);
        $need = array_column($this->getJson("/api/vault/$p")->json('needed'), 'kind');
        $this->assertSame(['LISTING', 'A_PLUS', 'LABEL_FNSKU'], $need);
        // move it, take it out
        $aid = (int) DB::table('product_assets')->where('fk_id_product', $p)->value('id');
        $this->postJson("/api/vault/asset/$aid", ['kind' => 'LISTING', 'marketplace' => $mp])->assertOk();
        $this->assertNotContains('LISTING', array_column($this->getJson("/api/vault/$p")->json('needed'), 'kind'), 'its Amazon listing images are there now');
        $this->postJson("/api/vault/asset/$aid", ['remove' => 1])->assertOk();
        $this->assertSame(0, DB::table('product_assets')->where('fk_id_product', $p)->count()); $this->assertSame(1, DB::table('sys_documents')->count(), 'the file stays in Documents');
        // filed from the product page: the product comes first even without its SKU in the name
        $r2 = $this->post('/api/intake', ['files' => [UploadedFile::fake()->createWithContent('box design.ai', '%!PS-Adobe vector')], 'ctx_entity' => $best['entity'], 'ctx_id' => $p])->assertOk()->json('items.0');
        $this->assertSame(['vault', 'ARTWORK', 100], [$r2['proposals'][0]['action'], $r2['proposals'][0]['kind'], $r2['proposals'][0]['score']]);
    }

    /** A vault picture has a public signed link (for a marketplace listing); labels / artwork never; a wrong signature or a removed file ends it. */
    public function test_public_link_of_a_vault_picture(): void
    {
        Storage::fake('local'); $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $p = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'PUB-1', 'product_name_vendor' => 'Lamp', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']);
        Storage::disk('local')->put('intake/x/main.jpg', 'JPEGDATA'); Storage::disk('local')->put('intake/x/art.pdf', 'PDF');
        $d1 = $this->f('sys_documents', ['fk_id_ent' => 1, 'doc_no' => 'D-P1', 'title' => 'main', 'orig_name' => 'main.jpg', 'stored_name' => 'intake/x/main.jpg', 'mime' => 'image/jpeg', 'status' => 'ACTIVE']);
        $d2 = $this->f('sys_documents', ['fk_id_ent' => 1, 'doc_no' => 'D-P2', 'title' => 'art', 'orig_name' => 'art.pdf', 'stored_name' => 'intake/x/art.pdf', 'mime' => 'application/pdf', 'status' => 'ACTIVE']);
        $a1 = ProductVault::put(1, $p, $d1, 'IMAGE_MAIN'); $a2 = ProductVault::put(1, $p, $d2, 'ARTWORK');
        $files = collect($this->getJson("/api/vault/$p")->json('places'))->flatMap(fn ($x) => $x['files']);
        $url = $files->firstWhere('id', $a1)['public']; $this->assertNotNull($url); $this->assertNull($files->firstWhere('id', $a2)['public'], 'artwork is never public');
        auth()->logout(); $path = parse_url($url, PHP_URL_PATH);
        $this->assertSame('JPEGDATA', $this->get($path)->assertOk()->getContent());
        $this->get(preg_replace('/-[a-f0-9]{32}\./', '-' . str_repeat('0', 32) . '.', $path))->assertNotFound();
        DB::table('product_assets')->where('id', $a1)->delete(); $this->get($path)->assertNotFound();
    }
}
