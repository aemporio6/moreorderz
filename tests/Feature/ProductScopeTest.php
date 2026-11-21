<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\Finder;
use App\Services\ProductScope;
use App\Services\ProductsList;
use App\Services\RecordService;
use App\Services\Registry;
use App\Services\Settings;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_D_PRODUCTS R-D-21 (who sees which product – list, search, export, record, dropdown) and R-D-17 (super roles by code, one check). */
class ProductScopeTest extends TestCase
{
    private int $amazon; private int $ebay; private int $s101; private int $s104; private int $priv; private int $pool; private int $none; private int $mine;

    private function f(string $t, array $d): int { return (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }
    private function product(string $sku): int { $id = $this->f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => $sku, 'product_name_vendor' => "Scope $sku", 'stock' => 1, 'f_active' => 1]);
        DB::table('sys_search_index')->insert(['fk_id_ent' => 1, 'entity' => 't_product_vendors', 'entity_id' => $id, 'department' => RecordService::formOfTable('t_product_vendors'), 'title' => "Scope $sku", 'subtitle' => 'Product', 'search_text' => strtolower("scopeprod $sku"), 'url' => "#/rec/x/$id", 'updated_at' => now()] + $this->notNull('sys_search_index'));
        return $id; }
    private function notNull(string $t): array { return array_diff_key(RecordService::fillNotNull($t, []), array_flip(['id'])); }
    private function vis(int $p, int $mp, string $mode, array $shops = []): void { DB::table('t_product_visibility')->insert(['fk_id_product' => $p, 'fk_id_marketplace' => $mp, 'visibility_mode' => $mode]); foreach ($shops as $s) DB::table('t_product_visibility_shops')->insert(['fk_id_product' => $p, 'fk_id_marketplace' => $mp, 'fk_id_branch' => $s]); }
    private function va(): object
    {
        $acc = $this->f('t_account', ['fk_id_ent' => 1, 'account_code' => 'VA' . random_int(10000, 99999), 'first_name' => 'Vera', 'sur_name' => 'Assist', 'f_active' => 1]);
        DB::table('t_account_branches')->insert(RecordService::fillNotNull('t_account_branches', ['fk_id_account' => $acc, 'fk_id_branch' => $this->s101, 'role_code' => 'VA', 'f_active' => 1, 'created_at' => now()]));
        $u = $this->makeUser('VA'); $u->fk_id_account = $acc; $u->save(); return $u->fresh();
    }
    private function skus(array $rows): array { return array_values(array_filter(array_map(fn ($r) => is_array($r) ? explode(' · ', (string) ($r['sku'] ?? $r['SKU_VENDOR'] ?? ''))[0] : null, $rows), fn ($s) => $s && str_starts_with($s, 'SCP-'))); }

    protected function setUp(): void
    {
        parent::setUp(); Context::set(1, $this->owner->id);
        $this->amazon = $this->f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'AmazonS', 'marketplace_descr' => 'Amazon scope', 'f_active' => 1]);
        $this->ebay = $this->f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'eBayS', 'marketplace_descr' => 'eBay scope', 'f_active' => 1]);
        $this->s101 = $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $this->amazon, 'shop_no' => 'S101', 'iso_branch' => 'S1' . random_int(1000, 9999), 'shop_name' => 'Shop 101', 'f_active' => 1]);
        $this->s104 = $this->f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $this->amazon, 'shop_no' => 'S104', 'iso_branch' => 'S4' . random_int(1000, 9999), 'shop_name' => 'Shop 104', 'f_active' => 1]);
        $this->priv = $this->product('SCP-PRIV104'); $this->vis($this->priv, $this->amazon, 'PRIVATE', [$this->s104]);
        $this->mine = $this->product('SCP-PRIV101'); $this->vis($this->mine, $this->amazon, 'PRIVATE', [$this->s101]);
        $this->pool = $this->product('SCP-POOL'); $this->vis($this->pool, $this->ebay, 'POOL');
        $this->none = $this->product('SCP-NONE');      // no visibility rows → hidden from non-super users (old)
    }

    public function test_the_rule_example_va_of_shop_101_does_not_see_the_private_product_of_shop_104(): void
    {
        $va = $this->va(); $this->actingAs($va);
        $want = ['SCP-POOL', 'SCP-PRIV101'];
        // product list and its search box
        $list = ProductsList::page(1, ['status' => 'active', 'per' => 100, 'q' => 'SCP-'], $va);
        $this->assertEqualsCanonicalizing($want, $this->skus($list['rows']));
        // CSV export
        $csv = ProductsList::export(1, ['status' => 'active', 'q' => 'SCP-'], 'csv', $va)['body'];
        $this->assertStringNotContainsString('SCP-PRIV104', $csv); $this->assertStringNotContainsString('SCP-NONE', $csv); $this->assertStringContainsString('SCP-POOL', $csv);
        // the top search
        $found = json_encode(Finder::find('scopeprod', $va, 50));
        $this->assertStringNotContainsString('SCP-PRIV104', $found); $this->assertStringContainsString('SCP-POOL', $found);
        // every generic list, record call and dropdown
        $form = RecordService::formOfTable('t_product_vendors');
        $this->assertEqualsCanonicalizing($want, $this->skus(array_map(fn ($r) => (array) $r, RecordService::list($form, ['per_page' => 250, 'cols' => ['SKU_VENDOR']])['rows'])));
        try { RecordService::get($form, $this->priv); $this->fail('the hidden product must not open'); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(404, $e->getStatusCode()); }
        $this->assertNotEmpty(RecordService::get($form, $this->pool));
        $opts = array_column(Registry::options(['source' => 'table:t_product_vendors', 'type' => 'select'], 1, null, 5000), 0);      // the product dropdown
        $this->assertContains((string) $this->pool, $opts); $this->assertContains((string) $this->mine, $opts); $this->assertNotContains((string) $this->priv, $opts); $this->assertNotContains((string) $this->none, $opts);
        $this->assertFalse(ProductScope::visible($va, $this->priv)); $this->assertTrue(ProductScope::visible($va, $this->mine));
        $this->assertFalse(ProductScope::visible(null, $this->mine), 'not logged in → pool only'); $this->assertTrue(ProductScope::visible(null, $this->pool));
    }

    public function test_super_roles_by_code_and_the_owner_choice(): void
    {
        $this->assertTrue(ProductScope::visible($this->owner, $this->none), 'the Owner sees every product');
        $this->assertTrue(ProductScope::visible($this->makeUser('MANAGER'), $this->priv));
        // R-D-17: a login whose person holds the account role ADMN-SUPER is super on every product screen
        $st = $this->makeUser('STOCK'); $this->assertFalse(ProductScope::visible($st, $this->priv), 'other roles are limited by default');
        $acc = $this->f('t_account', ['fk_id_ent' => 1, 'account_code' => 'SU' . random_int(10000, 99999), 'first_name' => 'Sana', 'sur_name' => 'Super', 'f_active' => 1]);
        $role = $this->f('cat_account_types', ['fk_id_ent' => 0, 'account_type_name' => 'Admin super', 'iso_account_type' => 'ADMN-SUPER', 'role_code' => 'ADMN-SUPER', 'f_active' => 1]);
        DB::table('t_account_in_types')->insert(RecordService::fillNotNull('t_account_in_types', ['fk_id_account' => $acc, 'fk_id_account_type' => $role, 'f_active' => 1]));
        $st->fk_id_account = $acc; $st->save(); $this->assertTrue(ProductScope::visible($st->fresh(), $this->priv));
        // the Owner's choice: only shop roles limited / nobody limited
        Settings::set('PRODUCT_SCOPE', 'SHOP_ROLES', 1);
        $this->assertTrue(ProductScope::visible($this->makeUser('SOURCING'), $this->priv)); $this->assertFalse(ProductScope::visible($this->va(), $this->priv));
        Settings::set('PRODUCT_SCOPE', 'OFF', 1); $this->assertTrue(ProductScope::visible($this->va(), $this->priv));
    }
}
