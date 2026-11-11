<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class MoMakeUser extends Command
{
    protected $signature = 'mo:user {login} {--role=OWNER} {--name=} {--company=} {--password=}';
    protected $description = 'Create a login user or reset the password of one (the password is asked for, never stored in plain text)';

    public function handle(): int
    {
        $role = strtoupper($this->option('role'));
        if (! in_array($role, User::ROLES, true)) { $this->error('Role must be one of: ' . implode(', ', User::ROLES)); return 1; }
        $pw = $this->option('password') ?: $this->secret('Password (min 10 characters)');
        if (strlen((string) $pw) < 10) { $this->error('Password too short (min 10).'); return 1; }
        $ent = $this->option('company') ?: DB::table('t_enterprise')->orderBy('id')->value('id');
        $u = User::firstOrNew(['login_id' => $this->argument('login')]);
        $u->fill(['password_hash' => Hash::make($pw), 'role_code' => $role, 'display_name' => $this->option('name') ?: $this->argument('login'), 'fk_id_ent' => $ent, 'f_active' => 1])->save();
        $this->info("User {$u->login_id} ready (role $role, company " . ($ent ?: '—') . ').');
        return 0;
    }
}
