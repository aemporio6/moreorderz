<?php

namespace App\Http\Middleware;

use App\Services\Context;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Every signed-in request works inside one company: the one chosen in the top bar, else the user's own. */
class SetContext
{
    public function handle(Request $request, Closure $next)
    {
        if ($u = Auth::user()) {
            if (! $u->f_active) { Auth::logout(); abort(401, 'This login is switched off.'); }
            $ent = (int) ($request->session()->get('ent') ?: $u->fk_id_ent);
            Context::set($ent ?: null, $u->id);
        }
        return $next($request);
    }
}
