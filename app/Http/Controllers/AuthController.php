<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{
    public function form()
    {
        return Auth::check() ? redirect('/') : view('login');
    }

    public function login(Request $r)
    {
        $data = $r->validate(['login' => 'required|string|max:100', 'password' => 'required|string|max:200']);
        $key = 'login:' . strtolower($data['login']) . '|' . $r->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors(['login' => 'Too many tries. Wait ' . ceil(RateLimiter::availableIn($key) / 60) . ' minute(s) and try again.'])->onlyInput('login');
        }
        $u = User::where('login_id', $data['login'])->where('f_active', 1)->first();
        if (! $u || ! $u->password_hash || ! Hash::check($data['password'], $u->password_hash)) {
            RateLimiter::hit($key, 900); \App\Services\Access::attempt($data['login'], $r->ip(), false);
            Audit::log('t_users', $u?->id, 'LOGIN_FAIL', ['login' => $data['login']], null, $u?->fk_id_ent ?? 0);
            return back()->withErrors(['login' => 'Login or password is not right.'])->onlyInput('login');
        }
        if (! \App\Services\Access::ipAllowed((int) $u->fk_id_ent, (int) $u->id, $u->role_code, $r->ip())) {
            \App\Services\Access::attempt($data['login'], $r->ip(), false); Audit::log('t_users', $u->id, 'LOGIN_FAIL', ['reason' => 'address not allowed', 'ip' => $r->ip()], null, $u->fk_id_ent ?? 0);
            return back()->withErrors(['login' => 'Signing in from this network is not allowed for your login. Ask the Owner.'])->onlyInput('login');
        }
        if ($why = \App\Services\PartnerOnboarding::blocked($u)) {      // R-FIN-3 onboarding gate
            \App\Services\Access::attempt($data['login'], $r->ip(), false); Audit::log('t_users', $u->id, 'LOGIN_FAIL', ['reason' => 'partner onboarding'], $why, $u->fk_id_ent ?? 0);
            return back()->withErrors(['login' => $why])->onlyInput('login');
        }
        RateLimiter::clear($key);
        if ($u->totp_secret) {      // 2-step sign-in: the password was right, now ask for the code of the phone app
            $r->session()->put('login_2fa', ['id' => $u->id, 'remember' => $r->boolean('remember'), 'at' => time()]);
            return redirect('/login/code');
        }
        return $this->finish($r, $u, $r->boolean('remember'));
    }

    /* ---------- R-G_CORE-65 forgot password ---------- */
    public function forgotForm() { return view('login', ['step' => 'forgot']); }
    public function forgot(Request $r)
    {
        $msg = \App\Services\PasswordReset::request((string) $r->input('login'), (string) $r->input('email'), $r->ip());
        return view('login', ['step' => 'sent', 'message' => $msg]);
    }
    public function resetForm(string $token) { return \App\Services\PasswordReset::valid($token) ? view('login', ['step' => 'reset', 'token' => $token]) : view('login', ['step' => 'sent', 'message' => 'This link has expired or was used already. Ask for a new one.']); }
    public function reset(Request $r, string $token)
    {
        try { $m = \App\Services\PasswordReset::reset($token, (string) $r->input('password'), (string) $r->input('password_again')); }
        catch (\InvalidArgumentException $e) { return back()->withErrors(['password' => $e->getMessage()]); }
        return redirect('/login')->with('status', $m);
    }

    public function codeForm(Request $r)
    {
        return $r->session()->has('login_2fa') ? view('login', ['step' => 'code']) : redirect('/login');
    }

    public function code(Request $r)
    {
        $p = $r->session()->get('login_2fa');
        if (! $p || time() - $p['at'] > 300) { $r->session()->forget('login_2fa'); return redirect('/login')->withErrors(['login' => 'That took too long. Sign in again.']); }
        $u = User::where('id', $p['id'])->where('f_active', 1)->firstOrFail();
        $key = 'login2:' . $u->id . '|' . $r->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) return back()->withErrors(['code' => 'Too many wrong codes. Wait a few minutes and sign in again.']);
        $code = preg_replace('/\s+/', '', (string) $r->input('code'));
        if (! (new \PragmaRX\Google2FA\Google2FA())->verifyKey(decrypt($u->totp_secret), $code, 1)) {
            RateLimiter::hit($key, 600);
            Audit::log('t_users', $u->id, 'LOGIN_FAIL', ['step' => 'code'], null, $u->fk_id_ent ?? 0);
            return back()->withErrors(['code' => 'That code is not right. Try the next one.']);
        }
        RateLimiter::clear($key);
        $r->session()->forget('login_2fa');
        return $this->finish($r, $u, (bool) $p['remember']);
    }

    private function finish(Request $r, User $u, bool $remember)
    {
        Auth::login($u, $remember);
        $r->session()->regenerate();
        $r->session()->put('ent', $u->fk_id_ent);
        \App\Services\Access::attempt((string) $u->login_id, $r->ip(), true); \App\Services\Access::start($r, $u);
        DB::table('t_users')->where('id', $u->id)->update(['last_login_at' => now()]);
        Audit::log('t_users', $u->id, 'LOGIN', [], null, $u->fk_id_ent ?? 0);
        return redirect()->intended('/');
    }

    public function logout(Request $r)
    {
        if ($u = Auth::user()) { Audit::log('t_users', $u->id, 'LOGOUT', [], null, $u->fk_id_ent ?? 0); \App\Services\Access::end($r); }
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();
        return redirect('/login');
    }
}
