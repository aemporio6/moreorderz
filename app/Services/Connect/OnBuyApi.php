<?php

namespace App\Services\Connect;

/**
 * OnBuy Seller API v2, built to OnBuy's published contract (docs.api.onbuy.com):
 *  - token:  POST https://api.onbuy.com/v2/auth/request-token (consumer_key, secret_key) → header Authorization: <access_token> (valid 15 minutes)
 *  - orders: GET  https://api.onbuy.com/v2/orders?site_id=2000&filter[status]=all&filter[modified_since]=…&limit=50&offset=n
 * OnBuy publishes no payout / fee API: its money comes in as files (Load money file). money() says so.
 * Secrets: consumer_key, secret_key. Settings: site_id (2000 = UK).
 * Built to contract and tested with OnBuy-shaped answers; NOT yet run against a real OnBuy account.
 */
class OnBuyApi extends MarketplaceAdapter
{
    private function token(): string
    {
        if (! empty($this->s['access_token']) && (int) ($this->s['access_expires'] ?? 0) > time() + 60) return $this->s['access_token'];
        $this->need('consumer_key', 'secret_key');
        $j = Api::ok($this->call('POST', 'https://api.onbuy.com/v2/auth/request-token', ['form' => ['consumer_key' => $this->s['consumer_key'], 'secret_key' => $this->s['secret_key']]]), 'OnBuy sign-in');
        $this->s['access_token'] = $j['access_token'] ?? throw new ApiError('OnBuy sign-in gave no access token.');
        $this->s['access_expires'] = isset($j['expires_at']) ? (int) $j['expires_at'] : time() + 840;
        $this->renewed = ['access_token' => $this->s['access_token'], 'access_expires' => $this->s['access_expires']];
        return $this->s['access_token'];
    }

    private function get(array $q): array
    {
        return Api::ok($this->call('GET', 'https://api.onbuy.com/v2/orders', ['headers' => ['Authorization' => $this->token()], 'query' => $q + ['site_id' => $this->cfg['site_id'] ?? 2000]]), 'OnBuy orders');
    }

    public function test(): string
    {
        $j = $this->get(['limit' => 1, 'filter[status]' => 'all']);
        return 'OnBuy answered: ' . (int) ($j['metadata']['total_rows'] ?? count($j['results'] ?? [])) . ' order(s) on the account.';
    }

    public function orders(string $from, string $to): array
    {
        $out = []; $offset = 0;
        do {
            $j = $this->get(['filter[status]' => 'all', 'filter[modified_since]' => $from . ' 00:00:00', 'limit' => 50, 'offset' => $offset, 'sort[created]' => 'asc']);
            foreach ($j['results'] ?? [] as $o) {
                $d = substr((string) ($o['date'] ?? $o['created'] ?? ''), 0, 10); if ($d === '' || $d > $to) continue;
                if (in_array(strtolower((string) ($o['status'] ?? '')), ['cancelled', 'refunded', 'awaiting payment'], true)) continue;
                $a = $o['delivery_address'] ?? [];
                foreach ($o['products'] ?? [] as $p) {
                    $qty = (int) ($p['quantity'] ?? 0); if ($qty <= 0 || ($p['sku'] ?? '') === '') continue;
                    $out[] = ['order_no' => (string) $o['order_id'], 'd_order' => $d, 'sku' => (string) $p['sku'], 'qty' => $qty, 'sale_rate' => round((float) ($p['unit_price'] ?? 0), 2), 'currency' => $o['currency_code'] ?? null,
                        'product_info' => mb_substr((string) ($p['name'] ?? ''), 0, 250), 'cust_name' => (string) ($o['buyer']['name'] ?? ''), 'ship_to_name' => (string) ($a['name'] ?? ''), 'ship_to_phone' => (string) ($o['buyer']['phone'] ?? ''),
                        'ship_to_address' => trim(($a['line_1'] ?? '') . ', ' . ($a['line_2'] ?? ''), ', '), 'ship_to_city' => (string) ($a['town'] ?? ''), 'ship_to_postcode' => (string) ($a['postcode'] ?? ''), 'ship_to_country_code' => $a['country_code'] ?? null,
                        'dispatch_by' => isset($o['dispatch_by']) ? substr((string) $o['dispatch_by'], 0, 10) : null];
                }
            }
            $offset += 50;
        } while (count($j['results'] ?? []) === 50 && $offset < 20000);
        return $out;
    }

    public function money(string $from, string $to): ?array { return null; }
}
