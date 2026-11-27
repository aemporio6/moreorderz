<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Live 26-11 'slow': heavy dashboard cards (the buying cycles with their 12 stages, needs-action, charts) are kept for a few minutes
 * per person and per filter. ANY change saved by anyone (a POST / PUT / DELETE that worked) starts a new version, so a screen never shows
 * data older than the last save; background jobs are covered by the short time. Not used while the tests run.
 */
class ReadCache
{
    public static function on(): bool { return ! app()->runningUnitTests(); }

    public static function version(): string
    {
        try { return (string) (Cache::store('file')->get('rc:ver') ?? '0'); } catch (\Throwable $e) { return '0'; }
    }

    public static function bump(): void
    {
        if (! self::on()) return;
        try { Cache::store('file')->forever('rc:ver', (string) hrtime(true)); } catch (\Throwable $e) {}
    }

    public static function remember(string $key, int $seconds, callable $fn): mixed
    {
        if (! self::on() || request()->boolean('fresh') || request()->header('X-Fresh') === '1') return $fn();      // the Refresh button
        $k = 'rc:' . sha1(self::version() . '|' . Context::ent() . '|' . Context::userId() . '|' . $key . '|' . json_encode(request()->query()));
        try { $hit = Cache::store('file')->get($k); } catch (\Throwable $e) { $hit = null; }
        if (is_array($hit) && array_key_exists('v', $hit)) return $hit['v'];
        $v = $fn();
        try { Cache::store('file')->put($k, ['v' => $v], $seconds); } catch (\Throwable $e) {}
        return $v;
    }
}
