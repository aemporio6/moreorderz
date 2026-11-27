<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Owner 27-11 06:43 / 07:28 – the communication hub. One contact list over everyone the business talks to: suppliers, forwarders and people
 * (partners / investors, VAs, staff, external contacts), each with every way to reach them – WhatsApp, WeChat, SMS / phone, e-mail – read from their own
 * record plus extra numbers added here. "Message" opens the person's OWN app (WhatsApp of whoever presses, SMS, mail; a WeChat id is copied) with the text
 * ready, and the message is written to that contact's conversation. What they answer is added with a paste, a screenshot, a file or a voice note
 * (played in the tool). Any line can be pinned as EVIDENCE to a record (PO, cycle, claim, payment, shipment…) and shows there as proof. Nothing is
 * ever changed or deleted from a conversation – a wrong line is struck out by a new one. The tool's own automatic messages (tm_messages) to a person
 * show in the same conversation, read only.
 */
class Contacts
{
    public const TYPES = ['SUPPLIER' => ['t_src_suppliers', 'Supplier'], 'FORWARDER' => ['t_src_forwarders', 'Forwarder'], 'PERSON' => ['t_account', 'Person']];
    public const CHANNELS = ['WHATSAPP' => 'WhatsApp', 'WECHAT' => 'WeChat', 'SMS' => 'SMS', 'CALL' => 'Phone call', 'EMAIL' => 'E-mail', 'NOTE' => 'Note (met / other)'];
    public const EVIDENCE_ON = ['t_src_po' => 'Purchase order', 't_src_cycles' => 'Buying cycle', 't_src_payments' => 'Supplier payment', 't_src_shipments' => 'Shipment', 't_src_grn' => 'Goods receipt', 't_src_qc' => 'QC',
        't_src_suppliers' => 'Supplier', 't_product_vendors' => 'Product', 't_account' => 'Person', 'fin_investor_ledger' => 'Partner ledger line', 't_sales' => 'Sale line'];
    public const MAX_BYTES = 25 * 1048576;
    private const EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'zip', 'mp4', 'mov', 'ogg', 'oga', 'opus', 'mp3', 'm4a', 'aac', 'wav', 'webm', 'amr'];

    private static function digits(?string $v): string { return preg_replace('/\D+/', '', (string) $v); }

    /** The contact row of one party: name, role text, its channels [[channel, address, label]]. */
    public static function party(int $ent, string $type, int $id): array
    {
        [$table] = self::TYPES[$type] ?? throw new \InvalidArgumentException('Unknown kind of contact.');
        $r = DB::table($table)->where('id', $id)->where('fk_id_ent', $ent)->first() ?? throw new \InvalidArgumentException('Contact not found.');
        $ch = []; $add = function (string $c, $v, string $l = '') use (&$ch) { $v = trim((string) $v); if ($v !== '' && ! in_array([$c, $v], array_map(fn ($x) => [$x[0], $x[1]], $ch), true)) $ch[] = [$c, $v, $l]; };
        if ($type === 'SUPPLIER') { $name = $r->supplier_name; $role = 'Supplier' . ($r->contact_person ? ' · ' . $r->contact_person : ''); $add('WHATSAPP', $r->phone_whatsapp); $add('WECHAT', $r->wechat); $add('EMAIL', $r->email); $add('CALL', $r->phone_whatsapp); }
        elseif ($type === 'FORWARDER') { $name = $r->forwarder_name; $role = 'Forwarder' . ($r->contact_person ? ' · ' . $r->contact_person : ''); $add('WHATSAPP', $r->phone); $add('WECHAT', $r->wechat); $add('EMAIL', $r->email); $add('CALL', $r->phone); }
        else { $name = trim(($r->first_name ?? '') . ' ' . ($r->sur_name ?? '')); $role = self::roleOf($ent, $id); $add('WHATSAPP', $r->whatsapp_number);
            foreach (DB::table('t_account_mobiles')->where('fk_id_account', $id)->where('f_active', 1)->limit(5)->pluck('mobile_no') as $m) { $add('SMS', $m); $add('CALL', $m); }
            foreach (DB::table('t_account_emails')->where('fk_id_account', $id)->where('f_active', 1)->limit(5)->pluck('email') as $m) $add('EMAIL', $m); }
        foreach (DB::table('comm_contacts')->where(['fk_id_ent' => $ent, 'party_type' => $type, 'party_id' => $id, 'f_active' => 1])->get() as $x) $add($x->channel, $x->address, (string) $x->label);
        return ['type' => $type, 'id' => $id, 'name' => $name ?: '#' . $id, 'role' => $role, 'channels' => array_map(fn ($c) => ['channel' => $c[0], 'name' => self::CHANNELS[$c[0]], 'address' => $c[1], 'label' => $c[2]], $ch),
            'open' => $type === 'SUPPLIER' ? '#/rec/t_src_suppliers/' . $id : ($type === 'FORWARDER' ? '#/rec/t_src_forwarders/' . $id : '#/rec/t_account/' . $id)];
    }

    private static function roleOf(int $ent, int $acc): string
    {
        $u = DB::table('t_users')->where('fk_id_account', $acc)->value('role_code'); $r = [];
        if ($u) $r[] = ucfirst(strtolower((string) $u));
        if (DB::table('fin_investor_ledger')->where('investor_id', $acc)->exists() || DB::table('fin_lots')->where('fk_id_owner', $acc)->exists()) $r[] = 'Partner / investor';
        return $r ? implode(' · ', $r) : 'Person';
    }

    /** The contact list: everyone, with how many ways to reach them, the last line of the conversation; search by name / number. */
    public static function list(int $ent, string $q = '', string $kind = ''): array
    {
        $q = trim($q); $like = '%' . addcslashes($q, '%_\\') . '%'; $rows = [];
        if ($kind === '' || $kind === 'SUPPLIER') foreach (DB::table('t_src_suppliers')->where('fk_id_ent', $ent)->where('f_active', 1)->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('supplier_name', 'like', $like)->orWhere('contact_person', 'like', $like)->orWhere('phone_whatsapp', 'like', $like)->orWhere('wechat', 'like', $like)))
            ->orderBy('supplier_name')->limit(300)->get(['id', 'supplier_name', 'contact_person', 'phone_whatsapp', 'wechat', 'email']) as $s)
            $rows[] = ['type' => 'SUPPLIER', 'id' => (int) $s->id, 'name' => $s->supplier_name, 'role' => 'Supplier' . ($s->contact_person ? ' · ' . $s->contact_person : ''), 'has' => array_keys(array_filter(['WHATSAPP' => $s->phone_whatsapp, 'WECHAT' => $s->wechat, 'EMAIL' => $s->email]))];
        if ($kind === '' || $kind === 'FORWARDER') foreach (DB::table('t_src_forwarders')->where('fk_id_ent', $ent)->where('f_active', 1)->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('forwarder_name', 'like', $like)->orWhere('contact_person', 'like', $like)->orWhere('phone', 'like', $like)))
            ->orderBy('forwarder_name')->limit(300)->get(['id', 'forwarder_name', 'contact_person', 'phone', 'wechat', 'email']) as $s)
            $rows[] = ['type' => 'FORWARDER', 'id' => (int) $s->id, 'name' => $s->forwarder_name, 'role' => 'Forwarder' . ($s->contact_person ? ' · ' . $s->contact_person : ''), 'has' => array_keys(array_filter(['WHATSAPP' => $s->phone, 'WECHAT' => $s->wechat, 'EMAIL' => $s->email]))];
        if ($kind === '' || $kind === 'PERSON') { $supAcc = DB::table('t_src_suppliers')->where('fk_id_ent', $ent)->whereNotNull('fk_id_account')->pluck('fk_id_account')->all();      // a supplier's party row is the supplier itself
            foreach (DB::table('t_account')->where('fk_id_ent', $ent)->where('f_active', 1)->whereNotIn('id', $supAcc ?: [0])->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('first_name', 'like', $like)->orWhere('sur_name', 'like', $like)->orWhere('whatsapp_number', 'like', $like)))
                ->orderBy('first_name')->limit(400)->get(['id', 'first_name', 'sur_name', 'whatsapp_number']) as $a)
                $rows[] = ['type' => 'PERSON', 'id' => (int) $a->id, 'name' => trim($a->first_name . ' ' . $a->sur_name) ?: '#' . $a->id, 'role' => 'Person', 'has' => $a->whatsapp_number ? ['WHATSAPP'] : []]; }
        $last = DB::table('comm_log')->where('fk_id_ent', $ent)->whereIn('id', DB::table('comm_log')->where('fk_id_ent', $ent)->selectRaw('MAX(id)')->groupBy('party_type', 'party_id'))->get(['party_type', 'party_id', 'body', 'file_name', 'said_at', 'direction'])->keyBy(fn ($l) => $l->party_type . ':' . $l->party_id);
        foreach ($rows as &$r) { $l = $last[$r['type'] . ':' . $r['id']] ?? null; $r['last'] = $l ? ['at' => (string) $l->said_at, 'text' => mb_substr((string) ($l->body ?: $l->file_name), 0, 70), 'in' => $l->direction === 'IN'] : null; } unset($r);
        usort($rows, fn ($a, $b) => strcmp($b['last']['at'] ?? '', $a['last']['at'] ?? '') ?: strcasecmp($a['name'], $b['name']));
        return ['rows' => $rows, 'channels' => self::CHANNELS, 'kinds' => array_map(fn ($t) => $t[1], self::TYPES)];
    }

    /** How a channel opens on the device of whoever presses (null = nothing to open: copy the id, or just log). */
    public static function url(string $channel, string $address, string $text): ?string
    {
        return match ($channel) {
            'WHATSAPP' => ($d = self::digits($address)) !== '' ? 'https://wa.me/' . $d . ($text !== '' ? '?text=' . rawurlencode($text) : '') : null,
            'SMS' => 'sms:' . preg_replace('/[^\d+]/', '', $address) . ($text !== '' ? '?body=' . rawurlencode($text) : ''),
            'CALL' => 'tel:' . preg_replace('/[^\d+]/', '', $address),
            'EMAIL' => filter_var($address, FILTER_VALIDATE_EMAIL) ? 'mailto:' . $address . '?body=' . rawurlencode($text) : null,
            default => null };
    }

    private static function link(?string $table, $id): array
    {
        $table = (string) $table; $id = (int) $id; if ($table === '' || ! $id) return [null, null];
        if (! isset(self::EVIDENCE_ON[$table]) || ! DB::table($table)->where('id', $id)->exists()) throw new \InvalidArgumentException('That record cannot carry a message.');
        return [$table, $id];
    }

    /**
     * Write one line of a conversation. $d: channel, direction (OUT | IN), text, address (for OUT: where it goes), link_table / link_id (the record it is about),
     * evidence (bool) + evidence_note, said_at. $file: a screenshot, document or voice note. Returns the row (+ 'url' to open for an OUT line).
     */
    public static function say(int $ent, string $type, int $id, array $d, ?UploadedFile $file, object $u): array
    {
        $p = self::party($ent, $type, $id); $ch = strtoupper((string) ($d['channel'] ?? 'NOTE')); if (! isset(self::CHANNELS[$ch])) throw new \InvalidArgumentException('Choose how it was said: WhatsApp, WeChat, SMS, call, e-mail or note.');
        $dir = strtoupper((string) ($d['direction'] ?? 'OUT')) === 'IN' ? 'IN' : 'OUT'; $text = trim((string) ($d['text'] ?? ''));
        if ($text === '' && ! $file) throw new \InvalidArgumentException('Write the message, or add the screenshot / file / voice note.');
        if (mb_strlen($text) > 5000) throw new \InvalidArgumentException('Keep one message under 5,000 characters – attach the rest as a file.');
        [$lt, $li] = self::link($d['link_table'] ?? null, $d['link_id'] ?? null);
        $row = ['fk_id_ent' => $ent, 'party_type' => $type, 'party_id' => $id, 'channel' => $ch, 'direction' => $dir, 'body' => $text !== '' ? $text : null, 'link_table' => $lt, 'link_id' => $li,
            'f_evidence' => ! empty($d['evidence']) && $lt ? 1 : 0, 'evidence_note' => ! empty($d['evidence']) ? mb_substr(trim((string) ($d['evidence_note'] ?? '')), 0, 255) ?: null : null,
            'said_at' => ! empty($d['said_at']) && strtotime((string) $d['said_at']) ? date('Y-m-d H:i:s', min(time(), strtotime((string) $d['said_at']))) : now(), 'user_id' => $u->id, 'created_at' => now(), 'updated_at' => now()];
        if ($file) {
            if (! $file->isValid() || $file->getSize() > self::MAX_BYTES) throw new \InvalidArgumentException('The file is larger than 25 MB – keep it in Drive and paste its link.');
            $ext = strtolower((string) $file->getClientOriginalExtension()) ?: strtolower((string) $file->guessExtension()); if (! in_array($ext, self::EXT, true)) throw new \InvalidArgumentException('This kind of file is not kept in a conversation (pictures, PDF, Office files, audio and video are).');
            $path = 'comm/' . $ent . '/' . date('Ym') . '/' . bin2hex(random_bytes(10)) . '.' . $ext; Storage::disk('local')->put($path, file_get_contents($file->getRealPath()));
            $row += ['file_path' => $path, 'file_name' => mb_substr(preg_replace('/[\x00-\x1F\/\\\\]+/u', ' ', (string) $file->getClientOriginalName()) ?: 'file.' . $ext, 0, 180), 'file_mime' => mb_substr((string) $file->getMimeType(), 0, 100), 'file_bytes' => (int) $file->getSize()];
        }
        $rid = (int) DB::table('comm_log')->insertGetId($row);
        $url = $dir === 'OUT' && ! empty($d['address']) ? self::url($ch, (string) $d['address'], $text) : null;
        if ($row['f_evidence']) Audit::log($lt, $li, 'UPDATE', ['evidence' => [null, 'message #' . $rid . ' of ' . $p['name']]], 'Evidence added', $ent);
        return ['id' => $rid, 'url' => $url, 'copy' => $dir === 'OUT' && $ch === 'WECHAT' ? (string) ($d['address'] ?? '') : null,
            'message' => $dir === 'IN' ? 'Added to the conversation.' : ($url ? 'Written to the conversation – ' . self::CHANNELS[$ch] . ' opens with the text ready.' : ($ch === 'WECHAT' ? 'Written to the conversation – the WeChat id and the text are copied; paste them in WeChat.' : 'Written to the conversation.'))];
    }

    private static function line(object $l, array $names): array
    {
        $kind = $l->file_mime ? (str_starts_with($l->file_mime, 'audio') || preg_match('/\.(ogg|oga|opus|mp3|m4a|aac|wav|amr)$/i', (string) $l->file_name) ? 'voice' : (str_starts_with($l->file_mime, 'image') ? 'image' : (str_starts_with($l->file_mime, 'video') ? 'video' : 'file'))) : null;
        return ['id' => (int) $l->id, 'at' => (string) $l->said_at, 'channel' => $l->channel, 'channel_name' => self::CHANNELS[$l->channel] ?? $l->channel, 'in' => $l->direction === 'IN', 'text' => $l->body, 'by' => $names[$l->user_id] ?? null,
            'file' => $l->file_path ? ['name' => $l->file_name, 'kind' => $kind, 'url' => '/api/contacts/file/' . $l->id, 'kb' => (int) ceil($l->file_bytes / 1024)] : null,
            'evidence' => (bool) $l->f_evidence, 'evidence_note' => $l->evidence_note, 'link' => $l->link_table ? ['table' => $l->link_table, 'id' => (int) $l->link_id, 'label' => (self::EVIDENCE_ON[$l->link_table] ?? $l->link_table) . ' ' . self::code($l->link_table, (int) $l->link_id)] : null];
    }

    private static function code(string $t, int $id): string
    {
        $col = ['t_src_po' => 'po_number', 't_src_cycles' => 'cycle_code', 't_src_payments' => 'payment_code', 't_src_shipments' => 'shipment_code', 't_src_grn' => 'grn_number', 't_src_suppliers' => 'supplier_name', 't_product_vendors' => 'SKU_VENDOR'][$t] ?? null;
        return (string) (($col ? DB::table($t)->where('id', $id)->value($col) : null) ?: '#' . $id);
    }

    /** One contact's conversation, oldest first: what was written here, plus the tool's own automatic messages to that person (read only). */
    public static function thread(int $ent, string $type, int $id, int $limit = 300): array
    {
        $p = self::party($ent, $type, $id);
        $rows = DB::table('comm_log')->where(['fk_id_ent' => $ent, 'party_type' => $type, 'party_id' => $id])->orderByDesc('said_at')->orderByDesc('id')->limit($limit)->get();
        $names = DB::table('t_users')->whereIn('id', $rows->pluck('user_id')->filter()->unique())->pluck('display_name', 'id')->all();
        $lines = $rows->map(fn ($l) => self::line($l, $names))->all();
        if ($type === 'PERSON') foreach (DB::table('tm_messages')->where('fk_id_account', $id)->orderByDesc('id')->limit(100)->get(['id', 'channel', 'direction', 'text', 'status', 'created_at']) as $m)
            $lines[] = ['id' => 'tm' . $m->id, 'at' => (string) $m->created_at, 'channel' => $m->channel, 'channel_name' => (self::CHANNELS[$m->channel] ?? ucfirst(strtolower((string) $m->channel))) . ' · automatic', 'in' => strtoupper((string) $m->direction) === 'IN',
                'text' => MessageLog::clean($m->text, true), 'by' => 'the tool', 'file' => null, 'evidence' => false, 'evidence_note' => null, 'link' => null, 'auto' => true, 'status' => $m->status];
        usort($lines, fn ($a, $b) => strcmp($a['at'], $b['at']));
        return ['party' => $p, 'lines' => $lines, 'channels' => self::CHANNELS, 'evidence_on' => self::EVIDENCE_ON];
    }

    /** Pin / unpin a line as evidence on a record (the line itself is never changed). */
    public static function evidence(int $ent, int $lineId, ?string $table, $recId, bool $on, string $note = ''): string
    {
        $l = DB::table('comm_log')->where('id', $lineId)->where('fk_id_ent', $ent)->first() ?? throw new \InvalidArgumentException('Message not found.');
        if (! $on) { DB::table('comm_log')->where('id', $lineId)->update(['f_evidence' => 0, 'updated_at' => now()]); if ($l->link_table) Audit::log($l->link_table, (int) $l->link_id, 'UPDATE', ['evidence' => ['message #' . $lineId, null]], 'Evidence removed', $ent); return 'No longer shown as evidence (the message stays in the conversation).'; }
        [$lt, $li] = self::link($table ?: $l->link_table, $recId ?: $l->link_id); if (! $lt) throw new \InvalidArgumentException('Choose the record this is evidence for.');
        DB::table('comm_log')->where('id', $lineId)->update(['f_evidence' => 1, 'link_table' => $lt, 'link_id' => $li, 'evidence_note' => mb_substr(trim($note), 0, 255) ?: null, 'updated_at' => now()]);
        Audit::log($lt, $li, 'UPDATE', ['evidence' => [null, 'message #' . $lineId]], 'Evidence added', $ent);
        return 'Shown as evidence on ' . (self::EVIDENCE_ON[$lt]) . ' ' . self::code($lt, $li) . '.';
    }

    /** The messages about one record (its Messages card): evidence first. */
    public static function forRecord(int $ent, string $table, int $id): array
    {
        if (! isset(self::EVIDENCE_ON[$table])) return ['lines' => [], 'party' => null];
        $rows = DB::table('comm_log')->where('fk_id_ent', $ent)->where('link_table', $table)->where('link_id', $id)->orderByDesc('f_evidence')->orderByDesc('said_at')->limit(100)->get();
        $names = DB::table('t_users')->whereIn('id', $rows->pluck('user_id')->filter()->unique())->pluck('display_name', 'id')->all();
        $who = []; $lines = $rows->map(function ($l) use ($names, $ent, &$who) { $k = $l->party_type . ':' . $l->party_id; try { $who[$k] ??= self::party($ent, $l->party_type, (int) $l->party_id)['name']; } catch (\Throwable $e) { $who[$k] = '?'; } return self::line($l, $names) + ['with' => $who[$k], 'party_type' => $l->party_type, 'party_id' => (int) $l->party_id]; })->all();
        // who to talk to about this record
        $party = match ($table) { 't_src_suppliers' => ['SUPPLIER', $id], 't_account' => ['PERSON', $id], 't_src_forwarders' => ['FORWARDER', $id],
            't_src_po' => ($s = (int) DB::table('t_src_po')->where('id', $id)->value('fk_id_supplier')) ? ['SUPPLIER', $s] : null,
            't_src_payments' => ($s = (int) DB::table('t_src_po')->where('id', DB::table('t_src_payments')->where('id', $id)->value('fk_id_po'))->value('fk_id_supplier')) ? ['SUPPLIER', $s] : null, default => null };
        return ['lines' => $lines, 'party' => $party ? ['type' => $party[0], 'id' => $party[1]] : null, 'evidence' => count(array_filter($lines, fn ($l) => $l['evidence']))];
    }

    public static function addContact(int $ent, string $type, int $id, string $channel, string $address, string $label, int $user): string
    {
        self::party($ent, $type, $id); $channel = strtoupper($channel); if (! in_array($channel, ['WHATSAPP', 'WECHAT', 'SMS', 'CALL', 'EMAIL'], true)) throw new \InvalidArgumentException('Choose WhatsApp, WeChat, SMS, phone or e-mail.');
        $address = trim($address); if ($address === '' || mb_strlen($address) > 190) throw new \InvalidArgumentException('Enter the number, id or address.');
        if ($channel === 'EMAIL' && ! filter_var($address, FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('That is not an e-mail address.');
        if (in_array($channel, ['WHATSAPP', 'SMS', 'CALL'], true)) { if (strlen(self::digits($address)) < 7) throw new \InvalidArgumentException('Enter the number with its country code, e.g. +86 138 0000 0000.'); $address = '+' . preg_replace('/^00/', '', self::digits($address)); }
        DB::table('comm_contacts')->updateOrInsert(['fk_id_ent' => $ent, 'party_type' => $type, 'party_id' => $id, 'channel' => $channel, 'address' => $address], ['label' => mb_substr(trim($label), 0, 80) ?: null, 'f_active' => 1, 'created_by' => $user, 'updated_at' => now(), 'created_at' => now()]);
        return self::CHANNELS[$channel] . ' ' . $address . ' added.';
    }
}
