<?php

namespace App\Services\Connect;

/**
 * eBay Sell APIs, built to eBay's published contract (developer.ebay.com):
 *  - token:   POST https://api.ebay.com/identity/v1/oauth2/token  (grant_type=refresh_token, Basic client_id:client_secret)
 *  - orders:  GET  https://api.ebay.com/sell/fulfillment/v1/order?filter=creationdate:[from..to]&limit=200&offset=n
 *  - money:   GET  https://apiz.ebay.com/sell/finances/v1/transaction?filter=transactionDate:[from..to]&limit=1000&offset=n
 * Secrets: client_id, client_secret, refresh_token. Settings: marketplace_id (EBAY_GB …), sandbox (1 = sandbox hosts).
 * Built to contract and tested with eBay-shaped answers; NOT yet run against a real eBay account.
 */
class EbayApi extends MarketplaceAdapter
{
    private const SCOPES = 'https://api.ebay.com/oauth/api_scope/sell.fulfillment.readonly https://api.ebay.com/oauth/api_scope/sell.finances';

    /** Fee type codes of the Finances API in words the money reader understands. */
    private const FEES = ['FINAL_VALUE_FEE' => 'Final value fee', 'FINAL_VALUE_FEE_FIXED_PER_ORDER' => 'Final value fee fixed per order', 'INTERNATIONAL_FEE' => 'International fee',
        'AD_FEE' => 'Promoted listings advert fee', 'PROMOTED_LISTINGS_FEE' => 'Promoted listings advert fee', 'REGULATORY_OPERATING_FEE' => 'Regulatory operating fee', 'BELOW_STANDARD_FEE' => 'Below standard fee',
        'HIGH_ITEM_NOT_AS_DESCRIBED_FEE' => 'Item not as described fee', 'INSERTION_FEE' => 'Insertion fee', 'SUBSCRIPTION_FEE' => 'Store subscription fee', 'PAYMENT_DISPUTE_FEE' => 'Payment dispute fee'];

    private function host(string $kind): string
    {
        $sb = ! empty($this->cfg['sandbox']);
        return ['api' => $sb ? 'https://api.sandbox.ebay.com' : 'https://api.ebay.com', 'apiz' => $sb ? 'https://apiz.sandbox.ebay.com' : 'https://apiz.ebay.com'][$kind];
    }

    private function token(): string
    {
        if (! empty($this->s['access_token']) && (int) ($this->s['access_expires'] ?? 0) > time() + 60) return $this->s['access_token'];
        $this->need('client_id', 'client_secret', 'refresh_token');
        $j = Api::ok($this->call('POST', $this->host('api') . '/identity/v1/oauth2/token', ['basic' => [$this->s['client_id'], $this->s['client_secret']], 'form' => ['grant_type' => 'refresh_token', 'refresh_token' => $this->s['refresh_token'], 'scope' => self::SCOPES]]), 'eBay sign-in');
        $this->s['access_token'] = $j['access_token'] ?? throw new ApiError('eBay sign-in gave no access token.');
        $this->s['access_expires'] = time() + (int) ($j['expires_in'] ?? 7200);
        $this->renewed = ['access_token' => $this->s['access_token'], 'access_expires' => $this->s['access_expires']];
        return $this->s['access_token'];
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer ' . $this->token(), 'X-EBAY-C-MARKETPLACE-ID' => $this->cfg['marketplace_id'] ?? 'EBAY_GB'];
    }

    public function test(): string
    {
        $j = Api::ok($this->call('GET', $this->host('api') . '/sell/fulfillment/v1/order', ['headers' => $this->headers(), 'query' => ['limit' => 1]]), 'eBay orders');
        return 'eBay answered: ' . (int) ($j['total'] ?? 0) . ' order(s) on the account.';
    }

    private static function range(string $from, string $to): string { return '[' . $from . 'T00:00:00.000Z..' . $to . 'T23:59:59.999Z]'; }

    public function orders(string $from, string $to): array
    {
        $out = []; $offset = 0;
        do {
            $j = Api::ok($this->call('GET', $this->host('api') . '/sell/fulfillment/v1/order', ['headers' => $this->headers(), 'query' => ['filter' => 'creationdate:' . self::range($from, $to), 'limit' => 200, 'offset' => $offset]]), 'eBay orders');
            foreach ($j['orders'] ?? [] as $o) {
                if (($o['cancelStatus']['cancelState'] ?? '') === 'CANCELED') continue;
                $to_ = $o['fulfillmentStartInstructions'][0]['shippingStep']['shipTo'] ?? []; $a = $to_['contactAddress'] ?? [];
                foreach ($o['lineItems'] ?? [] as $li) {
                    $qty = (int) ($li['quantity'] ?? 0); if ($qty <= 0) continue;
                    $out[] = ['order_no' => (string) $o['orderId'], 'd_order' => self::day($o['creationDate'] ?? null) ?? date('Y-m-d'), 'sku' => (string) ($li['sku'] ?? $li['legacyItemId'] ?? ''), 'qty' => $qty,
                        'sale_rate' => round((float) ($li['lineItemCost']['value'] ?? 0) / $qty, 2), 'product_info' => mb_substr((string) ($li['title'] ?? ''), 0, 250), 'currency' => $li['lineItemCost']['currency'] ?? null,
                        'cust_name' => (string) ($to_['fullName'] ?? $o['buyer']['username'] ?? ''), 'ship_to_name' => (string) ($to_['fullName'] ?? ''), 'ship_to_phone' => (string) ($to_['primaryPhone']['phoneNumber'] ?? ''),
                        'ship_to_address' => trim(($a['addressLine1'] ?? '') . ', ' . ($a['addressLine2'] ?? ''), ', '), 'ship_to_city' => (string) ($a['city'] ?? ''), 'ship_to_postcode' => (string) ($a['postalCode'] ?? ''),
                        'ship_to_country_code' => $a['countryCode'] ?? null, 'dispatch_by' => self::day($o['lineItems'][0]['lineItemFulfillmentInstructions']['shipByDate'] ?? null)];
                }
            }
            $offset += 200;
        } while (! empty($j['next']) && $offset < 20000);
        return array_values(array_filter($out, fn ($l) => $l['sku'] !== ''));
    }

    public function money(string $from, string $to): ?array
    {
        $rows = []; $offset = 0;
        do {
            $j = Api::ok($this->call('GET', $this->host('apiz') . '/sell/finances/v1/transaction', ['headers' => $this->headers(), 'query' => ['filter' => 'transactionDate:' . self::range($from, $to), 'limit' => 1000, 'offset' => $offset]]), 'eBay money');
            foreach ($j['transactions'] ?? [] as $t) {
                $type = (string) ($t['transactionType'] ?? ''); $payout = (string) ($t['payoutId'] ?? '');
                if ($payout === '' || in_array($type, ['TRANSFER', 'WITHDRAWAL', 'LOAN_REPAYMENT'], true)) continue;   // not paid out yet (comes with a later pull) / money moved, not earned
                $sign = ($t['bookingEntry'] ?? 'CREDIT') === 'DEBIT' ? -1 : 1; $amt = $sign * (float) ($t['amount']['value'] ?? 0); $cur = (string) ($t['amount']['currency'] ?? 'GBP');
                $d = self::day($t['transactionDate'] ?? null); $order = (string) ($t['orderId'] ?? ''); $li = $t['orderLineItems'] ?? [];
                $sku = '';   // the Finances API gives line item ids, not SKUs: the order number links the money to the order
                if ($type === 'SALE') {
                    $fees = 0.0; $feeRows = [];
                    foreach ($li as $l) foreach ($l['marketplaceFees'] ?? [] as $f) { $v = (float) ($f['amount']['value'] ?? 0); if ($v == 0.0) continue; $fees += $v; $feeRows[] = [self::FEES[$f['feeType'] ?? ''] ?? ('Fee ' . strtolower((string) ($f['feeType'] ?? ''))), $v]; }
                    if (! $feeRows && isset($t['totalFeeAmount']['value'])) { $fees = (float) $t['totalFeeAmount']['value']; if ($fees != 0.0) $feeRows[] = ['Final value fee', $fees]; }
                    $rows[] = self::moneyRow($payout, $d, $order, $sku, 0, 'Order', 'Sale', $amt + $fees, $cur, null);           // what the buyer paid = net + fees
                    foreach ($feeRows as [$what, $v]) $rows[] = self::moneyRow($payout, $d, $order, $sku, 0, 'Fee', $what, -$v, $cur, null);
                } elseif ($type === 'REFUND') $rows[] = self::moneyRow($payout, $d, $order, $sku, 0, 'Refund', (string) ($t['transactionMemo'] ?? 'Refund'), -abs($amt), $cur, null);
                elseif ($type === 'SHIPPING_LABEL') $rows[] = self::moneyRow($payout, $d, $order, $sku, 0, 'Shipping label', 'Postage label bought on eBay', $amt, $cur, null);
                elseif ($type === 'NON_SALE_CHARGE') $rows[] = self::moneyRow($payout, $d, $order, $sku, 0, 'Fee', self::FEES[$t['feeType'] ?? ''] ?? (string) ($t['transactionMemo'] ?? 'Other fee'), $amt, $cur, null);
                elseif ($type === 'DISPUTE') $rows[] = self::moneyRow($payout, $d, $order, $sku, 0, 'Dispute', 'Payment dispute / chargeback', $amt, $cur, null);
                elseif ($type === 'CREDIT') $rows[] = self::moneyRow($payout, $d, $order, $sku, 0, 'Reimbursement', (string) ($t['transactionMemo'] ?? 'Credit from eBay'), $amt, $cur, null);
                else $rows[] = self::moneyRow($payout, $d, $order, $sku, 0, ucfirst(strtolower($type)) ?: 'Other', (string) ($t['transactionMemo'] ?? $type), $amt, $cur, null);   // ADJUSTMENT, HOLD … kept, never dropped
            }
            $offset += 1000;
        } while (($j['total'] ?? 0) > $offset && $offset < 50000);
        return $rows;
    }
}
