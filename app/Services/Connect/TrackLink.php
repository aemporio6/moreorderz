<?php

namespace App\Services\Connect;

/** No API: the parcel is followed on the carrier's own page (the carrier's tracking link). Works today for every carrier with a link pattern. */
class TrackLink extends CarrierAdapter
{
    public function test(): string { return $this->linkFor('TEST123') ? 'Tracking link works: ' . $this->linkFor('TEST123') : 'The carrier has no tracking link pattern yet (Carrier profiles › tracking URL, with {tracking} where the number goes).'; }

    public function track(string $trackingNo): array
    {
        return ['status' => 'LINK', 'text' => 'Open the carrier\'s page to see where the parcel is.', 'events' => [], 'url' => $this->linkFor($trackingNo)];
    }
}
