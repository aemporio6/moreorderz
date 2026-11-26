<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Owner 26-11 21:31 (from MY TOOL supplier_link + docbrain, made smarter): one upload link per supplier. No login, the supplier can only
 * SEND files – he sees nothing of ours: no prices, no other supplier, no customer. 90 days, 10 files a send, 30 a day. Every file is
 * filed by itself: the kind (PI, commercial invoice, packing list, B/L, photos, test report …) from its name and its text, and the PO
 * when the PO number is in the name or the text and belongs to this supplier. It lands in "From suppliers" until someone says Done.
 */
class SupplierUploads
{
    public const PURPOSE = 'SUPPLIER_UPLOAD';
    public const TYPES = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'xlsx', 'xls', 'csv', 'doc', 'docx', 'txt'];
    public const MAX_MB = 20; public const PER_SEND = 10; public const PER_DAY = 30; public const DAYS = 90;
    public const VIA = 'supplier (upload link)';

    /** kind => words in the file name / text (English + Chinese) */
    private const KINDS = [
        'Proforma invoice (PI)' => ['proforma', 'pro forma', 'pro-forma', '形式发票', '\bpi\b', 'quotation', '报价'],
        'Commercial invoice' => ['commercial invoice', '商业发票', '\binvoice\b', '\binv\b', '发票'],
        'Packing list' => ['packing list', 'packinglist', '装箱单', '\bpl\b'],
        'Bill of lading / AWB' => ['bill of lading', '提单', '\bb/?l\b', '\bawb\b', 'airway', 'telex', 'shipping order'],
        'Test report' => ['test report', '检测报告', 'inspection', '\bqc\b', 'lab report', '质检'],
        'Certificate' => ['certificate', '证书', '\bce\b', '\bmsds\b', '\bsds\b', 'declaration of conformity', 'ukca', 'rohs'],
        'Artwork' => ['artwork', 'carton mark', 'label design', '\bdieline', '唛头', '设计稿'],
        'Contract' => ['contract', '合同', 'agreement'],
        'Customs entry' => ['customs', '报关', 'export declaration', 'c88'],
    ];

    public static function kinds(): array { return array_merge(array_keys(self::KINDS), ['Product photos', 'Other']); }

    public static function create(int $ent, int $supplierId, object $u): array
    {
        Perm::need($u, 'buying.shareLink');
        $s = DB::table('t_src_suppliers')->where('id', $supplierId)->where(fn ($q) => $q->where('fk_id_ent', $ent)->orWhereNull('fk_id_ent')->orWhere('fk_id_ent', 0))->first() ?? throw new \InvalidArgumentException('Supplier not found.');
        $token = bin2hex(random_bytes(32)); $exp = now()->addDays(self::DAYS);
        $id = (int) DB::table('sys_share_links')->insertGetId(['fk_id_ent' => $ent, 'entity_type' => 'SUPPLIER', 'entity_id' => $supplierId, 'link_hash' => hash('sha256', $token), 'purpose' => self::PURPOSE, 'fk_id_party' => $supplierId,
            'expires_at' => $exp, 'used_count' => 0, 'created_by' => $u->id, 'created_at' => now()]);
        Audit::log('sys_share_links', $id, 'INSERT', ['supplier' => $s->supplier_name, 'purpose' => self::PURPOSE, 'expires_at' => $exp->toDateTimeString()], null, $ent);
        $url = url('/s/' . $token); $until = $exp->format('d-m-Y');
        $text = "Hello {$s->contact_person}, please send us your documents (PI, invoice, packing list, B/L, photos, test reports) with this link – no login needed: $url (valid until $until)\n"
            . "您好，请用此链接上传文件（形式发票、商业发票、装箱单、提单、照片、检测报告），无需登录：{$url} （有效期至 {$until}）";
        $text = str_replace('Hello , ', 'Hello, ', $text);
        return ['id' => $id, 'url' => $url, 'expires_at' => $exp->toDateTimeString(), 'supplier' => $s->supplier_name, 'text' => $text,
            'whatsapp' => ! empty($s->phone_whatsapp) ? 'https://wa.me/' . preg_replace('/\D+/', '', (string) $s->phone_whatsapp) . '?text=' . rawurlencode($text) : null, 'email' => $s->email ?: null,
            'message' => 'Upload link made – copy it now, it is shown only once. Valid for ' . self::DAYS . ' days.'];
    }

    public static function links(int $ent): array
    {
        return DB::table('sys_share_links as l')->leftJoin('t_src_suppliers as s', 's.id', '=', 'l.entity_id')->leftJoin('t_users as u', 'u.id', '=', 'l.created_by')
            ->where('l.fk_id_ent', $ent)->where('l.purpose', self::PURPOSE)->orderByDesc('l.id')->limit(200)
            ->get(['l.id', 'l.entity_id as supplier_id', 's.supplier_name', 'l.expires_at', 'l.used_count', 'l.revoked_at', 'l.created_at', 'u.display_name as by'])
            ->map(fn ($r) => (array) $r + ['state' => $r->revoked_at ? 'Revoked' : ($r->expires_at < now() ? 'Expired' : 'Active')])->all();
    }

    /** What the public page shows: our company name, the supplier's own name, the limits. Nothing else. */
    public static function view(object $l): array
    {
        $s = DB::table('t_src_suppliers')->where('id', $l->entity_id)->first();
        $today = DB::table('t_src_documents')->where('uploaded_by', self::VIA)->where('notes', 'like', '%[link ' . $l->id . ']%')->where('created_at', '>=', date('Y-m-d'))->count();
        return ['company' => (string) DB::table('t_enterprise')->where('id', $l->fk_id_ent)->value('ent_name'), 'supplier' => $s->supplier_name ?? '', 'expires' => date('d-m-Y', strtotime((string) $l->expires_at)), 'left_today' => max(0, self::PER_DAY - $today)];
    }

    public static function upload(object $l, array $files, ?string $poRef, ?string $note, ?string $ip): array
    {
        if ($l->purpose !== self::PURPOSE) throw new \InvalidArgumentException('This link is not for that.');
        if (! $files) throw new \InvalidArgumentException('Choose at least one file. 请选择文件。');
        if (count($files) > self::PER_SEND) throw new \InvalidArgumentException('At most ' . self::PER_SEND . ' files at a time. 每次最多 ' . self::PER_SEND . ' 个文件。');
        if (self::view($l)['left_today'] < count($files)) throw new \InvalidArgumentException('Today\'s limit of ' . self::PER_DAY . ' files is reached – please send the rest tomorrow. 今日上传已达上限，请明天再传。');
        foreach ($files as $f) {
            $ext = strtolower((string) $f->getClientOriginalExtension());
            if (! in_array($ext, self::TYPES, true)) throw new \InvalidArgumentException('PDF, photos, Excel, Word or CSV only. 仅限 PDF、图片、Excel、Word、CSV。');
            if ($f->getSize() > self::MAX_MB * 1024 * 1024) throw new \InvalidArgumentException('A file can be ' . self::MAX_MB . ' MB at most. 单个文件最大 ' . self::MAX_MB . ' MB。');
        }
        $ent = (int) $l->fk_id_ent; $sid = (int) $l->entity_id; $made = [];
        $note = mb_substr(trim((string) $note), 0, 500); $poRef = mb_substr(trim((string) $poRef), 0, 60);
        foreach ($files as $f) {
            $ext = strtolower((string) $f->getClientOriginalExtension()); $orig = mb_substr(preg_replace('/[^\pL\pN ._()-]+/u', '_', (string) $f->getClientOriginalName()), 0, 180);
            $name = 'supplier_uploads/' . $sid . '/' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            Storage::disk('local')->put($name, file_get_contents($f->getRealPath()));
            $text = $ext === 'pdf' ? self::pdfText(Storage::disk('local')->path($name)) : '';
            $kind = self::kind($orig . ' ' . $note, $text, $ext);
            $po = self::findPo($ent, $sid, $poRef . ' ' . $orig . ' ' . $note . ' ' . mb_substr($text, 0, 4000));
            $guess = $po ? null : self::guessPo($ent, $sid, $text);      // docbrain: no number written – the PO whose total is in the text, with the reason
            $same = DB::table('t_src_documents')->where('fk_id_ent', $ent)->where('uploaded_by', self::VIA)->where('notes', 'like', '%supplier ' . $sid . '%')->where('doc_name', 'like', '%– ' . $orig)->exists();
            $id = (int) DB::table('t_src_documents')->insertGetId(RecordService::fillNotNull('t_src_documents', ['fk_id_ent' => $ent, 'fk_id_po' => $po->id ?? null, 'fk_id_cycle' => $po->fk_id_cycle ?? null,
                'doc_name' => mb_substr($kind . ' – ' . $orig, 0, 250), 'doc_type' => in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) ? 'PHOTO' : strtoupper($ext), 'doc_category' => $kind, 'doc_status' => 'Received',
                'drive_url' => 'local:' . $name, 'uploaded_by' => self::VIA, 'notes' => trim("[link {$l->id}] [inbox] supplier " . $sid . ($poRef !== '' ? " · PO given: $poRef" : '') . ($note !== '' ? " · $note" : '') . ($guess ? " [guess {$guess[0]}: {$guess[1]}]" : '') . ($same ? ' [same name came before]' : '')), 'f_active' => 1, 'created_at' => now()]));
            $made[] = $id; EventBus::fire('DOC_ADDED', 't_src_documents', $id, ['supplier' => $sid, 'via' => 'upload link', 'po' => $po->id ?? null], $ent);
        }
        DB::table('sys_share_links')->where('id', $l->id)->increment('used_count');
        Audit::log('sys_share_links', (int) $l->id, 'UPDATE', ['use' => 'files sent', 'files' => count($made), 'ip' => $ip], 'Supplier upload link used', $ent);
        $sup = (string) DB::table('t_src_suppliers')->where('id', $sid)->value('supplier_name');
        if ($l->created_by) Notify::push((int) $l->created_by, 'SOURCING', count($made) . " file(s) came in from $sup.", '#/supplier-inbox');
        return ['message' => 'Thank you – ' . count($made) . ' file(s) received. 谢谢，已收到 ' . count($made) . ' 个文件。', 'documents' => $made];
    }

    private static function pdfText(string $path): string
    {
        try { $p = new Process([is_executable('/usr/bin/pdftotext') ? '/usr/bin/pdftotext' : 'pdftotext', '-l', '2', '-enc', 'UTF-8', $path, '-']); $p->setTimeout(20); $p->run(); return $p->isSuccessful() ? mb_substr($p->getOutput(), 0, 20000) : ''; } catch (\Throwable) { return ''; }
    }

    /** The kind of a document: the name first, then the text; photos are photos. */
    public static function kind(string $name, string $text, string $ext): string
    {
        $name = preg_replace('/\.[a-z0-9]{2,5}$/i', '', $name);
        foreach ([$name, $text] as $hay) { $h = mb_strtolower(preg_replace('/[_.\-]+/', ' ', $hay)); if (trim($h) === '') continue;
            foreach (self::KINDS as $k => $words) foreach ($words as $w) if (preg_match('~' . (str_starts_with($w, '\b') ? $w : preg_quote($w, '~')) . '~u', $h)) return $k; }
        return in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) ? 'Product photos' : 'Other';
    }

    /** The PO of this supplier whose number is written in the text (only his own POs – never another supplier's). */
    public static function findPo(int $ent, int $sid, string $hay): ?object
    {
        $pos = DB::table('t_src_po')->where('fk_id_ent', $ent)->where('fk_id_supplier', $sid)->orderByDesc('id')->limit(300)->get(['id', 'fk_id_cycle', 'po_code', 'po_number']);
        $h = mb_strtoupper($hay);
        foreach ($pos as $p) foreach ([$p->po_number, $p->po_code] as $no) if ($no && mb_strlen((string) $no) >= 4 && preg_match('~(?<![A-Z0-9])' . preg_quote(mb_strtoupper((string) $no), '~') . '(?![A-Z0-9])~u', $h)) return $p;
        return null;
    }

    /** One of his open POs whose total (or deposit / balance) is written in the text – only when exactly one fits. */
    public static function guessPo(int $ent, int $sid, string $text): ?array
    {
        if (trim($text) === '' || ! preg_match_all('/(?<![\d.])\d{1,3}(?:,\d{3})*(?:\.\d{1,2})|(?<![\d.,])\d{3,9}\.\d{2}(?!\d)/', $text, $m)) return null;
        $nums = array_unique(array_map(fn ($x) => round((float) str_replace(',', '', $x), 2), $m[0]));
        $hits = [];
        foreach (DB::table('t_src_po')->where('fk_id_ent', $ent)->where('fk_id_supplier', $sid)->where('f_active', 1)->orderByDesc('id')->limit(100)->get(['id', 'po_total', 'po_code', 'po_number']) as $p) {
            $t = round((float) $p->po_total, 2); if ($t <= 0) continue;
            foreach ($nums as $n) { if (abs($n - $t) < 0.01) { $hits[$p->id] = 'its total ' . number_format($t, 2) . ' is in the file'; break; }
                foreach ([30, 50, 70] as $pct) if (abs($n - round($t * $pct / 100, 2)) < 0.01) { $hits[$p->id] = "$pct% of its total (" . number_format($n, 2) . ') is in the file'; break 2; } }
        }
        return count($hits) === 1 ? [array_key_first($hits), reset($hits)] : null;
    }

    public static function inbox(int $ent, bool $all = false): array
    {
        $q = DB::table('t_src_documents as d')->leftJoin('t_src_po as p', 'p.id', '=', 'd.fk_id_po')->where('d.fk_id_ent', $ent)->where('d.uploaded_by', self::VIA)->where('d.f_active', 1);
        if (! $all) $q->where('d.notes', 'like', '%[inbox]%');
        $rows = $q->orderByDesc('d.id')->limit(300)->get(['d.id', 'd.doc_name', 'd.doc_category', 'd.doc_type', 'd.fk_id_po', 'p.po_code', 'p.po_number', 'd.notes', 'd.created_at']);
        $names = DB::table('t_src_suppliers')->pluck('supplier_name', 'id');
        return $rows->map(function ($r) use ($names) { preg_match('/supplier (\d+)/', (string) $r->notes, $m); $sid = (int) ($m[1] ?? 0);
            return ['id' => (int) $r->id, 'name' => $r->doc_name, 'kind' => $r->doc_category, 'type' => $r->doc_type, 'po_id' => $r->fk_id_po ? (int) $r->fk_id_po : null, 'po' => $r->po_number ?: $r->po_code,
                'supplier_id' => $sid, 'supplier' => $names[$sid] ?? null, 'note' => trim(preg_replace('/\[(link \d+|inbox|guess \d+:[^\]]*|same name came before)\]\s*|supplier \d+\s*·?\s*/', '', (string) $r->notes)),
                'guess' => preg_match('/\[guess (\d+): ([^\]]*)\]/', (string) $r->notes, $g) && ! $r->fk_id_po ? ['po_id' => (int) $g[1], 'why' => $g[2]] : null, 'same' => str_contains((string) $r->notes, '[same name came before]'), 'at' => $r->created_at, 'open' => str_contains((string) $r->notes, '[inbox]')]; })->all();
    }

    /** Attach to a PO (of the same supplier), change the kind, or say Done – the file leaves the inbox. */
    public static function decide(int $ent, int $docId, array $in, object $u): array
    {
        Perm::need($u, 'buying.SEE');
        $d = DB::table('t_src_documents')->where('id', $docId)->where('fk_id_ent', $ent)->where('uploaded_by', self::VIA)->first() ?? throw new \InvalidArgumentException('File not found.');
        preg_match('/supplier (\d+)/', (string) $d->notes, $m); $sid = (int) ($m[1] ?? 0); $set = [];
        if (! empty($in['po_id'])) { $po = DB::table('t_src_po')->where('id', (int) $in['po_id'])->where('fk_id_ent', $ent)->first() ?? throw new \InvalidArgumentException('PO not found.');
            if ($sid && (int) $po->fk_id_supplier !== $sid) throw new \InvalidArgumentException('That PO is of another supplier.');
            $set += ['fk_id_po' => $po->id, 'fk_id_cycle' => $po->fk_id_cycle]; }
        if (! empty($in['kind'])) $set['doc_category'] = mb_substr((string) $in['kind'], 0, 120);
        if (! empty($in['done'])) $set['notes'] = trim(str_replace('[inbox]', '', (string) $d->notes));
        if (! $set) throw new \InvalidArgumentException('Nothing to change.');
        $set['updated_by'] = $u->id; $set['updated_at'] = now();
        DB::table('t_src_documents')->where('id', $docId)->update($set);
        Audit::log('t_src_documents', $docId, 'UPDATE', array_map(fn ($v) => [null, $v], array_diff_key($set, ['updated_at' => 1, 'updated_by' => 1])), 'From suppliers', $ent);
        return ['message' => ! empty($in['done']) ? 'Done – filed.' : 'Saved.'];
    }

    public static function file(int $ent, int $docId): ?array
    {
        $d = DB::table('t_src_documents')->where('id', $docId)->where('fk_id_ent', $ent)->where('uploaded_by', self::VIA)->first();
        if (! $d || ! str_starts_with((string) $d->drive_url, 'local:')) return null; $p = substr((string) $d->drive_url, 6);
        return Storage::disk('local')->exists($p) ? ['path' => $p, 'name' => preg_replace('/^.*? – /u', '', (string) $d->doc_name)] : null;
    }
}
