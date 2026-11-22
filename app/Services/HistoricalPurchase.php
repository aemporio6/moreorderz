<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Old (back-dated) purchases: R-B_SOURCING-5 Old entry screen, R-B_SOURCING-8 its payments, R-B_SOURCING-13 the historical CSV (Import Center type SRC_HISTORICAL).
 * One entry = one HISTORICAL cycle (RFQ / samples / production / QC skipped) → PO (CONFIRMED at the order rate) → payment terms + payments (one Paid term per payment,
 * an open "Balance (open)" term when part is still due) → shipment with freight / duty / other costs → GRN posted when goods were received (lots through the stock service,
 * owners by R-B_SOURCING-61). Completed only when a received date is given and every PRODUCT line is fully received. Old money before go-live has no bank line, so
 * payments are booked without a bank journal (paid_via HISTORICAL – the opening balances carry the money). Every entry returns WARN / NEW / NOTE / OVERRIDE log lines.
 */
class HistoricalPurchase
{
    public const COLUMNS = ['order_ref', 'order_ref_note', 'supplier_name', 'order_date', 'currency', 'owner_entity', 'brand', 'line_type', 'product_name', 'sku', 'qty', 'unit_price', 'exchange_rate', 'units_per_carton',
        'paid_amount', 'paid_currency', 'paid_date', 'paid_amount_2', 'paid_date_2', 'shipped_date', 'arrived_date', 'mode', 'forwarder', 'tracking', 'freight_cost', 'freight_currency', 'duty_cost', 'other_cost',
        'received_date', 'warehouse', 'qty_accepted', 'qty_damaged', 'qty_missing', 'batch', 'notes'];
    public const REQUIRED = ['order_ref', 'supplier_name', 'product_name', 'qty', 'unit_price'];

    private static function date(?string $v): ?string
    {
        $v = trim((string) $v); if ($v === '') return null;
        foreach (['Y-m-d', 'd M Y', 'j M Y', 'd/m/Y'] as $f) { $d = \DateTime::createFromFormat('!' . $f, $v); if ($d && $d->format($f) === $v) return $d->format('Y-m-d'); }
        return null;
    }
    private static function num(mixed $v): float { return MarketplaceFiles::num($v); }

    /** Pay-currency amount → order currency (R-B_SOURCING-30): through base at each currency's rate of the payment date. */
    private static function toOrder(float $amt, string $payCur, string $orderCur, string $date, int $ent): float
    {
        if ($payCur === $orderCur) return round($amt, 2);
        return round($amt * Fx::rate($payCur, $date, $ent) / max(0.000001, Fx::rate($orderCur, $date, $ent)), 2);
    }

    /* ---------------- one entry (screen or CSV group) ---------------- */

    public static function entry(int $ent, array $e, object $u): array
    {
        $log = []; $ref = SrcDuplicate::clean($e['order_ref'] ?? ''); $date = $e['order_date'] ?? null;
        $lines = array_values(array_filter((array) ($e['lines'] ?? []), fn ($l) => ! empty($l['product']) || trim((string) ($l['product_name'] ?? '')) !== '' || trim((string) ($l['line_type'] ?? '')) !== ''));
        if ((empty($e['supplier']) && trim((string) ($e['supplier_name'] ?? '')) === '') || ! $date || $ref === '' || ! $lines) throw new \InvalidArgumentException('Section 1: Supplier, Order Date, Order Reference and at least one line are required');
        return DB::transaction(function () use ($ent, $e, $u, $ref, $date, $lines, &$log) {
            /* supplier: chosen, or found by name; a new name is created (logged NEW) */
            $sup = ! empty($e['supplier']) ? DB::table('t_src_suppliers')->where('id', (int) $e['supplier'])->where('fk_id_ent', $ent)->first()
                : DB::table('t_src_suppliers')->where('fk_id_ent', $ent)->whereRaw('LOWER(TRIM(supplier_name)) = ?', [mb_strtolower(trim((string) $e['supplier_name']))])->where('f_active', 1)->first();
            if (! $sup) {
                if (empty($e['supplier_name'])) throw new \InvalidArgumentException('Select the Supplier');
                $sid = (int) DB::table('t_src_suppliers')->insertGetId(RecordService::fillNotNull('t_src_suppliers', ['fk_id_ent' => $ent, 'supplier_name' => mb_substr(trim((string) $e['supplier_name']), 0, 190), 'f_active' => 1, 'f_local' => 0, 'f_print_cn' => 0, 'created_by' => $u->id, 'created_at' => now()]));
                $sup = DB::table('t_src_suppliers')->where('id', $sid)->first(); $log[] = "NEW supplier \"{$sup->supplier_name}\"";
            }
            /* owner entity: chosen or found by code / name – never created silently */
            $owner = ! empty($e['owner']) ? (int) $e['owner'] : null;
            if (! $owner && ($on = trim((string) ($e['owner_name'] ?? ''))) !== '') $owner = (int) (DB::table('t_account')->where(fn ($w) => $w->where('account_code', $on)->orWhereRaw("LOWER(TRIM(CONCAT_WS(' ', first_name, sur_name))) = ?", [mb_strtolower($on)]))->value('id') ?? 0) ?: null;
            if (! $owner && Choices::get('OLD_ENTRY_OWNER_REQUIRED', $ent) === 'Y') throw new \InvalidArgumentException('Owner Entity (buyer) is required – companies & persons come from Setup > Investor Master - never created silently.' . (! empty($e['owner_name']) ? " \"{$e['owner_name']}\" was not found." : ''));
            /* duplicate supplier + order reference (R-B_SOURCING-7) */
            if ($x = SrcDuplicate::existing($ent, (int) $sup->id, $ref)) {
                if (trim((string) ($e['force_reason'] ?? '')) === '') throw new SrcDuplicateException($x, SrcDuplicate::message($x));
                $log[] = "OVERRIDE duplicate of {$x->cycle_code}: " . trim((string) $e['force_reason']);
            }
            $cur = strtoupper((string) ($e['currency'] ?? '')) ?: ($sup->fk_id_currency ? Fx::code($sup->fk_id_currency, $ent) : Fx::base($ent));
            $rate = (float) ($e['rate'] ?? 0) > 0 ? round((float) $e['rate'], 6) : Fx::rate($cur, $date, $ent);
            if ((float) ($e['rate'] ?? 0) <= 0 && $cur !== Fx::base($ent)) $log[] = "NOTE rate of $date used: $rate";
            /* products: chosen, or by SKU, else a draft product (logged NEW; it goes live when stock arrives) */
            $po_lines = []; $cps = []; $n = 0; $drafts = [];
            foreach ($lines as $l) {
                $n++; $type = strtoupper(trim((string) ($l['line_type'] ?? ''))) ?: 'PRODUCT'; $p = null;
                if ($type === 'PRODUCT') {
                    $p = ! empty($l['product']) ? DB::table('t_product_vendors')->where('id', (int) $l['product'])->where('fk_id_ent', $ent)->first() : null;
                    if (! $p && ($sku = trim((string) ($l['sku'] ?? ''))) !== '') $p = DB::table('t_product_vendors')->where('fk_id_ent', $ent)->where('SKU_VENDOR', $sku)->first();
                    if (! $p) {
                        $name = trim((string) ($l['product_name'] ?? '')) ?: "Line $n"; $sku = trim((string) ($l['sku'] ?? '')) ?: 'DRAFT-' . strtoupper(substr(md5($ref . $n . $name), 0, 8));
                        $pid = (int) DB::table('t_product_vendors')->insertGetId(RecordService::fillNotNull('t_product_vendors', ['fk_id_ent' => $ent, 'SKU_VENDOR' => mb_substr($sku, 0, 100), 'product_name_vendor' => mb_substr($name, 0, 250), 'f_active' => 0, 'stock' => 0, 'e_product_type' => 'pool']));
                        $p = DB::table('t_product_vendors')->where('id', $pid)->first(); $drafts[] = $pid; $log[] = "NEW product $sku \"$name\" (draft – it goes live when stock arrives)";
                    }
                }
                $qty = (float) ($l['qty'] ?? 0); $price = (float) ($l['unit_price'] ?? 0);
                if ($type === 'PRODUCT' && $qty <= 0) throw new \InvalidArgumentException("Line $n: the quantity must be more than zero.");
                $po_lines[] = ['product' => $p->id ?? null, 'qty' => $qty, 'unit_price' => $price, 'line_type' => $type, 'name' => trim((string) ($l['product_name'] ?? '')) ?: null, 'line_notes' => $l['notes'] ?? null];
                $cps[] = $p;
            }
            $first = collect($cps)->filter()->first();
            if (($e['brand'] ?? '') !== '' && ! ctype_digit((string) $e['brand'])) $log[] = "NOTE brand \"{$e['brand']}\" kept in the notes only (choose the brand on the cycle)";
            $cycle = (int) DB::table('t_src_cycles')->insertGetId(['fk_id_ent' => $ent, 'cycle_code' => Numbering::next('CYC', $ent, $date), 'sourcing_date' => $date, 'requested_by' => $u->id, 'product_name' => mb_substr((string) ($first->product_name_vendor ?? 'Old purchase'), 0, 200),
                'brand' => ctype_digit((string) ($e['brand'] ?? '')) ? (int) $e['brand'] : null, 'target_qty' => array_sum(array_map(fn ($l) => $l['line_type'] === 'PRODUCT' ? $l['qty'] : 0, $po_lines)), 'priority' => 'NORMAL', 'status' => 'OPEN', 'notes' => trim(($e['source'] ?? '') === 'CSV' ? 'CSV import' . ($e['notes'] ? ' · ' . $e['notes'] : '') : (string) ($e['notes'] ?? '')) ?: null,
                'cycle_type' => 'HISTORICAL', 'skipped_steps' => 'rfq,samples,production,qc', 'approval_status' => 'APPROVED', 'approved_by' => $u->id, 'approved_at' => now(), 'order_ref' => mb_substr($ref, 0, 100), 'order_ref_note' => mb_substr((string) ($e['order_ref_note'] ?? ''), 0, 100) ?: null,
                'fk_id_owner' => $owner, 'created_by' => $u->id, 'f_active' => 1, 'created_at' => now()]);
            foreach ($cps as $i => $p) if ($p) DB::table('t_src_cycle_products')->insert(['fk_id_ent' => $ent, 'fk_id_cycle' => $cycle, 'fk_id_supplier' => $sup->id, 'fk_id_product' => $p->id, 'product_name' => $p->product_name_vendor, 'sku' => $p->SKU_VENDOR,
                'target_qty' => $po_lines[$i]['qty'], 'target_unit_cost' => $po_lines[$i]['unit_price'], 'row_status' => 'CONFIRMED', 'created_by' => $u->id, 'f_active' => 1, 'created_at' => now()]);
            $po = BuyingService::createPo(['cycle' => $cycle, 'supplier' => $sup->id, 'currency' => $cur, 'date' => $date, 'deposit_pct' => 0, 'lines' => $po_lines, 'dup_checked' => true]);
            DB::table('t_src_po')->where('id', $po)->update(['status' => 'CONFIRMED', 'exchange_rate' => $rate, 'approved_by' => (string) $u->id, 'approval_date' => $date, 'notes' => $e['notes'] ?? null]);
            PoLines::resyncPo($po);
            $total = (float) DB::table('t_src_po')->where('id', $po)->value('po_total');
            /* R-B_SOURCING-8 payments: one Paid term per payment, an open balance term */
            $paid = 0.0; $tn = 0; $tol = (float) Settings::get('SRC_MONEY_TOL', $ent, 0.009);
            foreach ((array) ($e['payments'] ?? []) as $p) {
                $amt = round((float) ($p['amount'] ?? 0), 2); if ($amt <= 0) continue;
                $pc = strtoupper((string) ($p['currency'] ?? '')) ?: $cur; $pd = ($p['date'] ?? null) ?: $date; $pr = Fx::rate($pc, $pd, $ent); $inOrder = self::toOrder($amt, $pc, $cur, $pd, $ent); $tn++;
                $term = (int) DB::table('t_src_payment_terms')->insertGetId(['fk_id_ent' => $ent, 'fk_id_cycle' => $cycle, 'fk_id_po' => $po, 'term_no' => $tn, 'milestone' => $tn === 1 ? 'Deposit / Payment 1' : "Payment $tn", 'pct' => $total > 0 ? round($inOrder / $total * 100, 2) : 0,
                    'planned_amount' => $inOrder, 'paid_amount' => $inOrder, 'remaining_amount' => 0, 'completion_pct' => 100, 'status' => 'PAID', 'due_date' => $pd, 'created_by' => $u->id, 'f_active' => 1, 'created_at' => now()]);
                DB::table('t_src_payments')->insert(['fk_id_ent' => $ent, 'payment_code' => Numbering::next('PAY', $ent, $pd), 'fk_id_cycle' => $cycle, 'fk_id_po' => $po, 'fk_id_supplier' => $sup->id, 'fk_id_term' => $term, 'payment_type' => $tn === 1 ? 'Deposit' : 'Balance',
                    'payment_method' => $p['method'] ?? null, 'payment_amount' => $amt, 'fk_id_currency' => BuyingService::currencyId($pc), 'exchange_rate' => $pr, 'base_amount' => round($amt * $pr, 2), 'payment_date' => $pd, 'status' => 'PAID', 'txn_ref' => $p['ref'] ?? null,
                    'created_by' => $u->id, 'f_active' => 1, 'created_at' => now(), 'paid_via' => 'HISTORICAL']);
                if ($pc !== $cur) $log[] = "NOTE payment $tn: $amt $pc = $inOrder $cur at the rates of $pd";
                $paid = round($paid + $inOrder, 2);
            }
            $open = round($total - $paid, 2);
            if ($paid > 0 && $open > $tol) {
                $due = date('Y-m-d', strtotime("$date +" . (int) Choices::get('OLD_ENTRY_BALANCE_DUE_DAYS', $ent) . ' days'));
                DB::table('t_src_payment_terms')->insert(['fk_id_ent' => $ent, 'fk_id_cycle' => $cycle, 'fk_id_po' => $po, 'term_no' => $tn + 1, 'milestone' => 'Balance (open)', 'pct' => $total > 0 ? round($open / $total * 100, 2) : 0, 'planned_amount' => $open, 'paid_amount' => 0,
                    'remaining_amount' => $open, 'completion_pct' => 0, 'status' => 'DUE', 'due_date' => $due, 'due_rule' => 'HISTORICAL', 'created_by' => $u->id, 'f_active' => 1, 'created_at' => now()]);
                $log[] = 'Balance OPEN: ' . number_format($open, 2) . " $cur still due (shows on the dashboard)";
            }
            DB::table('t_src_po')->where('id', $po)->update(['deposit_amount' => 0, 'balance_amount' => max(0, $open), 'f_paid_in_full' => $paid > 0 && $open <= $tol ? 1 : 0]);
            /* shipment, costs, receiving */
            $sh = (array) ($e['shipping'] ?? []); $rc = (array) ($e['received'] ?? []); $ship = null;
            $accOf = fn ($l) => ($l['qty_accepted'] ?? null) === null || ($l['qty_accepted'] ?? '') === '' ? (float) ($l['qty'] ?? 0) : (float) $l['qty_accepted'];      /* R-B_SOURCING-10: a line with no receiving row counts as accepted = ordered */
            $hasRecv = ! empty($rc['date']) && collect($lines)->contains(fn ($l) => (strtoupper((string) ($l['line_type'] ?? '')) ?: 'PRODUCT') === 'PRODUCT' && $accOf($l) > 0);
            if (! empty($sh['shipped_date']) || ! empty($sh['arrived_date']) || $hasRecv || ! empty($sh['freight'])) {
                $fw = ! empty($sh['forwarder']) ? DB::table('t_src_forwarders')->where('fk_id_ent', $ent)->whereRaw('LOWER(TRIM(forwarder_name)) = ?', [mb_strtolower(trim((string) $sh['forwarder']))])->value('id') : null;
                if (! empty($sh['forwarder']) && ! $fw) $log[] = "WARN forwarder \"{$sh['forwarder']}\" not found – left empty";
                $ship = BuyingService::createShipment(['po' => $po, 'etd' => $sh['shipped_date'] ?? null, 'eta' => ($sh['arrived_date'] ?? null) ?: ($rc['date'] ?? null), 'mode' => $sh['mode'] ?? null, 'forwarder' => $fw]);
                DB::table('t_src_shipments')->where('id', $ship)->update(['actual_departure' => $sh['shipped_date'] ?? null, 'actual_arrival' => ($sh['arrived_date'] ?? null) ?: ($rc['date'] ?? null), 'tracking_url' => mb_substr((string) ($sh['tracking'] ?? ''), 0, 250) ?: null]);
                $cd = ($sh['shipped_date'] ?? null) ?: $date;
                foreach ((array) ($sh['freight'] ?? []) as $f) { $amt = round((float) ($f['cost'] ?? 0), 2); if ($amt <= 0) continue; $fc = strtoupper((string) ($f['currency'] ?? '')) ?: $cur; $fr = Fx::rate($fc, $cd, $ent);
                    DB::table('t_src_freight_costs')->insert(['fk_id_ent' => $ent, 'freight_code' => Numbering::next('FRT', $ent, $cd), 'fk_id_cycle' => $cycle, 'fk_id_shipment' => $ship, 'fk_id_forwarder' => $fw, 'cost_type' => 'FREIGHT', 'amount' => $amt, 'fk_id_currency' => BuyingService::currencyId($fc),
                        'exchange_rate' => $fr, 'base_amount' => round($amt * $fr, 2), 'cost_date' => $cd, 'pay_status' => 'PAID', 'paid_date' => $cd, 'created_by' => $u->id, 'f_active' => 1, 'created_at' => now()]); }
                if (($o = round((float) ($sh['other'] ?? 0), 2)) > 0) DB::table('t_src_freight_costs')->insert(['fk_id_ent' => $ent, 'freight_code' => Numbering::next('FRT', $ent, $cd), 'fk_id_cycle' => $cycle, 'fk_id_shipment' => $ship, 'cost_type' => 'OTHER', 'amount' => $o, 'fk_id_currency' => BuyingService::currencyId(Fx::base($ent)),
                    'exchange_rate' => 1, 'base_amount' => $o, 'cost_date' => $cd, 'pay_status' => 'PAID', 'paid_date' => $cd, 'created_by' => $u->id, 'f_active' => 1, 'created_at' => now()]);
                if (($du = round((float) ($sh['duty'] ?? 0), 2)) > 0) DB::table('t_src_customs')->insert(['fk_id_ent' => $ent, 'customs_code' => Numbering::next('CUS', $ent, $cd), 'fk_id_cycle' => $cycle, 'fk_id_shipment' => $ship, 'duty_amount' => $du, 'fk_id_currency' => BuyingService::currencyId(Fx::base($ent)),
                    'exchange_rate' => 1, 'base_amount' => $du, 'status' => 'CLEARED', 'clearance_date' => ($sh['arrived_date'] ?? null) ?: $cd, 'pay_status' => 'PAID', 'paid_date' => $cd, 'created_by' => $u->id, 'f_active' => 1, 'created_at' => now()]);
            }
            $done = false;
            if ($ship && $hasRecv) {
                $wh = $rc['warehouse'] ?? null;
                if ($wh && ! ctype_digit((string) $wh)) $wh = DB::table('cat_inventory_locations')->where('fk_id_ent', $ent)->whereRaw('LOWER(TRIM(location_name)) = ?', [mb_strtolower(trim((string) $wh))])->value('id');
                $wh = (int) ($wh ?: Settings::get('MAIN_WAREHOUSE', $ent, 0)) ?: (int) DB::table('cat_inventory_locations')->where('fk_id_ent', $ent)->where('f_active', 1)->whereIn('kind', ['WAREHOUSE', 'BIN'])->orderBy('id')->value('id');
                if (! $wh) throw new \InvalidArgumentException('Received goods need a warehouse – add one under Setup › Locations.');
                $pl = DB::table('t_src_po_lines')->where('fk_id_po', $po)->where('f_active', 1)->orderBy('id')->get()->values(); $gl = []; $k = 0; $extra = [];
                foreach ($lines as $i => $l) { $row = $pl[$i] ?? null; if (! $row || ! PoLines::isStock($row->line_type)) continue;
                    $acc = $accOf($l); $dmg = (float) ($l['qty_damaged'] ?? 0); $mis = ($l['qty_missing'] ?? '') === '' || ($l['qty_missing'] ?? null) === null ? max(0, (float) $row->qty_ordered - $acc - $dmg) : (float) $l['qty_missing'];
                    $gl[] = ['po_line' => $row->id, 'received' => $acc + $dmg, 'damaged' => $dmg, 'missing' => $mis];
                    $k++; $ov = ($l['landed_override'] ?? '') !== '' && ($l['landed_override'] ?? null) !== null ? round((float) $l['landed_override'], 4) : null;
                    if ($ov !== null && trim((string) ($l['override_reason'] ?? '')) === '') throw new \InvalidArgumentException("Line " . ($i + 1) . ': give the reason for the landed cost override.');
                    $extra[(int) $row->fk_id_product] = ['batch' => trim((string) ($l['batch'] ?? '')) ?: "HIST-$cycle-$k", 'override' => $ov, 'reason' => trim((string) ($l['override_reason'] ?? ''))]; }
                $grn = BuyingService::createGrn(['shipment' => $ship, 'location' => $wh, 'date' => $rc['date'], 'lines' => $gl]);
                DB::table('t_src_grn')->where('id', $grn)->update(['grn_source' => 'HISTORICAL', 'receiving_qc_status' => 'Accepted', 'notes' => mb_substr("Historical entry $ref", 0, 250)]);
                if (self::needsApproval($ent, $u)) {      /* R-B_SOURCING-5: stock unaffected until a Partner / the Owner approves */
                    $pending = true; DB::table('t_src_cycles')->where('id', $cycle)->update(['approval_status' => 'PENDING', 'approved_by' => null, 'approved_at' => null]);
                    $base = round((float) DB::table('t_src_po')->where('id', $po)->value('po_total') * $rate, 2);
                    Approvals::request($ent, 'SRC_HISTORICAL', 't_src_cycles', $cycle, $base, ['cycle' => $cycle, 'grn' => $grn, 'drafts' => $drafts, 'ref' => $ref, 'extra' => $extra], "Approve old purchase $ref (" . $sup->supplier_name . ') – its stock waits');
                    $log[] = 'NOTE pending approval – stock unaffected until a Partner approves';
                } else self::postReceived($cycle, $grn, $drafts, $extra);
                $log[] = 'GRN + ' . count($extra) . ' lot(s)' . (! empty($pending) ? ' (INACTIVE until approved)' : '');
                $done = ! empty($pending) ? false : ! DB::table('t_src_po_lines as p')->where('p.fk_id_po', $po)->where('p.f_active', 1)->where(fn ($q) => $q->whereNull('p.line_type')->orWhereIn('p.line_type', ['', 'PRODUCT']))
                    ->whereRaw('p.qty_ordered - 0.001 > (SELECT COALESCE(SUM(l.accepted_qty + l.damaged_qty + l.missing_qty),0) FROM t_src_grn_lines l WHERE l.fk_id_po_line = p.id)')->exists();
            } elseif (! empty($rc['date'])) $log[] = 'NOTE received date given but no accepted quantity – nothing received';
            DB::table('t_src_cycles')->where('id', $cycle)->update(['status' => $done ? 'COMPLETED' : 'OPEN']);
            $log[] = $done ? 'Cycle CLOSED (every PRODUCT line received)' : (! empty($pending) ? 'Cycle OPEN until the approval' : 'Cycle OPEN at the first unfilled step');
            Audit::log('t_src_cycles', $cycle, 'INSERT', ['HISTORICAL' => $ref, 'log' => $log], $e['force_reason'] ?? null, $ent);
            return ['cycle' => $cycle, 'po' => $po, 'code' => DB::table('t_src_cycles')->where('id', $cycle)->value('cycle_code'), 'completed' => $done, 'pending' => ! empty($pending), 'log' => $log,
                'message' => ! empty($pending) ? 'Saved PENDING APPROVAL - stock unaffected until a Partner approves. Opening cycle...' : 'Saved. Opening cycle...'];
        });
    }

    /** Settings › Choices OLD_ENTRY_APPROVAL: NOT_OWNER (default) = an old entry with received goods made by anyone but the Owner waits for approval. */
    public static function needsApproval(int $ent, object $u): bool { return Choices::get('OLD_ENTRY_APPROVAL', $ent) === 'NOT_OWNER' && ($u->role_code ?? '') !== 'OWNER'; }

    /** Post the GRN of an entry: lots, drafts live, cycle completed when every PRODUCT line is received. */
    public static function postReceived(int $cycle, int $grn, array $drafts, array $extra = []): bool
    {
        BuyingService::postGrn($grn);
        foreach ($extra as $pid => $x) {      /* R-B_SOURCING-10 per-line batch and the landed cost override (audit-logged with the reason) */
            $lots = DB::table('fin_lots')->where('source', 'GRN')->where('source_id', $grn)->where('fk_id_product', (int) $pid)->get(['id', 'unit_cost_gbp']);
            foreach ($lots as $l) {
                $set = ['batch_no' => mb_substr((string) $x['batch'], 0, 40)];
                if ($x['override'] !== null) { $set += ['unit_cost_gbp' => $x['override'], 'landed_status' => 'FINAL', 'cost_source' => 'OVERRIDE']; Audit::log('fin_lots', (int) $l->id, 'UPDATE', ['unit_cost_gbp' => [(float) $l->unit_cost_gbp, $x['override']]], 'Landed cost override: ' . $x['reason']); }
                DB::table('fin_lots')->where('id', $l->id)->update($set);
            }
        }
        if ($drafts) DB::table('t_product_vendors')->whereIn('id', $drafts)->whereIn('id', DB::table('fin_lots')->where('fk_id_cycle', $cycle)->select('fk_id_product'))->update(['f_active' => 1]);      /* a new product with stock is live */
        $po = (int) DB::table('t_src_po')->where('fk_id_cycle', $cycle)->value('id');
        $done = ! DB::table('t_src_po_lines as p')->where('p.fk_id_po', $po)->where('p.f_active', 1)->where(fn ($q) => $q->whereNull('p.line_type')->orWhereIn('p.line_type', ['', 'PRODUCT']))
            ->whereRaw('p.qty_ordered - 0.001 > (SELECT COALESCE(SUM(l.accepted_qty + l.damaged_qty + l.missing_qty),0) FROM t_src_grn_lines l WHERE l.fk_id_po_line = p.id)')->exists();
        DB::table('t_src_cycles')->where('id', $cycle)->update(['status' => $done ? 'COMPLETED' : 'OPEN']);
        return $done;
    }

    /** The approval handler: approved → the stock is posted; refused → nothing is received (the GRN stays a draft, the cycle shows REJECTED). */
    public static function decided(object $a, bool $ok, ?string $reason): void
    {
        $c = json_decode((string) $a->change_json, true);
        if ($ok) { DB::table('t_src_cycles')->where('id', $c['cycle'])->update(['approval_status' => 'APPROVED', 'approved_by' => Context::userId(), 'approved_at' => now()]); self::postReceived((int) $c['cycle'], (int) $c['grn'], (array) ($c['drafts'] ?? []), (array) ($c['extra'] ?? [])); }
        else DB::table('t_src_cycles')->where('id', $c['cycle'])->update(['approval_status' => 'REJECTED']);
        Audit::log('t_src_cycles', (int) $c['cycle'], $ok ? 'APPROVE' : 'REJECT', ['old purchase' => $c['ref'] ?? ''], $reason, (int) $a->fk_id_ent);
    }

    /* ---------------- R-B_SOURCING-13 the CSV ---------------- */

    public static function template(): string
    {
        $ex = [['PI-1001', '', 'Ningbo Housewares', '2025-03-01', 'USD', 'OWNER-CODE', '', 'PRODUCT', 'Steel mug', 'MUG-STEEL', '500', '1.20', '', '50', '300', 'USD', '2025-03-02', '300', '2025-04-10', '2025-04-15', '2025-05-20', 'SEA', '', '', '180', 'USD', '45', '0', '2025-05-22', '', '495', '3', '2', '', ''],
            ['PI-1001', '', 'Ningbo Housewares', '2025-03-01', 'USD', 'OWNER-CODE', '', 'PRODUCT', 'Steel lid', 'LID-STEEL', '500', '0.30', '', '100', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '500', '0', '0', '', '']];
        return implode(',', self::COLUMNS) . "\n" . implode("\n", array_map(fn ($r) => implode(',', $r), $ex)) . "\n";
    }

    /** The CSV as entries (no write). */
    public static function parse(string $text): array
    {
        if (trim($text) === '') throw new \InvalidArgumentException('Empty file.');
        $rows = PurchaseAudit::csv($text); $head = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', $h))), array_shift($rows) ?? []);
        foreach (self::REQUIRED as $c) if (! in_array($c, $head, true)) throw new \InvalidArgumentException("Missing required column: $c");
        $groups = []; $log = []; $skipped = 0;
        foreach ($rows as $i => $r) {
            if (! array_filter($r, fn ($x) => trim((string) $x) !== '')) continue;
            $a = []; foreach ($head as $k => $h) $a[$h] = trim((string) ($r[$k] ?? ''));
            if (($a['order_ref'] ?? '') === '') { $log[] = 'Line ' . ($i + 2) . ' skipped: order_ref is REQUIRED'; $skipped++; continue; }
            if (($a['product_name'] ?? '') === '' && ($a['line_type'] ?? '') === '') { $log[] = 'Line ' . ($i + 2) . ' skipped: product_name empty'; $skipped++; continue; }
            $groups[$a['order_ref']][] = $a;
        }
        $entries = [];
        foreach ($groups as $ref => $g) {
            $f = $g[0]; $warn = []; $pay = []; $freight = [];
            $explicit = false;
            if (array_key_exists('paid_amount_1', $f)) for ($n = 1; $n <= 5; $n++) if (isset($f["paid_amount_$n"]) && self::num($f["paid_amount_$n"]) > 0) { $explicit = true;      /* the new shape (paid_amount_1 … _5) – paid_amount_2 alone is the old column */ $pay[] = ['amount' => self::num($f["paid_amount_$n"]), 'currency' => $f["paid_currency_$n"] ?? '', 'date' => self::date($f["paid_date_$n"] ?? ''), 'ref' => $f["paid_ref_$n"] ?? null]; }
            if (array_key_exists('freight_cost_1', $f)) for ($n = 1; $n <= 5; $n++) if (isset($f["freight_cost_$n"]) && self::num($f["freight_cost_$n"]) > 0) $freight[] = ['cost' => self::num($f["freight_cost_$n"]), 'currency' => $f["freight_currency_$n"] ?? ''];
            if (! $explicit) foreach ($g as $k => $a) {      /* the old file shape: a repeat of the first row's value is not a second payment */
                foreach ([['paid_amount', 'paid_date'], ['paid_amount_2', 'paid_date_2']] as [$ac, $dc]) { $v = self::num($a[$ac] ?? ''); if ($v <= 0) continue;
                    if ($k > 0 && abs($v - self::num($f[$ac] ?? '')) < 0.005) { $warn[] = "WARN line " . ($k + 1) . " of $ref: $ac $v repeats the first row – possible double count – check (not added)"; continue; }
                    $pay[] = ['amount' => $v, 'currency' => $a['paid_currency'] ?? '', 'date' => self::date($a[$dc] ?? '') ?: self::date($a['paid_date'] ?? '')]; }
            }
            if (! $freight) foreach ($g as $k => $a) { $v = self::num($a['freight_cost'] ?? ''); if ($v <= 0) continue;
                if ($k > 0 && abs($v - self::num($f['freight_cost'] ?? '')) < 0.005) { $warn[] = "WARN line " . ($k + 1) . " of $ref: freight_cost $v repeats the first row – possible double count – check (not added)"; continue; }
                $freight[] = ['cost' => $v, 'currency' => $a['freight_currency'] ?? '']; }
            $e = ['source' => 'CSV', 'supplier_name' => $f['supplier_name'], 'order_date' => self::date($f['order_date'] ?? ''), 'order_ref' => $ref, 'order_ref_note' => $f['order_ref_note'] ?? null, 'currency' => $f['currency'] ?? '',
                'rate' => self::num($f['exchange_rate'] ?? '') ?: null, 'owner_name' => $f['owner_entity'] ?? '', 'brand' => $f['brand'] ?? null, 'notes' => null, 'payments' => $pay,
                'lines' => array_map(fn ($a) => ['line_type' => $a['line_type'] ?? '', 'product_name' => $a['product_name'] ?? '', 'sku' => $a['sku'] ?? '', 'qty' => self::num($a['qty'] ?? ''), 'unit_price' => self::num($a['unit_price'] ?? ''), 'notes' => $a['notes'] ?? null,
                    'qty_accepted' => ($a['qty_accepted'] ?? '') === '' ? null : self::num($a['qty_accepted']), 'qty_damaged' => self::num($a['qty_damaged'] ?? ''), 'qty_missing' => ($a['qty_missing'] ?? '') === '' ? null : self::num($a['qty_missing']), 'batch' => $a['batch'] ?? null], $g),
                'shipping' => ['shipped_date' => self::date($f['shipped_date'] ?? ''), 'arrived_date' => self::date($f['arrived_date'] ?? ''), 'mode' => $f['mode'] ?? null, 'forwarder' => $f['forwarder'] ?? null, 'tracking' => $f['tracking'] ?? null,
                    'freight' => $freight, 'duty' => self::num($f['duty_cost'] ?? ''), 'other' => self::num($f['other_cost'] ?? '')], 'received' => ['date' => self::date($f['received_date'] ?? ''), 'warehouse' => $f['warehouse'] ?? null]];
            $entries[$ref] = ['entry' => $e, 'warn' => $warn, 'rows' => count($g)];
        }
        return ['entries' => $entries, 'log' => $log, 'skipped' => $skipped];
    }

    /** R-B_SOURCING-13 check screen (dry run – never writes): per entry the supplier, date, lines, money, warnings, a duplicate to force with a reason, problems. */
    public static function check(int $ent, string $text, object $u): array
    {
        $p = self::parse($text); $out = [];
        foreach ($p['entries'] as $ref => $x) {
            $e = $x['entry']; $prob = [];
            if (! $e['order_date']) $prob[] = 'order_date must be YYYY-MM-DD or DD Mon YYYY';
            $sup = DB::table('t_src_suppliers')->where('fk_id_ent', $ent)->whereRaw('LOWER(TRIM(supplier_name)) = ?', [mb_strtolower(trim((string) $e['supplier_name']))])->where('f_active', 1)->first();
            $owner = trim((string) $e['owner_name']) !== '' ? DB::table('t_account')->where(fn ($w) => $w->where('account_code', $e['owner_name'])->orWhereRaw("LOWER(TRIM(CONCAT_WS(' ', first_name, sur_name))) = ?", [mb_strtolower(trim((string) $e['owner_name']))]))->value('id') : null;
            if (! $owner && Choices::get('OLD_ENTRY_OWNER_REQUIRED', $ent) === 'Y') $prob[] = 'owner entity ' . ($e['owner_name'] ? "\"{$e['owner_name']}\" not found" : 'missing');
            $dup = $sup ? SrcDuplicate::existing($ent, (int) $sup->id, $ref) : null;
            $out[] = ['ref' => $ref, 'supplier' => $e['supplier_name'], 'new_supplier' => ! $sup, 'date' => $e['order_date'], 'currency' => $e['currency'], 'lines' => count($e['lines']), 'rows' => $x['rows'],
                'total' => round(array_sum(array_map(fn ($l) => $l['qty'] * $l['unit_price'], $e['lines'])), 2), 'paid' => round(array_sum(array_column($e['payments'], 'amount')), 2), 'received' => (bool) $e['received']['date'],
                'warn' => $x['warn'], 'duplicate' => $dup->cycle_code ?? null, 'problems' => $prob];
        }
        return ['entries' => $out, 'log' => $p['log'], 'skipped' => $p['skipped'], 'ok' => count(array_filter($out, fn ($r) => ! $r['problems'] && ! $r['duplicate'])), 'pending_approval' => self::needsApproval($ent, $u)];
    }

    public static function import(int $ent, string $text, object $u, array $force = []): array
    {
        $p = self::parse($text); $log = $p['log']; $created = []; $failed = 0; $pending = 0;
        foreach ($p['entries'] as $ref => $x) {
            $e = $x['entry'] + ['force_reason' => $force[$ref] ?? null]; $warn = $x['warn'];
            if (! $e['order_date']) { $failed++; $log[] = "[$ref] FAILED: order_date must be YYYY-MM-DD or DD Mon YYYY"; continue; }
            try { $r = self::entry($ent, $e, $u); $created[] = $r['cycle']; if ($r['pending']) $pending++; foreach (array_merge($warn, $r['log']) as $l) $log[] = "[$ref] $l"; $log[] = "[$ref] created {$r['code']}"; }
            catch (SrcDuplicateException $d) { $failed++; $log[] = "[$ref] FAILED: " . $d->getMessage(); }
            catch (\Illuminate\Database\QueryException $t) { $failed++; report($t); $log[] = "[$ref] FAILED: Save failed - nothing was created (the details are in the system log)"; }
            catch (\Throwable $t) { $failed++; $log[] = "[$ref] FAILED: " . $t->getMessage(); if (! $t instanceof \InvalidArgumentException && ! $t instanceof \RuntimeException) report($t); }
        }
        $job = $created ? (int) DB::table('bulk_jobs')->insertGetId(['job_no' => 'SH-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5)), 'list_key' => 'src_historical', 'fk_id_account' => $u->id,
            'change_json' => json_encode(['cycles' => $created]), 'row_count' => count($created), 'status' => 'APPLIED', 'created_at' => now(), 'applied_at' => now()]) : null;
        return ['job' => $job, 'created' => count($created), 'pending' => $pending, 'failed' => $failed, 'skipped' => $p['skipped'], 'log' => $log, 'message' => 'Done: ' . count($created) . " entries created ($pending pending approval) · $failed failed"];
    }

    /** Undo a CSV batch: every cycle with all it made, when no later record uses it (a sale took a lot, a bank line matched a payment …). */
    public static function undo(int $jobId, object $u): array
    {
        $j = DB::table('bulk_jobs')->where('id', $jobId)->where('list_key', 'src_historical')->first() ?? throw new \InvalidArgumentException('Batch not found.');
        if ($j->status !== 'APPLIED') throw new \InvalidArgumentException('This batch was already undone.');
        $c = json_decode((string) $j->change_json, true); $gone = 0; $kept = [];
        foreach ($c['cycles'] as $cy) {
            $lots = DB::table('fin_lots')->where('fk_id_cycle', $cy)->pluck('id')->all();
            $used = ($lots && (DB::table('fin_lot_consumption')->whereIn('lot_id', $lots)->exists() || DB::table('fin_lots')->whereIn('id', $lots)->whereColumn('qty_left', '<', 'qty')->exists()))
                || DB::table('t_src_payments')->where('fk_id_cycle', $cy)->whereNotNull('fk_id_bank_line')->exists() || DB::table('t_src_po')->where('fk_id_cycle', $cy)->whereIn('id', DB::table('t_src_po')->whereNotNull('fk_id_repeat_from_po')->select('fk_id_repeat_from_po'))->exists();
            if ($used) { $kept[] = DB::table('t_src_cycles')->where('id', $cy)->value('cycle_code'); continue; }
            DB::transaction(function () use ($cy, $lots) {
                $grns = DB::table('t_src_grn')->where('fk_id_cycle', $cy)->pluck('id')->all(); $pos = DB::table('t_src_po')->where('fk_id_cycle', $cy)->pluck('id')->all();
                foreach (DB::table('gl_journals')->where('source_type', 'GRN')->whereIn('source_id', $grns ?: [0])->whereNull('reverses_id')->whereNull('reversed_by_id')->pluck('id') as $jid) Ledger::reverse((int) $jid, null, 'Historical import undone');
                $prods = DB::table('fin_lots')->whereIn('id', $lots ?: [0])->pluck('fk_id_product')->unique()->all();
                DB::table('t_stock_movements')->whereIn('fk_id_lot', $lots ?: [0])->delete(); DB::table('fin_lot_balances')->whereIn('fk_id_lot', $lots ?: [0])->delete(); DB::table('fin_lots')->whereIn('id', $lots ?: [0])->delete();
                foreach ($prods as $p) StockService::refreshCache((int) $p);
                DB::table('t_src_grn_lines')->whereIn('fk_id_grn', $grns ?: [0])->delete(); DB::table('t_src_grn')->whereIn('id', $grns ?: [0])->delete();
                $ships = DB::table('t_src_shipments')->where('fk_id_cycle', $cy)->pluck('id')->all();
                DB::table('t_src_shipment_po_link')->whereIn('fk_id_shipment', $ships ?: [0])->delete(); DB::table('t_src_shipments')->whereIn('id', $ships ?: [0])->delete();
                foreach (['t_src_freight_costs', 't_src_customs', 't_src_payments', 't_src_payment_terms', 't_src_cartons', 't_src_po_lines', 't_src_cycle_products'] as $t) DB::table($t)->where('fk_id_cycle', $cy)->delete();
                DB::table('t_src_po')->whereIn('id', $pos ?: [0])->delete(); DB::table('t_src_cycles')->where('id', $cy)->delete();
            });
            $gone++;
        }
        DB::table('bulk_jobs')->where('id', $j->id)->update(['status' => $kept ? 'PARTIAL' : 'UNDONE', 'undone_at' => now()]);
        return ['removed' => $gone, 'kept' => $kept, 'message' => "Undone: $gone entr" . ($gone === 1 ? 'y' : 'ies') . ' removed' . ($kept ? ' · kept (used by later records): ' . implode(', ', $kept) : '') . '.'];
    }
}
