<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * MR-1266 / MR-1418 one Documents module: a document (invoice, receipt, folder, contract, photo …) is one row in sys_documents with a clear number
 * DOC-<company>-<yymm>-<n>, kept as a Drive / web link, and linked to any record through sys_document_links. Every record page has a Documents tab
 * (View in the panel, Open in Drive, Edit, New version, Remove, Add); an order shows the documents of all its lines; a PO / cycle also shows the
 * sourcing documents of its steps (DocRollup). Old versions stay (REPLACED); nothing is deleted (status DELETED).
 */
class Documents
{
    public const TYPES = ['Invoice', 'Receipt', 'Folder', 'Contract', 'Photo', 'Proof of delivery', 'Other'];

    private static function typeId(int $ent, string $name): int
    {
        $name = trim($name) ?: 'Other';
        $id = DB::table('cat_doc_types')->whereRaw('LOWER(doc_type_name) = ?', [mb_strtolower($name)])->where(fn ($q) => $q->where('fk_id_ent', $ent)->orWhere('fk_id_ent', 0))->value('id');
        return (int) ($id ?: DB::table('cat_doc_types')->insertGetId(RecordService::fillNotNull('cat_doc_types', ['fk_id_ent' => 0, 'doc_type_name' => mb_substr($name, 0, 60), 'f_active' => 1])));
    }

    private static function check(array $d): array
    {
        $title = trim((string) ($d['title'] ?? '')); $url = trim((string) ($d['drive_url'] ?? ''));
        if ($title === '') throw new \InvalidArgumentException('A document needs a title.');
        if ($url === '' || ! DocRollup::linkOk($url)) throw new \InvalidArgumentException('Paste the link of the document (it must start with https://).');
        if (($d['amount'] ?? '') !== '' && isset($d['amount']) && ! is_numeric($d['amount'])) throw new \InvalidArgumentException('Amount must be a number.');
        if (! empty($d['d_doc']) && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d['d_doc'])) throw new \InvalidArgumentException('Date must be a date.');
        return ['title' => mb_substr($title, 0, 200), 'drive_url' => mb_substr($url, 0, 500), 'ref_no' => mb_substr(trim((string) ($d['ref_no'] ?? '')), 0, 80) ?: null, 'd_doc' => $d['d_doc'] ?? null ?: null,
            'amount' => ($d['amount'] ?? '') === '' ? null : round((float) $d['amount'], 2), 'notes' => mb_substr(trim((string) ($d['notes'] ?? '')), 0, 1000) ?: null];
    }

    /** Add a document and link it to a record. */
    public static function add(int $ent, string $entity, int $entityId, array $d, object $u): array
    {
        $row = self::check($d);
        $id = (int) DB::table('sys_documents')->insertGetId(RecordService::fillNotNull('sys_documents', $row + ['fk_id_ent' => $ent, 'doc_no' => 'TMP-' . bin2hex(random_bytes(6)), 'fk_id_doc_type' => self::typeId($ent, (string) ($d['type'] ?? 'Other')),
            'version' => 1, 'status' => 'ACTIVE', 'uploaded_by' => $u->id, 'created_at' => now()]));
        DB::table('sys_documents')->where('id', $id)->update(['doc_no' => mb_substr(Numbering::next('DOC', $ent), 0, 30)]);
        DB::table('sys_document_links')->insert(['fk_id_document' => $id, 'entity' => mb_substr($entity, 0, 60), 'entity_id' => $entityId]);
        Audit::log('sys_documents', $id, 'INSERT', ['title' => [null, $row['title']], 'linked' => [null, "$entity #$entityId"]], null, $ent);
        return ['id' => $id, 'doc_no' => DB::table('sys_documents')->where('id', $id)->value('doc_no'), 'message' => 'Document added'];
    }

    public static function edit(int $ent, int $id, array $d): array
    {
        $old = DB::table('sys_documents')->where('id', $id)->where('fk_id_ent', $ent)->where('status', 'ACTIVE')->first() ?? throw new \InvalidArgumentException('Document not found.');
        $row = self::check($d + (array) $old); if (isset($d['type'])) $row['fk_id_doc_type'] = self::typeId($ent, (string) $d['type']);
        $diff = []; foreach ($row as $k => $v) if ((string) ($old->$k ?? '') !== (string) $v) $diff[$k] = [$old->$k ?? null, $v];
        DB::table('sys_documents')->where('id', $id)->update($row); if ($diff) Audit::log('sys_documents', $id, 'UPDATE', $diff, null, $ent);
        return ['message' => $diff ? 'Saved' : 'No change.'];
    }

    /** New version: a new row with version + 1 and the same links; the old one stays as REPLACED. */
    public static function version(int $ent, int $id, array $d, object $u): array
    {
        $old = DB::table('sys_documents')->where('id', $id)->where('fk_id_ent', $ent)->where('status', 'ACTIVE')->first() ?? throw new \InvalidArgumentException('Document not found.');
        $row = self::check($d + ['title' => $old->title, 'ref_no' => $old->ref_no, 'd_doc' => $old->d_doc, 'amount' => $old->amount, 'notes' => $old->notes]);
        $new = (int) DB::table('sys_documents')->insertGetId(RecordService::fillNotNull('sys_documents', $row + ['fk_id_ent' => $ent, 'doc_no' => mb_substr($old->doc_no . '-v' . ($old->version + 1), 0, 30), 'fk_id_doc_type' => $old->fk_id_doc_type,
            'version' => $old->version + 1, 'fk_id_previous' => $old->id, 'status' => 'ACTIVE', 'uploaded_by' => $u->id, 'created_at' => now()]));
        foreach (DB::table('sys_document_links')->where('fk_id_document', $id)->get() as $l) DB::table('sys_document_links')->insert(['fk_id_document' => $new, 'entity' => $l->entity, 'entity_id' => $l->entity_id]);
        DB::table('sys_documents')->where('id', $id)->update(['status' => 'REPLACED']);
        Audit::log('sys_documents', $new, 'INSERT', ['version' => [$old->version, $old->version + 1]], 'New version', $ent);
        return ['id' => $new, 'message' => 'New version ' . ($old->version + 1) . ' saved; the old one is kept.'];
    }

    public static function remove(int $ent, int $id): array
    {
        $n = DB::table('sys_documents')->where('id', $id)->where('fk_id_ent', $ent)->where('status', 'ACTIVE')->update(['status' => 'DELETED']);
        if (! $n) throw new \InvalidArgumentException('Document not found.');
        Audit::log('sys_documents', $id, 'DELETE', [], null, $ent); return ['message' => 'Removed (kept in the history)'];
    }

    /** Documents of a record; an order (sale line) shows those of every line of the same order; PO / cycle add the sourcing documents. */
    public static function forRecord(int $ent, string $entity, int $id): array
    {
        $pairs = [[$entity, [$id]]]; $title = null;
        if ($entity === (RecordService::formOfTable('t_sales') ?? 't_sales') || $entity === 't_sales') {
            $s = DB::table('t_sales')->where('id', $id)->where('fk_id_ent', $ent)->first(['order_no', 'fk_id_branch']);
            if ($s && $s->order_no) { $pairs = [[$entity, DB::table('t_sales')->where('fk_id_ent', $ent)->where('fk_id_branch', $s->fk_id_branch)->where('order_no', $s->order_no)->pluck('id')->map(fn ($x) => (int) $x)->all()]]; $title = 'Order ' . $s->order_no; }
        }
        $rows = [];
        foreach ($pairs as [$e, $ids]) foreach (DB::table('sys_document_links as l')->join('sys_documents as d', 'd.id', '=', 'l.fk_id_document')->leftJoin('cat_doc_types as t', 't.id', '=', 'd.fk_id_doc_type')->leftJoin('t_users as u', 'u.id', '=', 'd.uploaded_by')
            ->where('l.entity', $e)->whereIn('l.entity_id', $ids)->where('d.fk_id_ent', $ent)->where('d.status', 'ACTIVE')->orderByDesc('d.id')
            ->get(['d.id', 'd.doc_no', 'd.title', 'd.drive_url', 'd.ref_no', 'd.d_doc', 'd.amount', 'd.version', 'd.notes', 'd.created_at', 't.doc_type_name as type', 'u.display_name as by']) as $d)
            $rows[$d->id] = (array) $d + ['preview' => DocRollup::preview($d->drive_url), 'source' => 'document'];
        $out = array_values($rows);
        $tbl = Registry::table($entity) ?? $entity;
        try {
            if ($tbl === 't_src_po') foreach (DocRollup::forPo($ent, $id)['rows'] as $r) $out[] = ['id' => null, 'doc_no' => null, 'title' => $r['name'], 'type' => $r['type'], 'drive_url' => $r['url'], 'preview' => $r['preview'], 'source' => 'sourcing · ' . $r['linked_via']];
            if ($tbl === 't_src_cycles') foreach (DocRollup::forCycle($ent, $id)['rows'] as $r) $out[] = ['id' => null, 'doc_no' => null, 'title' => $r['name'], 'type' => $r['type'], 'drive_url' => $r['url'], 'preview' => $r['preview'], 'source' => 'sourcing · ' . $r['linked_via']];
        } catch (\Throwable $e) {}
        return ['rows' => $out, 'title' => $title, 'types' => array_values(array_unique(array_merge(self::TYPES, DB::table('cat_doc_types')->where('f_active', 1)->pluck('doc_type_name')->all())))];
    }
}
