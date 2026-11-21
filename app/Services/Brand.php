<?php

namespace App\Services;

/**
 * The company logo on every A4 PDF (PO, GRN, prints, cycle reports). Owner answer 21 Nov 2026: use the approved MoreOrderz logo
 * ("Engineering seal", the same as in the app). A company may upload its own file later (setting COMPANY_LOGO = a stored file path);
 * without it the seal is used. Embedded as a data URI so dompdf needs no network.
 */
class Brand
{
    public const SEAL = 'resources/brand/moreorderz-seal.png';

    public static function file(?int $ent = null): string
    {
        $own = (string) (Settings::get('COMPANY_LOGO', $ent, '') ?? '');
        if ($own !== '' && is_file($p = storage_path('app/' . ltrim($own, '/'))) && in_array(strtolower(pathinfo($p, PATHINFO_EXTENSION)), ['png', 'jpg', 'jpeg'], true)) return $p;
        return base_path(self::SEAL);
    }

    public static function dataUri(?int $ent = null): string
    {
        $f = self::file($ent); $type = strtolower(pathinfo($f, PATHINFO_EXTENSION)) === 'png' ? 'png' : 'jpeg';
        return 'data:image/' . $type . ';base64,' . base64_encode((string) file_get_contents($f));
    }

    public const WATERMARK = 'resources/brand/moreorderz-watermark.png';
    /** Pages smaller than this (labels, thermal slips) get no watermark and no extra header logo. */
    public const MIN_MM = 120;

    /**
     * Owner 21 Nov 2026: every A4 / letter-size PDF page carries a very light watermark (the seal + "VARTLEAVES LIMITED"), and the
     * logo at the top when the document has none of its own. position:fixed repeats it on every page in dompdf.
     */
    public static function stamp(string $html, float $wMm, float $hMm, ?int $ent = null): string
    {
        if (min($wMm, $hMm) < self::MIN_MM || str_contains($html, 'data-wm="1"')) return $html;
        $w = round(min($wMm, $hMm) * 0.55); $left = round(($wMm - $w) / 2); $top = round(($hMm - $w * 1.03) / 2);
        $wm = '<div data-wm="1" style="position:fixed;left:' . $left . 'mm;top:' . $top . 'mm;width:' . $w . 'mm;z-index:-1"><img src="data:image/png;base64,' . base64_encode((string) file_get_contents(base_path(self::WATERMARK))) . '" style="width:' . $w . 'mm"></div>';
        $logo = str_contains($html, 'alt="logo"') ? '' : '<div style="text-align:right;margin:0 0 2mm">' . self::img($ent, 12) . '</div>';
        if (preg_match('~<body[^>]*>~i', $html, $m, PREG_OFFSET_CAPTURE)) { $at = $m[0][1] + strlen($m[0][0]); return substr($html, 0, $at) . $wm . $logo . substr($html, $at); }
        return $wm . $logo . $html;
    }

    /**
     * The logo on every Excel export (owner 21 Nov 2026): a picture at the top right of each sheet, next to the table (the data keeps row 1,
     * so a file can still be imported again), and in the printed page header with "Vartleaves Limited". A CSV file cannot hold a picture.
     */
    public static function xlsx(\PhpOffice\PhpSpreadsheet\Spreadsheet $book, ?int $ent = null): void
    {
        foreach ($book->getAllSheets() as $sh) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sh->getHighestColumn()) + 2;
            $d = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing(); $d->setName('MoreOrderz'); $d->setDescription('logo'); $d->setPath(self::file($ent)); $d->setHeight(64);
            $d->setCoordinates(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . '1'); $d->setWorksheet($sh);
            $h = new \PhpOffice\PhpSpreadsheet\Worksheet\HeaderFooterDrawing(); $h->setName('logo'); $h->setPath(self::file($ent)); $h->setHeight(36);
            $sh->getHeaderFooter()->addImage($h, \PhpOffice\PhpSpreadsheet\Worksheet\HeaderFooter::IMAGE_HEADER_LEFT);
            $sh->getHeaderFooter()->setOddHeader('&L&G&RVartleaves Limited');
        }
    }

    /** <img> for a PDF header; $mm = height in millimetres. */
    public static function img(?int $ent = null, int $mm = 16): string
    {
        return '<img src="' . self::dataUri($ent) . '" alt="logo" style="height:' . $mm . 'mm;width:' . $mm . 'mm">';
    }
}
