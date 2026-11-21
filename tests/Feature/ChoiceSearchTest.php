<?php

namespace Tests\Feature;

use App\Services\Choices;
use App\Services\DashCards;
use App\Services\Finder;
use App\Services\SearchIndex;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Owner rule 22 Nov 03:47 – part C as Settings › Choices: MR-0474 failing card alert, MR-0498 search minimum characters, MR-0023 amount tolerance, MR-0447 contract amber days. */
class ChoiceSearchTest extends TestCase
{
    public function test_card_alert_search_and_contract_window(): void
    {
        $this->actingAs($this->makeUser('OWNER'));
        $boom = fn () => throw new \RuntimeException('SQLSTATE test');
        DashCards::run(1, 'cardx', 'Card X', $boom);
        $this->assertSame(1, DB::table('tasks')->where('rule_code', 'SYS_ERROR')->where('title', 'like', '%Card X%')->count(), 'default: one admin alert');
        Choices::set('DASH_ERROR_ALERT', 'OFF', 1);
        DashCards::run(1, 'cardy', 'Card Y', $boom);
        $this->assertSame(0, DB::table('tasks')->where('rule_code', 'SYS_ERROR')->where('title', 'like', '%Card Y%')->count(), 'off: only the error log');
        $this->assertSame([1243.75, 1256.25], array_map(fn ($x) => round($x, 2), Finder::amountRange('1250')), 'default ± 0.5 %');
        Choices::set('SEARCH_AMOUNT_TOLERANCE', '2', 1); $this->assertSame([1225.0, 1275.0], array_map(fn ($x) => round($x, 2), Finder::amountRange('1250')));
        $this->assertSame(2, Finder::minChars());
        Choices::set('SEARCH_MIN_CHARS', '3', 1); $this->assertSame([], SearchIndex::find('ab', 1), 'two letters are no full search any more');
        $acc = DB::table('t_account')->insertGetId(\App\Services\RecordService::fillNotNull('t_account', ['fk_id_ent' => 1, 'account_code' => 'JH' . random_int(10000, 99999), 'first_name' => 'Job', 'f_active' => 1]));
        DB::table('t_account_job_history')->insert(\App\Services\RecordService::fillNotNull('t_account_job_history', ['fk_id_account' => $acc, 'f_active' => 1, 'd_job_start_date' => '2025-01-01', 'd_contract_renewal_date' => date('Y-m-d', strtotime('+45 days'))]));
        $soon = fn () => $this->getJson("/api/people/$acc/jobs")->assertOk()->json('0.renewal_soon') ?? $this->getJson("/api/people/$acc/jobs")->json('rows.0.renewal_soon');
        $this->assertFalse((bool) $soon(), 'default 30 days: 45 days away is not amber');
        Choices::set('CONTRACT_RENEWAL_DAYS', '60', 1); $this->assertTrue((bool) $soon(), '60 days: amber');
    }
}
