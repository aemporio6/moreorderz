<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * MR-1538 data health score: one score per company on Home, from five parts of the last 90 days –
 * sale lines without a cost, bank lines not matched, shops whose sales import is stale, open duplicate tasks, record completeness (R-G_CORE-108).
 * Each part is 0–100 (100 = clean); the score is their average. Every part names its count and where to fix it.
 */
class DataHealth
{
    public static function score(int $ent, ?string $today = null): array
    {
        $today ??= now()->toDateString(); $from = date('Y-m-d', strtotime("$today -90 days"));
        $pct = fn (int $bad, int $all) => $all > 0 ? (int) round(100 - $bad / $all * 100) : 100;
        $sales = DB::table('t_sales')->where('fk_id_ent', $ent)->where('d_order', '>=', $from)->where(fn ($w) => $w->whereNull('status')->orWhere('status', '<>', 'Cancelled'));
        $sAll = (clone $sales)->count(); $sBad = (clone $sales)->where(fn ($w) => $w->whereNull('cost_gbp')->orWhere('cost_gbp', 0))->count();
        $bank = DB::table('fin_bank_transactions')->where('company_id', $ent)->where('txn_date', '>=', $from);
        $bAll = (clone $bank)->count(); $bBad = (clone $bank)->where('match_status', 'UNMATCHED')->count();
        $last = DB::table('t_sale_imports')->where('fk_id_ent', $ent)->whereNotNull('fk_id_branch')->groupBy('fk_id_branch')->selectRaw('fk_id_branch, MAX(COALESCE(confirmed_at, imported_at)) last')->pluck('last', 'fk_id_branch');
        $stale = $last->filter(fn ($d) => ! $d || substr((string) $d, 0, 10) < date('Y-m-d', strtotime("$today -35 days")))->count();
        $dups = (int) DB::table('tasks')->where('fk_id_ent', $ent)->where('rule_code', 'like', '%DUP%')->whereNotIn('status', ['DONE', 'APPROVED', 'CANCELLED'])->count();
        $dq = DB::table('sys_dq_scores')->where('fk_id_ent', $ent)->avg('score_pct');
        $parts = [
            ['key' => 'costs', 'label' => 'Sale lines with a cost', 'score' => $pct($sBad, $sAll), 'bad' => $sBad, 'of' => $sAll, 'text' => "$sBad of $sAll sale lines have no cost", 'go' => '#/sales'],
            ['key' => 'bank', 'label' => 'Bank lines matched', 'score' => $pct($bBad, $bAll), 'bad' => $bBad, 'of' => $bAll, 'text' => "$bBad of $bAll bank lines not matched", 'go' => '#/money'],
            ['key' => 'imports', 'label' => 'Sales imports up to date', 'score' => $pct($stale, $last->count()), 'bad' => $stale, 'of' => $last->count(), 'text' => "$stale of {$last->count()} shops without a sales import for 35 days", 'go' => '#/sales'],
            ['key' => 'duplicates', 'label' => 'No duplicates waiting', 'score' => max(0, 100 - $dups * 5), 'bad' => $dups, 'of' => null, 'text' => "$dups duplicate task(s) open", 'go' => '#/tasks'],
            ['key' => 'records', 'label' => 'Records complete', 'score' => $dq === null ? 100 : (int) round((float) $dq), 'bad' => null, 'of' => null, 'text' => $dq === null ? 'not scored yet' : 'average completeness ' . (int) round((float) $dq) . ' %', 'go' => '#/data-quality'],
        ];
        $score = (int) round(array_sum(array_column($parts, 'score')) / count($parts));
        usort($parts, fn ($a, $b) => $a['score'] <=> $b['score']);
        return ['score' => $score, 'tone' => $score >= 90 ? 'done' : ($score >= 70 ? 'warn' : 'err'), 'parts' => $parts];
    }
}
