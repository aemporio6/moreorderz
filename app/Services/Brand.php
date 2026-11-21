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

    /** <img> for a PDF header; $mm = height in millimetres. */
    public static function img(?int $ent = null, int $mm = 16): string
    {
        return '<img src="' . self::dataUri($ent) . '" alt="logo" style="height:' . $mm . 'mm;width:' . $mm . 'mm">';
    }
}
