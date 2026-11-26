<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One event list for the whole tool (sys_event_types). A button fires an event; listeners update other records
 * and open or close tasks. Events are stored first (sys_events) so nothing is lost; listeners run right after
 * the save commits, and a failed listener leaves the event FAILED for the worker to retry.
 */
class EventBus
{
    /** event_code => list of callables(array $event): void */
    private static array $listeners = [];
    private static array $known = [];

    public static function listen(string $eventCode, callable $fn): void
    {
        self::$listeners[$eventCode][] = $fn;
    }

    /** The event names that have a listener right now (R-G_CORE-95 self-check). */
    public static function listening(): array { return array_keys(self::$listeners); }

    public static function fire(string $eventCode, string $entity, int|string|null $entityId, array $payload = [], ?int $ent = null): int
    {
        if ($new = EventCatalogue::canonical($eventCode)) {      // R-G_CORE-95 a retired alias: refused in development, the canonical name in production (logged)
            if (! app()->environment('production')) throw new \InvalidArgumentException(EventCatalogue::refusal($eventCode));
            Log::warning('Retired event name raised', ['event' => $eventCode, 'use' => $new]); $eventCode = $new;
        }
        if (! self::$known) self::$known = DB::table('sys_event_types')->pluck('f_active', 'event_code')->all();
        if (! array_key_exists($eventCode, self::$known)) {
            self::sysError(['unknown_event', $eventCode], "Unknown event $eventCode refused", "Something raised the event $eventCode, which is not in the event catalogue (sys_event_types).", (int) ($ent ?? Context::ent() ?? 0));      // MR-1508
            throw new \InvalidArgumentException("Event $eventCode is not in the event catalogue (sys_event_types).");
        }
        $ent ??= Context::ent() ?? 0;
        $id = DB::table('sys_events')->insertGetId([
            'fk_id_ent' => $ent, 'event_code' => $eventCode, 'entity' => $entity, 'entity_id' => (int) $entityId,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE), 'status' => 'NEW', 'attempts' => 0,
            'available_at' => now(), 'created_by' => Context::userId(), 'created_at' => now(),
        ]);
        DB::afterCommit(fn () => self::process($id));
        return $id;
    }

    public static function process(int $eventId): bool
    {
        $e = DB::table('sys_events')->where('id', $eventId)->first();
        if (! $e || $e->status === 'DONE') return true;
        $event = ['id' => $e->id, 'ent' => (int) $e->fk_id_ent, 'code' => $e->event_code, 'entity' => $e->entity, 'entity_id' => (int) $e->entity_id,
            'payload' => json_decode($e->payload_json ?: '[]', true) ?: [], 'user' => $e->created_by];
        DB::table('sys_events')->where('id', $eventId)->update(['status' => 'RUNNING', 'attempts' => $e->attempts + 1]);
        try {
            foreach (self::$listeners[$e->event_code] ?? [] as $fn) $fn($event);
            foreach (self::$listeners['*'] ?? [] as $fn) $fn($event);
            TaskEngine::onEvent($event);
            DB::table('sys_events')->where('id', $eventId)->update(['status' => 'DONE', 'processed_at' => now(), 'last_error' => null]);
            return true;
        } catch (\Throwable $ex) {
            Log::error("Event {$e->event_code} #$eventId failed: " . $ex->getMessage());
            DB::table('sys_events')->where('id', $eventId)->update(['status' => 'FAILED', 'last_error' => mb_substr($ex->getMessage(), 0, 500), 'available_at' => now()->addMinutes(5)]);
            if ($e->attempts + 1 >= 5) self::sysError(['listener', $e->event_code], "Event {$e->event_code} keeps failing (5 tries)", $ex->getMessage(), (int) $e->fk_id_ent);      // MR-1508 worker listener
            return false;
        }
    }

    /** Worker: retry events that are waiting or failed (max 5 tries). */
    public static function runQueue(int $limit = 200): int
    {
        $ids = DB::table('sys_events')->whereIn('status', ['NEW', 'FAILED'])->where('attempts', '<', 5)->where('available_at', '<=', now())->orderBy('id')->limit($limit)->pluck('id');
        $n = 0;
        foreach ($ids as $id) $n += self::process($id) ? 1 : 0;
        return $n;
    }

    /** MR-1508 one SYS_ERROR task per problem (dedupe); never throws – an alert must not break the work it reports on. */
    public static function sysError(array $key, string $title, string $detail, int $ent = 0): void
    {
        try { TaskEngine::open('SYS_ERROR', $key, mb_substr($title, 0, 200), ['ent' => $ent, 'description' => mb_substr($detail, 0, 1000)]); } catch (\Throwable $x) { Log::error('SYS_ERROR alert failed: ' . $x->getMessage()); }
    }

    public static function reset(): void
    {
        self::$listeners = [];
        self::$known = [];
    }
}
