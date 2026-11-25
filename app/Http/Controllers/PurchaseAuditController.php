<?php

namespace App\Http\Controllers;

use App\Services\PurchaseAudit as A;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** R-A_SALES-60 … 64 the monthly purchase audit of a shop. */
class PurchaseAuditController extends Controller
{
    private function run(callable $fn) { try { $r = $fn(); return is_array($r) ? $r : ['message' => $r]; } catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); } }
    private function text(Request $r): string { if (! $r->hasFile('file')) throw new \InvalidArgumentException('Choose the file.'); return (string) file_get_contents($r->file('file')->getRealPath()); }

    public function index(Request $r) { return A::runs($r->user()); }
    public function create(Request $r) { return $this->run(fn () => ['id' => A::createRun((int) $r->input('shop'), (string) $r->input('month'), $r->user()), 'message' => 'Run ready.']); }
    public function show(Request $r, int $id)
    {
        return $this->run(function () use ($r, $id) {
            $run = DB::table('t_audit_main as a')->join('t_branches as b', 'b.id', '=', 'a.fk_id_branch')->where('a.id', $id)->first(['a.*', 'b.shop_no', 'b.shop_name']) ?? throw new \InvalidArgumentException('Audit run not found.');
            $vendors = DB::table('t_vendors')->where('f_active', 1)->whereIn('audit_profile', ['AMAZON', 'GD'])->orderBy('vendor_name')->get(['id', 'vendor_name', 'audit_profile']);
            $v = (int) $r->query('vendor', 0); $prof = $v ? $vendors->firstWhere('id', $v)?->audit_profile : null;
            return ['run' => ['id' => (int) $run->id, 'shop' => trim($run->shop_no . ' ' . $run->shop_name), 'shop_no' => $run->shop_no, 'month' => substr((string) $run->d_yr_mon, 0, 7), 'status' => $run->e_audit_status],
                'invalid' => A::invalidNotes($id, $r->user()), 'compare' => A::compare($id, $r->user()), 'vendors' => $vendors,
                'vendor' => $v, 'amazon' => $prof === 'AMAZON' ? A::amazon($id, $v, $r->user()) : null, 'gd' => $prof === 'GD' ? A::gd($id, $v, $r->user()) : null];
        });
    }
    public function ebay(Request $r, int $id) { return $this->run(fn () => A::importEbay($id, (string) $r->file('file')?->getClientOriginalName(), $this->text($r), $r->user())); }
    public function supplier(Request $r, int $id, int $vendor)
    {
        return $this->run(fn () => DB::table('t_vendors')->where('id', $vendor)->value('audit_profile') === 'GD' ? A::uploadGd($id, $vendor, $this->text($r), $r->user()) : A::uploadAmazon($id, $vendor, $this->text($r), $r->user()));
    }
    public function accept(Request $r, int $id, int $sale) { return $this->run(fn () => A::acceptFile($id, $sale, $r->user())); }
    public function profile(Request $r, int $vendor)
    {
        \App\Services\Perm::need($r->user(), 'orders.CONFIRM'); $p = strtoupper((string) $r->input('profile'));
        if (! in_array($p, ['NONE', 'AMAZON', 'GD'], true)) return response()->json(['message' => 'Choose None, Amazon or GD.'], 422);
        DB::table('t_vendors')->where('id', $vendor)->update(['audit_profile' => $p]); \App\Services\Audit::log('t_vendors', $vendor, 'UPDATE', ['audit_profile' => $p], 'purchase audit profile');
        return ['message' => 'Saved.'];
    }
    public function suppliers(Request $r) { \App\Services\Perm::need($r->user(), 'orders.SEE'); return ['rows' => DB::table('t_vendors')->where('f_active', 1)->orderBy('vendor_name')->limit(500)->get(['id', 'vendor_name', 'iso_vendor', 'audit_profile'])]; }

    /* R-D-59 eBay active listings per shop */
    public function listings(Request $r) { return $this->run(fn () => \App\Services\ListingsImport::import((string) $r->file('file')?->getClientOriginalName(), $this->text($r), $r->user())); }
    public function listingsUndo(Request $r, int $job) { return $this->run(fn () => \App\Services\ListingsImport::undo($job, $r->user())); }
    public function listingBatches(Request $r) { $sh = \App\Services\Perm::shops($r->user()); return ['shops' => DB::table('t_branches')->where('fk_id_ent', (int) \App\Services\Context::ent())->where('f_active', 1)->when($sh !== null, fn ($q) => $q->whereIn('id', $sh ?: [0]))->orderBy('shop_no')->get(['id', 'shop_no', 'shop_name']), 'rows' => DB::table('bulk_jobs')->where('list_key', 'listing_import')->orderByDesc('id')->limit(30)->get(['id', 'job_no', 'status', 'row_count', 'created_at', 'change_json'])->map(fn ($j) => ['id' => (int) $j->id, 'no' => $j->job_no, 'status' => $j->status, 'changes' => (int) $j->row_count, 'at' => (string) $j->created_at,
        'shop' => DB::table('t_branches')->where('id', json_decode((string) $j->change_json, true)['shop'] ?? 0)->value('shop_no')])->all()]; }

    /* R-H-20 stock quantity per listing (outbound) */
    private function shopOk(Request $r): int { \App\Services\Perm::need($r->user(), 'orders.SEE'); $s = (int) $r->query('shop'); if (! \App\Services\Perm::shopAllowed($r->user(), $s)) abort(403, ' User has no rights for that shop'); return $s; }
    public function listingQty(Request $r) { $s = $this->shopOk($r); return \App\Services\ListingQty::rows((int) \App\Services\Context::ent(), $s) + ['shops' => DB::table('t_branches')->where('fk_id_ent', (int) \App\Services\Context::ent())->where('f_active', 1)->orderBy('shop_no')->get(['id', 'shop_no', 'shop_name'])]; }
    /* R-H-20 the waiting 0-quantity rows: GET = preview (never writes), POST = the file, marked exported */
    public function listingZero(Request $r) { $s = $this->shopOk($r); return ['waiting' => DB::table('stock_push_queue')->where('fk_id_branch', $s)->where('status', 'PENDING')->count(), 'csv' => \App\Services\ListingQty::zeroCsv((int) \App\Services\Context::ent(), $s, true)]; }
    public function listingZeroCsv(Request $r) { $s = $this->shopOk($r); \App\Services\Perm::need($r->user(), 'orders.ENTER'); return response(\App\Services\ListingQty::zeroCsv((int) \App\Services\Context::ent(), $s), 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="listing_zero_' . $s . '.csv"']); }
    public function listingQtyCsv(Request $r) { $s = $this->shopOk($r); return response(\App\Services\ListingQty::csv((int) \App\Services\Context::ent(), $s), 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="listing_quantities_' . $s . '.csv"']); }
}
