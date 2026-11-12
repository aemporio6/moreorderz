<?php

namespace App\Services\Connect;

/**
 * Pulls of a marketplace. orders() gives order lines in the shape of the order intake (the same as an order file);
 * money() gives money lines in the long layout the money-file reader takes (Payout ID, Date, Order ID, Type, Description, Amount, Currency, Payout date),
 * so an API pull is booked by exactly the same code as an uploaded file.
 */
abstract class MarketplaceAdapter extends Adapter
{
    public const MONEY_HEAD = ['Payout ID', 'Date', 'Order ID', 'SKU', 'Quantity', 'Type', 'Description', 'Amount', 'Currency', 'Payout date'];

    /** @return array<int, array> order lines */
    abstract public function orders(string $from, string $to): array;

    /** @return array<int, array> money rows (MONEY_HEAD order), or null when the service has no money API (files only) */
    abstract public function money(string $from, string $to): ?array;

    protected static function moneyRow(string $payout, ?string $date, string $order, string $sku, int $qty, string $type, string $desc, float $amount, string $cur, ?string $payDate): array
    {
        return [$payout, $date, $order, $sku, $qty, $type, $desc, round($amount, 2), strtoupper($cur), $payDate];
    }

    protected static function day(?string $iso): ?string { return $iso ? substr((string) $iso, 0, 10) : null; }
}
