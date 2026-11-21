<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Landed-cost engine (R-B_SOURCING-44, IC-4): how the costs of a cycle are spread over its products.
 * Defaults as the old tool (X3): freight by Quantity (LC_FREIGHT_METHOD), duty by Value (LC_DUTY_METHOD), insurance / origin / destination / last-mile / FEE / COST / agent / other by Value;
 * an old-entry (HISTORICAL) cycle: everything by Value. Weight / CBM read the carton rows; a product without carton data makes that cost fall back to Quantity (with a warning).
 * Each share is rounded to 2 dp and the last product takes the remainder. Loss units (LC_LOSS_UNITS): ABSORB (accepted units carry damaged + missing) or WRITE_OFF (loss line + claim).
 */
class LandedEngine
{
    public const METHODS = ['VALUE', 'QUANTITY', 'WEIGHT', 'CBM'];
    private const FREIGHT_WORDS = '/freight|main|sea|air|ocean|courier|haulage|shipping|express|rail|truck/i';
    private const VALUE_WORDS = '/insur|origin|destination|last.?mile|fee|cost|agent|other|commission|inspection|packag/i';

    public static function method(?string $m): string { $m = strtoupper(trim((string) $m)); return in_array($m, self::METHODS, true) ? $m : 'VALUE'; }

    /** The split method of a cost by its kind (freight / duty / everything else). */
    public static function defaultMethod(string $kind, int $ent, bool $historical = false): string
    {
        if ($historical) return 'VALUE';
        if ($kind === 'FREIGHT') return self::method(Choices::get('LC_FREIGHT_METHOD', $ent) ?: 'QUANTITY');
        if ($kind === 'DUTY') return self::method(Choices::get('LC_DUTY_METHOD', $ent) ?: 'VALUE');
        return 'VALUE';
    }

    public static function kindOfCostType(?string $type): string { $t = (string) $type; return preg_match(self::FREIGHT_WORDS, $t) && ! preg_match(self::VALUE_WORDS, $t) ? 'FREIGHT' : 'OTHER'; }

    /** R-B_SOURCING-43: the ten buckets of a landed-cost line (product value first; import VAT is never one). */
    public const BUCKETS = ['product_cost' => 'Product', 'packaging_cost' => 'Packaging / supplier charges', 'inspection_cost' => 'Inspection', 'origin_cost' => 'Origin / export',
        'freight_cost' => 'Freight', 'insurance_cost' => 'Insurance', 'customs_duty' => 'Customs duty', 'destination_cost' => 'Destination', 'last_mile_cost' => 'Last mile', 'other_cost' => 'Other'];

    /** R-B_SOURCING-36 cost type → bucket: main freight → freight, origin / export → origin, destination / customs clearance → destination, last mile, insurance; Agent Fee / Other → other. */
    public static function bucketOf(?string $costType, bool $duty = false): string
    {
        if ($duty) return 'customs_duty';
        $t = strtolower((string) $costType);
        return match (true) {
            (bool) preg_match('/insur/', $t) => 'insurance_cost',
            (bool) preg_match('/origin|export/', $t) => 'origin_cost',
            (bool) preg_match('/destination|clearance|port charge|terminal/', $t) => 'destination_cost',
            (bool) preg_match('/last.?mile|delivery to|haulage to warehouse/', $t) => 'last_mile_cost',
            (bool) preg_match('/inspect|qc|audit/', $t) => 'inspection_cost',
            (bool) preg_match('/packag|packing|label|carton|supplier charge/', $t) => 'packaging_cost',
            (bool) preg_match('/agent|commission|other|fee/', $t) => 'other_cost',
            (bool) preg_match(self::FREIGHT_WORDS, $t) => 'freight_cost',
            default => 'other_cost',
        };
    }

    /** Total = Σ buckets; per unit = accepted > 0 ? round(total ÷ accepted, 4) : 0. */
    public static function bucketTotal(array $b, float $accepted): array
    {
        $total = round(array_sum(array_map(fn ($k) => (float) ($b[$k] ?? 0), array_keys(self::BUCKETS))), 2);
        return [$total, $accepted > 0 ? round($total / $accepted, 4) : 0.0];
    }

    /**
     * Pure calculation. $products: key => [name, value, ordered, gross, cbm, accepted, damaged, missing, has_grn]; $costs: [[label, amount, method]].
     * Returns per product: shares per cost, total, unit cost (4 dp), loss; plus warnings and the formula text.
     */
    public static function split(array $products, array $costs, string $loss = 'ABSORB'): array
    {
        $keys = array_keys($products); $out = []; $warn = [];
        foreach ($keys as $k) $out[$k] = ['shares' => [], 'extra' => 0.0];
        foreach ($costs as $ci => [$label, $amount, $method]) {
            $method = self::method($method); $amount = round((float) $amount, 2); $basis = [];
            if (in_array($method, ['WEIGHT', 'CBM'], true)) {
                $field = $method === 'WEIGHT' ? 'gross' : 'cbm'; $missing = array_filter($keys, fn ($k) => (float) ($products[$k][$field] ?? 0) <= 0);
                if ($missing || array_sum(array_map(fn ($k) => (float) $products[$k][$field], $keys)) <= 0) { foreach ($missing ?: $keys as $k) $warn[] = 'No carton data for ' . $products[$k]['name'] . " – $label split by quantity"; $method = 'QUANTITY'; }
                else foreach ($keys as $k) $basis[$k] = (float) $products[$k][$field];
            }
            if ($method === 'VALUE') { foreach ($keys as $k) $basis[$k] = (float) $products[$k]['value']; if (array_sum($basis) <= 0) { $method = 'QUANTITY'; $basis = []; } }
            if ($method === 'QUANTITY') foreach ($keys as $k) $basis[$k] = (float) $products[$k]['ordered'];
            $sum = array_sum($basis); $left = $amount; $last = end($keys);
            foreach ($keys as $k) {
                $s = $k === $last ? round($left, 2) : ($sum > 0 ? round($amount * $basis[$k] / $sum, 2) : 0.0);      // 2 dp; the last product takes the remainder, so the shares add up to the cost exactly
                $left = round($left - $s, 2); $out[$k]['shares'][] = ['cost' => $label, 'method' => $method, 'pct' => $sum > 0 ? round($basis[$k] / $sum * 100, 2) : 0, 'amount' => $s, 'bucket' => $costs[$ci][3] ?? self::bucketOf($label)]; $out[$k]['extra'] = round($out[$k]['extra'] + $s, 2);
            }
        }
        foreach ($keys as $k) {
            $p = $products[$k]; $total = round((float) $p['value'] + $out[$k]['extra'], 2);
            $acc = (float) ($p['has_grn'] ? $p['accepted'] : $p['ordered']); $dm = $p['has_grn'] ? (float) $p['damaged'] + (float) $p['missing'] : 0.0;
            $units = $loss === 'WRITE_OFF' ? $acc + $dm : $acc; $unit = $units > 0 ? round($total / $units, 4) : 0.0;
            $lossAmt = $loss === 'WRITE_OFF' ? round($unit * $dm, 2) : 0.0;
            $f = 'Value ' . number_format((float) $p['value'], 2) . ' + ' . implode(' + ', array_map(fn ($s) => "{$s['cost']} " . number_format($s['amount'], 2) . " ({$s['method']} {$s['pct']}%)", $out[$k]['shares'])) . ' = ' . number_format($total, 2)
                . ' ÷ ' . ($loss === 'WRITE_OFF' ? "($acc accepted + $dm damaged / missing)" : "$acc accepted" . ($dm ? " (carry $dm damaged / missing)" : '')) . ' = ' . number_format($unit, 4);
            $out[$k] += ['value' => round((float) $p['value'], 2), 'total' => $total, 'units' => $units, 'unit' => $unit, 'loss' => $lossAmt, 'formula' => $f];
        }
        return ['products' => $out, 'warnings' => array_values(array_unique($warn))];
    }
}
