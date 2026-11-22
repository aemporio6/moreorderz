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
    public function history(Request $r, int $id) { return $this->run(fn () => ['rows' => G::history($id, $r->user())]); }
}
