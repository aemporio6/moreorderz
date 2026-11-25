<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * R-B_SOURCING-64 supplier link without login (M62).
 * A user with the right src.share_link makes a link for ONE purchase order, ONE party and ONE purpose:
 *   PO_CONFIRM · PRODUCTION_PHOTO · SHIP_DATE · ARTWORK_DOWNLOAD.
 * The link is a long random value; only its SHA-256 hash is kept (sys_share_links) with an expiry (Settings › Choices SHARE_LINK_DAYS, default 14)
 * and a use counter. The page behind it shows only that PO's own fields – its own prices, no other supplier, no customer data, no cost of ours –
 * and offers only the purpose. Every use is written to the audit log with the party and the IP. A link can be revoked at any time.
 * An expired or revoked link answers "This link has expired – ask your contact for a new one."
 */
class ShareLinks
{
    public const PURPOSES = ['PO_CONFIRM' => 'Confirm the purchase order', 'PRODUCTION_PHOTO' => 'Send production photos', 'SHIP_DATE' => 'Give the ready / ship date', 'ARTWORK_DOWNLOAD' => 'Download artwork and carton marks'];
    public const EXPIRED = 'This link has expired – ask your contact for a new one.';
    public const PHOTO_TYPES = ['jpg', 'jpeg', 'png', 'webp'];

    public static function days(int $ent): int { return max(1, min(90, (int) (Choices::get('SHARE_LINK_DAYS', $ent) ?: 14))); }

    public static function create(int $ent, int $poId, string $purpose, object $u): array
    {
        Perm::need($u, 'buying.shareLink');
        if (! isset(self::PURPOSES[$purpose])) throw new \InvalidArgumentException('Choose what the link is for.');
        $po = DB::table('t_src_po')->where('id', $poId)->where('fk_id_ent', $ent)->first() ?? throw new \InvalidArgumentException('Purchase order not found.');
        if ($purpose === 'PO_CONFIRM' && $po->status === 'DRAFT') throw new \InvalidArgumentException('Confirm the PO first – a draft is not sent to the supplier.');
        $token = bin2hex(random_bytes(32)); $exp = now()->addDays(self::days($ent));
        $id = (int) DB::table('sys_share_links')->insertGetId(['fk_id_ent' => $ent, 'entity_type' => 'PO', 'entity_id' => $poId, 'link_hash' => hash('sha256', $token), 'purpose' => $purpose, 'fk_id_party' => (int) $po->fk_id_supplier,
            'expires_at' => $exp, 'used_count' => 0, 'created_by' => $u->id, 'created_at' => now()]);
        Audit::log('sys_share_links', $id, 'INSERT', ['po' => $po->po_code, 'purpose' => $purpose, 'expires_at' => $exp->toDateTimeString()], null, $ent);
        $sup = DB::table('t_src_suppliers')->where('id', $po->fk_id_supplier)->first();
        $url = url('/s/' . $token);
        $text = 'Purchase order ' . ($po->po_number ?: $po->po_code) . ': ' . mb_strtolower(self::PURPOSES[$purpose]) . " – $url (valid until " . $exp->format('d-m-Y') . ')';
        /* the link itself is shown once – it is not stored anywhere, so it cannot be read back later */
        return ['id' => $id, 'url' => $url, 'expires_at' => $exp->toDateTimeString(), 'purpose' => $purpose, 'supplier' => $sup->supplier_name ?? null, 'text' => $text,
            'whatsapp' => ! empty($sup->phone_whatsapp) ? 'https://wa.me/' . preg_replace('/\D+/', '', (string) $sup->phone_whatsapp) . '?text=' . rawurlencode($text) : null, 'email' => $sup->email ?? null,
            'message' => 'Link made – copy it now, it is shown only once. Valid for ' . self::days($ent) . ' days.'];
    }

    public static function list(int $ent, int $poId): array
    {
        return DB::table('sys_share_links as l')->leftJoin('t_users as u', 'u.id', '=', 'l.created_by')->where('l.fk_id_ent', $ent)->where('l.entity_type', 'PO')->where('l.entity_id', $poId)->orderByDesc('l.id')
            ->get(['l.id', 'l.purpose', 'l.expires_at', 'l.used_count', 'l.revoked_at', 'l.created_at', 'u.display_name'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'purpose' => $r->purpose, 'purpose_label' => self::PURPOSES[$r->purpose] ?? $r->purpose, 'expires_at' => $r->expires_at, 'used' => (int) $r->used_count, 'by' => $r->display_name, 'made' => $r->created_at,
                'state' => $r->revoked_at ? 'Revoked' : ($r->expires_at < now() ? 'Expired' : 'Active')])->all();
    }

    public static function revoke(int $ent, int $id, object $u): array
    {
        Perm::need($u, 'buying.shareLink');
        $l = DB::table('sys_share_links')->where('id', $id)->where('fk_id_ent', $ent)->first() ?? throw new \InvalidArgumentException('Link not found.');
        if (! $l->revoked_at) { DB::table('sys_share_links')->where('id', $id)->update(['revoked_at' => now(), 'revoked_by' => $u->id]); Audit::log('sys_share_links', $id, 'UPDATE', ['revoked' => [null, 'yes']], null, $ent); }
        return ['message' => 'Link revoked – it no longer opens.'];
    }

    /** The live link of a token, or null (unknown, expired, revoked). */
    public static function resolve(string $token): ?object
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $token)) return null;
        $l = DB::table('sys_share_links')->where('link_hash', hash('sha256', $token))->first();
        return $l && ! $l->revoked_at && $l->expires_at >= now() && $l->entity_type === 'PO' ? $l : null;
    }

    private static function used(object $l, string $what, array $detail, ?string $ip): void
    {
        DB::table('sys_share_links')->where('id', $l->id)->increment('used_count');
        Audit::log('sys_share_links', (int) $l->id, 'UPDATE', ['use' => $what, 'party' => (int) $l->fk_id_party, 'ip' => $ip] + $detail, 'Share link used: ' . $what, (int) $l->fk_id_ent);
    }

    /** What the page behind the link may show: this PO's own fields only. */
    public static function view(object $l): array
    {
        $po = DB::table('t_src_po')->where('id', $l->entity_id)->first(); if (! $po) return ['purpose' => $l->purpose, 'gone' => true];
        $cur = $po->fk_id_currency ? (string) DB::table('cat_currency')->where('id', $po->fk_id_currency)->value('currency_code') : '';
        $v = ['purpose' => $l->purpose, 'purpose_label' => self::PURPOSES[$l->purpose], 'po' => $po->po_number ?: $po->po_code, 'date' => $po->po_date ? date('d-m-Y', strtotime((string) $po->po_date)) : null, 'expires' => date('d-m-Y', strtotime((string) $l->expires_at)),
            'company' => (string) DB::table('t_enterprise')->where('id', $po->fk_id_ent)->value('ent_name'), 'required_ship_date' => $po->required_ship_date, 'state' => self::state($po)];
        if ($l->purpose === 'PO_CONFIRM') {
            $v['currency'] = $cur; $v['total'] = round((float) $po->po_total, 2);
            $v['lines'] = DB::table('t_src_po_lines as pl')->leftJoin('t_src_cycle_products as cp', 'cp.id', '=', 'pl.fk_id_cycle_product')->where('pl.fk_id_po', $po->id)->where('pl.f_active', 1)->orderBy('pl.id')
                ->get(['pl.sku', 'cp.product_name', 'pl.qty_ordered', 'pl.unit_price', 'pl.line_total', 'pl.line_type', 'pl.line_notes'])
                ->map(fn ($x) => ['item' => trim(($x->sku ? $x->sku . ' ' : '') . ($x->product_name ?: ($x->line_notes ?: $x->line_type))), 'qty' => (float) $x->qty_ordered, 'unit_price' => round((float) $x->unit_price, 4), 'total' => round((float) $x->line_total, 2)])->all();
        }
        if ($l->purpose === 'ARTWORK_DOWNLOAD') $v['files'] = array_map(fn ($f) => ['id' => $f['id'], 'name' => $f['name'], 'kind' => $f['kind']], self::artwork($po));
        return $v;
    }

    private static function state(object $po): array
    {
        return ['confirmed_at' => $po->supplier_confirmed_at ?? null, 'change_asked' => $po->supplier_change_note ?? null, 'proposed_ship_date' => $po->supplier_ship_date ?? null];
    }

    /** Only APPROVED artwork versions of the PO's products (and their carton marks kept as artwork kind CARTON). */
    public static function artwork(object $po): array
    {
        $products = DB::table('t_src_po_lines')->where('fk_id_po', $po->id)->where('f_active', 1)->whereNotNull('fk_id_product')->pluck('fk_id_product')->unique()->all();
        if (! $products) return [];
        return DB::table('product_artwork_files as a')->join('sys_documents as d', 'd.id', '=', 'a.fk_id_document')->whereIn('a.fk_id_product', $products)->where('a.artwork_status', 'APPROVED')->orderBy('a.fk_id_product')->orderByDesc('a.id')
            ->get(['a.id', 'a.artwork_kind', 'a.version_no', 'd.title', 'd.orig_name', 'd.stored_name', 'd.drive_url', 'd.mime'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'name' => trim(($r->title ?: $r->orig_name ?: 'Artwork') . ' v' . $r->version_no), 'kind' => $r->artwork_kind, 'stored' => $r->stored_name, 'orig' => $r->orig_name, 'mime' => $r->mime, 'drive_url' => $r->drive_url])->all();
    }

    /** One action of the link's own purpose. Anything else is refused. */
    public static function act(object $l, string $action, array $in, ?string $ip, array $files = []): array
    {
        $po = DB::table('t_src_po')->where('id', $l->entity_id)->first() ?? throw new \InvalidArgumentException(self::EXPIRED);
        $ent = (int) $po->fk_id_ent; $no = $po->po_number ?: $po->po_code; $owner = (int) ($po->requested_by ?: $po->created_by) ?: null;
        $allowed = ['PO_CONFIRM' => ['accept', 'change'], 'PRODUCTION_PHOTO' => ['photos'], 'SHIP_DATE' => ['date'], 'ARTWORK_DOWNLOAD' => []][$l->purpose] ?? [];
        if (! in_array($action, $allowed, true)) throw new \InvalidArgumentException('This link is not for that.');
        return DB::transaction(function () use ($l, $po, $ent, $no, $owner, $action, $in, $ip, $files) {
            if ($action === 'accept') {
                $set = ['supplier_confirmed_at' => now(), 'supplier_change_note' => null]; if ($po->status === 'CONFIRMED') $set['status'] = 'SENT';      /* the step "Sent / confirmed" is done */
                DB::table('t_src_po')->where('id', $po->id)->update($set); self::used($l, 'PO accepted', [], $ip);
                TaskEngine::close('SRC_PO_CHANGE_ASKED', [$po->id], 'The supplier accepted the PO');
                if ($owner) Notify::push($owner, 'SOURCING', "The supplier confirmed PO $no.", '#/po/' . $po->id);
                return ['message' => 'Thank you – the purchase order is confirmed.'];
            }
            if ($action === 'change') {
                $note = mb_substr(trim((string) ($in['note'] ?? '')), 0, 500); if ($note === '') throw new \InvalidArgumentException('Write what should change.');
                DB::table('t_src_po')->where('id', $po->id)->update(['supplier_change_note' => $note]); self::used($l, 'PO change asked', ['note' => $note], $ip);
                TaskEngine::open('SRC_PO_CHANGE_ASKED', [$po->id], "The supplier asks a change on PO $no: " . mb_substr($note, 0, 120), ['ent' => $ent, 'owner_user_id' => $owner, 'module' => 't_src_po', 'record_id' => (int) $po->id, 'fix_url' => '#/po/' . $po->id]);
                return ['message' => 'Sent – your contact will answer the change you asked for.'];
            }
            if ($action === 'date') {
                $d = trim((string) ($in['date'] ?? '')); $t = strtotime($d); if ($d === '' || ! $t || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) throw new \InvalidArgumentException('Give the date as year-month-day.');
                if ($d < date('Y-m-d', strtotime('-1 day'))) throw new \InvalidArgumentException('The date cannot be in the past.');
                DB::table('t_src_po')->where('id', $po->id)->update(['supplier_ship_date' => $d]); self::used($l, 'Ship date proposed', ['date' => $d], $ip);
                TaskEngine::open('SRC_SHIP_DATE_PROPOSED', [$po->id], "The supplier gives $d as the ready / ship date of PO $no – accept it", ['ent' => $ent, 'owner_user_id' => $owner, 'module' => 't_src_po', 'record_id' => (int) $po->id, 'fix_url' => '#/po/' . $po->id]);
                return ['message' => 'Thank you – the date was sent to your contact.'];
            }
            /* photos */
            if (! $files) throw new \InvalidArgumentException('Choose at least one photo.');
            if (count($files) > 10) throw new \InvalidArgumentException('At most 10 photos at a time.');
            $made = [];
            foreach ($files as $f) {
                $ext = strtolower((string) $f->getClientOriginalExtension());
                if (! in_array($ext, self::PHOTO_TYPES, true) || ! str_starts_with((string) $f->getMimeType(), 'image/')) throw new \InvalidArgumentException('Photos only (JPG, PNG or WEBP).');
                if ($f->getSize() > 10 * 1024 * 1024) throw new \InvalidArgumentException('A photo can be 10 MB at most.');
            }
            foreach ($files as $f) {
                $name = 'share_photos/' . $po->id . '/' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . strtolower((string) $f->getClientOriginalExtension());
                Storage::disk('local')->put($name, file_get_contents($f->getRealPath()));
                $made[] = (int) DB::table('t_src_documents')->insertGetId(RecordService::fillNotNull('t_src_documents', ['fk_id_ent' => $ent, 'fk_id_cycle' => $po->fk_id_cycle, 'fk_id_po' => $po->id, 'doc_name' => mb_substr('Production photo – ' . $f->getClientOriginalName(), 0, 250), 'doc_type' => 'PHOTO',
                    'doc_category' => 'PRODUCTION', 'doc_status' => 'RECEIVED', 'drive_url' => 'local:' . $name, 'uploaded_by' => 'supplier (share link)', 'f_active' => 1, 'created_at' => now()]));
            }
            foreach ($made as $doc) EventBus::fire('DOC_ADDED', 't_src_documents', $doc, ['po' => (int) $po->id, 'via' => 'share link'], $ent);
            self::used($l, 'Production photos', ['photos' => count($made)], $ip);
            if ($owner) Notify::push($owner, 'SOURCING', count($made) . " production photo(s) came in for PO $no.", '#/po/' . $po->id);
            return ['message' => 'Thank you – ' . count($made) . ' photo(s) received.', 'documents' => $made];
        });
    }

    /** The PO owner accepts the proposed date: it becomes the PO's required ship date and the ETA basis of its open shipments. */
    public static function acceptDate(int $ent, int $poId, object $u): array
    {
        Perm::need($u, 'buying.BUY');
        $po = DB::table('t_src_po')->where('id', $poId)->where('fk_id_ent', $ent)->first() ?? throw new \InvalidArgumentException('Purchase order not found.');
        if (empty($po->supplier_ship_date)) throw new \InvalidArgumentException('The supplier gave no date.');
        DB::table('t_src_po')->where('id', $poId)->update(['required_ship_date' => $po->supplier_ship_date, 'supplier_ship_date' => null]);
        Audit::log('t_src_po', $poId, 'UPDATE', ['required_ship_date' => [$po->required_ship_date, $po->supplier_ship_date]], "Supplier's date accepted", $ent);
        TaskEngine::close('SRC_SHIP_DATE_PROPOSED', [$poId], 'Date accepted');
        return ['message' => 'Ship date set to ' . date('d-m-Y', strtotime((string) $po->supplier_ship_date)) . '.'];
    }

    /** One approved artwork file of the link's PO, for download; anything else → null. */
    public static function file(object $l, int $artworkId, ?string $ip): ?array
    {
        if ($l->purpose !== 'ARTWORK_DOWNLOAD') return null;
        $po = DB::table('t_src_po')->where('id', $l->entity_id)->first(); if (! $po) return null;
        $f = collect(self::artwork($po))->firstWhere('id', $artworkId); if (! $f) return null;
        self::used($l, 'Artwork downloaded', ['artwork' => $artworkId], $ip);
        return $f;
    }
}
