<?php

namespace App\Services;

/**
 * Owner 26-11: the old tool's wrong words (Vendor …) are shown as the right word on every screen; normal trade words are not changed.
 * ONE list. It changes only what is SHOWN (labels, headings, buttons, menu names, messages); database names, codes and stored data stay as they are.
 * The same list goes to the browser in boot ('words') and is applied there to the words of the screen – never to typed data or to table cells.
 * Longer phrases first; the first letter keeps its case.
 */
class SimpleWords
{
    public const MAP = [
        /* Owner 26-11 (09:54): ONLY the old tool's wrong words change. Marketplace, Inventory, Stock, Carrier, Courier, Pending, Waiting, Required … are right and stay as written. */
        'vendors' => 'suppliers', 'vendor' => 'supplier',
        'enterprise' => 'company', 'entity' => 'company', 'branches' => 'shops', 'branch' => 'shop',
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
