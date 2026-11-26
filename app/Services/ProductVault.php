<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Owner 27-11 02:31 – the product's vault ("Vector"): every file of a product in its own place – images, listing images per marketplace, Amazon A+ content,
 * labels, packaging artwork (vector .ai / .pdf / .eps / .svg), dielines, carton marks, certificates, manuals. A file that comes in anywhere (Import anything,
 * File a document, a supplier) is recognised – which product (SKU in the name or the text, or the record it was filed from) and which place (from its
 * name, type and words) – and offered there; one press puts it in. The vault says what an important place still lacks (main image, the listing images of
 * each marketplace the product is on, the FNSKU label of an Amazon listing, A+ for Amazon, the artwork of a custom box).
 */
class ProductVault
{
    public const KINDS = [
        'IMAGE_MAIN' => 'Main image', 'IMAGE' => 'Product images', 'LIFESTYLE' => 'Lifestyle images', 'LISTING' => 'Listing images', 'A_PLUS' => 'A+ / A++ content', 'VIDEO' => 'Videos',
        'LABEL_FNSKU' => 'FNSKU labels', 'BARCODE' => 'Barcode / EAN', 'CARTON_MARK' => 'Carton labels & marks', 'ARTWORK' => 'Packaging artwork (vector)', 'DIELINE' => 'Dielines',
        'PACKAGING_PHOTO' => 'Packaging photos', 'CERTIFICATE' => 'Certificates & tests', 'MANUAL' => 'Manuals & inserts', 'OTHER' => 'Other files',
    ];
    public const VECTOR = ['ai', 'eps', 'svg', 'cdr', 'psd', 'indd'];
    public const IMAGE = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'tif', 'tiff', 'heic'];

    /** An image kept on the product: a full link as it is; an old tool file name → the old tool's image folder (Settings OLD_IMAGE_BASE, e.g. https://old-tool/uploads/product_images/). */
    public static function imageUrl(?string $v): ?string
    {
        $v = trim((string) $v); if ($v === '') return null; if (preg_match('~^(https?:)?//|^/~i', $v)) return $v;
        $base = trim((string) Settings::get('OLD_IMAGE_BASE', null, '')); return $base !== '' && preg_match('/^[\w.\- ()]+\.(jpe?g|png|webp|gif|bmp)$/i', $v) ? rtrim($base, '/') . '/' . rawurlencode($v) : null;
    }

    /** The place of a file from its name, type and words. */
    public static function guess(string $name, string $ext = '', string $text = ''): string
    {
        $ext = strtolower($ext ?: pathinfo($name, PATHINFO_EXTENSION)); $h = mb_strtolower($name . ' ' . mb_substr($text, 0, 3000)); $n = mb_strtolower(preg_replace('/[_\-.]+/', ' ', $name));
        if (preg_match('/\b(a\+|a\s?plus|aplus|a\+\+|ebc|enhanced brand)\b/u', $n) || str_contains($n, 'a+')) return 'A_PLUS';
        if (preg_match('/\bfnsku\b|\bx00[0-9a-z]{7}\b/u', $h)) return 'LABEL_FNSKU';
        if (preg_match('/\b(die ?line|dieline|cutting line|knife line)\b/u', $h)) return 'DIELINE';
        if (preg_match('/\b(carton|shipping) ?(mark|label|marks)\b|\bmaster carton\b/u', $h)) return 'CARTON_MARK';
        if (preg_match('/\b(ean|upc|gtin|barcode)\b/u', $n)) return 'BARCODE';
        if (preg_match('/\b(certificate|ce|ukca|fcc|rohs|reach|msds|sds|test report|doc|declaration of conformity)\b/u', $n)) return 'CERTIFICATE';
        if (preg_match('/\b(manual|instruction|insert|leaflet|user guide)\b/u', $n)) return 'MANUAL';
        if (in_array($ext, self::VECTOR, true) || preg_match('/\b(artwork|print file|packaging design|box design)\b/u', $n)) return 'ARTWORK';
        if (in_array($ext, ['mp4', 'mov', 'webm', 'avi'], true)) return 'VIDEO';
        if (in_array($ext, self::IMAGE, true)) {
            if (preg_match('/\b(lifestyle|in use|scene|model|room)\b/u', $n)) return 'LIFESTYLE';
            if (preg_match('/\b(box|package|packaging|pack shot)\b/u', $n)) return 'PACKAGING_PHOTO';
            if (preg_match('/\b(amazon|ebay|etsy|tiktok|temu|onbuy|listing|infographic)\b/u', $n)) return 'LISTING';
            if (preg_match('/\b(main|hero|white ?(bg|background)|primary|cover)\b/u', $n)) return 'IMAGE_MAIN';
            return 'IMAGE';
        }
        if (preg_match('/\b(label|sticker)\b/u', $n)) return 'CARTON_MARK';
        return 'OTHER';
    }

    /** The marketplace a listing image is for, from its name. */
    public static function marketplaceOf(string $name, int $ent): ?int
    {
        $n = mb_strtolower($name);
        foreach (DB::table('cat_marketplaces')->where(fn ($w) => $w->where('fk_id_ent', $ent)->orWhere('fk_id_ent', 0))->get(['id', 'marketplace_short']) as $m) {
            $w = mb_strtolower(preg_replace('/[^a-z]/i', '', explode('_', (string) $m->marketplace_short)[0]));
            if (mb_strlen($w) >= 3 && str_contains($n, $w)) return (int) $m->id;
        }
        return null;
    }

    /** The product a file belongs to: a SKU in its name (longest match), else in its text. */
    public static function productOf(int $ent, string $name, string $text = ''): ?int
    {
        $hay = mb_strtoupper($name . ' ' . mb_substr($text, 0, 20000)); $best = null; $len = 0;
        foreach (DB::table('t_product_vendors')->where('fk_id_ent', $ent)->where('f_active', 1)->whereRaw('CHAR_LENGTH(SKU_VENDOR) >= 4')->limit(20000)->pluck('SKU_VENDOR', 'id') as $id => $sku) {
            $s = mb_strtoupper((string) $sku);
            if (mb_strlen($s) > $len && preg_match('~(?<![A-Z0-9])' . preg_quote($s, '~') . '(?![A-Z0-9])~u', $hay)) { $best = (int) $id; $len = mb_strlen($s); }
        }
        return $best;
    }

    /** Put a document in a product's vault (once). */
    public static function put(int $ent, int $product, int $document, string $kind, ?int $marketplace = null, ?object $u = null): int
    {
        $kind = isset(self::KINDS[$kind]) ? $kind : 'OTHER';
        $have = DB::table('product_assets')->where(['fk_id_product' => $product, 'fk_id_document' => $document])->first();
        if ($have) { DB::table('product_assets')->where('id', $have->id)->update(['kind' => $kind, 'fk_id_marketplace' => $marketplace, 'updated_at' => now()]); return (int) $have->id; }
        $main = $kind === 'IMAGE_MAIN' && ! DB::table('product_assets')->where('fk_id_product', $product)->where('kind', 'IMAGE_MAIN')->where('f_main', 1)->exists();
        return (int) DB::table('product_assets')->insertGetId(['fk_id_ent' => $ent, 'fk_id_product' => $product, 'fk_id_document' => $document, 'kind' => $kind, 'fk_id_marketplace' => $marketplace,
            'f_main' => $main ? 1 : 0, 'sort_no' => (int) DB::table('product_assets')->where('fk_id_product', $product)->where('kind', $kind)->max('sort_no') + 10, 'created_by' => $u->id ?? null, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Move a file to another place / marketplace, make it the main one, or take it out of the vault (the document itself stays). */
    public static function change(int $ent, int $id, array $in, ?object $u): array
    {
        $a = DB::table('product_assets')->where('id', $id)->where('fk_id_ent', $ent)->first() ?? throw new \InvalidArgumentException('Not found.');
        if (! empty($in['remove'])) { DB::table('product_assets')->where('id', $id)->delete(); Audit::log('product_assets', $id, 'DELETE', ['kind' => [$a->kind, null]], 'Vault', $ent); return ['message' => 'Taken out of the vault (the file is kept in Documents).']; }
        $set = ['updated_at' => now()];
        if (isset($in['kind'])) { if (! isset(self::KINDS[$in['kind']])) throw new \InvalidArgumentException('Unknown place.'); $set['kind'] = $in['kind']; }
        if (array_key_exists('marketplace', $in)) $set['fk_id_marketplace'] = $in['marketplace'] ? (int) $in['marketplace'] : null;
        if (! empty($in['main'])) { DB::table('product_assets')->where('fk_id_product', $a->fk_id_product)->where('kind', $set['kind'] ?? $a->kind)->update(['f_main' => 0]); $set['f_main'] = 1; }
        DB::table('product_assets')->where('id', $id)->update($set);
        Audit::log('product_assets', $id, 'UPDATE', array_map(fn ($v) => [null, $v], array_diff_key($set, ['updated_at' => 1])), 'Vault', $ent);
        return ['message' => 'Saved.'];
    }

    /** The vault of a product: every place with its files (and the images kept on the product itself), what is missing. */
    public static function page(int $ent, int $pid): array
    {
        $p = DB::table('t_product_vendors')->where('id', $pid)->first(['id', 'SKU_VENDOR', 'product_name_vendor', 'product_img', 'f_id_parent', 'fk_id_pack_box']) ?? throw new \InvalidArgumentException('Product not found.');
        $files = DB::table('product_assets as a')->join('sys_documents as d', 'd.id', '=', 'a.fk_id_document')->leftJoin('cat_marketplaces as m', 'm.id', '=', 'a.fk_id_marketplace')
            ->where('a.fk_id_product', $pid)->where('d.status', 'ACTIVE')->orderByDesc('a.f_main')->orderBy('a.sort_no')
            ->get(['a.id', 'a.kind', 'a.f_main', 'a.fk_id_marketplace', 'm.marketplace_short as marketplace', 'd.id as doc', 'd.doc_no', 'd.title', 'd.orig_name', 'd.mime', 'd.drive_url', 'd.created_at']);
        $places = [];
        foreach (self::KINDS as $k => $label) $places[$k] = ['kind' => $k, 'label' => $label, 'files' => []];
        foreach ($files as $f) $places[$f->kind]['files'][] = ['id' => (int) $f->id, 'doc' => (int) $f->doc, 'doc_no' => $f->doc_no, 'name' => $f->orig_name ?: $f->title, 'url' => $f->drive_url, 'main' => (bool) $f->f_main,
            'image' => str_starts_with((string) $f->mime, 'image/') || preg_match('/\.(jpe?g|png|webp|gif)$/i', (string) $f->orig_name), 'marketplace' => $f->marketplace, 'marketplace_id' => $f->fk_id_marketplace ? (int) $f->fk_id_marketplace : null, 'at' => substr((string) $f->created_at, 0, 10)];
        if ($img = self::imageUrl($p->product_img)) array_unshift($places['IMAGE_MAIN']['files'], ['id' => null, 'doc' => null, 'name' => 'Image on the product', 'url' => $img, 'main' => ! $places['IMAGE_MAIN']['files'], 'image' => true, 'marketplace' => null, 'kept_on_product' => true]);
        if (\Illuminate\Support\Facades\Schema::hasTable('t_product_vendors_images')) foreach (DB::table('t_product_vendors_images as i')->leftJoin('cat_marketplaces as m', 'm.id', '=', 'i.fk_id_marketplace')->where('i.fk_id_product_vendors', $pid)->orderBy('i.sort_no')->get(['i.image_url', 'i.f_main', 'm.marketplace_short']) as $i)
            $places[$i->marketplace_short ? 'LISTING' : 'IMAGE']['files'][] = ['id' => null, 'doc' => null, 'name' => basename((string) parse_url((string) $i->image_url, PHP_URL_PATH)) ?: 'image', 'url' => $i->image_url, 'main' => (bool) $i->f_main, 'image' => true, 'marketplace' => $i->marketplace_short, 'kept_on_product' => true];
        // what an important place still lacks
        $need = [];
        if (! $places['IMAGE_MAIN']['files']) $need[] = ['kind' => 'IMAGE_MAIN', 'text' => 'No main image yet.'];
        $listed = DB::table('t_product_listings as l')->join('cat_marketplaces as m', 'm.id', '=', 'l.fk_id_marketplace')->where('l.fk_id_product', $pid)->where('l.f_active', 1)->where('l.status', 'ACTIVE')
            ->groupBy('m.id', 'm.marketplace_short')->selectRaw("m.id, m.marketplace_short, MAX(CASE WHEN l.fulfilment = 'FBA' THEN 1 ELSE 0 END) fba")->get();
        foreach ($listed as $m) {
            if (! array_filter($places['LISTING']['files'], fn ($f) => ($f['marketplace_id'] ?? null) === (int) $m->id || $f['marketplace'] === $m->marketplace_short)) $need[] = ['kind' => 'LISTING', 'marketplace' => (int) $m->id, 'text' => "Listed on {$m->marketplace_short} – no listing images of it here yet."];
            if (stripos((string) $m->marketplace_short, 'AMAZON') === 0) {
                if (! $places['A_PLUS']['files']) $need[] = ['kind' => 'A_PLUS', 'text' => "On {$m->marketplace_short} – no A+ content here yet."];
                if ((int) $m->fba && ! $places['LABEL_FNSKU']['files']) $need[] = ['kind' => 'LABEL_FNSKU', 'text' => "Sold by FBA on {$m->marketplace_short} – no FNSKU label here yet."];
            }
        }
        if ((int) $p->fk_id_pack_box && ! $places['ARTWORK']['files'] && ! $places['DIELINE']['files']) $need[] = ['kind' => 'ARTWORK', 'text' => 'It has its own pack box – no artwork or dieline here yet.'];
        $need = array_values(array_unique($need, SORT_REGULAR));
        return ['product' => ['id' => (int) $p->id, 'sku' => $p->SKU_VENDOR, 'name' => $p->product_name_vendor], 'places' => array_values($places), 'count' => count($files), 'needed' => $need,
            'kinds' => self::KINDS, 'marketplaces' => $listed->map(fn ($m) => ['id' => (int) $m->id, 'name' => $m->marketplace_short])->all()];
    }
}
