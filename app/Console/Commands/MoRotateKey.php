<?php

namespace App\Console\Commands;

use App\Services\Audit;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * R-G_CORE-89 key rotation in one job: every value encrypted with the old APP_KEY (vault, UTR / NI numbers, authenticator secrets) is decrypted with it
 * and encrypted again with the current key, so nothing becomes unreadable when the key changes. The old key is read from the environment
 * variable OLD_APP_KEY (never typed on the command line, never stored). A dry run (default) only counts; --apply writes.
 */
class MoRotateKey extends Command
{
    protected $signature = 'mo:rotate-key {--apply : write the re-encrypted values (without it: count only)}';
    protected $description = 'Re-encrypt every stored secret after APP_KEY changed (old key in the OLD_APP_KEY environment variable)';
    public const CELLS = [['sys_vault', 'id', 'cipher', 'string'], ['acc_person_settings', 'id', 'utr_enc', 'string'], ['acc_person_settings', 'id', 'ni_enc', 'string'], ['t_users', 'id', 'totp_secret', 'value'], ['t_account_2fa', 'fk_id_account', 'secret_enc', 'string']];

    public function handle(): int
    {
        $old = (string) getenv('OLD_APP_KEY'); if ($old === '') { $this->error('Set OLD_APP_KEY in the environment first.'); return 1; }
        $raw = str_starts_with($old, 'base64:') ? base64_decode(substr($old, 7)) : $old;
        $oldEnc = new Encrypter($raw, config('app.cipher'));
        $res = self::rotate($oldEnc, (bool) $this->option('apply'));
        foreach ($res['tables'] as $t => $x) $this->line("$t: {$x['ok']} re-encrypted, {$x['failed']} not readable with the old key");
        $this->info($this->option('apply') ? 'Done.' : 'Dry run – nothing written. Run again with --apply.');
        return $res['failed'] ? 2 : 0;
    }

    public static function rotate(Encrypter $old, bool $apply): array
    {
        $out = []; $failed = 0;
        foreach (self::CELLS as [$t, $pk, $col, $kind]) {
            if (! \Illuminate\Support\Facades\Schema::hasColumn($t, $col)) continue;
            $ok = 0; $bad = 0;
            foreach (DB::table($t)->whereNotNull($col)->where($col, '<>', '')->get([$pk, $col]) as $r) {
                try {
                    $plain = $kind === 'string' ? $old->decryptString($r->$col) : $old->decrypt($r->$col);
                    if ($apply) DB::table($t)->where($pk, $r->$pk)->update([$col => $kind === 'string' ? Crypt::encryptString($plain) : encrypt($plain)]);
                    $ok++;
                } catch (\Throwable $e) { $bad++; }
            }
            $out[$t . '.' . $col] = ['ok' => $ok, 'failed' => $bad]; $failed += $bad;
        }
        if ($apply) Audit::log('sys_vault', 0, 'KEY_ROTATED', $out, 'APP_KEY rotated', 0);
        return ['tables' => $out, 'failed' => $failed];
    }
}
