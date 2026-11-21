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

    private function can(Request $r, string $code): void
    {
        \App\Services\Perm::need($r->user(), $code);
    }

    private function run(callable $fn)
    {
        try { return $fn(); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface|\Illuminate\Http\Exceptions\HttpResponseException $e) { throw $e; }   // "not found" / "not allowed" keep their own status
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
        $this->can($r, 'orders.SEE');
        $ent = (int) Context::ent(); $rows = Dispatch::board($ent);
        $count = fn (array $st) => count(array_filter($rows, fn ($x) => in_array($x->wh_status, $st, true)));
        return ['rows' => $rows, 'counts' => ['to_pick' => $count(['RESERVED']), 'short' => $count(['SHORT']), 'picking' => $count(['PICKING']), 'picked' => $count(['PICKED']), 'packed' => $count(['PACKED'])],
            'shops' => \App\Services\Perm::mine(DB::table('t_branches'), 'id')->where('fk_id_ent', $ent)->where('f_active', 1)->orderBy('shop_name')->get(['id', 'shop_name as name']),
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
        $this->can($r, 'orders.ENTER');
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
        \App\Services\Perm::need($r->user(), 'orders.labels');
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
        \App\Services\Perm::need($r->user(), 'orders.labelFile');
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
        \App\Services\Perm::need($r->user(), 'orders.trackingUpload');
        $ent = (int) \App\Services\Context::ent(); $shop = (int) $r->query('shop', 0); $from = $r->query('from') ?: date('Y-m-d', strtotime('-7 day')); $to = $r->query('to') ?: date('Y-m-d');
        $layouts = DB::table('mo_tracking_layouts')->where('f_active', 1)->orderBy('id')->get();
        $profileLayout = $shop ? DB::table('t_branches as b')->join('mp_profiles as p', 'p.id', '=', 'b.fk_id_mp_profile')->where('b.id', $shop)->value('p.tracking_upload_layout') : null;
        $code = (string) ($r->query('layout') ?: ($profileLayout ?: 'GENERIC')); $L = $layouts->firstWhere('code', $code) ?? $layouts->firstWhere('code', 'GENERIC');
        $rows = \App\Services\Perm::mine(DB::table('t_sales as s'), 's.fk_id_branch')->leftJoin('t_branches as b', 'b.id', '=', 's.fk_id_branch')->leftJoin('t_product_listings as l', 'l.id', '=', 's.fk_id_listing')->leftJoin('t_product_vendors as pv', 'pv.id', '=', 's.fk_product_id')
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
        return ['rows' => $out, 'shops' => \App\Services\Perm::mine(DB::table('t_branches'), 'id')->where('fk_id_ent', $ent)->where('f_active', 1)->orderBy('shop_name')->get(['id', 'shop_name as name']), 'from' => $from, 'to' => $to, 'shop' => $shop,
            'layout' => ['code' => $L->code, 'name' => $L->name, 'note' => $L->note, 'headers' => array_column($cols, 0), 'fields' => array_column($cols, 1), 'from_profile' => $profileLayout === $L->code], 'layouts' => $layouts->map(fn ($x) => ['code' => $x->code, 'name' => $x->name])];
    }

    /** Add or change a tracking-upload layout: one line per column, "Header = field". Owner / Manager. */
    public function saveTrackingLayout(Request $r)
    {
        $this->can($r, 'orders.saveTrackingLayout');
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
        $this->can($r, 'orders.ENTER');
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
        $this->can($r, 'orders.WAREHOUSE');
        return $this->run(function () { $id = Dispatch::buildPickList((int) Context::ent()); if (! $id) throw new \RuntimeException('Nothing is waiting to be picked.'); return ['id' => $id, 'message' => 'Pick list made']; });
    }

    public function pickList(Request $r, int $id)
    {
        $this->can($r, 'orders.SEE');
        $t = DB::table('wh_pick_tasks')->where('id', $id)->where('fk_id_ent', Context::ent())->first(['id', 'task_code', 'status', 'created_at']) ?? abort(404);
        return ['task' => $t, 'lines' => DB::table('wh_pick_task_lines as l')->join('t_product_vendors as p', 'p.id', '=', 'l.fk_id_product')->leftJoin('cat_inventory_locations as c', 'c.id', '=', 'l.fk_id_from_location')->leftJoin('t_sales as s', 's.id', '=', 'l.fk_id_sale')
            ->where('l.fk_id_pick_task', $id)->orderBy('l.line_no')->get(['l.id', 'l.line_no', 'c.location_name as bin', 'p.SKU_VENDOR as sku', 'p.product_name_vendor as name', 'l.qty', 'l.qty_done', 'l.qty_short', 'l.line_status', 's.order_no']), 'rights' => $this->rights($r)];
    }

    public function pick(Request $r, int $lineId)
    {
        $this->can($r, 'orders.WAREHOUSE');
        $d = $r->validate(['qty' => 'nullable|integer|min:0']);
        abort_unless(DB::table('wh_pick_task_lines as l')->join('wh_pick_tasks as t', 't.id', '=', 'l.fk_id_pick_task')->where('l.id', $lineId)->where('t.fk_id_ent', Context::ent())->exists(), 404);
        return $this->run(fn () => Dispatch::pick($lineId, $d['qty'] ?? null) + ['message' => 'Picked']);
    }

    // ---- pack ----
    public function pack(Request $r)
    {
        $this->can($r, 'orders.WAREHOUSE');
        $d = $r->validate(['order_no' => 'required|string|max:100', 'branch' => 'required|integer', 'carrier' => 'nullable|integer', 'box' => 'nullable|integer', 'weight_g' => 'nullable|integer|min:1', 'tracking_no' => 'nullable|string|max:60']);
        return $this->run(function () use ($d) { $id = Dispatch::pack(array_filter($d, fn ($v) => $v !== null));
            $pj = \App\Services\PrintService::auto((int) Context::ent(), 'WH_AUTOPRINT_PACKING_SLIP', 'PACKING_SLIP', [$id], 'AUTO_PACK');
            return ['id' => $id, 'print' => $pj && $pj['status'] === 'PRINTED' ? $pj + ['url' => "/api/print-jobs/{$pj['job']}/pdf"] : null, 'parcel_code' => DB::table('wh_parcels')->where('id', $id)->value('parcel_code'), 'message' => 'Parcel packed and ready']; });
    }

    // ---- handover ----
    public function openHandover(Request $r)
    {
        $this->can($r, 'orders.WAREHOUSE');
        $d = $r->validate(['carrier' => 'required|integer']);
        return $this->run(fn () => ['id' => Dispatch::openHandover((int) $d['carrier']), 'message' => 'Handover started']);
    }

    public function scan(Request $r, int $id)
    {
        $this->can($r, 'orders.WAREHOUSE');
        $d = $r->validate(['code' => 'required|string|max:100', 'op_id' => 'nullable|string|max:36']);
        return $this->run(fn () => Dispatch::scan($id, $d['code'], $d['op_id'] ?? null));
    }

    public function closeHandover(Request $r, int $id)
    {
        $this->can($r, 'orders.WAREHOUSE');
        $d = $r->validate(['driver' => 'nullable|string|max:120']);
        return $this->run(function () use ($id, $d) { $x = Dispatch::closeHandover($id, $d['driver'] ?? null);
            $pj = \App\Services\PrintService::auto((int) Context::ent(), 'WH_AUTOPRINT_MANIFEST', 'MANIFEST', [$id], 'AUTO_MANIFEST');
            if ($pj && $pj['status'] === 'PRINTED') { DB::table('wh_handover_sessions')->where('id', $id)->update(['manifest_file' => $pj['file']]); $x['print'] = $pj + ['url' => "/api/print-jobs/{$pj['job']}/pdf"]; }
            return $x + ['message' => "{$x['shipped']} parcel(s) shipped" . ($x['left_ready'] ? ", {$x['left_ready']} still ready" : '') . ($x['exceptions'] ? ", {$x['exceptions']} scan(s) to resolve" : '')]; });
    }

    // ---- month confirm ----
    public function confirmPreview(Request $r)
    {
        $this->can($r, 'orders.SEE');
        $d = $r->validate(['branch' => 'required|integer', 'month' => ['required', 'regex:/^\d{4}-\d{2}$/']]);
        abort_unless(DB::table('t_branches')->where('id', $d['branch'])->where('fk_id_ent', Context::ent())->exists(), 404);
        $p = Sales::confirmPreview((int) $d['branch'], $d['month']);
        if (! \App\Services\Perm::has($r->user(), 'orders.MONEY')) unset($p['sales_total']);
        return $p + ['rights' => $this->rights($r)];
    }

    public function confirm(Request $r)
    {
        $this->can($r, 'orders.CONFIRM');
        $d = $r->validate(['branch' => 'required|integer', 'month' => ['required', 'regex:/^\d{4}-\d{2}$/'], 'book_unshipped' => 'boolean']);
        abort_unless(DB::table('t_branches')->where('id', $d['branch'])->where('fk_id_ent', Context::ent())->exists(), 404);
        return $this->run(function () use ($d) { $x = Sales::confirmMonth((int) $d['branch'], $d['month'], (bool) ($d['book_unshipped'] ?? false)); return $x + ['message' => "Month confirmed: {$x['lines']} line(s), {$x['units']} unit(s) taken out of stock"]; });
    }

    // ---- returns ----
    public function returns(Request $r)
    {
        $this->can($r, 'orders.SEE');
        return ['rows' => \App\Services\Perm::mine(DB::table('wh_return_intakes as i'), 's.fk_id_branch')->leftJoin('t_sales as s', 's.id', '=', 'i.fk_id_sale')->leftJoin('t_users as u', 'u.id', '=', 'i.received_by')->where('i.fk_id_ent', Context::ent())->orderByDesc('i.id')->limit(200)
            ->get(['i.id', 'i.intake_code', 'i.scanned_code', 'i.status', 'i.qty', 'i.grade', 'i.received_at', 's.order_no', 's.sku', 'u.display_name as by']), 'rights' => $this->rights($r)];
    }

    public function receiveReturn(Request $r)
    {
        $this->can($r, 'orders.WAREHOUSE');
        $d = $r->validate(['code' => 'required|string|max:100', 'qty' => 'nullable|integer|min:1', 'condition' => 'nullable|in:SELLABLE,DAMAGED,QUARANTINE,MISSING', 'note' => 'nullable|string|max:500', 'op_id' => 'nullable|string|max:36']);
        return $this->run(function () use ($d) { $x = Dispatch::receiveReturn(array_filter($d, fn ($v) => $v !== null)); return $x + ['message' => $x['matched'] ? 'Return booked' . ($x['before_confirm'] ? '' : ' – the cost of the sale is reversed') : 'No order matches this code – saved for review']; });
    }

    // ───────────── sales list and the actions on a line (RULES_A_SALES §5, §6) ─────────────
    public function saleLines(Request $r)
    {
        $this->can($r, 'orders.SEE');
        $f = $this->saleFilters($r);
        foreach (['from', 'to'] as $k) if (! empty($f[$k]) && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $f[$k])) unset($f[$k]);
        // the last marketplace, shop and dates are remembered for the person on the server (every device); the one-batch view never overwrites them
        if (empty($f['batch']) && ! empty($f['marketplace']) && $r->boolean('remember', true)) \App\Services\ListExports::savePrefs($r->user(), 'sales_listing', ['scope' => array_intersect_key($f, array_flip(['marketplace', 'shop', 'from', 'to']))]);
        return \App\Services\SaleLines::list($f, $r->user()) + ['scope' => \App\Services\ListExports::prefs($r->user(), 'sales_listing')['list']['scope'] ?? null];
    }

    public function saleEdit(Request $r, int $id) { return $this->run(fn () => \App\Services\SaleLines::edit($id, $r->only(['order_no', 'cust_name', 'cust_contacts', 'qty', 'sale_rate', 'total_amount']), ! $r->boolean('preview'))); }
    public function saleCarrierOptions(Request $r, int $id) { $this->can($r, 'orders.SEE'); $s = DB::table('t_sales')->where('id', $id)->where('fk_id_ent', Context::ent())->first() ?? abort(404); return \App\Services\SaleLines::carrierOptions($s) + ['current' => ['fk_id_car_pkg_svc' => (int) $s->fk_id_car_pkg_svc, 'fk_id_packing' => (int) $s->fk_id_packing]]; }
    public function saleCarrier(Request $r, int $id) { return $this->run(fn () => \App\Services\SaleLines::carrierPacking($id, $r->only(['fk_id_car_pkg_svc', 'fk_id_packing']), ! $r->boolean('preview'))); }
    public function saleRefundInfo(Request $r, int $id) { $this->can($r, 'orders.SEE'); return \App\Services\SaleLines::refundInfo($id); }
    public function saleRefund(Request $r, int $id) { return $this->run(fn () => \App\Services\SaleLines::refund($id, $r->only(['sale_rate', 'total_amount', 'nature', 'loss_amount', 'reason']))); }
    public function saleDelete(Request $r, int $id) { return $this->run(fn () => \App\Services\SaleLines::deleteLine($id)); }
    public function saleLog(Request $r, int $id) { $this->can($r, 'orders.SEE'); return ['rows' => \App\Services\SaleLines::history($id)]; }
    public function saleMonthInfo(Request $r) { $this->can($r, 'orders.saleDeleteMonth'); $i = \App\Services\SaleLines::monthInfo((int) $r->query('shop'), (string) $r->query('month')); return ['lines' => $i['lines'], 'confirmed' => $i['confirmed'], 'text' => $i['text'], 'month_name' => $i['month_name']]; }
    public function saleDeleteMonth(Request $r) { return $this->run(fn () => \App\Services\SaleLines::deleteMonth((int) $r->input('shop'), (string) $r->input('month'))); }
    public function saleRestore(Request $r) { return $this->run(fn () => \App\Services\SaleLines::restore((string) $r->input('undo'))); }

    // ---------- the monthly sales import (R-A_SALES-1 … 18) ----------
    public function saleImportHome(Request $r)
    {
        $this->can($r, 'orders.saleImport'); $u = $r->user(); $ent = Context::ent();
        return \App\Services\SalesImport::register($u) + ['self_check' => \App\Services\SalesImport::selfCheckMessage(), 'can_columns' => \App\Services\Perm::has($u, 'orders.saleColumns'), 'can_delete' => \App\Services\Perm::has($u, 'orders.saleDeleteMonth'),
            'marketplaces' => DB::table('cat_marketplaces')->whereIn('id', \App\Services\Perm::mine(DB::table('t_branches'), 'id')->where('fk_id_ent', $ent)->pluck('fk_id_marketplace'))->orderBy('marketplace_descr')->get(['id', DB::raw("COALESCE(NULLIF(marketplace_short,''), NULLIF(marketplace_descr,''), CONCAT('Marketplace ', id)) as name")]),
            'can_refresh_va' => \App\Services\Perm::has($u, 'orders.saleUnlock'),
            'shops' => array_map(fn ($s) => ['id' => $s['id'], 'marketplace' => $s['marketplace'], 'active' => (int) $s['active'], 'name' => $s['label'], 'va' => $s['va'], 'va_pct' => $s['va_pct']], \App\Services\ShopLabel::list($u))];
    }
    public function saleImportName(Request $r) { $this->can($r, 'orders.saleImport'); return \App\Services\SalesImport::fileName((string) $r->query('name'), (int) $r->query('marketplace') ?: null); }
    public function saleImportRun(Request $r)
    {
        return $this->run(function () use ($r) {
            $this->can($r, 'orders.saleImport'); $f = $r->file('file'); $d = $r->only(['nature', 'marketplace', 'shop', 'month', 'year', 'fee', 'out_mode', 'delimiter', 'header_line']) + ['replace' => $r->boolean('replace'), 'sure' => $r->boolean('sure')];
            if (! $f || ! $f->isValid()) { \App\Services\SalesImport::preCheck($d, null, $r->user()); throw new \InvalidArgumentException('Choose a CSV file to upload.'); }
            return \App\Services\SalesImport::run($f->getRealPath(), $f->getClientOriginalName(), (int) $f->getSize(), $d, $r->user(), $r->boolean('apply'));
        });
    }
    public function saleImportErrors(Request $r, int $id) { $this->can($r, 'orders.saleImport'); return \App\Services\SalesImport::errors($id); }
    public function saleImportUnmatched(Request $r, int $id)
    {
        $this->can($r, 'orders.saleImport'); if ($r->query('format') !== 'csv') return ['rows' => \App\Services\SalesImport::unmatched($id)];
        return $this->run(function () use ($r, $id) { $x = \App\Services\DsMatch::csv(['batch' => $id], $r->user()); return response($x['body'], 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="' . $x['name'] . '"']); });
    }
    public function saleImportSku(Request $r) { $this->can($r, 'orders.saleImport'); return $r->query('q') !== null ? ['rows' => \App\Services\SalesImport::skuSearch((string) $r->query('q'))] : \App\Services\SalesImport::skuCheck((string) $r->query('sku')); }
    public function saleImportLink(Request $r, int $id) { return $this->run(fn () => \App\Services\SalesImport::link($id, (string) $r->input('sku'), $r->user())); }
    public function saleImportDelete(Request $r, int $id) { return $this->run(fn () => \App\Services\SalesImport::deleteBatch($id, $r->user())); }
    public function saleImportRestore(Request $r) { return $this->run(fn () => \App\Services\SalesImport::restoreBatch((string) $r->input('undo'), $r->user())); }
    public function saleColumns(Request $r, int $mp) { $this->can($r, 'orders.saleImport'); $set = strtoupper((string) $r->query('set', 'SALE')); abort_unless(isset(\App\Services\SalesImport::SETS[$set]), 404);
        return ['set' => $set, 'fields' => \App\Services\SalesImport::SETS[$set][2], 'required' => $set === 'MATCH' ? [] : \App\Services\SalesImport::REQUIRED, 'columns' => array_map(fn ($a) => implode(',', $a), \App\Services\SalesImport::columns($mp, $set)), 'can_save' => \App\Services\Perm::has($r->user(), 'orders.saleColumns')]; }
    public function saleColumnsSave(Request $r, int $mp) { return $this->run(function () use ($r, $mp) { $this->can($r, 'orders.saleColumns'); return ['message' => 'Columns saved.', 'columns' => \App\Services\SalesImport::saveColumns($mp, strtoupper((string) $r->input('set', 'SALE')), (array) $r->input('columns', []))]; }); }

    private function saleFilters(Request $r): array
    {
        $f = $r->only(['marketplace', 'shop', 'batch', 'status', 'type', 'show', 'visibility', 'company', 'vendor', 'investor', 'ds_platform', 'carrier', 'from', 'to', 'q', 'page', 'per_page']);
        foreach (['from', 'to'] as $k) if (! empty($f[$k]) && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $f[$k])) unset($f[$k]);
        return $f;
    }
    // ---------- the sales list as a view: columns per person, extra marketplace columns, export, Owner unlock (R-A_SALES-34, 44, 45, 46) ----------
    public function saleViewColumns(Request $r) { $this->can($r, 'orders.SEE'); return \App\Services\SaleView::saveHidden($r->user(), (array) $r->input('hidden', [])) + ['columns' => \App\Services\SaleView::columns($r->user())]; }
    public function saleExtra(Request $r, int $mp) { $this->can($r, 'orders.saleImport'); return ['rows' => \App\Services\SaleView::extraSetup($mp), 'can_save' => \App\Services\Perm::has($r->user(), 'orders.saleColumns')]; }
    public function saleExtraSave(Request $r, int $mp) { return $this->run(function () use ($r, $mp) { $this->can($r, 'orders.saleColumns'); return ['message' => 'Extra columns saved.', 'rows' => \App\Services\SaleView::saveExtra($mp, (array) $r->input('rows', []))]; }); }
    public function saleExportCheck(Request $r) { return $this->run(function () use ($r) { $this->can($r, 'orders.saleExport'); return \App\Services\SaleView::exportCheck($this->saleFilters($r), strtolower((string) $r->query('format', 'pdf')), $r->user()); }); }
    public function saleExport(Request $r)
    {
        return $this->run(function () use ($r) {
            $this->can($r, 'orders.saleExport'); $x = \App\Services\SaleView::export($this->saleFilters($r), strtolower((string) $r->query('format', 'pdf')), $r->user());
            return response($x['body'], 200, ['Content-Type' => $x['type'], 'Content-Disposition' => 'attachment; filename="' . $x['name'] . '"']);
        });
    }
    public function saleUnlockInfo(Request $r) { $this->can($r, 'orders.saleUnlock'); return Sales::unlockInfo((int) $r->query('shop'), (string) $r->query('month')); }
    public function saleUnlock(Request $r) { return $this->run(fn () => Sales::unlockMonth((int) $r->input('shop'), (string) $r->input('month'), (string) $r->input('reason'), $r->user())); }

    // ---------- drop-shipping match (R-A_SALES-19 … 26) ----------
    public function saleRematch(Request $r) { return $this->run(fn () => \App\Services\DsMatch::rematch($this->saleFilters($r), $r->user())); }
    public function saleNotMatchedCsv(Request $r) { return $this->run(function () use ($r) { $this->can($r, 'orders.SEE'); $x = \App\Services\DsMatch::csv($this->saleFilters($r), $r->user()); return response($x['body'], 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="' . $x['name'] . '"']); }); }
    public function saleMatch(Request $r, int $id) { return $this->run(function () use ($r, $id) { $this->can($r, 'orders.SEE'); return \App\Services\DsMatch::explain($id, $r->user()); }); }
    public function saleDsCandidates(Request $r, int $id) { $this->can($r, 'orders.dsLink'); return \App\Services\DsMatch::candidates($id, $r->user()); }
    public function saleDsLink(Request $r, int $id) { return $this->run(fn () => \App\Services\DsMatch::link($id, (int) $r->input('item'), (string) $r->input('reason'), $r->user())); }
    public function saleDsUnlink(Request $r, int $id) { return $this->run(fn () => \App\Services\DsMatch::unlink($id, (string) $r->input('reason'), $r->user())); }

    // ---------- shop label, sales reports, saved VA share, drop-shipping margin (R-A_SALES-81, 50, 51, 31, 91) ----------
    public function shopLabels(Request $r) { return ['shops' => \App\Services\ShopLabel::list($r->user(), ['marketplace' => $r->query('marketplace'), 'active_only' => $r->boolean('active_only')])]; }
    private function reportFilters(Request $r): array { return $r->only(['year', 'marketplace', 'shop', 'vendor', 'vendor_inventory', 'company', 'owner', 'va']); }
    public function saleReportAnnual(Request $r) { $this->can($r, 'orders.saleReports'); return \App\Services\SalesReports::annual($this->reportFilters($r), $r->user()) + ['lists' => \App\Services\SalesReports::filterLists($r->user())]; }
    public function saleReportDetailed(Request $r) { $this->can($r, 'orders.saleReports'); return \App\Services\SalesReports::detailed($this->reportFilters($r), $r->user()) + ['lists' => \App\Services\SalesReports::filterLists($r->user())]; }
    public function saleReportDownload(Request $r, string $report, string $format) { return $this->run(function () use ($r, $report, $format) { $this->can($r, 'orders.saleReports'); $x = \App\Services\SalesReports::download($report, $format, $this->reportFilters($r), $r->user()); return response($x['body'], 200, ['Content-Type' => $x['type'], 'Content-Disposition' => 'attachment; filename="' . $x['name'] . '"']); }); }
    public function saleRefreshVa(Request $r, int $id) { return $this->run(fn () => \App\Services\SalesReports::refreshVa($id, (string) $r->input('reason'), $r->user())); }
    public function saleMarginAccept(Request $r, int $id) { return $this->run(fn () => \App\Services\DsMatch::acceptMargin($id, (string) $r->input('note'), $r->user())); }

    // ---------- VA statement per shop + month (R-A_SALES-65) ----------
    public function vaStatements(Request $r) { $this->can($r, 'orders.SEE'); return \App\Services\VaStatements::list($r->user(), $r->only(['shop', 'all'])); }
    public function vaStatementPreview(Request $r) { return $this->run(function () use ($r) { $this->can($r, 'orders.vaStatement'); return \App\Services\VaStatements::preview((int) $r->query('shop'), (string) $r->query('month'), (float) $r->query('expenses', 0)); }); }
    public function vaStatementMake(Request $r) { return $this->run(fn () => \App\Services\VaStatements::generate((int) $r->input('shop'), (string) $r->input('month'), $r->input('expenses'), $r->input('detail'), $r->user())); }
    public function vaStatementApprove(Request $r, int $id) { return $this->run(fn () => \App\Services\VaStatements::approve($id, $r->user())); }
    public function vaStatementReject(Request $r, int $id) { return $this->run(fn () => \App\Services\VaStatements::reject($id, (string) $r->input('reason'), $r->user())); }
    public function vaStatementPdf(Request $r, int $id)
    {
        $this->can($r, 'orders.SEE'); $row = collect(\App\Services\VaStatements::list($r->user(), ['all' => 1])['rows'])->firstWhere('id', $id) ?? abort(404);      // only what this person may see
        $s = DB::table('va_statements')->where('id', $id)->first(); $path = storage_path('app/' . $s->pdf_file); if (! $s->pdf_file || ! is_file($path)) $path = storage_path('app/' . \App\Services\VaStatements::pdf($id));
        return response(file_get_contents($path), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="' . \App\Services\VaStatements::fileName($s) . '"']);
    }

    // ---------- shop monthly reminders (R-A_SALES-72) ----------
    public function shopReminders(Request $r) { $this->can($r, 'orders.SEE'); return \App\Services\ShopReminders::list($r->user(), $r->only(['shop'])); }
    public function shopReminderSave(Request $r) { return $this->run(fn () => \App\Services\ShopReminders::save($r->only(['shop', 'month', 'year', 'type', 'text']), (int) $r->input('id') ?: null, $r->user())); }
    public function shopReminderDo(Request $r, int $id, string $what) { return $this->run(fn () => match ($what) { 'resolve' => \App\Services\ShopReminders::resolve($id, $r->user()), 'revert' => \App\Services\ShopReminders::revert($id, $r->user()), default => \App\Services\ShopReminders::remove($id, $r->user()) }); }
    public function shopReminderTemplate(Request $r) { return $this->run(fn () => $r->boolean('remove') ? \App\Services\ShopReminders::removeTemplate((int) $r->input('id'), $r->user()) : \App\Services\ShopReminders::saveTemplate($r->only(['shop', 'type', 'text', 'due_day', 'f_active']), (int) $r->input('id') ?: null, $r->user())); }

    // ---------- carrier cost model and preset matrix (R-A_SALES-69, 70) ----------
    public function carrierMatrix(Request $r) { $this->can($r, 'orders.SEE'); return \App\Services\Carriers::matrix($r->user(), $r->only(['carrier'])); }
    public function carrierSave(Request $r, string $what)
    {
        return $this->run(function () use ($r, $what) { $id = (int) $r->input('id') ?: null; $d = $r->except(['id']); $u = $r->user();
            return match ($what) { 'carrier' => \App\Services\Carriers::saveCarrier($d, $id, $u), 'package' => \App\Services\Carriers::savePackage($d, $id, $u), 'service' => \App\Services\Carriers::saveService($d, $id, $u), 'packing' => \App\Services\Carriers::savePacking($d, $id, $u),
                'preset' => \App\Services\Carriers::savePreset($d, $id, $u), 'preset-delete' => \App\Services\Carriers::deletePreset((int) $id, $u), 'rate' => \App\Services\Carriers::setRate(strtoupper((string) $r->input('kind')), (int) $id, $r->input('rate'), $r->input('valid_from'), $u) }; });
    }
    public function carrierRateHistory(Request $r, string $kind, int $id) { $this->can($r, 'orders.SEE'); return ['rows' => \App\Services\Carriers::history(strtoupper($kind), $id)]; }
    public function carrierCheapest(Request $r) { $this->can($r, 'orders.SEE'); return ['best' => \App\Services\Carriers::cheapest((float) $r->query('weight', 0), (float) $r->query('l', 0), (float) $r->query('w', 0), (float) $r->query('h', 0), (int) $r->query('marketplace') ?: null)]; }

    public function saleDsConflicts(Request $r) { $this->can($r, 'orders.SEE'); return \App\Services\DsMatch::conflicts($this->saleFilters($r), $r->user()); }
    public function saleDsPick(Request $r, int $id) { return $this->run(fn () => \App\Services\DsMatch::pick($id, (int) $r->input('item'), $r->user())); }
}
