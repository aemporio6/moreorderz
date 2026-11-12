<?php

namespace App\Services\Connect;

/** A carrier: where is a parcel (track) and, when the carrier has an API, buy labels for orders (labels). */
abstract class CarrierAdapter extends Adapter
{
    /** @return array{status: string, text: string, events: array, url: ?string} */
    abstract public function track(string $trackingNo): array;

    /** Buy labels for parcels. $parcels: [order_no, name, address1, address2, city, postcode, country, phone, weight_g, service, value, currency].
     *  @return array{pdf: string, orders: array<string, ?string>} the label PDF (one label per page) and order → tracking number */
    public function labels(array $parcels): array { throw new ApiError('This carrier connection cannot buy labels: load the carrier\'s label PDF under Orders › Carrier labels.'); }

    protected function linkFor(string $no): ?string
    {
        $p = (string) ($this->cfg['tracking_url_pattern'] ?? '');
        return $p === '' ? null : str_replace(['{tracking}', '{TRACKING}', '{no}'], rawurlencode($no), $p);
    }
}
