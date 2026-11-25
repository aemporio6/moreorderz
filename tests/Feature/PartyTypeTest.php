<?php

namespace Tests\Feature;

use App\Services\Party;
use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-C-1 party type (P / C) and managed / external (M / E) are two columns, filtered independently. */
class PartyTypeTest extends TestCase
{
    public function test_old_external_row_stays_external_person_and_the_two_filters_are_independent(): void
    {
        $mk = fn (string $name, array $x) => (int) DB::table('t_account')->insertGetId(RecordService::fillNotNull('t_account', ['fk_id_ent' => 1, 'first_name' => $name, 'sur_name' => 'Zz', 'f_active' => 1] + $x));
        $ext = $mk('Extperson', ['l_account_kind' => 'E']);
        $this->assertSame(['E', 'P'], array_values((array) DB::table('t_account')->where('id', $ext)->first(['l_account_kind', 'l_party_type'])), 'an old external row is an external person – never turned into a company');
        $co = $mk('Extcompany', ['l_account_kind' => 'E', 'l_party_type' => 'C']); $man = $mk('Managedperson', []);
        $ids = fn (array $f) => array_column(Party::find(1, $f + ['q' => 'Zz']), 'id');
        $this->assertEqualsCanonicalizing([$ext, $co], $ids(['kind' => 'E']), 'External contacts list shows both');
        $this->assertSame([$co], $ids(['party_type' => 'C']), 'filter Company: not the external person');
        $this->assertSame([$ext], $ids(['kind' => 'E', 'party_type' => 'P']));
        $this->assertSame([$man], $ids(['kind' => 'M']));
        $type = (int) DB::table('cat_account_types')->insertGetId(RecordService::fillNotNull('cat_account_types', ['fk_id_ent' => 1, 'account_type_name' => 'Supplier contact', 'role_code' => 'SUPPLIER_CONTACT', 'f_active' => 1]));
        DB::table('t_account_in_types')->insert(['fk_id_account' => $co, 'fk_id_account_type' => $type, 'f_active' => 1]);
        $this->assertSame([$co], array_column(Party::listByRole(1, 'SUPPLIER_CONTACT', ['q' => 'Zz']), 'id'));
        $this->assertSame([], array_column(Party::listByRole(1, (string) $type, ['q' => 'Zz', 'party_type' => 'P']), 'id'));
        $this->assertSame('Company', Party::find(1, ['party_type' => 'C', 'q' => 'Extcompany'])[0]['party_type_label']);
        try { Party::find(1, ['party_type' => 'X']); $this->fail('bad type'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('P (Person) or C (Company)', $e->getMessage()); }
        $u = $this->makeUser('OWNER');
        $this->actingAs($u)->getJson('/api/parties?party_type=C&q=Zz')->assertOk()->assertJsonPath('rows.0.id', $co);
    }
}
