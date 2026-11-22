<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-B_SOURCING-61 / IC-5 lot owner at GRN: the accepted units of each GRN line are split between owners pro rata to the ACTIVE PO-scope agreements of that PO
 * (DRAFT / ENDED never count): weight = committed capital, or the profit share % when an agreement has no capital (Settings › Choices LOT_SPLIT_BY).
 * One lot per owner, same cost and received date; created largest share first (equal shares: lower agreement id first) so the lot ids keep FIFO for the
 * largest share. Whole units, the remainder to the largest share; damaged units stay with the largest share. No ACTIVE agreement → the cycle owner
 * (else setting DEFAULT_STOCK_OWNER). No stacking is paid out by the waterfall (WaterfallService::stack, stacking rule per agreement).
 */
class LotOwners
{
    /** [[owner, weight, agreement id]] largest first; [] = no ACTIVE PO-scope agreement. */
    public static function shares(int $ent, int $poId, ?string $date = null): array
    {
        $ags = DB::table('fin_investor_agreements')->where('company_id', $ent)->where('scope', 'PO')->where('po_id', $poId)->where('status', 'ACTIVE')
            ->when($date, fn ($q) => $q->where('effective_from', '<=', $date)->where(fn ($w) => $w->whereNull('effective_to')->orWhere('effective_to', '>=', $date)))->orderBy('id')->get();
        if ($ags->isEmpty()) return [];
        $byCapital = Choices::get('LOT_SPLIT_BY', $ent) !== 'SHARE_PCT' && $ags->every(fn ($a) => (float) $a->capital_amount > 0);
        $w = $ags->map(fn ($a) => [(int) $a->investor_id, $byCapital ? (float) $a->capital_amount : (float) $a->profit_share_pct, (int) $a->id])->filter(fn ($x) => $x[1] > 0)->values()->all();
        usort($w, fn ($a, $b) => $b[1] <=> $a[1] ?: $a[2] <=> $b[2]);
        return $w;
    }

    /** Split $qty whole units by the weights: floor each, the remainder to the largest share. Returns [[owner, qty, agreement]] in creation order, qty > 0 only. */
    public static function split(int $qty, array $shares): array
    {
        $sum = array_sum(array_column($shares, 1)); if ($sum <= 0 || $qty <= 0) return [];
        $out = array_map(fn ($s) => [$s[0], (int) floor($qty * $s[1] / $sum + 1e-9), $s[2]], $shares);
        $out[0][1] += $qty - array_sum(array_column($out, 1));
        $merged = [];
        foreach ($out as [$owner, $q, $ag]) { if (isset($merged[$owner])) $merged[$owner][1] += $q; else $merged[$owner] = [$owner, $q, $ag]; }      /* two agreements of one partner → one lot */
        return array_values(array_filter($merged, fn ($x) => $x[1] > 0));
    }

    /** The receive lines of one GRN line: one per owner (damaged units with the largest share). */
    public static function lines(array $line, array $shares): array
    {
        if (! $shares) return [$line];
        $good = $line['qty'] - $line['damaged_qty']; $parts = self::split($good, $shares); $out = [];
        if (! $parts) return [$line];
        foreach ($parts as $i => [$owner, $q, $ag]) $out[] = ['owner' => $owner, 'qty' => $q + ($i === 0 ? $line['damaged_qty'] : 0), 'damaged_qty' => $i === 0 ? $line['damaged_qty'] : 0, 'agreement' => $ag] + $line;
        return $out;
    }
}
