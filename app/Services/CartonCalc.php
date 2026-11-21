<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Carton / packing figures (R-B_SOURCING-34): one calculation for the carton form, the freight split (R-44), shipment totals and carton marks. CBM at 4 dp. */
class CartonCalc
{
    /** The volumetric divisor: the carrier's own, else the one of the shipping mode (VOLUMETRIC_DIVISOR_AIR …), else VOLUMETRIC_DIVISOR (default 5000); ≤ 0 → 5000. */
    public static function divisor(?int $ent, ?string $mode = null, ?int $carrierId = null): float
    {
        $d = $carrierId ? (float) DB::table('t_carriers')->where('id', $carrierId)->value('volumetric_divisor') : 0.0;
        if ($d <= 0 && $mode) $d = (float) Settings::get('VOLUMETRIC_DIVISOR_' . strtoupper(preg_replace('/\W+/', '_', $mode)), $ent, 0);
        if ($d <= 0) $d = (float) Settings::get('VOLUMETRIC_DIVISOR', $ent, 5000);
        return $d > 0 ? $d : 5000.0;
    }

    private static function n(array $v, string $k): float { return is_numeric($v[$k] ?? null) ? (float) $v[$k] : 0.0; }

    /** All figures from the typed ones (unit L/W/H, unit net / gross, units per carton, carton L/W/H, tare, weighed carton gross, planned units). */
    public static function calc(array $v, float $divisor = 5000): array
    {
        $ul = self::n($v, 'unit_l'); $uw = self::n($v, 'unit_w'); $uh = self::n($v, 'unit_h'); $upc = self::n($v, 'units_per_carton'); $plan = self::n($v, 'total_planned_units');
        $net = self::n($v, 'unit_net_weight'); $gross = self::n($v, 'unit_gross_weight'); $tare = self::n($v, 'carton_tare_weight'); $actual = self::n($v, 'actual_carton_gross_weight');
        $cl = self::n($v, 'carton_l'); $cw = self::n($v, 'carton_w'); $ch = self::n($v, 'carton_h'); $per = max($upc, 1);
        $full = (int) floor($plan / $per); $loose = (int) round($plan - $full * $per); $cartons = $full + ($loose > 0 ? 1 : 0);
        $cGrossCalc = round($upc * $gross + $tare, 4); $cGross = $actual > 0 ? $actual : $cGrossCalc;      // a weighed carton replaces the calculated weight
        $cCbm = round($cl * $cw * $ch / 1000000, 4);
        return ['unit_cbm' => round($ul * $uw * $uh / 1000000, 4), 'volumetric_weight' => round($ul * $uw * $uh / ($divisor > 0 ? $divisor : 5000), 4), 'carton_net_weight' => round($upc * $net, 4), 'carton_gross_weight' => $cGrossCalc,
            'full_cartons' => $full, 'loose_units' => $loose, 'carton_qty' => $cartons, 'total_units' => $plan, 'total_net_weight' => round($plan * $net, 2), 'total_gross_weight' => round($cartons * $cGross, 2),
            'carton_cbm' => $cCbm, 'total_cbm' => round($cCbm * $cartons, 4), 'gross_used' => $cGross, 'divisor' => $divisor];
    }

    /** The divisor for a carton row: the carrier / mode of the first shipment of its PO. */
    public static function divisorForRow(int $ent, array $row): float
    {
        $s = ! empty($row['fk_id_po']) ? DB::table('t_src_shipments')->where('fk_id_po', $row['fk_id_po'])->where('f_active', 1)->orderBy('id')->first(['shipping_mode']) : null;
        return self::divisor($ent, $s->shipping_mode ?? null);
    }

    /** Carton marks (M49): one mark per carton – PO, SKU, carton n of N, units, net / gross, CBM, made in. */
    public static function marks(int $ent, int $cartonRowId): array
    {
        $c = DB::table('t_src_cartons as c')->leftJoin('t_src_po as p', 'p.id', '=', 'c.fk_id_po')->leftJoin('cat_country as k', 'k.id', '=', 'c.fk_id_country')->where('c.id', $cartonRowId)->where('c.fk_id_ent', $ent)->first(['c.*', 'p.po_number', 'k.' . (Registry::labelColumn('cat_country') ?: 'id') . ' as country']) ?? throw new \InvalidArgumentException('Carton row not found.');
        $f = self::calc((array) $c, self::divisorForRow($ent, (array) $c)); $out = []; $n = max(1, (int) $f['carton_qty']); $per = max(1, (int) $c->units_per_carton);
        for ($i = 1; $i <= $n; $i++) { $units = $i === $n && $f['loose_units'] > 0 ? $f['loose_units'] : $per;
            $out[] = ['po' => $c->po_number, 'sku' => $c->sku, 'n' => $i, 'of' => $n, 'units' => $units, 'net' => round($units * (float) $c->unit_net_weight, 2), 'gross' => $i === $n && $f['loose_units'] > 0 && ! ((float) $c->actual_carton_gross_weight > 0) ? round($units * (float) $c->unit_gross_weight + (float) $c->carton_tare_weight, 2) : round($f['gross_used'], 2),
                'cbm' => $f['carton_cbm'], 'dims' => (float) $c->carton_l . ' × ' . (float) $c->carton_w . ' × ' . (float) $c->carton_h . ' cm', 'made_in' => $c->country, 'hs' => $c->hs_code]; }
        return $out;
    }
}
