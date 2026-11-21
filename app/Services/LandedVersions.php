<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Landed cost around the engine (BuyingService::landedCost):
 * - R-B_SOURCING-45 override per line: empty = calculated; reason required; the calculated value stays (calc_unit_cost); a change of more than
 *   LC_OVERRIDE_APPROVAL_PCT (10 %) waits for approval SRC_LC_OVERRIDE and the lots keep the calculated cost until then; applied → lots MANUAL;
 *   history LANDED_COST_OVERRIDE "<product>: calc X -> Y reason: …"; a re-run keeps an applied override and shows the new calc next to it.
 * - R-B_SOURCING-46 preview (the run is worked out and rolled back: nothing saved), FINAL only with the right, every product received, quantities
 *   Balanced and no missing rate.
 * - R-B_SOURCING-60 / R-FIN-47 units sold in a LOCKED shop-month are never edited: the cost difference goes to a COGS correction in the shop's next
 *   open month with the reason (fin_cogs_corrections); units sold in an open month take the new cost in place.
 */
class LandedVersions
{
    public const FINAL_RIGHT = 'buying.LANDED_FINAL';
    private static array $locked = [];

    public static function isLocked(?int $branch, string $month): bool
    {
        if (! $branch) return false;
        return self::$locked["$branch|$month"] ??= DB::table('aud_runs')->where('fk_id_branch', $branch)->where('period_month', $month)->where('status', 'LOCKED')->exists();
    }

    /** The first month after $month that is not LOCKED for the shop (an open placeholder month when none exists yet). */
    public static function nextOpen(int $branch, string $month): string
    {
        $m = $month;
        for ($i = 0; $i < 60; $i++) { $m = date('Y-m', strtotime("$m-01 +1 month")); if (! self::isLocked($branch, $m)) return $m; }
        return $m;
    }

    /**
     * A lot's cost changes from $old to $new: consumption rows in open months take the new cost; rows in LOCKED months stay and a correction is written
     * in the next open month. Returns [cost-of-sale change booked in place, cost-of-sale change booked as corrections].
     */
    public static function revalueConsumption(int $ent, int $lotId, float $new, string $reason, string $srcType, ?int $srcId): array
    {
        $inPlace = 0.0; $fwd = 0.0; self::$locked = [];
        $rows = DB::table('fin_lot_consumption as c')->leftJoin('t_sales as s', 's.id', '=', 'c.fk_id_sale')->where('c.lot_id', $lotId)->get(['c.id', 'c.qty', 'c.unit_cost_gbp', 'c.consumed_on', 's.fk_id_branch', 's.d_order', 's.fk_product_id', 'c.sku']);
        $prod = (int) DB::table('fin_lots')->where('id', $lotId)->value('fk_id_product');
        foreach ($rows as $r) {
            $d = round($new - (float) $r->unit_cost_gbp, 4); if ($d == 0.0) continue;
            $month = substr((string) ($r->d_order ?: $r->consumed_on), 0, 7);
            if ($r->fk_id_branch && self::isLocked((int) $r->fk_id_branch, $month)) {
                $amt = round($d * (float) $r->qty, 2); $fwd += $amt;
                DB::table('fin_cogs_corrections')->insert(['fk_id_ent' => $ent, 'fk_id_branch' => (int) $r->fk_id_branch, 'fk_id_product' => $prod ?: ((int) $r->fk_id_product ?: null), 'lot_id' => $lotId, 'source_month' => $month,
                    'target_month' => self::nextOpen((int) $r->fk_id_branch, $month), 'units' => (float) $r->qty, 'unit_delta' => $d, 'amount' => $amt, 'reason' => mb_substr($reason, 0, 255), 'source_type' => $srcType, 'source_id' => $srcId,
                    'created_by' => Context::userId(), 'created_at' => now()]);
                continue;      // the locked month keeps its figure
            }
            $inPlace += $d * (float) $r->qty;
            DB::table('fin_lot_consumption')->where('id', $r->id)->update(['unit_cost_gbp' => $new]);
        }
        return [round($inPlace, 2), round($fwd, 2)];
    }

    /** Currencies of a cycle (POs, freight, customs) with an open "Add rate" task. */
    public static function missingRates(int $ent, ?int $cycleId, array $poIds): array
    {
        $ids = DB::table('t_src_po')->whereIn('id', $poIds ?: [0])->pluck('fk_id_currency')->all();
        if ($cycleId) foreach (['t_src_freight_costs', 't_src_customs'] as $t) if (\Illuminate\Support\Facades\Schema::hasColumn($t, 'fk_id_currency')) $ids = array_merge($ids, DB::table($t)->where('fk_id_cycle', $cycleId)->where('f_active', 1)->pluck('fk_id_currency')->all());
        $codes = array_values(array_unique(array_filter(array_map(fn ($i) => $i ? Fx::code((int) $i, $ent) : null, $ids))));
        return array_values(array_filter($codes, fn ($c) => DB::table('tasks')->where('dedupe_key', 'like', "FIN_FX_MISSING:$c:%")->whereNotIn('status', ['DONE', 'APPROVED', 'CANCELLED'])->exists()));
    }

    /** Why this run may not be FINAL, or null. $prod: the engine's products (has_grn); $cycleId may be null. */
    public static function finalBlock(int $ent, array $prod, ?int $cycleId, array $poIds): ?string
    {
        if (array_filter($prod, fn ($p) => ! $p['has_grn'])) return 'Before every product is received the run can only be PROVISIONAL.';
        if ($cycleId && array_filter(CycleAlerts::quantities($cycleId), fn ($q) => $q['chip'] === 'Mismatch')) return 'The quantity check of this cycle shows a Mismatch. Make the GRN lines add up before the landed cost is FINAL.';
        if ($m = self::missingRates($ent, $cycleId, $poIds)) return 'A rate is missing (' . implode(', ', $m) . '). Add the rate before the landed cost is FINAL.';
        return null;
    }

    /** R-B_SOURCING-46 preview: the run worked out and rolled back. Per product: value, shares with method, accepted, per unit, old per unit, difference. */
    public static function preview(int $poId, array $d): array
    {
        $po = DB::table('t_src_po')->where('id', $poId)->where('fk_id_ent', Context::ent())->first() ?? throw new \InvalidArgumentException('PO not found.');
        $poIds = $po->fk_id_cycle ? DB::table('t_src_po')->where('fk_id_cycle', $po->fk_id_cycle)->where('f_active', 1)->pluck('id')->all() : [$poId];
        $old = DB::table('fin_lots')->whereIn('po_id', $poIds)->where('source', 'GRN')->groupBy('fk_id_product')->selectRaw('fk_id_product p, MAX(unit_cost_gbp) u')->pluck('u', 'p');
        $names = []; $r = null;
        DB::beginTransaction();
        try { $r = BuyingService::landedCost($poId, array_diff_key($d, ['final' => 1])); }
        finally { DB::rollBack(); }
        foreach (array_keys($r['products']) as $k) $names[$k] = DB::table('t_product_vendors')->where('id', $k)->value('SKU_VENDOR') ?: "#$k";
        $rows = [];
        foreach ($r['products'] as $k => $p) $rows[] = ['product_id' => $k, 'sku' => $names[$k], 'value' => $p['value'], 'shares' => $p['shares'], 'accepted' => $p['units'], 'unit' => $p['unit'],
            'old_unit' => isset($old[$k]) ? round((float) $old[$k], 4) : null, 'difference' => isset($old[$k]) ? round($p['unit'] - (float) $old[$k], 4) : null, 'formula' => $p['formula']];
        return ['rows' => $rows, 'warnings' => $r['warnings'], 'methods' => ['freight' => $d['freight_method'] ?? LandedEngine::defaultMethod('FREIGHT', (int) $po->fk_id_ent), 'duty' => $d['duty_method'] ?? LandedEngine::defaultMethod('DUTY', (int) $po->fk_id_ent)],
            'note' => 'Methods come from Settings › Sourcing: LC_FREIGHT_METHOD / LC_DUTY_METHOD – you may change them for this run. Nothing is saved until you press Save.'];
    }

    /**
     * R-B_SOURCING-46: a new freight / customs / GRN row of a cycle re-runs its landed cost as PROVISIONAL (the worker, every 15 minutes): once per cycle,
     * only after the change is 5 minutes old (debounce), never when the cycle's landed cost is FINAL (a later cost after FINAL is a version the user makes).
     */
    public static function autoRerun(int $ent, ?string $now = null): int
    {
        $now ??= date('Y-m-d H:i:s'); $cut = date('Y-m-d H:i:s', strtotime("$now -5 minutes")); $n = 0;
        $runs = DB::table('t_src_landed_cost')->where('fk_id_ent', $ent)->where('f_active', 1)->whereNull('superseded_by')->whereNotNull('fk_id_cycle')->where('landed_status', 'PROVISIONAL')->orderByDesc('id')->get()->unique('fk_id_cycle');
        foreach ($runs as $h) {
            if (DB::table('fin_lots')->where('fk_id_cycle', $h->fk_id_cycle)->where('landed_status', 'FINAL')->exists()) continue;
            $since = (string) $h->created_at; $c = (int) $h->fk_id_cycle;
            $changed = fn (string $t) => DB::table($t)->where('fk_id_cycle', $c)->where(fn ($w) => $w->where('created_at', '>', $since)->orWhere('updated_at', '>', $since))->selectRaw('MAX(GREATEST(COALESCE(updated_at, created_at), created_at)) m')->value('m');
            $vals = array_filter([$changed('t_src_freight_costs'), $changed('t_src_customs'), $changed('t_src_grn')]); $last = $vals ? max($vals) : null;
            if (! $last || $last > $cut) continue;      // nothing new, or changed less than 5 minutes ago
            $in = (json_decode((string) $h->warnings, true) ?: [])['input'] ?? [];
            try { BuyingService::landedCost((int) $h->fk_id_po, $in); $n++; } catch (\Throwable $e) { report($e); }
        }
        return $n;
    }

    // ───────────── R-B_SOURCING-45 override ─────────────

    public static function limitPct(int $ent): float { $v = Settings::get('LC_OVERRIDE_APPROVAL_PCT', $ent, null); return $v === null || $v === '' ? 10.0 : (float) $v; }

    /** Set or clear the override of a landed line. Returns the line state. */
    public static function override(int $lineId, ?float $unit, string $reason): array
    {
        return DB::transaction(function () use ($lineId, $unit, $reason) {
            $l = DB::table('t_src_landed_lines as l')->join('t_src_landed_cost as h', 'h.id', '=', 'l.fk_id_landed')->where('l.id', $lineId)->where('h.fk_id_ent', Context::ent())->lockForUpdate()
                ->first(['l.*', 'h.fk_id_cycle', 'h.fk_id_po', 'h.superseded_by', 'h.fk_id_ent', 'h.landed_status']) ?? throw new \InvalidArgumentException('Landed cost line not found.');
            if ($l->superseded_by) throw new \InvalidArgumentException('This version was replaced by a newer run – override the line of the newest version.');
            $ent = (int) $l->fk_id_ent; $calc = round((float) ($l->calc_unit_cost ?? $l->unit_cost), 4); $prod = DB::table('t_product_vendors')->where('id', $l->fk_id_product)->value('SKU_VENDOR') ?: '#' . $l->fk_id_product;
            if ($unit === null) {      // cleared: back to the calculated cost
                if ($l->override_status === 'APPLIED') self::applyUnit($ent, $l, $calc, 'Override removed: ' . $reason);
                DB::table('t_src_landed_lines')->where('id', $lineId)->update(['override_unit_cost' => null, 'override_reason' => null, 'override_status' => null, 'override_by' => null, 'override_at' => null, 'unit_cost' => $calc]);
                return ['status' => 'CALCULATED', 'unit' => $calc];
            }
            if (trim($reason) === '') throw new \InvalidArgumentException('Give the reason for the override.');
            if ($unit <= 0) throw new \InvalidArgumentException('The landed cost per unit must be more than 0.');
            $unit = round($unit, 4); $pct = $calc > 0 ? round(abs($unit - $calc) / $calc * 100, 1) : 100.0;
            $row = ['override_unit_cost' => $unit, 'override_reason' => mb_substr(trim($reason), 0, 255), 'override_by' => Context::userId(), 'override_at' => now()];
            $hist = "$prod: calc " . number_format($calc, 4) . ' -> ' . number_format($unit, 4) . ' reason: ' . trim($reason);
            if ($pct > self::limitPct($ent)) {
                $aid = Approvals::request($ent, 'SRC_LC_OVERRIDE', 't_src_landed_lines', $lineId, round(abs($unit - $calc) * (float) $l->accepted_qty, 2), ['line' => $lineId, 'unit' => $unit, 'calc' => $calc, 'pct' => $pct, 'reason' => trim($reason), 'category' => "Landed cost override $prod"], "Approve landed cost override $prod: " . number_format($calc, 4) . ' → ' . number_format($unit, 4) . " ($pct %)");
                DB::table('t_src_landed_lines')->where('id', $lineId)->update($row + ['override_status' => 'PENDING', 'fk_id_approval' => $aid]);
                if ($l->fk_id_cycle) Audit::log('t_src_cycles', (int) $l->fk_id_cycle, 'UPDATE', ['LANDED_COST_OVERRIDE' => $hist . " (waiting for approval, $pct %)"], trim($reason), $ent);
                return ['status' => 'PENDING', 'unit' => $calc, 'pct' => $pct, 'approval' => $aid];
            }
            DB::table('t_src_landed_lines')->where('id', $lineId)->update($row + ['override_status' => 'APPLIED', 'unit_cost' => $unit]);
            self::applyUnit($ent, $l, $unit, 'OVERRIDE: ' . $hist, 'MANUAL');
            if ($l->fk_id_cycle) Audit::log('t_src_cycles', (int) $l->fk_id_cycle, 'UPDATE', ['LANDED_COST_OVERRIDE' => $hist], trim($reason), $ent);
            return ['status' => 'APPLIED', 'unit' => $unit, 'pct' => $pct];
        });
    }

    /** Approval decided (handler of SRC_LC_OVERRIDE). */
    public static function decided(object $a, bool $ok): void
    {
        $l = DB::table('t_src_landed_lines as l')->join('t_src_landed_cost as h', 'h.id', '=', 'l.fk_id_landed')->where('l.id', $a->entity_id)->first(['l.*', 'h.fk_id_cycle', 'h.fk_id_po', 'h.superseded_by', 'h.fk_id_ent']);
        if (! $l || $l->override_status !== 'PENDING') return;
        if (! $ok || $l->superseded_by) { DB::table('t_src_landed_lines')->where('id', $l->id)->update(['override_status' => 'REJECTED']); return; }
        DB::table('t_src_landed_lines')->where('id', $l->id)->update(['override_status' => 'APPLIED', 'unit_cost' => $l->override_unit_cost]);
        self::applyUnit((int) $l->fk_id_ent, $l, (float) $l->override_unit_cost, 'OVERRIDE (approved): ' . $l->override_reason, 'MANUAL');
        if ($l->fk_id_cycle) Audit::log('t_src_cycles', (int) $l->fk_id_cycle, 'UPDATE', ['LANDED_COST_OVERRIDE' => 'approved ' . number_format((float) $l->override_unit_cost, 4)], $l->override_reason, (int) $l->fk_id_ent);
    }

    /** Give the lots of a line's product (in the run's POs) a new unit cost and book the difference (stock 1200 / cost of sale 5000 against costs on the way 1210). */
    private static function applyUnit(int $ent, object $l, float $unit, string $why, ?string $source = null): void
    {
        $poIds = $l->fk_id_cycle ? DB::table('t_src_po')->where('fk_id_cycle', $l->fk_id_cycle)->where('f_active', 1)->pluck('id')->all() : [(int) $l->fk_id_po];
        $base = DB::table('fin_lots')->whereIn('po_id', $poIds)->where('company_id', $ent)->where('source', 'GRN')->where('fk_id_product', $l->fk_id_product)->pluck('id')->all();
        $lots = DB::table('fin_lots')->whereIn('id', $base)->orWhere(fn ($w) => $w->where('source', 'SPLIT')->whereIn('source_id', $base ?: [0]))->lockForUpdate()->get();
        $toStock = 0.0; $toCogs = 0.0;
        foreach ($lots as $f) {
            $delta = $unit - (float) $f->unit_cost_gbp; if (round($delta, 4) == 0.0) continue;
            $q = (int) DB::table('fin_lot_balances')->where('fk_id_lot', $f->id)->sum('qty'); $toStock += round($unit * $q, 2) - round((float) $f->unit_cost_gbp * $q, 2);
            [$in, $fwd] = self::revalueConsumption($ent, (int) $f->id, $unit, 'Landed cost override: ' . $why, 'LANDED_OVERRIDE', (int) $l->id); $toCogs += $in + $fwd;
            DB::table('fin_lots')->where('id', $f->id)->update(['unit_cost_gbp' => $unit] + ($source ? ['cost_source' => $source] : []));
            DB::table('t_stock_movements')->where('fk_id_lot', $f->id)->update(['unit_cost_gbp' => $unit]);
        }
        $toStock = round($toStock, 2); $toCogs = round($toCogs, 2); $lines = [];
        if (abs($toStock) >= 0.01) $lines[] = $toStock > 0 ? ['account' => '1200', 'debit' => $toStock] : ['account' => '1200', 'credit' => -$toStock];
        if (abs($toCogs) >= 0.01) $lines[] = $toCogs > 0 ? ['account' => '5000', 'debit' => $toCogs] : ['account' => '5000', 'credit' => -$toCogs];
        $rest = round($toStock + $toCogs, 2); if (abs($rest) >= 0.01) $lines[] = $rest > 0 ? ['account' => '1210', 'credit' => $rest] : ['account' => '1210', 'debit' => -$rest];
        if (count($lines) > 1) Ledger::post($ent, Ledger::openDate($ent), 'LANDED_OVERRIDE', (int) $l->id, $lines, ['memo' => mb_substr($why, 0, 250)]);
        DB::table('t_product_vendors')->where('id', $l->fk_id_product)->update(['landing_price' => round($unit, 4)]);
    }
}
