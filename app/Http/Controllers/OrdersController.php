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
        catch (\Illuminate\Database\QueryException $e) { report($e); return response()->json(['message' => 'Something went wrong on our side. Nothing was saved. The details are in the system log.'], 500); }   // never show database text to a user
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

    /** Load a marketplace's order list (eBay orders report, Temu order list, or any order table). */
    public function importFile(Request $r)
    {
        $r->validate(['shop' => 'required|integer', 'file' => 'required|file|max:20480', 'currency' => 'nullable|string|size:3']);
        $f = $r->file('file');
        abort_unless(in_array(strtolower($f->getClientOriginalExtension()), ['csv', 'txt', 'tsv', 'xlsx', 'xls'], true), 422, 'Choose the order file the marketplace gave you (.csv or .xlsx).');
        try { $x = \App\Services\OrderFiles::import((int) \App\Services\Context::ent(), (int) $r->input('shop'), $f->getRealPath(), $f->getClientOriginalName(), array_filter(['currency' => $r->input('currency')]));
            return $x + ['message' => "{$x['format_name']}: {$x['orders']} order(s) · {$x['new']} new line(s)" . ($x['updated'] ? ", {$x['updated']} updated" : '') . ($x['locked'] ? ", {$x['locked']} already confirmed – not changed" : '') . ($x['unmatched'] ? " · {$x['unmatched']} with an unknown SKU" : '') . ($x['skipped'] ? ' · ' . count($x['skipped']) . ' skipped (cancelled or empty)' : '')]; }
        catch (\Illuminate\Database\QueryException $e) { report($e); return response()->json(['message' => 'Something went wrong on our side. Nothing was saved. The details are in the system log.'], 500); }
        catch (\InvalidArgumentException|\RuntimeException $e) { return response()->json(['message' => $e->getMessage(), 'errors' => ['_form' => [$e->getMessage()]]], 422); }
    }

    // ───────────── carrier label PDFs (R-A_SALES-85) ─────────────
    private function guarded(callable $fn)
    {
        try { return $fn(); }
        catch (\Illuminate\Database\QueryException $e) { report($e); return response()->json(['message' => 'Something went wrong on our side. Nothing was saved. The details are in the system log.'], 500); }
        catch (\InvalidArgumentException|\RuntimeException $e) { return response()->json(['message' => $e->getMessage(), 'errors' => ['_form' => [$e->getMessage()]]], 422); }
    }

    public function labels(Request $r)
    {
        abort_unless(in_array($r->user()->role_code, ['OWNER', 'MANAGER', 'WAREHOUSE', 'TL'], true), 403, 'Your role cannot see carrier labels.');
        return \App\Services\CarrierLabels::page((int) \App\Services\Context::ent());
    }

    public function labelImport(Request $r)
    {
        $r->validate(['carrier' => 'required|integer', 'shop' => 'required|integer', 'file' => 'required|file|max:51200']);
        $f = $r->file('file'); abort_unless(strtolower($f->getClientOriginalExtension()) === 'pdf', 422, 'Choose the label PDF the carrier gave you.');
        return $this->guarded(function () use ($r, $f) { $x = \App\Services\CarrierLabels::import((int) \App\Services\Context::ent(), (int) $r->input('carrier'), (int) $r->input('shop'), $f->getRealPath(), $f->getClientOriginalName());
            return $x + ['message' => "{$x['pages']} label(s): {$x['matched']} linked to their order" . ($x['set_aside'] ? ", {$x['set_aside']} waiting for you to pick the order" : '') . ($x['unmapped_service'] ? " · {$x['unmapped_service']} with a service text that is not mapped" : '')]; });
    }

    public function labelPick(Request $r, int $id)
    {
        $d = $r->validate(['sale' => 'required|integer']);
        return $this->guarded(function () use ($id, $d) { \App\Services\CarrierLabels::pick((int) \App\Services\Context::ent(), $id, (int) $d['sale']); return ['message' => 'Label linked to the order']; });
    }

    public function labelUndo(Request $r, int $id)
    {
        return $this->guarded(function () use ($id) { $n = \App\Services\CarrierLabels::undo((int) \App\Services\Context::ent(), $id); return ['message' => "Label file undone · $n order line(s) unlinked"]; });
    }

    public function labelFile(Request $r, int $id)
    {
        abort_unless(in_array($r->user()->role_code, ['OWNER', 'MANAGER', 'WAREHOUSE', 'TL'], true), 403);
        try { [$bytes, $name] = \App\Services\CarrierLabels::file((int) \App\Services\Context::ent(), $id); } catch (\InvalidArgumentException $e) { abort(404, $e->getMessage()); }
        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="' . $name . '"']);
    }

    /** 7.6 Tracking upload: the file the marketplace needs back – one line per order with its tracking number and carrier. */
    /**
     * The file a marketplace needs back with the tracking numbers. Its columns come from a layout (mo_tracking_layouts) – the one the shop's
     * marketplace profile names in tracking_upload_layout, or the one chosen on the screen; layouts are data and can be changed without code.
     */
    public function trackingUpload(Request $r)
    {
        abort_unless(in_array($r->user()->role_code, ['OWNER', 'MANAGER', 'WAREHOUSE', 'TL', 'VA'], true), 403);
        $ent = (int) \App\Services\Context::ent(); $shop = (int) $r->query('shop', 0); $from = $r->query('from') ?: date('Y-m-d', strtotime('-7 day')); $to = $r->query('to') ?: date('Y-m-d');
        $layouts = DB::table('mo_tracking_layouts')->where('f_active', 1)->orderBy('id')->get();
        $profileLayout = $shop ? DB::table('t_branches as b')->join('mp_profiles as p', 'p.id', '=', 'b.fk_id_mp_profile')->where('b.id', $shop)->value('p.tracking_upload_layout') : null;
        $code = (string) ($r->query('layout') ?: ($profileLayout ?: 'GENERIC')); $L = $layouts->firstWhere('code', $code) ?? $layouts->firstWhere('code', 'GENERIC');
        $rows = DB::table('t_sales as s')->leftJoin('t_branches as b', 'b.id', '=', 's.fk_id_branch')->leftJoin('t_product_listings as l', 'l.id', '=', 's.fk_id_listing')->leftJoin('t_product_vendors as pv', 'pv.id', '=', 's.fk_product_id')
            ->leftJoin('t_carriers as c', 'c.id', '=', 'b.fk_id_default_carrier')   // the parcel's carrier: the shop's usual carrier
            ->where('s.fk_id_ent', $ent)->where('s.sale_source', 'active')->whereNotNull('s.tracking_no')->where('s.tracking_no', '<>', '')->whereBetween('s.d_order', [$from, $to])
            ->when($shop, fn ($q) => $q->where('s.fk_id_branch', $shop))->orderBy('s.order_no')
            ->get(['b.shop_name as shop', 's.order_no', 's.tracking_no', 's.carrier_service_text as service', 's.label_status', 's.d_order as d', 's.qty as units', 'l.item_id', 'pv.SKU_VENDOR as sku', 'c.carrier_name as carrier', 's.updated_at']);
        $cols = json_decode((string) $L->columns_json, true) ?: [];
        $value = fn ($x, string $f) => match (true) { $f === 'order_no' => $x->order_no, $f === 'item_id' => $x->item_id, $f === 'sku' => $x->sku, $f === 'qty' => $x->units, $f === 'tracking_no' => $x->tracking_no,
            $f === 'carrier' => $x->carrier, $f === 'service' => $x->service, $f === 'ship_date' => substr((string) ($x->updated_at ?: date('Y-m-d')), 0, 10), str_starts_with($f, 'text:') => substr($f, 5), default => '' };
        // lines of one order: one line per order when the layout has no item / SKU / quantity column
        $perItem = (bool) array_intersect(array_column($cols, 1), ['item_id', 'sku', 'qty']);
        $out = $perItem ? $rows : $rows->unique(fn ($x) => $x->order_no . '|' . $x->tracking_no)->values();
        if ($r->query('csv') === '1') return response()->streamDownload(function () use ($out, $cols, $value, $L) { $o = fopen('php://output', 'w'); $d = $L->delimiter === '\t' || $L->delimiter === "\t" ? "\t" : ($L->delimiter ?: ',');
            fputcsv($o, array_column($cols, 0), $d); foreach ($out as $x) fputcsv($o, array_map(fn ($c) => $value($x, $c[1]), $cols), $d); fclose($o); },
            'tracking_upload_' . strtolower($L->code) . '_' . date('Ymd') . ($L->delimiter === "\t" ? '.txt' : '.csv'), ['Content-Type' => 'text/csv']);
        return ['rows' => $out, 'shops' => DB::table('t_branches')->where('fk_id_ent', $ent)->where('f_active', 1)->orderBy('shop_name')->get(['id', 'shop_name as name']), 'from' => $from, 'to' => $to, 'shop' => $shop,
            'layout' => ['code' => $L->code, 'name' => $L->name, 'note' => $L->note, 'headers' => array_column($cols, 0), 'fields' => array_column($cols, 1), 'from_profile' => $profileLayout === $L->code], 'layouts' => $layouts->map(fn ($x) => ['code' => $x->code, 'name' => $x->name])];
    }

    /** Add or change a tracking-upload layout: one line per column, "Header = field". Owner / Manager. */
    public function saveTrackingLayout(Request $r)
    {
        $this->can($r, ['OWNER', 'MANAGER']);
        return $this->run(function () use ($r) {
            $code = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '_', trim((string) $r->input('code')))); $name = trim((string) $r->input('name'));
            if ($code === '' || $name === '') throw new \InvalidArgumentException('Give the layout a code and a name.');
            $ok = ['order_no', 'item_id', 'sku', 'qty', 'tracking_no', 'carrier', 'service', 'ship_date', 'blank']; $cols = [];
            foreach (preg_split('/\r?\n/', (string) $r->input('columns')) as $line) {
                if (trim($line) === '') continue;
                if (! str_contains($line, '=')) throw new \InvalidArgumentException("Write each column as \"Header = field\": $line");
                [$h, $f] = array_map('trim', explode('=', $line, 2));
                if (! in_array($f, $ok, true) && ! str_starts_with($f, 'text:')) throw new \InvalidArgumentException("Unknown field \"$f\". Use one of: " . implode(', ', $ok) . ', or text:<fixed value>.');
                $cols[] = [$h, $f];
            }
            if (! in_array('tracking_no', array_column($cols, 1), true) || ! in_array('order_no', array_column($cols, 1), true)) throw new \InvalidArgumentException('A layout needs at least the order number (order_no) and the tracking number (tracking_no).');
            DB::table('mo_tracking_layouts')->updateOrInsert(['code' => $code], ['name' => mb_substr($name, 0, 120), 'columns_json' => json_encode($cols), 'delimiter' => $r->input('delimiter') === 'TAB' ? "\t" : ',', 'note' => mb_substr((string) $r->input('note'), 0, 255) ?: null, 'f_active' => 1]);
            Audit::log('mo_tracking_layouts', 0, 'UPDATE', ['code' => $code, 'columns' => count($cols)]);
            return ['code' => $code, 'message' => 'Layout saved. A marketplace profile uses it when its "tracking upload layout" is ' . $code . '.'];
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
