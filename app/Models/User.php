<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

/** Login user. Table t_users (old tool) with new hashed password and role. The old plain `pass` column is never read. */
class User extends Authenticatable
{
    protected $table = 't_users';
    public $timestamps = false;
    protected $guarded = [];
    protected $hidden = ['pass', 'password_hash', 'remember_token', 'totp_secret'];

    public const ROLES = ['OWNER', 'MANAGER', 'PARTNER', 'FINANCE', 'ACCOUNTANT', 'SOURCING', 'WAREHOUSE', 'TL', 'VA'];

    public function getAuthPassword(): string
    {
        return (string) $this->password_hash;
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function isOwner(): bool
    {
        return $this->role_code === 'OWNER';
    }
}
