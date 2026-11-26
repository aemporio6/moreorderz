<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Owner 26-11 "slow": the screens' data (JSON) goes over the line gzip-compressed – 5 to 10 times smaller (the host does not compress it by itself). */
class CompressJson
{
    public function handle(Request $request, Closure $next): Response
    {
        $res = $next($request);
        if (! function_exists('gzencode') || ! str_contains((string) $request->header('Accept-Encoding'), 'gzip') || $res->headers->has('Content-Encoding')) return $res;
        if (! str_contains((string) $res->headers->get('Content-Type'), 'json') || $res instanceof \Symfony\Component\HttpFoundation\StreamedResponse || $res instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) return $res;
        $body = $res->getContent(); if ($body === false || strlen($body) < 1400) return $res;
        $gz = gzencode($body, 5); if ($gz === false) return $res;
        $res->setContent($gz); $res->headers->set('Content-Encoding', 'gzip'); $res->headers->set('Vary', 'Accept-Encoding', false); $res->headers->set('Content-Length', (string) strlen($gz));
        return $res;
    }
}
