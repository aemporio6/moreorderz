<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Audit;
use App\Services\Context;
use App\Services\EventBus;
use App\Services\HomeCards;
use App\Services\Ledger;
use App\Services\Settings;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use PragmaRX\Google2FA\Google2FA;

/** Home cards, users and rights, own security (password, 2-step code), settings, audit log, system health, ledger view. */
class AdminController extends Controller
{
    private function owner(Request $r): void
    {
        abort_unless($r->user()->role_code === 'OWNER', 403, 'Only the Owner can do this.');
    }

    public function home(Request $r)
    {
        $u = $r->user();
        $recent = DB::table('sys_search_index as s')->join('sys_audit_log as a', fn ($j) => $j->on('a.table_name', '=', 's.entity')->on('a.record_id', '=', 's.entity_id'))
            ->where('a.user_id', $u->id)->whereIn('a.action', ['INSERT', 'UPDATE'])->groupBy('s.url', 's.title', 's.subtitle')->orderByRaw('MAX(a.id) DESC')->limit(8)
            ->get(['s.url', 's.title', 's.subtitle']);
        return ['cards' => HomeCards::for($u, (int) Context::ent()), 'recent' => $recent];
    }

    // ---- users ----
    public function users(Request $r)
    {
        abort_unless(in_array($r->user()->role_code, ['OWNER', 'MANAGER'], true), 403, 'Only the Owner and Managers see users.');
        return ['users' => DB::table('t_users as u')->leftJoin('t_enterprise as e', 'e.id', '=', 'u.fk_id_ent')->orderBy('u.id')
            ->get(['u.id', 'u.login_id', 'u.display_name', 'u.role_code', 'u.fk_id_ent', 'e.ent_name as company', 'u.f_active', 'u.last_login_at', DB::raw('u.totp_secret IS NOT NULL as two_step'), DB::raw('u.password_hash IS NOT NULL as has_password')]),
            'roles' => User::ROLES, 'companies' => DB::table('t_enterprise')->where('f_active', 1)->get(['id', 'ent_name as name'])];
    }

    public function saveUser(Request $r, ?int $id = null)
    {
        $this->owner($r);
        $d = $r->validate([
            'login_id' => ['required', 'string', 'max:100', Rule::unique('t_users', 'login_id')->ignore($id)],
            'display_name' => 'required|string|max:120', 'role_code' => ['required', Rule::in(User::ROLES)],
            'fk_id_ent' => 'required|integer|exists:t_enterprise,id', 'f_active' => 'boolean',
            'password' => [$id ? 'nullable' : 'required', 'string', 'min:10', 'max:200'],
        ], ['password.min' => 'The password needs at least 10 characters.', 'login_id.unique' => 'This login is already used.']);
        $u = $id ? User::findOrFail($id) : new User();
        if ($id && $u->id === $r->user()->id && (($d['role_code'] ?? 'OWNER') !== 'OWNER' || ! ($d['f_active'] ?? true))) {
            return response()->json(['message' => 'You cannot remove your own Owner role or switch yourself off.', 'errors' => ['role_code' => ['You cannot remove your own Owner role or switch yourself off.']]], 422);
        }
        $before = $u->only(['login_id', 'display_name', 'role_code', 'fk_id_ent', 'f_active']);
        $u->fill(['login_id' => $d['login_id'], 'display_name' => $d['display_name'], 'role_code' => $d['role_code'], 'fk_id_ent' => $d['fk_id_ent'], 'f_active' => (int) ($d['f_active'] ?? 1)]);
        if (! empty($d['password'])) $u->password_hash = Hash::make($d['password']);
        $u->save();
        $changes = [];
        foreach ($before as $k => $v) if ((string) $v !== (string) $u->$k) $changes[$k] = [$v, $u->$k];
        if (! empty($d['password'])) $changes['password'] = ['***', '***'];
        Audit::log('t_users', $u->id, $id ? 'UPDATE' : 'INSERT', $changes);
        return ['id' => $u->id, 'message' => $id ? 'User updated' : 'User created'];
    }

    // ---- my security ----
    public function password(Request $r)
    {
        $d = $r->validate(['current' => 'required|string', 'new' => 'required|string|min:10|max:200|different:current'], ['new.min' => 'The new password needs at least 10 characters.']);
        $u = $r->user();
        if (! Hash::check($d['current'], (string) $u->password_hash)) return response()->json(['message' => 'The current password is not right.', 'errors' => ['current' => ['The current password is not right.']]], 422);
        $u->password_hash = Hash::make($d['new']); $u->save();
        Audit::log('t_users', $u->id, 'UPDATE', ['password' => ['***', '***']]);
        return ['message' => 'Password changed'];
    }

    public function twoStepSetup(Request $r)
    {
        $g = new Google2FA();
        $secret = $g->generateSecretKey(32);
        $r->session()->put('totp_pending', $secret);
        $uri = $g->getQRCodeUrl(config('app.name'), $r->user()->login_id, $secret);
        $svg = (new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd())))->writeString($uri);
        return ['secret' => $secret, 'qr_svg' => $svg, 'on' => (bool) $r->user()->totp_secret];
    }

    public function twoStepEnable(Request $r)
    {
        $d = $r->validate(['code' => 'required|string']);
        $secret = (string) $r->session()->get('totp_pending');
        if (! $secret || ! (new Google2FA())->verifyKey($secret, preg_replace('/\s+/', '', $d['code']), 1)) {
            return response()->json(['message' => 'That code is not right. Check the time on your phone and try the next code.', 'errors' => ['code' => ['That code is not right.']]], 422);
        }
        $u = $r->user(); $u->totp_secret = encrypt($secret); $u->save();
        $r->session()->forget('totp_pending');
        Audit::log('t_users', $u->id, 'UPDATE', ['two_step' => ['off', 'on']]);
        return ['message' => '2-step sign-in is on'];
    }

    public function twoStepOff(Request $r, ?int $id = null)
    {
        $u = $id ? (function () use ($r, $id) { $this->owner($r); return User::findOrFail($id); })() : $r->user();
        $u->totp_secret = null; $u->save();
        Audit::log('t_users', $u->id, 'UPDATE', ['two_step' => ['on', 'off']]);
        return ['message' => '2-step sign-in is off'];
    }

    // ---- settings ----
    public function settings(Request $r)
    {
        $this->owner($r);
        $ent = (int) Context::ent();
        $rows = DB::table('sys_settings')->whereIn('fk_id_ent', [0, $ent])->orderBy('setting_key')->get();
        $out = [];
        foreach ($rows as $s) {
            $k = $s->setting_key;
            $out[$k] ??= ['key' => $k, 'global' => null, 'company' => null];
            $secret = (bool) preg_match('/SECRET|TOKEN|PASSWORD|API_KEY/i', $k);
            $out[$k][$s->fk_id_ent ? 'company' : 'global'] = $secret && $s->setting_value !== '' ? '••••' : $s->setting_value;
        }
        return ['settings' => array_values($out)];
    }

    public function saveSetting(Request $r)
    {
        $this->owner($r);
        $d = $r->validate(['key' => 'required|string|max:80|regex:/^[A-Za-z0-9_.]+$/', 'value' => 'nullable|string|max:5000', 'scope' => 'required|in:global,company']);
        if (! DB::table('sys_settings')->where('setting_key', $d['key'])->exists()) abort(404, 'Unknown setting.');
        $ent = $d['scope'] === 'company' ? (int) Context::ent() : 0;
        $old = DB::table('sys_settings')->where(['setting_key' => $d['key'], 'fk_id_ent' => $ent])->value('setting_value');
        Settings::set($d['key'], (string) ($d['value'] ?? ''), $ent, $r->user()->id);
        Audit::log('sys_settings', 0, 'UPDATE', [$d['key'] => [$old, $d['value']]], $d['scope']);
        return ['message' => 'Setting saved'];
    }

    // ---- audit log, health, ledger ----
    public function audit(Request $r)
    {
        abort_unless(in_array($r->user()->role_code, ['OWNER', 'ACCOUNTANT'], true), 403, 'Only the Owner and the Accountant see the audit log.');
        $q = DB::table('sys_audit_log as a')->leftJoin('t_users as u', 'u.id', '=', 'a.user_id');
        if ($v = $r->query('action')) $q->where('a.action', $v);
        if ($v = $r->query('table')) $q->where('a.table_name', $v);
        if ($v = $r->query('user')) $q->where('u.login_id', 'like', "%$v%");
        $total = (clone $q)->count();
        $page = max(1, (int) $r->query('page', 1));
        return ['total' => $total, 'page' => $page, 'rows' => $q->orderByDesc('a.id')->offset(($page - 1) * 50)->limit(50)
            ->get(['a.id', 'a.created_at', 'a.action', 'a.table_name', 'a.record_id', 'a.changes_json', 'a.reason', 'a.ip', 'u.display_name', 'u.login_id'])];
    }

    public function health(Request $r)
    {
        $this->owner($r);
        return [
            'events' => DB::table('sys_events')->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status'),
            'failed' => DB::table('sys_events')->where('status', 'FAILED')->orderByDesc('id')->limit(50)->get(['id', 'event_code', 'entity', 'entity_id', 'attempts', 'last_error', 'created_at']),
            'recent' => DB::table('sys_events')->orderByDesc('id')->limit(30)->get(['id', 'event_code', 'entity', 'entity_id', 'status', 'created_at']),
            'counts' => ['records_indexed' => DB::table('sys_search_index')->count(), 'audit_rows' => DB::table('sys_audit_log')->count(), 'open_tasks' => DB::table('tasks')->whereNotIn('status', ['DONE', 'APPROVED', 'CANCELLED'])->count(), 'users' => DB::table('t_users')->where('f_active', 1)->count()],
        ];
    }

    public function retryEvents(Request $r)
    {
        $this->owner($r);
        DB::table('sys_events')->where('status', 'FAILED')->update(['available_at' => now(), 'attempts' => 0]);
        return ['message' => EventBus::runQueue() . ' events processed'];
    }

    public function choices(Request $r)
    {
        abort_unless(in_array($r->user()->role_code, ['OWNER', 'MANAGER', 'FINANCE', 'ACCOUNTANT'], true), 403, 'Your role cannot see the company choices.');
        return ['choices' => \App\Services\Choices::all((int) Context::ent()), 'can_edit' => $r->user()->role_code === 'OWNER'];
    }

    public function saveChoice(Request $r)
    {
        abort_unless($r->user()->role_code === 'OWNER', 403, 'Only the Owner changes a company choice.');
        $d = $r->validate(['key' => 'required|string|max:60', 'value' => 'required|string|max:30']);
        try { \App\Services\Choices::set($d['key'], $d['value'], (int) Context::ent()); } catch (\InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage()], 422); }
        return ['message' => 'Choice saved – it applies from now on'];
    }

    public function ledger(Request $r)
    {
        abort_unless(in_array($r->user()->role_code, ['OWNER', 'FINANCE', 'ACCOUNTANT'], true), 403, 'Only Owner, Finance and Accountant see the ledger.');
        $ent = (int) Context::ent();
        $from = $r->query('from', date('Y-m-01')); $to = $r->query('to', date('Y-m-d'));
        return ['trial' => Ledger::trialBalance($ent, $to), 'pl' => Ledger::profitAndLoss($ent, $from, $to), 'from' => $from, 'to' => $to,
            'journals' => DB::table('gl_journals')->where('fk_id_ent', $ent)->orderByDesc('id')->limit(50)->get(['id', 'journal_no', 'journal_date', 'source_type', 'memo', 'reverses_id', 'reversed_by_id']),
            'locked_up_to' => Settings::get('GL_LOCKED_UPTO', $ent, '')];
    }

    // ───────────── sign-ins: sessions, tries, allowed addresses ─────────────
    private function guard(callable $fn)
    {
        try { return $fn(); }
        catch (\Illuminate\Database\QueryException $e) { report($e); return response()->json(['message' => 'Something went wrong on our side. Nothing was saved. The details are in the system log.'], 500); }
        catch (\InvalidArgumentException|\RuntimeException $e) { return response()->json(['message' => $e->getMessage(), 'errors' => ['_form' => [$e->getMessage()]]], 422); }
    }

    public function sessions(Request $r)
    {
        abort_unless(in_array($r->user()->role_code, ['OWNER', 'MANAGER'], true), 403, 'Only the Owner or a Manager sees the sign-ins.');
        return \App\Services\Access::page((int) Context::ent(), $r->hasSession() ? \App\Services\Access::hash($r) : '') + ['my_ip' => $r->ip(), 'can_rules' => $r->user()->role_code === 'OWNER'];
    }

    public function endSession(Request $r)
    {
        abort_unless(in_array($r->user()->role_code, ['OWNER', 'MANAGER'], true), 403, 'Only the Owner or a Manager ends a sign-in.');
        $d = $r->validate(['session_id' => 'nullable|integer', 'user_id' => 'nullable|integer']);
        return $this->guard(function () use ($d, $r) { $n = \App\Services\Access::endSessions((int) Context::ent(), $d['session_id'] ?? null, $d['user_id'] ?? null, isset($d['user_id']) && $r->hasSession() ? \App\Services\Access::hash($r) : null); return ['ended' => $n, 'message' => "$n sign-in(s) ended – that device must sign in again"]; });
    }

    public function ipRule(Request $r)
    {
        $this->owner($r);
        $d = $r->validate(['scope' => 'required|in:ALL,ROLE,USER', 'role_code' => 'nullable|string|max:20', 'user_id' => 'nullable|integer', 'ip_cidr' => 'required|string|max:50', 'note' => 'nullable|string|max:150']);
        return $this->guard(function () use ($d, $r) { return ['id' => \App\Services\Access::addRule((int) Context::ent(), $d, $r->ip()), 'message' => 'Allowed address added']; });
    }

    public function ipRuleRemove(Request $r, int $id)
    {
        $this->owner($r);
        return $this->guard(function () use ($id) { \App\Services\Access::removeRule((int) Context::ent(), $id); return ['message' => 'Allowed address removed']; });
    }

    // ───────────── saved list views (per user; Owner / Manager can share one with everybody) ─────────────
    public function views(Request $r, string $page)
    {
        $u = $r->user();
        return ['views' => DB::table('sys_saved_views')->where('page_key', $page)->where(fn ($w) => $w->where('fk_id_account', $u->id)->orWhere('f_shared', 1))->orderByDesc('f_default')->orderBy('view_name')->get()
            ->map(fn ($v) => ['id' => $v->id, 'name' => $v->view_name, 'state' => json_decode($v->filters_json, true) ?: [], 'default' => (bool) $v->f_default && (int) $v->fk_id_account === (int) $u->id, 'shared' => (bool) $v->f_shared, 'mine' => (int) $v->fk_id_account === (int) $u->id]), 'can_share' => in_array($u->role_code, ['OWNER', 'MANAGER'], true)];
    }

    public function saveView(Request $r)
    {
        $u = $r->user();
        $d = $r->validate(['page' => 'required|string|max:60', 'name' => 'required|string|min:1|max:80', 'state' => 'present|array', 'default' => 'nullable|boolean', 'shared' => 'nullable|boolean']);
        if (! empty($d['shared']) && ! in_array($u->role_code, ['OWNER', 'MANAGER'], true)) abort(403, 'Only the Owner or a Manager can share a view with everybody.');
        $state = ['q' => (string) ($d['state']['q'] ?? ''), 'sort' => (string) ($d['state']['sort'] ?? ''), 'dir' => ($d['state']['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc', 'filter' => array_map('strval', array_filter((array) ($d['state']['filter'] ?? []), fn ($v) => is_scalar($v) && $v !== '')),
            'cols' => array_values(array_filter(array_map('strval', array_filter((array) ($d['state']['cols'] ?? []), 'is_scalar')), fn ($c) => preg_match('/^[A-Za-z0-9_]{1,64}$/', $c)))];
        return DB::transaction(function () use ($u, $d, $state) {
            if (! empty($d['default'])) DB::table('sys_saved_views')->where('fk_id_account', $u->id)->where('page_key', $d['page'])->update(['f_default' => 0]);
            $have = DB::table('sys_saved_views')->where('fk_id_account', $u->id)->where('page_key', $d['page'])->where('view_name', $d['name'])->value('id');
            $row = ['filters_json' => json_encode($state), 'f_default' => empty($d['default']) ? 0 : 1, 'f_shared' => empty($d['shared']) ? 0 : 1];
            if ($have) DB::table('sys_saved_views')->where('id', $have)->update($row); else $have = DB::table('sys_saved_views')->insertGetId($row + ['fk_id_account' => $u->id, 'page_key' => $d['page'], 'view_name' => $d['name'], 'created_at' => now()]);
            return ['id' => $have, 'message' => 'View saved'];
        });
    }

    public function deleteView(Request $r, int $id)
    {
        $u = $r->user(); $v = DB::table('sys_saved_views')->where('id', $id)->first() ?? abort(404);
        abort_unless((int) $v->fk_id_account === (int) $u->id || ($v->f_shared && $u->role_code === 'OWNER'), 403, 'You can only remove your own views.');
        DB::table('sys_saved_views')->where('id', $id)->delete();
        return ['message' => 'View removed'];
    }
}
