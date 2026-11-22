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
    public function listingBatches(Request $r) { return ['rows' => DB::table('bulk_jobs')->where('list_key', 'listing_import')->orderByDesc('id')->limit(30)->get(['id', 'job_no', 'status', 'row_count', 'created_at', 'change_json'])->map(fn ($j) => ['id' => (int) $j->id, 'no' => $j->job_no, 'status' => $j->status, 'changes' => (int) $j->row_count, 'at' => (string) $j->created_at,
        'shop' => DB::table('t_branches')->where('id', json_decode((string) $j->change_json, true)['shop'] ?? 0)->value('shop_no')])->all()]; }
}
