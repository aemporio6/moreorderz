<?php

namespace App\Services;

use App\Services\Connect\Messenger;
use Illuminate\Support\Facades\DB;

/**
 * R-FIN-66 the ten finance alerts and R-FIN-67 their screen.
 * Rules live in fin_alert_rules (code, label, on / off, to_roles, channels) – the Owner switches them and chooses who hears and how.
 * One run (hourly, or "Run the checks now") works out, per company and rule, which subjects are true now:
 *   new subject → fin_alerts OPEN + sent on every channel of the rule; still true → sent again only after alert_repeat_days (2, minimum 1);
 *   gone → RESOLVED, its task closed. Dedupe key = code + subject (ref_key). A rule switched off raises nothing and its open alerts are resolved.
 * Channels: TASK raises FIN_<code>:<alert id> (sys_rules FIN_FA1 … FIN_FA10); WHATSAPP (template FIN_ALERT) and EMAIL go to every active login of
 * the rule's roles (OWNER, PARTNER, FINANCE_VA = role FINANCE) through the outbox; every message is logged in fin_messages ("Messages sent").
 * Thresholds come from the settings (free_cash_alert_limit, payout_tolerance, settlement_overdue_days, bank_overdue_days, large_fee_gbp,
 * slow_days, dead_days, agreement_ending_days, supplier_due_days, period_open_days, alert_repeat_days).
 * FA3 / FA4 / FA9 send messages only by default: their tasks are already raised by FIN_SETTLEMENT_OVERDUE, FIN_BANK_STALE and SRC_PAY_DUE.
 */
class FinanceAlerts
{
    /** code => [label, channels by default, sort] */
    public const RULES = [
        'FA1' => ['Free cash below the company limit', 'TASK,WHATSAPP', 1],
        'FA2' => ['Payout and bank deposit differ', 'TASK,WHATSAPP', 2],
        'FA3' => ['No settlement for a shop', 'WHATSAPP', 3],
        'FA4' => ['No bank lines for an account', 'WHATSAPP', 4],
        'FA5' => ['Refund rate jumped for a SKU', 'TASK,WHATSAPP', 5],
        'FA6' => ['Large fee line', 'TASK,WHATSAPP', 6],
        'FA7' => ['Slow or dead stock', 'TASK', 7],
        'FA8' => ['Partner agreement ending soon', 'TASK,WHATSAPP', 8],
        'FA9' => ['Supplier payment due', 'WHATSAPP', 9],
        'FA10' => ['Month still open after its end', 'TASK,WHATSAPP', 10],
    ];
    public const ROLES = ['OWNER' => 'OWNER', 'PARTNER' => 'PARTNER', 'FINANCE_VA' => 'FINANCE'];
    public const CHANNELS = ['WHATSAPP', 'EMAIL', 'TASK'];

    private static function set(int $ent, string $key, float $default): float
    {
        $v = Settings::get($key, $ent, null);
        return $v === null || $v === '' ? $default : (float) $v;
    }

    public static function rules(): array
    {
        return DB::table('fin_alert_rules')->orderBy('sort')->get()->map(fn ($r) => ['code' => $r->code, 'label' => $r->label, 'on' => (bool) $r->is_active,
            'to_roles' => array_values(array_filter(explode(',', (string) $r->to_roles))), 'channels' => array_values(array_filter(explode(',', (string) $r->channels)))])->all();
    }

    /** R-FIN-67: the Owner switches a rule, its roles and channels. */
    public static function saveRule(string $code, array $d): array
    {
        $r = DB::table('fin_alert_rules')->where('code', $code)->first() ?? throw new \InvalidArgumentException('Unknown alert.');
        $set = [];
        if (array_key_exists('on', $d)) $set['is_active'] = (int) (bool) $d['on'];
        if (array_key_exists('to_roles', $d)) {
            $roles = array_values(array_intersect(array_keys(self::ROLES), array_map('strtoupper', (array) $d['to_roles'])));
            if (! $roles) throw new \InvalidArgumentException('Choose at least one role: Owner, Partner or Finance VA.');
            $set['to_roles'] = implode(',', $roles);
        }
        if (array_key_exists('channels', $d)) {
            $ch = array_values(array_intersect(self::CHANNELS, array_map('strtoupper', (array) $d['channels'])));
            if (! $ch) throw new \InvalidArgumentException('Choose at least one way: WhatsApp, e-mail or task.');
            $set['channels'] = implode(',', $ch);
        }
        if ($set) { DB::table('fin_alert_rules')->where('code', $code)->update($set); Audit::log('fin_alert_rules', 0, 'UPDATE', ['code' => $code] + $set, null, (int) Context::ent()); }
        return ['message' => 'Saved'];
    }

    /** R-FIN-60 free cash: bank (books) − committed (supplier schedule remaining + approved withdrawals). */
    public static function freeCash(int $ent): array
    {
        $bank = round(array_sum(array_map(fn ($x) => (float) $x['books_base'], MoneyService::banks($ent))), 2); $today = date('Y-m-d');
        $sched = 0.0;
        foreach (DB::table('t_src_payment_terms as t')->join('t_src_po as p', 'p.id', '=', 't.fk_id_po')->where('t.fk_id_ent', $ent)->where('t.f_active', 1)->where('t.remaining_amount', '>', 0)->whereIn('p.status', ['CONFIRMED', 'SHIPPED', 'RECEIVED'])->get(['t.remaining_amount', 'p.fk_id_currency']) as $t)
            $sched += (float) $t->remaining_amount * Fx::rate(Fx::code($t->fk_id_currency, $ent), $today, $ent);
        $wd = 0.0;
        foreach (DB::table('fin_withdrawals')->where('company_id', $ent)->where('status', 'APPROVED')->get(['amount', 'currency']) as $w) $wd += Fx::toBase((float) $w->amount, $w->currency ?: Fx::base($ent), $today, $ent);
        $committed = round($sched + $wd, 2);
        return ['bank' => $bank, 'committed' => $committed, 'free' => round($bank - $committed, 2)];
    }

    /** Subjects that are true now for one rule: [ref_key => message]. */
    public static function subjects(string $code, int $ent, ?string $today = null): array
    {
        $today ??= date('Y-m-d'); $days = fn (?string $d) => $d ? (int) floor((strtotime($today) - strtotime(substr($d, 0, 10))) / 86400) : null;
        $money = fn ($v) => number_format((float) $v, 2) . ' ' . Fx::base($ent); $out = [];
        switch ($code) {
            case 'FA1':
                $lim = self::set($ent, 'free_cash_alert_limit', 0); if ($lim <= 0) break;
                $f = self::freeCash($ent); if ($f['free'] < $lim) $out['company'] = 'Free cash ' . $money($f['free']) . ' is below the limit ' . $money($lim) . '.';
                break;
            case 'FA2':
                $tol = self::set($ent, 'payout_tolerance', 1);
                foreach (DB::table('mo_settlements as s')->join('fin_bank_transactions as b', 'b.id', '=', 's.fk_id_bank_line')->join('t_branches as br', 'br.id', '=', 's.fk_id_branch')->where('s.fk_id_ent', $ent)
                    ->whereNotExists(fn ($q) => $q->from('aud_runs as r')->whereColumn('r.fk_id_branch', 's.fk_id_branch')->whereRaw("r.period_month = DATE_FORMAT(s.statement_date, '%Y-%m')")->whereIn('r.status', ['LOCKED', 'SUPERSEDED']))
                    ->get(['s.id', 's.statement_id', 's.payable', 'b.amount', 'br.shop_name']) as $s)
                    if (abs((float) $s->payable - (float) $s->amount) > $tol + 0.0001) $out['settlement:' . $s->id] = "{$s->shop_name} payout {$s->statement_id}: settlement " . number_format((float) $s->payable, 2) . ' but bank ' . number_format((float) $s->amount, 2) . '.';
                break;
            case 'FA3':
                $lim = (int) self::set($ent, 'settlement_overdue_days', 17);
                foreach (DB::table('t_branches as br')->join('mo_settlements as s', 's.fk_id_branch', '=', 'br.id')->where('br.fk_id_ent', $ent)->where('br.f_active', 1)->groupBy('br.id', 'br.shop_name')->selectRaw('br.id, br.shop_name, MAX(s.statement_date) d')->get() as $s)
                    if ($days($s->d) >= $lim) $out['shop:' . $s->id] = "No settlement for {$s->shop_name} for " . $days($s->d) . " days (last {$s->d}).";
                break;
            case 'FA4':
                $lim = (int) self::set($ent, 'bank_overdue_days', 8);
                foreach (DB::table('fin_bank_accounts as b')->join('fin_bank_transactions as t', 't.bank_account_id', '=', 'b.id')->where('b.company_id', $ent)->where('b.is_active', 1)->groupBy('b.id', 'b.bank_name', 'b.account_label')->selectRaw('b.id, b.bank_name, b.account_label, MAX(t.txn_date) d')->get() as $b)
                    if ($days($b->d) >= $lim) $out['bank:' . $b->id] = 'No bank lines for ' . trim($b->bank_name . ' ' . $b->account_label) . ' for ' . $days($b->d) . " days (last {$b->d}).";
                break;
            case 'FA5':
                // per SKU and month of the last 90 days: refunded units ÷ units; the newest month against the average of the earlier ones (≥ 2 months, average > 0)
                $from = date('Y-m-01', strtotime("$today -90 days"));
                $rows = DB::table('t_sales as s')->join('t_product_vendors as p', 'p.id', '=', 's.fk_product_id')->where('s.fk_id_ent', $ent)->where('s.d_order', '>=', $from)->where('s.d_order', '<=', $today . ' 23:59:59')->where('s.status', '<>', 'Cancelled')
                    ->groupBy('p.SKU_VENDOR', DB::raw("DATE_FORMAT(s.d_order, '%Y-%m')"))->selectRaw("p.SKU_VENDOR sku, DATE_FORMAT(s.d_order, '%Y-%m') m, SUM(s.qty) u, SUM(CASE WHEN s.status = 'Refunded' THEN s.qty ELSE 0 END) r")->orderBy('m')->get()->groupBy('sku');
                foreach ($rows as $sku => $ms) {
                    $rates = $ms->filter(fn ($x) => (float) $x->u > 0)->map(fn ($x) => (float) $x->r / (float) $x->u)->values()->all(); if (count($rates) < 2) continue;
                    $last = array_pop($rates); $avg = array_sum($rates) / count($rates);
                    if ($avg > 0 && $last > 2 * $avg && $last >= 0.02) $out['sku:' . $sku] = "Refund rate of $sku is " . round($last * 100, 1) . ' % – more than twice its 90-day average ' . round($avg * 100, 1) . ' %.';
                }
                break;
            case 'FA6':
                $big = self::set($ent, 'large_fee_gbp', 250);
                foreach (DB::table('fin_settlement_lines as l')->leftJoin('t_branches as br', 'br.id', '=', 'l.branch_id')->where('l.company_id', $ent)->where('l.bucket', 'FEE')->where('l.posted_date', '>=', date('Y-m-d', strtotime("$today -30 days")))->get(['l.id', 'l.amount', 'l.currency', 'l.posted_date', 'l.amount_description', 'br.shop_name']) as $l) {
                    $gbp = Fx::toBase((float) $l->amount, $l->currency ?: Fx::base($ent), (string) $l->posted_date, $ent);
                    if ($gbp <= -$big) $out['fee:' . $l->id] = 'Large fee ' . $money($gbp) . ' on ' . ($l->shop_name ?: 'a shop') . " ({$l->posted_date}" . ($l->amount_description ? ', ' . $l->amount_description : '') . ').';
                }
                break;
            case 'FA7':
                $slow = (int) self::set($ent, 'slow_days', 90); $dead = (int) self::set($ent, 'dead_days', 180);
                $last = DB::table('t_sales as s')->join('t_product_vendors as p', 'p.id', '=', 's.fk_product_id')->where('s.fk_id_ent', $ent)->whereNotIn('s.status', ['Cancelled'])->where('s.d_order', '<=', $today . ' 23:59:59')->groupBy('p.SKU_VENDOR')->selectRaw('p.SKU_VENDOR sku, MAX(s.d_order) d')->pluck('d', 'sku');
                foreach (DB::table('fin_lots')->where('company_id', $ent)->where('qty_left', '>', 0)->groupBy('sku')->selectRaw('sku, SUM(qty_left * COALESCE(unit_cost_gbp, 0)) v, MIN(received_date) first')->get() as $s) {
                    if ((float) $s->v <= 0) continue;      // only stock with a sellable value
                    $d = $days($last[$s->sku] ?? $s->first); if ($d === null || $d < $slow) continue;
                    $out['sku:' . $s->sku] = ($d >= $dead ? 'DEAD' : 'SLOW') . ": {$s->sku} – no sale for $d days, stock " . $money($s->v) . '.';
                }
                break;
            case 'FA8':
                $n = (int) self::set($ent, 'agreement_ending_days', 30);
                foreach (DB::table('fin_investor_agreements')->where('company_id', $ent)->where('status', 'ACTIVE')->whereBetween('effective_to', [$today, date('Y-m-d', strtotime("$today +$n days"))])->get(['id', 'investor_id', 'scope', 'effective_to']) as $a)
                    $out['agreement:' . $a->id] = 'Agreement of ' . PartnerService::name((int) $a->investor_id) . " ({$a->scope}) ends {$a->effective_to}.";
                break;
            case 'FA9':
                $n = (int) self::set($ent, 'supplier_due_days', 3);
                foreach (DB::table('t_src_payment_terms as t')->join('t_src_po as p', 'p.id', '=', 't.fk_id_po')->where('t.fk_id_ent', $ent)->where('t.f_active', 1)->where('t.remaining_amount', '>', 0)->whereIn('p.status', ['CONFIRMED', 'SHIPPED', 'RECEIVED'])
                    ->where('t.due_date', '<=', date('Y-m-d', strtotime("$today +$n days")))->get(['t.id', 't.due_date', 't.remaining_amount', 't.milestone', 'p.po_number']) as $t)
                    $out['term:' . $t->id] = "Supplier payment {$t->po_number} " . ($t->milestone ?: '') . ' ' . number_format((float) $t->remaining_amount, 2) . ($t->due_date < $today ? " was due {$t->due_date}." : " due {$t->due_date}.");
                break;
            case 'FA10':
                $n = (int) self::set($ent, 'period_open_days', 10);
                foreach (DB::table('aud_runs as r')->leftJoin('t_branches as br', 'br.id', '=', 'r.fk_id_branch')->where('r.fk_id_ent', $ent)->whereIn('r.status', ['OPEN', 'CHECKED'])->get(['r.id', 'r.period_month', 'r.status', 'br.shop_name']) as $r) {
                    $end = date('Y-m-t', strtotime($r->period_month . '-01'));
                    if ($days($end) >= $n) $out['run:' . $r->id] = ($r->shop_name ?: 'Shop') . " {$r->period_month} is still {$r->status} " . $days($end) . ' days after the month ended.';
                }
                break;
        }
        return $out;
    }

    /** One run over every rule of a company. $now for tests. Returns counts. */
    public static function run(int $ent, ?string $now = null): array
    {
        $now ??= date('Y-m-d H:i:s'); $today = substr($now, 0, 10);
        $repeat = max(1, (int) self::set($ent, 'alert_repeat_days', 2));
        $n = ['raised' => 0, 'repeated' => 0, 'resolved' => 0, 'messages' => 0];
        foreach (DB::table('fin_alert_rules')->orderBy('sort')->get() as $rule) {
            $true = $rule->is_active ? self::subjects($rule->code, $ent, $today) : [];
            $open = DB::table('fin_alerts')->where('company_id', $ent)->where('code', $rule->code)->where('status', 'OPEN')->get()->keyBy('ref_key');
            foreach ($true as $key => $msg) {
                $a = $open[$key] ?? null;
                if (! $a) {
                    $id = (int) DB::table('fin_alerts')->insertGetId(['code' => $rule->code, 'company_id' => $ent, 'ref_key' => mb_substr($key, 0, 190), 'message' => mb_substr($msg, 0, 500), 'status' => 'OPEN', 'first_at' => $now, 'last_sent_at' => $now]);
                    $n['messages'] += self::send($rule, $ent, $id, $key, $msg, $now); $n['raised']++;
                } elseif (strtotime($a->last_sent_at) <= strtotime($now) - $repeat * 86400 + 60) {      // still true: again every alert_repeat_days
                    DB::table('fin_alerts')->where('id', $a->id)->update(['message' => mb_substr($msg, 0, 500), 'last_sent_at' => $now]);
                    $n['messages'] += self::send($rule, $ent, (int) $a->id, $key, $msg, $now); $n['repeated']++;
                }
            }
            foreach ($open as $key => $a) if (! isset($true[$key])) {
                DB::table('fin_alerts')->where('id', $a->id)->update(['status' => 'RESOLVED', 'resolved_at' => $now]);
                TaskEngine::close('FIN_' . $rule->code, [$a->id], $rule->is_active ? 'Alert resolved' : 'Alert switched off');
                $n['resolved']++;
            }
        }
        return $n;
    }

    /** Send one alert on the rule's channels; returns how many messages were logged. */
    private static function send(object $rule, int $ent, int $alertId, string $key, string $msg, string $now): int
    {
        $ch = array_filter(explode(',', (string) $rule->channels)); $n = 0; $ev = $rule->code . ':' . $key . '|' . $now;      // one message per alert, person, channel and send time (fin_messages is unique on event + channel)
        $roles = array_values(array_filter(array_map(fn ($r) => self::ROLES[$r] ?? null, explode(',', (string) $rule->to_roles))));
        if (in_array('TASK', $ch, true)) {
            $owner = DB::table('t_users')->whereIn('role_code', $roles ?: ['OWNER'])->where('f_active', 1)->where(fn ($w) => $w->where('fk_id_ent', $ent)->orWhereNull('fk_id_ent')->orWhere('fk_id_ent', 0))->orderByRaw("role_code = 'OWNER' DESC")->orderBy('id')->value('id');
            $was = TaskEngine::isOpen('FIN_' . $rule->code, [$alertId]);      // a repeat keeps the same open task (no second message for it)
            $t = TaskEngine::open('FIN_' . $rule->code, [$alertId], $rule->code . ' · ' . $msg, ['ent' => $ent, 'module' => 'fin_alerts', 'record_id' => $alertId, 'fix_url' => '#/fin-alerts'] + ($owner ? ['owner_user_id' => (int) $owner] : []));
            if ($t && ! $was) { DB::table('fin_alerts')->where('id', $alertId)->update(['task_id' => $t]); $n += self::log(null, $owner ? (int) $owner : null, $rule->code, 'TASK', $msg, $ev, 'SENT'); }
        }
        $link = rtrim((string) config('app.url'), '/') . '/#/fin-alerts';
        foreach (DB::table('t_users')->whereIn('role_code', $roles)->where('f_active', 1)->where(fn ($w) => $w->where('fk_id_ent', $ent)->orWhereNull('fk_id_ent')->orWhere('fk_id_ent', 0))->get(['id', 'fk_id_account', 'display_name']) as $u) {
            $acc = (int) $u->fk_id_account;
            if (in_array('WHATSAPP', $ch, true)) {
                $ph = $acc ? Messenger::whatsappOf($acc, true) : null;
                if ($ph) { Messenger::queueWa($ent, $acc, $ph, 'FIN_ALERT', ['code' => $rule->code, 'text' => $msg, 'link' => $link], 'fin_alerts', $alertId); $n += self::log(null, (int) $u->id, $rule->code, 'WHATSAPP', $msg, $ev, 'QUEUED'); }
                else $n += self::log(null, (int) $u->id, $rule->code, 'WHATSAPP', 'Not sent: no WhatsApp number (or switched off) – ' . $msg, $ev, 'FAILED');
            }
            if (in_array('EMAIL', $ch, true)) {
                $to = $acc ? Messenger::emailOf($acc, true) : null;
                if ($to) { Messenger::queueEmail($ent, $acc, $to, 'Finance alert ' . $rule->code . ': ' . $rule->label, $msg . "\n\n" . $link, null, 'FIN_ALERT', 'fin_alerts', $alertId); $n += self::log(null, (int) $u->id, $rule->code, 'EMAIL', $msg, $ev, 'QUEUED'); }
                else $n += self::log(null, (int) $u->id, $rule->code, 'EMAIL', 'Not sent: no e-mail address (or switched off) – ' . $msg, $ev, 'FAILED');
            }
        }
        return $n;
    }

    private static function log(?int $investor, ?int $user, string $kind, string $channel, string $text, string $ev, string $status): int
    {
        DB::table('fin_messages')->insertOrIgnore(['investor_id' => $investor, 'user_id' => $user, 'kind' => mb_substr($kind, 0, 30), 'channel' => $channel, 'text' => mb_substr($text, 0, 600), 'event_key' => mb_substr($ev . '|u' . (int) $user, 0, 190), 'status' => $status, 'created_at' => now(), 'sent_at' => $status === 'SENT' ? now() : null]);
        return 1;
    }

    /** R-FIN-67 screen data: rules, open alerts (paged), messages sent (search, paged). */
    public static function screen(int $ent, int $page = 1, ?string $q = null, int $mpage = 1): array
    {
        $per = 25;
        $aq = DB::table('fin_alerts')->where('company_id', $ent)->where('status', 'OPEN');
        $alerts = (clone $aq)->orderByDesc('first_at')->orderByDesc('id')->offset(($page - 1) * $per)->limit($per)->get(['id', 'code', 'ref_key', 'message', 'first_at', 'last_sent_at', 'task_id'])->all();
        $mq = DB::table('fin_messages as m')->leftJoin('t_users as u', 'u.id', '=', 'm.user_id')->whereIn('m.kind', array_keys(self::RULES))
            ->when($q, fn ($x) => $x->where(fn ($w) => $w->where('m.text', 'like', "%$q%")->orWhere('m.kind', 'like', "$q%")->orWhere('u.display_name', 'like', "%$q%")));
        $msgs = (clone $mq)->orderByDesc('m.id')->offset(($mpage - 1) * $per)->limit($per)->get(['m.id', 'm.kind', 'm.channel', 'm.text', 'm.status', 'm.created_at', 'u.display_name as to_name'])->all();
        return ['rules' => self::rules(), 'roles' => array_keys(self::ROLES), 'channels' => self::CHANNELS, 'alerts' => $alerts, 'alerts_total' => (clone $aq)->count(), 'page' => $page,
            'messages' => $msgs, 'messages_total' => (clone $mq)->count(), 'mpage' => $mpage, 'per' => $per];
    }
}
