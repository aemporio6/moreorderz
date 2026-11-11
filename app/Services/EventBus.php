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

    public static function fire(string $eventCode, string $entity, int|string|null $entityId, array $payload = [], ?int $ent = null): int
    {
        if (! self::$known) self::$known = DB::table('sys_event_types')->pluck('f_active', 'event_code')->all();
        if (! array_key_exists($eventCode, self::$known)) {
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

    public static function reset(): void
    {
        self::$listeners = [];
        self::$known = [];
    }
}
