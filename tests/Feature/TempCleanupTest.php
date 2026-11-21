<?php

namespace Tests\Feature;

use App\Services\Settings;
use App\Services\TempCleanup;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_G_CORE R-G_CORE-75: nightly temp file retention – held uploads after 1 day, generator files after the same day; saved mappings stay; counts on Health. */
class TempCleanupTest extends TestCase
{
    private array $made = [];

    private function file(string $dir, string $name, string $when): string
    {
        $d = storage_path('app/' . $dir); if (! is_dir($d)) mkdir($d, 0775, true);
        $p = "$d/$name"; file_put_contents($p, 'x'); touch($p, strtotime($when)); $this->made[] = $p; return $p;
    }

    protected function tearDown(): void { foreach ($this->made as $p) @unlink($p); parent::tearDown(); }

    public function test_the_rule_example_upload_of_05_nov_gone_on_06_nov_0230_mapping_kept(): void
    {
        Settings::set('retention_import_upload_days', '1', 0); Settings::set('retention_generator_days', '0', 0);
        $up = $this->file('ds_tmp', 'tcleanup_05nov.csv', '2026-11-05 15:00:00');
        $fresh = $this->file('ds_tmp', 'tcleanup_06nov.csv', '2026-11-06 01:00:00');
        $gen = $this->file('csv_convert', 'tcleanup_gen.csv', '2026-11-05 23:00:00');
        $map = DB::table('csv_generator_history')->insertGetId(['import_name' => 'TC mapping', 'system_columns' => '{}', 'csv_columns' => '[]', 'import_controller_name' => 'standard_sheet', 'fk_id_account' => 0, 'created_by' => 0, 'created_at' => '2026-11-05 15:00:00']);
        $r = TempCleanup::run(strtotime('2026-11-06 02:30:00'));
        $this->assertFileDoesNotExist($up, 'the upload of 05 Nov is deleted on the 06 Nov run');
        $this->assertFileDoesNotExist($gen, 'generator files are kept only the same day');
        $this->assertFileExists($fresh, 'an upload of today stays');
        $this->assertTrue(DB::table('csv_generator_history')->where('id', $map)->exists(), 'a saved mapping from the same upload remains');
        $this->assertGreaterThanOrEqual(1, $r['uploads']); $this->assertGreaterThanOrEqual(1, $r['generator']);
        $this->actingAs($this->owner);
        $h = $this->getJson('/api/health')->assertOk()->json('temp_cleanup');
        $this->assertSame('OK', $h['status']); $this->assertStringStartsWith('deleted ', $h['text']);
        $this->assertSame(strtotime('2026-11-06 00:00:00'), TempCleanup::cutoff(1, strtotime('2026-11-06 02:30:00')));
        $this->assertSame(strtotime('2026-11-07 00:00:00'), TempCleanup::cutoff(0, strtotime('2026-11-06 02:30:00')));
    }

    public function test_no_clean_up_on_a_page_action_and_the_schedule(): void
    {
        $this->assertStringNotContainsString('@unlink($old)', file_get_contents(app_path('Services/DsUpload.php')));
        $this->assertStringNotContainsString('@unlink($old)', file_get_contents(app_path('Services/CsvConvert.php')));
        $this->assertStringContainsString("mo:cleanup-temp')->dailyAt('02:30')", file_get_contents(base_path('routes/console.php')));
        $this->artisan('mo:cleanup-temp')->assertExitCode(0);
    }
}
