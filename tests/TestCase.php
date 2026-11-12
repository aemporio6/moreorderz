<?php

namespace Tests;

use App\Models\User;
use App\Services\Context;
use App\Services\EventBus;
use App\Services\Registry;
use App\Services\Settings;
use App\Services\TaskEngine;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tests run on the real engine (MariaDB, database moreorderz_test). The schema is installed once per run;
 * every test runs inside a transaction that is rolled back, so tests never see each other's rows.
 */
abstract class TestCase extends BaseTestCase
{
    private static bool $installed = false;
    protected int $ent = 1;
    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        if (! self::$installed) {
            if (! Schema::hasTable('sys_registry') || getenv('MO_TEST_FRESH')) Artisan::call('mo:install', ['--fresh' => true]);
            self::$installed = true;
        }
        DB::beginTransaction();
        Registry::forget(); Settings::flush(); EventBus::reset(); TaskEngine::reset(); \App\Services\Rights::flush(); \App\Services\HomeCards::reset();
        app()->register(\App\Providers\AppServiceProvider::class, true);
        if (! DB::table('t_enterprise')->where('id', 1)->exists()) {
            DB::table('t_enterprise')->insert(['id' => 1, 'fk_id_module' => 0, 'iso_ent' => 'TST', 'ent_name' => 'Test Company Ltd', 'ent_address' => '1 Test Street', 'f_active' => 1, 'ent_short' => 'TST']);
        }
        $this->owner = User::create(['login_id' => 'owner@test', 'password_hash' => bcrypt('secret-password'), 'role_code' => 'OWNER', 'display_name' => 'Owner', 'fk_id_ent' => 1, 'f_active' => 1]);
        Context::set(1, $this->owner->id);
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        Context::set(null, null);
        parent::tearDown();
    }

    protected function makeUser(string $role, int $ent = 1): User
    {
        return User::create(['login_id' => strtolower($role) . uniqid() . '@test', 'password_hash' => bcrypt('secret-password'), 'role_code' => $role, 'display_name' => $role, 'fk_id_ent' => $ent, 'f_active' => 1]);
    }
}
