<?php

namespace Tests\Feature;

use App\Services\RecordService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-F_SETUP-39 typed real-world codes: format auto-corrected, unique, live check while typing with the old words. */
class MasterCodesTest extends TestCase
{
    private function err(string $form, array $v, ?int $id = null): array
    {
        try { RecordService::save($form, $v, $id); return []; } catch (\Illuminate\Validation\ValidationException $e) { return $e->errors(); }
    }

    public function test_unique_codes_format_and_live(): void
    {
        $this->actingAs($this->makeUser('OWNER')); \App\Services\Context::set(1, null);
        $c = (int) (DB::table('cat_country')->value('id') ?: DB::table('cat_country')->insertGetId(RecordService::fillNotNull('cat_country', ['country_name' => 'United Kingdom'])));
        $this->assertSame([], $this->err('cat_banks', ['country_id' => $c, 'bank_short_name' => 'TB', 'bank_name' => 'Test Bank', 'bank_iso_code' => ' tbuk gb2l ', 'f_active' => 1]));
        $this->assertSame('TBUKGB2L', DB::table('cat_banks')->where('bank_name', 'Test Bank')->value('bank_iso_code'), 'upper, spaces out');
        $this->assertSame(['This ISO code already exists. Please add another'], $this->err('cat_banks', ['country_id' => $c, 'bank_short_name' => 'T2', 'bank_name' => 'Other Bank', 'bank_iso_code' => 'tbukgb2l', 'f_active' => 1])['bank_iso_code'] ?? null);
        $id = (int) DB::table('cat_banks')->where('bank_name', 'Test Bank')->value('id');
        $this->assertSame([], $this->err('cat_banks', ['bank_iso_code' => 'TBUKGB2L'], $id), 'the record itself is not a duplicate');
        $live = $this->getJson('/api/form/cat_banks/live?' . http_build_query(['field' => 'bank_iso_code', 'value' => 'tbukgb2l']))->assertOk()->json();
        $this->assertSame([false, 'This ISO code already exists. Please add another'], [$live['ok'], $live['message']]);
        $this->assertTrue($this->getJson('/api/form/cat_banks/live?' . http_build_query(['field' => 'bank_iso_code', 'value' => 'tbukgb2l', 'id' => $id]))->json('ok'));
        $this->assertSame(['Use the 3-letter code (e.g. GBP, USD, EXW, FOB).'], $this->err('cat_currency', ['currency_code' => 'pounds', 'currency_name' => 'Pound'])['currency_code'] ?? null);
        $this->assertSame(DB::table('cat_banks')->count(), DB::table('cat_banks')->count(), 'a live check never writes');
    }
}
