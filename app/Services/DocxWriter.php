<?php

namespace App\Services;

/**
 * DOCX of a list (R-G_CORE-72, old Simple_docx): landscape A4, margins 567 twips (1 cm), equal column widths, header row repeated on every page,
 * rows never split across pages, row fills (Refunded F5B7B1, loss FDECEA – the colours of the PDF / XLSX), title 16 pt + grey subtitle (the filters),
 * a summary line "Label: value | …", an empty-list text. Built without the php-zip extension: the .docx package is written by zip() below (stored entries).
 */
class DocxWriter
{
    public const PAGE_W = 16838, PAGE_H = 11906, MARGIN = 567;

    /** $rows: list of cell lists; $fills: row index => hex fill (no #); $numeric: column indexes shown right-aligned. */
    public static function table(string $title, string $sub, array $summary, array $headers, array $rows, array $fills = [], array $numeric = [], string $empty = 'No rows.'): string
    {
        $x = fn ($v) => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $run = fn ($t, bool $b = false, int $sz = 14, ?string $color = null) => '<w:r><w:rPr>' . ($b ? '<w:b/>' : '') . ($color ? "<w:color w:val=\"$color\"/>" : '') . "<w:sz w:val=\"$sz\"/></w:rPr>"
            . implode('<w:br/>', array_map(fn ($l) => '<w:t xml:space="preserve">' . $x($l) . '</w:t>', explode("\n", (string) $t))) . '</w:r>';
        $p = fn ($t, bool $b = false, int $sz = 14, bool $right = false, ?string $color = null) => '<w:p><w:pPr>' . ($right ? '<w:jc w:val="right"/>' : '') . '<w:spacing w:after="40"/></w:pPr>' . $run($t, $b, $sz, $color) . '</w:p>';
        $n = max(1, count($headers)); $w = intdiv(self::PAGE_W - 2 * self::MARGIN, $n);
        $cell = fn ($t, bool $b = false, bool $right = false, ?string $fill = null) => "<w:tc><w:tcPr><w:tcW w:w=\"$w\" w:type=\"dxa\"/>" . ($fill ? "<w:shd w:val=\"clear\" w:color=\"auto\" w:fill=\"$fill\"/>" : '') . '</w:tcPr>' . $p($t, $b, 12, $right) . '</w:tc>';
        $body = $p($title, true, 32) . $p($sub, false, 16, false, '666666');
        if ($summary) $body .= $p(implode(' | ', array_map(fn ($s) => $s[0] . ': ' . $s[1], $summary)), true, 14);
        $body .= '<w:tbl><w:tblPr><w:tblW w:w="' . ($w * $n) . '" w:type="dxa"/><w:tblLayout w:type="fixed"/><w:tblBorders>' . implode('', array_map(fn ($b) => "<w:$b w:val=\"single\" w:sz=\"4\" w:space=\"0\" w:color=\"B8C4CF\"/>", ['top', 'left', 'bottom', 'right', 'insideH', 'insideV'])) . '</w:tblBorders></w:tblPr>';
        $body .= '<w:tblGrid>' . str_repeat("<w:gridCol w:w=\"$w\"/>", $n) . '</w:tblGrid>';
        $body .= '<w:tr><w:trPr><w:tblHeader/><w:cantSplit/></w:trPr>' . implode('', array_map(fn ($h, $i) => $cell($h, true, in_array($i, $numeric, true), 'D9E8F5'), $headers, array_keys($headers))) . '</w:tr>';
        foreach (array_values($rows) as $ri => $r) $body .= '<w:tr><w:trPr><w:cantSplit/></w:trPr>' . implode('', array_map(fn ($v, $i) => $cell($v, false, in_array($i, $numeric, true), $fills[$ri] ?? null), array_values($r), array_keys(array_values($r)))) . '</w:tr>';
        if (! $rows) $body .= '<w:tr>' . "<w:tc><w:tcPr><w:tcW w:w=\"" . ($w * $n) . "\" w:type=\"dxa\"/><w:gridSpan w:val=\"$n\"/></w:tcPr>" . $p($empty, false, 12) . '</w:tc></w:tr>';
        $body .= '</w:tbl>';
        $m = self::MARGIN;
        $doc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><w:body>' . $body
            . '<w:sectPr><w:footerReference w:type="default" r:id="rId1"/><w:pgSz w:w="' . self::PAGE_W . '" w:h="' . self::PAGE_H . "\" w:orient=\"landscape\"/><w:pgMar w:top=\"$m\" w:right=\"$m\" w:bottom=\"$m\" w:left=\"$m\" w:header=\"284\" w:footer=\"284\" w:gutter=\"0\"/></w:sectPr></w:body></w:document>";
        $footer = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:ftr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">' . $p("$title - $sub", false, 10, false, '666666') . '</w:ftr>';
        return self::zip([
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
            'word/_rels/document.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/></Relationships>',
            'word/document.xml' => $doc, 'word/footer1.xml' => $footer,
        ]);
    }

    /** A ZIP of stored (not compressed) entries, written by hand – no php-zip needed. */
    public static function zip(array $files): string
    {
        $out = ''; $dir = ''; $n = 0; [$t, $d] = self::dosTime();
        foreach ($files as $name => $data) {
            $crc = crc32($data); $len = strlen($data); $off = strlen($out); $n++;
            $out .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, $t, $d, $crc, $len, $len, strlen($name), 0) . $name . $data;
            $dir .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 0, $t, $d, $crc, $len, $len, strlen($name), 0, 0, 0, 0, 0, $off) . $name;
        }
        return $out . $dir . pack('VvvvvVVv', 0x06054b50, 0, 0, $n, $n, strlen($dir), strlen($out), 0);
    }

    private static function dosTime(): array
    {
        $g = getdate();
        return [($g['hours'] << 11) | ($g['minutes'] << 5) | intdiv($g['seconds'], 2), (($g['year'] - 1980) << 9) | ($g['mon'] << 5) | $g['mday']];
    }
}
