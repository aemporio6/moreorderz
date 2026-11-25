<?php

namespace Tests\Feature;

use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-C-30 seller marketplace account: shop required; the shop's Account kit reads it. */
class ShopKitTest extends TestCase
{
    public function test_seller_account_needs_its_shop_and_shows_in_the_kit(): void
    {
        $this->actingAs($this->makeUser('OWNER')); \App\Services\Context::set(1, null);
        $f = fn ($t, $d) => (int) DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
        $mp = $f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'eBay']);
        $shop = $f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => '101A', 'iso_branch' => 'K1', 'shop_name' => 'Pets', 'f_active' => 1]);
        $p = $f('t_account', ['fk_id_ent' => 1, 'account_code' => 'SK1', 'first_name' => 'Sana', 'sur_name' => 'K', 'f_active' => 1]);
        $country = (int) (DB::table('cat_country')->value('id') ?: $f('cat_country', ['country_name' => 'United Kingdom']));
        $dev = $f('t_mobile_devices', ['fk_id_ent' => 1, 'mobile_name' => 'Phone B', 'mobile_code' => 'PB', 'tot_slots' => 1, 'f_active' => 1]);
        $v = ['fk_id_account' => $p, 'market_place_type' => 'Seller', 'marketplace_type' => 'Business', 'country_id' => $country, 'fk_id_marketplace' => $mp, 'f_active' => 1];
        try { RecordService::save('t_account_marketplace', $v); $this->fail('no shop'); } catch (\Illuminate\Validation\ValidationException $e) { $this->assertSame(['Shop is required'], $e->errors()['fk_id_shop'] ?? null); }
        $id = (int) RecordService::save('t_account_marketplace', $v + ['fk_id_shop' => $shop, 'two_step_verification' => 1, 'authenticator_person' => 'Sana K', 'authenticator_device' => $dev])['id'];
        $kit = $this->getJson("/api/shops/$shop/kit")->assertOk()->json();
        $this->assertSame([$id, '2FA holder: Sana K on Phone B', ['missing payout account', 'missing payment account']], [$kit['accounts'][0]['account'], $kit['accounts'][0]['two_fa'], $kit['accounts'][0]['health']]);
        $loose = $f('t_account_marketplaces', ['fk_id_account' => $p, 'market_place_type' => 'Seller', 'fk_id_marketplace' => $mp, 'f_active' => 1]);
        $this->assertSame([$loose], array_column($this->getJson("/api/shops/$shop/kit")->json('missing_shop_link'), 'account'), 'an old seller account without a shop shows as missing shop link');
    }
}
