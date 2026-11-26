<?php

namespace App\Http\Controllers;

use App\Services\Context;
use App\Services\MyToolLink;
use Illuminate\Http\Request;

/** Owner 26-11 20:08: the three doors MY TOOL calls (Bearer link key, no session) + the Owner's screen. MY TOOL runs in a browser on another address, so the doors answer CORS. */
class MyToolLinkController extends Controller
{
    private function cors($res)
    {
        $res->headers->set('Access-Control-Allow-Origin', '*'); $res->headers->set('Access-Control-Allow-Headers', 'Authorization, Content-Type'); $res->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS'); $res->headers->set('Access-Control-Max-Age', '600');
        return $res;
    }

    private function key(Request $r): object
    {
        $k = MyToolLink::auth($r->bearerToken());
        if (! $k) abort($this->cors(response()->json(['ok' => false, 'message' => 'Wrong or revoked link key – make a new one in MoreOrderz › Setup › MY TOOL link.'], 401)));
        return $k;
    }

    public function preflight() { return $this->cors(response('', 204)); }

    public function hello(Request $r)
    {
        $k = $this->key($r); $st = MyToolLink::settings((int) $k->fk_id_ent);
        return $this->cors(response()->json(['ok' => true, 'company' => (string) \DB::table('t_enterprise')->where('id', $k->fk_id_ent)->value('ent_name'), 'link_on' => $st['on']]));
    }

    public function receive(Request $r)
    {
        $k = $this->key($r);
        try { return $this->cors(response()->json(MyToolLink::receive($k, (array) $r->json()->all()))); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { return $this->cors(response()->json(['ok' => false, 'message' => $e->getMessage()], $e->getStatusCode())); }
    }

    public function send(Request $r)
    {
        $k = $this->key($r);
        try { return $this->cors(response()->json(MyToolLink::send($k, $r->query('since')))); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { return $this->cors(response()->json(['ok' => false, 'message' => $e->getMessage()], $e->getStatusCode())); }
    }

    // ---- the Owner's screen ----
    private function owner(Request $r): void { abort_unless(($r->user()->role_code ?? '') === 'OWNER', 403, 'Only the Owner sets up the MY TOOL link.'); }

    public function page(Request $r) { $this->owner($r); return MyToolLink::page((int) Context::ent()); }

    public function saveSettings(Request $r) { $this->owner($r); MyToolLink::save((int) Context::ent(), $r->only(['on', 'bank', 'recv', 'send']), (int) $r->user()->id); return ['message' => 'Saved'] + MyToolLink::page((int) Context::ent()); }

    public function makeKey(Request $r) { $this->owner($r); return ['key' => MyToolLink::makeKey((int) Context::ent(), (int) $r->user()->id), 'message' => 'Copy the key now – it is shown only this once. Any older key stopped working.']; }

    public function revoke(Request $r) { $this->owner($r); return ['revoked' => MyToolLink::revoke((int) Context::ent()), 'message' => 'Key revoked – MY TOOL can no longer reach MoreOrderz until a new key is made.']; }

    public function apply(Request $r)
    {
        $this->owner($r); $d = $r->validate(['ids' => 'required|array', 'ids.*' => 'integer', 'decline' => 'boolean']);
        if (! empty($d['decline'])) return ['message' => MyToolLink::decline((int) Context::ent(), $d['ids'], (int) $r->user()->id) . ' declined – nothing applied.'];
        $x = MyToolLink::apply((int) Context::ent(), $d['ids'], (int) $r->user()->id);
        return $x + ['message' => ($x['message'] ?? ($x['applied'] . ' applied')) . ($x['not_used'] ? " · {$x['not_used']} kept (deletions / kinds not taken yet)" : '')];
    }
}
