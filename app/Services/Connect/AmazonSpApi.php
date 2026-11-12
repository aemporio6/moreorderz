<?php

namespace App\Services\Connect;

/**
 * Amazon Selling Partner API, built to Amazon's published contract (developer-docs.amazon.com/sp-api):
 *  - token:  POST https://api.amazon.com/auth/o2/token (grant_type=refresh_token, client_id, client_secret) → header x-amz-access-token
 *  - orders: GET {endpoint}/orders/v0/orders?MarketplaceIds=…&CreatedAfter=…&CreatedBefore=… (+ NextToken), items: /orders/v0/orders/{id}/orderItems
 *  - money:  GET {endpoint}/finances/v0/financialEventGroups?FinancialEventGroupStartedAfter=… then /finances/v0/financialEventGroups/{id}/financialEvents
 *            one event group = one settlement = one payout (FundTransferDate).
 * Buyer name / address are restricted data at Amazon (they need a Restricted Data Token and Amazon's approval): they are not pulled.
 * Secrets: client_id, client_secret, refresh_token. Settings: endpoint (https://sellingpartnerapi-eu.amazon.com), marketplace_ids (A1F83G8C2ARO7P = UK).
 * Built to contract and tested with Amazon-shaped answers; NOT yet run against a real seller account.
 */
class AmazonSpApi extends MarketplaceAdapter
{
    private function base(): string { return rtrim($this->cfg['endpoint'] ?? 'https://sellingpartnerapi-eu.amazon.com', '/'); }

    private function token(): string
    {
        if (! empty($this->s['access_token']) && (int) ($this->s['access_expires'] ?? 0) > time() + 60) return $this->s['access_token'];
        $this->need('client_id', 'client_secret', 'refresh_token');
        $j = Api::ok($this->call('POST', 'https://api.amazon.com/auth/o2/token', ['form' => ['grant_type' => 'refresh_token', 'refresh_token' => $this->s['refresh_token'], 'client_id' => $this->s['client_id'], 'client_secret' => $this->s['client_secret']]]), 'Amazon sign-in');
        $this->s['access_token'] = $j['access_token'] ?? throw new ApiError('Amazon sign-in gave no access token.');
        $this->s['access_expires'] = time() + (int) ($j['expires_in'] ?? 3600);
        $this->renewed = ['access_token' => $this->s['access_token'], 'access_expires' => $this->s['access_expires']];
        return $this->s['access_token'];
    }

    private function get(string $path, array $q, string $what): array
    {
        return Api::ok($this->call('GET', $this->base() . $path, ['headers' => ['x-amz-access-token' => $this->token()], 'query' => $q]), $what);
    }

    public function test(): string
    {
        $j = $this->get('/sellers/v1/marketplaceParticipations', [], 'Amazon seller account');
        return 'Amazon answered: ' . count($j['payload'] ?? []) . ' marketplace(s) on the account.';
    }

    public function orders(string $from, string $to): array
    {
        $out = []; $next = null; $n = 0;
        $toTime = min(strtotime($to . ' 23:59:59'), time() - 180);   // Amazon refuses a CreatedBefore less than 2 minutes ago
        do {
            $q = $next ? ['MarketplaceIds' => $this->cfg['marketplace_ids'] ?? 'A1F83G8C2ARO7P', 'NextToken' => $next] : ['MarketplaceIds' => $this->cfg['marketplace_ids'] ?? 'A1F83G8C2ARO7P', 'CreatedAfter' => $from . 'T00:00:00Z', 'CreatedBefore' => gmdate('Y-m-d\TH:i:s\Z', $toTime), 'MaxResultsPerPage' => 100];
            $j = $this->get('/orders/v0/orders', $q, 'Amazon orders')['payload'] ?? [];
            foreach ($j['Orders'] ?? [] as $o) {
                if (in_array($o['OrderStatus'] ?? '', ['Canceled', 'Pending'], true)) continue;   // a pending order has no confirmed price yet: it comes with a later pull
                $items = $this->get('/orders/v0/orders/' . rawurlencode($o['AmazonOrderId']) . '/orderItems', [], 'Amazon order items')['payload']['OrderItems'] ?? [];
                foreach ($items as $it) {
                    $qty = (int) ($it['QuantityOrdered'] ?? 0); if ($qty <= 0) continue;
                    $out[] = ['order_no' => (string) $o['AmazonOrderId'], 'd_order' => substr((string) $o['PurchaseDate'], 0, 10), 'sku' => (string) ($it['SellerSKU'] ?? ''), 'qty' => $qty,
                        'sale_rate' => round((float) ($it['ItemPrice']['Amount'] ?? 0) / $qty, 2), 'currency' => $it['ItemPrice']['CurrencyCode'] ?? null, 'product_info' => mb_substr((string) ($it['Title'] ?? ''), 0, 250),
                        'ship_to_postcode' => (string) ($o['ShippingAddress']['PostalCode'] ?? ''), 'ship_to_city' => (string) ($o['ShippingAddress']['City'] ?? ''), 'ship_to_country_code' => $o['ShippingAddress']['CountryCode'] ?? null,
                        'dispatch_by' => isset($o['LatestShipDate']) ? substr((string) $o['LatestShipDate'], 0, 10) : null];
                }
            }
            $next = $j['NextToken'] ?? null;
        } while ($next && ++$n < 200);
        return array_values(array_filter($out, fn ($l) => $l['sku'] !== ''));
    }

    public function money(string $from, string $to): ?array
    {
        $rows = []; $next = null; $groups = [];
        do {
            $q = $next ? ['NextToken' => $next] : ['FinancialEventGroupStartedAfter' => $from . 'T00:00:00Z', 'FinancialEventGroupStartedBefore' => gmdate('Y-m-d\TH:i:s\Z', min(strtotime($to . ' 23:59:59'), time() - 180)), 'MaxResultsPerPage' => 100];
            $j = $this->get('/finances/v0/financialEventGroups', $q, 'Amazon settlements')['payload'] ?? [];
            foreach ($j['FinancialEventGroupList'] ?? [] as $g) if (($g['ProcessingStatus'] ?? '') === 'Closed') $groups[] = $g;   // an open group is still growing: it comes with a later pull
            $next = $j['NextToken'] ?? null;
        } while ($next);
        foreach ($groups as $g) {
            $gid = (string) $g['FinancialEventGroupId']; $pay = isset($g['FundTransferDate']) ? substr((string) $g['FundTransferDate'], 0, 10) : null; $next = null;
            do {
                $ev = $this->get('/finances/v0/financialEventGroups/' . rawurlencode($gid) . '/financialEvents', $next ? ['NextToken' => $next] : ['MaxResultsPerPage' => 100], 'Amazon settlement lines')['payload'] ?? [];
                $e = $ev['FinancialEvents'] ?? [];
                foreach (['ShipmentEventList' => ['ShipmentItemList', 'ItemChargeList', 'ItemFeeList', 'Order'], 'RefundEventList' => ['ShipmentItemAdjustmentList', 'ItemChargeAdjustmentList', 'ItemFeeAdjustmentList', 'Refund']] as $list => [$itemsK, $chK, $feeK, $kind]) {
                    foreach ($e[$list] ?? [] as $s) {
                        $d = isset($s['PostedDate']) ? substr((string) $s['PostedDate'], 0, 10) : null; $order = (string) ($s['AmazonOrderId'] ?? '');
                        foreach ($s[$itemsK] ?? [] as $it) {
                            $sku = (string) ($it['SellerSKU'] ?? ''); $qty = (int) ($it['QuantityShipped'] ?? 0);
                            foreach ($it[$chK] ?? [] as $c) { $v = (float) ($c['ChargeAmount']['CurrencyAmount'] ?? 0); if ($v != 0.0) $rows[] = self::moneyRow($gid, $d, $order, $sku, $qty, $kind, self::words((string) $c['ChargeType']), $v, (string) ($c['ChargeAmount']['CurrencyCode'] ?? 'GBP'), $pay); }
                            foreach ($it[$feeK] ?? [] as $f) { $v = (float) ($f['FeeAmount']['CurrencyAmount'] ?? 0); if ($v != 0.0) $rows[] = self::moneyRow($gid, $d, $order, $sku, $qty, $kind === 'Refund' ? 'Refund' : 'Fee', self::words((string) $f['FeeType']), $v, (string) ($f['FeeAmount']['CurrencyCode'] ?? 'GBP'), $pay); }
                            foreach ($it['PromotionList'] ?? $it['PromotionAdjustmentList'] ?? [] as $p) { $v = (float) ($p['PromotionAmount']['CurrencyAmount'] ?? 0); if ($v != 0.0) $rows[] = self::moneyRow($gid, $d, $order, $sku, $qty, $kind, 'Promotional rebate', $v, (string) ($p['PromotionAmount']['CurrencyCode'] ?? 'GBP'), $pay); }
                        }
                    }
                }
                foreach ($e['ServiceFeeEventList'] ?? [] as $s) foreach ($s['FeeList'] ?? [] as $f) { $v = (float) ($f['FeeAmount']['CurrencyAmount'] ?? 0); if ($v != 0.0) $rows[] = self::moneyRow($gid, null, (string) ($s['AmazonOrderId'] ?? ''), '', 0, 'Fee', self::words((string) $f['FeeType']), $v, (string) ($f['FeeAmount']['CurrencyCode'] ?? 'GBP'), $pay); }
                foreach ($e['ProductAdsPaymentEventList'] ?? [] as $s) { $v = (float) ($s['transactionValue']['CurrencyAmount'] ?? 0); if ($v != 0.0) $rows[] = self::moneyRow($gid, isset($s['postedDate']) ? substr((string) $s['postedDate'], 0, 10) : null, '', '', 0, 'Advertising', 'Sponsored products advertising', $v, (string) ($s['transactionValue']['CurrencyCode'] ?? 'GBP'), $pay); }
                foreach ($e['AdjustmentEventList'] ?? [] as $s) { $v = (float) ($s['AdjustmentAmount']['CurrencyAmount'] ?? 0); if ($v != 0.0) $rows[] = self::moneyRow($gid, isset($s['PostedDate']) ? substr((string) $s['PostedDate'], 0, 10) : null, '', '', 0, str_contains(strtolower((string) $s['AdjustmentType']), 'reimburs') ? 'Reimbursement' : 'Adjustment', self::words((string) $s['AdjustmentType']), $v, (string) ($s['AdjustmentAmount']['CurrencyCode'] ?? 'GBP'), $pay); }
                foreach ($e['ChargebackEventList'] ?? [] as $s) foreach ($s['ShipmentItemAdjustmentList'] ?? [] as $it) foreach ($it['ItemChargeAdjustmentList'] ?? [] as $c) { $v = (float) ($c['ChargeAmount']['CurrencyAmount'] ?? 0); if ($v != 0.0) $rows[] = self::moneyRow($gid, null, (string) ($s['AmazonOrderId'] ?? ''), (string) ($it['SellerSKU'] ?? ''), 0, 'Chargeback', self::words((string) $c['ChargeType']), $v, (string) ($c['ChargeAmount']['CurrencyCode'] ?? 'GBP'), $pay); }
                $next = $ev['NextToken'] ?? null;
            } while ($next);
        }
        return $rows;
    }

    /** "FBAPerUnitFulfillmentFee" → "FBA per unit fulfillment fee" (the money reader sorts by these words). */
    private static function words(string $code): string
    {
        $w = trim(preg_replace('/(?<=[a-z])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', ' ', $code));
        return ucfirst(strtolower($w)) === '' ? 'Other' : preg_replace('/^Fba /i', 'FBA ', ucfirst(strtolower($w)));
    }
}
