<?php

namespace App\Http\Controllers;

use App\Services\PresetGroups as G;
use Illuminate\Http\Request;

/** R-D-62 preset groups: list, form, star, sort, copy, delete, history. */
class PresetGroupController extends Controller
{
    private function run(callable $fn) { try { $r = $fn(); return is_array($r) ? $r : ['message' => $r]; } catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); } }

    public function index(Request $r) { return $this->run(fn () => G::list($r->query(), $r->user())); }
    public function store(Request $r) { return $this->run(fn () => G::save($r->all(), $r->user())); }
    public function update(Request $r, int $id) { return $this->run(fn () => G::save($r->all(), $r->user(), $id)); }
    public function star(Request $r, int $id) { return $this->run(fn () => G::star($id, $r->user())); }
    public function sort(Request $r) { return $this->run(fn () => G::sort((array) $r->input('ids', []), (bool) $r->input('filtered'), $r->user())); }
    public function copy(Request $r, int $id) { return $this->run(fn () => G::copy($id, $r->user())); }
    public function destroy(Request $r, int $id) { return $this->run(fn () => G::delete($id, $r->user())); }
    /** R-D-63 combination browser + add; R-D-65 the carrier dropdown of every form; R-D-66 old virtual preset ids (Owner). */
    public function combos(Request $r, int $id) { return $this->run(fn () => \App\Services\CarrierPresets::combos($id, $r->query(), $r->user())); }
    public function addPresets(Request $r, int $id) { return $this->run(fn () => \App\Services\CarrierPresets::add($id, (array) $r->input('items', []), $r->user())); }
    public function dropdown(Request $r) { \App\Services\Perm::need($r->user(), 'orders.SEE'); $d = \App\Services\CarrierPresets::dropdown((int) $r->query('marketplace') ?: null, (int) $r->query('shop') ?: null, (int) $r->query('current') ?: null);
        if ($r->boolean('pick')) { $ent = (int) \App\Services\Context::ent();      // names for the preview – nobody types an id
            $d['pick'] = ['marketplaces' => \Illuminate\Support\Facades\DB::table('cat_marketplaces')->where('fk_id_ent', $ent)->where('f_active', 1)->orderBy('marketplace_short')->get(['id', 'marketplace_short as name']),
                'shops' => \Illuminate\Support\Facades\DB::table('t_branches')->where('fk_id_ent', $ent)->where('f_active', 1)->orderBy('shop_no')->get(['id', \Illuminate\Support\Facades\DB::raw("TRIM(CONCAT(shop_no, ' ', COALESCE(shop_name, ''))) as name")])]; }
        return $d; }
    public function review(Request $r) { return $this->run(fn () => ['rows' => \App\Services\CarrierPresets::reviewList($r->user())]); }
    public function resolve(Request $r, int $id) { return $this->run(fn () => \App\Services\CarrierPresets::resolve($id, (int) $r->input('preset'), $r->user())); }
    public function history(Request $r, int $id) { return $this->run(fn () => ['rows' => G::history($id, $r->user())]); }
}
