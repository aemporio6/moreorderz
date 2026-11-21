<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\RecordService;
use App\Services\Registry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** RULES_F_SETUP R-F_SETUP-36 (cascading Director 1 contact pickers) and R-F_SETUP-38 (director / owner picker = OWNER, DIRECTOR or PARTNER). */
class DirectorPickersTest extends TestCase
{
    private function f(string $t, array $d): int { return DB::table($t)->insertGetId(RecordService::fillNotNull($t, $d)); }
    private function opts(string $field, array $parents = []): array { return $this->getJson('/api/options/cat_company_house/' . $field . '?' . http_build_query(['parent' => $parents]))->assertOk()->json(); }
    private function person(string $first, string $sur, string $mid = ''): int { return $this->f('t_account', ['fk_id_ent' => 1, 'account_code' => 'D' . random_int(10000, 99999), 'first_name' => $first, 'middle_name' => $mid, 'sur_name' => $sur, 'f_active' => 1]); }
    private function role(string $code, string $iso): int { return $this->f('cat_account_types', ['fk_id_ent' => 0, 'account_type_name' => ucfirst(strtolower($code)) . ' test', 'iso_account_type' => $iso, 'role_code' => $code, 'f_active' => 1]); }
    private function give(int $p, int $role): void { DB::table('t_account_in_types')->insert(RecordService::fillNotNull('t_account_in_types', ['fk_id_account' => $p, 'fk_id_account_type' => $role, 'f_active' => 1])); }

    protected function setUp(): void { parent::setUp(); $this->actingAs($this->owner); Context::set(1, $this->owner->id); }

    public function test_director_1_reloads_four_pickers_with_only_that_persons_items(): void
    {
        $uk = $this->f('cat_country', ['country_name' => 'Dirland', 'country_code' => 9931, 'iso_code' => 'D1', 'f_active' => 1]);
        $a = $this->person('Asma', 'Ali'); $b = $this->person('Bilal', 'Baig');
        $aOff = $this->f('t_account_addresses', ['fk_id_account' => $a, 'l_address_type' => 'O', 'counti' => 'Kent', 'fk_id_country' => $uk, 'line1' => '1 Office Park', 'f_active' => 1]);
        $aHome = $this->f('t_account_addresses', ['fk_id_account' => $a, 'l_address_type' => 'H', 'counti' => 'Surrey', 'fk_id_country' => $uk, 'line1' => '9 Home Lane', 'f_active' => 1]);
        $this->f('t_account_addresses', ['fk_id_account' => $a, 'l_address_type' => 'H', 'line1' => 'Old flat', 'f_active' => 0]);
        $aMob = $this->f('t_account_mobiles', ['fk_id_account' => $a, 'fk_id_country' => $uk, 'mobile_no' => '7700900111', 'f_active' => 1]);
        $e2 = $this->f('t_account_emails', ['fk_id_account' => $a, 'email' => 'zed@example.test', 'f_active' => 1]); $e1 = $this->f('t_account_emails', ['fk_id_account' => $a, 'email' => 'asma@example.test', 'f_active' => 1]);
        $bAddr = $this->f('t_account_addresses', ['fk_id_account' => $b, 'l_address_type' => 'H', 'line1' => 'B street', 'f_active' => 1]);

        // the rule's test: director A (2 addresses, 1 mobile) → exactly A's 2 addresses, Home first, label "Home|Office <county> <country> <line1>"
        $this->assertSame([[(string) $aHome, 'Home Surrey Dirland 9 Home Lane'], [(string) $aOff, 'Office Kent Dirland 1 Office Park']], $this->opts('fk_director_1_home_addr', ['fk_director_1' => $a])['options']);
        $this->assertSame([(string) $aHome, (string) $aOff], array_column($this->opts('fk_director_1_reg_addr', ['fk_director_1' => $a])['options'], 0));
        $this->assertSame([(string) $aMob], array_column($this->opts('fk_director_1_reg_mobile', ['fk_director_1' => $a])['options'], 0), "A's one mobile");
        $this->assertSame([[(string) $e1, 'asma@example.test'], [(string) $e2, 'zed@example.test']], $this->opts('fk_director_1_reg_email', ['fk_director_1' => $a])['options'], 'e-mails A-Z');
        // switch to B → B's items only; no director → empty with a hint and the rule's placeholder
        $this->assertSame([(string) $bAddr], array_column($this->opts('fk_director_1_home_addr', ['fk_director_1' => $b])['options'], 0));
        $none = $this->opts('fk_director_1_reg_mobile'); $this->assertSame([], $none['options']); $this->assertSame('Choose Director 1 first.', $none['hint']); $this->assertSame('Select Mobile', $none['placeholder']);

        // the form: the four follow Director 1 (a changed Director 1 empties them), placeholders, "+ Add" opens Director 1's sub-list
        $def = collect(Registry::definition('cat_company_house', 'OWNER')['sections'])->flatMap(fn ($s) => $s['fields'])->keyBy('name');
        foreach (['fk_director_1_home_addr' => 'Select Address', 'fk_director_1_reg_addr' => 'Select Address', 'fk_director_1_reg_mobile' => 'Select Mobile', 'fk_director_1_reg_email' => 'Select Email'] as $n => $ph) {
            $this->assertSame('fk_director_1', $def[$n]['cascades_from']); $this->assertSame($ph, $def[$n]['placeholder']); $this->assertSame('fk_director_1', $def[$n]['person_field']); $this->assertNotEmpty($def[$n]['add_form']);
        }

        // on save: an item that is not Director 1's is refused; A's own are kept
        $in = ['company_name' => 'Pickers Ltd', 'fk_country_id' => $uk, 'iso_code' => 'PK' . random_int(100, 999), 'registration_no' => 'SC' . random_int(100000, 999999), 'company_house_telephone' => '0303 1234 500', 'company_email_1' => 'enquiries@companieshouse.gov.uk', 'company_address' => 'Crown Way Cardiff CF14 3UZ', 'fk_director_1' => $a];
        try { RecordService::save('cat_company_house', $in + ['fk_director_1_home_addr' => $bAddr]); $this->fail('B\'s address must be refused'); }
        catch (ValidationException $e) { $this->assertSame("This address is not one of Director 1's. Choose it again.", $e->errors()['fk_director_1_home_addr'][0]); }
        $id = RecordService::save('cat_company_house', $in + ['fk_director_1_home_addr' => $aHome, 'fk_director_1_reg_addr' => $aOff, 'fk_director_1_reg_mobile' => $aMob, 'fk_director_1_reg_email' => $e1])['id'];
        $row = DB::table('cat_company_house')->where('id', $id)->first();
        $this->assertSame([$aHome, $aOff, $aMob, $e1], [(int) $row->fk_director_1_home_addr, (int) $row->fk_director_1_reg_addr, (int) $row->fk_director_1_reg_mobile, (int) $row->fk_director_1_reg_email]);
    }

    public function test_directors_are_owner_director_or_partner_and_add_gives_director(): void
    {
        $owner = $this->role('OWNER', 'TO6'); $dir = $this->role('DIRECTOR', 'TD1'); $sup = $this->role('SUPPLIER', 'TS24');
        $o = $this->person('Omar', 'Owner', 'Bin'); $d = $this->person('Dania', 'Director'); $s = $this->person('Sami', 'Supplier');
        $this->give($o, $owner); $this->give($d, $dir); $this->give($s, $sup);
        foreach (['fk_director_1', 'fk_director_2', 'fk_director_3'] as $n) {
            $ids = array_column($this->opts($n)['options'], 0);
            $this->assertContains((string) $o, $ids, 'an old type-6 (Owner) account is offered'); $this->assertContains((string) $d, $ids);
            $this->assertNotContains((string) $s, $ids, 'a supplier-only party is not offered');
        }
        $this->assertContains([(string) $o, 'Omar Bin Owner'], array_map(fn ($x) => array_slice($x, 0, 2), $this->opts('fk_director_1')['options']), 'label first + middle + surname (a third value is its group)');
        $def = collect(Registry::definition('cat_company_house', 'OWNER')['sections'])->flatMap(fn ($s) => $s['fields'])->keyBy('name');
        $this->assertSame(['roles' => (string) $dir], $def['fk_director_2']['add_preset'], '"+ Add" on a director gives the new party the role DIRECTOR');
    }
}
