<?php

namespace App\Http\Middleware;

use App\Services\AllCompanies;
use App\Services\Context;
use Closure;
use Illuminate\Http\Request;

/** R-G_CORE-102: in "All companies (added up)" a read screen is answered once per company and added up (AllCompanies::merge). */
class AllCompaniesView
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->isMethod('GET') || ! Context::all() || ! ($u = $request->user()) || $request->route('which') === 'tasks' /* tasks read every company at once (TaskLists::base) */) return $next($request);
        $failed = null;
        $parts = AllCompanies::each($u, function () use ($next, $request, &$failed) {
            $res = $next($request);
            if ($res->getStatusCode() >= 400 || ! str_contains((string) $res->headers->get('Content-Type'), 'json')) { $failed ??= $res; return null; }
            return json_decode($res->getContent(), true);
        });
        if ($failed && ! array_filter(array_column($parts, 1))) return $failed;      // refused for every company (rights) – the same refusal
        $parts = array_values(array_filter($parts, fn ($p) => $p[1] !== null));
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('to')) ? (string) $request->query('to') : null;
        return response()->json(AllCompanies::merge($parts, $date));
    }
}
