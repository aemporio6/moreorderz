<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Owner 26-11 20:08 – MY TOOL connects to MoreOrderz directly (MY TOOL's docs/moreorderz-link.md, package format mytool-link-1 / moreorderz-link-1).
 * Nothing moves without permission: one master switch, a permission per kind and direction (ALWAYS / ASK / NEVER), a review inbox for ASK,
 * incoming deletions never applied (only listed), bank details refused unless the Owner allows them. The link key is shown once and kept only as a hash.
 */
class MyToolLink
{
    /** The kinds MoreOrderz takes in today: they become old purchases (MyToolImport). Others are kept in the inbox as "not used yet". */
    public const APPLY = ['purchases', 'parties', 'shipments', 'payments'];
    public const KINDS = ['purchases', 'shipments', 'payments', 'parties', 'inspections', 'receipts', 'moves', 'credits', 'products', 'tasks', 'salesStats'];
    private const BANK = ['bank', 'beneficiary', 'iban', 'swift', 'account', 'sortCode', 'accountNo', 'account_no', 'sort_code'];

    public static function settings(int $ent): array
    {
        $recv = []; $send = [];
        foreach (self::KINDS as $k) { $recv[$k] = (string) Settings::get("MYTOOL_RECV_$k", $ent, 'ASK'); $send[$k] = (string) Settings::get("MYTOOL_SEND_$k", $ent, 'NEVER'); }
        return ['on' => (bool) (int) Settings::get('MYTOOL_LINK_ON', $ent, 0), 'bank' => (bool) (int) Settings::get('MYTOOL_LINK_BANK', $ent, 0), 'recv' => $recv, 'send' => $send];
    }

    public static function save(int $ent, array $in, int $user): void
    {
        if (array_key_exists('on', $in)) Settings::set('MYTOOL_LINK_ON', $in['on'] ? 1 : 0, $ent, $user);
        if (array_key_exists('bank', $in)) Settings::set('MYTOOL_LINK_BANK', $in['bank'] ? 1 : 0, $ent, $user);
        foreach (['recv' => 'RECV', 'send' => 'SEND'] as $dir => $code) foreach ((array) ($in[$dir] ?? []) as $k => $v)
            if (in_array($k, self::KINDS, true) && in_array($v, ['ALWAYS', 'ASK', 'NEVER'], true)) Settings::set("MYTOOL_{$code}_$k", $v, $ent, $user);
        Audit::log('sys_settings', null, 'UPDATE', ['MYTOOL_LINK' => [null, json_encode($in)]], 'MY TOOL link settings', $ent);
    }

    /** A new key (the old ones stop at once). Returned once – only its hash is kept. */
    public static function makeKey(int $ent, int $user): string
    {
        DB::table('mo_link_keys')->where('fk_id_ent', $ent)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        $key = 'mol_' . bin2hex(random_bytes(24));
        DB::table('mo_link_keys')->insert(['fk_id_ent' => $ent, 'key_hash' => hash('sha256', $key), 'key_tail' => substr($key, -4), 'created_by' => $user, 'created_at' => now()]);
        Audit::log('mo_link_keys', null, 'INSERT', ['key' => [null, '…' . substr($key, -4)]], 'MY TOOL link key made', $ent);
        return $key;
    }

    public static function revoke(int $ent): int
    {
        $n = DB::table('mo_link_keys')->where('fk_id_ent', $ent)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        Audit::log('mo_link_keys', null, 'UPDATE', ['revoked' => [null, $n]], 'MY TOOL link key revoked', $ent);
        return $n;
    }

    /** The key of the request → its company and the person who made it; null when missing, wrong or revoked. */
    public static function auth(?string $bearer): ?object
    {
        if (! $bearer || ! str_starts_with($bearer, 'mol_')) return null;
        $k = DB::table('mo_link_keys')->where('key_hash', hash('sha256', $bearer))->whereNull('revoked_at')->first(); if (! $k) return null;
        DB::table('mo_link_keys')->where('id', $k->id)->update(['last_used_at' => now()]);
        return $k;
    }

    /** POST /api/mytool/receive – each item by its kind's permission: ALWAYS → applied, ASK → inbox, NEVER → refused. Deletions only listed. */
    public static function receive(object $key, array $pkg): array
    {
        $ent = (int) $key->fk_id_ent; $st = self::settings($ent);
        if (! $st['on']) abort(423, 'The MY TOOL link is switched off in MoreOrderz.');
        if (($pkg['format'] ?? '') !== 'mytool-link-1' || ! is_array($pkg['items'] ?? null)) abort(422, 'Not a mytool-link-1 package.');
        $ref = 'PKG-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(3)), 0, 6); $now = []; $queued = 0; $refused = 0; $ids = [];
        foreach ($pkg['items'] as $it) {
            $kind = (string) ($it['kind'] ?? ''); $id = mb_substr((string) ($it['id'] ?? ''), 0, 80); $op = ($it['op'] ?? 'upsert') === 'delete' ? 'delete' : 'upsert'; $data = (array) ($it['data'] ?? []);
            if (! $st['bank']) foreach (self::BANK as $b) unset($data[$b]);      // bank details only when the Owner allowed them
            $mode = $st['recv'][$kind] ?? 'NEVER'; $ids[] = $id;
            if ($mode === 'NEVER' || $id === '') { $refused++; continue; }
            $row = ['fk_id_ent' => $ent, 'package_ref' => $ref, 'kind' => mb_substr($kind, 0, 30), 'item_id' => $id, 'op' => $op, 'data_json' => json_encode($data), 'received_at' => now()];
            if ($op === 'delete') { DB::table('mo_link_inbox')->insert($row + ['status' => 'QUEUED', 'note' => 'A deletion in MY TOOL – never applied by itself; look and decide.']); $queued++; continue; }
            if ($mode === 'ALWAYS' && in_array($kind, self::APPLY, true)) { $now[] = DB::table('mo_link_inbox')->insertGetId($row + ['status' => 'QUEUED']); continue; }
            DB::table('mo_link_inbox')->insert($row + ['status' => 'QUEUED', 'note' => in_array($kind, self::APPLY, true) ? null : 'Kept here – MoreOrderz does not take "' . $kind . '" from MY TOOL yet.']); $queued++;
        }
        $applied = $now ? self::apply($ent, $now, (int) $key->created_by)['applied'] : 0;
        DB::table('mo_link_history')->insert(['fk_id_ent' => $ent, 'dir' => 'receive', 'how' => 'direct', 'n' => count($pkg['items']), 'applied' => $applied, 'queued' => $queued, 'refused' => $refused, 'ids' => mb_substr(implode(', ', $ids), 0, 4000), 'who' => mb_substr((string) ($pkg['sentBy'] ?? ''), 0, 120), 'at' => now()]);
        EventBus::fire('MYTOOL_RECEIVED', 'mo_link_inbox', null, ['applied' => $applied, 'queued' => $queued, 'refused' => $refused], $ent);
        return ['ok' => true, 'applied' => $applied, 'queued' => $queued, 'refused' => $refused];
    }

    /** Apply inbox rows (the Owner ticked them, or ALWAYS): the MY TOOL records of the latest version per id go through the MY TOOL import (same rules: the same old order is replaced by the MY TOOL one). */
    public static function apply(int $ent, array $inboxIds, int $userId): array
    {
        $rows = DB::table('mo_link_inbox')->where('fk_id_ent', $ent)->whereIn('id', $inboxIds ?: [0])->where('status', 'QUEUED')->orderBy('id')->get();
        $d = ['parties' => [], 'purchases' => [], 'shipments' => [], 'payments' => []]; $use = []; $skip = [];
        foreach ($rows as $r) {
            if ($r->op === 'delete' || ! in_array($r->kind, self::APPLY, true)) { $skip[] = $r->id; continue; }
            $x = json_decode((string) $r->data_json, true) ?: []; $x += ['no' => $r->item_id, 'code' => $r->item_id];
            $d[$r->kind][$r->item_id] = $x; $use[] = $r->id;
        }
        // a purchase's party must be known: take it from earlier packages when it came before
        foreach ($d['purchases'] + $d['shipments'] as $p) { $c = (string) ($p['party'] ?? ''); if ($c !== '' && ! isset($d['parties'][$c]) && ($old = DB::table('mo_link_inbox')->where('fk_id_ent', $ent)->where('kind', 'parties')->where('item_id', $c)->orderByDesc('id')->value('data_json'))) $d['parties'][$c] = (json_decode($old, true) ?: []) + ['code' => $c]; }
        $u = DB::table('t_users')->where('id', $userId)->first() ?? (object) ['id' => $userId];
        $res = $d['purchases'] || $d['shipments'] ? MyToolImport::importData($ent, $d, $u) : ['imported' => 0, 'already_in' => 0, 'failed' => [], 'log' => [], 'removed_old' => 0];
        DB::table('mo_link_inbox')->whereIn('id', $use ?: [0])->update(['status' => 'APPLIED', 'decided_at' => now(), 'decided_by' => $userId, 'note' => mb_substr($res['message'] ?? '', 0, 300)]);
        return ['applied' => count($use), 'not_used' => count($skip)] + $res;
    }

    public static function decline(int $ent, array $ids, int $userId): int
    {
        return DB::table('mo_link_inbox')->where('fk_id_ent', $ent)->whereIn('id', $ids ?: [0])->where('status', 'QUEUED')->update(['status' => 'DECLINED', 'decided_at' => now(), 'decided_by' => $userId]);
    }

    /** GET /api/mytool/send?since= – a moreorderz-link-1 package of what changed since then, only kinds whose "send" is not NEVER (purchases today). */
    public static function send(object $key, ?string $since): array
    {
        $ent = (int) $key->fk_id_ent; $st = self::settings($ent); if (! $st['on']) abort(423, 'The MY TOOL link is switched off in MoreOrderz.');
        $items = [];
        if (($st['send']['purchases'] ?? 'NEVER') !== 'NEVER') {
            $q = DB::table('t_src_po as p')->leftJoin('t_src_suppliers as s', 's.id', '=', 'p.fk_id_supplier')->leftJoin('cat_currency as c', 'c.id', '=', 'p.fk_id_currency')->where('p.fk_id_ent', $ent)->where('p.f_active', 1)
                ->when($since && strtotime($since), fn ($w) => $w->where(fn ($x) => $x->where('p.updated_at', '>=', date('Y-m-d H:i:s', strtotime($since)))->orWhere(fn ($y) => $y->whereNull('p.updated_at')->where('p.created_at', '>=', date('Y-m-d H:i:s', strtotime($since))))))
                ->limit(500)->get(['p.id', 'p.po_number', 'p.po_code', 'p.po_date', 'p.po_total', 's.supplier_code', 's.supplier_name', 'c.currency_code']);
            foreach ($q as $p) {
                $lines = DB::table('t_src_po_lines as l')->leftJoin('t_product_vendors as v', 'v.id', '=', 'l.fk_id_product')->where('l.fk_id_po', $p->id)->where('l.f_active', 1)->get(['v.SKU_VENDOR as sku', 'l.qty_ordered as q', 'l.unit_price as u', 'l.line_total as a']);
                $items[] = ['kind' => 'purchases', 'id' => (string) ($p->po_number ?: $p->po_code), 'op' => 'upsert', 'data' => ['party' => $p->supplier_code, 'partyName' => $p->supplier_name, 'date' => $p->po_date, 'cur' => $p->currency_code, 'total' => (float) $p->po_total,
                    'lines' => $lines->map(fn ($l) => ['sku' => $l->sku, 'q' => (float) $l->q, 'u' => (float) $l->u, 'a' => (float) $l->a])->all(), 'from' => 'MoreOrderz']];
            }
        }
        DB::table('mo_link_history')->insert(['fk_id_ent' => $ent, 'dir' => 'send', 'how' => 'direct', 'n' => count($items), 'ids' => mb_substr(implode(', ', array_column($items, 'id')), 0, 4000), 'who' => 'MY TOOL', 'at' => now()]);
        return ['format' => 'moreorderz-link-1', 'from' => 'MoreOrderz', 'company' => (string) DB::table('t_enterprise')->where('id', $ent)->value('ent_name'), 'sentAt' => now()->toIso8601String(), 'sentBy' => 'MoreOrderz', 'items' => $items];
    }

    public static function page(int $ent): array
    {
        $k = DB::table('mo_link_keys')->where('fk_id_ent', $ent)->whereNull('revoked_at')->orderByDesc('id')->first(['key_tail', 'created_at', 'last_used_at']);
        return self::settings($ent) + ['key' => $k, 'kinds' => self::KINDS, 'apply_kinds' => self::APPLY, 'address' => request()->getSchemeAndHttpHost(),
            'inbox' => DB::table('mo_link_inbox')->where('fk_id_ent', $ent)->where('status', 'QUEUED')->orderBy('id')->limit(500)->get(['id', 'package_ref', 'kind', 'item_id', 'op', 'data_json', 'note', 'received_at'])
                ->map(fn ($r) => ['id' => $r->id, 'package' => $r->package_ref, 'kind' => $r->kind, 'item' => $r->item_id, 'op' => $r->op, 'note' => $r->note, 'at' => $r->received_at, 'summary' => self::summary($r->kind, json_decode((string) $r->data_json, true) ?: [])]),
            'history' => DB::table('mo_link_history')->where('fk_id_ent', $ent)->orderByDesc('id')->limit(30)->get()];
    }

    private static function summary(string $kind, array $d): string
    {
        return trim(implode(' · ', array_filter([$d['date'] ?? null, $d['party'] ?? ($d['name'] ?? null), isset($d['cur']) ? $d['cur'] . ' ' . number_format((float) ($d['total'] ?? ($d['amount'] ?? array_sum(array_map(fn ($l) => (float) ($l['a'] ?? 0), (array) ($d['lines'] ?? []))))), 2) : null, $d['desc'] ?? ($d['p'] ?? null)])));
    }
}
