<?php

namespace Tests\Feature;

use App\Services\OldExtras;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Owner 27-11 01:34: the last old setup tables go to their place, once, secrets never read. */
class OldExtrasTest extends TestCase
{
    private static bool $ready = false;

    protected function beforeTransaction(): void
    {
        if (self::$ready) return;
        $c = config('database.connections.' . config('database.default'));
        $pdo = new \PDO("mysql:host={$c['host']};port={$c['port']}", $c['username'], $c['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE DATABASE IF NOT EXISTS mo_old_test CHARACTER SET utf8mb4'); $pdo->exec('USE mo_old_test'); foreach (['vpn_providers', 'vpn_servers', 'loc_warehouses', 'ota_lookups'] as $t) $pdo->exec("DROP TABLE IF EXISTS $t");      // the old-data test database (the test user may use only this one)
        $pdo->exec("CREATE TABLE vpn_providers (id INT PRIMARY KEY, provider_name VARCHAR(80), provider_code VARCHAR(20), service_type VARCHAR(20), website_url VARCHAR(120), f_active TINYINT, api_password VARCHAR(50))");
        $pdo->exec("INSERT INTO vpn_providers VALUES (1,'Proxy House','PH','VPN','https://example.test',1,'never-read')");
        $pdo->exec("CREATE TABLE vpn_servers (id INT PRIMARY KEY, fk_id_ent INT, fk_id_provider INT, server_code VARCHAR(30), ip_address VARCHAR(50), server_label VARCHAR(80), location VARCHAR(80), plan_name VARCHAR(80), notes TEXT, f_active TINYINT)");
        $pdo->exec("INSERT INTO vpn_servers VALUES (11,1,1,'UK-01','10.9.8.7','London 1','London','Static 1',NULL,1),(12,1,1,'UK-02','10.9.8.6','London 2','London','Static 1',NULL,1)");
        $pdo->exec("CREATE TABLE loc_warehouses (id INT PRIMARY KEY, fk_id_location INT, display_name VARCHAR(80), short_code VARCHAR(20), address_line1 VARCHAR(120), address_line2 VARCHAR(120), city VARCHAR(60), postcode VARCHAR(20), country VARCHAR(60))");
        $pdo->exec("INSERT INTO loc_warehouses VALUES (5,NULL,'Leeds unit','LDS','Unit 4 Mill Road',NULL,'Leeds','LS1 1AA','United Kingdom')");
        $pdo->exec("CREATE TABLE ota_lookups (id INT PRIMARY KEY, fk_id_ent INT, lookup_type VARCHAR(40), name VARCHAR(80), sort_order INT, f_active TINYINT)");
        $pdo->exec("INSERT INTO ota_lookups VALUES (1,1,'sim_plan','Pay monthly',1,1),(2,1,'sim_plan','Pay as you go',2,1),(3,1,'pc_kind','Laptop',1,1)");
        self::$ready = true;
    }

    public function test_old_extras_go_to_their_place_once(): void
    {
        $dry = OldExtras::bring('mo_old_test', 1, true);
        $this->assertSame(2, $dry['vpn_servers']); $this->assertSame(0, DB::table('cat_ip_burgers')->where('legacy_table', 'vpn_servers')->count(), 'a trial writes nothing');
        $x = OldExtras::bring('mo_old_test', 1);
        $this->assertSame([1, 2, 1, 3], [$x['vpn_providers'], $x['vpn_servers'], $x['loc_warehouses'], $x['ota_lookups']]);
        $ip = DB::table('cat_ip_burgers')->where('legacy_id', 11)->first();
        $this->assertSame(['10.9.8.7', 'London 1', 'Proxy House'], [$ip->ip_burger_ip, $ip->server_label, $ip->provider_name]);
        $w = DB::table('cat_warehouses')->where('legacy_id', 5)->first(); $this->assertSame(['LDS', 'Leeds unit', 'LS1 1AA'], [$w->warehouse_short, $w->warehouse_name, $w->postcode]);
        $this->assertSame(['Laptop'], DB::table('reference_items as i')->join('cat_references as r', 'r.id', '=', 'i.fk_reference_id')->where('r.ref_code', 'OLD_PC_KIND')->pluck('i.reference_name')->all());
        $again = OldExtras::bring('mo_old_test', 1);
        $this->assertSame([0, 0, 0, 0], [$again['vpn_providers'], $again['vpn_servers'], $again['loc_warehouses'], $again['ota_lookups']], 'once only');
        $this->assertSame(2, OldExtras::here('vpn_servers'));
        $this->assertStringNotContainsString('never-read', json_encode(DB::table('reference_items')->where('legacy_table', 'vpn_providers')->get()));
    }
}
