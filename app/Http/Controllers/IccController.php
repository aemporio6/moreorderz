<?php

namespace App\Http\Controllers;

use App\Services\Context;
use App\Services\Icc;
use App\Services\Perm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** 07_ICC – Inventory Cycle Calculator screens: #/icc (every product) and #/icc/<product> (one product, inputs + result). */
class IccController extends Controller
{
    private function product(int $id): object
    {
        $p = DB::table('t_product_vendors')->where('id', $id)->first(['id', 'fk_id_ent']);
        abort_if(! $p || (int) $p->fk_id_ent !== (int) Context::ent(), 404, 'Product not found.');
        return $p;
    }

    public function board(Request $r) { Perm::need($r->user(), 'stock.SEE'); $s = strtoupper((string) $r->query('status')); return Icc::board((int) Context::ent(), in_array($s, ['RED', 'YELLOW', 'GREEN', 'NONE'], true) ? $s : null); }

    /** GET = work it out without saving (a "what if" with ?mode=AIR etc.); the stored result is only written by POST or the nightly run. */
    public function show(Request $r, int $id)
    {
        Perm::need($r->user(), 'stock.SEE'); $this->product($id);
        $over = array_filter(['ship_mode' => $r->query('mode'), 'monthly_sales' => $r->query('monthly')], fn ($v) => $v !== null && $v !== '');
        try { $x = Icc::run($id, $over, false); } catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); }
        $saved = (array) (DB::table('icc_inputs')->where('fk_id_product', $id)->first() ?? []);
        $in = $x['inputs']; unset($in['holidays'], $in['rates']);
        return ['inputs' => $in, 'saved' => $saved, 'result' => $x['result'], 'modes' => array_keys(Icc::MODES), 'can_change' => Perm::has($r->user(), 'stock.CHANGE'),
            'open_pos' => DB::table('t_src_po')->whereIn('id', $x['inputs']['pipeline']['po_ids'] ?: [0])->get(['id', 'po_code', 'status', 'required_ship_date'])];
    }

    /** Save the inputs (only the ones sent) and work the product out again – result, product chip, event and task. */
    public function save(Request $r, int $id)
    {
        Perm::need($r->user(), 'stock.CHANGE'); $this->product($id);
        try { Icc::saveInputs($id, (array) $r->input('inputs', []), (int) $r->user()->id); $x = Icc::run($id); }
        catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); }
        return ['ok' => true, 'status' => $x['result']['status'], 'result' => $x['result']];
    }
}
