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

    private function can(Request $r, string $code): void
    {
        \App\Services\Perm::need($r->user(), $code);
    }

    private function run(callable $fn)
    {
        try { return $fn(); }
        catch (\Illuminate\Database\QueryException $e) { report($e); return response()->json(['message' => 'Something went wrong on our side. Nothing was saved. The details are in the system log.'], 500); }   // never show database text to a user
        catch (\InvalidArgumentException|\RuntimeException $e) { return response()->json(['message' => $e->getMessage(), 'errors' => ['_form' => [$e->getMessage()]]], 422); }
    }

    /** Products not listed per shop (R-D-58). */
    public function notListed(Request $r)
    {
        \App\Services\Perm::need($r->user(), 'stock.notListed'); $ent = (int) Context::ent(); \App\Services\NotListed::closeDone($ent);
        $f = $r->only(['shop', 'type', 'preset', 'packing']);
        return \App\Services\NotListed::summary($ent, $f) + ['lines' => ! empty($f['shop']) || $r->boolean('lines') ? \App\Services\NotListed::lines($ent, $f) : null, 'can_task' => \App\Services\Perm::has($r->user(), 'stock.notListed')];
    }
    public function notListedTasks(Request $r)
    {
        \App\Services\Perm::need($r->user(), 'stock.notListed');
        $pairs = array_values(array_filter(array_map(fn ($p) => is_array($p) && count($p) === 2 ? [(int) $p[0], (int) $p[1]] : null, (array) $r->input('pairs', []))));
        return \App\Services\NotListed::createTasks((int) Context::ent(), $pairs, $r->only(['shop', 'type', 'preset', 'packing']));
    }

    /** Products list (R-D-35). */
    public function products(Request $r)
    {
        \App\Services\Perm::need($r->user(), 'stock.SEE');
        $f = $r->only(['q', 'status', 'mp', 'set', 'page', 'per']); $v = \App\Services\ProductsList::view($r->user());
        if (! $r->has('per')) $f += ['per' => $v['per'], 'set' => $v['set'], 'status' => $v['status'], 'mp' => $v['mp'] ?? null, 'q' => $v['q'] ?? ''];      // the person's default view
        return \App\Services\ProductsList::page((int) Context::ent(), $f, $r->user());
    }
    /** Investor ids are company parties (R-D-80). */
    public function investorMap(Request $r) { \App\Services\Perm::need($r->user(), 'products.investors'); return \App\Services\InvestorParties::migrate(true) + ['waiting_review' => \App\Services\InvestorParties::reviewList()]; }
    public function investorMapRun(Request $r) { \App\Services\Perm::need($r->user(), 'products.investors'); return $this->run(fn () => \Illuminate\Support\Facades\DB::transaction(fn () => \App\Services\InvestorParties::migrate(false))); }
    public function productInvestors(Request $r, int $id) { \App\Services\Perm::need($r->user(), 'products.investors'); return ['selected' => \App\Services\InvestorParties::ofProduct($id), 'options' => \App\Services\InvestorParties::options((int) Context::ent(), $r->query('q')), 'sku' => \Illuminate\Support\Facades\DB::table('t_product_vendors')->where('id', $id)->value('SKU_VENDOR')]; }
    public function productInvestorsSave(Request $r, int $id) { \App\Services\Perm::need($r->user(), 'products.investors'); return $this->run(fn () => \App\Services\InvestorParties::save($id, (array) $r->input('ids', []))); }

    /** Simple / full warehouse by settings (R-H-7) and the putaway step. */
    public function whMode(Request $r) { \App\Services\Perm::need($r->user(), 'stock.bins'); return \App\Services\WarehouseMode::page((int) Context::ent()); }
    public function whModeApply(Request $r) { \App\Services\Perm::need($r->user(), 'stock.bins'); return $this->run(fn () => \App\Services\WarehouseMode::apply((int) Context::ent(), (string) $r->input('preset'), $r->user())); }
    public function putaways(Request $r) { \App\Services\Perm::need($r->user(), 'stock.CHANGE'); return ['rows' => \App\Services\WarehouseMode::putaways((int) Context::ent()), 'bins' => \Illuminate\Support\Facades\DB::table('cat_inventory_locations')->where('fk_id_ent', Context::ent())->where('kind', 'BIN')->where('f_active', 1)->orderBy('pick_seq')->limit(500)->get(['id', 'location_name as name'])]; }
    public function putaway(Request $r, int $id) { \App\Services\Perm::need($r->user(), 'stock.CHANGE'); return $this->run(fn () => \App\Services\WarehouseMode::putaway((int) Context::ent(), $id, (int) $r->input('bin'), $r->filled('qty') ? (int) $r->input('qty') : null)); }

    /** SKU live check (R-D-2) for the child cards of a parent. */
    public function skuCheck(Request $r) { $this->can($r, 'stock.SEE'); return \App\Services\SkuCheck::check((int) Context::ent(), (string) $r->query('sku', ''), $r->integer('id') ?: null); }

    /** Stock alerts page (R-D-46). */
    public function stockAlerts(Request $r) { $this->can($r, 'stock.SEE'); return \App\Services\StockAlerts::page((int) Context::ent()) + ['can_change' => \App\Services\Perm::has($r->user(), 'stock.CHANGE'), 'can_buy' => \App\Services\Perm::has($r->user(), 'buying.BUY')]; }
    public function stockAlertUpdate(Request $r, int $id)
    {
        $this->can($r, 'stock.CHANGE');
        try { return \App\Services\StockAlerts::update((int) Context::ent(), $id, $r->input('location') ? (int) $r->input('location') : null, $r->input('new_qty'), (string) $r->input('reason', ''), $r->input('notes'), \App\Services\Perm::has($r->user(), 'stock.adjust_owner')); }
        catch (\App\Services\ValidationErrors $e) { return response()->json(['message' => $e->getMessage(), 'errors' => array_map(fn ($m) => [$m], $e->errors)], 422); }
        catch (\InvalidArgumentException|\RuntimeException $e) { return response()->json(['message' => $e->getMessage(), 'errors' => ['_form' => [$e->getMessage()]]], 422); }
    }

    /** Inbound shipment prep (R-H-49). */
    public function inboundList(Request $r)
    {
        \App\Services\Perm::need($r->user(), 'stock.SEE'); $ent = (int) Context::ent();
        return ['rows' => \App\Services\InboundShipments::list($ent), 'can_change' => \App\Services\Perm::has($r->user(), 'stock.CHANGE'),
            'shops' => \App\Services\Perm::mine(\Illuminate\Support\Facades\DB::table('t_branches'), 'id')->where('f_active', 1)->orderBy('shop_no')->get(['id', \Illuminate\Support\Facades\DB::raw("TRIM(CONCAT_WS(' ', shop_no, shop_name)) as name")]),
            'locations' => \Illuminate\Support\Facades\DB::table('cat_inventory_locations')->where('fk_id_ent', $ent)->where('f_active', 1)->where(fn ($w) => $w->whereNull('kind')->orWhereIn('kind', ['WAREHOUSE', 'SHELF', 'MARKETPLACE_FULFILMENT', 'THREE_PL', 'FBA', '3PL', 'TRANSIT', 'CUSTOMER']))->orderBy('location_name')->get(['id', 'location_name as name', 'kind'])];
    }
    public function inboundShow(Request $r, int $id) { \App\Services\Perm::need($r->user(), 'stock.SEE'); return $this->run(fn () => \App\Services\InboundShipments::page((int) Context::ent(), $id) + ['can_change' => \App\Services\Perm::has($r->user(), 'stock.CHANGE')]); }
    public function inboundCreate(Request $r)
    {
        \App\Services\Perm::need($r->user(), 'stock.CHANGE');
        $shops = \App\Services\Perm::shops($r->user()); if ($shops !== null && ! in_array((int) $r->input('shop'), $shops, true)) return response()->json(['message' => 'This shop is not yours.', 'errors' => ['shop' => ['This shop is not yours.']]], 422);
        try { return \App\Services\InboundShipments::create((int) Context::ent(), $r->all()); }
        catch (\App\Services\ValidationErrors $e) { return response()->json(['message' => $e->getMessage(), 'errors' => array_map(fn ($m) => [$m], $e->errors)], 422); }
    }
    public function inboundAction(Request $r, int $id, string $act)
    {
        \App\Services\Perm::need($r->user(), 'stock.CHANGE'); $ent = (int) Context::ent();
        return $this->run(fn () => match ($act) {
            'reserve' => \App\Services\InboundShipments::reserve($ent, $id),
            'pick' => \App\Services\InboundShipments::pick($ent, $id, (string) $r->input('carton', ''), (string) $r->input('item', ''), (int) $r->input('qty', 1)),
            'ship' => \App\Services\InboundShipments::shipped($ent, $id, $r->only(['date', 'carrier', 'tracking'])),
            'receive' => \App\Services\InboundShipments::received($ent, $id, (array) $r->input('received', [])),
            default => abort(404),
        });
    }

    /** Visibility per marketplace × shop (R-D-20). */
    public function visibility(Request $r, int $id) { \App\Services\Perm::need($r->user(), 'form:product_visibility:VIEW'); return \App\Services\ProductVisibility::get((int) Context::ent(), $id) + ['can_edit' => \App\Services\Perm::has($r->user(), 'form:product_visibility:EDIT'), 'sku' => \Illuminate\Support\Facades\DB::table('t_product_vendors')->where('id', $id)->value('SKU_VENDOR')]; }
    public function visibilitySave(Request $r, int $id)
    {
        \App\Services\Perm::need($r->user(), 'form:product_visibility:EDIT');
        try { return \App\Services\ProductVisibility::save((int) Context::ent(), $id, (array) $r->input('tab', []), $r->user()); }
        catch (\App\Services\ValidationErrors $e) { return response()->json(['message' => $e->getMessage(), 'errors' => array_map(fn ($m) => [$m], $e->errors)], 422); }
        catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); }
    }

    /** Hide, delete, restore (R-D-9). */
    public function productHide(Request $r, int $id) { \App\Services\Perm::need($r->user(), 'products.hide'); return $this->run(fn () => \App\Services\ProductArchive::hide($id, (string) $r->input('reason'), $r->user())); }
    public function productDelete(Request $r, int $id) { \App\Services\Perm::need($r->user(), 'products.delete'); return $this->run(fn () => \App\Services\ProductArchive::delete($id, $r->user())); }
    public function productRestore(Request $r, int $id) { \App\Services\Perm::need($r->user(), 'products.hide'); if (\Illuminate\Support\Facades\DB::table('t_product_vendors')->where('id', $id)->value('row_state') === 'DELETED') \App\Services\Perm::need($r->user(), 'products.delete'); return $this->run(fn () => \App\Services\ProductArchive::restore($id, $r->user())); }
    public function productBin(Request $r) { \App\Services\Perm::need($r->user(), 'products.hide'); return ['rows' => \App\Services\ProductArchive::bin((int) Context::ent()), 'can_delete' => \App\Services\Perm::has($r->user(), 'products.delete')]; }
    public function productsView(Request $r) { return \App\Services\ProductsList::saveView($r->user(), $r->all()); }
    public function productsExport(Request $r, string $format)
    {
        \App\Services\Perm::need($r->user(), 'stock.SEE');
        $x = \App\Services\ProductsList::export((int) Context::ent(), $r->only(['q', 'status', 'mp', 'set', 'hidden']), $format, $r->user());
        return response($x['body'], 200, ['Content-Type' => $x['type'], 'Content-Disposition' => 'attachment; filename="' . $x['name'] . '"']);
    }

    public function onHand(Request $r)
    {
        $this->can($r, 'stock.SEE');
        $ent = (int) Context::ent(); $money = \App\Services\Perm::has($r->user(), 'stock.MONEY');
        $rows = StockService::onHand($ent, $r->query('q'));
        if (! $money) foreach ($rows as &$x) unset($x['value']);
        return ['rows' => $rows, 'show_value' => $money, 'total_value' => $money ? StockService::value($ent) : null, 'total_units' => (int) \Illuminate\Support\Facades\DB::table('fin_lot_balances as b')->join('fin_lots as l', 'l.id', '=', 'b.fk_id_lot')->where('l.company_id', $ent)->sum('b.qty'),   // the whole company, not only the rows shown
            'low' => \Illuminate\Support\Facades\DB::table('t_product_vendors')->where('fk_id_ent', $ent)->where('min_level', '>', 0)->whereColumn('stock', '<=', 'min_level')->count(),
            'shown' => count($rows), 'products' => \Illuminate\Support\Facades\DB::table('t_product_vendors')->where('fk_id_ent', $ent)->where('stock', '<>', 0)->count(), 'can_change' => \App\Services\Perm::has($r->user(), 'stock.CHANGE')];
    }

    public function product(Request $r, int $id)
    {
        $this->can($r, 'stock.SEE');
        abort_unless(DB::table('t_product_vendors')->where('id', $id)->where('fk_id_ent', Context::ent())->exists(), 404);
        $d = StockService::productStock($id);
        if (! \App\Services\Perm::has($r->user(), 'stock.MONEY')) foreach ($d['rows'] as $row) unset($row->unit_cost_gbp);
        return $d;
    }

    public function ledger(Request $r)
    {
        $this->can($r, 'stock.SEE');
        $q = DB::table('t_stock_movements as m')->join('t_product_vendors as p', 'p.id', '=', 'm.fk_id_product')->leftJoin('cat_inventory_locations as c', 'c.id', '=', 'm.fk_id_location')
            ->leftJoin('fin_lots as l', 'l.id', '=', 'm.fk_id_lot')->leftJoin('t_users as u', 'u.id', '=', 'm.created_by')->where('m.fk_id_ent', Context::ent());
        if ($v = $r->query('product')) $q->where('m.fk_id_product', (int) $v);
        if ($v = $r->query('type')) $q->where('m.movement_type', $v);
        if ($v = $r->query('q')) $q->where(fn ($w) => $w->where('p.SKU_VENDOR', 'like', "%$v%")->orWhere('l.lot_code', 'like', "%$v%"));
        $total = (clone $q)->count(); $page = max(1, (int) $r->query('page', 1));
        $cols = ['m.id', 'm.created_at', 'm.movement_type', 'm.qty_change', 'm.stock_after', 'm.source_type', 'm.source_id', 'm.notes', 'p.id as product_id', 'p.SKU_VENDOR as sku', 'c.location_name as location', 'l.lot_code', 'u.display_name as by'];
        if (\App\Services\Perm::has($r->user(), 'stock.MONEY')) $cols[] = 'm.unit_cost_gbp';
        return ['total' => $total, 'page' => $page, 'rows' => $q->orderByDesc('m.id')->offset(($page - 1) * 50)->limit(50)->get($cols)];
    }

    public function lots(Request $r)
    {
        $this->can($r, 'stock.SEE');
        $q = DB::table('fin_lots as l')->join('t_product_vendors as p', 'p.id', '=', 'l.fk_id_product')->leftJoin('t_account as a', 'a.id', '=', 'l.fk_id_owner')->where('l.company_id', Context::ent());
        if ($r->query('left', '1') === '1') $q->where('l.qty_left', '>', 0);
        if ($v = $r->query('q')) $q->where(fn ($w) => $w->where('l.sku', 'like', "%$v%")->orWhere('l.lot_code', 'like', "%$v%"));
        if ($r->query('nocost') === '1') $q->where('l.unit_cost_gbp', '<=', 0);
        $money = \App\Services\Perm::has($r->user(), 'stock.MONEY');
        $cols = ['l.id', 'l.lot_code', 'l.sku', 'p.id as product_id', 'p.product_name_vendor as name', 'l.source', 'l.received_date', 'l.qty', 'l.qty_left', 'l.visibility', 'l.landed_status', 'l.po_id', DB::raw("TRIM(CONCAT_WS(' ', a.first_name, a.sur_name)) as owner")];
        if ($money) $cols[] = 'l.unit_cost_gbp';
        $noCost = DB::table('fin_lots')->where('company_id', Context::ent())->where('qty_left', '>', 0)->where('unit_cost_gbp', '<=', 0)->count();
        $rows = $q->orderBy('l.received_date')->orderBy('l.id')->limit(500)->get($cols); $qc = \App\Services\SourcingExtras::qcOfPos($rows->pluck('po_id')->filter()->unique()->values()->all());
        foreach ($rows as $l) $l->qc = $l->po_id ? ($qc[$l->po_id] ?? null) : null;   // R-B_SOURCING-65: the lot shows the latest QC of its PO
        return ['rows' => $rows, 'show_value' => $money, 'no_cost' => $noCost, 'can_cost' => \App\Services\Perm::has($r->user(), 'stock.lots_may')];
    }

    public function lotCost(Request $r, int $id)
    {
        $this->can($r, 'stock.lotCost');
        $d = $r->validate(['cost' => 'required|numeric|min:0', 'reason' => 'required|string|min:3|max:200']);
        return $this->run(function () use ($id, $d) { $x = StockService::setLotCost((int) Context::ent(), $id, (float) $d['cost'], $d['reason']); return $x + ['message' => 'Cost saved' . ($x['stock_value_change'] ? ' – stock value changed by ' . number_format($x['stock_value_change'], 2) : '')]; });
    }

    /** Dropdown data for the stock action forms. */
    public function pick(Request $r)
    {
        $this->can($r, 'stock.SEE');
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
        $this->can($r, 'stock.opening');
        $d = $r->validate(['product' => 'required|integer', 'location' => 'required|integer', 'qty' => 'required|integer|min:1', 'unit_cost' => 'required|numeric|min:0', 'owner' => 'nullable|integer', 'received_date' => 'required|date', 'visibility' => 'nullable|in:POOL,PRIVATE']);
        return $this->run(fn () => StockService::receive((int) Context::ent(), [array_filter($d, fn ($v) => $v !== null)], 'OPENING', null, ['lot_source' => 'OPENING']) + ['message' => 'Opening stock saved as a lot']);
    }

    public function transfer(Request $r)
    {
        $this->can($r, 'stock.CHANGE');
        $d = $r->validate(['product' => 'required|integer', 'qty' => 'required|integer|min:1', 'from' => 'required|integer', 'to' => 'required|integer|different:from', 'reason' => 'nullable|string|max:255'], ['to.different' => 'Choose two different locations.']);
        return $this->run(function () use ($d) { StockService::transfer((int) Context::ent(), $d['product'], $d['qty'], $d['from'], $d['to'], $d['reason'] ?? null); return ['message' => "Moved {$d['qty']} unit(s)"]; });
    }

    public function adjust(Request $r)
    {
        $this->can($r, 'stock.CHANGE');
        $d = $r->validate(['product' => 'required|integer', 'location' => 'required|integer', 'qty' => 'required|integer|not_in:0', 'reason' => 'required|string|min:3|max:255', 'unit_cost' => 'nullable|numeric|min:0'], ['qty.not_in' => 'The change cannot be zero.', 'reason.required' => 'Give the reason for the adjustment.']);
        return $this->run(function () use ($d, $r) {
            $ent = (int) Context::ent(); $limit = (int) \App\Services\Settings::get('ADJUST_APPROVAL_QTY', $ent, 50);
            if (! \App\Services\Perm::has($r->user(), 'stock.adjust_owner') && abs((int) $d['qty']) > $limit) {   // a large change waits for a second person; stock does not move until then
                $p = \Illuminate\Support\Facades\DB::table('t_product_vendors')->where('id', $d['product'])->where('fk_id_ent', $ent)->first() ?? abort(404);
                \App\Services\Approvals::request($ent, 'STOCK_ADJUST', 't_product_vendors', (int) $p->id, 0, ['category' => "Stock adjustment {$p->SKU_VENDOR}: " . ((int) $d['qty'] > 0 ? '+' : '') . (int) $d['qty'] . ' units', 'note' => $d['reason'], 'adjust' => $d], "Approve stock adjustment {$p->SKU_VENDOR}: " . (int) $d['qty'] . ' units');
                return ['message' => "More than $limit units – the adjustment waits for approval and stock has not changed yet", 'status' => 'WAITING'];
            }
            $x = StockService::adjust($ent, $d['product'], $d['location'], $d['qty'], $d['reason'], array_filter(['unit_cost' => $d['unit_cost'] ?? null], fn ($v) => $v !== null)); return ['message' => 'Stock adjusted', 'value' => $x['value']]; });
    }

    public function ownership(Request $r)
    {
        $this->can($r, 'stock.ownership');
        $d = $r->validate(['lot' => 'required|integer', 'qty' => 'required|integer|min:1', 'to_owner' => 'required|integer']);
        return $this->run(fn () => ['lot' => StockService::transferOwnership((int) Context::ent(), $d['lot'], $d['qty'], $d['to_owner']), 'message' => 'Units given to the new owner (lot split)']);
    }

    public function check(Request $r)
    {
        $this->can($r, 'stock.check');
        return StockService::selfCheck((int) Context::ent());
    }

    // ---- pool, held stock ----
    public function pool(Request $r)
    {
        $this->can($r, 'stock.MONEY');
        return ['rows' => StockService::pool((int) Context::ent())];
    }

    public function held(Request $r)
    {
        $this->can($r, 'stock.SEE');
        $rows = StockService::held((int) Context::ent());
        if (! \App\Services\Perm::has($r->user(), 'stock.MONEY')) foreach ($rows as $x) unset($x->value);
        return ['rows' => $rows, 'can_change' => \App\Services\Perm::has($r->user(), 'stock.CHANGE')];
    }

    // ---- stock counts ----
    public function counts(Request $r)
    {
        $this->can($r, 'stock.SEE');
        return ['rows' => DB::table('t_stock_counts as c')->leftJoin('cat_inventory_locations as l', 'l.id', '=', 'c.fk_id_location')->leftJoin('t_users as u', 'u.id', '=', 'c.counted_by')->where('c.fk_id_ent', Context::ent())
            ->orderByDesc('c.id')->limit(200)->get(['c.id', 'c.count_code', 'c.d_count', 'c.status', 'c.variance_value', 'l.location_name as location', 'u.display_name as counted_by']), 'can_change' => \App\Services\Perm::has($r->user(), 'stock.CHANGE')];
    }

    public function count(Request $r, int $id)
    {
        $this->can($r, 'stock.SEE');
        $c = DB::table('t_stock_counts as c')->leftJoin('cat_inventory_locations as l', 'l.id', '=', 'c.fk_id_location')->where('c.id', $id)->where('c.fk_id_ent', Context::ent())->first(['c.*', 'l.location_name as location']) ?? abort(404);
        $blind = $c->f_blind && $c->status === 'OPEN';        // blind count: the counter does not see the system number until he submits
        $lines = DB::table('t_stock_count_lines as l')->join('t_product_vendors as p', 'p.id', '=', 'l.fk_id_product')->where('l.fk_id_count', $id)->orderBy('p.SKU_VENDOR')
            ->get(['l.fk_id_product as product_id', 'p.SKU_VENDOR as sku', 'p.product_name_vendor as name', 'l.qty_system', 'l.qty_counted', 'l.note', 'l.qty_recount', 'l.counted_by']);
        $me = (int) $r->user()->id;
        foreach ($lines as $l) {      // a difference above the limits needs a recount by another person, who does not see the first number (R-H-46)
            $l->needs_recount = $l->qty_counted !== null && CountService::needsRecount((int) $l->qty_system, (int) $l->qty_counted, (int) Context::ent()); $l->counted_by_me = (int) $l->counted_by === $me;
            if ($l->needs_recount && ! $l->counted_by_me && $l->qty_recount === null && $c->status === 'OPEN') $l->qty_counted = null; unset($l->counted_by);
        }
        if ($blind) foreach ($lines as $l) $l->qty_system = null;
        return ['count' => ['id' => $c->id, 'code' => $c->count_code, 'status' => $c->status, 'location' => $c->location, 'date' => $c->d_count, 'variance_value' => $c->variance_value, 'blind' => (bool) $blind, 'mine' => (int) $c->counted_by === $r->user()->id], 'lines' => $lines];
    }

    public function countOpen(Request $r)
    {
        $this->can($r, 'stock.CHANGE');
        $d = $r->validate(['location' => 'required|integer']);
        return $this->run(fn () => ['id' => CountService::open((int) Context::ent(), $d['location'], null, Settings::get('WH_COUNT_BLIND', Context::ent(), 'Y') === 'Y'), 'message' => 'Count opened']);
    }

    public function countEnter(Request $r, int $id)
    {
        $this->can($r, 'stock.CHANGE');
        $d = $r->validate(['lines' => 'required|array', 'lines.*.product' => 'required|integer', 'lines.*.qty' => 'required|integer|min:0', 'lines.*.note' => 'nullable|string|max:255']);
        return $this->run(function () use ($d, $id) { foreach ($d['lines'] as $l) CountService::enter($id, $l['product'], $l['qty'], $l['note'] ?? null); return ['message' => 'Count saved']; });
    }

    public function countRecount(Request $r, int $id)
    {
        $this->can($r, 'stock.CHANGE');
        $d = $r->validate(['product' => 'required|integer', 'qty' => 'required|integer|min:0']);
        return $this->run(function () use ($d, $id) { CountService::recount($id, $d['product'], $d['qty']); return ['message' => 'Recount saved']; });
    }

    public function missingWriteOff(Request $r, int $productId)
    {
        $this->can($r, 'stock.countApprove');
        $d = $r->validate(['qty' => 'nullable|integer|min:1', 'reason' => 'nullable|string|max:200']);
        return $this->run(fn () => CountService::writeOffMissing((int) Context::ent(), $productId, $d['qty'] ?? null, $d['reason'] ?? null));
    }

    public function countStep(Request $r, int $id, string $step)
    {
        $this->can($r, in_array($step, ['approve', 'post'], true) ? 'stock.countApprove' : 'stock.CHANGE');
        return $this->run(fn () => match ($step) {
            'submit' => CountService::submit($id),
            'approve' => (function () use ($id) { CountService::approve($id); return ['message' => 'Count approved']; })(),
            'post' => CountService::post($id) + ['message' => 'Differences posted to stock'],
            'cancel' => (function () use ($id) { CountService::cancel($id); return ['message' => 'Count cancelled']; })(),
            default => abort(404),
        });
    }

    // ---- prices ----
    public function prices(Request $r, int $productId)
    {
        $this->can($r, 'stock.prices');
        abort_unless(DB::table('t_product_vendors')->where('id', $productId)->where('fk_id_ent', Context::ent())->exists(), 404);
        $lc = \App\Services\Registry::labelColumn('cat_marketplaces');
        return ['timeline' => PriceService::timeline($productId), 'marketplaces' => DB::table('cat_marketplaces')->limit(100)->get(['id', "$lc as name"]),
            'reasons' => DB::table('cat_price_change_reasons')->where('f_active', 1)->get(['id', 'reason_name as name'])];
    }

    public function variations(Request $r, int $id) { return $this->run(fn () => \App\Services\Variations::grid($id, $r->user())); }
    public function variationsSave(Request $r, int $id) { return $this->run(fn () => \App\Services\Variations::save($id, (array) $r->input('cards', []), $r->user())); }
    public function variationsCopy(Request $r, int $id) { return $this->run(fn () => \App\Services\Variations::copyParentPrices($id, $r->user())); }
    public function variationRemove(Request $r, int $id, int $child) { return $this->run(fn () => \App\Services\Variations::remove($id, $child, $r->user())); }
    public function variationDuplicate(Request $r, int $id, int $child) { return $this->run(fn () => \App\Services\Variations::duplicate($id, $child, $r->user())); }
    public function mpPrices(Request $r, int $productId) { abort_unless(DB::table('t_product_vendors')->where('id', $productId)->where('fk_id_ent', Context::ent())->exists(), 404); return \App\Services\ProductPrices::page($productId, $r->user()); }
    public function tariff(Request $r) { \App\Services\Perm::need($r->user(), 'stock.prices'); return \App\Services\ThreePlTariff::page((int) Context::ent()) + ['can_edit' => \App\Services\Perm::has($r->user(), 'stock.setPrice')]; }
    public function tariffSave(Request $r, int $mp) { return $this->run(fn () => \App\Services\ThreePlTariff::save((int) Context::ent(), $mp, $r->only(['packing_fee', 'packing_fee_per', 'storage_fee', 'storage_fee_per']), $r->user())); }
    public function mpPriceSave(Request $r, int $productId, int $mp) { return $this->run(fn () => \App\Services\ProductPrices::save($productId, $mp, $r->all(), $r->user())); }

    public function setPrice(Request $r, int $productId)
    {
        $this->can($r, 'stock.setPrice');
        abort_unless(DB::table('t_product_vendors')->where('id', $productId)->where('fk_id_ent', Context::ent())->exists(), 404);
        $d = $r->validate(['marketplace' => 'required|integer', 'raw_price' => 'required|numeric|min:0', 'sale_price' => 'required|numeric|min:0', 'from_date' => 'required|date', 'reason' => 'nullable|integer']);
        return $this->run(fn () => ['id' => PriceService::set($productId, $d['marketplace'], (float) $d['raw_price'], (float) $d['sale_price'], $d['from_date'], $d['reason'] ?? null), 'message' => 'Price saved from ' . $d['from_date']]);
    }

    // ---- bins ----
    public function bins(Request $r)
    {
        $this->can($r, 'stock.bins');
        $d = $r->validate(['parent' => 'required|integer', 'zone' => 'required|string|max:6', 'levels' => 'required|integer|min:1', 'positions' => 'required|integer|min:1', 'create' => 'boolean']);
        return $this->run(function () use ($d) {
            $x = LocationGenerator::run((int) Context::ent(), $d, (bool) ($d['create'] ?? false));
            $pj = ! empty($x['ids']) ? \App\Services\PrintService::auto((int) Context::ent(), 'WH_AUTOPRINT_SHELF', 'LOCATION', $x['ids'], 'AUTO_SHELF') : null;
            if ($pj && $pj['status'] === 'PRINTED') $x['print'] = $pj + ['url' => "/api/print-jobs/{$pj['job']}/pdf"];
            return $x + ['message' => ! empty($d['create']) ? 'Bins created' . (isset($x['print']) ? ' – bin labels are ready to print' : '') : 'Preview'];
        });
    }

    // ───────────── SKU homes (R-H-9, R-H-43) ─────────────
    public function homes(Request $r)
    {
        \App\Services\Perm::need($r->user(), 'stock.homes');
        return \App\Services\SkuHomes::page((int) Context::ent(), $r->query('q') ?: null);
    }

    public function homeSave(Request $r)
    {
        $d = $r->validate(['product' => 'nullable|integer', 'sku' => 'nullable|string|max:80', 'bin' => 'required|integer', 'role' => 'required|in:PRIMARY,OVERFLOW', 'min' => 'nullable|integer|min:0', 'max' => 'nullable|integer|min:0']);
        return $this->run(function () use ($d) { $ent = (int) Context::ent(); $p = $d['product'] ?? \Illuminate\Support\Facades\DB::table('t_product_vendors')->where('fk_id_ent', $ent)->where('SKU_VENDOR', trim((string) ($d['sku'] ?? '')))->value('id') ?? throw new \InvalidArgumentException('No product with this SKU.');
            return ['id' => \App\Services\SkuHomes::set($ent, (int) $p, (int) $d['bin'], $d['role'], $d['min'] ?? null, $d['max'] ?? null), 'message' => 'Home saved']; });
    }

    public function homeMove(Request $r)
    {
        $d = $r->validate(['product' => 'required|integer', 'bin' => 'required|integer', 'move_units' => 'nullable|boolean', 'keep_old' => 'nullable|boolean']);
        return $this->run(function () use ($d) { $x = \App\Services\SkuHomes::move((int) Context::ent(), (int) $d['product'], (int) $d['bin'], ! empty($d['move_units']), ! empty($d['keep_old']));
            return $x + ['message' => "Home moved from {$x['from']} to {$x['to']}" . ($x['units_moved'] ? " · {$x['units_moved']} unit(s) moved" : '') . ($x['labels_problem'] ? ' · shelf labels NOT queued: ' . $x['labels_problem'] : ' · 2 shelf labels are in the print queue')]; });
    }

    public function homeRefill(Request $r, int $id)
    {
        return $this->run(function () use ($id) { $x = \App\Services\SkuHomes::refill((int) Context::ent(), $id); return $x + ['message' => "{$x['moved']} unit(s) of {$x['sku']} moved into {$x['bin']} ({$x['detail']})"]; });
    }

    public function homeSuggest(Request $r)
    {
        $d = $r->validate(['product' => 'required|integer', 'qty' => 'nullable|integer|min:0']);
        return ['places' => \App\Services\SkuHomes::suggest((int) Context::ent(), (int) $d['product'], (int) ($d['qty'] ?? 0))];
    }

    public function homeRemove(Request $r, int $id)
    {
        return $this->run(function () use ($id) { \App\Services\SkuHomes::remove((int) Context::ent(), $id); return ['message' => 'Home removed']; });
    }
}
