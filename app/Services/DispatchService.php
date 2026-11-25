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
            if ($comps = Bundles::of($s)) return Bundles::reserve($s, $comps);      /* R-H-19 a bundle reserves each component */
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

    public static function release(int $saleId, string $reason): ?string
    {
        return DB::transaction(function () use ($saleId, $reason) {
            $picked = DB::table('wh_reservations')->where('fk_id_sale', $saleId)->where('status', 'PICKED')->lockForUpdate()->get();
            if ($picked->isNotEmpty() && self::inParcel($saleId)) throw new \RuntimeException('This order is packed. Void its label / parcel first, then cancel (the units go back to picked).');
            foreach (DB::table('wh_reservations')->where('fk_id_sale', $saleId)->whereIn('status', ['OPEN', 'ALLOCATED'])->lockForUpdate()->get() as $r) {
                DB::table('wh_pick_task_lines')->where('fk_id_reservation', $r->id)->where('line_status', 'OPEN')->update(['line_status' => 'CANCELLED']);
                DB::table('wh_reservations')->where('id', $r->id)->update(['status' => 'RELEASED', 'release_reason' => mb_substr($reason, 0, 255), 'updated_at' => now()]);
                self::hist($r->id, $r->status, 'RELEASED', $r->qty, $r->qty, $reason);
            }
            TaskEngine::close('WH_ORDER_SHORT', [$saleId], 'Released');
            if ($picked->isNotEmpty()) {      /* R-H-17 cancel after pick: the units wait in DISPATCH for a PUTBACK to the home bin; the reservation is released when they are back */
                foreach ($picked as $r) { DB::table('wh_reservations')->where('id', $r->id)->update(['release_reason' => mb_substr($reason, 0, 255), 'updated_at' => now()]); self::hist($r->id, 'PICKED', 'PICKED', $r->qty, $r->qty, "Cancelled after pick – put back: $reason"); }
                $s = DB::table('t_sales')->where('id', $saleId)->first(['fk_id_ent', 'order_no', 'sku']);
                DB::table('t_sales')->where('id', $saleId)->update(['wh_status' => 'PUTBACK']);
                TaskEngine::open('WH_PUTBACK', [$saleId], "Put back {$picked->sum('qty_picked')} × {$s->sku} of cancelled order {$s->order_no} from the Dispatch area to its bin", ['ent' => (int) $s->fk_id_ent, 'module' => 't_sales', 'record_id' => $saleId, 'fix_url' => '#/orders']);
                return 'PUTBACK';
            }
            DB::table('t_sales')->where('id', $saleId)->update(['wh_status' => 'RELEASED']);
            return 'RELEASED';
        });
    }

    /** R-H-17: put the picked units of a cancelled line back (DISPATCH → the bin, default the first sellable bin of the product); then the reservation is RELEASED and available rises. */
    public static function putback(int $saleId, ?int $bin = null): array
    {
        return DB::transaction(function () use ($saleId, $bin) {
            $s = DB::table('t_sales')->where('id', $saleId)->where('fk_id_ent', Context::ent())->lockForUpdate()->first() ?? throw new \RuntimeException('Order line not found.');
            if ($s->wh_status !== 'PUTBACK') throw new \RuntimeException('Nothing to put back for this line.');
            $ent = (int) $s->fk_id_ent; $from = StockService::systemLocation($ent, 'DISPATCH'); $n = 0;
            foreach (DB::table('wh_reservations')->where('fk_id_sale', $saleId)->where('status', 'PICKED')->lockForUpdate()->get() as $r) {
                $to = $bin ?: (int) (self::binsOf((int) $r->fk_id_product, $ent, null)[0]->loc ?? DB::table('t_branches')->where('id', $s->fk_id_branch)->value('fk_id_main_location') ?? 0);
                if (! $to) throw new \RuntimeException('Choose the bin to put the units back in.');
                if ((int) $r->qty_picked > 0) StockService::transfer($ent, (int) $r->fk_id_product, (int) $r->qty_picked, $from, $to, "Putback cancelled order {$s->order_no}", ['rule' => $r->owner_rule === 'PRIVATE' ? 'PRIVATE' : 'POOL', 'owner' => $r->fk_id_owner_seg ?: null, 'source_type' => 'PUTBACK', 'source_id' => $saleId]);
                DB::table('wh_reservations')->where('id', $r->id)->update(['status' => 'RELEASED', 'updated_at' => now()]);
                self::hist($r->id, 'PICKED', 'RELEASED', $r->qty, $r->qty, 'Put back to bin ' . DB::table('cat_inventory_locations')->where('id', $to)->value('location_name'));
                $n += (int) $r->qty_picked;
            }
            DB::table('t_sales')->where('id', $saleId)->update(['wh_status' => 'RELEASED']);
            TaskEngine::close('WH_PUTBACK', [$saleId], 'Units put back');
            EventBus::fire('PUTBACK_DONE', 't_sales', $saleId, ['units' => $n], $ent);
            return ['units' => $n, 'message' => "$n unit(s) put back – the stock is free again."];
        });
    }

    /** R-H-35: the carrier took fewer than scanned – within WH_HANDOVER_REOPEN_MINUTES a parcel can be un-scanned with a reason (back to Ready, units SHIPPED → DISPATCH); later it is a lost-parcel case. */
    public static function unscan(int $sessionId, int $parcelId, string $reason): array
    {
        if (mb_strlen(trim($reason)) < 3) throw new \InvalidArgumentException('Give the reason for the un-scan.');
        return DB::transaction(function () use ($sessionId, $parcelId, $reason) {
            $s = DB::table('wh_handover_sessions')->where('id', $sessionId)->where('fk_id_ent', Context::ent())->lockForUpdate()->first() ?? throw new \RuntimeException('Handover session not found.');
            $ent = (int) $s->fk_id_ent; $min = (int) Settings::get('WH_HANDOVER_REOPEN_MINUTES', $ent, 60);
            if ($s->status !== 'CLOSED') throw new \RuntimeException('The handover is still open – just do not close it with this parcel, or scan it again.');
            if (! $s->closed_at || strtotime((string) $s->closed_at) < time() - $min * 60) throw new \RuntimeException("The handover was closed more than $min minutes ago – report the parcel as lost instead.");
            $p = DB::table('wh_parcels')->where('id', $parcelId)->where('fk_id_handover_session', $sessionId)->where('status', 'HANDED_OVER')->lockForUpdate()->first() ?? throw new \RuntimeException('This parcel was not handed over in this session.');
            $from = StockService::systemLocation($ent, 'SHIPPED'); $to = StockService::systemLocation($ent, 'DISPATCH');
            foreach (DB::table('wh_parcel_lines')->where('fk_id_parcel', $parcelId)->get() as $l) {
                $r = DB::table('wh_reservations')->where('fk_id_sale', $l->fk_id_sale)->where('status', 'SHIPPED')->lockForUpdate()->first();
                StockService::transfer($ent, (int) $l->fk_id_product, (int) $l->qty_packed, $from, $to, "Un-scan {$p->parcel_code}: $reason", ['rule' => ($r->owner_rule ?? 'POOL') === 'PRIVATE' ? 'PRIVATE' : 'POOL', 'owner' => $r->fk_id_owner_seg ?? null ?: null, 'source_type' => 'HANDOVER', 'source_id' => $sessionId]);
                if ($r) { DB::table('wh_reservations')->where('id', $r->id)->update(['status' => 'PICKED', 'updated_at' => now()]); self::hist($r->id, 'SHIPPED', 'PICKED', $r->qty, $r->qty, "Un-scanned from {$s->session_code}: $reason"); }
                DB::table('t_sales')->where('id', $l->fk_id_sale)->update(['qty_shipped' => DB::raw('GREATEST(COALESCE(qty_shipped,0) - ' . (int) $l->qty_packed . ', 0)'), 'wh_status' => 'PACKED']);
            }
            DB::table('wh_parcels')->where('id', $parcelId)->update(['status' => 'READY', 'fk_id_handover_session' => null, 'handed_over_at' => null]);
            DB::table('wh_handover_scans')->where('fk_id_handover_session', $sessionId)->where('fk_id_parcel', $parcelId)->where('result', 'OK')->update(['result' => 'UNSCANNED', 'resolve_note' => mb_substr($reason, 0, 255)]);
            DB::table('wh_handover_sessions')->where('id', $sessionId)->update(['scanned_count' => DB::raw('GREATEST(COALESCE(scanned_count,0) - 1, 0)')]);
            Audit::log('wh_parcels', $parcelId, 'UPDATE', ['status' => ['HANDED_OVER', 'READY'], 'session' => $s->session_code], $reason, $ent);
            EventBus::fire('HANDOVER_UNSCANNED', 'wh_parcels', $parcelId, ['session' => $sessionId, 'reason' => $reason], $ent);
            return ['message' => "Parcel {$p->parcel_code} is Ready again – the next handover takes it."];
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
                CountTriggers::trigger($ent, (int) $l->fk_id_from_location, (int) $l->fk_id_product, 'SHORT_PICK');      // R-H-47 (1)
            }
            $ask = $qty > 0 && Settings::get('WH_COUNT_ON_ZERO', $ent, 'Y') === 'Y' && CountTriggers::inBin((int) $l->fk_id_product, (int) $l->fk_id_from_location) === 0      // R-H-47 (2): the pick emptied the bin
                ? ['bin' => (int) $l->fk_id_from_location, 'name' => DB::table('cat_inventory_locations')->where('id', $l->fk_id_from_location)->value('location_name'), 'product' => (int) $l->fk_id_product] : null;
            EventBus::fire('PICK_CONFIRMED', 'wh_pick_task_lines', $lineId, ['qty' => $qty], $ent);
            return ['picked' => $qty, 'short' => $short, 'reservation' => $new, 'ask_empty' => $ask];
        });
    }

    // ---------------------------------------------------------------- pack

    /** Pack an order into one parcel: every line of the order must be fully picked. $d: order_no, branch, carrier?, box?, weight_g?. */
    /** Is the sale line in a live parcel? A lost parcel (claim case) does not count once a replacement (RESEND) is reserved. */
    private static function inParcel(int $saleId): bool
    {
        $resend = DB::table('wh_reservations')->where('fk_id_sale', $saleId)->where('reason', 'RESEND')->whereNotIn('status', ['RELEASED', 'SHIPPED'])->exists();
        return DB::table('wh_parcel_lines as pl')->join('wh_parcels as p', 'p.id', '=', 'pl.fk_id_parcel')->where('pl.fk_id_sale', $saleId)->whereNotIn('p.status', ['VOID', 'CANCELLED'])
            ->when($resend, fn ($q) => $q->whereNull('p.claim_status'))->exists();
    }

    public static function pack(array $d): int
    {
        return DB::transaction(function () use ($d) {
            $ent = (int) Context::ent();
            $lines = DB::table('t_sales')->where('fk_id_ent', $ent)->where('order_no', $d['order_no'])->where('fk_id_branch', $d['branch'])->where('status', 'Pending')->where('sale_source', 'active')->lockForUpdate()->get();
            if ($lines->isEmpty()) throw new \RuntimeException('No open order lines found for this order.');
            if (! empty($d['sales'])) {      // Split parcel: only the chosen lines go into this parcel (R-H-29)
                $lines = $lines->whereIn('id', array_map('intval', (array) $d['sales']))->values();
                if ($lines->isEmpty()) throw new \InvalidArgumentException('Choose at least one line of this order for the parcel.');
            } else $lines = $lines->filter(fn ($s) => ! self::inParcel((int) $s->id))->values();
            if ($lines->isEmpty()) throw new \RuntimeException("Order {$d['order_no']} is already packed.");
            foreach ($lines as $s) {
                $r = DB::table('wh_reservations')->where('fk_id_sale', $s->id)->whereNotIn('status', ['RELEASED', 'SHIPPED'])->orderByDesc('id')->first();
                if (! $r || $r->status !== 'PICKED') throw new \RuntimeException("Order {$s->order_no}: {$s->sku} is not fully picked yet.");
                if (self::inParcel((int) $s->id)) throw new \RuntimeException("Order {$s->order_no} is already packed.");
            }
            // pack verification, weight, box (R-H-29)
            $pl = array_values(array_filter(PackService::lines($ent, (string) $d['order_no'], (int) $d['branch']), fn ($l) => $lines->contains('id', (int) $l['sale'])));
            if (PackService::scanOn($ent)) {
                if (empty($d['scans'])) throw new \InvalidArgumentException('Scan each item of the order to verify it (or switch WH_SCAN_CONFIRM_PACK off).');
                $v = PackService::verify($pl, (array) $d['scans']); if (! $v['ok']) throw new \InvalidArgumentException(PackService::refusal($v));
            }
            $sug = PackService::suggest($ent, $pl);
            // carrier service: the rule's suggestion; the packer may change it with a reason (R-H-30)
            $svc = CarrierRules::suggest($ent, CarrierRules::facts($ent, (string) $d['order_no'], (int) $d['branch'], isset($d['weight_g']) ? (int) $d['weight_g'] : ($sug['weight_g'] ?? null)));
            if (empty($d['service']) && $svc['service']) { $d['service'] = $svc['service']; $d['carrier'] ??= $svc['carrier']; }
            elseif (! empty($d['service']) && $svc['service'] && (int) $d['service'] !== (int) $svc['service']) {
                if (! trim((string) ($d['service_reason'] ?? ''))) throw new \InvalidArgumentException('Give the reason for changing the suggested carrier service (' . $svc['why'] . ').');
                $changed = ['suggested' => $svc['service'], 'chosen' => (int) $d['service'], 'reason' => trim((string) $d['service_reason'])];
            }
            if (PackService::needsWeight(isset($d['service']) ? (int) $d['service'] : null) && empty($d['weight_g'])) throw new \InvalidArgumentException('Weight is needed for this carrier service – weigh the parcel.');
            $boxId = ! empty($d['box']) ? (int) $d['box'] : $sug['box'];
            $box = $boxId ? DB::table('wh_box_sizes')->where('id', $boxId)->whereIn('fk_id_ent', [$ent, 0])->first() : null;
            if (! empty($d['box']) && ! $box) throw new \InvalidArgumentException('Box not found.');
            $carrier = $d['carrier'] ?? DB::table('t_branches')->where('id', $d['branch'])->value('fk_id_default_carrier');
            $lost = DB::table('wh_parcel_lines as pl')->join('wh_parcels as p', 'p.id', '=', 'pl.fk_id_parcel')->whereIn('pl.fk_id_sale', $lines->pluck('id'))->whereNotNull('p.claim_status')->max('p.id');      // a replacement of a lost parcel (R-H-36)
            $pid = (int) DB::table('wh_parcels')->insertGetId(['resend_of' => $lost ?: null,'fk_id_ent' => $ent, 'parcel_code' => Numbering::next('PRC', $ent), 'fk_id_branch' => $d['branch'], 'order_no' => $d['order_no'], 'fk_id_site' => (int) ($lines[0]->fk_id_sell_location ?: 0), 'fk_id_carrier' => $carrier,
                'fk_id_box' => $box->id ?? null, 'box_suggested' => $sug['box'], 'fk_id_car_pkg_svc' => $d['service'] ?? null, 'weight_g' => $d['weight_g'] ?? $sug['weight_g'], 'length_cm' => $box ? $box->inner_l_mm / 10 : null, 'width_cm' => $box ? $box->inner_w_mm / 10 : null, 'height_cm' => $box ? $box->inner_h_mm / 10 : null,
                'tracking_no' => $d['tracking_no'] ?? null, 'label_status' => ! empty($d['tracking_no']) ? 'READY' : 'NONE', 'status' => 'READY', 'packed_by' => Context::userId(), 'packed_at' => now(), 'created_at' => now()]);
            foreach ($lines as $s) {
                DB::table('wh_parcel_lines')->insert(['fk_id_parcel' => $pid, 'fk_id_sale' => $s->id, 'fk_id_product' => $s->fk_product_id, 'qty' => $s->qty, 'qty_packed' => $s->qty, 'qty_scanned' => isset($v) ? $s->qty : null]);
                DB::table('t_sales')->where('id', $s->id)->update(['wh_status' => 'PACKED', 'tracking_no' => $d['tracking_no'] ?? $s->tracking_no]);
            }
            PackService::useBox($ent, $pid, $box->id ?? null);      // packing materials as stock (WH_PACKING_STOCK)
            if (isset($changed)) Audit::log('wh_parcels', $pid, 'UPDATE', ['carrier_service' => $changed], $changed['reason'], $ent);
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

    /** R-H-39 return grades → [stock condition, restock place]; the old codes are still read (SELLABLE = resellable new, MISSING = empty box). */
    public const GRADES = ['RESELLABLE_NEW' => 'Resellable – as new', 'RESELLABLE_OPENED' => 'Resellable – opened', 'DAMAGED' => 'Damaged', 'SUPPLIER_FAULT' => 'Supplier fault',
        'WRONG_ITEM_RETURNED' => 'Wrong item returned', 'MISSING_ITEM' => 'Missing item (empty box)'];
    private const OLD_GRADES = ['SELLABLE' => 'RESELLABLE_NEW', 'MISSING' => 'MISSING_ITEM', 'QUARANTINE' => 'QUARANTINE'];

    public static function grade(?string $g): string
    {
        $g = strtoupper(trim((string) $g)) ?: 'RESELLABLE_NEW';
        $g = self::OLD_GRADES[$g] ?? $g;
        if ($g !== 'QUARANTINE' && ! isset(self::GRADES[$g])) throw new \InvalidArgumentException('Choose the grade of the return from the list.');
        return $g;
    }

    /** Where a grade goes: [stock condition for the ledger, location id, settings used]. */
    private static function restock(int $ent, object $sale, string $grade): array
    {
        $home = function () use ($ent, $sale) {
            $h = DB::table('t_product_locations as h')->join('cat_inventory_locations as l', 'l.id', '=', 'h.fk_id_location')->where('h.fk_id_product', $sale->fk_product_id)->where('h.home_role', 'PRIMARY')->where('l.fk_id_ent', $ent)->where('l.f_active', 1)->value('h.fk_id_location');
            return (int) ($h ?: ($sale->fk_id_sell_location ?: StockService::systemLocation($ent, 'RETURNS')));
        };
        $to = fn (string $setting, string $def) => strtoupper((string) Settings::get($setting, $ent, $def)) === 'HOME' ? $home() : StockService::systemLocation($ent, 'RETURNS');
        return match ($grade) {
            'RESELLABLE_NEW' => ['SELLABLE', $to('WH_RETURN_NEW_TO', 'HOME')],
            'RESELLABLE_OPENED' => ['SELLABLE', $to('WH_RETURN_OPENED_TO', 'RETURNS')],
            'DAMAGED' => ['DAMAGED', StockService::systemLocation($ent, 'DAMAGED')],
            'SUPPLIER_FAULT', 'WRONG_ITEM_RETURNED', 'QUARANTINE' => ['QUARANTINE', StockService::systemLocation($ent, 'QUARANTINE')],
            'MISSING_ITEM' => ['MISSING', StockService::systemLocation($ent, 'WRITTEN_OFF')],
        };
    }

    /**
     * R-H-39 resolver: the sale lines a scanned code means – parcel tracking / parcel ref → its lines; order no or tracking on the line; return intake ref;
     * else an item code (SKU) → its shipped lines of the last 60 days (pick from a list). Returns sale rows (newest first).
     */
    public static function returnCandidates(int $ent, string $code, ?string $sku = null): array
    {
        $code = trim($code); if ($code === '') return [];
        $q = fn () => DB::table('t_sales')->where('fk_id_ent', $ent)->when($sku, fn ($w) => $w->where('sku', $sku));
        $parcel = DB::table('wh_parcels')->where('fk_id_ent', $ent)->where(fn ($w) => $w->where('tracking_no', $code)->orWhere('parcel_code', $code))->value('id');
        if ($parcel) { $ids = DB::table('wh_parcel_lines')->where('fk_id_parcel', $parcel)->pluck('fk_id_sale')->filter()->all(); if ($ids) return $q()->whereIn('id', $ids)->orderByDesc('id')->get()->all(); }
        $rows = $q()->where(fn ($w) => $w->where('order_no', $code)->orWhere('tracking_no', $code))->orderByDesc('id')->get();
        if ($rows->count()) return $rows->all();
        $in = DB::table('wh_return_intakes')->where('fk_id_ent', $ent)->where('intake_code', $code)->value('fk_id_sale');
        if ($in) return $q()->where('id', $in)->get()->all();
        return $q()->where('sku', $code)->where('qty_shipped', '>', 0)->where('created_at', '>=', now()->subDays(60))->orderByDesc('id')->limit(10)->get()->all();
    }

    /**
     * Book a return (R-H-39 … 41). $d: code (anything on the parcel) or sale, qty, grade (condition = old name), to_location?, note?, op_id?, more (receive more than once), intake (match an unmatched intake).
     * Several lines match → nothing is booked, the answer lists them to choose (choose). A line already returned → "Already received 2 of 4 – receive more?" (confirm) unless more = true.
     * Before Month Confirm the units come back from SHIPPED to the restock place; after Confirm they go back into their lot and the cost of sale is reversed.
     */
    public static function receiveReturn(array $d): array
    {
        return DB::transaction(function () use ($d) {
            $ent = (int) Context::ent(); $code = trim((string) ($d['code'] ?? ''));
            if (! empty($d['op_id']) && ($was = DB::table('t_sale_returns')->where('client_op_id', $d['op_id'])->first())) return ['intake' => (int) $was->fk_id_return_intake, 'matched' => true, 'return' => (int) $was->id, 'repeat' => true, 'before_confirm' => (bool) $was->f_before_confirm, 'cost_back' => 0.0];      // R-H-53 the same op twice
            $grade = self::grade($d['grade'] ?? $d['condition'] ?? null);
            $sale = null;
            if (! empty($d['sale'])) $sale = DB::table('t_sales')->where('id', $d['sale'])->where('fk_id_ent', $ent)->lockForUpdate()->first();
            else {
                $c = self::returnCandidates($ent, $code, $d['sku'] ?? null);
                $orders = collect($c)->pluck('order_no')->unique();
                if (count($c) > 1) return ['matched' => true, 'choose' => array_map(fn ($x) => ['sale' => (int) $x->id, 'order_no' => $x->order_no, 'sku' => $x->sku, 'qty' => (int) $x->qty_shipped, 'returned' => (int) DB::table('t_sale_returns')->where('fk_id_sale', $x->id)->sum('qty')], $c),
                    'message' => $orders->count() > 1 ? 'Several orders match – choose the order line.' : "Order {$orders->first()} has " . count($c) . ' lines – choose the line.'];
                $sale = $c ? DB::table('t_sales')->where('id', $c[0]->id)->lockForUpdate()->first() : null;
            }
            if (! $sale) {
                if (! empty($d['intake'])) throw new \InvalidArgumentException('Order line not found.');
                $intake = (int) DB::table('wh_return_intakes')->insertGetId(['fk_id_ent' => $ent, 'intake_code' => Numbering::next('RTI', $ent), 'fk_id_site' => 0, 'scanned_code' => mb_substr($code ?: ('sale ' . ($d['sale'] ?? '')), 0, 100), 'resolved_as' => 'UNMATCHED',
                    'fk_id_product' => null, 'qty' => $d['qty'] ?? 1, 'grade' => $grade, 'status' => 'UNMATCHED', 'note' => $d['note'] ?? null, 'client_op_id' => $d['op_id'] ?? null, 'received_by' => Context::userId() ?? 0, 'received_at' => now()]);
                EventBus::fire('RETURN_SCANNED', 'wh_return_intakes', $intake, ['matched' => false], $ent);
                TaskEngine::open('WH_RETURN_UNMATCHED', [$intake], "Return without an order: scanned “{$code}” – match it under Returns to match", ['ent' => $ent, 'module' => 'wh_return_intakes', 'record_id' => $intake]);      // R-H-40 no stock movement
                return ['intake' => $intake, 'matched' => false];
            }
            $qty = (int) ($d['qty'] ?? 1);
            if ($qty <= 0) throw new \InvalidArgumentException('Quantity must be more than zero.');
            $before = $sale->status === 'Pending';
            if ($before) { $out = (int) $sale->qty_shipped - (int) $sale->qty_returned_unconfirmed; if ($qty > $out) throw new \RuntimeException("Only $out unit(s) of this line were shipped and are still out."); }
            $had = (int) DB::table('t_sale_returns')->where('fk_id_sale', $sale->id)->sum('qty');
            if ($had > 0 && empty($d['more'])) return ['matched' => true, 'confirm' => true, 'sale' => (int) $sale->id, 'message' => "Already received $had of " . (int) ($sale->qty ?: $sale->qty_shipped) . ' – receive more?'];
            [$cond, $to] = self::restock($ent, $sale, $grade); $to = (int) ($d['to_location'] ?? $to);
            if (! empty($d['intake'])) {      // R-H-40 an unmatched intake matched later
                $intake = (int) $d['intake'];
                $x = DB::table('wh_return_intakes')->where('id', $intake)->where('fk_id_ent', $ent)->lockForUpdate()->first() ?? throw new \InvalidArgumentException('Return intake not found.');
                if ($x->status !== 'UNMATCHED') throw new \InvalidArgumentException('This return is already matched.');
                DB::table('wh_return_intakes')->where('id', $intake)->update(['resolved_as' => 'ORDER', 'fk_id_branch' => $sale->fk_id_branch, 'fk_id_sale' => $sale->id, 'fk_id_product' => $sale->fk_product_id, 'grade' => $grade, 'status' => 'BOOKED']);
                TaskEngine::close('WH_RETURN_UNMATCHED', [$intake], 'Matched to order ' . $sale->order_no);
            } else {
                $intake = (int) DB::table('wh_return_intakes')->insertGetId(['fk_id_ent' => $ent, 'intake_code' => Numbering::next('RTI', $ent), 'fk_id_site' => 0, 'scanned_code' => mb_substr($code ?: ('sale ' . $sale->id), 0, 100), 'resolved_as' => 'ORDER',
                    'fk_id_branch' => $sale->fk_id_branch, 'fk_id_sale' => $sale->id, 'fk_id_product' => $sale->fk_product_id, 'qty' => $qty, 'grade' => $grade, 'status' => 'BOOKED', 'note' => $d['note'] ?? null, 'client_op_id' => $d['op_id'] ?? null, 'received_by' => Context::userId() ?? 0, 'received_at' => now()]);
                EventBus::fire('RETURN_SCANNED', 'wh_return_intakes', $intake, ['matched' => true], $ent);
            }
            if ($before) {
                if ($cond !== 'MISSING') {
                    $r = DB::table('wh_reservations')->where('fk_id_sale', $sale->id)->orderByDesc('id')->first();
                    StockService::transfer($ent, (int) $sale->fk_product_id, $qty, StockService::systemLocation($ent, 'SHIPPED'), $to, "Return $grade before confirm", ['rule' => ($r->owner_rule ?? 'POOL') === 'PRIVATE' ? 'PRIVATE' : 'POOL', 'owner' => $r->fk_id_owner_seg ?? null]);
                    DB::table('t_sales')->where('id', $sale->id)->update(['qty_returned_unconfirmed' => DB::raw('COALESCE(qty_returned_unconfirmed,0) + ' . $qty)]);
                }
                $back = 0.0;
            } else {
                $back = StockService::returnIn($ent, (int) $sale->id, $qty, $cond, $to)['cost_back'];
            }
            $refunded = $sale->refunded_at || ($sale->fk_id_refund_proposal && DB::table('t_sale_refund_proposals')->where('id', $sale->fk_id_refund_proposal)->whereIn('status', ['APPROVED', 'APPLIED', 'DONE'])->exists());
            $ret = (int) DB::table('t_sale_returns')->insertGetId(['fk_id_ent' => $ent, 'fk_id_sale' => $sale->id, 'condition_code' => $cond, 'qty' => $qty, 'd_received' => date('Y-m-d'), 'restock_to' => $cond === 'SELLABLE' ? 'STOCK' : $cond, 'note' => $d['note'] ?? null,
                'created_by' => Context::userId(), 'created_at' => now(), 'fk_id_return_intake' => $intake, 'grade' => $grade, 'fk_id_restock_location' => $to, 'f_before_confirm' => $before ? 1 : 0, 'refund_review' => $refunded ? 'DONE' : 'OPEN', 'received_by' => Context::userId(), 'client_op_id' => $d['op_id'] ?? null]);
            // R-H-41: refund review only when the order has no refund yet; a refunded order just closes SAL_RETURN_EXPECTED
            if ($refunded) TaskEngine::close('SAL_RETURN_EXPECTED', [$sale->id], 'The return was booked as received');
            else TaskEngine::open('WH_RETURN_REFUND_REVIEW', [$ret], "Return received for order {$sale->order_no} ($qty × {$sale->sku}, " . (self::GRADES[$grade] ?? $grade) . ') – review the refund', ['ent' => $ent, 'module' => 't_sales', 'record_id' => $sale->id, 'fix_url' => '#/sales?q=' . rawurlencode((string) $sale->order_no)]);
            $claim = $grade === 'SUPPLIER_FAULT' ? self::claimForReturn($ent, $sale, $qty, $ret) : null;
            if ($grade === 'WRONG_ITEM_RETURNED') TaskEngine::open('WH_RETURN_UNMATCHED', [$intake], "Wrong item returned on order {$sale->order_no} – it is in QUARANTINE, check what came back", ['ent' => $ent, 'module' => 'wh_return_intakes', 'record_id' => $intake]);
            EventBus::fire('RETURN_RECEIVED', 't_sale_returns', $ret, ['sale' => $sale->id, 'qty' => $qty, 'condition' => $cond, 'grade' => $grade, 'before_confirm' => $before], $ent);
            if ($cond === 'SELLABLE' && $to !== StockService::systemLocation($ent, 'RETURNS')) self::fillShorts((int) $sale->fk_product_id);
            return ['intake' => $intake, 'matched' => true, 'return' => $ret, 'grade' => $grade, 'restock_location' => $to, 'before_confirm' => $before, 'cost_back' => $back, 'claim' => $claim, 'refund_review' => ! $refunded];
        });
    }

    /**
     * R-H-41 a SUPPLIER_FAULT return adds a claim line on the GRN of its lot: the lot the sale took (after Confirm), else the oldest lot of the product still holding units;
     * units + amount (lot cost) on that GRN line's claim (return_claim_qty raises the cap), the GRN claim OPEN. The units wait in QUARANTINE.
     */
    private static function claimForReturn(int $ent, object $sale, int $qty, int $retId): ?array
    {
        $lotId = DB::table('fin_lot_consumption')->where('fk_id_sale', $sale->id)->whereNull('fk_id_reverses')->orderByDesc('id')->value('lot_id');
        $lot = $lotId ? DB::table('fin_lots')->where('id', $lotId)->first() : DB::table('fin_lots')->where('company_id', $ent)->where('fk_id_product', $sale->fk_product_id)->where('source', 'GRN')->orderBy('received_date')->orderBy('id')->first();
        if (! $lot || $lot->source !== 'GRN' || ! $lot->source_id) return null;
        $line = DB::table('t_src_grn_lines')->where('fk_id_grn', $lot->source_id)->where('fk_id_product', $sale->fk_product_id)->orderBy('id')->first();
        if (! $line) return null;
        $amt = round($qty * (float) $lot->unit_cost_gbp, 2);
        DB::table('t_src_grn_lines')->where('id', $line->id)->update(['return_claim_qty' => DB::raw('COALESCE(return_claim_qty,0) + ' . $qty), 'claim_qty' => DB::raw('COALESCE(claim_qty,0) + ' . $qty), 'claim_amount' => DB::raw('COALESCE(claim_amount,0) + ' . $amt)]);
        $g = DB::table('t_src_grn')->where('id', $lot->source_id)->first();
        if (! in_array($g->claim_status, ['SETTLED', 'REJECTED', 'NO_CLAIM'], true)) DB::table('t_src_grn')->where('id', $g->id)->update(['claim_status' => 'OPEN']);
        DB::table('t_sale_returns')->where('id', $retId)->update(['fk_id_grn_claim' => $g->id]);
        SupplierClaims::refresh($ent, (int) $g->id);
        Audit::log('t_src_grn_lines', (int) $line->id, 'UPDATE', ['return_claim' => [$qty, $amt], 'return' => $retId], 'supplier-fault return', $ent);
        return ['grn' => (int) $g->id, 'grn_code' => $g->grn_number ?: $g->grn_code, 'lot' => $lot->lot_code, 'units' => $qty, 'amount' => $amt];
    }

    // ---------------------------------------------------------------- reads and helpers

    /** Orders board: open order lines with their warehouse status. */
    public static function board(int $ent): array
    {
        return \App\Services\Perm::mine(DB::table('t_sales as s'), 's.fk_id_branch')->leftJoin('wh_reservations as r', fn ($j) => $j->on('r.fk_id_sale', '=', 's.id')->whereNotIn('r.status', ['RELEASED'])->whereRaw('r.resend_no = (SELECT MAX(x.resend_no) FROM wh_reservations x WHERE x.fk_id_sale = r.fk_id_sale AND x.status <> "RELEASED")'))->leftJoin('t_branches as b', 'b.id', '=', 's.fk_id_branch')
            ->where('s.fk_id_ent', $ent)->where(fn ($w) => $w->where('s.status', 'Pending')->orWhere('s.wh_status', 'PUTBACK'))->where('s.sale_source', 'active')      /* R-H-17: cancelled lines waiting for a putback stay on the board */->whereRaw("COALESCE(s.wh_status,'') NOT IN ('SHIPPED','RELEASED')")->orderByRaw('s.dispatch_by IS NULL, s.dispatch_by')->orderBy('s.id')->limit(500)
            ->get(['s.id', 's.order_no', 's.d_order', 's.sku', 's.qty', 's.wh_status', 's.fk_id_branch', 'b.shop_name as shop', 's.ship_to_name', 's.dispatch_by', 's.carrier_service_text', 'r.status as reservation', 'r.qty as reserved', 'r.qty_short', 'r.qty_picked'])
            ->pipe(fn ($c) => DispatchDeadline::sort($c->all()));      // overdue → due before collection → due today → later; express first; order time (R-H-24)
    }

    /** Sellable bins of a product in walking order with the units a rule may take (oldest lot first inside the stock service). */
    public static function binsOf(int $productId, int $ent, ?int $privateOwner): array
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
    public static function ownerRule(object $sale): ?int
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
