<?php

namespace App\Services\Connect;

/**
 * TikTok Shop Partner API (version 202309), built to TikTok's published contract (partner.tiktokshop.com):
 *  - sign:   HMAC-SHA256(app_secret, app_secret + path + sorted(key+value of query without sign / access_token) + body + app_secret), hex
 *  - orders: POST {base}/order/202309/orders/search?shop_cipher=…&page_size=50 (+ page_token), body {create_time_ge, create_time_lt}
 *  - money:  GET  {base}/finance/202309/statements?statement_time_ge=…&statement_time_lt=… then /finance/202309/statements/{id}/statement_transactions
 *  - token:  GET  https://auth.tiktok-shops.com/api/v2/token/refresh?app_key&app_secret&refresh_token&grant_type=refresh_token
 * Secrets: app_key, app_secret, access_token, refresh_token. Settings: shop_cipher, base (https://open-api.tiktokglobalshop.com).
 * Built to contract and tested with TikTok-shaped answers; NOT yet run against a real shop.
 */
class TikTokShopApi extends MarketplaceAdapter
{
    private function base(): string { return rtrim($this->cfg['base'] ?? 'https://open-api.tiktokglobalshop.com', '/'); }

    public static function sign(string $path, array $query, string $body, string $secret): string
    {
        $q = array_diff_key($query, ['sign' => 1, 'access_token' => 1]); ksort($q);
        $plain = $path; foreach ($q as $k => $v) $plain .= $k . $v;
        return hash_hmac('sha256', $secret . $plain . $body . $secret, $secret);
    }

    private function refreshIfOld(): void
    {
        if ((int) ($this->s['access_expires'] ?? PHP_INT_MAX) > time() + 300 || empty($this->s['refresh_token'])) return;
        $j = Api::ok($this->call('GET', 'https://auth.tiktok-shops.com/api/v2/token/refresh', ['query' => ['app_key' => $this->s['app_key'], 'app_secret' => $this->s['app_secret'], 'refresh_token' => $this->s['refresh_token'], 'grant_type' => 'refresh_token']]), 'TikTok sign-in');
        $d = $j['data'] ?? []; if (empty($d['access_token'])) throw new ApiError('TikTok sign-in: ' . ($j['message'] ?? 'no access token'), true);
        $this->s['access_token'] = $d['access_token']; $this->s['refresh_token'] = $d['refresh_token'] ?? $this->s['refresh_token']; $this->s['access_expires'] = (int) ($d['access_token_expire_in'] ?? time() + 86400);
        $this->renewed = ['access_token' => $this->s['access_token'], 'refresh_token' => $this->s['refresh_token'], 'access_expires' => $this->s['access_expires']];
    }

    private function req(string $method, string $path, array $query = [], ?array $body = null, string $what = 'TikTok Shop'): array
    {
        $this->need('app_key', 'app_secret', 'access_token', 'shop_cipher');
        $this->refreshIfOld();
        $query += ['app_key' => $this->s['app_key'], 'timestamp' => time(), 'shop_cipher' => $this->cfg['shop_cipher']];
        $raw = $body === null ? '' : json_encode((object) $body);
        $query['sign'] = self::sign($path, $query, $raw, $this->s['app_secret']);
        $opt = ['headers' => ['x-tts-access-token' => $this->s['access_token'], 'content-type' => 'application/json'], 'query' => $query] + ($body === null ? [] : ['raw' => $raw]);
        $j = Api::ok($this->call($method, $this->base() . $path, $opt), $what);
        if ((int) ($j['code'] ?? 0) !== 0) throw new ApiError("$what: " . ($j['message'] ?? 'error ' . ($j['code'] ?? '')), in_array((int) ($j['code'] ?? 0), [105001, 105002, 36004004], true));
        return $j['data'] ?? [];
    }

    public function test(): string
    {
        $d = $this->req('POST', '/order/202309/orders/search', ['page_size' => 1], ['create_time_ge' => time() - 86400 * 7], 'TikTok orders');
        return 'TikTok Shop answered: ' . (int) ($d['total_count'] ?? 0) . ' order(s) in the last 7 days.';
    }

    public function orders(string $from, string $to): array
    {
        $out = []; $page = null; $n = 0;
        do {
            $d = $this->req('POST', '/order/202309/orders/search', ['page_size' => 50] + ($page ? ['page_token' => $page] : []), ['create_time_ge' => strtotime($from . ' 00:00:00 UTC'), 'create_time_lt' => strtotime($to . ' 23:59:59 UTC') + 1], 'TikTok orders');
            foreach ($d['orders'] ?? [] as $o) {
                if (in_array($o['status'] ?? '', ['CANCELLED', 'UNPAID'], true)) continue;
                $a = $o['recipient_address'] ?? [];
                // one line item = one unit at TikTok: lines of the same SKU are added up
                $by = [];
                foreach ($o['line_items'] ?? [] as $li) {
                    if (($li['display_status'] ?? '') === 'CANCELLED') continue;
                    $sku = (string) ($li['seller_sku'] ?? ''); if ($sku === '') continue;
                    $by[$sku] ??= ['qty' => 0, 'sum' => 0.0, 'cur' => $li['currency'] ?? null, 'name' => (string) ($li['product_name'] ?? '')];
                    $by[$sku]['qty']++; $by[$sku]['sum'] += (float) ($li['sale_price'] ?? 0);
                }
                foreach ($by as $sku => $b) $out[] = ['order_no' => (string) $o['id'], 'd_order' => gmdate('Y-m-d', (int) ($o['create_time'] ?? time())), 'sku' => $sku, 'qty' => $b['qty'], 'sale_rate' => round($b['sum'] / $b['qty'], 2),
                    'currency' => $b['cur'], 'product_info' => mb_substr($b['name'], 0, 250), 'cust_name' => (string) ($a['name'] ?? ''), 'ship_to_name' => (string) ($a['name'] ?? ''), 'ship_to_phone' => (string) ($a['phone_number'] ?? ''),
                    'ship_to_address' => (string) ($a['address_line1'] ?? $a['full_address'] ?? ''), 'ship_to_city' => (string) ($a['address_line3'] ?? ''), 'ship_to_postcode' => (string) ($a['postal_code'] ?? ''), 'ship_to_country_code' => $a['region_code'] ?? null,
                    'tracking_no' => ($o['tracking_number'] ?? '') ?: null];
            }
            $page = $d['next_page_token'] ?? null;
        } while ($page && ++$n < 400);
        return $out;
    }

    public function money(string $from, string $to): ?array
    {
        $rows = []; $page = null; $statements = [];
        do {
            $d = $this->req('GET', '/finance/202309/statements', ['statement_time_ge' => strtotime($from . ' 00:00:00 UTC'), 'statement_time_lt' => strtotime($to . ' 23:59:59 UTC') + 1, 'sort_field' => 'statement_time', 'page_size' => 50] + ($page ? ['page_token' => $page] : []), null, 'TikTok statements');
            foreach ($d['statements'] ?? [] as $st) if (($st['payment_status'] ?? 'PAID') !== 'PROCESSING') $statements[] = $st;
            $page = $d['next_page_token'] ?? null;
        } while ($page);
        foreach ($statements as $st) {
            $sid = (string) $st['id']; $date = gmdate('Y-m-d', (int) ($st['statement_time'] ?? time())); $cur = (string) ($st['currency'] ?? 'GBP'); $page = null;
            do {
                $d = $this->req('GET', '/finance/202309/statements/' . rawurlencode($sid) . '/statement_transactions', ['page_size' => 100, 'sort_field' => 'order_create_time'] + ($page ? ['page_token' => $page] : []), null, 'TikTok statement lines');
                foreach ($d['statement_transactions'] ?? [] as $t) {
                    $order = (string) ($t['order_id'] ?? ''); $type = strtoupper((string) ($t['type'] ?? 'ORDER'));
                    $kind = str_contains($type, 'REFUND') ? 'Refund' : (str_contains($type, 'ADJUST') ? 'Adjustment' : 'Order');
                    foreach (['revenue_amount' => [$kind === 'Refund' ? 'Refund' : 'Order', 'Revenue'], 'fee_amount' => ['Fee', 'Commission and transaction fees'], 'shipping_cost_amount' => ['Shipping', 'Shipping cost'],
                        'adjustment_amount' => ['Adjustment', 'Adjustment']] as $k => [$t2, $desc]) {
                        $v = (float) ($t[$k] ?? 0); if ($v == 0.0) continue;
                        $rows[] = self::moneyRow($sid, $date, $order, '', 0, $t2, $desc, $v, $cur, $date);
                    }
                }
                $page = $d['next_page_token'] ?? null;
            } while ($page);
        }
        return $rows;
    }
}
