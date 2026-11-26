<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/** Owner 26-11 "slow": every request that takes longer than 1.2 s on the server is written to storage/logs/slow-<date>.log
 *  (path, time, number of queries and their time, the slowest query shape – never values), so the slow screens are found from real use. */
class SlowLog
{
    public function handle(Request $request, Closure $next): Response
    {
        $t = microtime(true); $n = 0; $qms = 0.0; $worst = [0.0, '']; $shapes = [];
        DB::listen(function ($q) use (&$n, &$qms, &$worst, &$shapes) { $n++; $qms += $q->time; $k = mb_substr(preg_replace('/\s+/', ' ', $q->sql), 0, 160); $shapes[$k] = ($shapes[$k] ?? 0) + 1; if ($q->time > $worst[0]) $worst = [$q->time, mb_substr(preg_replace('/\s+/', ' ', $q->sql), 0, 300)]; });
        $res = $next($request);
        if ($request->method() !== 'GET' && $request->method() !== 'HEAD' && $res->getStatusCode() < 400) \App\Services\ReadCache::bump();      // a save by anyone: cached dashboard cards are read again
        $ms = (microtime(true) - $t) * 1000;
        if ($ms > 1200 && ! app()->runningUnitTests()) {
            arsort($shapes); $top = implode(' || ', array_map(fn ($k, $c) => "$c× $k", array_keys(array_slice($shapes, 0, 3, true)), array_slice($shapes, 0, 3, true)));
            @file_put_contents(storage_path('logs/slow-' . date('Y-m-d') . '.log'), sprintf("%s %s %s %dms queries=%d db=%dms worst=%dms %s ## TOP %s\n", date('H:i:s'), $request->method(), '/' . ltrim($request->path(), '/'), $ms, $n, $qms, $worst[0], $worst[1], $top), FILE_APPEND);
        }
        return $res;
    }
}
