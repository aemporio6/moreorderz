<?php

namespace App\Services\Connect;

use App\Services\Context;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/** Every call to an outside service goes through here: a time limit, one retry on "too many requests" / server errors, and a log row without any secret. */
class Api
{
    public static function call(?object $conn, string $adapter, string $method, string $url, array $opt = []): Response
    {
        $t0 = microtime(true); $code = null; $err = null;
        try {
            $req = Http::timeout((int) ($opt['timeout'] ?? 30))->acceptJson()->withHeaders($opt['headers'] ?? [])
                ->retry(2, 400, fn ($e) => $e instanceof \Illuminate\Http\Client\RequestException && in_array($e->response->status(), [429, 500, 502, 503, 504], true), throw: false);
            if (isset($opt['basic'])) $req = $req->withBasicAuth(...$opt['basic']);
            $res = self::send($req, strtoupper($method), $url, $opt);
            $code = $res->status();
            if ($res->failed()) $err = mb_substr(self::errorText($res), 0, 250);
            return $res;
        } catch (\Throwable $e) {
            $err = mb_substr(preg_replace('/[?&](key|token|secret|sign|access_token|app_key|client_secret)=[^&\s]*/i', '', $e->getMessage()), 0, 250);
            throw new ApiError('The service could not be reached: ' . $err);
        } finally {
            DB::table('sys_api_log')->insert(['fk_id_ent' => (int) ($conn->fk_id_ent ?? Context::ent() ?? 0), 'fk_id_connection' => $conn->id ?? null, 'adapter' => $adapter, 'method' => strtoupper($method),
                'url' => mb_substr((string) strtok($url, '?'), 0, 255), 'status_code' => $code, 'ms' => (int) round((microtime(true) - $t0) * 1000), 'error' => $err, 'user_id' => Context::userId(), 'created_at' => now()]);
        }
    }

    private static function send(PendingRequest $req, string $method, string $url, array $opt): Response
    {
        if ($method === 'GET') return $req->get($url, $opt['query'] ?? []);
        if (! empty($opt['query'])) $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($opt['query']);
        if (isset($opt['raw'])) return $req->withBody($opt['raw'], $opt['headers']['Content-Type'] ?? 'application/json')->send($method, $url);
        if (isset($opt['form'])) return $req->asForm()->send($method, $url, ['form_params' => $opt['form']]);
        return $req->send($method, $url, ['json' => $opt['json'] ?? new \stdClass()]);
    }

    /** A short, plain reason from an error answer (never the request, so never a key). */
    public static function errorText(Response $r): string
    {
        $j = $r->json() ?? [];
        $m = $j['errors'][0]['message'] ?? $j['errors'][0]['longMessage'] ?? $j['error_description'] ?? $j['message'] ?? $j['error']['message'] ?? $j['errorMsg'] ?? (is_string($j['error'] ?? null) ? $j['error'] : null);
        return 'HTTP ' . $r->status() . ($m ? ': ' . $m : '');
    }

    /** Stop with a plain message when the answer is an error. */
    public static function ok(Response $r, string $what): array
    {
        if ($r->status() === 401 || $r->status() === 403) throw new ApiError("$what: the service refused the keys (" . self::errorText($r) . '). Check or renew the connection.', true);
        if ($r->failed()) throw new ApiError("$what failed – " . self::errorText($r));
        return $r->json() ?? [];
    }
}
