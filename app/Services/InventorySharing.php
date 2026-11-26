<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-D-94 Stock › Inventory sharing: which shop gets which SKU, at what price, what quantity, and is it in sync.
 * One row per listing (shop × SKU, partner / dropship shops included): shop, SKU, visibility (R-D-20 Pool / Private for that marketplace × shop, and shown / hidden on the shop), price on that shop,
 * quantity offered (available stock after the profile's stock buffer – ListingQty), last push time and result of the stock sync,
 * the dropship supplier profile when the line is DROPSHIP. This screen reads; its bulk actions only change the listing or queue a push.
 */
class InventorySharing
{
    private static function shopIds(object $u): array { return array_map(fn ($s) => (int) $s['id'], ShopLabel::list($u, ['active_only' => true])); }

    public static function list(int $ent, array $f, object $u): array
    {
        Perm::need($u, 'stock.SEE'); $shops = ShopLabel::list($u, ['active_only' => true]); $ids = array_map(fn ($s) => (int) $s['id'], $shops); $label = array_column($shops, 'label', 'id');
        $q = DB::table('t_product_listings as l')->join('t_product_vendors as p', 'p.id', '=', 'l.fk_id_product')->where('l.fk_id_ent', $ent)->whereIn('l.fk_id_branch', $ids ?: [0]);
        if (! empty($f['shop'])) $q->where('l.fk_id_branch', (int) $f['shop']);
        if (($s = trim((string) ($f['sku'] ?? ''))) !== '') $q->where(fn ($w) => $w->where('l.marketplace_sku', 'like', "%$s%")->orWhere('p.SKU_VENDOR', 'like', "%$s%")->orWhere('l.listing_title', 'like', "%$s%"));
        $total = (clone $q)->count();
        $ls = $q->orderBy('p.SKU_VENDOR')->orderBy('l.fk_id_branch')->limit(1000)->get(['l.*', 'p.SKU_VENDOR', 'p.product_name_vendor', 'p.f_id_parent', 'p.fk_id_vendor_inventory']);
        $vis = DB::table('t_product_visibility')->whereIn('fk_id_product', $ls->pluck('fk_id_product')->merge($ls->pluck('f_id_parent'))->filter()->unique()->all() ?: [0])->get()->all();      /* R-D-20 Pool / Private per marketplace × shop */
        $last = DB::table('stock_push_queue')->whereIn('id', DB::table('stock_push_queue')->whereIn('fk_id_listing', $ls->pluck('id')->all() ?: [0])->groupBy('fk_id_listing')->selectRaw('MAX(id)'))->get()->keyBy('fk_id_listing');
        $prof = []; $dsp = DB::table('dropship_supplier_profiles as d')->where('d.fk_id_ent', $ent)->where('d.f_active', 1)->pluck('d.code', 'd.fk_id_supplier');
        $rows = [];
        foreach ($ls as $l) {
            $p = $prof[$l->fk_id_branch] ??= (ListingQty::profile((int) $l->fk_id_branch) ?? (object) ['stock_sync_mode' => 'OFF', 'stock_buffer_units' => 0, 'oos_action' => 'ZERO']);
            $shown = (int) $l->f_active === 1 && $l->status === 'ACTIVE'; $qty = $shown ? ListingQty::qty($l, $p) : 0; $push = $last[$l->id] ?? null; $mode = $p->stock_sync_mode ?? 'OFF';
            $state = ! $shown ? 'HIDDEN' : ($mode === 'OFF' ? 'NO_SYNC' : (! $push ? 'NEVER' : ($push->status === 'FAILED' ? 'OUT_OF_SYNC' : ($push->status === 'PENDING' ? 'WAITING' : ((int) $push->qty !== $qty ? 'OUT_OF_SYNC' : 'IN_SYNC')))));
            $rows[] = ['id' => (int) $l->id, 'shop' => $label[$l->fk_id_branch] ?? ('Shop ' . $l->fk_id_branch), 'shop_id' => (int) $l->fk_id_branch, 'sku' => $l->SKU_VENDOR, 'shop_sku' => $l->marketplace_sku, 'name' => $l->listing_title ?: $l->product_name_vendor,
                'shown' => $shown, 'visibility' => $shown ? 'Shown' : 'Hidden', 'sold_there' => ($pv = SaleLines::visibility((int) $l->fk_id_product, (int) $l->f_id_parent ?: null, (int) $l->fk_id_marketplace, (int) $l->fk_id_branch, $vis)) !== '', 'visibility_mode' => ['POOL' => 'Pool', 'PRIVATE' => 'Private'][$pv] ?? 'Not set for this shop', 'price' => $l->price !== null ? (float) $l->price : null, 'qty' => $qty, 'sync' => $mode, 'state' => $state,
                'state_text' => ['HIDDEN' => 'Hidden on this shop', 'NO_SYNC' => 'Stock sync is off for this shop', 'NEVER' => 'Never pushed', 'OUT_OF_SYNC' => $push && $push->status === 'FAILED' ? 'Out of sync – ' . ($push->note ?: 'the last push failed') : 'Out of sync – the shop was last sent ' . (int) ($push->qty ?? 0), 'WAITING' => 'Push waiting', 'IN_SYNC' => 'In sync'][$state],
                'last_push' => $push ? (string) ($push->done_at ?: $push->created_at) : null, 'last_result' => $push->status ?? null,
                'dropship' => $l->fulfilment === 'DROPSHIP', 'dropship_profile' => $l->fulfilment === 'DROPSHIP' ? ($dsp[(int) ($l->fk_id_vendor_inventory ?? 0)] ?? $dsp[(int) ($l->fk_id_vendor ?? 0)] ?? null) : null,      /* R-D-97: the profile of the product's supplier (else of the listing's) */
                'can_push' => $shown && $mode !== 'OFF'];
        }
        if (! empty($f['out_of_sync'])) $rows = array_values(array_filter($rows, fn ($r) => $r['state'] === 'OUT_OF_SYNC'));
        $n = fn (string $s) => count(array_filter($rows, fn ($r) => $r['state'] === $s));
        return ['rows' => $rows, 'total' => $total, 'limited' => $total > 1000, 'shops' => array_map(fn ($s) => ['id' => (int) $s['id'], 'label' => $s['label']], $shops),
            'summary' => ['sharing' => count(array_filter($rows, fn ($r) => $r['shown'])), 'hidden' => $n('HIDDEN'), 'out_of_sync' => $n('OUT_OF_SYNC')],
            'can' => ['show_hide' => Perm::has($u, 'stock.CHANGE'), 'price' => Perm::has($u, 'stock.setPrice'), 'push' => Perm::has($u, 'stock.CHANGE')]];
    }

    /** Bulk actions on the ticked rows: SHOW / HIDE on the shop, PRICE, PUSH now. */
    public static function bulk(int $ent, string $action, array $ids, mixed $value, object $u): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids))); if (! $ids) throw new \InvalidArgumentException('Tick at least one row.'); if (count($ids) > 500) throw new \InvalidArgumentException('At most 500 rows at a time.');
        Perm::need($u, $action === 'PRICE' ? 'stock.setPrice' : 'stock.CHANGE'); if (! in_array($action, ['SHOW', 'HIDE', 'PRICE', 'PUSH'], true)) throw new \InvalidArgumentException('Unknown action.');
        if ($action === 'PRICE' && (! is_numeric($value) || (float) $value <= 0)) throw new \InvalidArgumentException('Give a price above 0.');
        $mine = self::shopIds($u); $ls = DB::table('t_product_listings')->where('fk_id_ent', $ent)->whereIn('id', $ids)->whereIn('fk_id_branch', $mine ?: [0])->get();
        if ($ls->count() !== count($ids)) throw new \InvalidArgumentException('Some of these rows are not in your shops – load the list again.');
        $done = 0; $skipped = [];
        DB::transaction(function () use ($ls, $action, $value, $u, $ent, &$done, &$skipped) {
            foreach ($ls as $l) {
                if ($action === 'PUSH') {
                    $p = ListingQty::profile((int) $l->fk_id_branch); $mode = $p->stock_sync_mode ?? 'OFF';
                    if ($mode === 'OFF') { $skipped[] = $l->marketplace_sku . ': stock sync is off for this shop'; continue; }
                    if ((int) $l->f_active !== 1 || $l->status !== 'ACTIVE') { $skipped[] = $l->marketplace_sku . ': hidden on this shop'; continue; }
                    $qty = ListingQty::qty($l, $p);
                    DB::table('stock_push_queue')->where('fk_id_listing', $l->id)->where('status', 'PENDING')->update(['status' => 'REPLACED', 'done_at' => now()]);
                    DB::table('stock_push_queue')->insert(['fk_id_ent' => $ent, 'fk_id_branch' => $l->fk_id_branch, 'fk_id_listing' => $l->id, 'qty' => $qty, 'action' => $qty === 0 && ($p->oos_action ?? 'ZERO') === 'END' ? 'END' : ($qty === 0 ? 'ZERO' : 'SET'), 'via' => $mode, 'status' => 'PENDING', 'reason' => 'PUSH_NOW',
                        'note' => $mode === 'API' ? 'Waiting for the channel connector – no connector pushes quantities yet; it is in the quantity file meanwhile.' : null, 'created_at' => now()]);
                    $done++; continue;
                }
                $set = match ($action) { 'SHOW' => ['status' => 'ACTIVE', 'f_active' => 1], 'HIDE' => ['status' => 'INACTIVE'], 'PRICE' => ['price' => round((float) $value, 2)] };
                $diff = []; foreach ($set as $k => $v) if ((string) $l->$k !== (string) $v && ! ($k === 'price' && $l->price !== null && round((float) $l->price, 2) === $v)) $diff[$k] = [$l->$k, $v];
                if (! $diff) { $skipped[] = $l->marketplace_sku . ': already so'; continue; }
                DB::table('t_product_listings')->where('id', $l->id)->update($set + ['updated_by' => $u->id, 'updated_at' => now()]); Audit::log('t_product_listings', (int) $l->id, 'UPDATE', $diff, 'Inventory sharing', $ent); $done++;
            }
        });
        $what = ['SHOW' => 'shown on the shop', 'HIDE' => 'hidden on the shop', 'PRICE' => 'priced at ' . number_format((float) $value, 2, '.', ''), 'PUSH' => 'queued for a push'][$action];
        return ['done' => $done, 'skipped' => $skipped, 'message' => "$done row(s) $what." . ($skipped ? ' ' . count($skipped) . ' left as they were.' : '')];
    }
}
