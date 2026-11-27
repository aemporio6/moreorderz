<?php

namespace App\Http\Controllers;

use App\Services\Contacts;
use App\Services\Context;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Owner 27-11: Contacts & chat – the contact list, one conversation per contact, files and voice, evidence on records. Any signed-in person of the company. */
class ContactsController extends Controller
{
    private function ent(): int { return (int) Context::ent(); }
    private function run(callable $fn) { try { return $fn(); } catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); } }
    private const FORM = ['SUPPLIER' => 't_src_suppliers', 'FORWARDER' => 't_src_forwarders', 'PERSON' => 't_account'];
    /** A conversation is seen by who may see that kind of record (the same right as its list). */
    private function may(Request $r, string $type): bool { return \App\Services\Perm::has($r->user(), 'form:' . (self::FORM[$type] ?? 'x') . ':VIEW'); }
    private function type(string $t): string { $t = strtoupper($t); abort_unless($this->may(request(), $t), 403, 'Your role cannot see these contacts. Ask the Owner if you need it.'); return $t; }

    public function index(Request $r)
    {
        $d = Contacts::list($this->ent(), (string) $r->query('q', ''), strtoupper((string) $r->query('kind', '')));
        $d['rows'] = array_values(array_filter($d['rows'], fn ($x) => $this->may($r, $x['type']))); $d['kinds'] = array_filter($d['kinds'], fn ($k) => $this->may($r, $k), ARRAY_FILTER_USE_KEY);
        return $d;
    }
    public function thread(Request $r, string $type, int $id) { return $this->run(fn () => Contacts::thread($this->ent(), $this->type($type), $id)); }
    public function say(Request $r, string $type, int $id) { return $this->run(fn () => Contacts::say($this->ent(), $this->type($type), $id, $r->only(['channel', 'direction', 'text', 'address', 'link_table', 'link_id', 'evidence', 'evidence_note', 'said_at']), $r->file('file'), $r->user())); }
    public function addContact(Request $r, string $type, int $id) { return $this->run(fn () => ['message' => Contacts::addContact($this->ent(), $this->type($type), $id, (string) $r->input('channel'), (string) $r->input('address'), (string) $r->input('label', ''), (int) $r->user()->id)]); }
    public function evidence(Request $r, int $line) { $this->type((string) DB::table('comm_log')->where('id', $line)->value('party_type')); return $this->run(fn () => ['message' => Contacts::evidence($this->ent(), $line, $r->input('link_table'), $r->input('link_id'), $r->boolean('on', true), (string) $r->input('note', ''))]); }
    public function forRecord(Request $r, string $table, int $id) { $table = isset(Contacts::EVIDENCE_ON[$table]) ? $table : (string) (\App\Services\Registry::exists($table) ? \App\Services\Registry::table($table) : $table); $d = Contacts::forRecord($this->ent(), $table, $id); $d['table'] = $table; $d['lines'] = array_values(array_filter($d['lines'], fn ($l) => $this->may($r, $l['party_type']))); if ($d['party'] && ! $this->may($r, $d['party']['type'])) $d['party'] = null; return $d; }

    public function file(Request $r, int $line)
    {
        $l = DB::table('comm_log')->where('id', $line)->where('fk_id_ent', $this->ent())->first(); if ($l) $this->type($l->party_type); abort_unless($l && $l->file_path && Storage::disk('local')->exists($l->file_path), 404);
        $inline = (bool) preg_match('~^(image/(png|jpe?g|gif|webp)|audio/|video/|application/pdf)~', (string) $l->file_mime) && ! $r->query('download');
        return response(Storage::disk('local')->get($l->file_path), 200, ['Content-Type' => $inline ? $l->file_mime : 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => ($inline ? 'inline' : 'attachment') . '; filename="' . preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $l->file_name) . '"']);
    }
}
