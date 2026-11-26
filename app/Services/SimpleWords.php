<?php

namespace App\Services;

/**
 * Owner 26-11: easy everyday words on every screen – a person with little schooling must understand them.
 * ONE list. It changes only what is SHOWN (labels, headings, buttons, menu names, messages); database names, codes and stored data stay as they are.
 * The same list goes to the browser in boot ('words') and is applied there to the words of the screen – never to typed data or to table cells.
 * Longer phrases first; the first letter keeps its case.
 */
class SimpleWords
{
    public const MAP = [
        'vendor inventory' => 'supplier stock', 'vendors' => 'suppliers', 'vendor' => 'supplier',
        'marketplaces' => 'selling sites', 'marketplace' => 'selling site',
        'inventory' => 'stock', 'enterprise' => 'company', 'entity' => 'company', 'branches' => 'shops', 'branch' => 'shop',
        'carrier presets' => 'courier options', 'carrier preset' => 'courier option', 'carriers' => 'couriers', 'carrier' => 'courier', 'presets' => 'options', 'preset' => 'option', 'matrix' => 'table',
        'purchase price' => 'buying price', 'sale price' => 'selling price', 'list price' => 'full price', 'sourcing' => 'buying', 'procurement' => 'buying',
        'quantity' => 'how many', 'dimensions' => 'size', 'expiry' => 'end date', 'remarks' => 'notes', 'description' => 'details',
        'pending' => 'waiting', 'inactive' => 'switched off', 'deactivate' => 'switch off', 'mandatory' => 'needed', 'required' => 'needed', 'optional' => 'not needed',
        'insufficient' => 'not enough', 'invalid' => 'wrong', 'discrepancy' => 'difference', 'variance' => 'difference', 'threshold' => 'limit',
        'reconciliation' => 'matching', 'reconcile' => 'match', 'allocations' => 'shares', 'allocation' => 'share', 'allocate' => 'share out',
        'fulfilment' => 'sending orders', 'dispatch' => 'send out', 'dispatched' => 'sent out', 'consignment' => 'parcel batch',
        'evidence' => 'proof', 'acknowledge' => 'confirm seen', 'acknowledged' => 'seen', 'reassign' => 'give to someone else', 'dashboard' => 'overview',
        'estimated' => 'expected', 'outstanding' => 'still to pay', 'overdue' => 'late', 'duplicate' => 'same twice', 'visibility' => 'shown or hidden',
        'configuration' => 'settings', 'parameters' => 'settings', 'retrieve' => 'get', 'utilise' => 'use', 'commence' => 'start', 'terminate' => 'end',
        'obsolete' => 'no longer used', 'forwarder' => 'shipping agent', 'incoterm' => 'delivery terms', 'incoterms' => 'delivery terms',
    ];

    public static function apply(?string $text): string
    {
        static $re = null; $text = (string) $text; if ($text === '') return '';
        if ($re === null) { $k = array_keys(self::MAP); usort($k, fn ($a, $b) => strlen($b) <=> strlen($a)); $re = '/(?<![A-Za-z0-9_#\/.-])(' . implode('|', array_map('preg_quote', $k)) . ')(?![A-Za-z0-9_\/-]|\.[A-Za-z0-9])/i'; }
        return (string) preg_replace_callback($re, function ($m) {
            $to = self::MAP[strtolower($m[1])];
            if (strlen($m[1]) > 1 && strtoupper($m[1]) === $m[1]) return strtoupper($to);
            return ctype_upper($m[1][0]) ? ucfirst($to) : $to;
        }, $text);
    }
}
