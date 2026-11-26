<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * R-G_CORE-121 error pages. One page in the app's look for 403 / 404 / 500; an API call gets the same short text.
 * The details (message, file, SQL) go ONLY to sys_error_log – never to the screen and never into a URL. A 403 is written to the audit log (action DENIED).
 */
class ErrorPages
{
    public const T403 = 'You do not have the right to open this page';
    public const T404 = 'Page not found – search or go Home';
    public const T500 = 'Something went wrong – the error was logged as E-';

    /** Keep the details, return the id shown to the person. Never throws. */
    public static function log(\Throwable $e, ?Request $r = null): ?int
    {
        try {
            $sql = $e instanceof \Illuminate\Database\QueryException ? $e->getSql() : null;
            return (int) DB::table('sys_error_log')->insertGetId(['level' => 'ERROR', 'message' => mb_substr(get_class($e) . ': ' . $e->getMessage(), 0, 1000), 'file' => mb_substr($e->getFile(), -250), 'line' => $e->getLine(),
                'url' => $r ? mb_substr($r->method() . ' ' . $r->path(), 0, 250) : 'cli', 'user_id' => $r && $r->user() ? $r->user()->id : null, 'fk_id_ent' => Context::ent() ?: null,
                'context_json' => json_encode(['sql' => $sql ? mb_substr($sql, 0, 4000) : null]), 'fingerprint' => sha1(get_class($e) . '|' . $e->getFile() . '|' . $e->getLine() . '|' . microtime(true)), 'times_seen' => 1, 'f_alerted' => 0, 'created_at' => now(), 'last_seen' => now()]);
        } catch (\Throwable $x) { return null; }
    }

    public static function denied(?Request $r, ?string $right): void
    {
        try { Audit::log('sys_permissions', 0, 'DENIED', ['right' => [null, (string) $right], 'page' => [null, $r ? mb_substr($r->method() . ' ' . $r->path(), 0, 200) : '']], null, Context::ent() ?: null); } catch (\Throwable $x) {}
    }

    /** The answer for an exception, or null to let the framework answer (validation, sign-in, our own 4xx with a message). */
    public static function render(\Throwable $e, Request $r)
    {
        $json = $r->is('api/*') || $r->expectsJson();
        if ($e instanceof \Illuminate\Validation\ValidationException || $e instanceof \Illuminate\Auth\AuthenticationException || $e instanceof \Illuminate\Http\Exceptions\HttpResponseException || $e instanceof \Illuminate\Session\TokenMismatchException) return null;
        $code = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : ($e instanceof \Illuminate\Auth\Access\AuthorizationException ? 403 : ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500));
        if ($code === 403) { self::denied($r, $e->getMessage() ?: null); if ($json) return null; return response()->view('error', ['code' => 403, 'text' => self::T403 . ' – ask the Owner' . ($e->getMessage() ? ' for "' . $e->getMessage() . '"' : '') . '.', 'search' => false], 403); }
        if ($code === 404) return $json ? null : response()->view('error', ['code' => 404, 'text' => self::T404 . '.', 'search' => true], 404);
        if ($code < 500) return null;
        $id = self::log($e, $r); $text = self::T500 . ($id ?? '0') . '.';
        return $json ? response()->json(['message' => $text, 'error_id' => $id], 500) : response()->view('error', ['code' => 500, 'text' => $text, 'search' => false], 500);
    }
}
