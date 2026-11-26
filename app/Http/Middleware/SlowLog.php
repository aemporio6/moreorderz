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
        $t = microtime(true); $n = 0; $qms = 0.0; $worst = [0.0, ''];
        DB::listen(function ($q) use (&$n, &$qms, &$worst) { $n++; $qms += $q->time; if ($q->time > $worst[0]) $worst = [$q->time, mb_substr(preg_replace('/\s+/', ' ', $q->sql), 0, 300)]; });
        $res = $next($request);
        $ms = (microtime(true) - $t) * 1000;
        if ($ms > 1200 && ! app()->runningUnitTests()) {
            @file_put_contents(storage_path('logs/slow-' . date('Y-m-d') . '.log'), sprintf("%s %s %s %dms queries=%d db=%dms worst=%dms %s\n", date('H:i:s'), $request->method(), '/' . ltrim($request->path(), '/'), $ms, $n, $qms, $worst[0], $worst[1]), FILE_APPEND);
        }
        return $res;
    }
}
