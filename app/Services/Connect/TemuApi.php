<?php

namespace App\Services\Connect;

/**
 * Temu Seller Partner open API (router style), built to the contract Temu gives its partners (partner.temu.com):
 *  - one endpoint {base} (EU: https://openapi-b-eu.temu.com/openapi/router), POST JSON with type = method name, app_key, access_token, timestamp, data_type=JSON, sign
 *  - sign:  MD5( app_secret + sorted(key + value of every top-level parameter except sign; arrays / objects as JSON) + app_secret ), upper case hex
 *  - orders: type bg.order.list.v2.get (pageNumber, pageSize, createAfter, createBefore in seconds) → result.pageItems[].orderList[]
 * Temu publishes no payout / statement API and its developer documents are only open to approved partners: money comes in as the
 * statement Excel file (Load money file). money() says so.
 * Secrets: app_key, app_secret, access_token. Settings: base.
 * Built to the partner contract and tested with Temu-shaped answers; NOT yet run against a real Temu shop.
 */
class TemuApi extends MarketplaceAdapter
{
    public static function sign(array $p, string $secret): string
    {
        unset($p['sign']); ksort($p); $s = $secret;
        foreach ($p as $k => $v) $s .= $k . (is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) $v);
        return strtoupper(md5($s . $secret));
    }

    private function req(string $type, array $data, string $what): array
    {
        $this->need('app_key', 'app_secret', 'access_token');
        $p = ['type' => $type, 'app_key' => $this->s['app_key'], 'access_token' => $this->s['access_token'], 'timestamp' => time(), 'data_type' => 'JSON'] + $data;
        $p['sign'] = self::sign($p, $this->s['app_secret']);
        $j = Api::ok($this->call('POST', $this->cfg['base'] ?? 'https://openapi-b-eu.temu.com/openapi/router', ['json' => $p]), $what);
        if (empty($j['success'])) throw new ApiError("$what: " . ($j['errorMsg'] ?? 'refused') . (isset($j['errorCode']) ? ' (' . $j['errorCode'] . ')' : ''), in_array((int) ($j['errorCode'] ?? 0), [7000002, 7000003, 7000016], true));
        return $j['result'] ?? [];
    }

    public function test(): string
    {
        $r = $this->req('bg.order.list.v2.get', ['pageNumber' => 1, 'pageSize' => 1, 'createAfter' => time() - 86400 * 7, 'createBefore' => time()], 'Temu orders');
        return 'Temu answered: ' . (int) ($r['totalItemNum'] ?? 0) . ' order(s) in the last 7 days.';
    }

    public function orders(string $from, string $to): array
    {
        $out = []; $page = 1;
        do {
            $r = $this->req('bg.order.list.v2.get', ['pageNumber' => $page, 'pageSize' => 100, 'createAfter' => strtotime($from . ' 00:00:00 UTC'), 'createBefore' => strtotime($to . ' 23:59:59 UTC')], 'Temu orders');
            foreach ($r['pageItems'] ?? [] as $it) {
                $parent = $it['parentOrderMap'] ?? []; $no = (string) ($parent['parentOrderSn'] ?? ''); $d = isset($parent['parentOrderTime']) ? gmdate('Y-m-d', (int) $parent['parentOrderTime']) : date('Y-m-d');
                foreach ($it['orderList'] ?? [] as $o) {
                    $qty = (int) ($o['quantity'] ?? 0) - (int) ($o['canceledQuantityBeforeShipment'] ?? 0); $sku = (string) ($o['outSkuSnList'][0] ?? $o['skuId'] ?? '');
                    if ($qty <= 0 || $sku === '' || (int) ($o['orderStatus'] ?? 0) === 3) continue;   // 3 = cancelled
                    $out[] = ['order_no' => $no ?: (string) ($o['orderSn'] ?? ''), 'd_order' => $d, 'sku' => $sku, 'qty' => $qty, 'sale_rate' => round((float) ($o['goodsPrice'] ?? $o['basePrice'] ?? 0) / 100, 2),
                        'product_info' => mb_substr((string) ($o['goodsName'] ?? ''), 0, 250)];
                }
            }
            $page++;
        } while (count($r['pageItems'] ?? []) === 100 && $page < 200);
        return $out;
    }

    public function money(string $from, string $to): ?array { return null; }
}
