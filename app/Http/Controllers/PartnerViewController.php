<?php

namespace App\Http\Controllers;

use App\Services\Context;
use App\Services\InvoiceReports;
use App\Services\PartnerView;
use Illuminate\Http\Request;

/** Owner 26-11 23:19: each partner's whole account – list and one partner (a partner login: only his own). */
class PartnerViewController extends Controller
{
    private function hide(Request $r, array $x): array { if (! InvoiceReports::canMargin((int) Context::ent(), $r->user())) unset($x['stock_cost']); return $x; }

    public function index(Request $r)
    {
        try { $d = PartnerView::list((int) Context::ent(), $r->user()); } catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 403); }
        $d['rows'] = array_map(fn ($x) => $this->hide($r, $x), $d['rows']); return $d;
    }

    public function show(Request $r, int $acc)
    {
        try { $d = PartnerView::one((int) Context::ent(), $r->user(), $acc); } catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 403); }
        $d['summary'] = $this->hide($r, $d['summary']);
        if (! $d['margin']) foreach ($d['products'] as &$p) { unset($p['value'], $p['cost']); foreach ($p['owners'] as &$o) unset($o['value']); }
        return $d;
    }
}
