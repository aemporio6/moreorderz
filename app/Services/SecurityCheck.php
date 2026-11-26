<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * MR-1547 security + slow-query self-check (System › Health and `php artisan mo:security-check`): settings that must be right on a live server,
 * files the web must not reach, and foreign-key columns of large tables without an index (the slow-query review). The load test itself is
 * tools/loadtest.mjs, run against the live server (docs/GO_LIVE_ITEMS.md).
 */
class SecurityCheck
{
    public static function run(int $bigRows = 10000): array
    {
        $prod = app()->environment('production'); $out = [];
        $add = function (string $what, bool $ok, string $fix) use (&$out) { $out[] = ['check' => $what, 'ok' => $ok, 'fix' => $ok ? null : $fix]; };
        $add('Debug pages off', ! config('app.debug') || ! $prod, 'Set APP_DEBUG=false in .env and run config:cache.');
        $add('Application key set', strlen((string) config('app.key')) > 20, 'Run php artisan key:generate --force once.');
        $add('Address uses https', ! $prod || str_starts_with((string) config('app.url'), 'https://'), 'Set APP_URL=https://… in .env.');
        $add('Session cookie only over https', ! $prod || (bool) config('session.secure') || str_starts_with((string) config('app.url'), 'https://'), 'Set SESSION_SECURE_COOKIE=true.');
        $add('No .env inside the web folder', ! is_file(public_path('.env')), 'Remove public/.env – the web folder must hold no settings file.');
        $add('No backups inside the web folder', ! glob(public_path('*.sql*')) && ! glob(public_path('*.zip')), 'Move .sql / .zip files out of public/.');
        $add('Backups folder is private', ! str_starts_with(realpath(storage_path('app/backups')) ?: storage_path('app/backups'), realpath(public_path()) ?: public_path()), 'storage/app/backups must not be under public/.');
        $add('Sign-in is locked after wrong passwords', str_contains((string) @file_get_contents(app_path('Http/Controllers/AuthController.php')), 'login_attempts') || DB::getSchemaBuilder()->hasTable('sys_login_attempts'), 'Sign-in lock missing.');
        $slow = [];
        foreach (DB::select("SELECT TABLE_NAME t, TABLE_ROWS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND TABLE_ROWS >= ?", [$bigRows]) as $t) {
            $idx = array_map(fn ($r) => $r->Column_name, array_filter(DB::select("SHOW INDEX FROM `{$t->t}`"), fn ($r) => (int) $r->Seq_in_index === 1));
            foreach (DB::select("SELECT COLUMN_NAME c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND (COLUMN_NAME LIKE 'fk\\_%' OR COLUMN_NAME IN ('fk_product_id','order_no','d_order','sku'))", [$t->t]) as $c)
                if (! in_array($c->c, $idx, true)) $slow[] = ['table' => $t->t, 'rows' => (int) $t->n, 'column' => $c->c];
        }
        $add('Large tables have indexes on their link columns', ! $slow, count($slow) . ' column(s) of large tables without an index – see the list (an index per column fixes it).');
        return ['checks' => $out, 'passed' => count(array_filter($out, fn ($c) => $c['ok'])), 'total' => count($out), 'missing_indexes' => array_slice($slow, 0, 100)];
    }
}
