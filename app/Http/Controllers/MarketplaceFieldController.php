<?php

namespace App\Http\Controllers;

use App\Services\Context;
use App\Services\MarketplaceFields as F;
use App\Services\Perm;
use App\Services\ValidationErrors;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Marketplace field builder and product values (R-A_SALES-67). */
class MarketplaceFieldController extends Controller
{
    private function run(callable $fn)
    {
        try { return $fn(); }
        catch (ValidationErrors $e) { return response()->json(['message' => $e->getMessage(), 'errors' => array_map(fn ($m) => [$m], $e->errors)], 422); }
        catch (\Illuminate\Database\QueryException $e) { report($e); return response()->json(['message' => 'Something went wrong on our side. Nothing was saved. The details are in the system log.'], 500); }
        catch (\InvalidArgumentException|\RuntimeException $e) { return response()->json(['message' => $e->getMessage(), 'errors' => ['_form' => [$e->getMessage()]]], 422); }
    }

    public function index(Request $r)
    {
        Perm::need($r->user(), 'products.marketplaceValues');
        $mps = DB::table('cat_marketplaces')->where('fk_id_ent', Context::ent())->where('f_active', 1)->orderBy('id')->get(['id', DB::raw("COALESCE(NULLIF(marketplace_short,''), marketplace_descr) as name")]);
        $mp = (int) ($r->input('mp') ?: ($mps[0]->id ?? 0));
        return ['marketplaces' => $mps, 'mp' => $mp, 'fields' => $mp ? F::list($mp, $r->boolean('deleted')) : [], 'types' => F::TYPES, 'can_edit' => Perm::has($r->user(), 'products.marketplaceFields')];
    }

    public function name(Request $r)
    {
        Perm::need($r->user(), 'products.marketplaceFields');
        if ($r->filled('id')) return ['name' => (string) DB::table('cat_marketplace_fields')->where('id', (int) $r->input('id'))->value('field_name'), 'fixed' => true];
        return ['name' => trim((string) $r->input('label')) === '' ? '' : F::nameFor((int) $r->input('mp'), (string) $r->input('label')), 'fixed' => false];
    }

    public function save(Request $r, ?int $id = null)
    {
        Perm::need($r->user(), 'products.marketplaceFields');
        return $this->run(fn () => F::save($r->only(['fk_id_marketplace', 'field_label', 'field_type', 'field_options', 'f_required', 'sort_order']), $id));
    }

    public function delete(Request $r, int $id) { Perm::need($r->user(), 'products.marketplaceFields'); return $this->run(fn () => F::delete($id)); }

    public function product(Request $r, int $id) { Perm::need($r->user(), 'products.marketplaceValues'); return F::product($id) + ['can_edit' => Perm::has($r->user(), 'products.marketplaceValues')]; }

    public function saveProduct(Request $r, int $id, int $mp)
    {
        Perm::need($r->user(), 'products.marketplaceValues');
        $files = []; foreach ((array) $r->file('files', []) as $k => $f) if ($f) $files[(int) $k] = $f;
        return $this->run(fn () => F::saveValues($id, $mp, (array) $r->input('values', []), $files));
    }
}
