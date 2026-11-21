<?php

namespace App\Services;

use App\Services\Connect\Messenger;
use Illuminate\Support\Facades\DB;

/**
 * R-FIN-50 monthly summary and missing-report task; R-FIN-32 the nightly / monthly finance jobs.
 * - 1st of the month 08:00: one WhatsApp per partner with any ACTIVE agreement: "Monthly summary: balance £x, profit to date £y. Open your
 *   Partner Account for details." – all companies added up; key MONTHLY:YYYY-MM:<partner>, so a second run the same month sends nothing.
 *   Queued in the one message queue tm_messages (event MONTHLY:YYYY-MM:partner); a partner without a WhatsApp number (or who switched messages off) is logged as not sent.
 * - Hourly: task FIN_REPORT_MISSING:<shop> "Upload the next settlement report for <shop> (last ended <date>)" for each OPEN / CHECKED period
 *   whose month ended more than settlement_overdue_days (17) ago with no later period of the shop. Improved: it closes by itself when the
 *   next settlement arrives (or a later period exists). While it is open, the shop's general FIN_SETTLEMENT_OVERDUE task is not raised too.
 */
class FinanceMonthly
{
    public static function money(float $v, string $cur): string { return ($cur === 'GBP' ? '£' : $cur . ' ') . number_format($v, 2); }

    /** The summaries of one month (default: the current one). Returns what was sent / skipped. */
    public static function summaries(?string $today = null): array
    {
        $today ??= date('Y-m-d'); $month = substr($today, 0, 7); $n = ['sent' => 0, 'not_sent' => 0, 'already' => 0];
        $partners = DB::table('fin_investor_agreements')->where('status', 'ACTIVE')->where('effective_from', '<=', $today)->where(fn ($w) => $w->whereNull('effective_to')->orWhere('effective_to', '>=', $today))
            ->distinct()->pluck('investor_id')->map(fn ($x) => (int) $x);
        foreach ($partners as $p) {
            $key = "MONTHLY:$month:$p";
            if (DB::table('tm_messages')->where('event_key', $key)->exists()) { $n['already']++; continue; }      // R-G_CORE-46 one queue: once per partner and month
            $bal = 0.0; $profit = 0.0; $cur = null;
            foreach (DB::table('fin_investor_ledger')->where('investor_id', $p)->distinct()->pluck('company_id')->merge(DB::table('fin_investor_agreements')->where('investor_id', $p)->distinct()->pluck('company_id'))->unique() as $co) {
                $a = PartnerService::account((int) $co, $p); $bal += (float) $a['balance']; $profit += (float) $a['profit_to_date']; $cur ??= Fx::base((int) $co);
            }
            $cur ??= 'GBP';
            $text = 'Monthly summary: balance ' . self::money($bal, $cur) . ', profit to date ' . self::money($profit, $cur) . '. Open your Partner Account for details.';
            $ent = (int) (DB::table('fin_investor_agreements')->where('investor_id', $p)->where('status', 'ACTIVE')->value('company_id') ?? 0);
            $ph = Messenger::whatsappOf($p, true);
            if ($ph) Messenger::queueWa($ent, $p, $ph, 'MONTHLY_SUMMARY', ['balance' => self::money($bal, $cur), 'profit' => self::money($profit, $cur), 'link' => FinanceSettings::appUrl() . '/#/my-account'], 't_account', $p, $key);
            else Messenger::notSent($ent, $p, null, 'WHATSAPP', 'MONTHLY_SUMMARY', $text, 'No WhatsApp number – or WhatsApp switched off for this person', $key);
            $n[$ph ? 'sent' : 'not_sent']++;
        }
        return $n;
    }

    /** Shops whose report is missing: [shop id => [shop name, last period end]]. */
    public static function missing(int $ent, ?string $today = null): array
    {
        $today ??= date('Y-m-d'); $lim = FinanceSettings::int($ent, 'settlement_overdue_days') ?: 17; $out = [];
        foreach (DB::table('aud_runs as r')->join('t_branches as br', 'br.id', '=', 'r.fk_id_branch')->where('r.fk_id_ent', $ent)->where('br.f_active', 1)->whereIn('r.status', ['OPEN', 'CHECKED'])
            ->orderBy('r.period_month')->get(['r.fk_id_branch', 'r.period_month', 'br.shop_name']) as $r) {
            $end = date('Y-m-t', strtotime($r->period_month . '-01'));
            if ((strtotime($today) - strtotime($end)) / 86400 <= $lim) continue;
            $later = DB::table('aud_runs')->where('fk_id_branch', $r->fk_id_branch)->where('period_month', '>', $r->period_month)->whereNotIn('status', ['SUPERSEDED'])->exists()
                || DB::table('mo_settlements')->where('fk_id_branch', $r->fk_id_branch)->where('statement_date', '>', $end)->exists();
            if (! $later) $out[(int) $r->fk_id_branch] = [$r->shop_name, $end];
        }
        return $out;
    }

    /** Hourly: open / close FIN_REPORT_MISSING per shop. */
    public static function missingTasks(int $ent, ?string $today = null): int
    {
        $problems = [];
        foreach (self::missing($ent, $today) as $shop => [$name, $end])
            $problems[] = ['key' => [$shop], 'title' => "Upload the next settlement report for $name (last ended $end)", 'opt' => ['module' => 't_branches', 'record_id' => $shop, 'fix_url' => '#/settlements', 'shop' => $shop]];
        $r = TaskEngine::sync('FIN_REPORT_MISSING', $problems, null, $ent);
        return $r['opened'] + $r['closed'];
    }
}
