<?php

namespace App\Http\Controllers;

use App\Services\Context;
use App\Services\CountService;
use App\Services\LocationGenerator;
use App\Services\PriceService;
use App\Services\Settings;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Stock screens: on hand, ledger, lots, and the actions that change stock (all through StockService). */
class StockController extends Controller
{
    private const SEE = ['OWNER', 'MANAGER', 'WAREHOUSE', 'SOURCING', 'FINANCE', 'ACCOUNTANT', 'PARTNER'];
    private const CHANGE = ['OWNER', 'MANAGER', 'WAREHOUSE'];
    private const MONEY = ['OWNER', 'MANAGER', 'FINANCE', 'ACCOUNTANT', 'PARTNER'];

    private function can(Request $r, array $roles): void
    {
        abort_unless(in_array($r->user()->role_code, $roles, true), 403, 'Your role cannot do this here. Ask the Owner if you need it.');
    }

    private function run(callable $fn)
    {
        try { return $fn(); }
        catch (\InvalidArgumentException|\RuntimeException $e) { return response()->json(['message' => $e->getMessage(), 'errors' => ['_form' => [$e->getMessage()]]], 422); }
    }

    public function onHand(Request $r)
    {
        $this->can($r, self::SEE);
        $ent = (int) Context::ent(); $money = in_array($r->user()->role_code, self::MONEY, true);
        $rows = StockService::onHand($ent, $r->query('q'));
        if (! $money) foreach ($rows as &$x) unset($x['value']);
        return ['rows' => $rows, 'show_value' => $money, 'total_value' => $money ? StockService::value($ent) : null, 'total_units' => array_sum(array_column($rows, 'units')),
            'low' => count(array_filter($rows, fn ($x) => $x['low'])), 'can_change' => in_array($r->user()->role_code, self::CHANGE, true)];
    }

    public function product(Request $r, int $id)
    {
        $this->can($r, self::SEE);
        abort_unless(DB::table('t_product_vendors')->where('id', $id)->where('fk_id_ent', Context::ent())->exists(), 404);
        $d = StockService::productStock($id);
        if (! in_array($r->user()->role_code, self::MONEY, true)) foreach ($d['rows'] as $row) unset($row->unit_cost_gbp);
        return $d;
    }

    public function ledger(Request $r)
    {
        $this->can($r, self::SEE);
        $q = DB::table('t_stock_movements as m')->join('t_product_vendors as p', 'p.id', '=', 'm.fk_id_product')->leftJoin('cat_inventory_locations as c', 'c.id', '=', 'm.fk_id_location')
            ->leftJoin('fin_lots as l', 'l.id', '=', 'm.fk_id_lot')->leftJoin('t_users as u', 'u.id', '=', 'm.created_by')->where('m.fk_id_ent', Context::ent());
        if ($v = $r->query('product')) $q->where('m.fk_id_product', (int) $v);
        if ($v = $r->query('type')) $q->where('m.movement_type', $v);
        if ($v = $r->query('q')) $q->where(fn ($w) => $w->where('p.SKU_VENDOR', 'like', "%$v%")->orWhere('l.lot_code', 'like', "%$v%"));
        $total = (clone $q)->count(); $page = max(1, (int) $r->query('page', 1));
        $cols = ['m.id', 'm.created_at', 'm.movement_type', 'm.qty_change', 'm.stock_after', 'm.source_type', 'm.source_id', 'm.notes', 'p.id as product_id', 'p.SKU_VENDOR as sku', 'c.location_name as location', 'l.lot_code', 'u.display_name as by'];
        if (in_array($r->user()->role_code, self::MONEY, true)) $cols[] = 'm.unit_cost_gbp';
        return ['total' => $total, 'page' => $page, 'rows' => $q->orderByDesc('m.id')->offset(($page - 1) * 50)->limit(50)->get($cols)];
    }

    public function lots(Request $r)
    {
        $this->can($r, self::SEE);
        $q = DB::table('fin_lots as l')->join('t_product_vendors as p', 'p.id', '=', 'l.fk_id_product')->leftJoin('t_account as a', 'a.id', '=', 'l.fk_id_owner')->where('l.company_id', Context::ent());
        if ($r->query('left', '1') === '1') $q->where('l.qty_left', '>', 0);
        if ($v = $r->query('q')) $q->where(fn ($w) => $w->where('l.sku', 'like', "%$v%")->orWhere('l.lot_code', 'like', "%$v%"));
        $money = in_array($r->user()->role_code, self::MONEY, true);
        $cols = ['l.id', 'l.lot_code', 'l.sku', 'p.id as product_id', 'p.product_name_vendor as name', 'l.source', 'l.received_date', 'l.qty', 'l.qty_left', 'l.visibility', 'l.landed_status', DB::raw("TRIM(CONCAT_WS(' ', a.first_name, a.sur_name)) as owner")];
        if ($money) $cols[] = 'l.unit_cost_gbp';
        return ['rows' => $q->orderBy('l.received_date')->orderBy('l.id')->limit(500)->get($cols), 'show_value' => $money];
    }

    /** Dropdown data for the stock action forms. */
    public function pick(Request $r)
    {
        $this->can($r, self::SEE);
        $ent = Context::ent(); $q = $r->query('q');
        return [
            'products' => DB::table('t_product_vendors')->where('fk_id_ent', $ent)->where('f_active', 1)->when($q, fn ($x) => $x->where(fn ($w) => $w->where('SKU_VENDOR', 'like', "%$q%")->orWhere('product_name_vendor', 'like', "%$q%")))
                ->orderBy('SKU_VENDOR')->limit(300)->get(['id', 'SKU_VENDOR as sku', 'product_name_vendor as name']),
            'locations' => DB::table('cat_inventory_locations')->where(fn ($w) => $w->where('fk_id_ent', $ent)->orWhereNull('fk_id_ent'))->where(fn ($w) => $w->where('f_active', 1)->orWhereNull('f_active'))->orderBy('location_name')->limit(500)->get(['id', 'location_name as name', 'kind']),
            'owners' => DB::table('t_account')->where('fk_id_ent', $ent)->where('f_active', 1)->orderBy('first_name')->limit(300)->get(['id', DB::raw("TRIM(CONCAT_WS(' ', first_name, sur_name)) as name")]),
        ];
    }

    public function opening(Request $r)
    {
        $this->can($r, ['OWNER', 'MANAGER']);
        $d = $r->validate(['product' => 'required|integer', 'location' => 'required|integer', 'qty' => 'required|integer|min:1', 'unit_cost' => 'required|numeric|min:0', 'owner' => 'nullable|integer', 'received_date' => 'required|date', 'visibility' => 'nullable|in:POOL,PRIVATE']);
        return $this->run(fn () => StockService::receive((int) Context::ent(), [array_filter($d, fn ($v) => $v !== null)], 'OPENING', null, ['lot_source' => 'OPENING']) + ['message' => 'Opening stock saved as a lot']);
    }

    public function transfer(Request $r)
    {
        $this->can($r, self::CHANGE);
        $d = $r->validate(['product' => 'required|integer', 'qty' => 'required|integer|min:1', 'from' => 'required|integer', 'to' => 'required|integer|different:from', 'reason' => 'nullable|string|max:255'], ['to.different' => 'Choose two different locations.']);
        return $this->run(function () use ($d) { StockService::transfer((int) Context::ent(), $d['product'], $d['qty'], $d['from'], $d['to'], $d['reason'] ?? null); return ['message' => "Moved {$d['qty']} unit(s)"]; });
    }

    public function adjust(Request $r)
    {
        $this->can($r, self::CHANGE);
        $d = $r->validate(['product' => 'required|integer', 'location' => 'required|integer', 'qty' => 'required|integer|not_in:0', 'reason' => 'required|string|min:3|max:255', 'unit_cost' => 'nullable|numeric|min:0'], ['qty.not_in' => 'The change cannot be zero.', 'reason.required' => 'Give the reason for the adjustment.']);
        return $this->run(function () use ($d) { $x = StockService::adjust((int) Context::ent(), $d['product'], $d['location'], $d['qty'], $d['reason'], array_filter(['unit_cost' => $d['unit_cost'] ?? null], fn ($v) => $v !== null)); return ['message' => 'Stock adjusted', 'value' => $x['value']]; });
    }

    public function ownership(Request $r)
    {
        $this->can($r, ['OWNER', 'MANAGER']);
        $d = $r->validate(['lot' => 'required|integer', 'qty' => 'required|integer|min:1', 'to_owner' => 'required|integer']);
        return $this->run(fn () => ['lot' => StockService::transferOwnership((int) Context::ent(), $d['lot'], $d['qty'], $d['to_owner']), 'message' => 'Units given to the new owner (lot split)']);
    }

    public function check(Request $r)
    {
        $this->can($r, ['OWNER', 'MANAGER']);
        return StockService::selfCheck((int) Context::ent());
    }

    // ---- pool, held stock ----
    public function pool(Request $r)
    {
        $this->can($r, self::MONEY);
        return ['rows' => StockService::pool((int) Context::ent())];
    }

    public function held(Request $r)
    {
        $this->can($r, self::SEE);
        $rows = StockService::held((int) Context::ent());
        if (! in_array($r->user()->role_code, self::MONEY, true)) foreach ($rows as $x) unset($x->value);
        return ['rows' => $rows, 'can_change' => in_array($r->user()->role_code, self::CHANGE, true)];
    }

    // ---- stock counts ----
    public function counts(Request $r)
    {
        $this->can($r, self::SEE);
        return ['rows' => DB::table('t_stock_counts as c')->leftJoin('cat_inventory_locations as l', 'l.id', '=', 'c.fk_id_location')->leftJoin('t_users as u', 'u.id', '=', 'c.counted_by')->where('c.fk_id_ent', Context::ent())
            ->orderByDesc('c.id')->limit(200)->get(['c.id', 'c.count_code', 'c.d_count', 'c.status', 'c.variance_value', 'l.location_name as location', 'u.display_name as counted_by']), 'can_change' => in_array($r->user()->role_code, self::CHANGE, true)];
    }

    public function count(Request $r, int $id)
    {
        $this->can($r, self::SEE);
        $c = DB::table('t_stock_counts as c')->leftJoin('cat_inventory_locations as l', 'l.id', '=', 'c.fk_id_location')->where('c.id', $id)->where('c.fk_id_ent', Context::ent())->first(['c.*', 'l.location_name as location']) ?? abort(404);
        $blind = $c->f_blind && $c->status === 'OPEN';        // blind count: the counter does not see the system number until he submits
        $lines = DB::table('t_stock_count_lines as l')->join('t_product_vendors as p', 'p.id', '=', 'l.fk_id_product')->where('l.fk_id_count', $id)->orderBy('p.SKU_VENDOR')
            ->get(['l.fk_id_product as product_id', 'p.SKU_VENDOR as sku', 'p.product_name_vendor as name', 'l.qty_system', 'l.qty_counted', 'l.note']);
        if ($blind) foreach ($lines as $l) $l->qty_system = null;
        return ['count' => ['id' => $c->id, 'code' => $c->count_code, 'status' => $c->status, 'location' => $c->location, 'date' => $c->d_count, 'variance_value' => $c->variance_value, 'blind' => (bool) $blind, 'mine' => (int) $c->counted_by === $r->user()->id], 'lines' => $lines];
    }

    public function countOpen(Request $r)
    {
        $this->can($r, self::CHANGE);
        $d = $r->validate(['location' => 'required|integer']);
        return $this->run(fn () => ['id' => CountService::open((int) Context::ent(), $d['location'], null, Settings::get('WH_COUNT_BLIND', Context::ent(), 'Y') === 'Y'), 'message' => 'Count opened']);
    }

    public function countEnter(Request $r, int $id)
    {
        $this->can($r, self::CHANGE);
        $d = $r->validate(['lines' => 'required|array', 'lines.*.product' => 'required|integer', 'lines.*.qty' => 'required|integer|min:0', 'lines.*.note' => 'nullable|string|max:255']);
        return $this->run(function () use ($d, $id) { foreach ($d['lines'] as $l) CountService::enter($id, $l['product'], $l['qty'], $l['note'] ?? null); return ['message' => 'Count saved']; });
    }

    public function countStep(Request $r, int $id, string $step)
    {
        $this->can($r, in_array($step, ['approve', 'post'], true) ? ['OWNER', 'MANAGER'] : self::CHANGE);
        return $this->run(fn () => match ($step) {
            'submit' => CountService::submit($id) + ['message' => 'Count submitted for approval'],
            'approve' => (function () use ($id) { CountService::approve($id); return ['message' => 'Count approved']; })(),
            'post' => CountService::post($id) + ['message' => 'Differences posted to stock'],
            'cancel' => (function () use ($id) { CountService::cancel($id); return ['message' => 'Count cancelled']; })(),
            default => abort(404),
        });
    }

    // ---- prices ----
    public function prices(Request $r, int $productId)
    {
        $this->can($r, ['OWNER', 'MANAGER', 'FINANCE', 'ACCOUNTANT', 'VA', 'TL']);
        abort_unless(DB::table('t_product_vendors')->where('id', $productId)->where('fk_id_ent', Context::ent())->exists(), 404);
        $lc = \App\Services\Registry::labelColumn('cat_marketplaces');
        return ['timeline' => PriceService::timeline($productId), 'marketplaces' => DB::table('cat_marketplaces')->limit(100)->get(['id', "$lc as name"]),
            'reasons' => DB::table('cat_price_change_reasons')->where('f_active', 1)->get(['id', 'reason_name as name'])];
    }

    public function setPrice(Request $r, int $productId)
    {
        $this->can($r, ['OWNER', 'MANAGER', 'TL']);
        abort_unless(DB::table('t_product_vendors')->where('id', $productId)->where('fk_id_ent', Context::ent())->exists(), 404);
        $d = $r->validate(['marketplace' => 'required|integer', 'raw_price' => 'required|numeric|min:0', 'sale_price' => 'required|numeric|min:0', 'from_date' => 'required|date', 'reason' => 'nullable|integer']);
        return $this->run(fn () => ['id' => PriceService::set($productId, $d['marketplace'], (float) $d['raw_price'], (float) $d['sale_price'], $d['from_date'], $d['reason'] ?? null), 'message' => 'Price saved from ' . $d['from_date']]);
    }

    // ---- bins ----
    public function bins(Request $r)
    {
        $this->can($r, ['OWNER', 'MANAGER']);
        $d = $r->validate(['parent' => 'required|integer', 'zone' => 'required|string|max:6', 'levels' => 'required|integer|min:1', 'positions' => 'required|integer|min:1', 'create' => 'boolean']);
        return $this->run(function () use ($d) {
            $x = LocationGenerator::run((int) Context::ent(), $d, (bool) ($d['create'] ?? false));
            $pj = ! empty($x['ids']) ? \App\Services\PrintService::auto((int) Context::ent(), 'WH_AUTOPRINT_SHELF', 'LOCATION', $x['ids'], 'AUTO_SHELF') : null;
            if ($pj && $pj['status'] === 'PRINTED') $x['print_url'] = "/api/print-jobs/{$pj['job']}/pdf";
            return $x + ['message' => ! empty($d['create']) ? 'Bins created' . (isset($x['print_url']) ? ' – bin labels are ready to print' : '') : 'Preview'];
        });
    }
}
