<?php

namespace App\Services\Connect;

/**
 * Royal Mail Click & Drop API, built to Royal Mail's published contract (api.parcel.royalmail.com, API v1):
 *  - header Authorization: <API key>
 *  - POST {base}/orders  {items: [{orderReference, recipient{address{fullName, addressLine1, addressLine2, city, postcode, countryCode}, phoneNumber}, orderDate, subtotal, shippingCostCharged, total, currencyCode,
 *                                  packages[{weightInGrams, packageFormatIdentifier}], postageDetails{serviceCode}}]} → createdOrders[{orderIdentifier, orderReference, trackingNumber}], failedOrders[…]
 *  - GET  {base}/orders/{id;id;…}/label?documentType=postageLabel&includeReturnsLabel=false → one PDF
 * Tracking at Royal Mail is a separate API (adapter ROYALMAIL_TRACKING); this one gives the tracking link.
 * Secrets: api_key. Settings: base (https://api.parcel.royalmail.com/api/v1), package_format (parcel), default_service.
 * Built to contract and tested with Royal-Mail-shaped answers; NOT yet run against a real Click & Drop account.
 */
class RoyalMailClickDrop extends CarrierAdapter
{
    private function base(): string { return rtrim($this->cfg['base'] ?? 'https://api.parcel.royalmail.com/api/v1', '/'); }

    private function auth(): array { $this->need('api_key'); return ['Authorization' => $this->s['api_key']]; }

    public function test(): string
    {
        $j = Api::ok($this->call('GET', $this->base() . '/version', ['headers' => $this->auth()]), 'Royal Mail Click & Drop');
        return 'Click & Drop answered (API ' . ($j['release'] ?? $j['version'] ?? 'v1') . ').';
    }

    public function track(string $trackingNo): array
    {
        return ['status' => 'LINK', 'text' => 'Click & Drop does not give tracking events; open the Royal Mail page.', 'events' => [], 'url' => $this->linkFor($trackingNo)];
    }

    public function labels(array $parcels): array
    {
        $items = array_map(fn ($p) => ['orderReference' => mb_substr((string) $p['order_no'], 0, 40), 'orderDate' => gmdate('Y-m-d\TH:i:s\Z'), 'subtotal' => round((float) ($p['value'] ?? 0), 2), 'shippingCostCharged' => 0, 'total' => round((float) ($p['value'] ?? 0), 2), 'currencyCode' => $p['currency'] ?? 'GBP',
            'recipient' => ['address' => ['fullName' => mb_substr((string) $p['name'], 0, 70), 'addressLine1' => mb_substr((string) $p['address1'], 0, 100), 'addressLine2' => mb_substr((string) ($p['address2'] ?? ''), 0, 100), 'city' => (string) $p['city'], 'postcode' => (string) $p['postcode'], 'countryCode' => $p['country'] ?: 'GB'], 'phoneNumber' => (string) ($p['phone'] ?? '')],
            'packages' => [['weightInGrams' => max(1, (int) ($p['weight_g'] ?? 100)), 'packageFormatIdentifier' => $this->cfg['package_format'] ?? 'parcel']],
            'postageDetails' => ['serviceCode' => $p['service'] ?: ($this->cfg['default_service'] ?? 'CRL48')]], $parcels);
        $j = Api::ok($this->call('POST', $this->base() . '/orders', ['headers' => $this->auth(), 'json' => ['items' => $items]]), 'Royal Mail orders');
        $made = []; foreach ($j['createdOrders'] ?? [] as $o) $made[(string) $o['orderReference']] = ['id' => $o['orderIdentifier'], 'tracking' => $o['trackingNumber'] ?? null];
        if (! $made) throw new ApiError('Royal Mail made no label: ' . implode(' | ', array_map(fn ($f) => ($f['order']['orderReference'] ?? '') . ' ' . ($f['errors'][0]['errorMessage'] ?? 'refused'), array_slice($j['failedOrders'] ?? [], 0, 5))));
        $pdf = $this->call('GET', $this->base() . '/orders/' . implode(';', array_column($made, 'id')) . '/label', ['headers' => $this->auth(), 'query' => ['documentType' => 'postageLabel', 'includeReturnsLabel' => 'false']]);
        if ($pdf->failed() || ! str_starts_with($pdf->body(), '%PDF')) throw new ApiError('Royal Mail made the orders but gave no label PDF – ' . Api::errorText($pdf) . '. Print them from Click & Drop.');
        return ['pdf' => $pdf->body(), 'orders' => array_map(fn ($m) => $m['tracking'], $made), 'failed' => array_map(fn ($f) => ($f['order']['orderReference'] ?? '') . ': ' . ($f['errors'][0]['errorMessage'] ?? 'refused'), $j['failedOrders'] ?? [])];
    }
}
