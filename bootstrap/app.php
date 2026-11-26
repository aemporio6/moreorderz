<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(\App\Http\Middleware\CompressJson::class);
        $middleware->append(\App\Http\Middleware\SlowLog::class);      // owner 26-11 'slow': find the slow screens from real use      // owner 26-11 'slow'
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(fn (\Throwable $e, Request $request) => \App\Services\ErrorPages::render($e, $request));
    })
    ->withSingletons([\Illuminate\Contracts\Debug\ExceptionHandler::class => \App\Exceptions\Handler::class])      // R-G_CORE-121 command-line errors: short text + id
    ->create();
