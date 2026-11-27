<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Picqer\Barcode\BarcodeGeneratorPNG;

/**
 * MR-0134 own labels from the Label Designer (old tool: bin labels only; product, carton, FNSKU and pallet labels were made outside the tool).
 * A design = name, kind (product / carton / FNSKU / pallet / goods-in), size (presets or own mm), the fields in their order (each normal / big / small,
 * with or without its caption) and one barcode (Code 128, EAN-13, QR) of a chosen field. Every value is read from the product record and what it is
 * linked to – SKU, name, colour, size, EAN / UPC, FNSKU of its listing, weight, origin, units per carton, latest batch (lot) – so nothing is typed twice.
 * "Carton x / y" and "Pallet x / y" count the labels of one print (qty). Printing goes through the normal print path (routes, printer, media, log, reprint reason).
 */
class LabelDesigner
{
    public const KINDS = ['PRODUCT' => 'Product / SKU label', 'CARTON' => 'Carton label', 'FNSKU' => 'FNSKU / marketplace item label', 'PALLET' => 'Pallet label', 'GOODS_IN' => 'Goods-in label'];
    public const SIZES = ['50x25' => [50, 25], '60x40' => [60, 40], '70x37' => [70, 37], '100x50' => [100, 50], '100x100' => [100, 100], '100x150' => [100, 150], 'A6' => [105, 148]];
    public const BARCODES = ['CODE128' => 'Code 128', 'EAN13' => 'EAN-13', 'QR' => 'QR code', 'NONE' => 'No barcode'];
    public const FIELDS = ['sku' => 'SKU', 'name' => 'Product name', 'variant' => 'Variant (colour / size)', 'ean' => 'EAN / UPC', 'fnsku' => 'FNSKU', 'asin' => 'ASIN',
        'qty' => 'Quantity', 'units_per_carton' => 'Units per carton', 'batch' => 'Batch (lot)', 'count' => 'Carton / pallet no. (x / y)', 'weight' => 'Weight (kg)',
        'carton_weight' => 'Carton gross weight (kg)', 'origin' => 'Made in', 'date' => 'Date', 'company' => 'Company', 'text' => 'Own text'];
    /** a ready design per kind, so the designer never starts empty */
    public const START = ['PRODUCT' => [['sku', 'big'], ['name', 'normal'], ['variant', 'small'], ['ean', 'small']], 'CARTON' => [['sku', 'big'], ['name', 'normal'], ['units_per_carton', 'normal', true], ['count', 'big', true], ['carton_weight', 'small', true], ['origin', 'small', true]],
        'FNSKU' => [['fnsku', 'normal'], ['name', 'small'], ['variant', 'small']], 'PALLET' => [['company', 'normal'], ['sku', 'big'], ['qty', 'big', true], ['count', 'big', true], ['date', 'small', true]],
        'GOODS_IN' => [['sku', 'big'], ['name', 'normal'], ['batch', 'normal', true], ['qty', 'normal', true], ['date', 'small', true]]];
    public const START_BARCODE = ['PRODUCT' => ['CODE128', 'sku'], 'CARTON' => ['CODE128', 'sku'], 'FNSKU' => ['CODE128', 'fnsku'], 'PALLET' => ['QR', 'sku'], 'GOODS_IN' => ['CODE128', 'batch']];
    public const START_SIZE = ['PRODUCT' => '50x25', 'CARTON' => '100x150', 'FNSKU' => '50x25', 'PALLET' => '100x150', 'GOODS_IN' => '100x50'];

    private static function e(mixed $v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

    public static function list(int $ent): array
    {
        return DB::table('label_designs')->whereIn('fk_id_ent', [0, $ent])->where('f_active', 1)->orderBy('design_kind')->orderBy('name')->get()->map(fn ($d) => self::row($d))->all();
    }

    private static function row(object $d): array
    {
        return ['id' => (int) $d->id, 'name' => $d->name, 'kind' => $d->design_kind, 'kind_name' => self::KINDS[$d->design_kind] ?? $d->design_kind, 'w' => (float) $d->width_mm, 'h' => (float) $d->height_mm,
            'fields' => json_decode((string) $d->fields_json, true) ?: [], 'barcode' => $d->barcode_type, 'barcode_field' => $d->barcode_field, 'text' => (string) $d->own_text];
    }

    public static function find(int $ent, int $id): array
    {
        $d = DB::table('label_designs')->where('id', $id)->whereIn('fk_id_ent', [0, $ent])->first() ?? throw new \InvalidArgumentException('Label design not found.');
        return self::row($d);
    }

    /** A new design starts from the ready one of its kind. */
    public static function blank(string $kind): array
    {
        $kind = isset(self::KINDS[$kind]) ? $kind : 'PRODUCT'; [$w, $h] = self::SIZES[self::START_SIZE[$kind]];
        return ['id' => null, 'name' => self::KINDS[$kind], 'kind' => $kind, 'w' => $w, 'h' => $h, 'fields' => array_map(fn ($f) => ['field' => $f[0], 'size' => $f[1], 'caption' => (bool) ($f[2] ?? false)], self::START[$kind]),
            'barcode' => self::START_BARCODE[$kind][0], 'barcode_field' => self::START_BARCODE[$kind][1], 'text' => ''];
    }

    public static function save(int $ent, array $d, ?int $user): int
    {
        $name = trim((string) ($d['name'] ?? '')); if ($name === '') throw new \InvalidArgumentException('Give the label a name.');
        $kind = (string) ($d['kind'] ?? ''); if (! isset(self::KINDS[$kind])) throw new \InvalidArgumentException('Choose what the label is for.');
        $w = (float) ($d['w'] ?? 0); $h = (float) ($d['h'] ?? 0); if ($w < 20 || $w > 216 || $h < 10 || $h > 300) throw new \InvalidArgumentException('Width 20–216 mm and height 10–300 mm.');
        $fields = []; foreach ((array) ($d['fields'] ?? []) as $f) { $k = (string) ($f['field'] ?? ''); if (! isset(self::FIELDS[$k])) continue;
            $fields[] = ['field' => $k, 'size' => in_array($f['size'] ?? '', ['big', 'small'], true) ? $f['size'] : 'normal', 'caption' => ! empty($f['caption'])]; }
        if (! $fields) throw new \InvalidArgumentException('Choose at least one field.');
        $bc = (string) ($d['barcode'] ?? 'NONE'); if (! isset(self::BARCODES[$bc])) $bc = 'NONE'; $bf = (string) ($d['barcode_field'] ?? 'sku'); if (! isset(self::FIELDS[$bf])) $bf = 'sku';
        $row = ['name' => mb_substr($name, 0, 120), 'design_kind' => $kind, 'width_mm' => round($w, 1), 'height_mm' => round($h, 1), 'fields_json' => json_encode($fields), 'barcode_type' => $bc, 'barcode_field' => $bf,
            'own_text' => mb_substr(trim((string) ($d['text'] ?? '')), 0, 200), 'f_active' => 1, 'updated_by' => $user, 'updated_at' => now()];
        $id = (int) ($d['id'] ?? 0);
        if ($id) { if (! DB::table('label_designs')->where('id', $id)->where('fk_id_ent', $ent)->update($row) && ! DB::table('label_designs')->where('id', $id)->where('fk_id_ent', $ent)->exists()) throw new \InvalidArgumentException('Label design not found.'); }
        else $id = (int) DB::table('label_designs')->insertGetId($row + ['fk_id_ent' => $ent, 'created_by' => $user, 'created_at' => now()]);
        Audit::log('label_designs', $id, $d['id'] ?? null ? 'UPDATE' : 'INSERT', ['design' => [null, $name]], null, $ent);
        return $id;
    }

    public static function remove(int $ent, int $id): void { DB::table('label_designs')->where('id', $id)->where('fk_id_ent', $ent)->update(['f_active' => 0, 'updated_at' => now()]); }

    /** Every value a label can show, read from the product and what it is linked to. */
    public static function values(int $ent, int $productId, int $qty = 1): array
    {
        $p = DB::table('t_product_vendors')->where('id', $productId)->where('fk_id_ent', $ent)->first() ?? throw new \InvalidArgumentException('Product not found.');
        $listing = DB::table('t_product_listings')->where('fk_id_product', $productId)->where(fn ($w) => $w->whereNotNull('fnsku')->where('fnsku', '<>', '')->orWhereNotNull('asin'))->orderByRaw("fnsku IS NULL OR fnsku = ''")->first(['fnsku', 'asin']);
        $origin = ($c = (int) ($p->fk_id_origin_country ?: $p->fk_id_country_make)) ? DB::table('cat_country')->where('id', $c)->value('country_name') : null;
        $num = fn ($v) => is_numeric($v) && (float) $v > 0 ? rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.') : '';
        return ['sku' => (string) $p->SKU_VENDOR, 'name' => (string) $p->product_name_vendor, 'variant' => trim(implode(' / ', array_filter([$p->f_color ?? null, $p->f_size ?? null]))),
            'ean' => (string) ($p->ean ?: $p->upc ?: ''), 'fnsku' => (string) ($listing->fnsku ?? ''), 'asin' => (string) ($listing->asin ?? ''), 'qty' => (string) $qty,
            'units_per_carton' => (string) ((int) $p->units_per_carton ?: ''), 'batch' => (string) (DB::table('fin_lots')->where('fk_id_product', $productId)->whereNull('voided_at')->orderByDesc('received_date')->orderByDesc('id')->value('lot_code') ?? ''),
            'weight' => $num($p->unit_gross_weight ?: $p->weight), 'carton_weight' => $num($p->carton_gross_weight), 'origin' => (string) ($origin ?? ''), 'date' => date('d M Y'),
            'company' => (string) (DB::table('t_enterprise')->where('id', $ent)->value('ent_name') ?? '')];
    }

    private static function code(string $type, string $value, float $wMm): string
    {
        if ($value === '' || $type === 'NONE') return '';
        if ($type === 'QR') return '<img src="' . PrintService::qr($value, 240) . '" style="width:' . min(30, $wMm * 0.35) . 'mm">';
        $t = $type === 'EAN13' && preg_match('/^\d{12,13}$/', $value) ? BarcodeGeneratorPNG::TYPE_EAN_13 : BarcodeGeneratorPNG::TYPE_CODE_128;      // not an EAN number → Code 128, never a broken barcode
        return '<img src="data:image/png;base64,' . base64_encode((new BarcodeGeneratorPNG())->getBarcode($value, $t, 2, 60)) . '" style="width:' . max(25, $wMm - 6) . 'mm;height:' . ($wMm < 60 ? 8 : 12) . 'mm">';
    }

    /** One label per copy; "x / y" counts the copies of this print. */
    public static function pages(int $ent, array $ids, array $design, int $copies): array
    {
        $pages = []; $w = (float) $design['w']; $base = $w < 60 ? 7 : ($w < 110 ? 9 : 11);
        foreach ($ids as $pid) {
            $v = self::values($ent, (int) $pid, $copies) + ['text' => (string) $design['text']];
            for ($i = 1; $i <= $copies; $i++) {
                $v['count'] = $i . ' / ' . $copies; $html = '';
                foreach ($design['fields'] as $f) { $val = $v[$f['field']] ?? ''; if ($val === '') continue;
                    $pt = ['big' => $base + 5, 'small' => $base - 2][$f['size']] ?? $base;
                    $html .= '<div style="font-size:' . $pt . 'pt;line-height:1.15;' . ($f['size'] === 'big' ? 'font-weight:bold;' : '') . 'overflow:hidden;white-space:nowrap">' . ($f['caption'] ? '<span style="font-weight:normal;font-size:' . max(5, $pt - 2) . 'pt">' . self::e(self::FIELDS[$f['field']]) . ': </span>' : '') . self::e($val) . '</div>'; }
                $code = self::code((string) $design['barcode'], (string) ($v[$design['barcode_field']] ?? ''), $w);
                $pages[] = '<div style="padding:' . ($w < 60 ? 1.5 : 3) . 'mm">' . $html . ($code ? '<div style="margin-top:1mm">' . $code . '<div style="font-size:' . max(5, $base - 2) . 'pt">' . self::e($v[$design['barcode_field']] ?? '') . '</div></div>' : '') . '</div>';
            }
        }
        return $pages;
    }

    /** The PDF of a design for one product, at the design's size – what the designer shows while you change it. Nothing is saved or printed. */
    public static function preview(int $ent, array $design, ?int $productId): string
    {
        $pid = $productId ?: (int) DB::table('t_product_vendors')->where('fk_id_ent', $ent)->where('f_active', 1)->orderByDesc('id')->value('id');
        if (! $pid) throw new \InvalidArgumentException('Add a product first – the preview shows a real product.');
        $w = (float) $design['w']; $h = (float) $design['h']; $copies = in_array('count', array_column($design['fields'], 'field'), true) ? 2 : 1;
        $body = ''; foreach (self::pages($ent, [$pid], $design, $copies) as $i => $p) $body .= '<div style="width:' . $w . 'mm;height:' . $h . 'mm;overflow:hidden;' . ($i ? 'page-break-before:always;' : '') . '">' . $p . '</div>';
        return PrintService::pdf('<!doctype html><html><head><meta charset="utf-8"><style>@page{margin:0} body{margin:0;font-family:\'DejaVu Sans\',sans-serif}</style></head><body>' . $body . '</body></html>', $w, $h, false);
    }
}
