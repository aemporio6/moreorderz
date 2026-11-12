<?php

namespace App\Console\Commands;

use App\Services\Vault;
use Illuminate\Console\Command;

/** Seal secrets already in the tables (after an old-data import without masking): vault, last 4 digits of cards, CVV / PIN wiped. */
class MoVaultSeal extends Command
{
    protected $signature = 'mo:vault-seal {--only=* : only these tables}';
    protected $description = 'Move secret values already in the tables into the encrypted vault; keep only the last 4 digits of card numbers; wipe CVV and PIN';

    public function handle(): int
    {
        $rows = Vault::sealExisting($this->option('only'));
        if (! $rows) { $this->line('Nothing to seal: every secret is already in the vault.'); return 0; }
        $this->table(['Table', 'Secrets sealed', 'Card numbers cut to 4 digits', 'CVV / PIN wiped'], array_map(fn ($r) => [$r['table'], $r['sealed'], $r['cards'], $r['wiped']], $rows));
        return 0;
    }
}
