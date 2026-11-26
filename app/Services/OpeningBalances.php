<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-G_CORE-87 the opening as-of date of a company and its lock. Old stock comes in as opening lots on that date (mo:import-old);
 * a GRN makes lots only after the opening balances are LOCKED, and only when it is dated on / after the as-of date (an older receipt's
 * goods are already in the opening balances). The status lists that decide what counts as received, in production and in transit are
 * Reference Register lists (Setup › Lists): GRN_POSTED_STATUSES, PO_PRODUCTION_STATUSES, TRANSIT_STATUSES.
 */
class OpeningBalances
{
    public const LISTS = [
        'GRN_POSTED_STATUSES' => ['Receipts that make lots', ['POSTED' => 'Posted', 'CONFIRMED' => 'Confirmed', 'RECEIVED' => 'Received', 'COMPLETED' => 'Completed', 'ACTIVE' => 'active']],
        'PO_PRODUCTION_STATUSES' => ['PO lines in production', ['CONFIRMED' => 'Confirmed', 'PRODUCTION' => 'Production', 'IN_PRODUCTION' => 'In Production', 'READY' => 'Ready', 'QC' => 'QC']],
        'TRANSIT_STATUSES' => ['Goods in transit', ['SHIPPED' => 'Shipped', 'IN_TRANSIT' => 'In Transit', 'AT_PORT' => 'At Port', 'CUSTOMS' => 'Customs']],
    ];

    /** Codes of a status list (upper case); the built-in values when the list was never set up. */
    public static function statuses(string $list): array
    {
        $rows = DB::table('reference_items as i')->join('cat_references as r', 'r.id', '=', 'i.fk_reference_id')->where('r.ref_code', $list)->where('i.f_active', 1)->pluck('i.item_code')->all();
        $codes = array_values(array_unique(array_filter(array_map(fn ($x) => strtoupper(str_replace(' ', '_', trim((string) $x))), $rows))));
        return $codes ?: array_keys(self::LISTS[$list][1] ?? []);
    }

    public static function asOf(int $ent): ?string { $d = (string) Settings::get('OPENING_AS_OF_DATE', $ent, ''); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null; }
    public static function locked(int $ent): bool { return Settings::get('OPENING_LOCKED', $ent, 'N') === 'Y'; }

    public static function state(int $ent): array
    {
        $d = self::asOf($ent);
        return ['as_of' => $d, 'locked' => self::locked($ent), 'opening_lots' => (int) DB::table('fin_lots')->where('company_id', $ent)->where('source', 'OPENING')->count(),
            'opening_value' => round((float) DB::table('fin_lots')->where('company_id', $ent)->where('source', 'OPENING')->sum(DB::raw('qty * unit_cost_gbp')), 2),
            'lists' => collect(self::LISTS)->map(fn ($x, $k) => ['code' => $k, 'name' => $x[0], 'values' => self::statuses($k)])->values()->all()];
    }

    /** The as-of date can change until the balances are locked. */
    public static function setAsOf(int $ent, string $date, object $u): array
    {
        Perm::need($u, 'money.approve');
        if (self::locked($ent)) throw new \InvalidArgumentException('The opening balances are locked – the as-of date cannot change any more.');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! strtotime($date)) throw new \InvalidArgumentException('Enter the as-of date (year-month-day).');
        $old = self::asOf($ent); Settings::set('OPENING_AS_OF_DATE', $date, $ent, (int) $u->id);
        Audit::log('sys_settings', null, 'UPDATE', ['setting' => 'OPENING_AS_OF_DATE', 'from' => $old, 'to' => $date], 'Opening balances', $ent);
        return ['message' => "Opening as-of date set to $date."] + self::state($ent);
    }

    public const TYPES = ['BANK', 'PARTNER_CAPITAL', 'STOCK_VALUE', 'SUPPLIER_DUE', 'MARKETPLACE_RESERVE', 'LOAN', 'OPENING_LOT'];

    /**
     * R-FIN-20 one row of the cut-over wizard (Owner, before the lock). Each type books itself: BANK = the account's opening balance and date,
     * PARTNER_CAPITAL = CAPITAL_IN "OPENING" in the partner ledger (+ ledger 3000 → 2150), STOCK_VALUE 3000 → 1200, SUPPLIER_DUE 2000, MARKETPLACE_RESERVE 1120 (the shop),
     * LOAN 2400, OPENING_LOT = a FIFO lot source OPENING on the as-of date (qty × unit cost). The company as-of date = the latest as_of_date of its rows.
     */
    public static function addRow(int $ent, array $d, object $u): array
    {
        if (($u->role_code ?? '') !== 'OWNER') throw new \InvalidArgumentException('Only the Owner enters the opening balances.');
        if (self::locked($ent)) throw new \InvalidArgumentException('Opening balances are LOCKED for this company. Owner: unlock first (logged).');
        $type = strtoupper((string) ($d['type'] ?? '')); if (! in_array($type, self::TYPES, true)) throw new \InvalidArgumentException('Choose the type: ' . implode(', ', self::TYPES) . '.');
        $asOf = (string) ($d['as_of_date'] ?? ''); if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf) || ! strtotime($asOf)) throw new \InvalidArgumentException('as_of_date must be YYYY-MM-DD.');
        $ref = (int) ($d['ref_id'] ?? 0); $amount = round((float) ($d['amount'] ?? 0), 2);
        if ($type === 'BANK' && ! DB::table('fin_bank_accounts')->where('id', $ref)->where('company_id', $ent)->exists()) throw new \InvalidArgumentException('BANK needs the bank account (ref_id).');
        if ($type === 'PARTNER_CAPITAL' && ! $ref) throw new \InvalidArgumentException('PARTNER_CAPITAL needs the partner (ref_id = investor id).');
        if (in_array($type, ['LOAN', 'SUPPLIER_DUE'], true) && ! $ref) throw new \InvalidArgumentException("$type needs the party (ref_id = the lender / supplier account).");
        if ($type === 'MARKETPLACE_RESERVE' && ! DB::table('t_branches')->where('id', $ref)->where('fk_id_ent', $ent)->exists()) throw new \InvalidArgumentException('MARKETPLACE_RESERVE needs the shop (ref_id).');
        $sku = trim((string) ($d['sku'] ?? '')); $qty = (int) ($d['qty'] ?? 0); $cost = round((float) ($d['unit_cost'] ?? 0), 4);
        if ($type === 'OPENING_LOT') { if ($sku === '' || $qty <= 0 || $cost <= 0) throw new \InvalidArgumentException('An OPENING_LOT needs sku, qty (>0) and unit_cost (>0).'); $amount = round($qty * $cost, 2); }
        return DB::transaction(function () use ($ent, $d, $u, $type, $asOf, $ref, $amount, $sku, $qty, $cost) {
            $id = (int) DB::table('fin_opening_balances')->insertGetId(['company_id' => $ent, 'as_of_date' => $asOf, 'type' => $type, 'ref_id' => $ref ?: null, 'sku' => $sku ?: null, 'qty' => $qty ?: null, 'unit_cost' => $cost ?: null,
                'amount' => $amount, 'currency' => (string) ($d['currency'] ?? Fx::base($ent)), 'note' => mb_substr((string) ($d['note'] ?? ''), 0, 255) ?: null, 'created_by' => $u->id, 'created_at' => now()]);
            $gl = fn (string $dr, string $cr, ?int $refDr = null, ?int $refCr = null) => $amount != 0.0 ? Ledger::post($ent, $asOf, 'OPENING', $id, [['account' => $dr, 'debit' => abs($amount), 'ref_id' => $refDr], ['account' => $cr, 'credit' => abs($amount), 'ref_id' => $refCr]], ['memo' => "Opening balance $type"]) : null;
            match ($type) {
                'BANK' => DB::table('fin_bank_accounts')->where('id', $ref)->update(['opening_balance' => $amount, 'opening_date' => $asOf]),
                'PARTNER_CAPITAL' => [DB::table('fin_investor_ledger')->insert(['investor_id' => $ref, 'company_id' => $ent, 'entry_date' => $asOf, 'type' => 'CAPITAL_IN', 'amount' => $amount, 'reference_no' => 'OPENING', 'created_at' => now()]), $gl('3000', '2150', null, $ref)],
                'STOCK_VALUE' => $gl('1200', '3000'), 'SUPPLIER_DUE' => $gl('3000', '2000', null, $ref ?: null), 'MARKETPLACE_RESERVE' => $gl('1120', '3000', $ref), 'LOAN' => $gl('3000', '2400', null, $ref ?: null),
                'OPENING_LOT' => self::lot($ent, $id, $sku, $qty, $cost, $asOf, isset($d['po_id']) ? (int) $d['po_id'] : null),
            };
            $max = (string) DB::table('fin_opening_balances')->where('company_id', $ent)->max('as_of_date');
            if ($max !== self::asOf($ent)) Settings::set('OPENING_AS_OF_DATE', $max, $ent, (int) $u->id);
            Audit::log('fin_opening_balances', $id, 'INSERT', ['type' => $type, 'amount' => $amount, 'as_of' => $asOf], null, $ent);
            return ['id' => $id, 'message' => "Opening $type saved." . ($type === 'OPENING_LOT' ? " Lot of $qty unit(s) made." : '')] + self::state($ent);
        });
    }

    private static function lot(int $ent, int $rowId, string $sku, int $qty, float $cost, string $date, ?int $po): void
    {
        $p = DB::table('t_product_vendors')->where('fk_id_ent', $ent)->where('SKU_VENDOR', $sku)->value('id') ?? throw new \InvalidArgumentException("No product with SKU $sku.");
        $loc = DB::table('cat_inventory_locations')->where('fk_id_ent', $ent)->where('f_active', 1)->whereIn('kind', ['WAREHOUSE', 'BIN', 'SHELF'])->orderByRaw("kind = 'WAREHOUSE' DESC")->orderBy('id')->value('id') ?? throw new \InvalidArgumentException('Add a warehouse location first.');
        $lots = StockService::receive($ent, [['product' => (int) $p, 'qty' => $qty, 'location' => (int) $loc, 'unit_cost' => $cost, 'received_date' => $date, 'landed_status' => 'FINAL']], 'OPENING', $rowId, ['lot_source' => 'OPENING'])['lots'] ?? [];
        if ($po && $lots) DB::table('fin_lots')->whereIn('id', $lots)->update(['po_id' => $po]);
    }

    public static function rows(int $ent): array
    {
        return DB::table('fin_opening_balances')->where('company_id', $ent)->orderBy('as_of_date')->orderBy('id')->get()->all();
    }

    /** Unlock: Owner, with a reason, logged. */
    public static function unlock(int $ent, object $u, string $reason): array
    {
        if (($u->role_code ?? '') !== 'OWNER') throw new \InvalidArgumentException('Only the Owner unlocks the opening balances.');
        if (trim($reason) === '') throw new \InvalidArgumentException('Give the reason for unlocking.');
        Settings::set('OPENING_LOCKED', 'N', $ent, (int) $u->id); DB::table('fin_opening_locks')->where('company_id', $ent)->delete();
        Audit::log('sys_settings', null, 'UNLOCK', ['setting' => 'OPENING_LOCKED'], $reason, $ent);
        return ['message' => 'Opening balances unlocked.'] + self::state($ent);
    }

    /** Owner only, once: from now on GRNs dated on / after the as-of date make lots. */
    public static function lock(int $ent, object $u): array
    {
        if (($u->role_code ?? '') !== 'OWNER') throw new \InvalidArgumentException('Only the Owner locks the opening balances.');
        if (! DB::table('fin_opening_balances')->where('company_id', $ent)->exists() && ! DB::table('fin_lots')->where('company_id', $ent)->where('source', 'OPENING')->exists()) throw new \InvalidArgumentException('Nothing to lock: enter the opening balances first.');
        if (! self::asOf($ent)) throw new \InvalidArgumentException('Set the opening as-of date first.');
        if (self::locked($ent)) throw new \InvalidArgumentException('The opening balances are already locked.');
        Settings::set('OPENING_LOCKED', 'Y', $ent, (int) $u->id); DB::table('fin_opening_locks')->updateOrInsert(['company_id' => $ent], ['locked_at' => now(), 'locked_by' => $u->id]);
        Audit::log('sys_settings', null, 'LOCK', ['setting' => 'OPENING_LOCKED', 'as_of' => self::asOf($ent)], 'Opening balances locked', $ent);
        return ['message' => 'Opening balances locked. Receipts dated on or after ' . self::asOf($ent) . ' now make lots.'] + self::state($ent);
    }

    /** The gate of postGrn: null = make lots; 'BEFORE' = dated before the as-of date (no lot); else the refusal text. */
    public static function gate(int $ent, string $grnDate): ?string
    {
        if (! ($d = self::asOf($ent))) return null;      // a company without an opening (no old data) works as always
        if (! self::locked($ent)) return 'Lock the opening balances first (Month close › Opening balances) – lots are made only after that.';
        return $grnDate < $d ? 'BEFORE' : null;
    }
}
