<?php

namespace Tests\Feature;

use App\Services\CartonCalc;
use App\Services\Choices;
use App\Services\Settings;
use App\Services\SourcingSettings;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_B_SOURCING R-B_SOURCING-51: typed sourcing settings at company level with history. */
class SourcingSettingsTest extends TestCase
{
    private function row(string $k): array { return collect($this->getJson('/api/settings/sourcing')->assertOk()->json('rows'))->firstWhere('key', $k); }

    public function test_typed_list_checks_and_history(): void
    {
        $this->actingAs($this->owner);
        $keys = array_column($this->getJson('/api/settings/sourcing')->json('rows'), 'key');
        foreach (['BASE_CURRENCY', 'VOLUMETRIC_DIVISOR', 'LC_FREIGHT_METHOD', 'LC_DUTY_METHOD', 'LC_LOSS_UNITS', 'LC_OVERRIDE_APPROVAL_PCT', 'SRC_MONEY_TOL', 'SRC_QC_PASS_GREEN', 'SRC_PAY_REMIND_DAYS', 'SRC_SHIP_ARRIVING_DAYS', 'DASH_PARTNER', 'DASH_MANAGER', 'INVESTOR_ROLE', 'VOLUMETRIC_DIVISOR_AIR'] as $k) $this->assertContains($k, $keys);
        $duty = $this->row('LC_DUTY_METHOD');
        $this->assertSame(['choice', 'VALUE', ['VALUE', 'QUANTITY', 'WEIGHT', 'CBM']], [$duty['type'], $duty['value'], array_column($duty['options'], 'value')]);
        $this->assertSame('QUANTITY', $this->row('LC_FREIGHT_METHOD')['value'], 'freight default = Quantity (X3)');

        // the rule test: changing LC_DUTY_METHOD writes history
        $this->postJson('/api/settings/sourcing', ['key' => 'LC_DUTY_METHOD', 'value' => 'weight'])->assertOk();
        $this->assertSame('WEIGHT', Choices::get('LC_DUTY_METHOD', 1));
        $h = $this->getJson('/api/settings/sourcing')->json('history.0');
        $this->assertSame(['LC_DUTY_METHOD', 'VALUE', 'WEIGHT'], [$h['key'], $h['from'], $h['to']]);
        $this->postJson('/api/settings/sourcing', ['key' => 'LC_DUTY_METHOD', 'value' => 'WEIGHT'])->assertOk()->assertJsonPath('message', 'No change.');

        // typed checks
        $this->postJson('/api/settings/sourcing', ['key' => 'VOLUMETRIC_DIVISOR', 'value' => '0'])->assertStatus(422)->assertJsonPath('errors.value.0', 'Enter a whole number from 1 to 100000');
        $this->postJson('/api/settings/sourcing', ['key' => 'SRC_QC_PASS_GREEN', 'value' => '120'])->assertStatus(422);
        $this->postJson('/api/settings/sourcing', ['key' => 'BASE_CURRENCY', 'value' => 'XXX'])->assertStatus(422)->assertJsonPath('errors.value.0', 'Choose an active currency');
        $this->postJson('/api/settings/sourcing', ['key' => 'DASH_PARTNER', 'value' => '999999'])->assertStatus(422)->assertJsonPath('errors.value.0', 'Choose active people only');
        $this->postJson('/api/settings/sourcing', ['key' => 'NOPE', 'value' => '1'])->assertStatus(422);

        // per mode divisor: empty = the general one; set → used by the carton calculator
        $this->postJson('/api/settings/sourcing', ['key' => 'VOLUMETRIC_DIVISOR_AIR', 'value' => '6000'])->assertOk();
        $this->assertSame(6000.0, CartonCalc::divisor(1, 'air'));
        $this->postJson('/api/settings/sourcing', ['key' => 'VOLUMETRIC_DIVISOR_AIR', 'value' => ''])->assertOk();
        $this->assertSame((float) (Settings::get('VOLUMETRIC_DIVISOR', 1, 5000) ?: 5000), CartonCalc::divisor(1, 'air'));
        $this->postJson('/api/settings/sourcing', ['key' => 'SRC_PAY_REMIND_DAYS', 'value' => '5'])->assertOk();
        $this->assertSame('5', (string) Settings::get('SRC_PAY_REMIND_DAYS', 1));

        // only the Owner
        $m = $this->makeUser('MANAGER'); \App\Services\Perm::flush();
        $this->actingAs($m)->getJson('/api/settings/sourcing')->assertStatus(403);
    }

    public function test_old_divisor_zero_becomes_5000(): void
    {
        DB::table('sys_settings')->updateOrInsert(['fk_id_ent' => 0, 'setting_key' => 'VOLUMETRIC_DIVISOR'], ['setting_value' => '0']);
        (require base_path('database/migrations/2026_11_21_000078_sourcing_settings.php'))->up();
        Settings::flush();
        $this->assertSame('5000', (string) DB::table('sys_settings')->where('fk_id_ent', 0)->where('setting_key', 'VOLUMETRIC_DIVISOR')->value('setting_value'));
    }

    public function test_qc_pass_rate_green_follows_the_setting(): void
    {
        $this->actingAs($this->owner); \App\Services\Context::set(1, $this->owner->id);
        $f = fn ($t, $d) => (int) DB::table($t)->insertGetId(\App\Services\RecordService::fillNotNull($t, $d + ['fk_id_ent' => 1, 'f_active' => 1, 'created_at' => now()]));
        $cy = $f('t_src_cycles', ['cycle_code' => 'CYC-SS-' . uniqid(), 'product_name' => 'Lamp', 'status' => 'Open', 'cycle_type' => 'FULL', 'created_by' => $this->owner->id]);
        $po = $f('t_src_po', ['po_number' => 'PO-SS-' . uniqid(), 'fk_id_cycle' => $cy, 'status' => 'CONFIRMED', 'po_date' => '2026-11-01']);
        $f('t_src_qc', ['fk_id_cycle' => $cy, 'fk_id_po' => $po, 'qc_code' => 'QC-SS', 'qty_inspected' => 100, 'qty_passed' => 96, 'qty_failed' => 4, 'qc_result' => 'PASSED', 'corrective_action' => 'n/a']);
        $q = $this->getJson("/api/cycle/$cy/extras")->assertOk()->json('qcs.0');
        $this->assertSame([96, true], [(int) $q['pass_pct'], $q['pass_green']]);
        $this->postJson('/api/settings/sourcing', ['key' => 'SRC_QC_PASS_GREEN', 'value' => '97'])->assertOk(); Settings::flush();
        $this->assertFalse($this->getJson("/api/cycle/$cy/extras")->json('qcs.0.pass_green'));
    }
}
