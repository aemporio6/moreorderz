<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use App\Services\Context;
use App\Services\DispatchService as Dispatch;
use App\Services\SalesService as Sales;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Orders board, order entry, pick lists, pack, courier handover, Month Confirm and returns. */
class OrdersController extends Controller
{
    private const SEE = ['OWNER', 'MANAGER', 'TL', 'VA', 'WAREHOUSE', 'FINANCE', 'ACCOUNTANT'];
    private const ENTER = ['OWNER', 'MANAGER', 'TL', 'VA'];
    private const WAREHOUSE = ['OWNER', 'MANAGER', 'WAREHOUSE'];
    private const CONFIRM = ['OWNER', 'MANAGER', 'TL'];
    private const MONEY = ['OWNER', 'MANAGER', 'TL', 'VA', 'FINANCE', 'ACCOUNTANT'];

    private function can(Request $r, array $roles): void
    {
        abort_unless(in_array($r->user()->role_code, $roles, true), 403, 'Your role cannot do this here. Ask the Owner if you need it.');
    }

    private function run(callable $fn)
    {
        try { return $fn(); }
        catch (\InvalidArgumentException|\RuntimeException $e) { return response()->json(['message' => $e->getMessage(), 'errors' => ['_form' => [$e->getMessage()]]], 422); }
    }

    private function rights(Request $r): array
    {
        $role = $r->user()->role_code;
        return ['enter' => in_array($role, self::ENTER, true), 'warehouse' => in_array($role, self::WAREHOUSE, true), 'confirm' => in_array($role, self::CONFIRM, true), 'money' => in_array($role, self::MONEY, true)];
    }

    public function board(Request $r)
    {
        $this->can($r, self::SEE);
        $ent = (int) Context::ent(); $rows = Dispatch::board($ent);
        $count = fn (array $st) => count(array_filter($rows, fn ($x) => in_array($x->wh_status, $st, true)));
        return ['rows' => $rows, 'counts' => ['to_pick' => $count(['RESERVED']), 'short' => $count(['SHORT']), 'picking' => $count(['PICKING']), 'picked' => $count(['PICKED']), 'packed' => $count(['PACKED'])],
            'shops' => DB::table('t_branches')->where('fk_id_ent', $ent)->where('f_active', 1)->orderBy('shop_name')->get(['id', 'shop_name as name']),
            'carriers' => DB::table('t_carriers')->where('fk_id_ent', $ent)->where('f_active', 1)->orderBy('carrier_name')->get(['id', 'carrier_name as name']),
            'boxes' => DB::table('wh_box_sizes')->where('fk_id_ent', $ent)->where('f_active', 1)->get(['id', 'box_code as name']),
            'pick_lists' => DB::table('wh_pick_tasks')->where('fk_id_ent', $ent)->whereIn('status', ['OPEN', 'IN_PROGRESS'])->orderBy('id')->get(['id', 'task_code', 'status', 'created_at',
                DB::raw('(SELECT COUNT(*) FROM wh_pick_task_lines l WHERE l.fk_id_pick_task = wh_pick_tasks.id AND l.line_status = "OPEN") as open_lines')]),
            'ready' => DB::table('wh_parcels as p')->leftJoin('t_carriers as c', 'c.id', '=', 'p.fk_id_carrier')->where('p.fk_id_ent', $ent)->where('p.status', 'READY')->orderBy('p.id')->get(['p.id', 'p.parcel_code', 'p.order_no', 'p.tracking_no', 'p.fk_id_carrier', 'c.carrier_name as carrier']),
            'handovers' => DB::table('wh_handover_sessions as s')->leftJoin('t_carriers as c', 'c.id', '=', 's.fk_id_carrier')->where('s.fk_id_ent', $ent)->where('s.status', 'OPEN')->get(['s.id', 's.session_code', 's.expected_count', 's.scanned_count', 's.exception_count', 'c.carrier_name as carrier']),
            'rights' => $this->rights($r), 'month' => date('Y-m')];
    }

    public function enter(Request $r)
    {
        $this->can($r, self::ENTER);
        $d = $r->validate(['branch' => 'required|integer', 'order_no' => 'required|string|max:100', 'd_order' => 'required|date', 'sku' => 'required|string|max:100', 'qty' => 'required|integer|min:1', 'sale_rate' => 'required|numeric|min:0',
            'cust_name' => 'nullable|string|max:150', 'ship_to_address' => 'nullable|string|max:300', 'ship_to_city' => 'nullable|string|max:100', 'ship_to_postcode' => 'nullable|string|max:20', 'ship_to_phone' => 'nullable|string|max:40', 'tracking_no' => 'nullable|string|max:60']);
        return $this->run(function () use ($d) {
            $x = Sales::intake((int) $d['branch'], [array_filter($d, fn ($v) => $v !== null)]);
            return $x + ['message' => $x['unmatched'] ? 'Saved, but the SKU matches no product – fix it under "Not matched"' : ($x['short'] ? 'Saved – not enough stock to reserve all of it' : ($x['updated'] ? 'Order line updated' : 'Order saved and stock reserved'))];
        });
    }

    public function cancel(Request $r, int $id)
    {
        $this->can($r, self::ENTER);
        $d = $r->validate(['reason' => 'required|string|min:3|max:255'], ['reason.required' => 'Give the reason for cancelling.']);
        return $this->run(function () use ($id, $d) {
            $s = DB::table('t_sales')->where('id', $id)->where('fk_id_ent', Context::ent())->first() ?? throw new \RuntimeException('Order line not found.');
            if ($s->status !== 'Pending' || $s->f_locked) throw new \RuntimeException('Only a pending line can be cancelled.');
            if ((int) $s->qty_shipped > 0) throw new \RuntimeException('This line is already shipped. Book a return instead.');
            Dispatch::release($id, $d['reason']);
            DB::table('t_sales')->where('id', $id)->update(['status' => 'Cancelled', 'updated_at' => now()]);
            Audit::log('t_sales', $id, 'UPDATE', ['status' => ['Pending', 'Cancelled']], $d['reason']);
            return ['message' => 'Order line cancelled; its stock is free again'];
        });
    }

    // ---- pick ----
    public function buildPick(Request $r)
    {
        $this->can($r, self::WAREHOUSE);
        return $this->run(function () { $id = Dispatch::buildPickList((int) Context::ent()); if (! $id) throw new \RuntimeException('Nothing is waiting to be picked.'); return ['id' => $id, 'message' => 'Pick list made']; });
    }

    public function pickList(Request $r, int $id)
    {
        $this->can($r, self::SEE);
        $t = DB::table('wh_pick_tasks')->where('id', $id)->where('fk_id_ent', Context::ent())->first(['id', 'task_code', 'status', 'created_at']) ?? abort(404);
        return ['task' => $t, 'lines' => DB::table('wh_pick_task_lines as l')->join('t_product_vendors as p', 'p.id', '=', 'l.fk_id_product')->leftJoin('cat_inventory_locations as c', 'c.id', '=', 'l.fk_id_from_location')->leftJoin('t_sales as s', 's.id', '=', 'l.fk_id_sale')
            ->where('l.fk_id_pick_task', $id)->orderBy('l.line_no')->get(['l.id', 'l.line_no', 'c.location_name as bin', 'p.SKU_VENDOR as sku', 'p.product_name_vendor as name', 'l.qty', 'l.qty_done', 'l.qty_short', 'l.line_status', 's.order_no']), 'rights' => $this->rights($r)];
    }

    public function pick(Request $r, int $lineId)
    {
        $this->can($r, self::WAREHOUSE);
        $d = $r->validate(['qty' => 'nullable|integer|min:0']);
        abort_unless(DB::table('wh_pick_task_lines as l')->join('wh_pick_tasks as t', 't.id', '=', 'l.fk_id_pick_task')->where('l.id', $lineId)->where('t.fk_id_ent', Context::ent())->exists(), 404);
        return $this->run(fn () => Dispatch::pick($lineId, $d['qty'] ?? null) + ['message' => 'Picked']);
    }

    // ---- pack ----
    public function pack(Request $r)
    {
        $this->can($r, self::WAREHOUSE);
        $d = $r->validate(['order_no' => 'required|string|max:100', 'branch' => 'required|integer', 'carrier' => 'nullable|integer', 'box' => 'nullable|integer', 'weight_g' => 'nullable|integer|min:1', 'tracking_no' => 'nullable|string|max:60']);
        return $this->run(function () use ($d) { $id = Dispatch::pack(array_filter($d, fn ($v) => $v !== null));
            $pj = \App\Services\PrintService::auto((int) Context::ent(), 'WH_AUTOPRINT_PACKING_SLIP', 'PACKING_SLIP', [$id], 'AUTO_PACK');
            return ['id' => $id, 'print' => $pj && $pj['status'] === 'PRINTED' ? $pj + ['url' => "/api/print-jobs/{$pj['job']}/pdf"] : null, 'parcel_code' => DB::table('wh_parcels')->where('id', $id)->value('parcel_code'), 'message' => 'Parcel packed and ready']; });
    }

    // ---- handover ----
    public function openHandover(Request $r)
    {
        $this->can($r, self::WAREHOUSE);
        $d = $r->validate(['carrier' => 'required|integer']);
        return $this->run(fn () => ['id' => Dispatch::openHandover((int) $d['carrier']), 'message' => 'Handover started']);
    }

    public function scan(Request $r, int $id)
    {
        $this->can($r, self::WAREHOUSE);
        $d = $r->validate(['code' => 'required|string|max:100', 'op_id' => 'nullable|string|max:36']);
        return $this->run(fn () => Dispatch::scan($id, $d['code'], $d['op_id'] ?? null));
    }

    public function closeHandover(Request $r, int $id)
    {
        $this->can($r, self::WAREHOUSE);
        $d = $r->validate(['driver' => 'nullable|string|max:120']);
        return $this->run(function () use ($id, $d) { $x = Dispatch::closeHandover($id, $d['driver'] ?? null);
            $pj = \App\Services\PrintService::auto((int) Context::ent(), 'WH_AUTOPRINT_MANIFEST', 'MANIFEST', [$id], 'AUTO_MANIFEST');
            if ($pj && $pj['status'] === 'PRINTED') { DB::table('wh_handover_sessions')->where('id', $id)->update(['manifest_file' => $pj['file']]); $x['print'] = $pj + ['url' => "/api/print-jobs/{$pj['job']}/pdf"]; }
            return $x + ['message' => "{$x['shipped']} parcel(s) shipped" . ($x['left_ready'] ? ", {$x['left_ready']} still ready" : '') . ($x['exceptions'] ? ", {$x['exceptions']} scan(s) to resolve" : '')]; });
    }

    // ---- month confirm ----
    public function confirmPreview(Request $r)
    {
        $this->can($r, self::SEE);
        $d = $r->validate(['branch' => 'required|integer', 'month' => ['required', 'regex:/^\d{4}-\d{2}$/']]);
        abort_unless(DB::table('t_branches')->where('id', $d['branch'])->where('fk_id_ent', Context::ent())->exists(), 404);
        $p = Sales::confirmPreview((int) $d['branch'], $d['month']);
        if (! in_array($r->user()->role_code, self::MONEY, true)) unset($p['sales_total']);
        return $p + ['rights' => $this->rights($r)];
    }

    public function confirm(Request $r)
    {
        $this->can($r, self::CONFIRM);
        $d = $r->validate(['branch' => 'required|integer', 'month' => ['required', 'regex:/^\d{4}-\d{2}$/'], 'book_unshipped' => 'boolean']);
        abort_unless(DB::table('t_branches')->where('id', $d['branch'])->where('fk_id_ent', Context::ent())->exists(), 404);
        return $this->run(function () use ($d) { $x = Sales::confirmMonth((int) $d['branch'], $d['month'], (bool) ($d['book_unshipped'] ?? false)); return $x + ['message' => "Month confirmed: {$x['lines']} line(s), {$x['units']} unit(s) taken out of stock"]; });
    }

    // ---- returns ----
    public function returns(Request $r)
    {
        $this->can($r, self::SEE);
        return ['rows' => DB::table('wh_return_intakes as i')->leftJoin('t_sales as s', 's.id', '=', 'i.fk_id_sale')->leftJoin('t_users as u', 'u.id', '=', 'i.received_by')->where('i.fk_id_ent', Context::ent())->orderByDesc('i.id')->limit(200)
            ->get(['i.id', 'i.intake_code', 'i.scanned_code', 'i.status', 'i.qty', 'i.grade', 'i.received_at', 's.order_no', 's.sku', 'u.display_name as by']), 'rights' => $this->rights($r)];
    }

    public function receiveReturn(Request $r)
    {
        $this->can($r, self::WAREHOUSE);
        $d = $r->validate(['code' => 'required|string|max:100', 'qty' => 'nullable|integer|min:1', 'condition' => 'nullable|in:SELLABLE,DAMAGED,QUARANTINE,MISSING', 'note' => 'nullable|string|max:500', 'op_id' => 'nullable|string|max:36']);
        return $this->run(function () use ($d) { $x = Dispatch::receiveReturn(array_filter($d, fn ($v) => $v !== null)); return $x + ['message' => $x['matched'] ? 'Return booked' . ($x['before_confirm'] ? '' : ' – the cost of the sale is reversed') : 'No order matches this code – saved for review']; });
    }
}
