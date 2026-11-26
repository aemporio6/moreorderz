<?php

namespace App\Http\Controllers;

use App\Services\Context;
use App\Services\InventoryHub;
use App\Services\Perm;
use Illuminate\Http\Request;

/** Owner 26-11 23:14: the Inventory screen and a product's story (reads only). */
class InventoryController extends Controller
{
    public function index(Request $r) { Perm::need($r->user(), 'stock.SEE'); return InventoryHub::page((int) Context::ent(), $r->user(), $r->only(['q', 'shop', 'marketplace', 'mode', 'owner', 'investor', 'state', 'children', 'sort', 'page'])); }

    private function run(callable $fn) { try { return $fn(); } catch (\InvalidArgumentException|\RuntimeException $e) { return response()->json(['message' => $e->getMessage()], 422); } }

    /** What the action windows of a product offer: its places with stock, every place, its lots, the owners. */
    public function options(Request $r, int $id)
    {
        Perm::need($r->user(), 'stock.SEE'); $ent = (int) Context::ent(); \App\Services\FbaLedger::locations($ent);
        $here = \Illuminate\Support\Facades\DB::table('fin_lot_balances as b')->join('fin_lots as l', 'l.id', '=', 'b.fk_id_lot')->join('cat_inventory_locations as c', 'c.id', '=', 'b.fk_id_location')->where('l.fk_id_product', $id)->where('b.qty', '>', 0)
            ->groupBy('c.id', 'c.location_name')->selectRaw('c.id, c.location_name name, SUM(b.qty) qty')->get();
        $all = \Illuminate\Support\Facades\DB::table('cat_inventory_locations')->where('fk_id_ent', $ent)->where('f_active', 1)->whereIn('kind', ['WAREHOUSE', 'SHELF', 'BIN', 'FBA', 'THREE_PL', 'TRANSIT'])->orWhere(fn ($q) => $q->where('fk_id_ent', $ent)->where('f_active', 1)->whereNull('kind'))
            ->orderByRaw("FIELD(kind,'WAREHOUSE','FBA','THREE_PL','SHELF','BIN','TRANSIT')")->orderBy('location_name')->limit(400)->get(['id', 'location_name as name', 'kind']);
        $lots = \Illuminate\Support\Facades\DB::table('fin_lots')->where('fk_id_product', $id)->where('company_id', $ent)->where('qty_left', '>', 0)->orderBy('received_date')->get(['id', 'lot_code', 'qty_left', 'fk_id_owner', 'unit_cost_gbp', 'received_date'])
            ->map(fn ($l) => (array) $l + ['owner' => $l->fk_id_owner ? \App\Services\PartnerService::name((int) $l->fk_id_owner) : 'Company']);
        $owners = \Illuminate\Support\Facades\DB::table('fin_investor_agreements')->where('company_id', $ent)->distinct()->pluck('investor_id')->filter()->map(fn ($o) => ['id' => (int) $o, 'name' => \App\Services\PartnerService::name((int) $o)])->values();
        return ['here' => $here, 'all' => $all, 'lots' => $lots, 'owners' => $owners, 'can' => Perm::has($r->user(), 'stock.CHANGE')];
    }

    public function transfer(Request $r, int $id)
    {
        Perm::need($r->user(), 'stock.CHANGE'); $d = $r->validate(['from' => 'required|integer', 'to' => 'required|integer', 'qty' => 'required|integer|min:1', 'reason' => 'nullable|string|max:200']);
        return $this->run(fn () => ['rows' => \App\Services\StockService::transfer((int) Context::ent(), $id, (int) $d['qty'], (int) $d['from'], (int) $d['to'], $d['reason'] ?? 'Moved from Inventory', ['source_type' => 'INVENTORY']), 'message' => 'Moved – the ledger has both lines.']);
    }

    public function owner(Request $r, int $id)
    {
        Perm::need($r->user(), 'stock.CHANGE'); $d = $r->validate(['lot' => 'required|integer', 'qty' => 'required|integer|min:1', 'to' => 'required|integer|min:0']);
        abort_unless(\Illuminate\Support\Facades\DB::table('fin_lots')->where('id', $d['lot'])->where('fk_id_product', $id)->exists(), 404);
        return $this->run(fn () => ['lot' => \App\Services\StockService::transferOwnership((int) Context::ent(), (int) $d['lot'], (int) $d['qty'], (int) $d['to']), 'message' => 'The owner is changed for those units (a new lot keeps the cost and date).']);
    }

    public function fbaRecon(Request $r) { Perm::need($r->user(), 'stock.SEE'); $s = Perm::shops($r->user()); return \App\Services\FbaLedger::recon((int) Context::ent(), $r->query('shop') ? (int) $r->query('shop') : null, $s); }
    /** Owner 27-11 02:31: the product's vault. */
    public function vault(Request $r, int $id) { Perm::need($r->user(), 'stock.SEE'); return $this->run(fn () => \App\Services\ProductVault::page((int) Context::ent(), $id)); }
    public function vaultAsset(Request $r, int $id) { Perm::need($r->user(), 'form:' . (\App\Services\RecordService::formOfTable('t_product_vendors') ?: 't_product_vendor') . ':EDIT'); return $this->run(fn () => \App\Services\ProductVault::change((int) Context::ent(), $id, $r->only(['kind', 'marketplace', 'main', 'remove']), $r->user())); }

    /** Owner 27-11 02:31: packaging worked out – a product (with ?qty=) or a whole PO. */
    public function packaging(Request $r, int $id) { Perm::need($r->user(), 'stock.SEE'); return $this->run(fn () => \App\Services\Packaging::product($id, $r->query('qty') !== null ? (float) $r->query('qty') : null)); }
    public function packagingPo(Request $r, int $id) { abort_unless(Perm::has($r->user(), 'buying.SEE') || Perm::has($r->user(), 'stock.SEE'), 403); return $this->run(fn () => \App\Services\Packaging::po((int) Context::ent(), $id)); }

    /** Inventory (d) rest: each shop's quantity against what we can sell. */
    public function shopCheck(Request $r) { Perm::need($r->user(), 'stock.SEE'); return \App\Services\ShopStockCheck::rows((int) Context::ent(), $r->user(), $r->query('shop') ? (int) $r->query('shop') : null, strtoupper((string) $r->query('state', ''))); }
    public function fbaLink(Request $r) { $d = $r->validate(['shop' => 'required|integer', 'sku' => 'required|string|max:100', 'product' => 'required|integer']); return $this->run(fn () => \App\Services\FbaLedger::link((int) Context::ent(), $r->user(), (int) $d['shop'], $d['sku'], (int) $d['product'])); }
    public function fbaBook(Request $r) { return $this->run(fn () => \App\Services\FbaLedger::book((int) Context::ent(), $r->user(), (array) $r->input('lines', []), $r->input('from', 'adjust'))); }

    public function story(Request $r, int $id)
    {
        Perm::need($r->user(), 'stock.SEE');
        try { return InventoryHub::story((int) Context::ent(), $r->user(), $id); } catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 403); }
    }
}
