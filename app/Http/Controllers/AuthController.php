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
            RateLimiter::hit($key, 900);
            Audit::log('t_users', $u?->id, 'LOGIN_FAIL', ['login' => $data['login']], null, $u?->fk_id_ent ?? 0);
            return back()->withErrors(['login' => 'Login or password is not right.'])->onlyInput('login');
        }
        RateLimiter::clear($key);
        Auth::login($u, $r->boolean('remember'));
        $r->session()->regenerate();
        $r->session()->put('ent', $u->fk_id_ent);
        DB::table('t_users')->where('id', $u->id)->update(['last_login_at' => now()]);
        Audit::log('t_users', $u->id, 'LOGIN', [], null, $u->fk_id_ent ?? 0);
        return redirect()->intended('/');
    }

    public function logout(Request $r)
    {
        if ($u = Auth::user()) Audit::log('t_users', $u->id, 'LOGOUT', [], null, $u->fk_id_ent ?? 0);
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();
        return redirect('/login');
    }
}
