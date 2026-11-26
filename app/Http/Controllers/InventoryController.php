<?php

namespace App\Http\Controllers;

use App\Services\Context;
use App\Services\InventoryHub;
use App\Services\Perm;
use Illuminate\Http\Request;

/** Owner 26-11 23:14: the Inventory screen and a product's story (reads only). */
class InventoryController extends Controller
{
    public function index(Request $r) { Perm::need($r->user(), 'stock.SEE'); return InventoryHub::page((int) Context::ent(), $r->user(), $r->only(['q', 'shop', 'marketplace', 'mode', 'owner', 'state', 'children', 'sort', 'page'])); }

    public function story(Request $r, int $id)
    {
        Perm::need($r->user(), 'stock.SEE');
        try { return InventoryHub::story((int) Context::ent(), $r->user(), $id); } catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 403); }
    }
}
