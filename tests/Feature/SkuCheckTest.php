<?php

namespace Tests\Feature;

use App\Services\RecordService;
use App\Services\SkuCheck;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_D_PRODUCTS R-D-2: SKU uniqueness per company and the live check – exact = block with a link, look-alike = warning, else available. */
class SkuCheckTest extends TestCase
{
    private function prod(string $sku, int $ent = 1): int { return (int) DB::table('t_product_vendors')->insertGetId(RecordService::fillNotNull('t_product_vendors', ['fk_id_ent' => $ent, 'SKU_VENDOR' => $sku, 'product_name_vendor' => "Item $sku", 'f_active' => 1])); }
    private function live(string $v, string $id = ''): array { return $this->getJson('/api/form/t_product_vendor/live?' . http_build_query(['field' => 'sku_vendor', 'value' => $v, 'id' => $id]))->assertOk()->json(); }

    public function test_the_rule_example(): void
    {
        $this->actingAs($this->owner);
        $id = $this->prod('AB-100');
        $r = $this->live(' ab-100 ');
        $this->assertSame([false, 'block', SkuCheck::BLOCK, "#/rec/t_product_vendor/$id"], [$r['ok'], $r['level'], $r['message'], $r['link']]);
        $this->assertSame('ab-100', $r['value'], 'the answer carries the trimmed value; the field is never cleared (the browser keeps what was typed while typing)');
        $w = $this->live('AB 100');
        $this->assertSame(['warn', 'Looks like SKU AB-100 – use it instead?'], [$w['level'], $w['message']]);
        $this->assertSame('warn', $this->live('ab_0100')['level'], 'leading zeros and underscores ignored');
        $this->assertSame(['ok', 'SKU is available'], [$this->live('AB-101')['level'], $this->live('AB-101')['message']]);
        $this->assertSame('ok', $this->live('AB-100', (string) $id)['level'], 'the product itself is no clash');
        $this->assertTrue(in_array('sku_vendor', $this->getJson('/api/form/t_product_vendor')->json('live_typing') ?? [], true));

        // the server repeats the exact check on save, with the same message; another company may use the SKU
        $this->postJson('/api/record/t_product_vendor', ['values' => ['sku_vendor' => 'AB-100 ', 'product_name_vendor' => 'Copy', 'min_level' => 0]])->assertStatus(422)->assertJsonPath('errors.sku_vendor.0', SkuCheck::BLOCK);
        $this->prod('AB-100', 2);
        $this->assertSame(2, DB::table('t_product_vendors')->where('SKU_VENDOR', 'AB-100')->count(), 'save of AB-100 in another company is allowed');
        $this->assertNull(SkuCheck::exact(2, 'AB-100', (int) DB::table('t_product_vendors')->where('fk_id_ent', 2)->value('id')));
    }

    public function test_child_cards_use_the_same_check(): void
    {
        $this->actingAs($this->owner);
        $this->prod('KID-1');
        $r = $this->getJson('/api/sku-check?sku=kid-1')->assertOk()->json();
        $this->assertSame('block', $r['level']);
        $this->assertSame(SkuCheck::norm('KID-1'), SkuCheck::norm('kid 0001'), 'normalisation');
        $this->assertSame('KID-1', SkuCheck::norm(' kid__001 '));
    }
}
