<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Daily fulfilment orders (RULES_A_SALES R-A_SALES-75 / 76 / 77): a seller types an order to ship; a manager / TL sets the carrier service, tracking and status;
 * the selection is exported as the 25-column carrier file. Each order is a normal sale line (stock is reserved like any order) plus its extra fields.
 * The same queue serves any marketplace. For a daily-only profile the sale price comes later from the money report – a month cannot be confirmed while such a line has no price.
 */
class Fulfilment
{
    public const TEXT = ['item_name' => 255, 'custom_1' => 255, 'custom_2' => 255, 'custom_3' => 255, 'buyer_full_name' => 150, 'buyer_first_name' => 150, 'buyer_last_name' => 150, 'buyer_email' => 190, 'buyer_phone' => 40, 'recipient_first_name' => 150, 'recipient_last_name' => 150, 'recipient_phone' => 40, 'state' => 100, 'address_1' => 255, 'address_2' => 255, 'address_3' => 255, 'country_name' => 100];
    /** search option => [label, column, managers only] */
    public const SEARCH = ['order_no' => ['Order Number', 's.order_no', false], 'sku' => ['SKU', 's.sku', false], 'item' => ['Item Name / Title', 'x.item_name', false], 'buyer_first' => ['Buyer First Name', 'x.buyer_first_name', false], 'buyer_last' => ['Buyer Last Name', 'x.buyer_last_name', false], 'buyer_email' => ['Buyer Email', 'x.buyer_email', false], 'buyer_phone' => ['Buyer Phone', 'x.buyer_phone', false],
        'recipient_first' => ['Recipient First Name', 'x.recipient_first_name', false], 'recipient_last' => ['Recipient Last Name', 'x.recipient_last_name', false], 'recipient_phone' => ['Recipient Phone', 'x.recipient_phone', false], 'country' => ['Country', 'x.country_name', false], 'state' => ['State', 'x.state', false], 'city' => ['City', 's.ship_to_city', false], 'tracking' => ['Order Tracking', 's.tracking_no', true]];
    public const PRESETS = ['today' => 'Today', 'yesterday' => 'Yesterday', 'last7' => 'Last 7 days', 'last30' => 'Last 30 days', 'this_month' => 'This month', 'last_month' => 'Last month'];
    public const EXPORT_HEAD = ['Order #', 'Order Date', 'Item SKU', 'Item Name / Title', 'Item Quantity', 'Buyer First Name', 'Buyer Last Name', 'Buyer Email', 'Buyer Phone', 'Recipient First Name', 'Recipient Last Name', 'Recipient Phone', 'Address Line 1', 'Address Line 2', 'Address Line 3', 'City', 'State', 'Postal Code', 'Country', 'Country Code', 'Length(cm)', 'Width(cm)', 'Height(cm)', 'Weight(gr)', 'Shipping Service'];

    public static function manages(?object $u): bool { return (bool) ($u && Perm::has($u, 'orders.fulfilManage')); }

    private static function shops(?object $u): array { return array_values(array_filter(ShopLabel::list($u), fn ($s) => (int) $s['active'])); }

    /** [from, to] of a preset, or of from / to, or of one date. */
    public static function range(array $f, bool $manager, ?string $today = null): array
    {
        $t = $today ?? date('Y-m-d'); $day = fn ($v) => ($d = trim((string) $v) === '' ? '' : Parse::date((string) $v)) ? $d : null;
        if (! $manager) { $d = $day($f['date'] ?? null); return [$d, $d]; }      // a seller has one date
        if (! empty($f['range']) && preg_match('/^(\d{4}-\d{2}-\d{2})\[-\](\d{4}-\d{2}-\d{2})$/', (string) $f['range'], $m)) return [$m[1], $m[2]];      // sent as from[-]to
        return match ($f['preset'] ?? '') { 'today' => [$t, $t], 'yesterday' => [date('Y-m-d', strtotime("$t -1 day")), date('Y-m-d', strtotime("$t -1 day"))], 'last7' => [date('Y-m-d', strtotime("$t -6 days")), $t], 'last30' => [date('Y-m-d', strtotime("$t -29 days")), $t],
            'this_month' => [substr($t, 0, 8) . '01', date('Y-m-t', strtotime($t))], 'last_month' => [date('Y-m-01', strtotime(substr($t, 0, 8) . '01 -1 month')), date('Y-m-t', strtotime(substr($t, 0, 8) . '01 -1 month'))], default => [$day($f['from'] ?? null), $day($f['to'] ?? null)] };
    }

    private static function query(array $f, ?object $u, ?string $today = null)
    {
        $mg = self::manages($u); $q = DB::table('mo_sale_fulfilment as x')->join('t_sales as s', 's.id', '=', 'x.fk_id_sale')->where('x.fk_id_ent', Context::ent()); $scope = Perm::shops($u); if ($scope !== null) $q->whereIn('s.fk_id_branch', $scope);
        if (! $mg) $q->where('x.created_by', $u->id ?? 0);      // a seller sees only the rows he created on his shops
        if (! empty($f['shop'])) $q->where('s.fk_id_branch', (int) $f['shop']); if (($f['status'] ?? '') === 'pending') $q->where('x.f_done', 0); elseif (($f['status'] ?? '') === 'done') $q->where('x.f_done', 1);
        [$from, $to] = self::range($f, $mg, $today); if ($from) $q->where('s.d_order', '>=', $from); if ($to) $q->where('s.d_order', '<=', $to);
        $kw = trim((string) ($f['q'] ?? '')); $by = (string) ($f['by'] ?? '');
        if ($kw !== '') { if (! isset(self::SEARCH[$by]) || (self::SEARCH[$by][2] && ! $mg)) throw new \InvalidArgumentException('Please select an option first');
            $q->where(self::SEARCH[$by][1], 'like', '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $kw) . '%'); }
        return $q;
    }

    private static function cols(): array { return ['x.*', 's.id', 's.fk_id_branch', 's.order_no', 's.d_order', 's.sku', 's.qty', 's.sale_rate', 's.total_amount', 's.status', 's.f_locked', 's.sale_source', 's.tracking_no', 's.carrier_service_text', 's.ship_to_city', 's.ship_to_postcode', 's.ship_to_country_code', 's.parcel_l_cm', 's.parcel_w_cm', 's.parcel_h_cm', 's.parcel_weight_g', 'b.shop_no', 'b.shop_name']; }

    private static function row(object $r, bool $mg): array
    {
        $done = (bool) $r->f_done; $tracked = trim((string) $r->tracking_no) !== '';
        return ['id' => (int) $r->id, 'shop' => (int) $r->fk_id_branch, 'shop_label' => trim((string) $r->shop_no) . ' (' . trim((string) $r->shop_name) . ')', 'order_no' => $r->order_no, 'date' => date('d-m-Y', strtotime((string) $r->d_order)), 'd_order' => substr((string) $r->d_order, 0, 10), 'sku' => str_starts_with((string) $r->sku, 'NOSKU-') ? '' : $r->sku, 'qty' => (int) $r->qty,
            'length' => $r->parcel_l_cm, 'width' => $r->parcel_w_cm, 'height' => $r->parcel_h_cm, 'weight' => $r->parcel_weight_g, 'city' => (string) $r->ship_to_city, 'postcode' => (string) $r->ship_to_postcode, 'country_code' => (string) $r->ship_to_country_code,
            'carrier_service' => (string) $r->carrier_service_text, 'tracking_no' => (string) $r->tracking_no, 'done' => $done, 'status' => $done ? 'Done' : 'Pending', 'colour' => ($mg ? $done : $tracked) ? 'green' : 'red',      // manager: red = Pending, green = Done; seller: red until tracking is filled
            'matched' => $r->sale_source === 'active', 'no_price' => (float) $r->total_amount <= 0, 'locked' => $r->status !== 'Pending' || (int) $r->f_locked === 1] + array_intersect_key((array) $r, self::TEXT);
    }

    public static function list(array $f, ?object $u, ?string $today = null): array
    {
        Perm::need($u, 'orders.fulfilSee'); $mg = self::manages($u); $per = min(200, max(10, (int) ($f['per_page'] ?? 50))); $page = max(1, (int) ($f['page'] ?? 1)); $q = self::query($f, $u, $today); $n = (clone $q)->count();
        $rows = $q->join('t_branches as b', 'b.id', '=', 's.fk_id_branch')->orderByDesc('s.d_order')->orderByDesc('s.id')->offset(($page - 1) * $per)->limit($per)->get(self::cols()); [$from, $to] = self::range($f, $mg, $today);
        return ['rows' => $rows->map(fn ($r) => self::row($r, $mg))->all(), 'count' => $n, 'page' => $page, 'pages' => max(1, (int) ceil($n / $per)), 'manager' => $mg, 'can_add' => Perm::has($u, 'orders.fulfilAdd'), 'can_export' => Perm::has($u, 'orders.fulfilExport'), 'from' => $from, 'to' => $to, 'presets' => $mg ? self::PRESETS : null,
            'search' => array_map(fn ($s) => $s[0], array_filter(self::SEARCH, fn ($s) => $mg || ! $s[2])), 'shops' => array_map(fn ($s) => ['id' => $s['id'], 'name' => $s['label']], self::shops($u)), 'defaults' => ['length' => 25, 'width' => 20, 'height' => 2, 'country_code' => 'GB', 'qty' => 1, 'd_order' => $today ?? date('Y-m-d')],
            'services' => DB::table('t_carrier_pkg_svc as cv')->join('t_carriers as c', 'c.id', '=', 'cv.fk_id_carrier')->whereIn('c.fk_id_ent', [0, (int) Context::ent()])->where('cv.f_active', 1)->orderBy('c.carrier_name')->orderBy('cv.pkg_svc_name')->get([DB::raw("CONCAT(c.carrier_name, '-', cv.pkg_svc_name) as name")])->pluck('name')->all()];
    }

    private static function one(int $id, ?object $u): object
    {
        $r = DB::table('mo_sale_fulfilment as x')->join('t_sales as s', 's.id', '=', 'x.fk_id_sale')->join('t_branches as b', 'b.id', '=', 's.fk_id_branch')->where('x.fk_id_sale', $id)->where('x.fk_id_ent', Context::ent())->first(self::cols()) ?? abort(404, 'Fulfilment order not found.');
        if (! Perm::shopAllowed($u, (int) $r->fk_id_branch) || (! self::manages($u) && (int) $r->created_by !== (int) ($u->id ?? 0))) abort(403, "You don't have rights for this order."); return $r;
    }

    /** Add (id null) or edit. Status, carrier service and tracking: managers / TL only, and only on edit. */
    public static function save(array $d, ?int $id, ?object $u): array
    {
        $mg = self::manages($u); Perm::need($u, $id ? 'orders.fulfilSee' : 'orders.fulfilAdd'); $old = $id ? self::one($id, $u) : null; $e = [];
        if ($old && ($old->status !== 'Pending' || (int) $old->f_locked)) throw new \InvalidArgumentException('This order is confirmed. It cannot be changed.');
        $shopId = (int) ($old->fk_id_branch ?? ($d['shop'] ?? 0)); if (! $shopId) $e['shop'] = 'Choose the shop.'; elseif (! in_array($shopId, array_column(self::shops($u), 'id'), true)) $e['shop'] = 'Choose one of your active shops.';
        $no = trim((string) ($old->order_no ?? ($d['order_no'] ?? ''))); if ($no === '') $e['order_no'] = 'Write the order number.';
        $date = trim((string) ($d['d_order'] ?? '')) === '' ? '' : Parse::date((string) $d['d_order']); if (! $date) $e['d_order'] = 'Choose the order date.'; elseif ($date > date('Y-m-d', strtotime('+1 day'))) $e['d_order'] = 'The order date cannot be in the future.';
        $qty = trim((string) ($d['qty'] ?? '')) === '' ? 1 : (int) $d['qty']; if ($qty < 1) $e['qty'] = 'Quantity must be 1 or more.';
        $num = function (string $k, float $def) use ($d, &$e) { $v = trim((string) ($d[$k] ?? '')); if ($v === '') return $def; if (! is_numeric($v) || (float) $v < 0) { $e[$k] = 'Write a number of 0 or more.'; return $def; } return (float) $v; };
        $l = $num('length', 25); $w = $num('width', 20); $h = $num('height', 2); $g = $num('weight', 0); $email = trim((string) ($d['buyer_email'] ?? '')); if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) $e['buyer_email'] = 'This is not an e-mail address.';
        $cc = strtoupper(trim((string) ($d['country_code'] ?? ''))) ?: 'GB'; if (! preg_match('/^[A-Z]{2}$/', $cc)) $e['country_code'] = 'Use the 2-letter country code, for example GB.';
        if ($e) throw \Illuminate\Validation\ValidationException::withMessages($e);
        $x = []; foreach (self::TEXT as $k => $len) $x[$k] = mb_substr(trim((string) ($d[$k] ?? '')), 0, $len); $sku = trim((string) ($old ? $old->sku : ($d['sku'] ?? ''))); if (! $old && $sku === '') $sku = 'NOSKU-' . mb_substr($no, 0, 80);
        $name = $x['buyer_full_name'] ?: trim($x['buyer_first_name'] . ' ' . $x['buyer_last_name']); $ship = trim($x['recipient_first_name'] . ' ' . $x['recipient_last_name']) ?: $name; $addr = implode(', ', array_filter([$x['address_1'], $x['address_2'], $x['address_3']]));
        return DB::transaction(function () use ($d, $id, $old, $u, $mg, $shopId, $no, $date, $qty, $l, $w, $h, $g, $cc, $x, $sku, $name, $ship, $addr) {
            if (! $old) { if (DB::table('t_sales')->where('fk_id_ent', Context::ent())->where('fk_id_branch', $shopId)->where('order_no', $no)->where('sku', $sku)->exists()) throw \Illuminate\Validation\ValidationException::withMessages(['order_no' => "Order $no with this SKU is already saved for this shop."]);
                $r = SalesService::intake($shopId, [['order_no' => $no, 'd_order' => $date, 'sku' => $sku, 'qty' => $qty, 'sale_rate' => 0, 'cust_name' => $name, 'cust_contacts' => trim($x['buyer_email'] . ' ' . $x['buyer_phone']), 'product_info' => $x['item_name'], 'ship_to_name' => $ship, 'ship_to_phone' => $x['recipient_phone'] ?: $x['buyer_phone'], 'ship_to_address' => $addr, 'ship_to_city' => trim((string) ($d['city'] ?? '')), 'ship_to_postcode' => mb_strtoupper(trim((string) ($d['postcode'] ?? ''))), 'ship_to_country_code' => $cc]], ['no_zero_task' => true]);
                $id = (int) $r['ids'][0]; DB::table('mo_sale_fulfilment')->insert($x + ['fk_id_sale' => $id, 'fk_id_ent' => (int) Context::ent(), 'f_done' => 0, 'created_by' => $u->id, 'created_at' => now()]); }      // new rows are Pending
            else DB::table('mo_sale_fulfilment')->where('fk_id_sale', $id)->update($x + ['updated_at' => now()]);
            $upd = ['parcel_l_cm' => $l, 'parcel_w_cm' => $w, 'parcel_h_cm' => $h, 'parcel_weight_g' => (int) round($g), 'ship_to_country_code' => $cc];
            if ($old) { $upd += ['d_order' => $date, 'qty' => $qty, 'cust_name' => $name, 'product_info' => $x['item_name'], 'ship_to_name' => $ship, 'ship_to_phone' => $x['recipient_phone'] ?: $x['buyer_phone'], 'ship_to_address' => $addr, 'ship_to_city' => trim((string) ($d['city'] ?? '')), 'ship_to_postcode' => mb_strtoupper(trim((string) ($d['postcode'] ?? ''))), 'updated_at' => now()];
                if ($mg) { $upd += ['carrier_service_text' => mb_substr(trim((string) ($d['carrier_service'] ?? '')), 0, 100) ?: null, 'tracking_no' => mb_substr(trim((string) ($d['tracking_no'] ?? '')), 0, 60) ?: null]; if (array_key_exists('done', $d)) self::mark([$id], (bool) $d['done'], $u); } }
            DB::table('t_sales')->where('id', $id)->update($upd); Audit::log('t_sales', $id, $old ? 'UPDATE' : 'INSERT', ['fulfilment order' => $no, 'by a manager' => $mg && $old ? 'yes' : 'no']);
            return ['message' => $old ? 'Order saved.' : "Order $no added. It is Pending.", 'id' => $id, 'row' => self::row(self::one($id, $u), $mg)];
        });
    }

    private static function mark(array $ids, bool $done, ?object $u): int
    {
        return DB::table('mo_sale_fulfilment')->whereIn('fk_id_sale', $ids)->where('f_done', $done ? 0 : 1)->update(['f_done' => $done ? 1 : 0, 'done_by' => $done ? ($u->id ?? null) : null, 'done_at' => $done ? now() : null, 'updated_at' => now()]);
    }

    /** Managers / TL: "Change Status" or "Delete Selected" for the ticked rows. */
    public static function bulk(array $ids, string $action, ?object $u, ?string $status = null): array
    {
        if (! self::manages($u)) throw new \InvalidArgumentException('Only a manager or team leader can change the status or delete orders here.'); $ids = array_values(array_unique(array_filter(array_map('intval', $ids)))); if (! $ids) throw new \InvalidArgumentException('Tick at least one order.');
        $rows = array_map(fn ($id) => self::one($id, $u), $ids);
        return DB::transaction(function () use ($rows, $ids, $action, $status, $u) {
            if ($action === 'status') { if (! in_array($status, ['done', 'pending'], true)) throw new \InvalidArgumentException('Choose Pending or Done.'); $n = self::mark($ids, $status === 'done', $u); Audit::log('mo_sale_fulfilment', null, 'UPDATE', ['ids' => $ids, 'status' => $status]); return ['message' => "$n order(s) set to " . ($status === 'done' ? 'Done' : 'Pending') . '.', 'changed' => $n]; }
            if ($action !== 'delete') throw new \InvalidArgumentException('Unknown action.'); $n = 0; $kept = [];
            foreach ($rows as $r) { if ($r->status !== 'Pending' || (int) $r->f_locked) { $kept[] = $r->order_no; continue; } $s = DB::table('t_sales')->where('id', $r->id)->first();
                DB::table('mo_recycle_bin')->insert(['fk_id_ent' => (int) $s->fk_id_ent, 'table_name' => 't_sales', 'row_id' => (int) $s->id, 'label' => mb_substr("Fulfilment order {$s->order_no}", 0, 255), 'group_key' => 'ful-' . $s->id . '-' . time(), 'row_json' => json_encode(['sale' => (array) $s, 'fulfilment' => (array) DB::table('mo_sale_fulfilment')->where('fk_id_sale', $s->id)->first()]), 'deleted_by' => $u->id, 'deleted_at' => now(), 'note' => 'Deleted in the fulfilment queue']);
                DispatchService::release((int) $s->id, 'Fulfilment order deleted'); DB::table('mo_sale_fulfilment')->where('fk_id_sale', $s->id)->delete(); DB::table('t_sales')->where('id', $s->id)->delete(); Audit::log('t_sales', (int) $s->id, 'DELETE', ['fulfilment order' => $s->order_no]); $n++; }
            return ['message' => "$n order(s) deleted." . ($kept ? ' Kept because they are confirmed: ' . implode(', ', $kept) . '.' : ''), 'deleted' => $n, 'kept' => $kept];
        });
    }

    /** Phone for the carrier file. OLD: "+44" → "0", brackets removed, spaces stay. NATIONAL: the country's own dialling code → "0", then digits only. */
    public static function phone(?string $p, ?string $countryCode = 'GB', ?string $mode = null): string
    {
        $p = trim((string) $p); if ($p === '') return ''; $mode ??= Choices::get('FULFIL_PHONE_FORMAT', Context::ent());
        if ($mode === 'OLD') return str_replace(['+44', '(', ')'], ['0', '', ''], $p);
        $dial = ['GB' => '44', 'IE' => '353', 'FR' => '33', 'DE' => '49', 'ES' => '34', 'IT' => '39', 'NL' => '31', 'BE' => '32', 'PL' => '48', 'US' => '1', 'CA' => '1', 'AU' => '61', 'PK' => '92', 'IN' => '91', 'BD' => '880', 'AE' => '971'][strtoupper((string) $countryCode)] ?? null;
        $plus = str_starts_with($p, '+') || str_starts_with($p, '00'); $digits = preg_replace('/\D/', '', $p); if (str_starts_with($p, '00')) $digits = substr($digits, 2);
        if ($plus && $dial && str_starts_with($digits, $dial)) { $rest = ltrim(substr($digits, strlen($dial)), '0'); return in_array($dial, ['1'], true) ? $rest : '0' . $rest; }
        return $plus ? '+' . $digits : $digits;      // a foreign number keeps its international form
    }

    /** The 25-column carrier file of the filtered rows (same filters and the same scope as the list). */
    public static function export(array $f, ?object $u, ?string $now = null): array
    {
        Perm::need($u, 'orders.fulfilExport'); $q = self::query($f, $u); if (! empty($f['ids'])) $q->whereIn('s.id', array_map('intval', (array) $f['ids'])); $mode = Choices::get('FULFIL_PHONE_FORMAT', Context::ent());
        $h = fopen('php://temp', 'w+'); fputcsv($h, self::EXPORT_HEAD, ',', '"', ''); $n = 0;
        foreach ($q->join('t_branches as b', 'b.id', '=', 's.fk_id_branch')->orderBy('s.d_order')->orderBy('s.id')->limit(20000)->get(self::cols()) as $r) { $n++; $cc = (string) $r->ship_to_country_code ?: 'GB';
            fputcsv($h, array_map(fn ($v) => DsMatch::guard((string) $v), [$r->order_no, date('d/m/Y', strtotime((string) $r->d_order)), str_starts_with((string) $r->sku, 'NOSKU-') ? '' : $r->sku, $r->item_name, (int) $r->qty, $r->buyer_first_name, $r->buyer_last_name, $r->buyer_email, self::phone($r->buyer_phone, $cc, $mode), $r->recipient_first_name, $r->recipient_last_name, self::phone($r->recipient_phone, $cc, $mode),
                $r->address_1, $r->address_2, $r->address_3, $r->ship_to_city, $r->state, $r->ship_to_postcode, $r->country_name, $cc, rtrim(rtrim((string) $r->parcel_l_cm, '0'), '.'), rtrim(rtrim((string) $r->parcel_w_cm, '0'), '.'), rtrim(rtrim((string) $r->parcel_h_cm, '0'), '.'), (int) $r->parcel_weight_g, $r->carrier_service_text]), ',', '"', ''); }
        rewind($h); $body = stream_get_contents($h); fclose($h); Audit::log('mo_sale_fulfilment', null, 'EXPORT', ['rows' => $n]);
        return ['name' => 'TikTokSale_' . date('YmdHis', $now ? strtotime($now) : time()) . '.csv', 'body' => $body, 'rows' => $n];      // 24-hour clock in the name (the old 12-hour name was a bug)
    }

    /** Month Confirm is refused while a fulfilment order of that shop and month has no sale price yet. */
    public static function confirmBlock(int $branchId, string $month): ?string
    {
        $n = DB::table('mo_sale_fulfilment as x')->join('t_sales as s', 's.id', '=', 'x.fk_id_sale')->where('s.fk_id_branch', $branchId)->whereRaw("DATE_FORMAT(s.d_order,'%Y-%m') = ?", [$month])->where('s.status', 'Pending')->where('s.total_amount', '<=', 0)->count();
        return $n ? "$n fulfilment order(s) of this month have no sale price yet. Load the money report of the shop (or type the prices on the Sales page), then confirm." : null;
    }
}
