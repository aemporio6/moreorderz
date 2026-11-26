<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Owner 26-11 22:28 – ONE importer for everything (MY TOOL's Smart Import, docbrain and "Where does this go?" together, and further):
 * whatever comes in – files of any kind, many at once, a whole Drive folder, a Drive / web link, a pasted table, a pasted screenshot – is kept
 * (nothing is lost), read (tables, Excel, Word, PDF text; pictures and scanned PDFs are read in the browser and the text comes with them),
 * understood (what it is, which supplier, which PO, which products, amount / currency / date / invoice no.) and offered to its place:
 *   open  – a table goes to the screen whose importer takes it (that screen keeps its own checks, mapping and undo; the file is handed over)
 *   attach – a document goes onto its record (PO, supplier, product, payment) as a Document, and shows there in every list
 *   form  – a new thing it names (an unknown supplier, a payment on a receipt) opens its form already filled in; saved → the file goes onto it
 *   keep  – kept in Documents of the company; skip – set aside (still listed under "Set aside", never deleted).
 * Every proposal says why; the best one is chosen by itself; nothing is written until the person presses.
 */
class Intake
{
    public const MAX_MB = 25;
    public const TABLE = ['csv', 'tsv', 'txt', 'xlsx', 'xls'];
    public const PICTURE = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'heic'];

    /** The real kind of a file from its first bytes, then its name (MY TOOL filetype: names without an ending are common). */
    public static function sniff(string $head, string $name): string
    {
        $e = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (str_starts_with($head, '%PDF')) return 'pdf';
        if (str_starts_with($head, "\x89PNG")) return 'png';
        if (str_starts_with($head, "\xFF\xD8\xFF")) return 'jpg';
        if (str_starts_with($head, 'GIF8')) return 'gif';
        if (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP') return 'webp';
        if (str_starts_with($head, "\xD0\xCF\x11\xE0")) return in_array($e, ['doc', 'xls'], true) ? $e : 'xls';
        if (str_starts_with($head, "PK\x03\x04")) return str_contains($head, 'word/') ? 'docx' : (str_contains($head, 'xl/') ? 'xlsx' : (in_array($e, ['docx', 'xlsx', 'zip'], true) ? $e : 'zip'));
        if ($e !== '') return $e;
        return preg_match('/[\t,;].*\n/', substr($head, 0, 2000)) ? 'csv' : 'txt';
    }

    /** Take one thing in: keep the bytes, read what can be read here, understand it. $text = what the browser read (PDF / picture). */
    public static function take(int $ent, object $u, string $bytes, string $name, string $source = 'UPLOAD', ?string $url = null, ?string $text = null, ?string $readBy = null, ?array $ctx = null): array
    {
        if ($bytes === '') throw new \InvalidArgumentException("$name is empty.");
        if (strlen($bytes) > self::MAX_MB * 1048576) throw new \InvalidArgumentException("$name is larger than " . self::MAX_MB . ' MB – keep it in Drive and paste its link.');
        $ext = self::sniff(substr($bytes, 0, 4096), $name); $sha = hash('sha256', $bytes);
        $name = mb_substr(trim(preg_replace('/[\x00-\x1F\/\\\\]+/u', ' ', $name)) ?: 'pasted', 0, 180); if (! pathinfo($name, PATHINFO_EXTENSION)) $name .= '.' . $ext;
        $before = DB::table('mo_intake')->where('fk_id_ent', $ent)->where('sha256', $sha)->orderByDesc('id')->first();
        $path = 'intake/' . $ent . '/' . date('Ym') . '/' . bin2hex(random_bytes(8)) . '.' . $ext;
        Storage::disk('local')->put($path, $bytes);
        $id = (int) DB::table('mo_intake')->insertGetId(['fk_id_ent' => $ent, 'user_id' => $u->id, 'name' => $name, 'ext' => $ext, 'mime' => mb_substr((string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes), 0, 100),
            'size_bytes' => strlen($bytes), 'sha256' => $sha, 'path' => $path, 'source' => $source, 'url' => $url ? mb_substr($url, 0, 500) : null, 'status' => 'NEW', 'created_at' => now(), 'updated_at' => now()]
            + (($c = self::ctx($ctx)) ? ['ctx_entity' => $c[0], 'ctx_id' => $c[1]] : []));
        $t = $text !== null && trim($text) !== '' ? $text : self::read(Storage::disk('local')->path($path), $ext);
        DB::table('mo_intake')->where('id', $id)->update(['text' => mb_substr((string) $t, 0, 200000), 'read_by' => $text !== null && trim($text) !== '' ? ($readBy ?: 'browser') : ($t !== '' ? 'server' : null)]);
        $a = self::analyse($id);
        if ($before) $a['again'] = 'The same file came in before (' . substr((string) $before->created_at, 0, 16) . ', ' . strtolower($before->status) . ').';
        return $a;
    }

    /**
     * Next #3 "File a document from anywhere": the record the person was on when he filed it. [form key, id] or null.
     * The browser sends a form key (record page) or a table (PO, cycle, product pages); it must exist and be a real record.
     */
    public static function ctx(?array $c): ?array
    {
        if (! $c || empty($c['entity']) || (int) ($c['id'] ?? 0) <= 0) return null;
        $e = (string) $c['entity']; if (! Registry::exists($e)) $e = (string) (RecordService::formOfTable($e) ?: '');
        if ($e === '' || ! Registry::exists($e)) return null; $t = Registry::table($e);
        return $t && DB::table($t)->where('id', (int) $c['id'])->exists() ? [mb_substr($e, 0, 60), (int) $c['id']] : null;
    }

    /** "PO P-0012" / "supplier Shenzhen Toys" – what the record is called, for the proposal. */
    public static function ctxLabel(string $e, int $id): string
    {
        $t = (string) Registry::table($e); $col = $t ? Registry::labelColumn($t) : null;
        $v = $t && $col ? trim((string) DB::table($t)->where('id', $id)->value($col)) : '';
        $what = ['t_src_po' => 'PO', 't_src_suppliers' => 'supplier', 't_product_vendors' => 'product', 't_src_cycles' => 'cycle', 't_sales' => 'order', 't_invoices' => 'invoice', 't_branches' => 'shop'][$t] ?? 'this record';
        return $what . ' ' . ($v !== '' && $col !== 'id' ? mb_substr($v, 0, 60) : '#' . $id);
    }

    /** Text of what the server can read itself: tables, Excel, Word, PDF (when the PDF tool is there). */
    public static function read(string $path, string $ext): string
    {
        try {
            if (in_array($ext, ['csv', 'tsv', 'txt'], true)) return mb_convert_encoding((string) file_get_contents($path, false, null, 0, 2000000), 'UTF-8', 'UTF-8, UTF-16LE, Windows-1252, GB18030');
            if (in_array($ext, ['xlsx', 'xls'], true)) {
                $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($path); $out = [];
                foreach ($book->getAllSheets() as $sh) { $out[] = '## ' . $sh->getTitle(); foreach ($sh->toArray(null, true, true, false) as $i => $r) { if ($i >= 400) break; $out[] = implode("\t", array_map(fn ($v) => (string) $v, $r)); } }
                return implode("\n", $out);
            }
            if ($ext === 'docx') { $z = new \ZipArchive(); if ($z->open($path) !== true) return ''; $x = (string) $z->getFromName('word/document.xml'); $z->close();
                return trim(html_entity_decode(strip_tags(str_replace(['</w:p>', '<w:tab/>', '<w:br/>'], ["\n", "\t", "\n"], $x)), ENT_QUOTES | ENT_XML1, 'UTF-8')); }
            if ($ext === 'pdf') foreach (['/usr/bin/pdftotext', '/usr/local/bin/pdftotext'] as $tool) if (is_executable($tool)) {
                $p = new \Symfony\Component\Process\Process([$tool, '-layout', '-l', '6', '-enc', 'UTF-8', $path, '-']); $p->setTimeout(30); $p->run(); return $p->isSuccessful() ? $p->getOutput() : ''; }
        } catch (\Throwable $e) { report($e); }
        return '';
    }

    /** What it is, what it belongs to and where it can go – stored with the row and returned. */
    public static function analyse(int $id): array
    {
        $r = DB::table('mo_intake')->where('id', $id)->first() ?? throw new \InvalidArgumentException('Not found.');
        $ent = (int) $r->fk_id_ent; $text = (string) $r->text; $ext = (string) $r->ext; $path = Storage::disk('local')->path((string) $r->path);
        $props = []; $facts = ['read' => $text !== '' ? (mb_strlen($text) . ' characters read' . ($r->read_by ? " ({$r->read_by})" : '')) : 'nothing could be read from it'];
        $kind = null;
        // 1. a table → the screen whose importer takes it
        if (in_array($ext, self::TABLE, true)) {
            try { $rec = Importer::recommend($path, (string) $r->name); $kind = $rec['best']['title'];
                foreach (array_merge([$rec['best']], $rec['others']) as $i => $o) $props[] = ['action' => 'open', 'screen' => $o['screen'], 'title' => 'Load it in ' . $o['title'], 'why' => $o['why'], 'score' => (int) $o['score']];
            } catch (\Throwable $e) { $facts['table'] = $e->getMessage(); }
        }
        // 2. carrier labels
        if ($ext === 'pdf' && $text !== '') foreach (CarrierLabels::KNOWN_TRACKING as $re) if (preg_match_all($re, $text, $m) && count($m[0]) >= 1) {
            $kind = 'Carrier labels'; $props[] = ['action' => 'open', 'screen' => '#/labels', 'title' => 'Load the labels (' . count(array_unique($m[0])) . ' tracking numbers found)', 'why' => 'tracking numbers of a carrier in the text', 'score' => 96]; break; }
        // 3. a document: kind, supplier, PO, products, amounts
        if (! $kind || ! in_array($ext, self::TABLE, true)) {
            $dk = SupplierUploads::kind((string) $r->name, $text, $ext); $kind ??= $dk;
            $hay = mb_strtoupper($text . ' ' . $r->name);
            $sup = null; if ($text !== '') foreach (DB::table('t_src_suppliers')->where(fn ($q) => $q->where('fk_id_ent', $ent)->orWhereNull('fk_id_ent')->orWhere('fk_id_ent', 0))->where('f_active', 1)->get(['id', 'supplier_name']) as $s)
                if (mb_strlen(trim((string) $s->supplier_name)) >= 4 && str_contains($hay, mb_strtoupper(trim((string) $s->supplier_name))) && (! $sup || mb_strlen($s->supplier_name) > mb_strlen($sup->supplier_name))) $sup = $s;
            $pos = [];
            foreach (DB::table('t_src_po')->where('fk_id_ent', $ent)->where('f_active', 1)->orderByDesc('id')->limit(3000)->get(['id', 'po_code', 'po_number', 'fk_id_supplier', 'po_total', 'fk_id_cycle']) as $p)
                foreach ([$p->po_number, $p->po_code] as $no) if ($no && mb_strlen((string) $no) >= 4 && preg_match('~(?<![A-Z0-9])' . preg_quote(mb_strtoupper((string) $no), '~') . '(?![A-Z0-9])~u', $hay)) { $pos[$p->id] = $p; break; }
            if (! $pos && $sup && ($g = SupplierUploads::guessPo($ent, (int) $sup->id, $text))) { $p = DB::table('t_src_po')->where('id', $g[0])->first(); if ($p) { $pos[$p->id] = $p; $facts['po_guess'] = $g[1]; } }
            $skus = []; if ($text !== '') foreach (DB::table('t_product_vendors')->where('fk_id_ent', $ent)->where('f_active', 1)->whereRaw('CHAR_LENGTH(SKU_VENDOR) >= 4')->limit(8000)->pluck('SKU_VENDOR', 'id') as $pid => $sku)
                if (preg_match('~(?<![A-Z0-9-])' . preg_quote(mb_strtoupper((string) $sku), '~') . '(?![A-Z0-9-])~u', $hay)) { $skus[$pid] = $sku; if (count($skus) >= 30) break; }
            $money = self::money($text);
            $facts += array_filter(['kind' => $dk, 'supplier' => $sup->supplier_name ?? null, 'pos' => array_values(array_map(fn ($p) => $p->po_number ?: $p->po_code, $pos)) ?: null,
                'products' => array_values($skus) ?: null, 'amount' => $money['amount'], 'currency' => $money['cur'] ?: null, 'date' => $money['date'] ?: null, 'refs' => $money['refs'] ?: null]);
            $poForm = RecordService::formOfTable('t_src_po') ?: 't_src_po'; $supForm = RecordService::formOfTable('t_src_suppliers') ?: 't_src_suppliers';
            foreach (array_slice($pos, 0, 3, true) as $p) $props[] = ['action' => 'attach', 'entity' => $poForm, 'entity_id' => (int) $p->id, 'category' => $dk, 'title' => 'Put it on ' . ($p->po_number ?: $p->po_code) . ' as ' . $dk,
                'why' => isset($facts['po_guess']) ? $facts['po_guess'] : 'the PO number is written in it', 'score' => isset($facts['po_guess']) ? 80 : 92];
            if ($sup) $props[] = ['action' => 'attach', 'entity' => $supForm, 'entity_id' => (int) $sup->id, 'category' => $dk, 'title' => "Put it on the supplier {$sup->supplier_name}", 'why' => 'the supplier\'s name is in it', 'score' => $pos ? 60 : 85];
            if (count($skus) === 1 && ! $pos) { $pid = array_key_first($skus); $props[] = ['action' => 'attach', 'entity' => RecordService::formOfTable('t_product_vendors') ?: 't_product_vendor', 'entity_id' => (int) $pid, 'category' => $dk, 'title' => 'Put it on the product ' . $skus[$pid], 'why' => 'its SKU is in it', 'score' => 70]; }
            $paidWords = preg_match('/\b(paid|payment|transfer(red)?|remittance|receipt|debited|successful)\b|付款|转账|汇款|成功/iu', $text);
            if ($money['amount'] && $paidWords) $props[] = ['action' => 'form', 'form' => 'src_payment', 'title' => 'Record a supplier payment of ' . number_format($money['amount'], 2) . ' ' . ($money['cur'] ?: ''),
                'preset' => array_filter(['payment_amount' => $money['amount'], 'payment_date' => $money['date'] ?: null, 'fk_id_supplier' => $sup ? (int) $sup->id : ($pos ? (int) reset($pos)->fk_id_supplier : null), 'fk_id_cycle' => $pos ? (int) reset($pos)->fk_id_cycle : null,
                    'fk_id_currency' => $money['cur'] ? DB::table('cat_currency')->where('currency_code', $money['cur'] === 'CNY' ? 'CNY' : $money['cur'])->value('id') : null]), 'entity_after' => RecordService::formOfTable('t_src_payments') ?: 'src_payment', 'why' => 'it reads like a payment receipt (amount' . ($money['date'] ? ', date' : '') . ')', 'score' => $pos ? 88 : 75];
            if (! $sup && in_array($dk, ['Proforma invoice (PI)', 'Commercial invoice', 'Packing list', 'Contract'], true) && ($nm = self::sellerName($text)))
                $props[] = ['action' => 'form', 'form' => $supForm, 'title' => "New supplier “{$nm}”", 'preset' => ['supplier_name' => $nm, '__label' => $nm], 'why' => 'this seller is not among your suppliers yet', 'score' => 65];
        }
        if (! empty($r->ctx_entity) && $r->ctx_id) {      // filed from a record: that record comes first, with its kind
            $dk = $dk ?? SupplierUploads::kind((string) $r->name, $text, $ext); $lbl = self::ctxLabel((string) $r->ctx_entity, (int) $r->ctx_id);
            $props = array_values(array_filter($props, fn ($p) => ! ($p['action'] === 'attach' && $p['entity'] === $r->ctx_entity && (int) $p['entity_id'] === (int) $r->ctx_id)));
            $props[] = ['action' => 'attach', 'entity' => (string) $r->ctx_entity, 'entity_id' => (int) $r->ctx_id, 'category' => $dk, 'title' => 'Put it on ' . $lbl . ' as ' . $dk, 'why' => 'you filed it from this record; its kind is guessed from ' . ($text !== '' ? 'what it says' : 'its name'), 'score' => 99];
            $facts['from'] = $lbl;
        }
        // owner 27-11 02:31 a supplier's packing list: its carton figures go into the products' packaging – "shall I put it in?"
        if ($text !== '' && (($dk ?? '') === 'Packing list' || preg_match('/pcs ?\/ ?ctn|carton size|meas(urement)?|g\.?\s?w\.?/iu', $text)) && ($pk = Packaging::proposal($ent, $text))) {
            $props[] = ['action' => 'packaging', 'rows' => $pk, 'title' => 'Put the carton figures of ' . count($pk) . ' product(s) in: ' . implode(', ', array_slice(array_column($pk, 'sku'), 0, 4)) . (count($pk) > 4 ? ' …' : ''),
                'why' => implode('; ', array_slice(array_map(fn ($r) => $r['sku'] . ': ' . implode(', ', array_map(fn ($k, $c) => str_replace('_', ' ', $k) . ' ' . ($c['from'] ?: '—') . ' → ' . $c['to'], array_keys($r['changes']), $r['changes'])), $pk), 0, 3)), 'score' => 95];
            $facts['packing'] = count($pk) . ' product(s) with carton figures';
        }
        // owner 27-11 02:31 the product vault: a product file (picture, artwork, label, A+, dieline …) goes to its place in its product's vault
        $vk = ProductVault::guess((string) $r->name, $ext, $text); $ctxProd = ! empty($r->ctx_entity) && Registry::table((string) $r->ctx_entity) === 't_product_vendors' ? (int) $r->ctx_id : 0;
        $vp = $ctxProd ?: (in_array($ext, array_merge(ProductVault::IMAGE, ProductVault::VECTOR, ['pdf', 'mp4', 'mov']), true) || $vk !== 'OTHER' ? ProductVault::productOf($ent, (string) $r->name, $vk === 'OTHER' ? '' : $text) : null);
        if ($vp && ($ctxProd || $vk !== 'OTHER' || in_array($ext, ProductVault::IMAGE, true))) {
            $sku = (string) DB::table('t_product_vendors')->where('id', $vp)->value('SKU_VENDOR'); $mp = $vk === 'LISTING' ? ProductVault::marketplaceOf((string) $r->name, $ent) : null;
            $props[] = ['action' => 'vault', 'entity' => RecordService::formOfTable('t_product_vendors') ?: 't_product_vendor', 'entity_id' => $vp, 'kind' => $vk, 'marketplace' => $mp, 'category' => ProductVault::KINDS[$vk],
                'title' => "Put it in the vault of {$sku} › " . ProductVault::KINDS[$vk], 'why' => ($ctxProd ? 'you filed it from this product' : 'its SKU is in the ' . (str_contains(mb_strtoupper((string) $r->name), mb_strtoupper($sku)) ? 'file name' : 'text')) . '; the place is read from its name and type', 'score' => $ctxProd ? 100 : 93];
            $facts['vault'] = "$sku › " . ProductVault::KINDS[$vk];
        }
        $props[] = ['action' => 'keep', 'title' => 'Keep it in Documents', 'why' => 'kept with the company, found by search', 'score' => 30];
        $props[] = ['action' => 'skip', 'title' => 'Set aside', 'why' => 'nothing is done; it stays listed under Set aside', 'score' => 1];
        usort($props, fn ($a, $b) => $b['score'] <=> $a['score']);
        DB::table('mo_intake')->where('id', $id)->update(['kind' => mb_substr((string) $kind, 0, 60) ?: null, 'facts_json' => json_encode($facts, JSON_UNESCAPED_UNICODE), 'proposals_json' => json_encode($props, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
        return self::row(DB::table('mo_intake')->where('id', $id)->first());
    }

    /** Amount, currency, date and reference numbers a receipt / invoice shows (MY TOOL ocrFacts, made stricter). */
    public static function money(string $text): array
    {
        $out = ['amount' => null, 'cur' => '', 'date' => '', 'refs' => []]; if (trim($text) === '') return $out;
        $flat = preg_replace('/\s+/u', ' ', $text);
        $curOf = fn (string $s) => preg_match('/¥|￥|RMB|CNY|元|人民币/u', $s) ? 'CNY' : (preg_match('/£|GBP/u', $s) ? 'GBP' : (preg_match('/€|EUR/u', $s) ? 'EUR' : (preg_match('/\$|USD/u', $s) ? 'USD' : '')));
        $num = function (string $v): float { $v = str_replace(' ', '', $v); if (preg_match('/^\d{1,3}(\.\d{3})+,\d{2}$/', $v)) $v = str_replace(['.', ','], ['', '.'], $v); else $v = str_replace(',', '', $v); return (float) $v; };
        $best = null;
        if (preg_match_all('/(total|amount|grand total|sum|paid|payment|合计|总计|金额|实付)[^0-9£$€¥￥]{0,25}([£$€¥￥]|RMB|CNY|USD|GBP|EUR)?\s?(\d{1,3}(?:[,.\s]\d{3})*(?:[.,]\d{2})|\d+(?:\.\d{2}))/iu', $flat, $m, PREG_SET_ORDER))
            foreach ($m as $x) { $v = $num($x[3]); if ($v > 0 && (! $best || preg_match('/total|合计|总计/iu', $x[1]))) { $best = $v; $out['cur'] = $curOf($x[2] . ' ' . $x[0]) ?: $out['cur']; } }
        if (! $best && preg_match_all('/([£$€¥￥]|RMB|CNY|USD|GBP|EUR)\s?(\d{1,3}(?:,\d{3})*(?:\.\d{2})|\d+\.\d{2})/u', $flat, $m, PREG_SET_ORDER)) { $vals = []; foreach ($m as $x) $vals[] = [$num($x[2]), $curOf($x[1])]; usort($vals, fn ($a, $b) => $b[0] <=> $a[0]); $best = $vals[0][0]; $out['cur'] = $vals[0][1]; }
        $out['amount'] = $best ? round($best, 2) : null; if (! $out['cur']) $out['cur'] = $curOf($flat);
        if (preg_match('/\b(20\d{2})[-\/.年](\d{1,2})[-\/.月](\d{1,2})/u', $flat, $d)) $out['date'] = sprintf('%04d-%02d-%02d', $d[1], $d[2], $d[3]);
        elseif (preg_match('/\b(\d{1,2})[-\/. ](\d{1,2}|[A-Za-z]{3,9})[-\/. ](20\d{2})\b/', $flat, $d)) { $mo = is_numeric($d[2]) ? (int) $d[2] : (int) date('n', strtotime('1 ' . $d[2] . ' 2000')); if ($mo >= 1 && $mo <= 12 && (int) $d[1] <= 31) $out['date'] = sprintf('%04d-%02d-%02d', $d[3], $mo, $d[1]); }
        if (preg_match_all('/\b(?:invoice|inv|pi|order|ref(?:erence)?|transaction|txn|no)\.?\s*(?:no\.?|number|#|id)?\s*[:#]?\s*([A-Z0-9][A-Z0-9\-\/]{4,24})/iu', $flat, $m)) $out['refs'] = array_values(array_unique(array_filter($m[1], fn ($x) => preg_match('/\d/', $x))));
        $out['refs'] = array_slice($out['refs'], 0, 5);
        return $out;
    }

    /** The seller named on a PI / invoice: "Seller: …", "Exporter …", or a line with Co., Ltd / Limited / 有限公司. */
    public static function sellerName(string $text): ?string
    {
        if (preg_match('/(?:seller|supplier|exporter|shipper|vendor|beneficiary|卖方|供应商)\s*[:：]\s*([^\n]{4,80})/iu', $text, $m)) return trim(preg_replace('/\s{2,}.*$/u', '', $m[1]));
        foreach (preg_split('/\R/u', $text) as $l) { $l = trim($l); if (preg_match('/^(.{3,70}(?:Co\.,?\s*Ltd\.?|Limited|LTD\.?|有限公司))/iu', $l, $m)) return trim($m[1]); }
        foreach (preg_split('/\R/u', $text) as $l) { $l = trim($l); if (mb_strlen($l) <= 70 && preg_match('/\b(Trading|Factory|Industrial|Manufactur\w*)\b/iu', $l) && ! preg_match('/\d{3,}/', $l)) return $l; }
        return null;
    }

    public static function row(object $r): array
    {
        return ['id' => (int) $r->id, 'name' => $r->name, 'ext' => $r->ext, 'size' => (int) $r->size_bytes, 'source' => $r->source, 'kind' => $r->kind, 'status' => $r->status, 'at' => $r->created_at,
            'facts' => json_decode((string) $r->facts_json, true) ?: [], 'proposals' => json_decode((string) $r->proposals_json, true) ?: [], 'done' => json_decode((string) $r->done_json, true) ?: null,
            'read_by' => $r->read_by, 'ctx' => ! empty($r->ctx_entity) ? ['entity' => $r->ctx_entity, 'id' => (int) $r->ctx_id] : null, 'preview' => mb_substr((string) $r->text, 0, 600), 'document' => $r->fk_id_document ? (int) $r->fk_id_document : null];
    }

    public static function list(int $ent, string $status = 'NEW', ?array $ctx = null): array
    {
        $q = DB::table('mo_intake')->where('fk_id_ent', $ent)->orderByDesc('id')->limit(200);
        if ($c = self::ctx($ctx)) $q->where('ctx_entity', $c[0])->where('ctx_id', $c[1]);
        if ($status !== 'ALL') $q->where('status', $status);
        return ['items' => $q->get()->map(fn ($r) => self::row($r))->all(), 'counts' => DB::table('mo_intake')->where('fk_id_ent', $ent)->groupBy('status')->selectRaw('status, COUNT(*) n')->pluck('n', 'status')];
    }

    /** Do what was chosen. attach / keep make a Document (the file is served by the tool); open / form only mark it, the screen does the work. */
    public static function decide(int $ent, int $id, array $in, object $u): array
    {
        $r = DB::table('mo_intake')->where('id', $id)->where('fk_id_ent', $ent)->first() ?? throw new \InvalidArgumentException('Not found.');
        $act = (string) ($in['action'] ?? '');
        if ($act === 'skip') { DB::table('mo_intake')->where('id', $id)->update(['status' => 'SKIPPED', 'done_json' => json_encode(['action' => 'skip', 'by' => $u->id, 'at' => now()->toDateTimeString()]), 'updated_at' => now()]); return ['message' => 'Set aside.']; }
        if ($act === 'open') { DB::table('mo_intake')->where('id', $id)->update(['status' => 'DONE', 'done_json' => json_encode(['action' => 'open', 'screen' => (string) ($in['screen'] ?? ''), 'by' => $u->id, 'at' => now()->toDateTimeString()]), 'updated_at' => now()]); return ['message' => 'Handed to the screen.']; }
        $packed = null; if ($act === 'packaging') {      // the packing list's carton figures into the products, then the file is kept
            $pp = collect(json_decode((string) $r->proposals_json, true) ?: [])->firstWhere('action', 'packaging'); if (! $pp) throw new \InvalidArgumentException('Nothing to put in.');
            $packed = Packaging::apply($ent, $pp['rows'], $u); $act = 'keep'; }
        $vault = $act === 'vault'; if ($vault) $act = 'attach';      // the vault = attached to the product + its place in the vault
        if (! in_array($act, ['attach', 'keep'], true)) throw new \InvalidArgumentException('Choose what to do with it.');
        $entity = $act === 'keep' ? 'company' : (string) ($in['entity'] ?? ''); $eid = $act === 'keep' ? $ent : (int) ($in['entity_id'] ?? 0);
        if ($act === 'attach') { abort_unless(Registry::exists($entity) && $eid > 0, 422, 'Choose the record.'); Perm::need($u, "form:$entity:EDIT");
            $t = Registry::table($entity); abort_unless($t && DB::table($t)->where('id', $eid)->exists(), 422, 'That record does not exist.'); }
        $doc = $r->fk_id_document;
        if (! $doc) {
            $facts = json_decode((string) $r->facts_json, true) ?: []; $cat = (string) ($in['category'] ?? '') ?: (string) ($facts['kind'] ?? $r->kind ?? 'Other');
            $doc = (int) DB::table('sys_documents')->insertGetId(RecordService::fillNotNull('sys_documents', ['fk_id_ent' => $ent, 'doc_no' => 'TMP-' . bin2hex(random_bytes(6)), 'fk_id_doc_type' => self::docType($ent, $cat),
                'title' => mb_substr($cat . ' – ' . $r->name, 0, 200), 'drive_url' => mb_substr(url('/api/intake/' . $id . '/file'), 0, 500), 'stored_name' => $r->path, 'orig_name' => $r->name, 'mime' => $r->mime, 'size_bytes' => (int) $r->size_bytes,
                'sha256' => $r->sha256, 'ref_no' => isset($facts['refs'][0]) ? mb_substr((string) $facts['refs'][0], 0, 80) : null, 'd_doc' => $facts['date'] ?? null, 'amount' => $facts['amount'] ?? null,
                'version' => 1, 'status' => 'ACTIVE', 'uploaded_by' => $u->id, 'created_at' => now(), 'notes' => 'From Import anything (' . strtolower((string) $r->source) . ')']));
            DB::table('sys_documents')->where('id', $doc)->update(['doc_no' => mb_substr(Numbering::next('DOC', $ent), 0, 30)]);
        }
        if (! DB::table('sys_document_links')->where(['fk_id_document' => $doc, 'entity' => $entity, 'entity_id' => $eid])->exists()) DB::table('sys_document_links')->insert(['fk_id_document' => $doc, 'entity' => mb_substr($entity, 0, 60), 'entity_id' => $eid]);
        DB::table('mo_intake')->where('id', $id)->update(['status' => 'DONE', 'fk_id_document' => $doc, 'done_json' => json_encode(['action' => $act, 'entity' => $entity, 'entity_id' => $eid, 'by' => $u->id, 'at' => now()->toDateTimeString()]), 'updated_at' => now()]);
        Audit::log('sys_documents', $doc, 'UPDATE', ['linked' => [null, "$entity #$eid"], 'from' => [null, 'intake #' . $id]], 'Import anything', $ent);
        if ($vault) { abort_unless(Registry::table($entity) === 't_product_vendors', 422, 'The vault is a product\'s.'); ProductVault::put($ent, $eid, (int) $doc, (string) ($in['kind'] ?? ProductVault::guess((string) $r->name, (string) $r->ext)), ! empty($in['marketplace']) ? (int) $in['marketplace'] : null, $u);
            DB::table('mo_intake')->where('id', $id)->update(['done_json' => json_encode(['action' => 'vault', 'entity' => $entity, 'entity_id' => $eid, 'kind' => $in['kind'] ?? null, 'by' => $u->id, 'at' => now()->toDateTimeString()])]); }
        if ($entity === 't_src_po' || $entity === RecordService::formOfTable('t_src_po')) EventBus::fire('DOC_ADDED', 't_src_po', $eid, ['via' => 'import anything', 'document' => $doc], $ent);
        if ($packed) return ['message' => $packed['message'] . ' The list is kept in Documents.', 'document' => $doc, 'updated' => $packed['updated']];
        return ['message' => $vault ? 'Put in the product\'s vault.' : ($act === 'keep' ? 'Kept in Documents.' : 'Put on the record – it shows in its Documents.'), 'document' => $doc];
    }

    private static function docType(int $ent, string $name): int
    {
        $id = DB::table('cat_doc_types')->whereRaw('LOWER(doc_type_name) = ?', [mb_strtolower($name)])->where(fn ($q) => $q->where('fk_id_ent', $ent)->orWhere('fk_id_ent', 0))->value('id');
        return (int) ($id ?: DB::table('cat_doc_types')->insertGetId(RecordService::fillNotNull('cat_doc_types', ['fk_id_ent' => 0, 'doc_type_name' => mb_substr($name, 0, 60), 'f_active' => 1])));
    }

    /**
     * A link: a Google Drive file (shared "anyone with the link") is fetched; a Drive folder is walked with the Google Drive connection
     * (every file comes in, sub-folders too, up to 60 files); any other https link to a file is fetched. The link itself is kept with each file.
     */
    public static function fromLink(int $ent, object $u, string $link): array
    {
        $link = trim($link); if (! preg_match('~^https://~i', $link)) throw new \InvalidArgumentException('Paste a link that starts with https://');
        $host = strtolower((string) parse_url($link, PHP_URL_HOST));
        if (in_array($host, ['localhost', '127.0.0.1'], true) || preg_match('/^(10|127|169\.254|192\.168|172\.(1[6-9]|2\d|3[01]))\./', (string) gethostbyname($host))) throw new \InvalidArgumentException('That link points inside the server – not allowed.');
        if (preg_match('~drive\.google\.com/(?:drive/(?:u/\d+/)?folders/|open\?id=)([A-Za-z0-9_-]{15,})~', $link, $m) && str_contains($link, 'folders')) {
            $c = Connect\DriveFolders::connection() ?? throw new \InvalidArgumentException('A Drive folder is read with the Google Drive connection (Data & Reports › API connections). Until then, open the folder and drop its files here.');
            $ad = Connect\Connections::adapter($c); $out = []; $todo = [[$m[1], '']]; $seen = 0;
            while ($todo && $seen < 60) { [$fid, $prefix] = array_shift($todo);
                foreach ($ad->files($fid) as $f) { if ($seen >= 60) break;
                    if (($f['mimeType'] ?? '') === 'application/vnd.google-apps.folder') { $todo[] = [$f['id'], $prefix . $f['name'] . ' / ']; continue; }
                    try { [$p, $nm] = $ad->download($f); $out[] = self::take($ent, $u, (string) file_get_contents($p), $prefix . $nm, 'DRIVE', 'https://drive.google.com/file/d/' . $f['id'] . '/view'); @unlink($p); $seen++; }
                    catch (\Throwable $e) { $out[] = ['error' => ($f['name'] ?? 'file') . ': ' . $e->getMessage()]; } } }
            return $out;
        }
        $get = $link; $name = basename((string) parse_url($link, PHP_URL_PATH)) ?: 'linked file';
        if (preg_match('~drive\.google\.com/(?:file/d/|open\?id=|uc\?(?:.*&)?id=)([A-Za-z0-9_-]{15,})~', $link, $m)) $get = 'https://drive.google.com/uc?export=download&id=' . $m[1];
        elseif (preg_match('~docs\.google\.com/spreadsheets/d/([A-Za-z0-9_-]{15,})~', $link, $m)) { $get = 'https://docs.google.com/spreadsheets/d/' . $m[1] . '/export?format=xlsx'; $name = 'Google sheet.xlsx'; }
        $resp = Http::timeout(60)->withOptions(['allow_redirects' => ['max' => 5]])->get($get);
        if ($resp->failed()) throw new \InvalidArgumentException('The link could not be fetched (' . $resp->status() . '). Is it shared as "anyone with the link"?');
        $body = $resp->body(); $ct = (string) $resp->header('Content-Type');
        if (str_contains($ct, 'text/html') && preg_match('/<title>/i', substr($body, 0, 3000))) throw new \InvalidArgumentException('The link opened a web page, not a file – in Drive choose Share › "Anyone with the link", or download the file and drop it here.');
        if (preg_match('/filename\*?=(?:UTF-8\'\')?"?([^";]+)/i', (string) $resp->header('Content-Disposition'), $fm)) $name = rawurldecode($fm[1]);
        return [self::take($ent, $u, $body, $name, 'LINK', $link)];
    }
}
