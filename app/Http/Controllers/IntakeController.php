<?php

namespace App\Http\Controllers;

use App\Services\Context;
use App\Services\Intake;
use App\Services\Perm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Owner 26-11 22:28: the one importer – take anything in, list it, do what was chosen, serve the kept file. */
class IntakeController extends Controller
{
    private function ent(): int { return (int) Context::ent(); }
    private function run(callable $fn) { try { return $fn(); } catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); } }
    private function may(Request $r): void { $u = $r->user(); abort_unless(Perm::has($u, 'buying.SEE') || Perm::has($u, 'money.SEE') || Perm::has($u, 'orders.SEE') || Perm::has($u, 'stock.SEE'), 403, 'You do not have the right to import – ask the Owner.'); }

    public function index(Request $r) { $this->may($r); return Intake::list($this->ent(), strtoupper((string) $r->query('status', 'NEW')), $r->filled('ctx_entity') ? ['entity' => (string) $r->query('ctx_entity'), 'id' => (int) $r->query('ctx_id')] : null); }

    /** files[] (with texts[i] = what the browser read, readers[i]), or text (a pasted table / words), or link. */
    public function take(Request $r)
    {
        $this->may($r); $u = $r->user(); $ent = $this->ent(); $out = []; $err = [];
        $ctx = $r->filled('ctx_entity') ? ['entity' => (string) $r->input('ctx_entity'), 'id' => (int) $r->input('ctx_id')] : null;      // filed from a record
        foreach (array_values((array) $r->file('files', [])) as $i => $f) {
            try { $out[] = Intake::take($ent, $u, (string) file_get_contents($f->getRealPath()), (string) $f->getClientOriginalName(), (string) ($r->input("sources.$i") ?: 'UPLOAD'), null, $r->input("texts.$i"), $r->input("readers.$i"), $ctx); }
            catch (\InvalidArgumentException $e) { $err[] = $e->getMessage(); }
        }
        if (trim((string) $r->input('text', '')) !== '') { $t = (string) $r->input('text'); try { $out[] = Intake::take($ent, $u, $t, 'pasted ' . date('Y-m-d H.i') . (preg_match('/\t/', $t) ? '.tsv' : '.txt'), 'PASTE', null, $t, 'pasted', $ctx); } catch (\InvalidArgumentException $e) { $err[] = $e->getMessage(); } }
        if (trim((string) $r->input('link', '')) !== '') { try { foreach (Intake::fromLink($ent, $u, (string) $r->input('link')) as $x) { if (isset($x['error'])) $err[] = $x['error']; else $out[] = $x; } } catch (\InvalidArgumentException $e) { $err[] = $e->getMessage(); } }
        if (! $out && $err) return response()->json(['message' => implode(' ', $err)], 422);
        return ['items' => $out, 'errors' => $err];
    }

    public function decide(Request $r, int $id) { $this->may($r); return $this->run(fn () => Intake::decide($this->ent(), $id, $r->only(['action', 'entity', 'entity_id', 'category', 'screen', 'kind', 'marketplace']), $r->user())); }

    public function again(Request $r, int $id) { $this->may($r); abort_unless(DB::table('mo_intake')->where('id', $id)->where('fk_id_ent', $this->ent())->exists(), 404);
        if ($r->filled('text')) DB::table('mo_intake')->where('id', $id)->update(['text' => mb_substr((string) $r->input('text'), 0, 200000), 'read_by' => mb_substr((string) $r->input('reader', 'browser'), 0, 40)]);
        return Intake::analyse($id); }

    public function file(Request $r, int $id)
    {
        $this->may($r); $x = DB::table('mo_intake')->where('id', $id)->where('fk_id_ent', $this->ent())->first(); abort_unless($x && $x->path && Storage::disk('local')->exists($x->path), 404);
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $x->name);
        return response(Storage::disk('local')->get($x->path), 200, ['Content-Type' => $x->mime ?: 'application/octet-stream', 'Content-Disposition' => ($r->query('download') ? 'attachment' : 'inline') . '; filename="' . $safe . '"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "sandbox; default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'"]);
    }
}
