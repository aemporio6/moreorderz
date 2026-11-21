<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Supplier purchase order PDF (template po_a4, R-F_SETUP-11): company header, 3 × 3 meta grid (old order), supplier and ship-to blocks, lines
 * (#, Product, SKU, Qty, Unit Price, Discount, Other, Line Total; second line in Chinese when the supplier prints Chinese labels), totals PO TOTAL / Deposit /
 * BALANCE, payment schedule, supplier bank (toggle), notes, signature lines with Name / Date, footer "Generated … · Page x of y · Version n".
 * Versions: the same data prints the same version; changed data makes version n+1 (PO_<po_number>_<supplier short>_v<n>.pdf), kept and linked to the PO.
 * Send to supplier: e-mail (PDF attached) or WhatsApp through the message queue, logged on the PO.
 */
class PoPdf
{
    private static function e($v): string { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); }
    private static function m($v): string { return number_format((float) $v, 2); }
    private static function q($v): string { return rtrim(rtrim(number_format((float) $v, 3, '.', ','), '0'), '.'); }

    public static function data(int $ent, int $id, bool $bank = true): array
    {
        $po = DB::table('t_src_po')->where('id', $id)->where('fk_id_ent', $ent)->first() ?? throw new \InvalidArgumentException('Not found.');
        $sup = $po->fk_id_supplier ? DB::table('t_src_suppliers')->where('id', $po->fk_id_supplier)->first() : null;
        $co = DB::table('cat_company_house')->where('fk_id_ent', $ent)->where('f_active', 1)->orderByDesc('f_tenant_main')->orderBy('id')->first();
        $en = DB::table('t_enterprise')->where('id', $ent)->first();
        $company = ['name' => $co->company_name ?? $en->ent_name ?? '', 'address' => $co->trading_address ?? $co->company_address ?? $en->ent_address ?? null, 'number' => $co->registration_no ?? null, 'vat' => $co->vat_no ?? null,
            'phone' => $co->company_telephone ?? $en->ent_phone ?? null, 'email' => $co->company_email_1 ?? null];
        $cn = (int) ($sup->f_print_cn ?? 0) === 1;
        $lines = DB::table('t_src_po_lines as l')->leftJoin('t_product_vendors as v', 'v.id', '=', 'l.fk_id_product')->leftJoin('t_src_cycle_products as cp', 'cp.id', '=', 'l.fk_id_cycle_product')->where('l.fk_id_po', $id)->where('l.f_active', 1)->orderBy('l.id')
            ->get(['l.sku', 'v.SKU_VENDOR', 'v.product_name_vendor', 'cp.product_name as cp_name', 'v.product_name_cn', 'cp.product_spec', 'l.line_notes', 'l.qty_ordered', 'l.qty_confirmed', 'l.unit_price', 'l.discount', 'l.other_charges', 'l.line_total'])
            ->map(fn ($l) => ['product' => $l->product_name_vendor ?: $l->cp_name ?: $l->line_notes, 'cn' => $cn ? ($l->product_name_cn ?: null) : null, 'sku' => $l->sku ?: $l->SKU_VENDOR, 'qty' => (float) $l->qty_confirmed > 0 ? (float) $l->qty_confirmed : (float) $l->qty_ordered,
                'unit_price' => (float) $l->unit_price, 'discount' => (float) $l->discount, 'other' => (float) $l->other_charges, 'total' => (float) $l->line_total])->all();
        $terms = DB::table('t_src_payment_terms')->where('fk_id_po', $id)->where('f_active', 1)->orderBy('term_no')->get(['term_no', 'milestone', 'pct', 'planned_amount', 'due_date'])
            ->map(fn ($t) => ['no' => (int) $t->term_no, 'milestone' => $t->milestone, 'pct' => (float) $t->pct, 'amount' => (float) $t->planned_amount, 'due' => $t->due_date])->all();
        $bankRow = $bank && $sup ? DB::table('t_account_banks as b')->leftJoin('cat_banks as c', 'c.id', '=', 'b.fk_bank_id')->where('b.fk_id_src_supplier', $sup->id)->where('b.f_active', 1)->orderBy('b.id')->first(['c.bank_name', 'b.beneficiary_name', 'b.account_no', 'b.iban_no', 'b.swift_code']) : null;
        $wh = DB::table('t_src_shipments')->where('fk_id_po', $id)->where('f_active', 1)->whereNotNull('fk_id_warehouse')->value('fk_id_warehouse');
        $shipTo = $wh ? DB::table('cat_inventory_locations')->where('id', $wh)->first(['location_name', 'address_text']) : null;
        $no = $po->po_number ?: $po->po_code ?: ('PO-' . $po->id);
        return ['id' => (int) $po->id, 'no' => $no, 'code' => $po->po_code ?: $no, 'company' => $company,
            'meta' => ['PO Number' => $no, 'PO Date' => $po->po_date, 'Sourcing Cycle' => $po->fk_id_cycle ? DB::table('t_src_cycles')->where('id', $po->fk_id_cycle)->value('cycle_code') : null,
                'Supplier' => $sup->supplier_name ?? null, 'Currency' => Fx::code($po->fk_id_currency, $ent), 'Incoterm' => $po->fk_id_incoterm ? DB::table('cat_incoterm')->where('id', $po->fk_id_incoterm)->value('incoterm_code') : null,
                'Payment Terms' => $po->fk_id_payment_term ? DB::table('cat_payment_terms')->where('id', $po->fk_id_payment_term)->value('term_short') : null, 'Expected Production' => $po->expected_production_date, 'Required Ship Date' => $po->required_ship_date],
            'supplier' => $sup ? ['name' => $sup->supplier_name, 'address' => trim(implode(', ', array_filter([$sup->address, $sup->city]))), 'contact' => $sup->contact_person, 'phone' => $sup->phone_whatsapp, 'wechat' => $sup->wechat, 'email' => $sup->email, 'short' => $sup->supplier_code ?: $sup->supplier_name] : null,
            'ship_to' => $shipTo ? ['name' => $shipTo->location_name, 'address' => $shipTo->address_text] : null, 'lines' => $lines, 'cn' => $cn,
            'totals' => ['po_total' => round((float) $po->po_total, 2), 'deposit' => round((float) $po->deposit_amount, 2), 'balance' => round((float) $po->balance_amount, 2)], 'terms' => $terms,
            'bank' => $bankRow ? ['bank' => $bankRow->bank_name, 'account_name' => $bankRow->beneficiary_name, 'account' => $bankRow->iban_no ?: $bankRow->account_no, 'swift' => $bankRow->swift_code] : null, 'notes' => $po->notes];
    }

    /** The A4 page (PrintService page frame, kind PO). */
    public static function page(int $ent, int $id, ?int $version = null, bool $bank = true, bool $counted = false): string
    {
        $d = self::data($ent, $id, $bank); $c = $d['company']; $dt = fn ($v) => $v ? date('d-m-Y', strtotime((string) $v)) : '—';
        $teal = '#0b7285'; $th = 'background:#e7f5f8;border:0.2mm solid #999;padding:1mm;text-align:left;font-size:8.5pt'; $td = 'border:0.2mm solid #999;padding:1mm;vertical-align:top';
        $o = '<div class="in" style="font-family:Arial,\'DejaVu Sans\',sans-serif"><table style="width:100%"><tr><td style="vertical-align:top"><div class="big" style="font-size:12pt">' . self::e($c['name']) . '</div><div class="mut">'
            . implode('<br>', array_map(fn ($x) => self::e($x), array_filter([$c['address'], $c['number'] ? 'Company no ' . $c['number'] : null, $c['vat'] ? 'VAT ' . $c['vat'] : null, trim(implode(' · ', array_filter([$c['phone'], $c['email']])))]))) . '</div></td>'
            . '<td style="text-align:right;vertical-align:top"><div style="font-size:18pt;color:' . $teal . ';font-weight:bold">PURCHASE ORDER</div><div class="big">' . self::e($d['code']) . '</div></td></tr></table>'
            . '<div style="border-bottom:0.6mm solid ' . $teal . ';margin:2mm 0 3mm"></div><table style="width:100%">';
        $meta = $d['meta']; foreach (['PO Date', 'Expected Production', 'Required Ship Date'] as $k) $meta[$k] = $dt($meta[$k]);
        foreach (array_chunk($meta, 3, true) as $row) { $o .= '<tr>'; foreach ($row as $k => $v) $o .= '<td style="width:33%;padding:0 2mm 2mm 0;vertical-align:top"><div class="mut">' . self::e($k) . '</div><div class="big">' . self::e($v ?? '—') . '</div></td>'; $o .= '</tr>'; }
        $o .= '</table><table style="width:100%;margin-top:2mm"><tr><td style="width:50%;vertical-align:top"><div class="mut">Supplier</div>';
        if ($s = $d['supplier']) $o .= '<div class="big">' . self::e($s['name']) . '</div><div>' . implode('<br>', array_map(fn ($x) => self::e($x), array_filter([$s['address'], $s['contact'], trim(implode(' / ', array_filter([$s['phone'], $s['wechat'] ? 'WeChat ' . $s['wechat'] : null, $s['email']])))]))) . '</div>';
        $o .= '</td><td style="vertical-align:top">' . ($d['ship_to'] ? '<div class="mut">Ship to</div><div class="big">' . self::e($d['ship_to']['name']) . '</div><div>' . self::e($d['ship_to']['address']) . '</div>' : '') . '</td></tr></table>'
            . '<table style="width:100%;border-collapse:collapse;margin-top:3mm"><tr>' . implode('', array_map(fn ($h) => '<th style="' . $th . '">' . $h . '</th>', ['#', 'Product', 'SKU', 'Qty', 'Unit Price', 'Discount', 'Other', 'Line Total'])) . '</tr>';
        foreach ($d['lines'] as $i => $l) $o .= '<tr><td style="' . $td . '">' . ($i + 1) . '</td><td style="' . $td . '">' . self::e(mb_substr((string) $l['product'], 0, 80)) . ($l['cn'] ? '<div class="mut">' . self::e($l['cn']) . '</div>' : '') . '</td><td style="' . $td . '">' . self::e($l['sku'])
            . '</td><td style="' . $td . ';text-align:right">' . self::q($l['qty']) . '</td><td style="' . $td . ';text-align:right">' . self::m($l['unit_price']) . '</td><td style="' . $td . ';text-align:right">' . self::m($l['discount']) . '</td><td style="' . $td . ';text-align:right">' . self::m($l['other']) . '</td><td style="' . $td . ';text-align:right">' . self::m($l['total']) . '</td></tr>';
        $t = $d['totals']; $tr = fn ($label, $v, $b) => '<tr><td colspan="7" style="' . $td . ';text-align:right">' . ($b ? "<b>$label</b>" : $label) . '</td><td style="' . $td . ';text-align:right">' . ($b ? '<b>' . self::m($v) . '</b>' : self::m($v)) . '</td></tr>';
        $o .= $tr('PO TOTAL', $t['po_total'], true) . $tr('Deposit', $t['deposit'], false) . $tr('BALANCE', $t['balance'], true) . '</table>';
        if ($d['terms']) { $o .= '<div class="big" style="margin-top:4mm">Payment schedule</div><table style="width:100%;border-collapse:collapse;margin-top:1mm"><tr>' . implode('', array_map(fn ($h) => '<th style="' . $th . '">' . $h . '</th>', ['Term', 'Milestone', '%', 'Amount', 'Due date'])) . '</tr>';
            foreach ($d['terms'] as $x) $o .= '<tr><td style="' . $td . '">' . $x['no'] . '</td><td style="' . $td . '">' . self::e($x['milestone']) . '</td><td style="' . $td . ';text-align:right">' . self::q($x['pct']) . '</td><td style="' . $td . ';text-align:right">' . self::m($x['amount']) . '</td><td style="' . $td . '">' . $dt($x['due']) . '</td></tr>';
            $o .= '</table>'; }
        if ($b = $d['bank']) $o .= '<div class="big" style="margin-top:4mm">Supplier bank (beneficiary)</div><div>' . self::e($b['bank']) . ' · ' . self::e($b['account_name']) . ' · ' . self::e($b['account']) . ($b['swift'] ? ' · SWIFT ' . self::e($b['swift']) : '') . '</div>';
        if ($d['notes']) $o .= '<div style="margin-top:4mm"><span class="mut">Notes:</span> ' . nl2br(self::e($d['notes'])) . '</div>';
        $o .= '<table style="width:100%;margin-top:14mm"><tr><td style="width:50%"><div>Buyer Signature</div><div style="margin-top:8mm">Name: ____________________</div><div style="margin-top:3mm">Date: ____________________</div></td>'
            . '<td><div>Supplier Signature</div><div style="margin-top:8mm">Name: ____________________</div><div style="margin-top:3mm">Date: ____________________</div></td></tr></table>'
            . ($counted ? '<div class="mut pofoot" style="font-size:8pt">Generated ' . date('d-m-Y H:i') . ' · <span class="pgno"></span>' . ($version ? ' · Version ' . $version : '') . '</div>'
                : '<div class="mut" style="margin-top:6mm;font-size:8pt">Generated ' . date('d-m-Y H:i') . ($version ? ' · Version ' . $version : '') . '</div>') . '</div>';
        return $o;
    }

    /** Make the PDF: same data → the same version again; changed data → version n+1, kept and linked to the PO (t_src_documents). */
    public static function make(int $ent, int $id, bool $bank = true): array
    {
        $d = self::data($ent, $id, $bank); $hash = sha1(json_encode($d));
        $last = DB::table('src_po_pdf_versions')->where('fk_id_po', $id)->orderByDesc('version')->first();
        $ver = $last && $last->data_hash === $hash ? (int) $last->version : (int) ($last->version ?? 0) + 1;
        $short = preg_replace('/[^A-Za-z0-9]+/', '', (string) ($d['supplier']['short'] ?? 'SUP')) ?: 'SUP';
        $file = 'PO_' . preg_replace('/[^A-Za-z0-9_-]/', '-', $d['no']) . '_' . mb_substr($short, 0, 20) . '_v' . $ver . '.pdf';
        $html = '<html><head><meta charset="utf-8"><style>@page{margin:0} body{margin:0;font-size:9.5pt;color:#000} .in{padding:12mm} .mut{color:#555;font-size:8.5pt} .big{font-weight:bold} .pofoot{position:fixed;bottom:8mm;left:12mm} .pgno:after{content:"Page " counter(page) " of __N__"}</style></head><body>' . self::page($ent, $id, $ver, $bank, true) . '</body></html>';
        $n = max(1, preg_match_all('~/Type\s*/Page[^s]~', PrintService::pdf(str_replace('__N__', '1', $html), 210, 297)));      // first pass counts the pages, the second prints "Page x of y"
        $html = str_replace('__N__', (string) $n, $html); $pdf = PrintService::pdf($html, 210, 297);
        $rel = 'src_docs/po_' . $id . '/' . $file; $abs = GrnPrint::abs($rel); if (! is_dir(dirname($abs))) mkdir(dirname($abs), 0775, true);
        if (! $last || $last->data_hash !== $hash) {
            file_put_contents($abs, $pdf);
            $po = DB::table('t_src_po')->where('id', $id)->first(['fk_id_cycle']);
            $doc = DB::table('t_src_documents')->insertGetId(RecordService::fillNotNull('t_src_documents', ['fk_id_ent' => $ent, 'fk_id_po' => $id, 'fk_id_cycle' => $po->fk_id_cycle ?: null, 'doc_type' => 'PO PDF', 'doc_category' => 'PO', 'doc_name' => $file, 'notes' => $rel, 'doc_status' => 'GENERATED', 'f_active' => 1, 'created_by' => Context::userId(), 'created_at' => now()]));
            DB::table('src_po_pdf_versions')->insert(['fk_id_ent' => $ent, 'fk_id_po' => $id, 'version' => $ver, 'data_hash' => $hash, 'file_name' => $file, 'file_path' => $rel, 'fk_id_document' => $doc, 'created_by' => Context::userId(), 'created_at' => now()]);
            Audit::log('t_src_po', $id, 'UPDATE', ['pdf_version' => $ver, 'file' => $file], 'PO PDF made', $ent);
        } elseif (! is_file($abs)) file_put_contents($abs, $pdf);
        return ['file' => $file, 'version' => $ver, 'path' => $rel, 'body' => $pdf, 'html' => $html];
    }

    /** Send to supplier: e-mail with the latest PDF attached, or WhatsApp text, through the message queue; logged on the PO. */
    public static function send(int $ent, int $id, string $via): array
    {
        $x = self::make($ent, $id); $d = self::data($ent, $id); $s = $d['supplier'] ?? null;
        if (! $s) throw new \InvalidArgumentException('This PO has no supplier.');
        $via = strtoupper($via);
        if ($via === 'EMAIL') {
            if (! filter_var((string) $s['email'], FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('The supplier has no e-mail address.');
            $q = Connect\Messenger::queueEmail($ent, null, $s['email'], "Purchase order {$d['no']}", "Dear {$s['name']},\n\nPlease find attached our purchase order {$d['no']} (version {$x['version']}).\n\nKind regards,\n{$d['company']['name']}", GrnPrint::abs($x['path']), 'PO_SEND', 't_src_po', $id);
            $to = $s['email'];
        } elseif ($via === 'WHATSAPP') {
            $phone = preg_replace('/[^0-9+]/', '', (string) $s['phone']); if (strlen(preg_replace('/\D/', '', $phone)) < 8) throw new \InvalidArgumentException('The supplier has no WhatsApp number.');
            $q = Connect\Messenger::queueText($ent, null, $phone, "Purchase order {$d['no']} (version {$x['version']}) from {$d['company']['name']}: total " . number_format($d['totals']['po_total'], 2) . ' ' . $d['meta']['Currency'] . '. The PDF follows by e-mail or on request.', 't_src_po', $id);
            $to = $phone;
        } else throw new \InvalidArgumentException('Choose e-mail or WhatsApp.');
        DB::table('src_po_pdf_versions')->where('fk_id_po', $id)->where('version', $x['version'])->update(['sent_at' => now(), 'sent_via' => $via, 'sent_to' => $to]);
        Audit::log('t_src_po', $id, 'UPDATE', ['sent_to_supplier' => $via, 'to' => $to, 'version' => $x['version'], 'queue_id' => $q], 'PO sent to the supplier', $ent);
        return ['message' => "PO {$d['no']} v{$x['version']} queued to the supplier by " . strtolower($via) . " ($to).", 'version' => $x['version']];
    }
}
