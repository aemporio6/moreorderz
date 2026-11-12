<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Orders in the warehouse (RULES_H): reserve at intake → pick list → pick → pack → carrier handover (= ship) → returns.
 * Units move bin → DISPATCH → SHIPPED through the stock service; the lot goes with them (oldest first, by the shop's owner rule).
 * The cost of the sale is booked only at Sales Month Confirm (SalesService), which takes the units out of SHIPPED.
 */
class DispatchService
{
    // ---------------------------------------------------------------- reservations

    /** Reserve stock for a sale line at intake (no bin chosen yet). Short → reservation for what there is + WH_ORDER_SHORT. */
    public static function reserve(int $saleId): ?int
    {
        return DB::transaction(function () use ($saleId) {
            $s = DB::table('t_sales')->where('id', $saleId)->lockForUpdate()->first() ?? throw new \RuntimeException('Sale line not found.');
            if (($s->fulfilment_channel ?: 'SELF') !== 'SELF' || $s->sale_source !== 'active' || ! $s->fk_product_id || $s->status !== 'Pending') return null;
            $ent = (int) $s->fk_id_ent;
            DB::table('t_product_vendors')->where('id', $s->fk_product_id)->lockForUpdate()->first();          // two intakes of the last unit wait here
            $r = DB::table('wh_reservations')->where('fk_id_sale', $saleId)->whereNotIn('status', ['RELEASED', 'SHIPPED'])->first();
            $have = $r ? (int) $r->qty : 0; $need = (int) $s->qty;
            $extra = min(max(0, $need - $have), StockService::available((int) $s->fk_product_id));
            $qty = $have + ($need > $have ? $extra : $need - $have); $short = $need - $qty;
            $owner = self::ownerRule($s);
            if ($r) {
                DB::table('wh_reservations')->where('id', $r->id)->update(['qty' => $qty, 'qty_short' => $short, 'updated_at' => now()]);
                self::hist($r->id, $r->status, $r->status, $have, $qty, 'Quantity changed');
                $rid = (int) $r->id;
            } else {
                $rid = (int) DB::table('wh_reservations')->insertGetId(['fk_id_ent' => $ent, 'fk_id_branch' => $s->fk_id_branch, 'fk_id_site' => (int) ($s->fk_id_sell_location ?: 0), 'fk_id_sale' => $saleId, 'fk_id_product' => $s->fk_product_id,
                    'owner_rule' => $owner ? 'PRIVATE' : 'POOL', 'fk_id_owner_seg' => $owner ?? 0, 'qty' => $qty, 'qty_short' => $short, 'qty_picked' => 0, 'status' => 'OPEN', 'dispatch_by' => $s->dispatch_by, 'created_by' => Context::userId(), 'created_at' => now(), 'updated_at' => now()]);
                self::hist($rid, null, 'OPEN', null, $qty, 'Reserved at intake');
            }
            DB::table('t_sales')->where('id', $saleId)->update(['wh_status' => $short > 0 ? 'SHORT' : 'RESERVED']);
            if ($short > 0) TaskEngine::open('WH_ORDER_SHORT', [$saleId], "Order {$s->order_no}: $short of $need × {$s->sku} not in stock", ['ent' => $ent, 'module' => 't_sales', 'record_id' => $saleId]);
            else TaskEngine::close('WH_ORDER_SHORT', [$saleId], 'Stock reserved');
            EventBus::fire('STOCK_RESERVED', 't_sales', $saleId, ['qty' => $qty, 'short' => $short], $ent);
            return $rid;
        });
    }

    /** After stock arrives: fill short reservations of a product, oldest dispatch-by first. */
    public static function fillShorts(int $productId): int
    {
        $n = 0;
        foreach (DB::table('wh_reservations')->where('fk_id_product', $productId)->where('status', 'OPEN')->where('qty_short', '>', 0)->orderByRaw('dispatch_by IS NULL, dispatch_by')->orderBy('id')->pluck('fk_id_sale') as $sale) {
            self::reserve((int) $sale);
            $n++;
        }
        return $n;
    }

    public static function release(int $saleId, string $reason): void
    {
        DB::transaction(function () use ($saleId, $reason) {
            foreach (DB::table('wh_reservations')->where('fk_id_sale', $saleId)->whereIn('status', ['OPEN', 'ALLOCATED'])->lockForUpdate()->get() as $r) {
                DB::table('wh_pick_task_lines')->where('fk_id_reservation', $r->id)->where('line_status', 'OPEN')->update(['line_status' => 'CANCELLED']);
                DB::table('wh_reservations')->where('id', $r->id)->update(['status' => 'RELEASED', 'release_reason' => mb_substr($reason, 0, 255), 'updated_at' => now()]);
                self::hist($r->id, $r->status, 'RELEASED', $r->qty, $r->qty, $reason);
            }
            if (DB::table('wh_reservations')->where('fk_id_sale', $saleId)->where('status', 'PICKED')->exists()) throw new \RuntimeException('These units are already picked. Put them back first (Move from the Dispatch area to a shelf).');
            DB::table('t_sales')->where('id', $saleId)->update(['wh_status' => 'RELEASED']);
            TaskEngine::close('WH_ORDER_SHORT', [$saleId], 'Released');
        });
    }

    // ---------------------------------------------------------------- pick

    /** Build one pick list from the open reservations: bins in walking order (pick_seq), never more than a bin holds. */
    public static function buildPickList(int $ent, ?array $saleIds = null): ?int
    {
        return DB::transaction(function () use ($ent, $saleIds) {
            $res = DB::table('wh_reservations')->where('fk_id_ent', $ent)->where('status', 'OPEN')->where('qty', '>', 0)->when($saleIds, fn ($q) => $q->whereIn('fk_id_sale', $saleIds))
                ->orderByRaw('dispatch_by IS NULL, dispatch_by')->orderBy('id')->lockForUpdate()->get();
            if ($res->isEmpty()) return null;
            $task = (int) DB::table('wh_pick_tasks')->insertGetId(['fk_id_ent' => $ent, 'task_code' => Numbering::next('PCK', $ent), 'fk_id_site' => (int) ($res[0]->fk_id_site ?: 0), 'task_type' => 'ORDER', 'pick_mode' => (string) Settings::get('WH_PICK_MODE', $ent, 'SINGLE'),
                'status' => 'OPEN', 'created_by' => Context::userId(), 'created_at' => now()]);
            $taken = []; $lines = [];          // units of a bin already promised on this and other open lists
            foreach ($res as $r) {
                $left = (int) $r->qty - (int) $r->qty_picked;
                foreach (self::binsOf((int) $r->fk_id_product, $ent, $r->owner_rule === 'PRIVATE' ? (int) $r->fk_id_owner_seg : null) as $b) {
                    if ($left <= 0) break;
                    $key = $r->fk_id_product . ':' . $b->loc . ':' . ($r->fk_id_owner_seg ?: 0);
                    $taken[$key] ??= (int) DB::table('wh_pick_task_lines as l')->join('wh_reservations as x', 'x.id', '=', 'l.fk_id_reservation')->where('l.fk_id_product', $r->fk_id_product)->where('l.fk_id_from_location', $b->loc)->where('l.line_status', 'OPEN')
                        ->where(fn ($w) => $r->owner_rule === 'PRIVATE' ? $w->where('x.fk_id_owner_seg', $r->fk_id_owner_seg) : $w->where('x.owner_rule', 'POOL'))->sum(DB::raw('l.qty - COALESCE(l.qty_done,0)'));
                    $free = (int) $b->qty - $taken[$key];
                    if ($free <= 0) continue;
                    $take = min($left, $free);
                    $lines[] = ['seq' => (int) $b->pick_seq, 'row' => ['fk_id_pick_task' => $task, 'fk_id_product' => $r->fk_id_product, 'fk_id_from_location' => $b->loc, 'fk_id_reservation' => $r->id, 'fk_id_sale' => $r->fk_id_sale, 'qty' => $take, 'qty_done' => 0, 'qty_short' => 0, 'line_status' => 'OPEN']];
                    $taken[$key] += $take; $left -= $take;
                }
                DB::table('wh_reservations')->where('id', $r->id)->update(['status' => 'ALLOCATED', 'updated_at' => now()]);
                self::hist($r->id, 'OPEN', 'ALLOCATED', $r->qty, $r->qty, 'On pick list');
                DB::table('t_sales')->where('id', $r->fk_id_sale)->update(['wh_status' => 'PICKING']);
            }
            usort($lines, fn ($a, $b) => $a['seq'] <=> $b['seq']);
            foreach ($lines as $i => $l) DB::table('wh_pick_task_lines')->insert($l['row'] + ['line_no' => $i + 1]);
            EventBus::fire('PICK_LIST_BUILT', 'wh_pick_tasks', $task, ['lines' => count($lines)], $ent);
            return $task;
        });
    }

    /** Confirm one pick line. $qty less than the line = short pick: the rest goes back to the queue and the bin gets a count task. */
    public static function pick(int $lineId, ?int $qty = null): array
    {
        return DB::transaction(function () use ($lineId, $qty) {
            $l = DB::table('wh_pick_task_lines')->where('id', $lineId)->lockForUpdate()->first() ?? throw new \RuntimeException('Pick line not found.');
            if ($l->line_status !== 'OPEN') throw new \RuntimeException('This line is already ' . strtolower($l->line_status) . '.');
            $task = DB::table('wh_pick_tasks')->where('id', $l->fk_id_pick_task)->first();
            $ent = (int) $task->fk_id_ent; $qty ??= (int) $l->qty;
            if ($qty < 0 || $qty > $l->qty) throw new \InvalidArgumentException("Pick between 0 and {$l->qty}.");
            $r = DB::table('wh_reservations')->where('id', $l->fk_id_reservation)->lockForUpdate()->first();
            if ($qty > 0) {
                StockService::transfer($ent, (int) $l->fk_id_product, $qty, (int) $l->fk_id_from_location, StockService::systemLocation($ent, 'DISPATCH'), 'Pick ' . $task->task_code,
                    ['rule' => $r->owner_rule === 'PRIVATE' ? 'PRIVATE' : 'POOL', 'owner' => $r->fk_id_owner_seg ?: null, 'source_type' => 'PICK', 'source_id' => $l->fk_id_sale]);
            }
            $short = (int) $l->qty - $qty;
            DB::table('wh_pick_task_lines')->where('id', $lineId)->update(['qty_done' => $qty, 'qty_short' => $short, 'line_status' => $short > 0 ? 'SHORT' : 'DONE', 'done_by' => Context::userId(), 'done_at' => now(), 'fk_id_to_location' => StockService::systemLocation($ent, 'DISPATCH')]);
            $picked = (int) $r->qty_picked + $qty;
            $openLines = DB::table('wh_pick_task_lines')->where('fk_id_reservation', $r->id)->where('line_status', 'OPEN')->exists();
            $new = $picked >= $r->qty ? 'PICKED' : ($openLines ? 'ALLOCATED' : 'OPEN');          // a short pick puts the rest back in the queue
            DB::table('wh_reservations')->where('id', $r->id)->update(['qty_picked' => $picked, 'status' => $new, 'updated_at' => now()]);
            if ($new !== $r->status) self::hist($r->id, $r->status, $new, $r->qty, $r->qty, $short ? 'Short pick' : 'Picked');
            if ($new === 'PICKED') DB::table('t_sales')->where('id', $r->fk_id_sale)->update(['wh_status' => 'PICKED']);
            DB::table('wh_pick_tasks')->where('id', $task->id)->update(['status' => DB::table('wh_pick_task_lines')->where('fk_id_pick_task', $task->id)->where('line_status', 'OPEN')->exists() ? 'IN_PROGRESS' : 'DONE',
                'started_at' => $task->started_at ?: now(), 'last_activity_at' => now(), 'finished_at' => DB::table('wh_pick_task_lines')->where('fk_id_pick_task', $task->id)->where('line_status', 'OPEN')->exists() ? null : now(), 'assigned_to' => $task->assigned_to ?: Context::userId()]);
            if ($short > 0) {
                $sku = DB::table('t_product_vendors')->where('id', $l->fk_id_product)->value('SKU_VENDOR'); $bin = DB::table('cat_inventory_locations')->where('id', $l->fk_id_from_location)->value('location_name');
                TaskEngine::open('WH_SHORT_PICK', [$l->fk_id_from_location, $sku], "Short pick at $bin: $short × $sku missing – count the bin", ['ent' => $ent, 'module' => 'cat_inventory_locations', 'record_id' => $l->fk_id_from_location]);
                EventBus::fire('SHORT_PICK', 'wh_pick_task_lines', $lineId, ['short' => $short, 'bin' => $l->fk_id_from_location], $ent);
            }
            EventBus::fire('PICK_CONFIRMED', 'wh_pick_task_lines', $lineId, ['qty' => $qty], $ent);
            return ['picked' => $qty, 'short' => $short, 'reservation' => $new];
        });
    }

    // ---------------------------------------------------------------- pack

    /** Pack an order into one parcel: every line of the order must be fully picked. $d: order_no, branch, carrier?, box?, weight_g?. */
    public static function pack(array $d): int
    {
        return DB::transaction(function () use ($d) {
            $ent = (int) Context::ent();
            $lines = DB::table('t_sales')->where('fk_id_ent', $ent)->where('order_no', $d['order_no'])->where('fk_id_branch', $d['branch'])->where('status', 'Pending')->where('sale_source', 'active')->lockForUpdate()->get();
            if ($lines->isEmpty()) throw new \RuntimeException('No open order lines found for this order.');
            foreach ($lines as $s) {
                $r = DB::table('wh_reservations')->where('fk_id_sale', $s->id)->whereNotIn('status', ['RELEASED'])->first();
                if (! $r || $r->status !== 'PICKED') throw new \RuntimeException("Order {$s->order_no}: {$s->sku} is not fully picked yet.");
                if (DB::table('wh_parcel_lines as pl')->join('wh_parcels as p', 'p.id', '=', 'pl.fk_id_parcel')->where('pl.fk_id_sale', $s->id)->whereNotIn('p.status', ['VOID', 'CANCELLED'])->exists()) throw new \RuntimeException("Order {$s->order_no} is already packed.");
            }
            $box = ! empty($d['box']) ? DB::table('wh_box_sizes')->where('id', $d['box'])->where('fk_id_ent', $ent)->first() : null;
            $carrier = $d['carrier'] ?? DB::table('t_branches')->where('id', $d['branch'])->value('fk_id_default_carrier');
            $pid = (int) DB::table('wh_parcels')->insertGetId(['fk_id_ent' => $ent, 'parcel_code' => Numbering::next('PRC', $ent), 'fk_id_branch' => $d['branch'], 'order_no' => $d['order_no'], 'fk_id_site' => (int) ($lines[0]->fk_id_sell_location ?: 0), 'fk_id_carrier' => $carrier,
                'fk_id_box' => $box->id ?? null, 'weight_g' => $d['weight_g'] ?? null, 'length_cm' => $box ? $box->inner_l_mm / 10 : null, 'width_cm' => $box ? $box->inner_w_mm / 10 : null, 'height_cm' => $box ? $box->inner_h_mm / 10 : null,
                'tracking_no' => $d['tracking_no'] ?? null, 'label_status' => ! empty($d['tracking_no']) ? 'READY' : 'NONE', 'status' => 'READY', 'packed_by' => Context::userId(), 'packed_at' => now(), 'created_at' => now()]);
            foreach ($lines as $s) {
                DB::table('wh_parcel_lines')->insert(['fk_id_parcel' => $pid, 'fk_id_sale' => $s->id, 'fk_id_product' => $s->fk_product_id, 'qty' => $s->qty, 'qty_packed' => $s->qty]);
                DB::table('t_sales')->where('id', $s->id)->update(['wh_status' => 'PACKED', 'tracking_no' => $d['tracking_no'] ?? $s->tracking_no]);
            }
            EventBus::fire('PARCEL_PACKED', 'wh_parcels', $pid, ['order' => $d['order_no']], $ent);
            EventBus::fire('PARCEL_READY', 'wh_parcels', $pid, [], $ent);
            return $pid;
        });
    }

    // ---------------------------------------------------------------- handover = ship

    public static function openHandover(int $carrierId, string $mode = 'COLLECTION'): int
    {
        $ent = (int) Context::ent();
        DB::table('t_carriers')->where('id', $carrierId)->where('fk_id_ent', $ent)->first() ?? throw new \RuntimeException('Carrier not found.');
        if ($open = DB::table('wh_handover_sessions')->where('fk_id_ent', $ent)->where('fk_id_carrier', $carrierId)->where('status', 'OPEN')->value('id')) return (int) $open;      // one open session per carrier
        $exp = DB::table('wh_parcels')->where('fk_id_ent', $ent)->where('fk_id_carrier', $carrierId)->where('status', 'READY')->count();
        return (int) DB::table('wh_handover_sessions')->insertGetId(['fk_id_ent' => $ent, 'session_code' => Numbering::next('HOV', $ent), 'fk_id_site' => 0, 'fk_id_carrier' => $carrierId, 'handover_mode' => $mode, 'status' => 'OPEN',
            'expected_count' => $exp, 'scanned_count' => 0, 'exception_count' => 0, 'opened_by' => Context::userId() ?? 0, 'opened_at' => now()]);
    }

    /** One scan in a handover: OK / DUPLICATE / WRONG_CARRIER / NOT_READY / UNKNOWN. The same scan sent twice (poor Wi-Fi) counts once. */
    public static function scan(int $sessionId, string $code, ?string $opId = null): array
    {
        return DB::transaction(function () use ($sessionId, $code, $opId) {
            $s = DB::table('wh_handover_sessions')->where('id', $sessionId)->where('fk_id_ent', Context::ent())->lockForUpdate()->first() ?? throw new \RuntimeException('Handover session not found.');
            if ($s->status !== 'OPEN') throw new \RuntimeException('This handover is closed.');
            if ($opId && ($again = DB::table('wh_handover_scans')->where('fk_id_handover_session', $sessionId)->where('client_op_id', $opId)->first())) return ['result' => $again->result, 'repeat' => true];
            $code = trim($code);
            $p = DB::table('wh_parcels')->where('fk_id_ent', $s->fk_id_ent)->where(fn ($w) => $w->where('parcel_code', $code)->orWhere('tracking_no', $code))->first();
            $result = ! $p ? 'UNKNOWN' : (DB::table('wh_handover_scans')->where('fk_id_handover_session', $sessionId)->where('fk_id_parcel', $p->id)->where('result', 'OK')->exists() ? 'DUPLICATE'
                : ((int) $p->fk_id_carrier !== (int) $s->fk_id_carrier ? 'WRONG_CARRIER' : ($p->status !== 'READY' ? 'NOT_READY' : 'OK')));
            DB::table('wh_handover_scans')->insert(['fk_id_handover_session' => $sessionId, 'scanned_code' => mb_substr($code, 0, 100), 'fk_id_parcel' => $p->id ?? null, 'result' => $result, 'f_resolved' => $result === 'OK' ? 1 : 0, 'client_op_id' => $opId, 'scanned_by' => Context::userId() ?? 0, 'scanned_at' => now()]);
            if ($result === 'OK') DB::table('wh_handover_sessions')->where('id', $sessionId)->increment('scanned_count');
            elseif ($result !== 'DUPLICATE') DB::table('wh_handover_sessions')->where('id', $sessionId)->increment('exception_count');
            $msg = ['OK' => 'OK', 'DUPLICATE' => 'Already scanned', 'WRONG_CARRIER' => 'This parcel is for another carrier – keep it back', 'NOT_READY' => 'Not ready: ' . strtolower($p->status ?? ''), 'UNKNOWN' => 'No parcel matches this code'][$result];
            $x = DB::table('wh_handover_sessions')->where('id', $sessionId)->first();
            return ['result' => $result, 'message' => $msg, 'scanned' => (int) $x->scanned_count, 'expected' => (int) $x->expected_count];
        });
    }

    /** Close = ship: scanned parcels leave (DISPATCH → SHIPPED, reservation SHIPPED, qty_shipped set). Not scanned parcels stay Ready. */
    public static function closeHandover(int $sessionId, ?string $driver = null): array
    {
        return DB::transaction(function () use ($sessionId, $driver) {
            $s = DB::table('wh_handover_sessions')->where('id', $sessionId)->where('fk_id_ent', Context::ent())->lockForUpdate()->first() ?? throw new \RuntimeException('Handover session not found.');
            if ($s->status !== 'OPEN') throw new \RuntimeException('This handover is already closed.');
            $ent = (int) $s->fk_id_ent; $from = StockService::systemLocation($ent, 'DISPATCH'); $to = StockService::systemLocation($ent, 'SHIPPED'); $n = 0;
            foreach (DB::table('wh_handover_scans')->where('fk_id_handover_session', $sessionId)->where('result', 'OK')->pluck('fk_id_parcel') as $pid) {
                foreach (DB::table('wh_parcel_lines')->where('fk_id_parcel', $pid)->get() as $l) {
                    $r = DB::table('wh_reservations')->where('fk_id_sale', $l->fk_id_sale)->where('status', 'PICKED')->lockForUpdate()->first() ?? throw new \RuntimeException('A scanned parcel has units that were never picked.');
                    StockService::transfer($ent, (int) $l->fk_id_product, (int) $l->qty_packed, $from, $to, 'Handover ' . $s->session_code, ['rule' => $r->owner_rule === 'PRIVATE' ? 'PRIVATE' : 'POOL', 'owner' => $r->fk_id_owner_seg ?: null, 'source_type' => 'SHIP', 'source_id' => $l->fk_id_sale]);
                    DB::table('wh_reservations')->where('id', $r->id)->update(['status' => 'SHIPPED', 'updated_at' => now()]);
                    self::hist($r->id, 'PICKED', 'SHIPPED', $r->qty, $r->qty, 'Handed over ' . $s->session_code);
                    DB::table('t_sales')->where('id', $l->fk_id_sale)->update(['qty_shipped' => DB::raw('COALESCE(qty_shipped,0) + ' . (int) $l->qty_packed), 'wh_status' => 'SHIPPED']);
                }
                DB::table('wh_parcels')->where('id', $pid)->update(['status' => 'HANDED_OVER', 'fk_id_handover_session' => $sessionId, 'handed_over_at' => now()]);
                EventBus::fire('PARCEL_HANDED_OVER', 'wh_parcels', (int) $pid, ['session' => $sessionId], $ent);
                $n++;
            }
            $left = DB::table('wh_parcels')->where('fk_id_ent', $ent)->where('fk_id_carrier', $s->fk_id_carrier)->where('status', 'READY')->count();
            $exc = DB::table('wh_handover_scans')->where('fk_id_handover_session', $sessionId)->whereIn('result', ['UNKNOWN', 'WRONG_CARRIER', 'NOT_READY'])->where('f_resolved', 0)->count();
            DB::table('wh_handover_sessions')->where('id', $sessionId)->update(['status' => 'CLOSED', 'closed_by' => Context::userId(), 'closed_at' => now(), 'driver_name' => $driver]);
            if ($exc) TaskEngine::open('WH_HANDOVER_EXCEPTION', [$sessionId], "Handover {$s->session_code}: $exc scan(s) to resolve", ['ent' => $ent, 'module' => 'wh_handover_sessions', 'record_id' => $sessionId]);
            EventBus::fire('HANDOVER_CLOSED', 'wh_handover_sessions', $sessionId, ['shipped' => $n, 'left_ready' => $left, 'exceptions' => $exc], $ent);
            return ['shipped' => $n, 'left_ready' => $left, 'exceptions' => $exc];
        });
    }

    // ---------------------------------------------------------------- returns

    /**
     * Book a return as received. Before Month Confirm the units simply come back from SHIPPED to the restock place;
     * after Confirm they go back into their lot and the cost of sale is reversed (stock service).
     * $d: code (order no / tracking / parcel code) or sale, qty, condition SELLABLE / DAMAGED / QUARANTINE / MISSING, to_location?, note?.
     */
    public static function receiveReturn(array $d): array
    {
        return DB::transaction(function () use ($d) {
            $ent = (int) Context::ent(); $code = trim((string) ($d['code'] ?? ''));
            $sale = ! empty($d['sale']) ? DB::table('t_sales')->where('id', $d['sale'])->where('fk_id_ent', $ent)->lockForUpdate()->first()
                : DB::table('t_sales')->where('fk_id_ent', $ent)->where(fn ($w) => $w->where('order_no', $code)->orWhere('tracking_no', $code))->when(! empty($d['sku']), fn ($q) => $q->where('sku', $d['sku']))->orderByDesc('id')->lockForUpdate()->first();
            $intake = (int) DB::table('wh_return_intakes')->insertGetId(['fk_id_ent' => $ent, 'intake_code' => Numbering::next('RTI', $ent), 'fk_id_site' => 0, 'scanned_code' => mb_substr($code ?: ('sale ' . ($d['sale'] ?? '')), 0, 100), 'resolved_as' => $sale ? 'ORDER' : 'UNMATCHED',
                'fk_id_branch' => $sale->fk_id_branch ?? null, 'fk_id_sale' => $sale->id ?? null, 'fk_id_product' => $sale->fk_product_id ?? null, 'qty' => $d['qty'] ?? 1, 'grade' => $d['condition'] ?? 'SELLABLE', 'status' => $sale ? 'BOOKED' : 'UNMATCHED', 'note' => $d['note'] ?? null,
                'client_op_id' => $d['op_id'] ?? null, 'received_by' => Context::userId() ?? 0, 'received_at' => now()]);
            EventBus::fire('RETURN_SCANNED', 'wh_return_intakes', $intake, ['matched' => (bool) $sale], $ent);
            if (! $sale) {
                TaskEngine::open('WH_RETURN_UNMATCHED', [$intake], "Return without an order: scanned “{$code}”", ['ent' => $ent, 'module' => 'wh_return_intakes', 'record_id' => $intake]);
                return ['intake' => $intake, 'matched' => false];
            }
            $qty = (int) ($d['qty'] ?? 1); $cond = strtoupper($d['condition'] ?? 'SELLABLE');
            if ($qty <= 0) throw new \InvalidArgumentException('Quantity must be more than zero.');
            $before = $sale->status === 'Pending';
            $kinds = ['SELLABLE' => null, 'DAMAGED' => 'DAMAGED', 'QUARANTINE' => 'QUARANTINE', 'MISSING' => 'WRITTEN_OFF'];
            $kind = array_key_exists($cond, $kinds) ? $kinds[$cond] : 'RETURNS';
            $to = $d['to_location'] ?? ($kind ? StockService::systemLocation($ent, $kind) : (int) ($sale->fk_id_sell_location ?: StockService::systemLocation($ent, 'RETURNS')));
            if ($before) {
                $out = (int) $sale->qty_shipped - (int) $sale->qty_returned_unconfirmed;
                if ($qty > $out) throw new \RuntimeException("Only $out unit(s) of this line were shipped and are still out.");
                if ($cond !== 'MISSING') {
                    $r = DB::table('wh_reservations')->where('fk_id_sale', $sale->id)->orderByDesc('id')->first();
                    StockService::transfer($ent, (int) $sale->fk_product_id, $qty, StockService::systemLocation($ent, 'SHIPPED'), (int) $to, "Return $cond before confirm", ['rule' => ($r->owner_rule ?? 'POOL') === 'PRIVATE' ? 'PRIVATE' : 'POOL', 'owner' => $r->fk_id_owner_seg ?? null, 'source_type' => 'RETURN', 'source_id' => $sale->id]);
                    DB::table('t_sales')->where('id', $sale->id)->update(['qty_returned_unconfirmed' => DB::raw('COALESCE(qty_returned_unconfirmed,0) + ' . $qty)]);
                }
                $back = 0.0;
            } else {
                $back = StockService::returnIn($ent, (int) $sale->id, $qty, $cond, $d['to_location'] ?? null)['cost_back'];
            }
            $ret = (int) DB::table('t_sale_returns')->insertGetId(['fk_id_ent' => $ent, 'fk_id_sale' => $sale->id, 'condition_code' => $cond, 'qty' => $qty, 'd_received' => date('Y-m-d'), 'restock_to' => $kind ?: 'STOCK', 'note' => $d['note'] ?? null,
                'created_by' => Context::userId(), 'created_at' => now(), 'fk_id_return_intake' => $intake, 'grade' => $cond, 'fk_id_restock_location' => $to, 'f_before_confirm' => $before ? 1 : 0, 'refund_review' => 'OPEN', 'received_by' => Context::userId()]);
            TaskEngine::open('WH_RETURN_REFUND_REVIEW', [$ret], "Return received for order {$sale->order_no} ($qty × {$sale->sku}, $cond) – review the refund", ['ent' => $ent, 'module' => 't_sales', 'record_id' => $sale->id]);
            EventBus::fire('RETURN_RECEIVED', 't_sale_returns', $ret, ['sale' => $sale->id, 'qty' => $qty, 'condition' => $cond, 'before_confirm' => $before], $ent);
            if ($cond === 'SELLABLE') self::fillShorts((int) $sale->fk_product_id);
            return ['intake' => $intake, 'matched' => true, 'return' => $ret, 'before_confirm' => $before, 'cost_back' => $back];
        });
    }

    // ---------------------------------------------------------------- reads and helpers

    /** Orders board: open order lines with their warehouse status. */
    public static function board(int $ent): array
    {
        return DB::table('t_sales as s')->leftJoin('wh_reservations as r', fn ($j) => $j->on('r.fk_id_sale', '=', 's.id')->whereNotIn('r.status', ['RELEASED']))->leftJoin('t_branches as b', 'b.id', '=', 's.fk_id_branch')
            ->where('s.fk_id_ent', $ent)->where('s.status', 'Pending')->where('s.sale_source', 'active')->whereRaw("COALESCE(s.wh_status,'') NOT IN ('SHIPPED','RELEASED')")->orderByRaw('s.dispatch_by IS NULL, s.dispatch_by')->orderBy('s.id')->limit(500)
            ->get(['s.id', 's.order_no', 's.d_order', 's.sku', 's.qty', 's.wh_status', 's.fk_id_branch', 'b.shop_name as shop', 's.ship_to_name', 's.dispatch_by', 'r.status as reservation', 'r.qty as reserved', 'r.qty_short', 'r.qty_picked'])->all();
    }

    /** Sellable bins of a product in walking order with the units a rule may take (oldest lot first inside the stock service). */
    private static function binsOf(int $productId, int $ent, ?int $privateOwner): array
    {
        return DB::table('fin_lot_balances as b')->join('fin_lots as l', 'l.id', '=', 'b.fk_id_lot')->join('cat_inventory_locations as c', 'c.id', '=', 'b.fk_id_location')
            ->where('l.fk_id_product', $productId)->where('l.company_id', $ent)->where('b.qty', '>', 0)
            ->where(fn ($w) => $w->whereNull('c.kind')->orWhereNotIn('c.kind', ['DAMAGED', 'RETURNS', 'PRODUCTION', 'TRANSIT', 'WRITTEN_OFF', 'QUARANTINE', 'MISSING', 'SUPPLIER', 'CUSTOMER', 'SHIPPED', 'DISPATCH', 'RECEIVING']))
            ->where(fn ($w) => $w->whereNull('c.f_sellable')->orWhere('c.f_sellable', 1))
            ->where(fn ($w) => $privateOwner ? $w->where('l.fk_id_owner', $privateOwner) : $w->where('l.visibility', 'POOL')->orWhereNull('l.fk_id_owner'))
            ->groupBy('b.fk_id_location', 'c.pick_seq')->orderByRaw('c.pick_seq IS NULL, c.pick_seq')->orderBy('b.fk_id_location')
            ->get(['b.fk_id_location as loc', DB::raw('COALESCE(c.pick_seq, 999999) as pick_seq'), DB::raw('SUM(b.qty) as qty')])->all();
    }

    /** Private product → the lots of its owner; else pool. (Owner of the product's newest private lot, or the shop owner when it owns lots of it.) */
    private static function ownerRule(object $sale): ?int
    {
        $type = DB::table('t_product_vendors')->where('id', $sale->fk_product_id)->value('e_product_type');
        if ($type !== 'private') return null;
        $o = DB::table('fin_lots')->where('fk_id_product', $sale->fk_product_id)->where('visibility', 'PRIVATE')->whereNotNull('fk_id_owner')->where('qty_left', '>', 0)->orderBy('received_date')->orderBy('id')->value('fk_id_owner');
        return $o ? (int) $o : null;
    }

    private static function hist(int $rid, ?string $old, string $new, ?int $oq, ?int $nq, string $why): void
    {
        DB::table('wh_reservation_history')->insert(['fk_id_reservation' => $rid, 'old_status' => $old, 'new_status' => $new, 'old_qty' => $oq, 'new_qty' => $nq, 'reason' => mb_substr($why, 0, 255), 'changed_by' => Context::userId(), 'changed_at' => now()]);
    }
}
