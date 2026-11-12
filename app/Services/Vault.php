<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Secret values of records: passwords, memorable answers and dates, gateway codes, full bank account numbers.
 *  - VAULT  the value is kept encrypted (application key) in sys_vault; the record keeps only •••• (account numbers: •••• + last 4).
 *           Revealing it needs the reveal right (choice VAULT_REVEAL); every reveal and every refusal is logged as REVEAL (never the value).
 *  - CARD   card numbers: only the last 4 digits are kept, nowhere the full number (spec: "card numbers keep the last 4 digits only").
 *  - NEVER  CVV / CVC / PIN: never stored, a typed value is dropped.
 * Which columns: every field the registry marks secret, plus bank account numbers and IBANs, plus every CVV / PIN column.
 */
class Vault
{
    public const MASK = '••••';

    private const NEVER = '/(^|_)(cvv|cvc|pin)(_|$)/i';

    private const CARD = '/(^|_)card_(number|no)$/i';

    private const ACCOUNT = '/(^|_)(account_no|account_number|iban_no|iban)$/i';

    private static array $cache = [];

    /** column → VAULT | CARD | NEVER for one table (only columns the table really has). */
    public static function columns(string $table): array
    {
        if (isset(self::$cache[$table])) return self::$cache[$table];
        $have = collect(DB::select('SELECT column_name AS c, data_type AS ty, character_maximum_length AS len FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?', [$table]))->keyBy('c');
        $secret = DB::table('sys_registry')->where('table_name', $table)->where('secret', 1)->whereNotNull('column_name')->pluck('column_name')->flip();
        $out = [];
        foreach ($have as $c => $x) {
            if (preg_match('/_(enc|ref)$/', $c)) continue;   // already encrypted elsewhere, or only a pointer to a secret kept outside
            if (preg_match(self::NEVER, $c)) $out[$c] = 'NEVER';
            elseif (preg_match(self::CARD, $c)) $out[$c] = 'CARD';
            elseif (isset($secret[$c]) || (preg_match(self::ACCOUNT, $c) && str_starts_with($table, 't_account'))) $out[$c] = 'VAULT';
        }
        return self::$cache[$table] = ['kinds' => $out, 'types' => $have->all()];
    }

    public static function kinds(string $table): array { return self::columns($table)['kinds']; }

    public static function isMasked(mixed $v): bool { return is_string($v) && str_starts_with($v, '•'); }

    private static function digits4(string $v): ?string { $d = preg_replace('/\D/', '', $v); return strlen($d) >= 4 ? substr($d, -4) : null; }

    /** What the record keeps in place of the value. */
    private static function maskFor(string $table, string $col, string $plain, string $kind): ?string
    {
        $t = self::columns($table)['types'][$col] ?? null;
        if (! $t || ! in_array($t->ty, ['varchar', 'char', 'text', 'tinytext', 'mediumtext', 'longtext'], true)) return null;   // a date or number column cannot hold a mask: it stays empty
        $l4 = ($kind === 'CARD' || preg_match(self::ACCOUNT, $col)) ? self::digits4($plain) : null;
        $m = self::MASK . ($l4 ? ' ' . $l4 : '');
        return $t->len ? mb_substr($m, 0, (int) $t->len) : $m;
    }

    /**
     * Before a record is written: secret values are taken out of $data (and replaced by their mask); returns what goes to the vault after the write.
     * A masked value sent back unchanged, or an empty value, keeps the secret as it is; a single "-" removes it.
     */
    public static function prepare(string $table, array &$data, ?int $id): array
    {
        $kinds = self::kinds($table); $put = []; $dropped = [];
        foreach ($kinds as $col => $kind) {
            if (! array_key_exists($col, $data)) continue;
            $v = $data[$col]; $s = is_scalar($v) ? trim((string) $v) : '';
            if ($kind === 'NEVER') { if ($s !== '') $dropped[] = $col; $data[$col] = null; if ($id) unset($data[$col]); continue; }
            if ($s === '' || self::isMasked($s)) { unset($data[$col]); continue; }               // unchanged
            if ($s === '-') { $data[$col] = null; $put[$col] = null; continue; }               // remove the secret
            if ($kind === 'CARD') {
                $data[$col] = self::maskFor($table, $col, $s, 'CARD');
                if (array_key_exists('card_last4', self::columns($table)['types'])) $data['card_last4'] = self::digits4($s);
                continue;                                                                          // the full card number is not kept anywhere
            }
            $data[$col] = self::maskFor($table, $col, $s, $kind); $put[$col] = $s;
        }
        return ['put' => $put, 'dropped' => $dropped];
    }

    /** After the write: keep the secrets of record $id. */
    public static function store(string $table, int $id, array $put): void
    {
        foreach ($put as $col => $plain) {
            $key = ['table_name' => $table, 'record_id' => $id, 'column_name' => $col];
            if ($plain === null) { DB::table('sys_vault')->where($key)->delete(); continue; }
            DB::table('sys_vault')->updateOrInsert($key, ['fk_id_ent' => (int) Context::ent(), 'cipher' => Crypt::encryptString($plain), 'last4' => preg_match(self::ACCOUNT, $col) ? self::digits4($plain) : null, 'updated_by' => Context::userId(), 'updated_at' => now()]);
        }
        if ($put) Audit::log($table, $id, 'UPDATE', array_fill_keys(array_keys($put), ['••••', '••••']), 'Secret kept in the vault', Context::ent());
    }

    /** What a screen shows for a row: every secret masked (also old rows not yet sealed), for every role. */
    public static function maskRow(string $table, array $row): array
    {
        foreach (self::kinds($table) as $col => $kind) {
            if (! array_key_exists($col, $row) || $row[$col] === null || $row[$col] === '' || self::isMasked($row[$col])) continue;
            $row[$col] = $kind === 'NEVER' ? null : (self::maskFor($table, $col, (string) $row[$col], $kind) ?? self::MASK);
        }
        return $row;
    }

    public static function mayReveal(?string $role): bool
    {
        if ($role === 'OWNER') return true;
        return $role === 'MANAGER' && Choices::get('VAULT_REVEAL', Context::ent()) === 'OWNER_MANAGER';
    }

    /** Reveal one secret of a record the user may open. Logged SHOWN or DENIED; the value itself is never logged. */
    public static function reveal(string $formKey, int $id, string $field): array
    {
        $table = Registry::table($formKey) ?? throw new \InvalidArgumentException('Unknown form.');
        $col = collect(Registry::fields($formKey))->first(fn ($f) => $f['field_name'] === $field || $f['column_name'] === $field)['column_name'] ?? $field;
        $kind = self::kinds($table)[$col] ?? null;
        if ($kind !== 'VAULT') throw new \InvalidArgumentException($kind === 'CARD' ? 'Only the last 4 digits of a card number are kept – there is nothing more to reveal.' : ($kind === 'NEVER' ? 'CVV and PIN are never stored.' : 'This field is not a secret.'));
        $role = Context::role();
        if (! self::mayReveal($role) || ! Rights::can($role, 'VIEW', $formKey)) {
            Audit::log($table, $id, 'REVEAL', ['field' => $col, 'result' => 'DENIED'], null, Context::ent());
            throw new \Illuminate\Auth\Access\AuthorizationException('Your role may not reveal secrets. Every attempt is logged.');
        }
        RecordService::get($formKey, $id);   // the record must exist and be in the user's companies (404 otherwise)
        $cell = DB::table('sys_vault')->where(['table_name' => $table, 'record_id' => $id, 'column_name' => $col])->first();
        if ($cell) $value = Crypt::decryptString($cell->cipher);
        else { $raw = DB::table($table)->where(Registry::pk($table), $id)->value($col); $value = self::isMasked($raw) ? null : $raw; }   // an old row not yet sealed
        Audit::log($table, $id, 'REVEAL', ['field' => $col, 'result' => $value === null ? 'EMPTY' : 'SHOWN'], null, Context::ent());
        return ['field' => $field, 'value' => $value];
    }

    /**
     * Seal values already in the tables (old data loaded without masking, rows written before the vault existed):
     * secrets go to the vault, card numbers keep their last 4 digits, CVV / PIN are wiped. Safe to run again.
     */
    public static function sealExisting(array $only = []): array
    {
        $tables = DB::table('sys_registry')->where('secret', 1)->whereNotNull('table_name')->distinct()->pluck('table_name')
            ->merge(collect(DB::select("SELECT DISTINCT table_name AS t FROM information_schema.columns WHERE table_schema = DATABASE() AND (column_name REGEXP '(^|_)(cvv|cvc|pin)(_|$)' OR column_name REGEXP '(^|_)card_(number|no)$' OR (table_name LIKE 't\\\\_account%' AND column_name REGEXP '(^|_)(account_no|account_number|iban_no|iban)$'))"))->pluck('t'))
            ->unique()->filter(fn ($t) => DB::getSchemaBuilder()->hasTable($t) && (! $only || in_array($t, $only, true)))->values();
        $out = [];
        foreach ($tables as $t) {
            $kinds = self::kinds($t); if (! $kinds) continue; $pk = Registry::pk($t); $n = ['table' => $t, 'sealed' => 0, 'cards' => 0, 'wiped' => 0];
            $sel = array_unique(array_merge([$pk], array_keys($kinds), array_key_exists('fk_id_ent', self::columns($t)['types']) ? ['fk_id_ent'] : []));
            DB::table($t)->select($sel)->orderBy($pk)->chunk(500, function ($rows) use ($t, $pk, $kinds, &$n) {
                foreach ($rows as $r) {
                    $set = []; $put = [];
                    foreach ($kinds as $col => $kind) {
                        $v = $r->{$col}; if ($v === null || $v === '' || self::isMasked($v) || $v === '0000-00-00') continue;
                        if ($kind === 'NEVER') { $set[$col] = null; $n['wiped']++; continue; }
                        if ($kind === 'CARD') { $set[$col] = self::maskFor($t, $col, (string) $v, 'CARD'); if (array_key_exists('card_last4', self::columns($t)['types'])) $set['card_last4'] = self::digits4((string) $v); $n['cards']++; continue; }
                        $set[$col] = self::maskFor($t, $col, (string) $v, $kind); $put[$col] = (string) $v; $n['sealed']++;
                    }
                    if (! $set) continue;
                    DB::transaction(function () use ($t, $pk, $r, $set, $put) {
                        foreach ($put as $col => $plain) DB::table('sys_vault')->updateOrInsert(['table_name' => $t, 'record_id' => $r->{$pk}, 'column_name' => $col],
                            ['fk_id_ent' => (int) ($r->fk_id_ent ?? 0), 'cipher' => Crypt::encryptString($plain), 'last4' => preg_match(self::ACCOUNT, $col) ? self::digits4($plain) : null, 'updated_at' => now()]);
                        DB::table($t)->where($pk, $r->{$pk})->update($set);
                    });
                }
            });
            if ($n['sealed'] || $n['cards'] || $n['wiped']) $out[] = $n;
        }
        Audit::log('sys_vault', 0, 'UPDATE', ['sealed' => array_sum(array_column($out, 'sealed')), 'cards' => array_sum(array_column($out, 'cards')), 'wiped' => array_sum(array_column($out, 'wiped'))], 'Existing secrets sealed', null);
        return $out;
    }
}
