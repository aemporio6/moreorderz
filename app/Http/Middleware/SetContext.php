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
            if ($request->hasSession() && ! app()->runningUnitTests() && ! \App\Services\Access::touch($request, $u)) { Auth::logout(); $request->session()->invalidate(); abort(401, 'This sign-in was ended. Sign in again.'); }
            $ent = (int) ($request->session()->get('ent') ?: $u->fk_id_ent);
            Context::set($ent ?: null, $u->id); Context::setAll(false);
            // R-G_CORE-102 "All companies (added up)": reads cover every company; a write first names its company (header X-Company)
            if ($request->hasSession() && $request->session()->get('ent_all') && \App\Services\AllCompanies::may($u)) {
                $pick = (int) $request->header('X-Company');
                $allowed = \App\Services\AllCompanies::companies($u)->pluck('id')->map(fn ($i) => (int) $i)->all();
                if ($pick) { abort_unless(in_array($pick, $allowed, true), response()->json(['message' => 'You cannot work in that company.'], 403)); Context::set($pick, $u->id); }
                else {
                    Context::setAll(true, $allowed);
                    if (! $request->isMethod('GET') && ! $request->isMethod('HEAD') && ! \App\Services\AllCompanies::freeWrite($request))
                        abort(response()->json(['message' => 'You are looking at all companies. Choose the company this belongs to.', 'choose_company' => true,
                            'companies' => \App\Services\AllCompanies::companies($u)->map(fn ($c) => ['id' => (int) $c->id, 'name' => $c->name])->values()], 409));
                }
            }
            \App\Services\SecureStep::seen($request);      // R-G_CORE-98 activity for the 2FA re-ask
            // shop scope: a person limited to some shops cannot name another shop on any screen
            foreach (['shop', 'shop_id', 'branch', 'branch_id', 'fk_id_branch', 'fk_id_shop'] as $k) {
                $v = $request->input($k) ?? $request->route($k);
                if (is_scalar($v) && ctype_digit((string) $v) && ! \App\Services\Perm::shopAllowed($u, (int) $v)) abort(response()->json(['message' => 'This shop is not one of your shops. Ask the Owner if you need it.'], 403));
            }
            // "View as": the Owner looks at the tool as another role – read-only
            \App\Services\Perm::viewAs(null);
            if ($u->role_code === 'OWNER' && $request->hasSession() && ($va = $request->session()->get('view_as'))) {
                \App\Services\Perm::viewAs($va[0], $va[1]);
                if (! $request->isMethod('GET') && ! $request->is('api/permissions/view-as')) abort(response()->json(['message' => 'You are viewing as ' . $va[1] . ' – nothing can be changed. Press "Back to myself" first.'], 403));
            }
        }
        return $next($request);
    }
}
