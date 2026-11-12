<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * 12.7 Subscriptions: software and services the company pays again and again (t_account_subscriptions).
 * Cost per month = price ÷ months of the cycle (spec 12.6 / R-C). "Paid" books the expense (category SUBSCRIPTION, through the normal expense
 * with its approval limit) and moves the next due date by one cycle. Logins of a subscription stay in the vault.
 */
class Subscriptions
{
    public const MONTHS = ['WEEKLY' => 12 / 52, 'MONTHLY' => 1, 'QUARTERLY' => 3, 'YEARLY' => 12, 'ONE_OFF' => null];

    public static function page(int $ent): array
    {
        $rows = DB::table('t_account_subscriptions as s')->join('t_account as a', 'a.id', '=', 's.fk_id_account')->leftJoin('cat_subscription_items as i', 'i.id', '=', 's.fk_item_id')
            ->leftJoin('t_branches as b', 'b.id', '=', 's.fk_id_branch')->leftJoin('cat_currency as c', 'c.id', '=', 's.fk_id_currency')
            ->when($ent, fn ($q) => $q->where('a.fk_id_ent', $ent))->orderByRaw('s.f_active DESC, s.next_due_date IS NULL, s.next_due_date')
            ->get(['s.id', 's.f_active', 'i.item_name', 's.username', 's.billing_cycle', 's.price', 'c.currency_code as currency', 's.next_due_date', 's.payment_date', 's.f_auto_renew', 's.subscription_link', 'b.shop_name as shop', 's.fk_id_bank_account',
                DB::raw("TRIM(CONCAT(a.first_name, ' ', a.sur_name)) as holder"), 's.fk_id_account']);
        $today = date('Y-m-d'); $soon = date('Y-m-d', strtotime('+7 days')); $perMonth = []; $over = 0; $due = 0;
        foreach ($rows as $r) {
            $m = self::MONTHS[$r->billing_cycle ?? 'MONTHLY'] ?? null;
            $r->per_month = $m && $r->price !== null ? round((float) $r->price / $m, 2) : null;
            $r->state = ! $r->f_active ? 'OFF' : (! $r->next_due_date ? 'NO_DATE' : ($r->next_due_date < $today ? 'OVERDUE' : ($r->next_due_date <= $soon ? 'DUE' : 'OK')));
            if ($r->f_active && $r->per_month !== null) $perMonth[$r->currency ?: Fx::base($ent)] = round(($perMonth[$r->currency ?: Fx::base($ent)] ?? 0) + $r->per_month, 2);
            if ($r->state === 'OVERDUE') $over++; if ($r->state === 'DUE') $due++;
            unset($r->username);   // the login name stays on the record page; only the vault keeps the password
        }
        return ['rows' => $rows, 'per_month' => $perMonth, 'overdue' => $over, 'due_7' => $due, 'active' => $rows->where('f_active', 1)->count(),
            'banks' => DB::table('fin_bank_accounts')->where('company_id', $ent)->where('is_active', 1)->get(['id', DB::raw("CONCAT(bank_name, ' · ', COALESCE(account_label, '')) as name"), 'currency'])];
    }

    public static function next(string $from, string $cycle): ?string
    {
        return match ($cycle) { 'WEEKLY' => date('Y-m-d', strtotime($from . ' +7 days')), 'MONTHLY' => date('Y-m-d', strtotime($from . ' +1 month')), 'QUARTERLY' => date('Y-m-d', strtotime($from . ' +3 months')), 'YEARLY' => date('Y-m-d', strtotime($from . ' +1 year')), default => null };
    }

    /** Paid: book the expense and move the due date one cycle on. */
    public static function paid(int $ent, int $id, array $d): array
    {
        if (! in_array(Context::role(), ['OWNER', 'FINANCE', 'MANAGER'], true)) throw new \InvalidArgumentException('Only the Owner, a Manager or Finance books payments.');
        $s = DB::table('t_account_subscriptions as s')->join('t_account as a', 'a.id', '=', 's.fk_id_account')->leftJoin('cat_subscription_items as i', 'i.id', '=', 's.fk_item_id')
            ->where('s.id', $id)->when($ent, fn ($q) => $q->where('a.fk_id_ent', $ent))->first(['s.*', 'i.item_name']) ?? throw new \InvalidArgumentException('Subscription not found.');
        $date = $d['date'] ?? date('Y-m-d'); $amount = (float) ($d['amount'] ?? $s->price);
        return DB::transaction(function () use ($ent, $s, $d, $date, $amount) {
            $x = MoneyService::addExpense($ent, ['bank' => (int) ($d['bank'] ?? $s->fk_id_bank_account), 'amount' => $amount, 'category' => 'SUBSCRIPTION', 'branch' => $s->fk_id_branch, 'date' => $date, 'vat_amount' => $d['vat_amount'] ?? null,
                'note' => 'Subscription: ' . ($s->item_name ?: '#' . $s->id) . ' (' . strtolower((string) $s->billing_cycle) . ')']);
            $next = self::next($s->next_due_date ?: $date, (string) $s->billing_cycle);
            DB::table('t_account_subscriptions')->where('id', $s->id)->update(['payment_date' => $date, 'next_due_date' => $next] + ($s->billing_cycle === 'ONE_OFF' ? ['f_active' => 0] : []));
            Audit::log('t_account_subscriptions', $s->id, 'UPDATE', ['paid' => $amount, 'next_due_date' => [$s->next_due_date, $next], 'expense' => $x['id']], null, $ent);
            return $x + ['next_due_date' => $next];
        });
    }

    /** Night: a task for every active subscription whose due date has passed. */
    public static function nightly(): int
    {
        $n = 0;
        foreach (DB::table('t_account_subscriptions as s')->join('t_account as a', 'a.id', '=', 's.fk_id_account')->leftJoin('cat_subscription_items as i', 'i.id', '=', 's.fk_item_id')->where('s.f_active', 1)->whereNotNull('s.next_due_date')->where('s.next_due_date', '<', date('Y-m-d'))->get(['s.id', 's.next_due_date', 'i.item_name', 'a.fk_id_ent']) as $s) {
            if (TaskEngine::open('ACC_SUBSCRIPTION_DUE', [$s->id], 'Subscription ' . ($s->item_name ?: '#' . $s->id) . ' was due ' . $s->next_due_date . ': book the payment or switch it off', ['ent' => (int) $s->fk_id_ent, 'module' => 't_account_subscriptions', 'record_id' => $s->id, 'fix_url' => '#/subscriptions'])) $n++;
        }
        foreach (DB::table('tasks')->where('rule_code', 'ACC_SUBSCRIPTION_DUE')->whereNotIn('status', ['DONE', 'CANCELLED'])->get(['dedupe_key']) as $t) {
            $sid = (int) explode(':', $t->dedupe_key)[1];
            $s = DB::table('t_account_subscriptions')->where('id', $sid)->first();
            if (! $s || ! $s->f_active || ! $s->next_due_date || $s->next_due_date >= date('Y-m-d')) TaskEngine::close('ACC_SUBSCRIPTION_DUE', [$sid], 'Paid or switched off');
        }
        return $n;
    }
}
