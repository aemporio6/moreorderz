<?php

namespace App\Http\Controllers;

use App\Services\Connect\Connections;
use App\Services\Connect\DriveFolders;
use App\Services\Connect\Hubs;
use App\Services\Connect\Messenger;
use App\Services\Context;
use App\Services\Subscriptions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Outside connections (15.5), marketplace hubs (6.4), messages (13.6), Drive folders (14.2), subscriptions (12.7), WhatsApp webhook. */
class ConnectController extends Controller
{
    private function can(Request $r, array $roles): void
    {
        abort_unless(in_array($r->user()->role_code, $roles, true), 403, 'Your role cannot do this here. Ask the Owner if you need it.');
    }

    private function run(callable $fn)
    {
        try { return $fn(); }
        catch (\Illuminate\Database\QueryException $e) { report($e); return response()->json(['message' => 'Something went wrong on our side. Nothing was saved. The details are in the system log.'], 500); }
        catch (\InvalidArgumentException|\RuntimeException $e) { return response()->json(['message' => $e->getMessage(), 'errors' => ['_form' => [$e->getMessage()]]], 422); }
    }

    public function connections(Request $r)
    {
        $this->can($r, ['OWNER', 'MANAGER']);
        $ent = (int) Context::ent();
        $rows = DB::table('api_connections as c')->leftJoin('t_branches as b', 'b.id', '=', 'c.fk_id_branch')->leftJoin('t_carriers as k', 'k.id', '=', 'c.fk_id_carrier')
            ->whereIn('c.fk_id_ent', [$ent, 0])->whereNotNull('c.adapter')->orderBy('c.adapter')->get(['c.*', 'b.shop_name as shop', 'k.carrier_name as carrier']);
        return ['manage' => Connections::mayManage(),
            'adapters' => array_map(fn ($k, $a) => ['code' => $k, 'kind' => $a[0], 'name' => $a[2], 'secrets' => $a[3], 'settings' => $a[4], 'does' => $a[5], 'state' => $a[6]], array_keys(Connections::ADAPTERS), Connections::ADAPTERS),
            'rows' => $rows->map(fn ($c) => ['id' => $c->id, 'adapter' => $c->adapter, 'label' => $c->label, 'kind' => Connections::ADAPTERS[$c->adapter][0] ?? '', 'shop' => $c->shop, 'shop_id' => $c->fk_id_branch, 'carrier' => $c->carrier, 'carrier_id' => $c->fk_id_carrier,
                'status' => $c->status, 'last_sync_at' => $c->last_sync_at, 'last_error' => $c->last_error, 'last_test' => $c->last_test, 'settings' => json_decode((string) $c->settings_json, true) ?: [], 'filled' => Connections::filled($c), 'state' => Connections::ADAPTERS[$c->adapter][6] ?? '']),
            'shops' => DB::table('t_branches')->where('fk_id_ent', $ent)->where('f_active', 1)->orderBy('shop_name')->get(['id', 'shop_name as name']),
            'carriers' => DB::table('t_carriers')->where(fn ($q) => $q->where('fk_id_ent', $ent)->orWhere('fk_id_ent', 0)->orWhereNull('fk_id_ent'))->where('f_active', 1)->orderBy('carrier_name')->get(['id', 'carrier_name as name']),
            'log' => DB::table('sys_api_log')->whereIn('fk_id_ent', [$ent, 0])->orderByDesc('id')->limit(40)->get(['created_at', 'adapter', 'method', 'url', 'status_code', 'ms', 'error']),
            'jobs' => DB::table('sys_job_runs')->where(fn ($q) => $q->where('job_code', 'like', 'API_%')->orWhere('job_code', 'MSG_SEND'))->orderByDesc('id')->limit(20)->get(['job_code', 'started_at', 'run_status', 'items_done', 'error_text']),
            'webhook' => rtrim((string) config('app.url'), '/') . '/wa/inbound'];
    }

    public function saveConnection(Request $r, ?int $id = null) { return $this->run(fn () => ['id' => Connections::save((array) $r->input(), $id), 'message' => 'Connection saved. Keys typed here are kept encrypted and are not shown again.']); }

    public function testConnection(Request $r, int $id) { $this->can($r, ['OWNER', 'MANAGER']); return $this->run(fn () => Connections::test($id)); }

    public function switchConnection(Request $r, int $id)
    {
        $this->can($r, ['OWNER']);
        return $this->run(function () use ($id, $r) { $c = Connections::row($id); DB::table('api_connections')->where('id', $id)->update(['status' => $r->boolean('on') ? 'NEW' : 'OFF']); \App\Services\Audit::log('api_connections', $id, 'UPDATE', ['status' => [$c->status, $r->boolean('on') ? 'NEW' : 'OFF']]); return ['ok' => true]; });
    }

    public function pull(Request $r, int $id)
    {
        $this->can($r, ['OWNER', 'MANAGER', 'TL', 'FINANCE']);
        return $this->run(fn () => Connections::pull($id, strtoupper((string) $r->input('what')), (string) ($r->input('from') ?: date('Y-m-d', strtotime('-7 days'))), (string) ($r->input('to') ?: date('Y-m-d'))));
    }

    public function track(Request $r, int $carrier, string $no) { $this->can($r, ['OWNER', 'MANAGER', 'TL', 'VA', 'WAREHOUSE']); return $this->run(fn () => Connections::track($carrier, $no)); }

    public function buyLabels(Request $r)
    {
        $this->can($r, ['OWNER', 'MANAGER', 'WAREHOUSE']);
        return $this->run(fn () => Connections::buyLabels((int) $r->input('connection'), (int) $r->input('shop'), array_map('intval', (array) $r->input('sales', []))));
    }

    public function hubs(Request $r) { $this->can($r, ['OWNER', 'MANAGER', 'TL', 'VA', 'FINANCE']); return Hubs::page((int) Context::ent()); }

    public function messages(Request $r)
    {
        $this->can($r, ['OWNER', 'MANAGER', 'TL', 'FINANCE']);
        $ent = (int) Context::ent(); $wa = DB::table('api_connections')->where('adapter', 'WHATSAPP_CLOUD')->whereIn('fk_id_ent', [$ent, 0])->first();
        $strip = fn ($b) => explode("\n\x1e", (string) $b, 2)[0];
        return ['whatsapp' => $wa ? ['status' => $wa->status, 'label' => $wa->label] : null, 'email' => Messenger::emailReady(),
            'wa' => DB::table('wa_outbound as o')->leftJoin('t_account as a', 'a.id', '=', 'o.fk_id_account')->whereIn('o.fk_id_ent', [$ent, 0])->orderByDesc('o.id')->limit(150)
                ->get(['o.id', 'o.template_code', 'o.body', 'o.status', 'o.error', 'o.attempts', 'o.queued_at', 'o.sent_at', DB::raw("TRIM(CONCAT(a.first_name, ' ', a.sur_name)) as who"), 'o.phone'])
                ->map(function ($m) use ($strip) { $m->body = $strip($m->body); $m->phone = '…' . substr((string) $m->phone, -4); return $m; }),
            'mail' => DB::table('email_outbound')->whereIn('fk_id_ent', [$ent, 0])->orderByDesc('id')->limit(150)->get(['id', 'to_address', 'subject', 'status', 'error', 'attempts', 'queued_at', 'sent_at']),
            'inbox' => DB::table('wa_inbound as i')->leftJoin('t_account as a', 'a.id', '=', 'i.fk_id_account')->whereIn('i.fk_id_ent', [$ent, 0])->orderByDesc('i.id')->limit(100)
                ->get(['i.id', 'i.body', 'i.status', 'i.note', 'i.received_at', 'i.media_id', DB::raw("TRIM(CONCAT(a.first_name, ' ', a.sur_name)) as who")]),
            'templates' => DB::table('wa_templates')->orderBy('code')->get(['code', 'title', 'audience', 'body', 'f_active'])];
    }

    public function retryMessage(Request $r, string $kind, int $id)
    {
        $this->can($r, ['OWNER', 'MANAGER']);
        $t = $kind === 'mail' ? 'email_outbound' : 'wa_outbound';
        DB::table($t)->where('id', $id)->whereIn('status', ['FAILED', 'HELD'])->update(['status' => 'QUEUED', 'attempts' => 0, 'next_try_at' => null, 'error' => null]);
        return $this->run(fn () => Messenger::sendDue(20));
    }

    public function sendNow(Request $r) { $this->can($r, ['OWNER', 'MANAGER']); return $this->run(fn () => Messenger::sendDue(100)); }

    public function sendStatement(Request $r, int $id) { $this->can($r, ['OWNER', 'FINANCE']); return $this->run(fn () => ['queued' => Messenger::statement((int) Context::ent(), $id), 'message' => 'Queued to the partner. It goes out with the next send run.']); }

    public function drive(Request $r) { $this->can($r, ['OWNER', 'MANAGER', 'TL', 'FINANCE', 'SOURCING']); return DriveFolders::page((string) $r->input('entity', 'SHOP'), (string) $r->input('q', '')); }

    public function driveLink(Request $r) { $this->can($r, ['OWNER', 'MANAGER']); return $this->run(function () use ($r) { DriveFolders::setLink((string) $r->input('entity'), (int) $r->input('id'), (string) $r->input('url')); return ['ok' => true]; }); }

    public function driveMake(Request $r) { $this->can($r, ['OWNER', 'MANAGER']); return $this->run(fn () => DriveFolders::make((string) $r->input('entity'), (int) $r->input('id'))); }

    public function driveCheck(Request $r) { $this->can($r, ['OWNER', 'MANAGER', 'TL', 'FINANCE']); return $this->run(fn () => DriveFolders::check($r->input('watch') ? (int) $r->input('watch') : null)); }

    public function driveLoad(Request $r, int $id) { $this->can($r, ['OWNER', 'MANAGER', 'TL', 'VA', 'FINANCE']); return $this->run(fn () => DriveFolders::load($id)); }

    public function subscriptions(Request $r) { $this->can($r, ['OWNER', 'MANAGER', 'FINANCE']); return Subscriptions::page((int) Context::ent()); }

    public function subscriptionPaid(Request $r, int $id) { return $this->run(fn () => Subscriptions::paid((int) Context::ent(), $id, (array) $r->input())); }

    /** WhatsApp webhook, outside the sign-in: GET = Meta checks the verify token; POST = signed messages and receipts. */
    public function waVerify(Request $r)
    {
        foreach (DB::table('api_connections')->where('adapter', 'WHATSAPP_CLOUD')->get() as $c) {
            $s = Connections::secrets($c);
            if ($r->query('hub_mode') === 'subscribe' && ($s['verify_token'] ?? '') !== '' && hash_equals((string) $s['verify_token'], (string) $r->query('hub_verify_token'))) return response((string) $r->query('hub_challenge'), 200)->header('Content-Type', 'text/plain');
        }
        return response('Forbidden', 403);
    }

    public function waInbound(Request $r)
    {
        try { return response()->json(Messenger::inbound($r->getContent(), $r->header('X-Hub-Signature-256'))); }
        catch (\Illuminate\Auth\Access\AuthorizationException) { return response()->json(['message' => 'Signature does not match.'], 403); }
    }
}
