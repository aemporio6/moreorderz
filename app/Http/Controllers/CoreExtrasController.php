<?php

namespace App\Http\Controllers;

use App\Services\Context;
use App\Services\Perm;
use App\Services\ReturnAbuse;
use Illuminate\Http\Request;

/** Batch 7e-222 Core screens: buyer / return-abuse list (MR-1544). */
class CoreExtrasController extends Controller
{
    private function ent(): int { return (int) Context::ent(); }

    public function returnAbuse(Request $r)
    {
        Perm::need($r->user(), 'orders.saleRefund');
        $d = ReturnAbuse::scan($this->ent()); $see = Perm::has($r->user(), 'orders.dsSeeContact');
        foreach ($d['rows'] as &$x) { if (! $see) { $x['name'] = mb_substr($x['name'], 0, 1) . '•••'; $x['postcode'] = mb_substr($x['postcode'], 0, 3) . '•••'; } unset($x['key']); } unset($x);
        return $d + ['can_see_contact' => $see];
    }

    public function returnAbuseCheck(Request $r, int $sale) { Perm::need($r->user(), 'orders.SEE'); return ['warning' => ReturnAbuse::warning($this->ent(), $sale)]; }

    public function whatsNew(Request $r) { return \App\Services\Features::page($this->ent(), $r->user()); }      // MR-1542
    public function featureSet(Request $r)
    {
        try { \App\Services\Features::set($this->ent(), (string) $r->input('code'), $r->boolean('on'), $r->user()); return ['message' => 'Saved']; }
        catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); }
    }

    /** MR-1536 marketplace fee check. */
    public function feeCheck(Request $r)
    {
        Perm::need($r->user(), 'money.SEE'); $ym = preg_match('/^\d{4}-\d{2}$/', (string) $r->query('ym')) ? $r->query('ym') : date('Y-m', strtotime('first day of last month'));
        $d = \App\Services\FeeCheck::month($this->ent(), $ym, $r->query('shop') ? (int) $r->query('shop') : null);
        $d['shops'] = \Illuminate\Support\Facades\DB::table('t_branches')->where('fk_id_ent', $this->ent())->whereIn('id', array_unique(array_merge(array_column($d['rows'], 'shop'), $d['no_rate_shops'])) ?: [0])->pluck('shop_no', 'id');
        foreach ($d['rows'] as &$x) unset($x['line_ids']); unset($x);
        return $d;
    }

    public function feeProof(Request $r)
    {
        Perm::need($r->user(), 'money.SEE'); $ym = (string) $r->query('ym'); $shop = (int) $r->query('shop');
        abort_unless(preg_match('/^\d{4}-\d{2}$/', $ym) && $shop, 422, 'Month and shop are needed.');
        return response(\App\Services\FeeCheck::proof($this->ent(), $ym, $shop), 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => "attachment; filename=\"fee_overcharges_{$shop}_{$ym}.csv\""]);
    }

    /** MR-1262 the importer recommends where a file goes (reads only; nothing is saved). */
    public function importRecommend(Request $r)
    {
        $r->validate(['file' => 'required|file|max:20480']); $f = $r->file('file');
        try { return \App\Services\Importer::recommend($f->getRealPath(), (string) $f->getClientOriginalName()); }
        catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); }
    }

    public function sandbox(Request $r) { try { return \App\Services\Sandbox::create($r->user()); } catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); } }      // MR-1541

    /** MR-1266 / MR-1418 documents of any record. */
    private function docRight(Request $r, string $key, string $act): void { abort_unless(\App\Services\Registry::exists($key), 404, 'Unknown form.'); Perm::need($r->user(), "form:$key:$act"); }
    private function docRun(callable $fn) { try { return $fn(); } catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); } }
    public function docs(Request $r, string $key, int $id) { $this->docRight($r, $key, 'VIEW'); return \App\Services\Documents::forRecord($this->ent(), $key, $id); }
    public function docAdd(Request $r, string $key, int $id) { $this->docRight($r, $key, 'EDIT'); return $this->docRun(fn () => \App\Services\Documents::add($this->ent(), $key, $id, $r->all(), $r->user())); }
    private function docItemRight(Request $r, int $doc): void
    {
        $keys = \Illuminate\Support\Facades\DB::table('sys_document_links')->where('fk_id_document', $doc)->pluck('entity')->all(); abort_unless($keys, 404, 'Document not found.');
        foreach ($keys as $k) if (Perm::has($r->user(), "form:$k:EDIT")) return; abort(403, 'You may not change this document.');
    }
    public function docEdit(Request $r, int $doc) { $this->docItemRight($r, $doc); return $this->docRun(fn () => \App\Services\Documents::edit($this->ent(), $doc, $r->all())); }
    public function docVersion(Request $r, int $doc) { $this->docItemRight($r, $doc); return $this->docRun(fn () => \App\Services\Documents::version($this->ent(), $doc, $r->all(), $r->user())); }
    public function docRemove(Request $r, int $doc) { $this->docItemRight($r, $doc); return $this->docRun(fn () => \App\Services\Documents::remove($this->ent(), $doc)); }

    /** MR-1539 where a record came from (file + row, old tool row, GRN / PO, journals). */
    public function lineage(Request $r, string $key, int $id) { abort_unless(\App\Services\Registry::exists($key), 404, 'Unknown form.'); Perm::need($r->user(), "form:$key:VIEW"); return \App\Services\Lineage::of((string) \App\Services\Registry::table($key), $id, $this->ent()); }

    /** Owner 26-11: MY TOOL purchase history into Old purchases – check (writes nothing), then import (Owner). */
    public function mytool(Request $r)
    {
        abort_unless(($r->user()->role_code ?? '') === 'OWNER', 403, 'Only the Owner loads the MY TOOL data.');
        $r->validate(['file' => 'required|file|max:30720']); $json = (string) file_get_contents($r->file('file')->getRealPath());
        try { return $r->boolean('apply') ? \App\Services\MyToolImport::import($this->ent(), $json, $r->user()) : \App\Services\MyToolImport::check($this->ent(), $json); }
        catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); }
    }
}
