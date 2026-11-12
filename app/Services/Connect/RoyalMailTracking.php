<?php

namespace App\Services\Connect;

/**
 * Royal Mail Tracking API v2, built to the published contract (developer.royalmail.net):
 *  GET https://api.royalmail.net/mailpieces/v2/{mailPieceId}/events  headers X-IBM-Client-Id, X-IBM-Client-Secret, X-Accept-RMG-Terms: yes
 *  → mailPieces{ summary{statusDescription, statusCategory}, events[{eventName, eventDateTime, locationName}] }
 * Secrets: client_id, client_secret. Built to contract and tested with Royal-Mail-shaped answers; NOT yet run against a real account.
 */
class RoyalMailTracking extends CarrierAdapter
{
    private function get(string $no): array
    {
        $this->need('client_id', 'client_secret');
        return Api::ok($this->call('GET', rtrim($this->cfg['base'] ?? 'https://api.royalmail.net', '/') . '/mailpieces/v2/' . rawurlencode($no) . '/events',
            ['headers' => ['X-IBM-Client-Id' => $this->s['client_id'], 'X-IBM-Client-Secret' => $this->s['client_secret'], 'X-Accept-RMG-Terms' => 'yes']]), 'Royal Mail tracking');
    }

    public function test(): string
    {
        try { $this->get('AA000000000GB'); } catch (ApiError $e) { if ($e->auth) throw $e; }   // an unknown number is fine – refused keys are not
        return 'Royal Mail tracking accepted the keys.';
    }

    public function track(string $trackingNo): array
    {
        $m = $this->get($trackingNo)['mailPieces'] ?? [];
        $cat = strtoupper((string) ($m['summary']['statusCategory'] ?? ''));
        return ['status' => str_contains($cat, 'DELIVERED') ? 'DELIVERED' : ($cat === '' ? 'UNKNOWN' : 'IN_TRANSIT'), 'text' => (string) ($m['summary']['statusDescription'] ?? ''),
            'events' => array_map(fn ($e) => ['at' => $e['eventDateTime'] ?? null, 'what' => $e['eventName'] ?? '', 'where' => $e['locationName'] ?? ''], $m['events'] ?? []), 'url' => $this->linkFor($trackingNo)];
    }
}
