<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Products list (R-D-35) with its Type chip (R-D-25), Cover status (R-D-37) and WAC column (R-D-38).
 * - Rows: parents and products on their own (children are inside their parent), sorted id DESC, pages of 10 / 20 / 50 / 100 (default 20), pager ± 3 pages.
 * - Speed: one LIMIT query for the page, then batch look-ups for the page's ids only (visibility, owners, child counts, WAC, listings, images, investors).
 * - Default columns: thumbnail, Name (+ "— N" children / "— variant"), SKU · MP-{id}, Owner (or "Multiple"), Type, one pill per active marketplace,
 *   Qty, Cover, WAC, Listings, ACTIVE / INACTIVE, actions. Optional column set "Pricing" for the chosen marketplace.
 * - Row text colour from the reference list PRODUCT_ROW_STATUS (first match wins; inactive is checked before "alone" – the old order never reached it).
 * - Every person keeps a default view (filters, page size, column set, hidden columns). Exports CSV / Excel / PDF with the current search and order, HTML stripped.
 */
class ProductsList
{
    public const PAGE = 'products';
    public const SIZES = [10, 20, 50, 100];
    /** PRODUCT_ROW_STATUS: value => [label, colour] in the order they are tested */
    public const ROW_STATUS = ['OOS' => ['Manual out of stock', '#e45573'], 'INACTIVE' => ['Inactive', '#c1318b'], 'PARENT' => ['Parent (family)', '#15a985'], 'ALONE' => ['On its own', '#f0c902']];
    public const TYPES = ['JOINT' => 'Joint (2+ investors)', 'HOLDING' => 'Holding (1 investor)', 'CENTRAL' => 'Central (pool)', 'PRIVATE' => 'Private'];
    public const DEFAULT_COLS = ['image' => 'Image', 'name' => 'Name', 'sku' => 'SKU', 'owner' => 'Owner', 'type' => 'Type', 'tags' => 'Tags', 'marketplaces' => 'Marketplaces', 'qty' => 'Qty', 'cover' => 'Cover', 'wac' => 'WAC £', 'listings' => 'Listings', 'status' => 'Status'];
    public const PRICING_COLS = ['raw' => 'Raw', 'vat' => 'VAT', 'postage' => 'Postage', 'packing_rate' => 'Packing', 'fee' => 'Fee', 'final_sale' => 'Final sale', 'cost' => 'Cost (FIFO)', 'margin' => 'Margin £', 'margin_pct' => 'Margin %',
        'stock' => 'Stock', 'min_level' => 'Min level', 'alert' => 'Alert?', 'family' => 'Family?', 'variant' => 'Variant', 'created' => 'Created', 'country' => 'Country', 'weight' => 'Weight', 'dimensions' => 'Dimensions',
        'carrier' => 'Carrier', 'packing' => 'Packing', 'image_url' => 'Image URL', 'expiry' => 'Expiry', 'description' => 'Description'];

    public static function cut(string $name): string { return mb_strlen($name) > 72 ? mb_substr($name, 0, 72) . '....' : $name; }

    public static function rowStatus(object $p, bool $parent): string
    {
        if ((int) $p->f_out_of_stock === 1) return 'OOS';
        if (! (int) $p->f_active) return 'INACTIVE';
        return $parent ? 'PARENT' : 'ALONE';
    }

    /** R-D-25: ≥ 2 investors → JOINT, 1 → HOLDING, none and Pool → CENTRAL, Private → PRIVATE; with a marketplace chosen, that marketplace's mode. */
    public static function type(int $investors, array $modes, ?int $mp = null): ?string
    {
        if ($investors >= 2) return 'JOINT';
        if ($investors === 1) return 'HOLDING';
        $m = $mp ? ($modes[$mp] ?? null) : (in_array('POOL', $modes, true) ? 'POOL' : (in_array('PRIVATE', $modes, true) ? 'PRIVATE' : null));
        return $m === 'POOL' ? 'CENTRAL' : ($m === 'PRIVATE' ? 'PRIVATE' : null);
    }

    public const OWNER_TYPES = ['JOINT', 'HOLDING', 'CENTRAL', 'PRIVATE'];

    /** The SQL of type() so the list can be filtered by the chip (same order: investors first, then the marketplace mode). */
    public static function typeFilter($q, string $type, ?int $mp): void
    {
        $inv = '(SELECT COUNT(DISTINCT pi.fk_id_account) FROM t_product_investors pi WHERE pi.fk_id_product = p.id)';
        $mode = fn (string $m) => 'EXISTS (SELECT 1 FROM t_product_visibility v WHERE v.fk_id_product = p.id AND UPPER(v.visibility_mode) = ' . "'$m'" . ($mp ? ' AND v.fk_id_marketplace = ' . (int) $mp : '') . ')';
        match ($type) {
            'JOINT' => $q->whereRaw("$inv >= 2"),
            'HOLDING' => $q->whereRaw("$inv = 1"),
            'CENTRAL' => $q->whereRaw("$inv = 0 AND " . $mode('POOL')),
            'PRIVATE' => $q->whereRaw("$inv = 0 AND " . ($mp ? $mode('PRIVATE') : 'NOT ' . $mode('POOL') . ' AND ' . $mode('PRIVATE'))),
        };
    }

    /** The chip of one lot (R-D-25): its investors (sourcing investments of the lot's PO / cycle) ≥ 2 → JOINT, 1 → HOLDING, else the lot's pool / private. */
    public static function lotType(object $lot): ?string
    {
        $n = (int) DB::table('t_src_investments')->where('f_active', 1)->where(fn ($w) => $w->when($lot->po_id, fn ($x) => $x->orWhere('fk_id_po', $lot->po_id))->when($lot->fk_id_cycle, fn ($x) => $x->orWhere('fk_id_cycle', $lot->fk_id_cycle))
            ->when(! $lot->po_id && ! $lot->fk_id_cycle, fn ($x) => $x->whereRaw('1 = 0')))->whereNotIn('status', ['CANCELLED', 'REFUNDED'])->distinct()->count('fk_id_investor');
        if ($n >= 2) return 'JOINT';
        if ($n === 1) return 'HOLDING';
        $v = strtoupper((string) $lot->visibility);
        return $v === 'POOL' ? 'CENTRAL' : ($v === 'PRIVATE' ? 'PRIVATE' : null);
    }

    /** R-D-37: ICC days-of-cover status when the product has sales history in ICC, else the stock / min-level rule. */
    public static function cover(object $p): array
    {
        $icc = ['RED' => 'Critical', 'YELLOW' => 'Urgent', 'GREEN' => 'Good'];
        if (isset($icc[(string) $p->icc_status]) && $p->icc_run_at) return ['status' => $icc[$p->icc_status], 'basis' => 'ICC days of cover'];
        $s = (float) $p->stock; $min = (float) $p->min_level;
        return ['status' => $s <= 0 ? 'Critical' : ($s <= $min ? 'Urgent' : 'Good'), 'basis' => 'min-level rule'];
    }

    /** R-D-38: WAC = Σ cost ÷ Σ qty of the product's lots (landed cost incl. shipping, other cost, freight), 2 dp; null when qty 0. */
    public static function wac(array $ids): array
    {
        if (! $ids) return [];
        return DB::table('fin_lots')->whereIn('fk_id_product', $ids)->whereNotIn('source', ['SPLIT', 'DAMAGED'])->groupBy('fk_id_product')
            ->selectRaw('fk_id_product p, SUM(qty * unit_cost_gbp) total, SUM(qty) q')->get()
            ->mapWithKeys(fn ($r) => [(int) $r->p => (float) $r->q > 0 ? ['wac' => round((float) $r->total / (float) $r->q, 2), 'total' => round((float) $r->total, 2), 'qty' => (int) $r->q] : null])->filter()->all();
    }

    public static function view(?object $u): array
    {
        $j = $u ? json_decode((string) DB::table('t_user_column_prefs')->where('fk_id_user', $u->id)->where('page_key', self::PAGE)->value('prefs_json'), true) : null;
        return (is_array($j) ? $j : []) + ['per' => 20, 'set' => 'default', 'status' => 'active', 'hidden' => []];
    }

    public static function saveView(object $u, array $d): array
    {
        $v = ['per' => in_array((int) ($d['per'] ?? 20), self::SIZES, true) ? (int) $d['per'] : 20, 'set' => ($d['set'] ?? '') === 'pricing' ? 'pricing' : 'default',
            'status' => in_array($d['status'] ?? 'active', ['active', 'inactive', 'all', 'hidden', 'deleted'], true) ? $d['status'] : 'active', 'mp' => isset($d['mp']) && ctype_digit((string) $d['mp']) ? (int) $d['mp'] : null,
            'q' => mb_substr(trim((string) ($d['q'] ?? '')), 0, 100), 'type' => in_array(strtoupper((string) ($d['type'] ?? '')), self::OWNER_TYPES, true) ? strtoupper($d['type']) : '', 'hidden' => array_values(array_intersect((array) ($d['hidden'] ?? []), array_keys(self::DEFAULT_COLS + self::PRICING_COLS)))];
        DB::table('t_user_column_prefs')->updateOrInsert(['fk_id_user' => $u->id, 'page_key' => self::PAGE], ['prefs_json' => json_encode($v), 'hidden_columns' => '', 'updated_at' => now()]);
        return ['message' => 'Saved as your default view', 'view' => $v];
    }

    private static function query(int $ent, array $f, ?object $u = null)
    {
        $q = DB::table('t_product_vendors as p')->where('p.fk_id_ent', $ent)->where(fn ($w) => $w->whereNull('p.f_id_parent')->orWhere('p.f_id_parent', 0))->whereNull('p.fk_id_merged_into');
        ProductScope::apply($q, $u, 'p');      // R-D-21 who sees which product (list, search box, export)
        $st = $f['status'] ?? 'active';
        if ($st === 'active') $q->where('p.f_active', 1); elseif ($st === 'inactive') $q->where('p.f_active', 0)->where('p.row_state', 'ACTIVE');
        elseif ($st === 'hidden') $q->where('p.row_state', 'HIDDEN'); elseif ($st === 'deleted') $q->where('p.row_state', 'DELETED'); else $q->where('p.row_state', '<>', 'DELETED');      // R-D-9: "Hidden products" saved view; the recycle bin
        if (in_array($ty = strtoupper((string) ($f['type'] ?? '')), self::OWNER_TYPES, true)) self::typeFilter($q, $ty, ! empty($f['mp']) ? (int) $f['mp'] : null);      // R-D-25 filter by the chip
        /* R-D-36 search: name / SKU / UPC / EAN, any letter case; "MP-123" (or "mp-123") finds the product with id 123 */
        if (($t = trim((string) ($f['q'] ?? ''))) !== '') { $like = '%' . addcslashes($t, '%_\\') . '%'; $mpId = preg_match('/^mp-?(\d+)$/i', $t, $m) ? (int) $m[1] : -1;
            $q->where(fn ($w) => $w->where('p.product_name_vendor', 'like', $like)->orWhere('p.SKU_VENDOR', 'like', $like)->orWhere('p.ean', 'like', $like)->orWhere('p.upc', 'like', $like)->orWhere('p.id', $mpId)); }
        self::filters($q, $f, $ent);
        return $q;
    }

    public const FILTERS = ['icc', 'company', 'category', 'brand', 'carrier', 'investor', 'logistic', 'supplier', 'trend', 'country', 'ean', 'asin', 'mode', 'tag', 'oos'];

    /** R-D-36 the filter bar (each is an AND; the same EXISTS rules everywhere the list is used: page, export, search). */
    /** The tags of a product: the Tags field, else the old Tag 1–3. */
    public static function tagIds(object $p): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) ($p->tag_ids ?? ''))))));
        return $ids ?: array_values(array_unique(array_filter([(int) ($p->f_tag1 ?? 0), (int) ($p->f_tag2 ?? 0), (int) ($p->f_tag3 ?? 0)])));
    }

    public static function filters($q, array $f, int $ent): void
    {
        $n = fn (string $k) => (int) ($f[$k] ?? 0); $mp = $n('mp');
        if ($n('category')) $q->where('p.fk_id_product_type', $n('category'));
        if ($n('brand')) $q->where('p.fk_id_brand', $n('brand'));
        if ($n('trend')) $q->where('p.fk_id_product_trend', $n('trend'));
        if ($n('country')) $q->where('p.fk_id_country_make', $n('country'));
        if ($n('supplier')) $q->where('p.fk_id_vendor_inventory', $n('supplier'));
        if (($e = trim((string) ($f['ean'] ?? ''))) !== '') $q->where('p.ean', $e);      /* exact */
        if (($a = trim((string) ($f['asin'] ?? ''))) !== '') $q->where(fn ($w) => $w->where('p.upc', $a)->orWhereExists(fn ($x) => $x->from('t_product_listings as la')->whereColumn('la.fk_id_product', 'p.id')->where('la.asin', $a)));      /* exact; the old tool kept the ASIN in UPC */
        if ($n('carrier')) { $c = $n('carrier'); $q->where(fn ($w) => $w->whereExists(fn ($x) => $x->from('t_product_marketplace_shipping as ms')->join('t_carrier_pkg_svc as cp', 'cp.id', '=', 'ms.fk_id_car_pkg_svc')->whereColumn('ms.fk_id_product', 'p.id')->where('cp.fk_id_carrier', $c))
            ->orWhereExists(fn ($x) => $x->from('t_carrier_pkg_svc as cp2')->whereColumn('cp2.id', 'p.fk_id_car_pkg_svc')->where('cp2.fk_id_carrier', $c))); }      /* used on any marketplace shipping row (or the product's own) */
        if ($n('investor')) { $i = $n('investor'); $q->whereExists(fn ($x) => $x->from('t_product_investors as pi2')->whereColumn('pi2.fk_id_product', 'p.id')->where(fn ($w) => $w->where('pi2.fk_id_account', $i)->orWhere('pi2.fk_id_party', $i))); }
        if ($n('logistic')) { $l = $n('logistic'); $q->whereExists(fn ($x) => $x->from('t_src_po_lines as pl')->join('t_src_po as po', 'po.id', '=', 'pl.fk_id_po')->whereColumn('pl.fk_id_product', 'p.id')->where('po.fk_id_supplier', $l)->where('pl.f_active', 1)->whereNotIn('po.status', ['CANCELLED', 'DRAFT'])); }      /* an active purchase from that party */
        if ($n('company')) { $co = $n('company'); $q->whereExists(fn ($x) => $x->from('t_product_visibility as cv')->join('t_branches as cb', fn ($j) => $j->on('cb.fk_id_marketplace', '=', 'cv.fk_id_marketplace')->where(fn ($w) => $w->whereColumn('cb.id', 'cv.fk_id_branch')->orWhere('cv.fk_id_branch', 0)))
            ->whereColumn('cv.fk_id_product', 'p.id')->where('cb.fk_id_company', $co)->where('cb.f_active', 1)); }      /* company of a shop the product is visible in */
        if (in_array($md = strtoupper((string) ($f['mode'] ?? '')), ['POOL', 'PRIVATE'], true)) $q->whereExists(fn ($x) => $x->from('t_product_visibility as mv')->whereColumn('mv.fk_id_product', 'p.id')->whereRaw('UPPER(mv.visibility_mode) = ?', [$md])->when($mp, fn ($w) => $w->where('mv.fk_id_marketplace', $mp)));      /* with a marketplace chip: that marketplace's mode; without: any */
        if ($tg = (int) ($f['tag'] ?? 0)) $q->where(fn ($w) => $w->whereRaw('FIND_IN_SET(?, p.tag_ids)', [$tg])->orWhere('p.f_tag1', $tg)->orWhere('p.f_tag2', $tg)->orWhere('p.f_tag3', $tg));      // R-D-13 filter by tag (the Tags field, old Tag 1–3 too)
        if (in_array($ic = strtoupper((string) ($f['icc'] ?? '')), ['RED', 'YELLOW', 'OK'], true)) $q->where('p.icc_status', $ic)->whereNotNull('p.icc_run_at');      /* R-G_CORE-103 the ICC chart's slice */
        if (! empty($f['oos'])) $q->where(fn ($w) => $w->where('p.f_out_of_stock', 1)->orWhere('p.stock', '<=', 0));      /* out of stock = the flag, or nothing left */
    }

    /** The lists of the filter bar (names, never ids typed). */
    public static function filterLists(int $ent): array
    {
        $own = fn ($q) => $q->where(fn ($w) => $w->where('fk_id_ent', $ent)->orWhereNull('fk_id_ent')->orWhere('fk_id_ent', 0)); $l = fn ($rows) => collect($rows)->map(fn ($r) => ['id' => (int) $r->id, 'name' => (string) $r->name])->filter(fn ($r) => $r['name'] !== '')->values()->all();
        return ['category' => $l($own(DB::table('cat_product_type')->where('f_active', 1))->orderBy('product_type_name')->get(['id', 'product_type_name as name'])), 'brand' => $l($own(DB::table('cat_brands')->where('f_active', 1))->orderBy('brand_name')->get(['id', 'brand_name as name'])),
            'trend' => $l($own(DB::table('cat_product_trends')->where('f_active', 1))->orderBy('product_trend_name')->get(['id', 'product_trend_name as name'])), 'country' => $l(DB::table('cat_country')->where('f_active', 1)->orderBy('country_name')->get(['id', 'country_name as name'])),
            'carrier' => $l($own(DB::table('t_carriers')->where('f_active', 1))->orderBy('carrier_name')->get(['id', 'carrier_name as name'])), 'supplier' => $l(DB::table('t_src_suppliers')->where('fk_id_ent', $ent)->where('f_active', 1)->orderBy('supplier_name')->get(['id', 'supplier_name as name'])),
            'company' => $l(DB::table('t_enterprise')->where('f_active', 1)->orderBy('ent_name')->get(['id', 'ent_name as name'])),
            'investor' => $l(DB::table('t_product_investors as pi')->join('t_account as a', 'a.id', '=', 'pi.fk_id_account')->join('t_product_vendors as pp', 'pp.id', '=', 'pi.fk_id_product')->where('pp.fk_id_ent', $ent)->groupBy('a.id', 'a.first_name', 'a.sur_name', 'a.account_code')->orderBy('a.first_name')->get(['a.id', DB::raw("TRIM(CONCAT_WS(' ', a.first_name, a.sur_name)) as name")]))];
    }

    /** One page of the list. $f: q, status, mp, set, page, per. */
    public static function page(int $ent, array $f, ?object $u): array
    {
        $per = in_array((int) ($f['per'] ?? 20), self::SIZES, true) ? (int) $f['per'] : 20; $page = max(1, (int) ($f['page'] ?? 1)); $mp = ! empty($f['mp']) ? (int) $f['mp'] : null;
        $base = self::query($ent, $f, $u); $total = (clone $base)->count(); $pages = max(1, (int) ceil($total / $per)); $page = min($page, $pages);
        $rows = (clone $base)->orderByDesc('p.id')->offset(($page - 1) * $per)->limit($per)->get(['p.*']);
        $out = self::build($ent, $rows, $mp, ($f['set'] ?? 'default') === 'pricing', $u);
        $a = $total ? ($page - 1) * $per + 1 : 0; $b = min($total, $page * $per);
        $mps = DB::table('cat_marketplaces')->whereIn('fk_id_ent', [0, $ent])->where('f_active', 1)->orderBy('marketplace_short')->get(['id', DB::raw("COALESCE(NULLIF(marketplace_short,''), NULLIF(marketplace_descr,''), id) as name")]);
        $money = $u && Perm::has($u, 'stock.prices');
        return ['rows' => $out, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per' => $per, 'sizes' => self::SIZES, 'footer' => "Showing {$a}–{$b} of $total · Page $page of $pages",
            'pager' => range(max(1, $page - 3), min($pages, $page + 3)), 'marketplaces' => $mps, 'columns' => self::DEFAULT_COLS, 'tag_list' => DB::table('cat_tags')->where(fn ($w) => $w->whereIn('fk_id_ent', [0, $ent])->orWhereNull('fk_id_ent'))->where('f_active', 1)->orderBy('tag_name')->get(['id', 'tag_name as name', DB::raw("CASE WHEN tag_color REGEXP '^#[0-9a-fA-F]{6}$' THEN tag_color ELSE '#6c757d' END as color")]), 'pricing_columns' => self::PRICING_COLS, 'row_status' => self::ROW_STATUS, 'types' => self::TYPES, 'owner_types' => self::OWNER_TYPES,
            'view' => self::view($u), 'filter_lists' => self::filterLists($ent), 'filters' => array_filter(array_intersect_key($f, array_flip(self::FILTERS)), fn ($v) => $v !== null && $v !== ''), 'money' => $money, 'pricing_needs_mp' => ($f['set'] ?? '') === 'pricing' && ! $mp];
    }

    /** Batch look-ups for the page's products only. */
    public static function build(int $ent, $rows, ?int $mp, bool $pricing, ?object $u): array
    {
        $ids = $rows->pluck('id')->map(fn ($x) => (int) $x)->all(); if (! $ids) return [];
        $kids = DB::table('t_product_vendors')->whereIn('f_id_parent', $ids)->where('f_active', 1)->groupBy('f_id_parent')->selectRaw('f_id_parent p, COUNT(*) n')->pluck('n', 'p');
        $vis = DB::table('t_product_visibility as v')->leftJoin('t_branches as b', 'b.id', '=', 'v.fk_id_branch')->leftJoin('t_enterprise as e', 'e.id', '=', 'b.fk_id_company')->whereIn('v.fk_id_product', $ids)
            ->get(['v.fk_id_product', 'v.fk_id_marketplace', 'v.visibility_mode', 'e.ent_name'])->groupBy('fk_id_product');
        $inv = DB::table('t_product_investors')->whereIn('fk_id_product', $ids)->groupBy('fk_id_product')->selectRaw('fk_id_product p, COUNT(DISTINCT fk_id_account) n')->pluck('n', 'p');
        $lst = DB::table('t_product_listings')->whereIn('fk_id_product', $ids)->where('f_active', 1)->groupBy('fk_id_product')->selectRaw('fk_id_product p, COUNT(*) n')->pluck('n', 'p');
        $img = DB::table('t_product_vendors_images')->whereIn('fk_id_product_vendors', $ids)->orderByDesc('f_main')->orderBy('sort_no')->orderBy('id')->get(['fk_id_product_vendors', 'thumb_url', 'image_url'])->groupBy('fk_id_product_vendors');
        $wac = self::wac($ids); $tagAll = DB::table('cat_tags')->get(['id', 'tag_name', 'tag_color'])->keyBy('id');      // R-D-13 coloured chips
        $mps = DB::table('cat_marketplaces')->whereIn('fk_id_ent', [0, $ent])->where('f_active', 1)->orderBy('marketplace_short')->get(['id', DB::raw("COALESCE(NULLIF(marketplace_short,''), NULLIF(marketplace_descr,''), id) as name")]);
        $money = $u && Perm::has($u, 'stock.prices');
        $pr = $pricing && $mp ? DB::table('t_product_marketplace_prices')->whereIn('fk_id_product', $ids)->where('fk_id_marketplace', $mp)->get()->keyBy('fk_id_product') : collect();
        $sh = $pricing && $mp ? DB::table('t_product_marketplace_shipping')->whereIn('fk_id_product', $ids)->where('fk_id_marketplace', $mp)->get()->keyBy('fk_id_product') : collect();
        $countries = $pricing ? DB::table('cat_country')->whereIn('id', $rows->pluck('fk_id_country_make')->filter()->unique()->all() ?: [0])->pluck('country_name', 'id') : collect();
        return $rows->map(function ($p) use ($kids, $vis, $inv, $lst, $img, $wac, $mps, $mp, $pricing, $pr, $sh, $money, $countries, $tagAll) {
            $id = (int) $p->id; $n = (int) ($kids[$id] ?? 0); $parent = $n > 0;      // a family = it has active children (f_parent is 1 on every row, so it is not used)
            $v = $vis[$id] ?? collect(); $modes = []; foreach ($v as $x) $modes[(int) $x->fk_id_marketplace] = strtoupper((string) $x->visibility_mode);
            $owners = $v->pluck('ent_name')->filter()->unique()->values()->all();
            $i = ($img[$id] ?? collect())->first(); $thumb = $i ? ($i->thumb_url ?: $i->image_url) : ($p->product_img ?: null);
            $status = self::rowStatus($p, $parent); $cov = self::cover($p);
            $row = ['id' => $id, 'image' => $thumb, 'name' => self::cut((string) $p->product_name_vendor), 'full_name' => (string) $p->product_name_vendor, 'suffix' => $parent ? "— $n" : ($p->variant ? '— ' . $p->variant : ''),
                'sku' => trim((string) $p->SKU_VENDOR) . ' · MP-' . $id, 'owner' => count($owners) > 1 ? 'Multiple' : ($owners[0] ?? ''), 'owners' => $owners,
                'type' => self::type((int) ($inv[$id] ?? 0), $modes, $mp), 'pills' => $mps->map(fn ($m) => ['id' => (int) $m->id, 'name' => $m->name, 'visible' => isset($modes[(int) $m->id]), 'mode' => $modes[(int) $m->id] ?? null])->all(),
                'qty' => (int) $p->stock, 'cover' => $cov['status'], 'cover_basis' => $cov['basis'], 'wac' => $money ? ($wac[$id]['wac'] ?? null) : null, 'wac_tip' => $money && isset($wac[$id]) ? 'WAC = £' . number_format($wac[$id]['total'], 2) . ' / ' . $wac[$id]['qty'] : null,
                'tags' => collect(self::tagIds($p))->map(fn ($t) => isset($tagAll[$t]) ? ['id' => $t, 'name' => $tagAll[$t]->tag_name, 'color' => preg_match('/^#[0-9a-f]{6}$/i', (string) $tagAll[$t]->tag_color) ? $tagAll[$t]->tag_color : '#6c757d'] : null)->filter()->values()->all(),
                'listings' => (int) ($lst[$id] ?? 0) ?: 'none', 'active' => (bool) $p->f_active, 'state' => $p->row_state ?? 'ACTIVE', 'hidden_reason' => $p->hidden_reason ?? null, 'row_status' => $status, 'colour' => self::ROW_STATUS[$status][1]];
            if ($pricing) {
                $r = ['fk_id_marketplace' => $mp, 'raw_price' => (float) ($pr[$id]->raw_price ?? $p->raw_price ?? 0), 'sale_price' => (float) ($pr[$id]->sale_price ?? $p->sale_price ?? 0), 'marketplace_fee' => (float) ($pr[$id]->marketplace_fee ?? 0),
                    'fk_id_car_pkg_svc' => $sh[$id]->fk_id_car_pkg_svc ?? $p->fk_id_car_pkg_svc, 'fk_id_packing' => $sh[$id]->fk_id_packing ?? $p->fk_id_packing, 'packing_fee' => (float) ($sh[$id]->packing_fee ?? 0), 'storage_fee' => (float) ($sh[$id]->storage_fee ?? 0)];
                $fig = $mp ? ProductPrices::figures($id, $r) : null;
                $row += ['raw' => $money && $fig ? $r['raw_price'] : null, 'vat' => $money ? (float) $p->sale_price_with_vat : null, 'postage' => $money && $fig ? $fig['rates']['carrier'] : null, 'packing_rate' => $money && $fig ? $fig['rates']['packing'] : null,
                    'fee' => $money && $fig ? $r['marketplace_fee'] : null, 'final_sale' => $money && $fig ? $r['sale_price'] : null, 'cost' => $money && $fig ? $fig['fifo'] : null, 'margin' => $money && $fig ? $fig['margin'] : null, 'margin_pct' => $money && $fig ? $fig['margin_pct'] : null,
                    'stock' => (int) $p->stock, 'min_level' => (int) $p->min_level, 'alert' => (int) $p->f_alert_min_level ? 'Yes' : 'No', 'family' => $parent ? 'Yes' : 'No', 'variant' => (string) $p->variant, 'created' => $p->d_created ? substr((string) $p->d_created, 0, 10) : '',
                    'country' => (string) ($countries[$p->fk_id_country_make] ?? ''), 'weight' => (string) $p->weight, 'dimensions' => (string) $p->dimensions, 'carrier' => $fig['rates']['carrier_name'] ?? '', 'packing' => $fig['rates']['packing_name'] ?? '',
                    'image_url' => (string) ($thumb ?? ''), 'expiry' => $p->d_product_expiry_date ? substr((string) $p->d_product_expiry_date, 0, 10) : '', 'description' => mb_substr(strip_tags((string) $p->product_description), 0, 200)];
            }
            return $row;
        })->all();
    }

    /** Text of one cell for the files (HTML stripped). */
    public static function text(array $r, string $k): string
    {
        return match ($k) {
            'image' => (string) ($r['image'] ?? ''), 'name' => trim($r['full_name'] . ' ' . $r['suffix']), 'owner' => $r['owner'] === 'Multiple' ? 'Multiple (' . implode(', ', $r['owners']) . ')' : $r['owner'],
            'type' => (string) ($r['type'] ?? ''), 'tags' => implode(', ', array_column($r['tags'] ?? [], 'name')), 'marketplaces' => implode(', ', array_map(fn ($p) => $p['name'] . ($p['visible'] ? ' (' . ($p['mode'] ?: '') . ')' : ''), array_filter($r['pills'], fn ($p) => $p['visible']))),
            'status' => $r['active'] ? 'ACTIVE' : 'INACTIVE', 'wac' => $r['wac'] === null ? '' : number_format((float) $r['wac'], 2, '.', ''),
            default => is_bool($r[$k] ?? null) ? ($r[$k] ? 'Yes' : 'No') : (is_float($r[$k] ?? null) ? number_format($r[$k], 2, '.', '') : strip_tags((string) ($r[$k] ?? ''))),
        };
    }

    /** CSV / Excel / PDF of the whole filtered list (current search and order, visible columns). */
    public static function export(int $ent, array $f, string $format, ?object $u): array
    {
        if (! in_array($format, ['csv', 'xlsx', 'pdf'], true)) throw new \InvalidArgumentException('Unknown format.');
        $pricing = ($f['set'] ?? '') === 'pricing'; $mp = ! empty($f['mp']) ? (int) $f['mp'] : null; $hidden = (array) ($f['hidden'] ?? self::view($u)['hidden']);
        $cols = array_diff_key(self::DEFAULT_COLS + ($pricing ? self::PRICING_COLS : []), array_flip(array_merge($hidden, ['image'])));
        if (! ($u && Perm::has($u, 'stock.prices'))) $cols = array_diff_key($cols, array_flip(['wac', 'raw', 'vat', 'postage', 'packing_rate', 'fee', 'final_sale', 'cost', 'margin', 'margin_pct']));
        $rows = self::query($ent, $f, $u)->orderByDesc('p.id')->limit(5000)->get(['p.*']); $data = [];
        foreach ($rows->chunk(200) as $c) $data = array_merge($data, self::build($ent, $c->values(), $mp, $pricing, $u));
        $grid = array_map(fn ($r) => array_map(fn ($k) => self::text($r, $k), array_keys($cols)), $data); $head = array_values($cols);
        $name = 'products_' . date('Y-m-d_His');
        Audit::log('t_product_vendors', null, 'EXPORT', ['format' => $format, 'rows' => count($grid), 'filters' => array_intersect_key($f, array_flip(['q', 'status', 'mp', 'set']))], null, $ent);
        if ($format === 'csv') { $h = fopen('php://temp', 'r+'); fwrite($h, "\xEF\xBB\xBF"); fputcsv($h, $head); foreach ($grid as $g) fputcsv($h, array_map(fn ($v) => preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v, $g)); rewind($h); return ['name' => "$name.csv", 'type' => 'text/csv; charset=UTF-8', 'body' => stream_get_contents($h), 'rows' => count($grid)]; }
        if ($format === 'xlsx') {
            $x = new \PhpOffice\PhpSpreadsheet\Spreadsheet(); $sh = $x->getActiveSheet(); $sh->setTitle('Products');
            foreach ($head as $i => $hh) $sh->setCellValue([$i + 1, 1], $hh);
            foreach ($grid as $n => $g) foreach ($g as $i => $v) $sh->setCellValueExplicit([$i + 1, $n + 2], $v, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $tmp = tempnam(sys_get_temp_dir(), 'pl'); \App\Services\Brand::xlsx($x); (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($x))->save($tmp); $body = file_get_contents($tmp); @unlink($tmp);
            return ['name' => "$name.xlsx", 'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'body' => $body, 'rows' => count($grid)];
        }
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES);
        $html = '<html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:8px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #ccc;padding:2px 3px;text-align:left}th{background:#eee}</style></head><body><h3>Products</h3><table><tr>'
            . implode('', array_map(fn ($h) => '<th>' . $e($h) . '</th>', $head)) . '</tr>' . implode('', array_map(fn ($g, $r) => '<tr style="color:' . $r['colour'] . '">' . implode('', array_map(fn ($v) => '<td>' . $e($v) . '</td>', $g)) . '</tr>', $grid, $data)) . '</table></body></html>';
        return ['name' => "$name.pdf", 'type' => 'application/pdf', 'body' => PrintService::pdf($html, 297, 210), 'rows' => count($grid)];
    }
}
