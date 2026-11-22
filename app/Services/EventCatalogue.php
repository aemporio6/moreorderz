<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RULES_G_CORE R-G_CORE-95 the event catalogue = 06_ENGINES §D only (sys_event_types). Retired aliases are refused by the event bus
 * ("Unknown event – use GRN_POSTED"; in production the canonical name is used and the slip is logged), mapped once in stored rows
 * (task templates), and the self-check lists every raise, listener or template trigger whose name is not in the catalogue (System › Health).
 */
class EventCatalogue
{
    /** retired name => canonical name (06_ENGINES §D "Retired aliases"). */
    public const RETIRED = [
        'SRC_PO_CONFIRMED' => 'PO_CONFIRMED', 'GRN_SAVED' => 'GRN_POSTED', 'STOCK_RECEIVED' => 'GRN_POSTED', 'LOT_COST_FINAL' => 'LANDED_FINAL', 'LANDED_COST_SAVED' => 'LANDED_FINAL',
        'SALE_STOCK_TAKEN' => 'SALE_MONTH_CONFIRMED', 'MONTH_CLOSE' => 'MONTH_LOCKED', 'IMPORT_SAVED' => 'IMPORT_APPLIED', 'IMPORT_DONE' => 'IMPORT_APPLIED', 'IMPORT_CONFIRMED' => 'IMPORT_APPLIED',
        'PARTY_SAVED' => 'PERSON_UPDATED', 'PARTY_MERGED' => 'PERSON_MERGED', 'BULK_JOB' => 'BULK_APPLIED', 'DS_IMPORTED' => 'DS_BATCH_APPLIED', 'PAYMENT_PAID' => 'PAYMENT_POSTED',
        'INVOICE_GENERATED' => 'INVOICE_CREATED', 'STOCK_MOVED' => 'STOCK_TRANSFERRED', 'AUDIT_CHECKED' => 'MONTH_CHECKED', 'DUE_REACHED' => 'DUE_SOON', 'STOCK_OWNER_CHANGED' => 'LOT_OWNER_CHANGED',
        'OWNER_CHANGED' => 'LOT_OWNER_CHANGED', 'REFUND_SAVED' => 'REFUND_PROPOSED', 'SALES_CONFIRMED' => 'SALE_MONTH_CONFIRMED',
    ];

    /** The canonical name of a retired one (null = not retired). */
    public static function canonical(string $code): ?string { return self::RETIRED[strtoupper($code)] ?? null; }

    /** The refusal text of a retired name. */
    public static function refusal(string $code): string { return 'Unknown event – use ' . self::RETIRED[strtoupper($code)]; }

    /** Map stored rows once (task template triggers). Returns how many rows changed. */
    public static function migrateStored(): int
    {
        $n = 0;
        if (Schema::hasTable('task_templates')) foreach (self::RETIRED as $old => $new) $n += DB::table('task_templates')->where('trigger_event', $old)->update(['trigger_event' => $new]);
        return $n;
    }

    /**
     * Self-check: names used in the code (EventBus::fire / EventBus::listen with a literal name), listeners registered right now
     * and template triggers, against the catalogue. Returns warnings (plain sentences) for System › Health.
     */
    public static function check(?string $root = null): array
    {
        $known = DB::table('sys_event_types')->pluck('event_code')->flip(); $w = []; $seen = [];
        $root ??= app_path();
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() !== 'php') continue;
            if (! preg_match_all("/EventBus::(fire|listen)\\(\\s*'([A-Z0-9_]+)'/", (string) file_get_contents($f->getPathname()), $m, PREG_SET_ORDER)) continue;
            foreach ($m as [, $kind, $code]) $seen[$code][] = ($kind === 'fire' ? 'raised in ' : 'listened to in ') . basename($f->getPathname());
        }
        foreach (EventBus::listening() as $code) $seen[$code][] = 'a listener';
        if (Schema::hasTable('task_templates')) foreach (DB::table('task_templates')->where('is_active', 1)->whereNotIn('trigger_event', ['MANUAL', ''])->pluck('trigger_event')->unique() as $code) $seen[$code][] = 'a task template trigger';
        ksort($seen);
        foreach ($seen as $code => $where) {
            if (isset($known[$code])) continue;
            $where = implode(', ', array_unique($where));
            $w[] = isset(self::RETIRED[$code]) ? "Event $code is a retired name ($where) – use " . self::RETIRED[$code] . '.' : "Event $code is not in the event catalogue ($where).";
        }
        return $w;
    }
}
