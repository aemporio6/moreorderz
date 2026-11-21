<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** System › Health: what the database still misses, per module (R-A_SALES-13): migration files that did not run yet, and the tables / columns a module's own check misses. */
class SchemaCheck
{
    private const MODULES = ['sales' => 'Sales', 'ds_match' => 'Sales', 'va_statement' => 'Sales', 'shop_reminder' => 'Sales', 'carrier' => 'Sales', 'history_sales' => 'Sales', 'permission' => 'Users and rights', 'finder' => 'Search', 'lists' => 'Lists and exports',
        'stock' => 'Stock', 'warehouse' => 'Stock', 'sku_home' => 'Stock', 'print' => 'Printing', 'label' => 'Printing', 'money' => 'Money', 'settlement' => 'Money', 'bank' => 'Money', 'vat' => 'Money', 'finance' => 'Money', 'close' => 'Month close', 'partner' => 'Partners', 'waterfall' => 'Partners',
        'buying' => 'Buying', 'sourcing' => 'Buying', 'shipment' => 'Buying', 'import' => 'Old data import', 'connect' => 'Connections', 'vault' => 'Security', 'session' => 'Security'];

    public static function module(string $file): string { foreach (self::MODULES as $word => $m) if (str_contains($file, $word)) return $m; return 'Core'; }

    /** ['module' => [['file' => …, 'missing' => [...]], …]] – empty when nothing is missing. */
    public static function missing(?array $files = null, ?array $ran = null, ?callable $salesProbe = null): array
    {
        $files ??= array_map('basename', glob(database_path('migrations/*.php')) ?: []); sort($files);
        try { $ran ??= DB::table('migrations')->pluck('migration')->all(); } catch (\Throwable $e) { $ran = []; }
        $out = [];
        foreach ($files as $f) if (! in_array(preg_replace('/\.php$/', '', $f), $ran, true)) $out[self::module($f)][] = ['file' => $f, 'missing' => ['this migration has not run yet']];
        foreach (SalesImport::selfCheckFiles($salesProbe) as $file => $m) { $have = false; foreach (array_keys($out['Sales'] ?? []) as $i) if (str_contains($file, $out['Sales'][$i]['file'])) { $out['Sales'][$i]['missing'] = array_merge($out['Sales'][$i]['missing'], $m); $have = true; } if (! $have) $out['Sales'][] = ['file' => $file, 'missing' => $m]; }
        ksort($out); return $out;
    }
}
