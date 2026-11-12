<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * The only writer of stock (PHASE_03). Stock is a ledger of movements, never a typed number:
 *   t_stock_movements  one line per change (product, signed qty, location, lot, owner, unit cost, source)
 *   fin_lots           units received together, with landed cost, owner, date, visibility (POOL / PRIVATE)
 *   fin_lot_balances   where the units of a lot are now (lot × location)
 * t_product_vendors.stock is a cache = sum of the balances, written here in the same transaction.
 * Every method runs in one transaction and locks the balance rows it changes, so two people can never take the same unit.
 */
class StockService
{
    /** Movement types that never change value in the ledger accounts (a move between places of the same owner). */
    private const NOT_SELLABLE = ['DAMAGED', 'RETURNS', 'PRODUCTION', 'TRANSIT', 'WRITTEN_OFF', 'QUARANTINE', 'MISSING', 'SUPPLIER', 'CUSTOMER', 'SHIPPED'];

    /**
     * Receive units: one lot per line. Called by GRN post (step 3) and by the opening stock entry.
     * $lines: product, qty, location, unit_cost; optional owner, visibility, received_date, damaged_qty, po_id, cycle_id.
     * $opt: lot_source (GRN / OPENING / MANUAL …), contra = ledger account credited (default 3000 for OPENING, 1210 else), post = false to skip the journal.
     */
    public static function receive(int $ent, array $lines, string $sourceType, ?int $sourceId, array $opt = []): array
    {
        return DB::transaction(function () use ($ent, $lines, $sourceType, $sourceId, $opt) {
            $lotIds = []; $value = 0.0;
            $lotSource = $opt['lot_source'] ?? 'GRN';
            foreach ($lines as $l) {
                $qty = (int) $l['qty']; $dmg = (int) ($l['damaged_qty'] ?? 0);
                if ($qty <= 0) throw new \InvalidArgumentException('Received quantity must be more than zero.');
                if ($dmg < 0 || $dmg > $qty) throw new \InvalidArgumentException('Damaged quantity cannot be more than the received quantity.');
                $p = self::product((int) $l['product'], $ent);
                self::location((int) $l['location'], $ent);
                $cost = round((float) $l['unit_cost'], 4);
                if ($cost < 0) throw new \InvalidArgumentException('Unit cost cannot be negative.');
                $date = $l['received_date'] ?? date('Y-m-d');
                $lotId = DB::table('fin_lots')->insertGetId([
                    'company_id' => $ent, 'sku' => (string) $p->SKU_VENDOR, 'po_id' => $l['po_id'] ?? null, 'source' => $lotSource, 'source_id' => $sourceId,
                    'received_date' => $date, 'qty' => $qty, 'qty_left' => $qty, 'unit_cost_gbp' => $cost, 'created_at' => now(),
                    'lot_code' => Numbering::next('LOT', $ent, $date), 'fk_id_product' => $p->id, 'fk_id_owner' => $l['owner'] ?? null,
                    'fk_id_cycle' => $l['cycle_id'] ?? null, 'landed_status' => $l['landed_status'] ?? 'PROVISIONAL',
                    'visibility' => $l['visibility'] ?? (($p->e_product_type ?? 'pool') === 'private' ? 'PRIVATE' : 'POOL'),
                ]);
                $good = $qty - $dmg;
                if ($good > 0) {
                    self::addBalance($lotId, (int) $l['location'], $good);
                    self::move($ent, $p->id, 'purchase_in', $good, (int) $l['location'], $lotId, $l['owner'] ?? null, $cost, $sourceType, $sourceId);
                }
                if ($dmg > 0) {
                    $dl = self::systemLocation($ent, 'DAMAGED');
                    self::addBalance($lotId, $dl, $dmg);
                    self::move($ent, $p->id, 'damaged', $dmg, $dl, $lotId, $l['owner'] ?? null, $cost, $sourceType, $sourceId, 'Arrived damaged');
                    TaskEngine::open('SRC_GRN_SHORT', [$sourceId ?? 0, $p->SKU_VENDOR], "Damaged on arrival – claim from the supplier: $dmg × {$p->SKU_VENDOR}", ['ent' => $ent, 'module' => 'fin_lots', 'record_id' => $lotId, 'amount' => round($dmg * $cost, 2)]);
                }
                self::refreshCache($p->id);
                $lotIds[] = $lotId; $value += $qty * $cost;
            }
            $value = round($value, 2);
            if ($value > 0 && ($opt['post'] ?? true)) {
                $contra = $opt['contra'] ?? ($lotSource === 'OPENING' ? '3000' : '1210');
                Ledger::post($ent, $lines[0]['received_date'] ?? date('Y-m-d'), $sourceType, $sourceId, [
                    ['account' => '1200', 'debit' => $value], ['account' => $contra, 'credit' => $value, 'ref_id' => $opt['contra_ref'] ?? null],
                ], ['memo' => 'Stock received', 'allow_no_ref' => true]);
            }
            EventBus::fire('GRN_POSTED', $sourceType === 'GRN' ? 't_src_grn' : 'fin_lots', $sourceId ?? $lotIds[0], ['lots' => $lotIds, 'value' => $value, 'source' => $lotSource], $ent);
            return ['lots' => $lotIds, 'value' => $value];
        });
    }

    /** Move units between two locations: oldest lots first, the lot and its cost travel with the units. */
    public static function transfer(int $ent, int $productId, int $qty, int $fromLoc, int $toLoc, ?string $reason = null, array $opt = []): array
    {
        if ($qty <= 0) throw new \InvalidArgumentException('Quantity must be more than zero.');
        if ($fromLoc === $toLoc) throw new \InvalidArgumentException('Choose two different locations.');
        return DB::transaction(function () use ($ent, $productId, $qty, $fromLoc, $toLoc, $reason, $opt) {
            $p = self::product($productId, $ent);
            self::location($toLoc, $ent);
            $picks = self::pick($productId, $fromLoc, $qty, $opt['owner'] ?? null, null);
            $pair = null; $out = [];
            foreach ($picks as $pk) {
                self::addBalance($pk['lot'], $fromLoc, -$pk['qty']);
                self::addBalance($pk['lot'], $toLoc, $pk['qty']);
                $a = self::move($ent, $productId, 'transfer_out', -$pk['qty'], $fromLoc, $pk['lot'], $pk['owner'], $pk['cost'], $opt['source_type'] ?? 'TRANSFER', $opt['source_id'] ?? null, $reason);
                $pair ??= $a;
                $b = self::move($ent, $productId, 'transfer_in', $pk['qty'], $toLoc, $pk['lot'], $pk['owner'], $pk['cost'], $opt['source_type'] ?? 'TRANSFER', $opt['source_id'] ?? null, $reason, $pair);
                DB::table('t_stock_movements')->where('id', $a)->update(['pair_id' => $pair]);
                $out[] = ['lot' => $pk['lot'], 'qty' => $pk['qty']];
            }
            self::refreshCache($productId);
            EventBus::fire('STOCK_TRANSFERRED', 't_product_vendors', $productId, ['qty' => $qty, 'from' => $fromLoc, 'to' => $toLoc], $ent);
            return $out;
        });
    }

    /**
     * Take units for a confirmed sale: FIFO by received date, then lot id. A PRIVATE rule takes only that owner's lots;
     * POOL takes pool lots. Writes sale_out lines and the consumption rows that carry cost and owner to Finance.
     * Refuses when there is not enough stock at the location. Returns ['cost' => total, 'rows' => [...]].
     */
    public static function consumeForSale(int $ent, int $saleId, int $productId, int $qty, int $locId, ?int $privateOwner = null, array $opt = []): array
    {
        if ($qty <= 0) throw new \InvalidArgumentException('Quantity must be more than zero.');
        return DB::transaction(function () use ($ent, $saleId, $productId, $qty, $locId, $privateOwner, $opt) {
            $p = self::product($productId, $ent);
            $picks = self::pick($productId, $locId, $qty, $privateOwner, $privateOwner ? 'PRIVATE' : 'POOL');
            $date = $opt['date'] ?? date('Y-m-d'); $total = 0.0; $rows = [];
            foreach ($picks as $pk) {
                self::addBalance($pk['lot'], $locId, -$pk['qty']);
                DB::table('fin_lots')->where('id', $pk['lot'])->decrement('qty_left', $pk['qty']);
                $mv = self::move($ent, $productId, 'sale_out', -$pk['qty'], $locId, $pk['lot'], $pk['owner'], $pk['cost'], 'SALE', $saleId);
                DB::table('fin_lot_consumption')->insert([
                    'period_id' => (int) ($opt['period_id'] ?? 0), 'settlement_line_id' => (int) ($opt['settlement_line_id'] ?? 0), 'lot_id' => $pk['lot'], 'sku' => (string) $p->SKU_VENDOR,
                    'qty' => $pk['qty'], 'unit_cost_gbp' => $pk['cost'], 'fk_id_sale' => $saleId, 'fk_id_owner' => $pk['owner'], 'fk_id_movement' => $mv, 'consumed_on' => $date, 'reason' => 'SALE',
                ]);
                $total += $pk['qty'] * $pk['cost'];
                $rows[] = ['lot' => $pk['lot'], 'qty' => $pk['qty'], 'unit_cost' => $pk['cost'], 'owner' => $pk['owner']];
                EventBus::fire('STOCK_CONSUMED', 'fin_lots', $pk['lot'], ['sale' => $saleId, 'qty' => $pk['qty'], 'left' => (int) DB::table('fin_lots')->where('id', $pk['lot'])->value('qty_left')], $ent);
            }
            self::refreshCache($productId);
            return ['cost' => round($total, 2), 'rows' => $rows];
        });
    }

    /** A return booked as received: the units go back into the lot they came from, at the location for their condition. */
    public static function returnIn(int $ent, int $saleId, int $qty, string $condition = 'SELLABLE', ?int $toLoc = null): array
    {
        if ($qty <= 0) throw new \InvalidArgumentException('Quantity must be more than zero.');
        return DB::transaction(function () use ($ent, $saleId, $qty, $condition, $toLoc) {
            $cons = DB::table('fin_lot_consumption')->where('fk_id_sale', $saleId)->whereNull('fk_id_reverses')->orderByDesc('id')->get();   // newest consumption first
            $back = (int) abs(DB::table('fin_lot_consumption')->where('fk_id_sale', $saleId)->whereNotNull('fk_id_reverses')->sum('qty'));
            $sold = (int) $cons->sum('qty');
            if ($qty > $sold - $back) throw new \RuntimeException('Only ' . ($sold - $back) . ' unit(s) of this sale can still come back.');
            $kinds = ['SELLABLE' => null, 'DAMAGED' => 'DAMAGED', 'MISSING' => 'WRITTEN_OFF', 'QUARANTINE' => 'QUARANTINE'];
            $kind = array_key_exists($condition, $kinds) ? $kinds[$condition] : 'RETURNS';
            $left = $qty; $cost = 0.0; $productId = null;
            foreach ($cons as $c) {
                if ($left <= 0) break;
                $already = (int) abs(DB::table('fin_lot_consumption')->where('fk_id_reverses', $c->id)->sum('qty'));
                $take = min($left, $c->qty - $already);
                if ($take <= 0) continue;
                $lot = DB::table('fin_lots')->where('id', $c->lot_id)->lockForUpdate()->first();
                $productId = (int) $lot->fk_id_product;
                $orig = DB::table('t_stock_movements')->where('id', $c->fk_id_movement)->first();
                $loc = $toLoc ?? ($kind ? self::systemLocation($ent, $kind) : (int) $orig->fk_id_location);
                if ($condition !== 'MISSING') {
                    self::addBalance($lot->id, $loc, $take);
                    DB::table('fin_lots')->where('id', $lot->id)->increment('qty_left', $take);
                }
                $mv = self::move($ent, $productId, $condition === 'MISSING' ? 'written_off' : 'return_in', $condition === 'MISSING' ? 0 : $take, $loc, $lot->id, $c->fk_id_owner, (float) $c->unit_cost_gbp, 'RETURN', $saleId, "Return: $condition");
                DB::table('fin_lot_consumption')->insert(['period_id' => $c->period_id, 'settlement_line_id' => $c->settlement_line_id, 'lot_id' => $lot->id, 'sku' => $c->sku, 'qty' => -$take,
                    'unit_cost_gbp' => $c->unit_cost_gbp, 'fk_id_sale' => $saleId, 'fk_id_owner' => $c->fk_id_owner, 'fk_id_movement' => $mv, 'consumed_on' => date('Y-m-d'), 'fk_id_reverses' => $c->id, 'reason' => 'RETURN']);
                if ($condition !== 'MISSING') $cost += $take * (float) $c->unit_cost_gbp;
                $left -= $take;
            }
            if ($productId) self::refreshCache($productId);
            return ['cost_back' => round($cost, 2)];
        });
    }

    /** Correct a quantity at one location. Minus takes oldest lots first; plus needs a lot to add to (or creates a MANUAL lot at the given cost). */
    public static function adjust(int $ent, int $productId, int $locId, int $qty, string $reason, array $opt = []): array
    {
        if ($qty === 0) throw new \InvalidArgumentException('The change cannot be zero.');
        if (trim($reason) === '') throw new \InvalidArgumentException('Give the reason for the adjustment.');
        return DB::transaction(function () use ($ent, $productId, $locId, $qty, $reason, $opt) {
            $p = self::product($productId, $ent);
            self::location($locId, $ent);
            $value = 0.0;
            if ($qty < 0) {
                foreach (self::pick($productId, $locId, -$qty, $opt['owner'] ?? null, null) as $pk) {
                    self::addBalance($pk['lot'], $locId, -$pk['qty']);
                    DB::table('fin_lots')->where('id', $pk['lot'])->decrement('qty_left', $pk['qty']);
                    self::move($ent, $productId, 'adjustment', -$pk['qty'], $locId, $pk['lot'], $pk['owner'], $pk['cost'], 'ADJUST', $opt['source_id'] ?? null, $reason);
                    $value -= $pk['qty'] * $pk['cost'];
                }
            } else {
                $lot = ! empty($opt['lot']) ? DB::table('fin_lots')->where('id', $opt['lot'])->where('fk_id_product', $productId)->lockForUpdate()->first()
                    : DB::table('fin_lots')->where('fk_id_product', $productId)->where('company_id', $ent)->orderByDesc('received_date')->orderByDesc('id')->lockForUpdate()->first();
                if (! $lot) {
                    if (! isset($opt['unit_cost'])) throw new \RuntimeException('This product has no lot yet. Give the unit cost so a lot can be made.');
                    $r = self::receive($ent, [['product' => $productId, 'qty' => $qty, 'location' => $locId, 'unit_cost' => $opt['unit_cost'], 'owner' => $opt['owner'] ?? null]], 'ADJUST', null, ['lot_source' => 'MANUAL', 'contra' => '4100']);
                    Audit::log('t_product_vendors', $productId, 'UPDATE', ['stock_adjust' => [0, $qty], 'reason' => $reason], $reason, $ent);
                    return ['value' => $r['value']];
                }
                self::addBalance($lot->id, $locId, $qty);
                DB::table('fin_lots')->where('id', $lot->id)->increment('qty_left', $qty);
                self::move($ent, $productId, 'adjustment', $qty, $locId, $lot->id, $lot->fk_id_owner, (float) $lot->unit_cost_gbp, 'ADJUST', $opt['source_id'] ?? null, $reason);
                $value += $qty * (float) $lot->unit_cost_gbp;
            }
            $value = round($value, 2);
            if ($value != 0.0) {
                Ledger::post($ent, date('Y-m-d'), 'STOCK_ADJUST', $productId, $value < 0
                    ? [['account' => '5500', 'debit' => -$value], ['account' => '1200', 'credit' => -$value]]
                    : [['account' => '1200', 'debit' => $value], ['account' => '4100', 'credit' => $value]], ['memo' => mb_substr("Stock adjustment {$p->SKU_VENDOR}: $reason", 0, 255)]);
            }
            self::refreshCache($productId);
            Audit::log('t_product_vendors', $productId, 'UPDATE', ['stock_adjust' => [null, $qty], 'location' => $locId], $reason, $ent);
            EventBus::fire('STOCK_ADJUSTED', 't_product_vendors', $productId, ['qty' => $qty, 'location' => $locId, 'value' => $value], $ent);
            return ['value' => $value];
        });
    }

    /** Give units of a lot to another owner: the lot is split (same cost and date, so FIFO order is kept). */
    public static function transferOwnership(int $ent, int $lotId, int $qty, int $toOwner, ?float $unitValue = null): int
    {
        if ($qty <= 0) throw new \InvalidArgumentException('Quantity must be more than zero.');
        return DB::transaction(function () use ($ent, $lotId, $qty, $toOwner, $unitValue) {
            $lot = DB::table('fin_lots')->where('id', $lotId)->where('company_id', $ent)->lockForUpdate()->first() ?? throw new \RuntimeException('Lot not found.');
            if ((int) $lot->fk_id_owner === $toOwner) throw new \InvalidArgumentException('The lot already belongs to this owner.');
            if ($qty > $lot->qty_left) throw new \RuntimeException("Only {$lot->qty_left} unit(s) are left in this lot.");
            $newId = DB::table('fin_lots')->insertGetId(['company_id' => $ent, 'sku' => $lot->sku, 'po_id' => $lot->po_id, 'source' => 'SPLIT', 'source_id' => $lot->id, 'received_date' => $lot->received_date,
                'qty' => $qty, 'qty_left' => $qty, 'unit_cost_gbp' => $lot->unit_cost_gbp, 'created_at' => now(), 'lot_code' => Numbering::next('LOT', $ent, $lot->received_date), 'fk_id_product' => $lot->fk_id_product,
                'fk_id_owner' => $toOwner, 'fk_id_cycle' => $lot->fk_id_cycle, 'landed_status' => $lot->landed_status, 'visibility' => $lot->visibility]);
            $left = $qty;
            foreach (DB::table('fin_lot_balances')->where('fk_id_lot', $lotId)->where('qty', '>', 0)->orderBy('id')->lockForUpdate()->get() as $b) {
                if ($left <= 0) break;
                $take = min($left, $b->qty);
                self::addBalance($lotId, (int) $b->fk_id_location, -$take);
                self::addBalance($newId, (int) $b->fk_id_location, $take);
                $o = self::move($ent, (int) $lot->fk_id_product, 'ownership_out', -$take, (int) $b->fk_id_location, $lotId, $lot->fk_id_owner, (float) $lot->unit_cost_gbp, 'OWNERSHIP', $newId);
                self::move($ent, (int) $lot->fk_id_product, 'ownership_in', $take, (int) $b->fk_id_location, $newId, $toOwner, (float) $lot->unit_cost_gbp, 'OWNERSHIP', $newId, null, $o);
                $left -= $take;
            }
            if ($left > 0) throw new \RuntimeException('The units of this lot are not all in stock locations.');
            DB::table('fin_lots')->where('id', $lotId)->decrement('qty_left', $qty);
            DB::table('fin_lots')->where('id', $lotId)->decrement('qty', $qty);
            EventBus::fire('OWNERSHIP_TRANSFERRED', 'fin_lots', $newId, ['from_lot' => $lotId, 'qty' => $qty, 'from_owner' => $lot->fk_id_owner, 'to_owner' => $toOwner, 'unit_value' => $unitValue ?? (float) $lot->unit_cost_gbp], $ent);
            return $newId;
        });
    }

    // ---------- reads ----------

    /** Units of a product: everywhere, at one location, or of one owner. */
    public static function balance(int $productId, ?int $locId = null, ?int $ownerId = null): int
    {
        return (int) DB::table('fin_lot_balances as b')->join('fin_lots as l', 'l.id', '=', 'b.fk_id_lot')->where('l.fk_id_product', $productId)
            ->when($locId, fn ($q) => $q->where('b.fk_id_location', $locId))->when($ownerId, fn ($q) => $q->where('l.fk_id_owner', $ownerId))->sum('b.qty');
    }

    /** Units that can be sold: sellable locations only, minus what is reserved. */
    public static function available(int $productId): int
    {
        $sellable = (int) DB::table('fin_lot_balances as b')->join('fin_lots as l', 'l.id', '=', 'b.fk_id_lot')->join('cat_inventory_locations as c', 'c.id', '=', 'b.fk_id_location')
            ->where('l.fk_id_product', $productId)->where(fn ($w) => $w->whereNull('c.kind')->orWhereNotIn('c.kind', self::NOT_SELLABLE))->where(fn ($w) => $w->whereNull('c.f_sellable')->orWhere('c.f_sellable', 1))->sum('b.qty');
        $reserved = (int) DB::table('wh_reservations')->where('fk_id_product', $productId)->whereIn('status', ['OPEN', 'ACTIVE', 'RESERVED', 'PICKING'])->sum(DB::raw('qty - COALESCE(qty_picked,0)'));
        return max(0, $sellable - $reserved);
    }

    /** Value of the stock of a company at cost (units in stock × lot cost). Must equal ledger account 1200. */
    public static function value(int $ent, ?int $productId = null): float
    {
        return round((float) DB::table('fin_lot_balances as b')->join('fin_lots as l', 'l.id', '=', 'b.fk_id_lot')->where('l.company_id', $ent)
            ->when($productId, fn ($q) => $q->where('l.fk_id_product', $productId))->sum(DB::raw('b.qty * l.unit_cost_gbp')), 2);
    }

    /** Stock on hand per product for the list screen. */
    public static function onHand(int $ent, ?string $q = null, int $limit = 200): array
    {
        $rows = DB::table('t_product_vendors as p')->leftJoin('fin_lots as l', fn ($j) => $j->on('l.fk_id_product', '=', 'p.id')->where('l.company_id', $ent))->leftJoin('fin_lot_balances as b', 'b.fk_id_lot', '=', 'l.id')
            ->where('p.fk_id_ent', $ent)->when($q, fn ($x) => $x->where(fn ($w) => $w->where('p.SKU_VENDOR', 'like', "%$q%")->orWhere('p.product_name_vendor', 'like', "%$q%")))
            ->groupBy('p.id', 'p.SKU_VENDOR', 'p.product_name_vendor', 'p.stock', 'p.min_level')->orderByRaw('COALESCE(SUM(b.qty),0) DESC')->limit($limit)
            ->get(['p.id', 'p.SKU_VENDOR as sku', 'p.product_name_vendor as name', 'p.stock as cached', 'p.min_level', DB::raw('COALESCE(SUM(b.qty),0) as units'), DB::raw('COALESCE(SUM(b.qty * l.unit_cost_gbp),0) as value')]);
        return $rows->map(fn ($r) => ['id' => $r->id, 'sku' => $r->sku, 'name' => $r->name, 'units' => (int) $r->units, 'available' => self::available($r->id), 'value' => round((float) $r->value, 2),
            'low' => $r->min_level > 0 && $r->units <= $r->min_level, 'mismatch' => (int) $r->cached !== (int) $r->units])->all();
    }

    /** Where the units of one product are, by location and by lot. */
    public static function productStock(int $productId): array
    {
        $by = DB::table('fin_lot_balances as b')->join('fin_lots as l', 'l.id', '=', 'b.fk_id_lot')->join('cat_inventory_locations as c', 'c.id', '=', 'b.fk_id_location')->leftJoin('t_account as a', 'a.id', '=', 'l.fk_id_owner')
            ->where('l.fk_id_product', $productId)->where('b.qty', '<>', 0)->orderBy('l.received_date')->orderBy('l.id')
            ->get(['c.location_name as location', 'c.kind', 'l.id as lot_id', 'l.lot_code', 'l.received_date', 'l.unit_cost_gbp', 'l.visibility', 'l.landed_status', 'b.qty', DB::raw("TRIM(CONCAT_WS(' ', a.first_name, a.sur_name)) as owner")]);
        return ['units' => self::balance($productId), 'available' => self::available($productId), 'rows' => $by];
    }

    /** Nightly self-check: the cached stock number equals the ledger; no balance is negative. Opens / closes STK_LEDGER_MISMATCH. */
    public static function selfCheck(int $ent): array
    {
        $bad = DB::table('t_product_vendors as p')->where('p.fk_id_ent', $ent)
            ->selectRaw('p.id, p.SKU_VENDOR sku, p.stock cached, (SELECT COALESCE(SUM(b.qty),0) FROM fin_lot_balances b JOIN fin_lots l ON l.id=b.fk_id_lot WHERE l.fk_id_product=p.id) units, (SELECT COALESCE(SUM(m.qty_change),0) FROM t_stock_movements m WHERE m.fk_id_product=p.id) ledger')
            ->havingRaw('cached <> units OR units <> ledger')->get();
        $r = TaskEngine::sync('STK_LEDGER_MISMATCH', $bad->map(fn ($b) => ['key' => [$b->id], 'title' => "Stock differs for {$b->sku}: product says {$b->cached}, lots {$b->units}, ledger {$b->ledger}", 'opt' => ['module' => 't_product_vendors', 'record_id' => $b->id]])->all(), null, $ent);
        $neg = DB::table('fin_lot_balances as b')->join('fin_lots as l', 'l.id', '=', 'b.fk_id_lot')->where('l.company_id', $ent)->where('b.qty', '<', 0)->count();
        $gl = Ledger::balance($ent, '1200');
        return ['mismatch' => $bad->count(), 'negative_balances' => $neg, 'stock_value' => self::value($ent), 'ledger_1200' => $gl, 'value_matches_ledger' => abs(self::value($ent) - $gl) < 0.005] + $r;
    }

    /** STK_LOW: a task per product at or under its minimum level (when the alert is switched on); closes when stock is back above it. */
    public static function lowStockTasks(int $ent): array
    {
        $low = DB::table('t_product_vendors')->where('fk_id_ent', $ent)->where('f_active', 1)->where('f_alert_min_level', 1)->where('min_level', '>', 0)->whereColumn('stock', '<=', 'min_level')->get(['id', 'SKU_VENDOR as sku', 'stock', 'min_level']);
        $r = TaskEngine::sync('STK_LOW', $low->map(fn ($p) => ['key' => [$p->id], 'title' => "Stock low: {$p->sku} has {$p->stock} (minimum {$p->min_level})", 'opt' => ['module' => 't_product_vendors', 'record_id' => $p->id]])->all(), null, $ent);
        return $r + ['open' => $low->count()];
    }

    // ---------- internals ----------

    /** Choose lots FIFO at a location and lock their balance rows. $rule: POOL / PRIVATE / null (any). Refuses when short. */
    private static function pick(int $productId, int $locId, int $qty, ?int $owner, ?string $rule): array
    {
        $rows = DB::table('fin_lot_balances as b')->join('fin_lots as l', 'l.id', '=', 'b.fk_id_lot')
            ->where('l.fk_id_product', $productId)->where('b.fk_id_location', $locId)->where('b.qty', '>', 0)
            ->when($rule === 'PRIVATE', fn ($q) => $q->where('l.fk_id_owner', $owner))
            ->when($rule === 'POOL', fn ($q) => $q->where('l.visibility', 'POOL'))
            ->when($rule === null && $owner, fn ($q) => $q->where('l.fk_id_owner', $owner))
            ->orderBy('l.received_date')->orderBy('l.id')->lockForUpdate()
            ->get(['b.id as bid', 'b.qty', 'l.id as lot', 'l.unit_cost_gbp as cost', 'l.fk_id_owner as owner']);
        $have = (int) $rows->sum('qty');
        if ($have < $qty) {
            $sku = DB::table('t_product_vendors')->where('id', $productId)->value('SKU_VENDOR');
            $loc = DB::table('cat_inventory_locations')->where('id', $locId)->value('location_name');
            throw new \RuntimeException("Not enough stock of $sku at $loc: need $qty, have $have.");
        }
        $out = []; $left = $qty;
        foreach ($rows as $r) {
            if ($left <= 0) break;
            $take = min($left, (int) $r->qty);
            $out[] = ['lot' => (int) $r->lot, 'qty' => $take, 'cost' => (float) $r->cost, 'owner' => $r->owner ? (int) $r->owner : null];
            $left -= $take;
        }
        return $out;
    }

    private static function addBalance(int $lotId, int $locId, int $delta): void
    {
        $b = DB::table('fin_lot_balances')->where(['fk_id_lot' => $lotId, 'fk_id_location' => $locId])->lockForUpdate()->first();
        $new = ($b->qty ?? 0) + $delta;
        if ($new < 0) throw new \RuntimeException('Stock cannot go below zero.');
        $b ? DB::table('fin_lot_balances')->where('id', $b->id)->update(['qty' => $new, 'updated_at' => now()])
           : DB::table('fin_lot_balances')->insert(['fk_id_lot' => $lotId, 'fk_id_location' => $locId, 'qty' => $new, 'updated_at' => now()]);
    }

    private static function move(int $ent, int $productId, string $type, int $qty, int $locId, ?int $lotId, ?int $owner, float $cost, ?string $srcType, ?int $srcId, ?string $note = null, ?int $pair = null): int
    {
        $after = (int) DB::table('t_stock_movements')->where('fk_id_product', $productId)->sum('qty_change') + $qty;
        return (int) DB::table('t_stock_movements')->insertGetId(['fk_id_product' => $productId, 'movement_type' => $type, 'qty_change' => $qty, 'stock_after' => $after, 'notes' => $note ? mb_substr($note, 0, 255) : null,
            'created_by' => Context::userId() ?? 0, 'created_at' => now(), 'fk_id_ent' => $ent, 'fk_id_location' => $locId, 'fk_id_lot' => $lotId, 'fk_id_owner' => $owner, 'unit_cost_gbp' => $cost,
            'source_type' => $srcType, 'source_id' => $srcId, 'pair_id' => $pair]);
    }

    private static function refreshCache(int $productId): void
    {
        $units = self::balance($productId);
        DB::table('t_product_vendors')->where('id', $productId)->update(['stock' => $units, 'f_out_of_stock' => $units <= 0 ? 1 : 0]);
    }

    private static function product(int $id, int $ent): object
    {
        return DB::table('t_product_vendors')->where('id', $id)->where('fk_id_ent', $ent)->lockForUpdate()->first() ?? throw new \RuntimeException('Product not found in this company.');
    }

    private static function location(int $id, int $ent): object
    {
        return DB::table('cat_inventory_locations')->where('id', $id)->where(fn ($w) => $w->where('fk_id_ent', $ent)->orWhereNull('fk_id_ent')->orWhere('fk_id_ent', 0))->first() ?? throw new \RuntimeException('Location not found in this company.');
    }

    /** The one DAMAGED / RETURNS / WRITTEN_OFF … location of a company; made the first time it is needed. */
    public static function systemLocation(int $ent, string $kind): int
    {
        $id = DB::table('cat_inventory_locations')->where('fk_id_ent', $ent)->where('kind', $kind)->orderBy('id')->value('id');
        if ($id) return (int) $id;
        $names = ['DAMAGED' => 'Damaged', 'RETURNS' => 'Returns', 'WRITTEN_OFF' => 'Written off', 'QUARANTINE' => 'Quarantine', 'TRANSIT' => 'In transit', 'MISSING' => 'Missing'];
        return (int) DB::table('cat_inventory_locations')->insertGetId(['location_name' => $names[$kind] ?? ucfirst(strtolower($kind)), 'fk_id_location_type' => 0, 'f_active' => 1, 'created_at' => now(),
            'fk_id_ent' => $ent, 'location_code' => substr($kind, 0, 30), 'kind' => $kind, 'f_sellable' => 0]);
    }
}
