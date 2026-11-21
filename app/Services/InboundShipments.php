<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Inbound shipment prep for a fulfilment centre / 3PL (R-H-49, 18_Labels §6, M146 / M200).
 * DRAFT (shipment + cartons, SINGLE = one SKU, MIXED = several) → "Reserve and pick" reserves the units (wh_reservations reason INBOUND_SHIPMENT,
 * shop of the shipment, ALLOCATED at once so order pick lists never take them) and builds one pick task (wh_pick_tasks type INBOUND, one line per
 * carton × SKU × bin, bins in walking order) → PICKING. The picker scans the carton label, then the item (SKU / marketplace item code), then the qty:
 * the units move bin → Dispatch area with their own lots (lots kept on the line). All picked → PACKED.
 * Labels: marketplace item labels (FNSKU-type code from the shop listing, else the marketplace SKU, else our SKU – one per unit), carton labels
 * (carton x / N, FC, shipment id, shop, SKU + qty, kg, size, scan code) and the picking & packing list – all from the same shipment rows.
 * "Shipped" = step 1 of the two-step transfer (R-D-48): the same lots Dispatch → TRANSIT (owner kept), reservations SHIPPED, task done.
 * "Received at the FC" = step 2: received qty TRANSIT → the destination location; a difference opens STK_FC_DIFF per SKU (difference stays in TRANSIT).
 */
class InboundShipments
{
    public const OPEN = ['DRAFT', 'PICKING', 'PACKED'];

    public static function code(object $s, int $no): string { return $s->shipment_ref . '-C' . str_pad((string) $no, 2, '0', STR_PAD_LEFT); }

    private static function ship(int $ent, int $id, bool $lock = false): object
    {
        return DB::table('fba_shipments')->where('id', $id)->where('fk_id_ent', $ent)->when($lock, fn ($q) => $q->lockForUpdate())->first() ?? throw new \InvalidArgumentException('Inbound shipment not found.');
    }

    /** Items of each carton: MIXED cartons from fba_carton_items, SINGLE cartons from the carton row itself. */
    public static function cartons(int $id): array
    {
        $out = [];
        foreach (DB::table('fba_cartons')->where('fk_id_fba_shipment', $id)->orderBy('carton_no')->get() as $c) {
            $items = DB::table('fba_carton_items as i')->join('t_product_vendors as p', 'p.id', '=', 'i.fk_id_product')->where('i.fk_id_fba_carton', $c->id)->orderBy('i.id')->get(['i.fk_id_product as product', 'p.SKU_VENDOR as sku', 'i.qty'])->map(fn ($r) => (array) $r)->all();
            if (! $items && $c->fk_id_product) $items = [['product' => (int) $c->fk_id_product, 'sku' => DB::table('t_product_vendors')->where('id', $c->fk_id_product)->value('SKU_VENDOR'), 'qty' => (int) $c->qty]];
            $out[] = ['id' => (int) $c->id, 'no' => (int) $c->carton_no, 'kind' => $c->carton_kind, 'items' => $items, 'units' => array_sum(array_column($items, 'qty')), 'kg' => (float) $c->weight_kg,
                'dims' => rtrim(rtrim((string) $c->length_cm, '0'), '.') . ' × ' . rtrim(rtrim((string) $c->width_cm, '0'), '.') . ' × ' . rtrim(rtrim((string) $c->height_cm, '0'), '.') . ' cm', 'label_status' => $c->label_status];
        }
        return $out;
    }

    /** New shipment with its cartons in one transaction. cartons: [{items: [{product, qty}], weight_kg, length_cm, width_cm, height_cm}] */
    public static function create(int $ent, array $d): array
    {
        $shop = (int) ($d['shop'] ?? 0); $from = (int) ($d['from_location'] ?? 0);
        $err = [];
        if (! $shop || ! DB::table('t_branches')->where('id', $shop)->where('f_active', 1)->exists()) $err['shop'] = 'Choose the shop the shipment goes to';
        if (! $from || ! DB::table('cat_inventory_locations')->where('id', $from)->where('fk_id_ent', $ent)->exists()) $err['from_location'] = 'Choose the site it ships from';
        foreach ((array) ($d['cartons'] ?? []) as $n => $c) foreach ((array) ($c['items'] ?? []) as $k => $i)      // a typed SKU / item code is enough (the phone form)
            if (empty($i['product']) && trim((string) ($i['sku'] ?? '')) !== '') $d['cartons'][$n]['items'][$k]['product'] = self::resolveItem($ent, $shop, (string) $i['sku']) ?? -1;
        $cartons = array_values(array_filter((array) ($d['cartons'] ?? []), fn ($c) => array_filter((array) ($c['items'] ?? []), fn ($i) => (int) ($i['qty'] ?? 0) > 0)));
        if (! $cartons) $err['cartons'] = 'Add at least one carton with an item';
        foreach ($cartons as $n => $c) {
            foreach ((array) $c['items'] as $i) if ((int) ($i['qty'] ?? 0) > 0 && ! DB::table('t_product_vendors')->where('id', (int) ($i['product'] ?? 0))->where('fk_id_ent', $ent)->exists()) $err['cartons'] = 'Carton ' . ($n + 1) . ': choose the product';
            if ((float) ($c['weight_kg'] ?? 0) <= 0) $err['cartons'] = 'Carton ' . ($n + 1) . ': enter the weight (kg)';
        }
        if ($err) throw new ValidationErrors($err);
        return DB::transaction(function () use ($ent, $d, $shop, $from, $cartons) {
            $dest = (int) ($d['dest_location'] ?? 0) ?: StockService::systemLocation($ent, 'TRANSIT');
            $id = (int) DB::table('fba_shipments')->insertGetId(['fk_id_ent' => $ent, 'shipment_ref' => mb_substr(trim((string) ($d['shipment_ref'] ?? '')) ?: Numbering::next('FBA', $ent), 0, 60), 'fk_id_branch' => $shop,
                'fk_id_from_location' => $from, 'fk_id_dest_location' => $dest, 'd_created' => date('Y-m-d'), 'status' => 'DRAFT', 'notes' => $d['notes'] ?? null, 'created_by' => Context::userId(), 'created_at' => now()]);
            foreach ($cartons as $n => $c) {
                $items = array_values(array_filter((array) $c['items'], fn ($i) => (int) ($i['qty'] ?? 0) > 0)); $single = count(array_unique(array_column($items, 'product'))) === 1;
                $cid = (int) DB::table('fba_cartons')->insertGetId(['fk_id_fba_shipment' => $id, 'carton_no' => $n + 1, 'carton_kind' => $single ? 'SINGLE' : 'MIXED', 'fk_id_product' => $single ? (int) $items[0]['product'] : null,
                    'qty' => $single ? array_sum(array_map(fn ($i) => (int) $i['qty'], $items)) : null, 'weight_kg' => (float) $c['weight_kg'], 'length_cm' => (float) ($c['length_cm'] ?? 0), 'width_cm' => (float) ($c['width_cm'] ?? 0), 'height_cm' => (float) ($c['height_cm'] ?? 0)]);
                foreach ($items as $i) DB::table('fba_carton_items')->insert(['fk_id_fba_carton' => $cid, 'fk_id_product' => (int) $i['product'], 'sku' => DB::table('t_product_vendors')->where('id', (int) $i['product'])->value('SKU_VENDOR'), 'qty' => (int) $i['qty']]);
            }
            Audit::log('fba_shipments', $id, 'INSERT', ['cartons' => count($cartons)], null, $ent);
            EventBus::fire('SHIPMENT_SAVED', 'fba_shipments', $id, ['cartons' => count($cartons)], $ent);
            return ['id' => $id, 'message' => 'Inbound shipment saved with ' . count($cartons) . ' carton(s).'];
        });
    }

    /**
     * The marketplace's carton file (Imp_inbound_cartons, R-G_CORE-96): CSV or Excel, two shapes —
     * one row per carton × SKU (Carton / Box no, SKU, Qty, Weight kg, Length / Width / Height cm), or the pack-list grid
     * (one row per SKU with "Box 1 …", "Box 2 …" quantity columns, and rows "Box weight", "Box length / width / height").
     * Only a DRAFT shipment; the cartons of the file replace the typed ones; SKUs are matched by SKU or the listing's item code. Event INBOUND_CARTONS_IMPORTED.
     */
    public static function importCartons(int $ent, int $id, string $path, string $name): array
    {
        $s = self::ship($ent, $id);
        if ($s->status !== 'DRAFT') throw new \InvalidArgumentException('Cartons can be imported only while the shipment is a draft.');
        $rows = self::sheet($path, $name);
        $hi = null; foreach (array_slice($rows, 0, 15, true) as $i => $r) if (in_array('sku', array_map(fn ($c) => Parse::header($c), $r), true) || in_array('merchant sku', array_map(fn ($c) => Parse::header($c), $r), true)) { $hi = $i; break; }
        if ($hi === null) throw new \InvalidArgumentException('No header row with "SKU" was found in the first 15 lines.');
        $h = array_map(fn ($c) => Parse::header($c), $rows[$hi]); $col = fn (array $names) => ($k = array_values(array_intersect($names, $h))) ? array_search($k[0], $h, true) : null;
        $sku = $col(['sku', 'merchant sku']); $boxCols = []; foreach ($h as $i => $x) if (preg_match('/^box\s*#?\s*(\d+)/', $x, $m)) $boxCols[$i] = (int) $m[1];
        $cartons = []; $errors = [];
        if ($boxCols) {      // pack-list grid
            foreach (array_slice($rows, $hi + 1, null, true) as $ln => $r) {
                $first = Parse::header($r[0] ?? ''); $code = trim((string) ($r[$sku] ?? ''));
                foreach (['weight' => 'weight_kg', 'length' => 'length_cm', 'width' => 'width_cm', 'height' => 'height_cm'] as $w => $f)
                    if (preg_match("/box $w/", $first) || preg_match("/box $w/", Parse::header($code))) { foreach ($boxCols as $i => $b) if (is_numeric($r[$i] ?? null)) $cartons[$b][$f] = (float) $r[$i]; continue 2; }
                if ($code === '') continue;
                $p = self::resolveItem($ent, (int) $s->fk_id_branch, $code); if (! $p) { $errors[] = 'Line ' . ($ln + 1) . ": SKU $code not found"; continue; }
                foreach ($boxCols as $i => $b) if ((int) ($r[$i] ?? 0) > 0) $cartons[$b]['items'][] = ['product' => $p, 'qty' => (int) $r[$i]];
            }
        } else {
            $cn = $col(['carton', 'carton no', 'carton number', 'box', 'box no', 'box number']); $q = $col(['qty', 'quantity', 'units']);
            if ($cn === null || $q === null) throw new \InvalidArgumentException('The file needs the columns Carton (or Box), SKU and Qty.');
            $dim = ['weight_kg' => $col(['weight', 'weight kg', 'weight (kg)', 'gross weight']), 'length_cm' => $col(['length', 'length cm', 'length (cm)']), 'width_cm' => $col(['width', 'width cm', 'width (cm)']), 'height_cm' => $col(['height', 'height cm', 'height (cm)'])];
            foreach (array_slice($rows, $hi + 1, null, true) as $ln => $r) {
                $code = trim((string) ($r[$sku] ?? '')); $b = (int) preg_replace('/\D/', '', (string) ($r[$cn] ?? ''));
                if ($code === '' && ! $b) continue;
                if (! $b) { $errors[] = 'Line ' . ($ln + 1) . ': carton number missing'; continue; }
                $p = self::resolveItem($ent, (int) $s->fk_id_branch, $code); if (! $p) { $errors[] = 'Line ' . ($ln + 1) . ": SKU $code not found"; continue; }
                if ((int) ($r[$q] ?? 0) <= 0) { $errors[] = 'Line ' . ($ln + 1) . ': quantity must be more than 0'; continue; }
                $cartons[$b]['items'][] = ['product' => $p, 'qty' => (int) $r[$q]];
                foreach ($dim as $f => $i) if ($i !== null && is_numeric($r[$i] ?? null) && (float) $r[$i] > 0) $cartons[$b][$f] = (float) $r[$i];
            }
        }
        $cartons = array_filter($cartons, fn ($c) => ! empty($c['items'])); ksort($cartons);
        foreach ($cartons as $b => $c) if ((float) ($c['weight_kg'] ?? 0) <= 0) $errors[] = "Carton $b: weight missing";
        if ($errors) throw new ValidationErrors(['file' => implode(' · ', array_slice($errors, 0, 20)) . (count($errors) > 20 ? ' · …' : '')]);
        if (! $cartons) throw new \InvalidArgumentException('No carton lines in the file.');
        return DB::transaction(function () use ($ent, $id, $cartons, $name) {
            $old = DB::table('fba_cartons')->where('fk_id_fba_shipment', $id)->pluck('id')->all();
            DB::table('fba_carton_items')->whereIn('fk_id_fba_carton', $old ?: [0])->delete(); DB::table('fba_cartons')->where('fk_id_fba_shipment', $id)->delete();
            $n = 0; $lines = 0;
            foreach ($cartons as $c) {
                $n++; $items = []; foreach ($c['items'] as $i) $items[$i['product']] = ($items[$i['product']] ?? 0) + $i['qty'];
                $single = count($items) === 1;
                $cid = (int) DB::table('fba_cartons')->insertGetId(['fk_id_fba_shipment' => $id, 'carton_no' => $n, 'carton_kind' => $single ? 'SINGLE' : 'MIXED', 'fk_id_product' => $single ? array_key_first($items) : null, 'qty' => $single ? array_sum($items) : null,
                    'weight_kg' => (float) $c['weight_kg'], 'length_cm' => (float) ($c['length_cm'] ?? 0), 'width_cm' => (float) ($c['width_cm'] ?? 0), 'height_cm' => (float) ($c['height_cm'] ?? 0)]);
                foreach ($items as $p => $q) { DB::table('fba_carton_items')->insert(['fk_id_fba_carton' => $cid, 'fk_id_product' => $p, 'sku' => DB::table('t_product_vendors')->where('id', $p)->value('SKU_VENDOR'), 'qty' => $q]); $lines++; }
            }
            Audit::log('fba_shipments', $id, 'UPDATE', ['cartons_file' => $name, 'cartons' => $n, 'lines' => $lines], 'Cartons imported', $ent);
            EventBus::fire('INBOUND_CARTONS_IMPORTED', 'fba_shipments', $id, ['cartons' => $n, 'lines' => $lines, 'file' => $name], $ent);
            return ['cartons' => $n, 'lines' => $lines, 'message' => "$n carton(s), $lines line(s) imported – they replace the cartons typed before."];
        });
    }

    /** Rows of a CSV or the first sheet of an Excel file. */
    private static function sheet(string $path, string $name): array
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (in_array($ext, ['xlsx', 'xls'], true)) return \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path)->setReadDataOnly(true)->load($path)->getSheet(0)->toArray(null, true, false, false);
        if (! in_array($ext, ['csv', 'txt', 'tsv'], true)) throw new \InvalidArgumentException('Upload the carton file as CSV or Excel.');
        $raw = (string) file_get_contents($path); $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw); $first = strtok($raw, "\n") ?: '';
        $del = substr_count($first, "\t") > substr_count($first, ',') ? "\t" : (substr_count($first, ';') > substr_count($first, ',') ? ';' : ',');
        $fh = fopen('php://temp', 'w+'); fwrite($fh, $raw); rewind($fh); $out = [];
        while (($r = fgetcsv($fh, 0, $del, '"', '\\')) !== false) $out[] = array_map(fn ($c) => trim((string) $c), $r);
        fclose($fh); return $out;
    }

    /** Reserve the units and build the pick task. Refused (nothing saved) when a SKU is short. */
    public static function reserve(int $ent, int $id): array
    {
        return DB::transaction(function () use ($ent, $id) {
            $s = self::ship($ent, $id, true);
            if ($s->status !== 'DRAFT') throw new \InvalidArgumentException('This shipment is already ' . strtolower($s->status) . '.');
            $cartons = self::cartons($id); $need = [];
            foreach ($cartons as $c) foreach ($c['items'] as $i) $need[(int) $i['product']] = ($need[(int) $i['product']] ?? 0) + (int) $i['qty'];
            $short = [];
            foreach ($need as $p => $q) { DB::table('t_product_vendors')->where('id', $p)->lockForUpdate()->first(); $have = StockService::available($p); if ($have < $q) $short[] = DB::table('t_product_vendors')->where('id', $p)->value('SKU_VENDOR') . " (need $q, free $have)"; }
            if ($short) throw new \InvalidArgumentException('Not enough free stock: ' . implode(', ', $short) . '. Nothing was reserved.');
            $site = (int) (DB::table('cat_inventory_locations')->where('id', $s->fk_id_from_location)->value('fk_id_site') ?: $s->fk_id_from_location);
            $res = [];
            foreach ($need as $p => $q) {
                $owner = DispatchService::ownerRule((object) ['fk_product_id' => $p]);
                $res[$p] = (int) DB::table('wh_reservations')->insertGetId(['fk_id_ent' => $ent, 'fk_id_branch' => $s->fk_id_branch, 'fk_id_site' => $site, 'fk_id_fba_shipment' => $id, 'fk_id_product' => $p, 'owner_rule' => $owner ? 'PRIVATE' : 'POOL',
                    'fk_id_owner_seg' => $owner ?? 0, 'qty' => $q, 'qty_short' => 0, 'qty_picked' => 0, 'status' => 'ALLOCATED', 'reason' => 'INBOUND_SHIPMENT', 'created_by' => Context::userId(), 'created_at' => now(), 'updated_at' => now()]);
                DB::table('wh_reservation_history')->insert(RecordService::fillNotNull('wh_reservation_history', ['fk_id_reservation' => $res[$p], 'old_status' => null, 'new_status' => 'ALLOCATED', 'old_qty' => null, 'new_qty' => $q, 'reason' => 'Inbound shipment ' . $s->shipment_ref, 'changed_by' => Context::userId(), 'changed_at' => now()]));
            }
            $task = (int) DB::table('wh_pick_tasks')->insertGetId(['fk_id_ent' => $ent, 'task_code' => Numbering::next('PCK', $ent), 'fk_id_site' => $site, 'task_type' => 'INBOUND', 'pick_mode' => 'SINGLE', 'fk_id_fba_shipment' => $id, 'status' => 'OPEN', 'created_by' => Context::userId(), 'created_at' => now()]);
            $lines = []; $taken = [];
            foreach ($cartons as $c) foreach ($c['items'] as $i) {
                $p = (int) $i['product']; $left = (int) $i['qty']; $owner = DB::table('wh_reservations')->where('id', $res[$p])->value('fk_id_owner_seg');
                foreach (DispatchService::binsOf($p, $ent, $owner ? (int) $owner : null) as $b) {
                    if ($left <= 0) break;
                    $k = "$p:{$b->loc}"; $taken[$k] ??= (int) DB::table('wh_pick_task_lines')->where('fk_id_product', $p)->where('fk_id_from_location', $b->loc)->where('line_status', 'OPEN')->sum(DB::raw('qty - qty_done'));
                    $free = (int) $b->qty - $taken[$k]; if ($free <= 0) continue;
                    $take = min($left, $free); $taken[$k] += $take; $left -= $take;
                    $lines[] = ['seq' => [(int) $c['no'], (int) $b->pick_seq], 'row' => ['fk_id_pick_task' => $task, 'fk_id_product' => $p, 'fk_id_from_location' => $b->loc, 'fk_id_reservation' => $res[$p], 'fk_id_fba_carton' => $c['id'], 'qty' => $take, 'qty_done' => 0, 'qty_short' => 0, 'line_status' => 'OPEN']];
                }
                if ($left > 0) throw new \InvalidArgumentException('Not enough units on the shelves for ' . $i['sku'] . " (carton {$c['no']}). Nothing was reserved.");
            }
            usort($lines, fn ($a, $b) => $a['seq'] <=> $b['seq']);
            foreach ($lines as $n => $l) DB::table('wh_pick_task_lines')->insert($l['row'] + ['line_no' => $n + 1]);
            DB::table('fba_shipments')->where('id', $id)->update(['status' => 'PICKING']);
            TaskEngine::open('WH_INBOUND_PICK', [$id], "Pick inbound shipment {$s->shipment_ref} into " . count($cartons) . ' carton(s)', ['ent' => $ent, 'module' => 'fba_shipments', 'record_id' => $id, 'fix_url' => '#/inbound/' . $id]);
            EventBus::fire('PICK_LIST_BUILT', 'wh_pick_tasks', $task, ['lines' => count($lines), 'fba_shipment' => $id], $ent);
            Audit::log('fba_shipments', $id, 'UPDATE', ['status' => ['DRAFT', 'PICKING'], 'units' => array_sum($need), 'pick_task' => $task], 'Reserved and pick task built', $ent);
            return ['task' => $task, 'units' => array_sum($need), 'lines' => count($lines), 'message' => 'Reserved ' . array_sum($need) . ' unit(s); pick task with ' . count($lines) . ' line(s).'];
        });
    }

    /** One scan step: carton label → item (SKU or marketplace item code) → qty. Units go bin → Dispatch area with their lots. */
    public static function pick(int $ent, int $id, string $carton, string $item, int $qty): array
    {
        return DB::transaction(function () use ($ent, $id, $carton, $item, $qty) {
            $s = self::ship($ent, $id, true);
            if ($s->status !== 'PICKING') throw new \InvalidArgumentException('This shipment is not being picked (' . strtolower($s->status) . ').');
            $no = preg_match('/-C(\d+)$/i', trim($carton), $m) && str_starts_with(strtoupper(trim($carton)), strtoupper($s->shipment_ref)) ? (int) $m[1] : (ctype_digit(trim($carton)) ? (int) trim($carton) : 0);
            $c = DB::table('fba_cartons')->where('fk_id_fba_shipment', $id)->where('carton_no', $no)->first() ?? throw new \InvalidArgumentException("\"$carton\" is not a carton of this shipment – scan the carton label first.");
            $product = self::resolveItem($ent, (int) $s->fk_id_branch, $item) ?? throw new \InvalidArgumentException("\"$item\" is not a known SKU or item code.");
            $task = DB::table('wh_pick_tasks')->where('fk_id_fba_shipment', $id)->where('task_type', 'INBOUND')->whereNotIn('status', ['CANCELLED'])->orderByDesc('id')->first();
            $lines = DB::table('wh_pick_task_lines')->where('fk_id_pick_task', $task->id)->where('fk_id_fba_carton', $c->id)->where('fk_id_product', $product)->where('line_status', 'OPEN')->orderBy('line_no')->lockForUpdate()->get();
            $left = (int) $lines->sum(fn ($l) => $l->qty - $l->qty_done);
            if (! $lines->count()) throw new \InvalidArgumentException('Carton ' . $c->carton_no . ' needs no (more) units of this item.');
            if ($qty <= 0 || $qty > $left) throw new \InvalidArgumentException("Carton {$c->carton_no} needs $left more of this item.");
            $todo = $qty; $dispatch = StockService::systemLocation($ent, 'DISPATCH');
            foreach ($lines as $l) {
                if ($todo <= 0) break;
                $take = min($todo, (int) ($l->qty - $l->qty_done)); $r = DB::table('wh_reservations')->where('id', $l->fk_id_reservation)->first();
                $moved = StockService::transfer($ent, $product, $take, (int) $l->fk_id_from_location, $dispatch, 'Inbound pick ' . $s->shipment_ref . ' carton ' . $c->carton_no,
                    ['rule' => $r->owner_rule === 'PRIVATE' ? 'PRIVATE' : 'POOL', 'owner' => $r->fk_id_owner_seg ?: null, 'source_type' => 'PICK', 'source_id' => $id]);
                $lots = json_decode((string) $l->lots_json, true) ?: []; foreach ($moved as $mv) $lots[(string) $mv['lot']] = ($lots[(string) $mv['lot']] ?? 0) + $mv['qty'];
                $done = (int) $l->qty_done + $take;
                DB::table('wh_pick_task_lines')->where('id', $l->id)->update(['qty_done' => $done, 'line_status' => $done >= $l->qty ? 'DONE' : 'OPEN', 'fk_id_to_location' => $dispatch, 'lots_json' => json_encode($lots), 'done_by' => Context::userId(), 'done_at' => now()]);
                DB::table('wh_reservations')->where('id', $r->id)->update(['qty_picked' => DB::raw('qty_picked + ' . $take), 'updated_at' => now()]);
                $todo -= $take;
            }
            $open = DB::table('wh_pick_task_lines')->where('fk_id_pick_task', $task->id)->where('line_status', 'OPEN')->exists();
            DB::table('wh_pick_tasks')->where('id', $task->id)->update(['status' => $open ? 'IN_PROGRESS' : 'DONE', 'started_at' => $task->started_at ?: now(), 'last_activity_at' => now(), 'finished_at' => $open ? null : now(), 'assigned_to' => $task->assigned_to ?: Context::userId()]);
            if (! DB::table('wh_pick_task_lines')->where('fk_id_pick_task', $task->id)->where('fk_id_fba_carton', $c->id)->where('line_status', 'OPEN')->exists()) DB::table('fba_cartons')->where('id', $c->id)->update(['label_status' => $c->label_status ?: 'PACKED']);
            if (! $open) {
                DB::table('fba_shipments')->where('id', $id)->update(['status' => 'PACKED']);
                foreach (DB::table('wh_reservations')->where('fk_id_fba_shipment', $id)->where('status', 'ALLOCATED')->get() as $r) {
                    DB::table('wh_reservations')->where('id', $r->id)->update(['status' => 'PICKED', 'updated_at' => now()]);
                    DB::table('wh_reservation_history')->insert(RecordService::fillNotNull('wh_reservation_history', ['fk_id_reservation' => $r->id, 'old_status' => 'ALLOCATED', 'new_status' => 'PICKED', 'old_qty' => $r->qty, 'new_qty' => $r->qty, 'reason' => 'Picked into cartons', 'changed_by' => Context::userId(), 'changed_at' => now()]));
                }
            }
            return ['carton' => (int) $c->carton_no, 'left_in_carton' => $left - $qty, 'all_done' => ! $open, 'message' => "Carton {$c->carton_no}: $qty in." . ($open ? '' : ' All cartons packed – print the labels and ship.')];
        });
    }

    /** SKU, marketplace item code (FNSKU-type) or marketplace SKU of the shop's listing. */
    public static function resolveItem(int $ent, int $shop, string $code): ?int
    {
        $code = trim($code); if ($code === '') return null;
        $p = DB::table('t_product_vendors')->where('fk_id_ent', $ent)->where('SKU_VENDOR', $code)->value('id');
        $p ??= DB::table('t_product_listings')->where('fk_id_ent', $ent)->where(fn ($w) => $w->where('fnsku', $code)->orWhere('marketplace_sku', $code))->orderByRaw('fk_id_branch <=> ? DESC', [$shop])->value('fk_id_product');
        return $p ? (int) $p : null;
    }

    /** The code printed on the item label of a product for the shop: listing FNSKU → marketplace SKU → our SKU. */
    public static function itemCode(int $ent, int $shop, int $product): string
    {
        $l = DB::table('t_product_listings')->where('fk_id_ent', $ent)->where('fk_id_product', $product)->where(fn ($w) => $w->whereNotNull('fnsku')->orWhereNotNull('marketplace_sku'))->orderByRaw('fk_id_branch <=> ? DESC', [$shop])->first(['fnsku', 'marketplace_sku']);
        return (string) ((($l->fnsku ?? '') ?: ($l->marketplace_sku ?? '')) ?: DB::table('t_product_vendors')->where('id', $product)->value('SKU_VENDOR'));
    }

    /** Step 1 of the two-step transfer: the picked lots Dispatch area → TRANSIT (owner kept). */
    public static function shipped(int $ent, int $id, array $d = []): array
    {
        return DB::transaction(function () use ($ent, $id, $d) {
            $s = self::ship($ent, $id, true);
            if ($s->status !== 'PACKED') throw new \InvalidArgumentException($s->status === 'PICKING' ? 'Pick every carton first.' : 'This shipment is ' . strtolower($s->status) . '.');
            $dispatch = StockService::systemLocation($ent, 'DISPATCH'); $transit = StockService::systemLocation($ent, 'TRANSIT'); $units = 0;
            foreach (DB::table('wh_pick_task_lines as l')->join('wh_pick_tasks as t', 't.id', '=', 'l.fk_id_pick_task')->where('t.fk_id_fba_shipment', $id)->where('t.task_type', 'INBOUND')->where('l.line_status', 'DONE')->get(['l.*']) as $l)
                foreach (json_decode((string) $l->lots_json, true) ?: [] as $lot => $q) {
                    StockService::transfer($ent, (int) $l->fk_id_product, (int) $q, $dispatch, $transit, 'Inbound shipment ' . $s->shipment_ref . ' shipped', ['lot' => (int) $lot, 'source_type' => 'FBA_SHIPMENT', 'source_id' => $id]);
                    $units += (int) $q;
                }
            DB::table('fba_shipments')->where('id', $id)->update(['status' => 'SHIPPED', 'd_shipped' => $d['date'] ?? date('Y-m-d'), 'fk_id_carrier' => ($d['carrier'] ?? null) ?: null, 'tracking_no' => isset($d['tracking']) ? mb_substr((string) $d['tracking'], 0, 60) : null]);
            foreach (DB::table('wh_reservations')->where('fk_id_fba_shipment', $id)->where('status', 'PICKED')->get() as $r) {
                DB::table('wh_reservations')->where('id', $r->id)->update(['status' => 'SHIPPED', 'updated_at' => now()]);
                DB::table('wh_reservation_history')->insert(RecordService::fillNotNull('wh_reservation_history', ['fk_id_reservation' => $r->id, 'old_status' => 'PICKED', 'new_status' => 'SHIPPED', 'old_qty' => $r->qty, 'new_qty' => $r->qty, 'reason' => 'Shipped to the fulfilment centre', 'changed_by' => Context::userId(), 'changed_at' => now()]));
            }
            TaskEngine::close('WH_INBOUND_PICK', [$id], 'Shipped');
            Audit::log('fba_shipments', $id, 'UPDATE', ['status' => ['PACKED', 'SHIPPED'], 'units' => $units, 'tracking' => $d['tracking'] ?? null], 'Shipped – units in transit', $ent);
            return ['units' => $units, 'message' => "$units unit(s) shipped – now in transit until the centre confirms."];
        });
    }

    /** Step 2: what the centre received per SKU (product id => qty). Received units TRANSIT → destination; a difference opens STK_FC_DIFF. */
    public static function received(int $ent, int $id, array $got): array
    {
        return DB::transaction(function () use ($ent, $id, $got) {
            $s = self::ship($ent, $id, true);
            if ($s->status !== 'SHIPPED') throw new \InvalidArgumentException('Only a shipped shipment can be received.');
            $sent = []; foreach (self::cartons($id) as $c) foreach ($c['items'] as $i) $sent[(int) $i['product']] = ($sent[(int) $i['product']] ?? 0) + (int) $i['qty'];
            $transit = StockService::systemLocation($ent, 'TRANSIT'); $dest = (int) $s->fk_id_dest_location; $diffs = [];
            foreach ($sent as $p => $q) {
                $r = max(0, min($q, (int) ($got[$p] ?? $got[(string) $p] ?? 0))); $sku = DB::table('t_product_vendors')->where('id', $p)->value('SKU_VENDOR');
                if ($r > 0 && $dest !== $transit) foreach (DB::table('wh_pick_task_lines as l')->join('wh_pick_tasks as t', 't.id', '=', 'l.fk_id_pick_task')->where('t.fk_id_fba_shipment', $id)->where('l.fk_id_product', $p)->pluck('l.lots_json') as $js) {
                    foreach (json_decode((string) $js, true) ?: [] as $lot => $lq) { if ($r <= 0) break; $take = min($r, (int) $lq); StockService::transfer($ent, $p, $take, $transit, $dest, 'Received at the centre ' . $s->shipment_ref, ['lot' => (int) $lot, 'source_type' => 'FBA_SHIPMENT', 'source_id' => $id]); $r -= $take; }
                }
                $in = max(0, min($q, (int) ($got[$p] ?? $got[(string) $p] ?? 0)));
                if ($in !== $q) { $diffs[] = "$sku: sent $q, received $in"; TaskEngine::open('STK_FC_DIFF', [$s->shipment_ref, $sku], "Centre received $in of $q × $sku ({$s->shipment_ref})", ['ent' => $ent, 'module' => 'fba_shipments', 'record_id' => $id, 'fix_url' => '#/inbound/' . $id]); }
                else TaskEngine::close('STK_FC_DIFF', [$s->shipment_ref, $sku], 'Received in full');
            }
            DB::table('fba_shipments')->where('id', $id)->update(['status' => $diffs ? 'DIFFERENCE' : 'CLOSED']);
            if (! $diffs) EventBus::fire('FBA_SHIPMENT_CLOSED', 'fba_shipments', $id, ['units' => array_sum($sent)], $ent);
            Audit::log('fba_shipments', $id, 'UPDATE', ['status' => ['SHIPPED', $diffs ? 'DIFFERENCE' : 'CLOSED'], 'differences' => $diffs], 'Received at the centre', $ent);
            return ['differences' => $diffs, 'message' => $diffs ? count($diffs) . ' difference(s) – a task is open for each.' : 'Received in full – shipment closed.'];
        });
    }

    public static function list(int $ent): array
    {
        return DB::table('fba_shipments as s')->leftJoin('t_branches as b', 'b.id', '=', 's.fk_id_branch')->where('s.fk_id_ent', $ent)->orderByDesc('s.id')->limit(200)
            ->get(['s.id', 's.shipment_ref', 's.status', 's.d_created', 's.d_shipped', 's.tracking_no', DB::raw("TRIM(CONCAT_WS(' ', b.shop_no, b.shop_name)) as shop"),
                DB::raw('(SELECT COUNT(*) FROM fba_cartons c WHERE c.fk_id_fba_shipment = s.id) as cartons')])->map(fn ($r) => (array) $r)->all();
    }

    public static function page(int $ent, int $id): array
    {
        $s = self::ship($ent, $id);
        $task = DB::table('wh_pick_tasks')->where('fk_id_fba_shipment', $id)->where('task_type', 'INBOUND')->orderByDesc('id')->first(['id', 'task_code', 'status']);
        $lines = $task ? DB::table('wh_pick_task_lines as l')->join('t_product_vendors as p', 'p.id', '=', 'l.fk_id_product')->leftJoin('cat_inventory_locations as b', 'b.id', '=', 'l.fk_id_from_location')->leftJoin('fba_cartons as c', 'c.id', '=', 'l.fk_id_fba_carton')
            ->where('l.fk_id_pick_task', $task->id)->orderBy('l.line_no')->get(['l.id', 'l.line_no', 'c.carton_no', 'p.SKU_VENDOR as sku', 'b.location_name as bin', 'l.qty', 'l.qty_done', 'l.line_status'])->map(fn ($r) => (array) $r)->all() : [];
        $loc = fn ($i) => DB::table('cat_inventory_locations')->where('id', $i)->value('location_name');
        return ['shipment' => ['id' => (int) $s->id, 'ref' => $s->shipment_ref, 'status' => $s->status, 'shop' => trim((string) DB::table('t_branches')->where('id', $s->fk_id_branch)->selectRaw("CONCAT_WS(' ', shop_no, shop_name) as n")->value('n')),
            'from' => $loc($s->fk_id_from_location), 'dest' => $loc($s->fk_id_dest_location), 'd_created' => $s->d_created, 'd_shipped' => $s->d_shipped, 'tracking' => $s->tracking_no],
            'cartons' => array_map(fn ($c) => $c + ['code' => self::code($s, $c['no'])], self::cartons($id)), 'task' => $task, 'lines' => $lines];
    }

    /** Carton labels (one per carton): FBA style (carton x/N, FC, shipment id, SKU, qty, kg) and 3PL style (shop, inbound order, carton no., goods id) on one label. */
    public static function cartonLabels(int $ent, int $id): array
    {
        $s = self::ship($ent, $id); $all = self::cartons($id); $fc = DB::table('cat_inventory_locations')->where('id', $s->fk_id_dest_location)->value('location_name');
        $shop = trim((string) DB::table('t_branches')->where('id', $s->fk_id_branch)->selectRaw("CONCAT_WS(' ', shop_no, shop_name) as n")->value('n'));
        return array_map(fn ($c) => ['code' => self::code($s, $c['no']), 'n' => $c['no'], 'of' => count($all), 'ref' => $s->shipment_ref, 'fc' => $fc, 'shop' => $shop, 'kind' => $c['kind'],
            'items' => array_map(fn ($i) => ['sku' => $i['sku'], 'goods' => self::itemCode($ent, (int) $s->fk_id_branch, (int) $i['product']), 'qty' => (int) $i['qty']], $c['items']), 'units' => $c['units'], 'kg' => $c['kg'], 'dims' => $c['dims']], $all);
    }

    /** Marketplace item labels: one per unit, with the item code (FNSKU-type) and our SKU. */
    public static function itemLabels(int $ent, int $id): array
    {
        $s = self::ship($ent, $id); $out = [];
        foreach (self::cartons($id) as $c) foreach ($c['items'] as $i) {
            $code = self::itemCode($ent, (int) $s->fk_id_branch, (int) $i['product']); $name = (string) DB::table('t_product_vendors')->where('id', $i['product'])->value('product_name_vendor');
            for ($n = 0; $n < (int) $i['qty']; $n++) $out[] = ['code' => $code, 'sku' => $i['sku'], 'name' => mb_substr($name, 0, 50)];
        }
        return $out;
    }
}
