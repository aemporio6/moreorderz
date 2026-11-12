<?php

namespace Tests\Feature;

use App\Services\Choices;
use App\Services\Connect\Connections;
use App\Services\Connect\DriveFolders;
use App\Services\Connect\GoogleDrive;
use App\Services\Connect\Messenger;
use App\Services\Connect\TemuApi;
use App\Services\Connect\TikTokShopApi;
use App\Services\Context;
use App\Services\MoneyService as Money;
use App\Services\PriceService;
use App\Services\RecordService;
use App\Services\StockService as Stock;
use App\Services\Subscriptions;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Round 2 part 6: outside connections. The services are never called: every answer below is shaped like the provider's published contract (Http::fake).
 * These tests prove our side (signing, paging, mapping, booking, errors, secrets); they do not prove the real service answers the same way.
 */
class ConnectTest extends TestCase
{
    private int $shop; private int $p; private int $bin; private int $bank; private int $mp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->owner); Context::set(1, $this->owner->id);
        $f = fn ($t, $d) => DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d));
        $this->mp = $f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'eBay']);
        $this->p = $f('t_product_vendors', ['fk_id_ent' => 1, 'SKU_VENDOR' => 'ALX-127', 'product_name_vendor' => 'Tyre wax', 'stock' => 0, 'f_active' => 1, 'e_product_type' => 'pool']);
        $this->bin = $f('cat_inventory_locations', ['location_name' => 'A-A-01', 'kind' => 'BIN', 'fk_id_ent' => 1, 'f_sellable' => 1, 'f_active' => 1, 'pick_seq' => 1]);
        $this->shop = $f('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $this->mp, 'shop_no' => 'S1', 'iso_branch' => 'S1', 'shop_name' => 'Shop A', 'f_active' => 1, 'fk_id_main_location' => $this->bin, 'fulfilment_channel' => 'SELF']);
        Stock::receive(1, [['product' => $this->p, 'qty' => 50, 'location' => $this->bin, 'unit_cost' => 1.00]], 'GRN', 1);
        PriceService::set($this->p, $this->mp, 8, 5.00, '2026-01-01');
        $this->bank = Money::createBank(1, ['bank_name' => 'Bank One', 'account_label' => 'Main', 'currency' => 'GBP', 'opening_balance' => 1000, 'opening_date' => '2026-08-01']);
    }

    private function ebay(): int
    {
        return Connections::save(['adapter' => 'EBAY_API', 'label' => 'eBay main', 'shop' => $this->shop, 'settings' => ['marketplace_id' => 'EBAY_GB'], 'secrets' => ['client_id' => 'cid-123', 'client_secret' => 'csecret-XYZ', 'refresh_token' => 'rtok-SECRET']]);
    }

    private function fakeEbay(): void
    {
        Http::fake([
            'api.ebay.com/identity/*' => Http::response(['access_token' => 'atok-SECRET', 'expires_in' => 7200]),
            'api.ebay.com/sell/fulfillment/v1/order*' => Http::response(['total' => 2, 'orders' => [
                ['orderId' => '12-34567-89012', 'creationDate' => '2026-11-10T09:00:00.000Z', 'buyer' => ['username' => 'buyer1'], 'lineItems' => [['sku' => 'ALX-127', 'quantity' => 2, 'title' => 'Tyre wax', 'lineItemCost' => ['value' => '10.00', 'currency' => 'GBP']]],
                    'fulfillmentStartInstructions' => [['shippingStep' => ['shipTo' => ['fullName' => 'Made Up Buyer', 'contactAddress' => ['addressLine1' => '1 Made Up Road', 'city' => 'Leeds', 'postalCode' => 'LS1 1AA', 'countryCode' => 'GB']]]]]],
                ['orderId' => '12-99999-00000', 'creationDate' => '2026-11-10T10:00:00.000Z', 'cancelStatus' => ['cancelState' => 'CANCELED'], 'lineItems' => [['sku' => 'ALX-127', 'quantity' => 1, 'lineItemCost' => ['value' => '5.00', 'currency' => 'GBP']]]],
            ]]),
            'apiz.ebay.com/sell/finances/v1/transaction*' => Http::response(['total' => 4, 'transactions' => [
                ['transactionId' => 'T1', 'orderId' => '12-34567-89012', 'transactionType' => 'SALE', 'bookingEntry' => 'CREDIT', 'transactionDate' => '2026-11-10T12:00:00.000Z', 'payoutId' => 'P-777', 'amount' => ['value' => '8.20', 'currency' => 'GBP'],
                    'orderLineItems' => [['marketplaceFees' => [['feeType' => 'FINAL_VALUE_FEE', 'amount' => ['value' => '1.50', 'currency' => 'GBP']], ['feeType' => 'FINAL_VALUE_FEE_FIXED_PER_ORDER', 'amount' => ['value' => '0.30', 'currency' => 'GBP']]]]]],
                ['transactionId' => 'T2', 'transactionType' => 'NON_SALE_CHARGE', 'feeType' => 'AD_FEE', 'bookingEntry' => 'DEBIT', 'transactionDate' => '2026-11-10T12:00:00.000Z', 'payoutId' => 'P-777', 'amount' => ['value' => '0.70', 'currency' => 'GBP']],
                ['transactionId' => 'T3', 'transactionType' => 'TRANSFER', 'bookingEntry' => 'DEBIT', 'payoutId' => 'P-777', 'amount' => ['value' => '7.50', 'currency' => 'GBP']],
                ['transactionId' => 'T4', 'orderId' => '12-1', 'transactionType' => 'SALE', 'bookingEntry' => 'CREDIT', 'transactionDate' => '2026-11-11T12:00:00.000Z', 'amount' => ['value' => '3.00', 'currency' => 'GBP']],   // no payout yet
            ]]),
        ]);
    }

    public function test_keys_are_kept_only_in_the_vault_and_only_the_owner_sets_up_a_connection(): void
    {
        $id = $this->ebay();
        $c = DB::table('api_connections')->find($id);
        $this->assertSame('••••', $c->token_ref);
        $everything = json_encode(DB::table('api_connections')->get()) . json_encode(DB::table('sys_vault')->get()) . json_encode(DB::table('sys_audit_log')->where('table_name', 'api_connections')->get());
        foreach (['csecret-XYZ', 'rtok-SECRET'] as $k) $this->assertStringNotContainsString($k, $everything);
        $this->assertSame(['client_id' => true, 'client_secret' => true, 'refresh_token' => true], Connections::filled($c));
        $this->assertSame('rtok-SECRET', Connections::secrets($c)['refresh_token']);
        // the screen never gets a secret back
        $page = $this->getJson('/api/connections')->assertOk()->getContent();
        $this->assertStringNotContainsString('rtok-SECRET', $page); $this->assertStringNotContainsString('csecret', $page);
        // an empty field keeps the key; a typed one replaces it
        Connections::save(['adapter' => 'EBAY_API', 'shop' => $this->shop, 'secrets' => ['client_secret' => '', 'refresh_token' => 'rtok-NEW']], $id);
        $this->assertSame(['csecret-XYZ', 'rtok-NEW'], [Connections::secrets(DB::table('api_connections')->find($id))['client_secret'], Connections::secrets(DB::table('api_connections')->find($id))['refresh_token']]);
        $mgr = $this->makeUser('MANAGER');
        $this->actingAs($mgr)->postJson('/api/connections', ['adapter' => 'EBAY_API', 'shop' => $this->shop])->assertStatus(422);
    }

    public function test_ebay_orders_pull_goes_through_the_order_intake_once_and_logs_no_secret(): void
    {
        $id = $this->ebay(); $this->fakeEbay();
        $r = $this->postJson("/api/connections/$id/pull", ['what' => 'ORDERS', 'from' => '2026-11-01', 'to' => '2026-11-12'])->assertOk()->json();
        $this->assertSame([1, 1, 1], [$r['lines'], $r['orders'], $r['new']], 'the cancelled order is left out');
        $sale = DB::table('t_sales')->where('order_no', '12-34567-89012')->first();
        $this->assertSame([2, 5.0, $this->p, 'LS1 1AA'], [(int) $sale->qty, (float) $sale->sale_rate, (int) $sale->fk_product_id, $sale->ship_to_postcode]);
        $this->assertSame(0, $this->postJson("/api/connections/$id/pull", ['what' => 'ORDERS', 'from' => '2026-11-01', 'to' => '2026-11-12'])->json('new'), 'nothing twice');
        Http::assertSent(fn (HttpRequest $q) => str_contains($q->url(), 'creationdate') && $q->hasHeader('X-EBAY-C-MARKETPLACE-ID', 'EBAY_GB') && $q->hasHeader('Authorization', 'Bearer atok-SECRET'));
        $log = json_encode(DB::table('sys_api_log')->get());
        foreach (['atok-SECRET', 'rtok-SECRET', 'csecret-XYZ', 'creationdate'] as $x) $this->assertStringNotContainsString($x, $log, 'the log keeps the path only');
        $this->assertSame('atok-SECRET', Connections::secrets(DB::table('api_connections')->find($id))['access_token'], 'the new access token is kept in the vault for the next call');
        $this->assertSame('CONNECTED', DB::table('api_connections')->where('id', $id)->value('status'));
        $this->assertSame(2, DB::table('imp_batches')->where('source', 'API')->where('fk_id_branch', $this->shop)->count());
        $this->assertSame('OK', DB::table('sys_job_runs')->where('job_code', 'API_PULL_ORDERS')->orderByDesc('id')->value('run_status'));
    }

    public function test_ebay_money_pull_is_booked_like_a_money_file_gross_fees_and_ads_and_waits_for_unpaid_lines(): void
    {
        $id = $this->ebay(); $this->fakeEbay();
        $r = Connections::pull($id, 'MONEY', '2026-11-01', '2026-11-12');
        $this->assertSame(1, $r['statements']);
        $st = DB::table('mo_settlements')->where('fk_id_branch', $this->shop)->first();
        $this->assertStringContainsString('P-777', $st->statement_id);
        $lines = DB::table('fin_settlement_lines')->where('branch_id', $this->shop)->get();
        $this->assertEqualsWithDelta(7.50, $lines->sum('amount'), 0.001, 'sale 10.00 − fees 1.80 − ad fee 0.70 = the payout 7.50');
        $this->assertEqualsWithDelta(10.00, $lines->where('bucket', 'SALE')->sum('amount'), 0.001, 'the sale is the gross the buyer paid');
        $this->assertSame(0, $lines->where('order_id', '12-1')->count(), 'a line without a payout waits for a later pull');
        $this->assertSame(0, Connections::pull($id, 'MONEY', '2026-11-01', '2026-11-12')['new'], 'nothing twice');
    }

    public function test_keys_refused_marks_the_connection_and_opens_one_task(): void
    {
        $id = $this->ebay();
        Http::fake(['api.ebay.com/identity/*' => Http::sequence()->push(['error' => 'invalid_grant', 'error_description' => 'the refresh token is expired'], 400)->push(['errors' => [['message' => 'Invalid access token']]], 401)]);
        $t = Connections::test($id);
        $this->assertFalse($t['ok']); $this->assertStringContainsString('refresh token is expired', $t['text']);
        try { Connections::pull($id, 'ORDERS', '2026-11-01', '2026-11-02'); $this->fail('refused'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('refused the keys', $e->getMessage()); }
        $this->assertSame('EXPIRED', DB::table('api_connections')->where('id', $id)->value('status'));
        $this->assertSame(1, DB::table('tasks')->where('rule_code', 'SYS_API_SYNC')->whereNotIn('status', ['DONE', 'CANCELLED'])->count());
        $this->assertSame('FAILED', DB::table('sys_job_runs')->where('job_code', 'API_PULL_ORDERS')->orderByDesc('id')->value('run_status'));
    }

    public function test_amazon_orders_and_settlement_groups(): void
    {
        $id = Connections::save(['adapter' => 'AMAZON_SPAPI', 'shop' => $this->shop, 'secrets' => ['client_id' => 'a', 'client_secret' => 'b', 'refresh_token' => 'c']]);
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'Atza|x', 'expires_in' => 3600]),
            '*/orders/v0/orders/206-1/orderItems*' => Http::response(['payload' => ['OrderItems' => [['SellerSKU' => 'ALX-127', 'QuantityOrdered' => 3, 'ItemPrice' => ['Amount' => '15.00', 'CurrencyCode' => 'GBP'], 'Title' => 'Tyre wax']]]]),
            '*/orders/v0/orders*' => Http::response(['payload' => ['Orders' => [['AmazonOrderId' => '206-1', 'PurchaseDate' => '2026-11-09T08:00:00Z', 'OrderStatus' => 'Unshipped', 'ShippingAddress' => ['PostalCode' => 'M1 1AA', 'City' => 'Manchester', 'CountryCode' => 'GB']], ['AmazonOrderId' => '206-2', 'PurchaseDate' => '2026-11-09T08:00:00Z', 'OrderStatus' => 'Canceled']]]]),
            '*/finances/v0/financialEventGroups/G1/financialEvents*' => Http::response(['payload' => ['FinancialEvents' => [
                'ShipmentEventList' => [['AmazonOrderId' => '206-1', 'PostedDate' => '2026-11-10T00:00:00Z', 'ShipmentItemList' => [['SellerSKU' => 'ALX-127', 'QuantityShipped' => 3,
                    'ItemChargeList' => [['ChargeType' => 'Principal', 'ChargeAmount' => ['CurrencyAmount' => 15.0, 'CurrencyCode' => 'GBP']]], 'ItemFeeList' => [['FeeType' => 'Commission', 'FeeAmount' => ['CurrencyAmount' => -2.25, 'CurrencyCode' => 'GBP']], ['FeeType' => 'FBAPerUnitFulfillmentFee', 'FeeAmount' => ['CurrencyAmount' => -3.0, 'CurrencyCode' => 'GBP']]]]]]],
                'ServiceFeeEventList' => [['FeeList' => [['FeeType' => 'Subscription', 'FeeAmount' => ['CurrencyAmount' => -25.0, 'CurrencyCode' => 'GBP']]]]]]]]),
            '*/finances/v0/financialEventGroups*' => Http::response(['payload' => ['FinancialEventGroupList' => [['FinancialEventGroupId' => 'G1', 'ProcessingStatus' => 'Closed', 'FundTransferDate' => '2026-11-12T00:00:00Z'], ['FinancialEventGroupId' => 'G2', 'ProcessingStatus' => 'Open']]]]),
        ]);
        $o = Connections::pull($id, 'ORDERS', '2026-11-01', '2026-11-10');
        $this->assertSame([1, 1], [$o['lines'], $o['new']]);
        $this->assertSame('M1 1AA', DB::table('t_sales')->where('order_no', '206-1')->value('ship_to_postcode'));
        Http::assertSent(fn (HttpRequest $q) => $q->hasHeader('x-amz-access-token', 'Atza|x'));
        $m = Connections::pull($id, 'MONEY', '2026-11-01', '2026-11-12');
        $this->assertSame(1, $m['statements'], 'only the closed group');
        $lines = DB::table('fin_settlement_lines')->where('branch_id', $this->shop)->get();
        $this->assertEqualsWithDelta(15.0 - 2.25 - 3.0 - 25.0, $lines->sum('amount'), 0.001);
        $this->assertSame(['MP_COMMISSION', 'MP_FULFILMENT', 'MP_SUBSCRIPTION'], $lines->where('bucket', '!=', 'SALE')->pluck('amount_type')->sort()->values()->all());
    }

    public function test_tiktok_signing_and_orders_temu_signing_onbuy_money_is_by_file(): void
    {
        // signing worked by hand: HMAC-SHA256(secret, secret + path + sorted key value pairs + body + secret)
        $sig = TikTokShopApi::sign('/order/202309/orders/search', ['app_key' => 'k1', 'timestamp' => 1700000000, 'shop_cipher' => 'C', 'access_token' => 'never', 'sign' => 'x'], '{"a":1}', 'sec');
        $this->assertSame(hash_hmac('sha256', 'sec/order/202309/orders/searchapp_keyk1shop_cipherCtimestamp1700000000{"a":1}sec', 'sec'), $sig);
        $this->assertSame(strtoupper(md5('Sa1b' . json_encode(['x' => 1]) . 'typetS')), TemuApi::sign(['type' => 't', 'b' => ['x' => 1], 'a' => 1, 'sign' => 'old'], 'S'));
        $id = Connections::save(['adapter' => 'TIKTOK_SHOP_API', 'shop' => $this->shop, 'settings' => ['shop_cipher' => 'ROW_x'], 'secrets' => ['app_key' => 'k1', 'app_secret' => 'sec', 'access_token' => 'tt', 'refresh_token' => 'rr']]);
        Http::fake(['*/order/202309/orders/search*' => Http::response(['code' => 0, 'data' => ['orders' => [['id' => '5768', 'create_time' => strtotime('2026-11-09'), 'status' => 'AWAITING_SHIPMENT',
            'line_items' => [['seller_sku' => 'ALX-127', 'sale_price' => '4.50', 'currency' => 'GBP', 'product_name' => 'Tyre wax'], ['seller_sku' => 'ALX-127', 'sale_price' => '4.50', 'currency' => 'GBP']], 'recipient_address' => ['name' => 'X', 'postal_code' => 'B1 1AA', 'region_code' => 'GB']]]]])]);
        $r = Connections::pull($id, 'ORDERS', '2026-11-01', '2026-11-10');
        $this->assertSame(2, (int) DB::table('t_sales')->where('order_no', '5768')->value('qty'), 'two line items of one SKU = quantity 2');
        Http::assertSent(fn (HttpRequest $q) => str_contains($q->url(), 'sign=') && $q->hasHeader('x-tts-access-token', 'tt'));
        $o = Connections::save(['adapter' => 'ONBUY_API', 'shop' => $this->shop, 'secrets' => ['consumer_key' => 'a', 'secret_key' => 'b']]);
        try { Connections::pull($o, 'MONEY', '2026-11-01', '2026-11-10'); $this->fail('no money API'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('money file', $e->getMessage()); }
    }

    public function test_messages_wait_without_a_channel_and_go_out_when_one_is_set_up(): void
    {
        $partner = DB::table('t_account')->insertGetId(RecordService::fillNotNull('t_account', ['fk_id_ent' => 1, 'account_code' => 'P' . random_int(100, 999), 'first_name' => 'Pat', 'sur_name' => 'Partner', 'f_active' => 1, 'whatsapp_number' => '+44 7700 900123']));
        $id = Messenger::queueWa(1, $partner, Messenger::whatsappOf($partner), 'STATEMENT', ['month' => 'October 2026', 'profit' => '120.00', 'balance' => '300.00', 'link' => 'https://x/#/p']);
        Messenger::sendDue();
        $this->assertSame('HELD', DB::table('wa_outbound')->where('id', $id)->value('status'));
        Connections::save(['adapter' => 'WHATSAPP_CLOUD', 'secrets' => ['phone_number_id' => '1055', 'access_token' => 'EAAG-SECRET', 'app_secret' => 'appsec', 'verify_token' => 'vt']]);
        $err = ['error' => ['message' => 'Template not approved']];
        Http::fake(['graph.facebook.com/*' => Http::sequence()->push(['messages' => [['id' => 'wamid.ABC']]])->push($err, 400)->push($err, 400)->push($err, 400)]);
        Messenger::sendDue();
        $m = DB::table('wa_outbound')->find($id);
        $this->assertSame(['SENT', 'wamid.ABC'], [$m->status, $m->provider_msg_id]);
        Http::assertSent(fn (HttpRequest $q) => $q['to'] === '447700900123' && $q['template']['name'] === 'statement' && array_column($q['template']['components'][0]['parameters'], 'text') === ['October 2026', '120.00', '300.00', 'https://x/#/p']);
        // e-mail: held without a mail server, sent with one
        $e = Messenger::queueEmail(1, $partner, 'pat@example.test', 'Statement', 'Hello');
        config(['mail.default' => 'log']); Messenger::sendDue();
        $this->assertSame('HELD', DB::table('email_outbound')->where('id', $e)->value('status'));
        config(['mail.default' => 'smtp']); Mail::fake(); Messenger::sendDue();
        $this->assertSame('SENT', DB::table('email_outbound')->where('id', $e)->value('status'));
        // three failures → a task for the admin
        $f = Messenger::queueWa(1, $partner, '447700900123', 'STATEMENT', ['month' => 'x', 'profit' => '1', 'balance' => '1', 'link' => 'l']);
        for ($i = 0; $i < 3; $i++) { DB::table('wa_outbound')->where('id', $f)->update(['next_try_at' => null]); Messenger::sendDue(); }
        $this->assertSame([3, 'FAILED'], [(int) DB::table('wa_outbound')->where('id', $f)->value('attempts'), DB::table('wa_outbound')->where('id', $f)->value('status')]);
        $this->assertStringContainsString('Template not approved', DB::table('wa_outbound')->where('id', $f)->value('error'));
        $this->assertTrue(DB::table('tasks')->where('rule_code', 'SYS_WA_FAILING')->exists());
    }

    public function test_whatsapp_webhook_reads_only_signed_bodies_and_done_closes_the_owners_own_task(): void
    {
        Connections::save(['adapter' => 'WHATSAPP_CLOUD', 'secrets' => ['phone_number_id' => '1055', 'access_token' => 't', 'app_secret' => 'appsec', 'verify_token' => 'my-verify']]);
        $this->get('/wa/inbound?hub_mode=subscribe&hub_verify_token=my-verify&hub_challenge=42')->assertOk()->assertSee('42');
        $this->get('/wa/inbound?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=42')->assertStatus(403);
        $acc = DB::table('t_account')->insertGetId(RecordService::fillNotNull('t_account', ['fk_id_ent' => 1, 'account_code' => 'V' . random_int(100, 999), 'first_name' => 'Vee', 'sur_name' => 'A', 'f_active' => 1, 'whatsapp_number' => '+92 300 1234567']));
        $va = $this->makeUser('VA'); DB::table('t_users')->where('id', $va->id)->update(['fk_id_account' => $acc]);
        $task = DB::table('tasks')->insertGetId(RecordService::fillNotNull('tasks', ['fk_id_ent' => 1, 'title' => 'Photograph the shelf', 'owner_user_id' => $va->id, 'status' => 'TODO', 'source' => 'MANUAL', 'priority' => 'NORMAL', 'created_at' => now()]));
        DB::table('wa_outbound')->insert(['fk_id_ent' => 1, 'fk_id_account' => $acc, 'phone' => '923001234567', 'template_code' => 'TASK_NEW', 'body' => 'x', 'link_table' => 'tasks', 'link_id' => $task, 'status' => 'SENT', 'provider_msg_id' => 'wamid.OUT1']);
        $body = json_encode(['entry' => [['changes' => [['value' => ['messages' => [['from' => '923001234567', 'id' => 'wamid.IN1', 'type' => 'text', 'text' => ['body' => 'DONE'], 'context' => ['id' => 'wamid.OUT1']]],
            'statuses' => [['id' => 'wamid.OUT1', 'status' => 'read']]]]]]]]);
        $this->call('POST', '/wa/inbound', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=bad'], $body)->assertStatus(403);
        $this->assertSame(0, DB::table('wa_inbound')->count());
        $this->call('POST', '/wa/inbound', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=' . hash_hmac('sha256', $body, 'appsec')], $body)->assertOk()->assertJsonPath('tasks_closed', 1);
        $this->assertSame('DONE', DB::table('tasks')->where('id', $task)->value('status'));
        $this->assertSame('READ', DB::table('wa_outbound')->where('provider_msg_id', 'wamid.OUT1')->value('status'));
        $this->assertSame(['HANDLED', $acc], [DB::table('wa_inbound')->value('status'), (int) DB::table('wa_inbound')->value('fk_id_account')]);
        // the same message again is stored once
        $this->call('POST', '/wa/inbound', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=' . hash_hmac('sha256', $body, 'appsec')], $body)->assertOk()->assertJsonPath('messages', 0);
    }

    public function test_statement_send_choice_and_partner_without_contact(): void
    {
        $this->assertSame('AUTO', Choices::get('STATEMENT_SEND', 1));
        $this->assertSame(0, Messenger::statementsOfRun(1, 999999), 'nothing to send for a run without statements');
        $this->assertSame('WHATSAPP', Choices::get('TASK_DIGEST', 1));
    }

    public function test_royal_mail_labels_and_tracking_link(): void
    {
        $car = DB::table('t_carriers')->insertGetId(RecordService::fillNotNull('t_carriers', ['fk_id_ent' => 1, 'carrier_name' => 'Royal Mail (test)', 'carrier_code' => 'RMT', 'f_active' => 1, 'tracking_url_pattern' => 'https://track.example/{tracking}']));
        $this->assertSame(['LINK', 'https://track.example/AB123456789GB'], array_values(array_intersect_key(Connections::track($car, 'AB123456789GB'), ['status' => 1, 'url' => 1])));
        $id = Connections::save(['adapter' => 'ROYALMAIL_CLICKDROP', 'carrier' => $car, 'secrets' => ['api_key' => 'rm-key']]);
        Http::fake(['api.parcel.royalmail.com/api/v1/orders/*/label*' => Http::response('%PDF-1.4 fake', 200, ['Content-Type' => 'application/pdf']),
            'api.parcel.royalmail.com/api/v1/orders' => Http::response(['createdOrders' => [['orderIdentifier' => 501, 'orderReference' => 'A-1', 'trackingNumber' => 'TT123GB']], 'failedOrders' => [['order' => ['orderReference' => 'A-2'], 'errors' => [['errorMessage' => 'Postcode is not valid']]]]])]);
        $r = Connections::adapter(DB::table('api_connections')->find($id))->labels([['order_no' => 'A-1', 'name' => 'X', 'address1' => '1 Road', 'city' => 'Leeds', 'postcode' => 'LS1 1AA', 'country' => 'GB', 'service' => null, 'value' => 10],
            ['order_no' => 'A-2', 'name' => 'Y', 'address1' => '2 Road', 'city' => 'Leeds', 'postcode' => 'bad', 'country' => 'GB', 'service' => null, 'value' => 5]]);
        $this->assertSame(['A-1' => 'TT123GB'], $r['orders']);
        $this->assertSame(['A-2: Postcode is not valid'], $r['failed']);
        $this->assertStringStartsWith('%PDF', $r['pdf']);
        Http::assertSent(fn (HttpRequest $q) => $q->hasHeader('Authorization', 'rm-key') && str_contains($q->url(), '/orders/501/label'));
    }

    public function test_drive_service_account_folders_and_a_watched_folder_loads_an_order_file(): void
    {
        $k = openssl_pkey_new(['private_key_bits' => 2048]); openssl_pkey_export($k, $pem);
        $key = ['client_email' => 'mo@test.iam.gserviceaccount.com', 'private_key' => $pem];
        $jwt = GoogleDrive::jwt($key, 1700000000); [$h, $c, $s] = explode('.', $jwt);
        $this->assertSame(1, openssl_verify("$h.$c", base64_decode(strtr($s, '-_', '+/')), openssl_pkey_get_details($k)['key'], OPENSSL_ALGO_SHA256), 'the JWT is signed with the key');
        $this->assertSame('mo@test.iam.gserviceaccount.com', json_decode(base64_decode(strtr($c, '-_', '+/')), true)['iss']);
        // links by hand work without any connection
        DriveFolders::setLink('SHOP', $this->shop, 'https://drive.google.com/drive/folders/1AbCdEfGhIjKlMn');
        $this->assertSame('1AbCdEfGhIjKlMn', DB::table('sys_drive_folders')->where(['entity' => 'SHOP', 'entity_id' => $this->shop])->value('folder_id'));
        try { DriveFolders::setLink('SHOP', $this->shop, 'https://evil.example/x'); $this->fail('only Drive links'); } catch (\InvalidArgumentException) {}
        Connections::save(['adapter' => 'GOOGLE_DRIVE', 'settings' => ['root_folder_id' => 'ROOT1'], 'secrets' => ['service_account_json' => json_encode($key)]]);
        $csv = "Order ID,SKU,Quantity,Unit price,Order date\nDRV-1,ALX-127,1,5.00,2026-11-10\n";
        Http::fake(function (HttpRequest $q) use ($csv) {
            $u = $q->url();
            if (str_contains($u, 'oauth2.googleapis.com/token')) return Http::response(['access_token' => 'ya29.x', 'expires_in' => 3600]);
            if ($q->method() === 'POST' && str_contains($u, '/drive/v3/files')) return Http::response(['id' => 'NEWFOLDER', 'webViewLink' => 'https://drive.google.com/drive/folders/NEWFOLDER']);
            if (str_contains($u, '/drive/v3/files/F1')) return Http::response($csv);
            if (str_contains($u, '/drive/v3/files')) return Http::response(['files' => [['id' => 'F1', 'name' => 'orders.csv', 'mimeType' => 'text/csv']]]);
            return Http::response([], 404);
        });
        $po = DB::table('t_branches')->insertGetId(RecordService::fillNotNull('t_branches', ['fk_id_ent' => 1, 'shop_name' => 'Shop B', 'f_active' => 1]));
        $this->assertSame('NEWFOLDER', DriveFolders::make('SHOP', $po)['id']);
        DB::table('imp_drive_folders')->insert(RecordService::fillNotNull('imp_drive_folders', ['fk_id_ent' => 1, 'fk_id_branch' => $this->shop, 'report_type' => 'FULFILMENT_ORDERS', 'folder_url' => 'https://drive.google.com/drive/folders/1WatchFolderAbc', 'f_auto_save' => 1, 'f_active' => 1]));
        $x = DriveFolders::check();
        $this->assertSame([1, 1, []], [$x['found'], $x['loaded'], $x['errors']]);
        $this->assertSame(1, DB::table('t_sales')->where('order_no', 'DRV-1')->count());
        $this->assertSame(0, DriveFolders::check()['found'], 'a file is taken once');
    }

    public function test_subscription_paid_books_the_expense_and_moves_the_due_date_overdue_opens_a_task(): void
    {
        $acc = DB::table('t_account')->insertGetId(RecordService::fillNotNull('t_account', ['fk_id_ent' => 1, 'account_code' => 'S' . random_int(100, 999), 'first_name' => 'Sub', 'sur_name' => 'Holder', 'f_active' => 1]));
        $s = DB::table('t_account_subscriptions')->insertGetId(RecordService::fillNotNull('t_account_subscriptions', ['fk_id_account' => $acc, 'f_active' => 1, 'billing_cycle' => 'QUARTERLY', 'price' => 30, 'next_due_date' => date('Y-m-d', strtotime('-2 days')), 'fk_id_branch' => $this->shop]));
        $p = Subscriptions::page(1); $row = $p['rows']->firstWhere('id', $s);
        $this->assertSame(['OVERDUE', 10.0], [$row->state, $row->per_month]);
        $this->assertSame(1, Subscriptions::nightly());
        $r = $this->postJson("/api/subscriptions/$s/paid", ['bank' => $this->bank, 'amount' => 30])->assertOk()->json();
        $this->assertSame(date('Y-m-d', strtotime(date('Y-m-d', strtotime('-2 days')) . ' +3 months')), $r['next_due_date']);
        $this->assertSame('SUBSCRIPTION', DB::table('fin_expenses')->where('id', $r['id'])->value('category_code'));
        Subscriptions::nightly();
        $this->assertSame(0, DB::table('tasks')->where('rule_code', 'ACC_SUBSCRIPTION_DUE')->whereNotIn('status', ['DONE', 'CANCELLED'])->count(), 'paid → the task closes');
    }

    public function test_hubs_page_shows_the_shop_its_connection_and_what_waits(): void
    {
        $this->ebay();
        $h = collect($this->getJson('/api/hubs')->assertOk()->json('shops'))->firstWhere('id', $this->shop);
        $this->assertSame('EBAY_API', $h['connection']['adapter']);
        $this->assertContains('No money file for more than a month', $h['warnings']);
        foreach (['15.5' => '#/connections', '6.4' => '#/hubs', '13.6' => '#/messages', '14.2' => '#/drive', '12.7' => '#/subscriptions'] as $no => $route)
            $this->assertSame($route, \App\Http\Controllers\AppController::routeOf((object) ['screen_no' => $no, 'forms' => '']));
    }
}
