<?php

namespace App\Http\Controllers;

use App\Services\Context;
use App\Services\ShareLinks;
use App\Services\SupplierUploads;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** R-B_SOURCING-64 supplier link without login: the public page of one link (no menu, no other record), and the signed-in side (make / list / revoke). */
class ShareController extends Controller
{
    private function e(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

    private function shell(string $title, string $body, int $status = 200)
    {
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . $this->e($title) . '</title>'
            . '<style>body{font-family:system-ui,sans-serif;margin:0;background:#f6f8f7;color:#14211e;line-height:1.5}main{max-width:720px;margin:0 auto;padding:20px 16px}.card{background:#fff;border:1px solid #dbe3e0;border-radius:10px;padding:16px;margin-bottom:14px}'
            . 'h1{font-size:1.3rem;margin:0 0 4px}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:6px 4px;border-bottom:1px solid #e7ecea;font-size:.92rem}td.n,th.n{text-align:right}.muted{color:#5b6b66;font-size:.88rem}'
            . 'button{background:#0f766e;color:#fff;border:0;border-radius:8px;padding:10px 16px;font-size:1rem;cursor:pointer}button.alt{background:#fff;color:#14211e;border:1px solid #9bb0aa}textarea,input{width:100%;box-sizing:border-box;padding:8px;border:1px solid #9bb0aa;border-radius:8px;font:inherit}'
            . '.ok{color:#0f766e;font-weight:600}.wrap{overflow-x:auto}form{margin-top:10px}</style></head><body><main>' . $body . '</main></body></html>';
        return response($html, $status)->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex');
    }

    private function gone() { return $this->shell('Link expired', '<div class="card"><h1>' . $this->e(ShareLinks::EXPIRED) . '</h1></div>', 410); }

    public function page(Request $r, string $token)
    {
        $l = ShareLinks::resolve($token); if (! $l) return $this->gone();
        if ($l->entity_type === 'SUPPLIER') return $this->uploadPage($r, $l, $token);
        $v = ShareLinks::view($l); if (! empty($v['gone'])) return $this->gone();
        $done = (string) $r->session()->pull('share_msg', ''); $err = (string) $r->session()->pull('share_err', ''); $csrf = '<input type="hidden" name="_token" value="' . $this->e(csrf_token()) . '">'; $u = '/s/' . $token;
        $b = '<div class="card"><h1>' . $this->e($v['purpose_label']) . '</h1><div class="muted">' . $this->e($v['company']) . ' · Purchase order ' . $this->e($v['po']) . ($v['date'] ? ' · ' . $this->e($v['date']) : '') . ' · link valid until ' . $this->e($v['expires']) . '</div>'
            . ($done !== '' ? '<p class="ok">' . $this->e($done) . '</p>' : '') . ($err !== '' ? '<p style="color:#b42318">' . $this->e($err) . '</p>' : '') . '</div>';
        if ($v['purpose'] === 'PO_CONFIRM') {
            $rows = ''; foreach ($v['lines'] as $x) $rows .= '<tr><td>' . $this->e($x['item']) . '</td><td class="n">' . $this->e(rtrim(rtrim(number_format($x['qty'], 2, '.', ''), '0'), '.')) . '</td><td class="n">' . $this->e(number_format($x['unit_price'], 2)) . '</td><td class="n">' . $this->e(number_format($x['total'], 2)) . '</td></tr>';
            $b .= '<div class="card"><div class="wrap"><table><thead><tr><th>Item</th><th class="n">Qty</th><th class="n">Unit price</th><th class="n">Total</th></tr></thead><tbody>' . $rows . '</tbody><tfoot><tr><th colspan="3">Total ' . $this->e($v['currency']) . '</th><th class="n">' . $this->e(number_format($v['total'], 2)) . '</th></tr></tfoot></table></div>'
                . ($v['state']['confirmed_at'] ? '<p class="ok">Confirmed on ' . $this->e(date('d-m-Y', strtotime((string) $v['state']['confirmed_at']))) . '.</p>' : '')
                . '<form method="post" action="' . $u . '/accept">' . $csrf . '<button>Accept this purchase order</button></form>'
                . '<form method="post" action="' . $u . '/change">' . $csrf . '<label class="muted" for="note">Or ask for a change</label><textarea id="note" name="note" rows="3" maxlength="500" required></textarea><p><button class="alt">Ask for this change</button></p></form></div>';
        } elseif ($v['purpose'] === 'PRODUCTION_PHOTO') {
            $b .= '<div class="card"><form method="post" action="' . $u . '/photos" enctype="multipart/form-data">' . $csrf . '<label class="muted" for="ph">Production photos (JPG, PNG or WEBP, up to 10)</label><input id="ph" type="file" name="photos[]" accept="image/*" multiple required><p><button>Send the photos</button></p></form></div>';
        } elseif ($v['purpose'] === 'SHIP_DATE') {
            $b .= '<div class="card">' . ($v['required_ship_date'] ? '<p class="muted">Ship date asked for: ' . $this->e(date('d-m-Y', strtotime((string) $v['required_ship_date']))) . '</p>' : '') . ($v['state']['proposed_ship_date'] ? '<p class="ok">You gave: ' . $this->e(date('d-m-Y', strtotime((string) $v['state']['proposed_ship_date']))) . '</p>' : '')
                . '<form method="post" action="' . $u . '/date">' . $csrf . '<label class="muted" for="d">Ready / ship date</label><input id="d" type="date" name="date" required><p><button>Send the date</button></p></form></div>';
        } else {
            $rows = ''; foreach ($v['files'] as $f) $rows .= '<tr><td>' . $this->e($f['name']) . '</td><td>' . $this->e($f['kind']) . '</td><td><a href="' . $u . '/file/' . (int) $f['id'] . '">Download</a></td></tr>';
            $b .= '<div class="card">' . ($rows ? '<div class="wrap"><table><thead><tr><th>File</th><th>Kind</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div>' : '<p class="muted">No approved artwork yet.</p>') . '</div>';
        }
        return $this->shell($v['purpose_label'], $b);
    }

    public function act(Request $r, string $token, string $action)
    {
        $l = ShareLinks::resolve($token); if (! $l) return $this->gone();
        if ($l->entity_type === 'SUPPLIER' || $action === 'upload') {
            try { if ($l->entity_type !== 'SUPPLIER' || $action !== 'upload') throw new \InvalidArgumentException('This link is not for that.');
                $x = SupplierUploads::upload($l, array_values(array_filter((array) $r->file('files', []))), $r->input('po'), $r->input('note'), $r->ip()); $r->session()->put('share_msg', $x['message']); }
            catch (\InvalidArgumentException $e) { $r->session()->put('share_err', $e->getMessage()); }
            return redirect('/s/' . $token);
        }
        try { $x = ShareLinks::act($l, $action, $r->only(['note', 'date']), $r->ip(), array_values(array_filter((array) $r->file('photos', [])))); $r->session()->put('share_msg', $x['message']); }
        catch (\InvalidArgumentException $e) { $r->session()->put('share_err', $e->getMessage()); }
        return redirect('/s/' . $token);
    }

    /** Owner 26-11 21:31 supplier upload link: send-only, in English and Chinese. */
    private function uploadPage(Request $r, object $l, string $token)
    {
        $v = SupplierUploads::view($l); $u = '/s/' . $token; $csrf = '<input type="hidden" name="_token" value="' . $this->e(csrf_token()) . '">';
        $done = (string) $r->session()->pull('share_msg', ''); $err = (string) $r->session()->pull('share_err', '');
        $b = '<div class="card"><h1>Send your documents · 上传文件</h1><div class="muted">' . $this->e($v['company']) . ' · for ' . $this->e($v['supplier']) . ' · valid until 有效期至 ' . $this->e($v['expires']) . '</div>'
            . ($done !== '' ? '<p class="ok">' . $this->e($done) . '</p>' : '') . ($err !== '' ? '<p style="color:#b42318">' . $this->e($err) . '</p>' : '') . '</div>'
            . '<div class="card"><p class="muted" style="margin-top:0">PI, invoice, packing list, B/L, photos, test reports. PDF, photos, Excel, Word or CSV – up to ' . SupplierUploads::PER_SEND . ' files at a time, ' . SupplierUploads::MAX_MB . ' MB each. You can send ' . $v['left_today'] . ' more today.<br>形式发票、商业发票、装箱单、提单、照片、检测报告。每次最多 ' . SupplierUploads::PER_SEND . ' 个文件，每个最大 ' . SupplierUploads::MAX_MB . ' MB。</p>'
            . '<form method="post" action="' . $u . '/upload" enctype="multipart/form-data">' . $csrf
            . '<label class="muted" for="f">Files · 文件</label><input id="f" type="file" name="files[]" multiple required accept=".pdf,.jpg,.jpeg,.png,.webp,.xlsx,.xls,.csv,.doc,.docx,.txt">'
            . '<p><label class="muted" for="po">PO number (if you know it) · 订单号（如有）</label><input id="po" name="po" maxlength="60"></p>'
            . '<p><label class="muted" for="n">Note · 备注</label><textarea id="n" name="note" rows="2" maxlength="500"></textarea></p><p><button>Send · 发送</button></p></form></div>'
            . '<p class="muted">You can only send files here – nothing else can be seen or changed. 此页面只能上传文件。</p>';
        return $this->shell('Send your documents', $b);
    }

    /** Owner 27-11: one vault picture without a login (a marketplace listing or a designer opens it); pictures only, signed link. */
    public function vaultPicture(Request $r, int $id, string $sig)
    {
        $f = \App\Services\ProductVault::publicFile($id, $sig); abort_unless($f && Storage::disk('local')->exists($f->stored_name), 404);
        return response(Storage::disk('local')->get($f->stored_name), 200, ['Content-Type' => $f->mime, 'Cache-Control' => 'public, max-age=86400', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'"]);
    }

    public function file(Request $r, string $token, int $id)
    {
        $l = ShareLinks::resolve($token); if (! $l || $l->entity_type !== 'PO') return $this->gone();
        $f = ShareLinks::file($l, $id, $r->ip()); if (! $f) abort(404);
        if ($f['stored'] && Storage::disk('local')->exists($f['stored'])) return response(Storage::disk('local')->get($f['stored']), 200, ['Content-Type' => $f['mime'] ?: 'application/octet-stream', 'Content-Disposition' => 'attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) ($f['orig'] ?: $f['name'])) . '"', 'Cache-Control' => 'no-store']);
        if ($f['drive_url'] && preg_match('#^https://#', (string) $f['drive_url'])) return redirect()->away($f['drive_url']);
        abort(404);
    }

    /* ---- signed-in side ---- */
    private function run(callable $fn) { try { return $fn(); } catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); } }
    public function index(Request $r, int $po) { \App\Services\Perm::need($r->user(), 'buying.SEE'); $p = \Illuminate\Support\Facades\DB::table('t_src_po')->where('id', $po)->where('fk_id_ent', Context::ent())->first(['supplier_confirmed_at', 'supplier_change_note', 'supplier_ship_date']); return ['po' => $p ? ['confirmed_at' => $p->supplier_confirmed_at, 'change_asked' => $p->supplier_change_note, 'proposed_ship_date' => $p->supplier_ship_date] : null, 'rows' => ShareLinks::list((int) Context::ent(), $po), 'purposes' => ShareLinks::PURPOSES, 'can' => \App\Services\Perm::has($r->user(), 'buying.shareLink'), 'days' => ShareLinks::days((int) Context::ent())]; }
    public function create(Request $r, int $po) { return $this->run(fn () => ShareLinks::create((int) Context::ent(), $po, (string) $r->input('purpose'), $r->user())); }
    public function revoke(Request $r, int $id) { return $this->run(fn () => ShareLinks::revoke((int) Context::ent(), $id, $r->user())); }
    public function supplierLink(Request $r, int $id) { return $this->run(fn () => SupplierUploads::create((int) Context::ent(), $id, $r->user())); }
    public function supplierInbox(Request $r) { \App\Services\Perm::need($r->user(), 'buying.SEE'); $ent = (int) Context::ent();
        $items = SupplierUploads::inbox($ent, (bool) $r->query('all')); $sids = array_values(array_unique(array_filter(array_column($items, 'supplier_id'))));
        $pos = \Illuminate\Support\Facades\DB::table('t_src_po')->where('fk_id_ent', $ent)->whereIn('fk_id_supplier', $sids ?: [0])->orderByDesc('id')->limit(500)->get(['id', 'fk_id_supplier', 'po_code', 'po_number'])->groupBy('fk_id_supplier')
            ->map(fn ($g) => $g->map(fn ($p) => ['id' => (int) $p->id, 'no' => $p->po_number ?: $p->po_code])->values())->all();
        return ['items' => $items, 'pos' => $pos, 'kinds' => \App\Services\SupplierUploads::kinds(), 'links' => \App\Services\Perm::has($r->user(), 'buying.shareLink') ? SupplierUploads::links($ent) : [],
            'suppliers' => \Illuminate\Support\Facades\DB::table('t_src_suppliers')->where('f_active', 1)->where(fn ($q) => $q->where('fk_id_ent', $ent)->orWhereNull('fk_id_ent')->orWhere('fk_id_ent', 0))->orderBy('supplier_name')->get(['id', 'supplier_name'])];
    }
    public function supplierDecide(Request $r, int $id) { return $this->run(fn () => SupplierUploads::decide((int) Context::ent(), $id, $r->only(['po_id', 'kind', 'done']), $r->user())); }
    public function supplierFile(Request $r, int $id) { \App\Services\Perm::need($r->user(), 'buying.SEE'); $f = SupplierUploads::file((int) Context::ent(), $id); if (! $f) abort(404);
        return response(Storage::disk('local')->get($f['path']), 200, ['Content-Type' => Storage::disk('local')->mimeType($f['path']) ?: 'application/octet-stream', 'Content-Disposition' => 'inline; filename="' . preg_replace('/[^A-Za-z0-9._-]+/', '_', $f['name']) . '"', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
    public function acceptDate(Request $r, int $po) { return $this->run(fn () => ShareLinks::acceptDate((int) Context::ent(), $po, $r->user())); }
}
