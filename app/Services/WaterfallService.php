<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The profit waterfall of one shop × month (build spec PHASE_06 §6.3, rules R-INV-5 / 6, R-FIN-41 / 47 / 48 / 74 / 75), in this fixed order:
 *   shop profit (after its share of the overheads, see Choices)
 *   − VA salary − owner fixed fee − handler fixed fee            (split rule of the shop, else the Default rule)
 *   − loss brought forward from the last locked month            → nothing is shared while this is not above zero; the rest is carried forward
 *   − tax reserve                                                 → distributable
 *   − VA share % − handler share %
 *   then by the shop's profit route (one route per shop × month, both built):
 *     LOT_PROFIT          lot owners earn the profit of the units sold from their own lots, then the agreements share the rest (stacking rule per agreement)
 *     INVESTOR_INVOICING  investor % of the distributable goes to the lot owners by the cost of their units sold; owner % is shown; the rest stays in the company
 * Also here: partner statements as PDF (versioned, never deleted) and the adjustment to a locked month (goes forward, statement v+1).
 */
class WaterfallService
{
    public const ROUTES = ['LOT_PROFIT' => 'Lot profit: lot owners earn on their own lots, agreements share the rest', 'INVESTOR_INVOICING' => 'Investor invoicing: the four-way split rule (investor / owner / VA / handler)'];
    public const STACKING = ['SPLIT_UNITS' => 'Own-lot profit on his units, share only on the other units', 'NONE' => 'No share while he owns a lot of that PO', 'CAPPED' => 'Both, the share capped at a % of the PO profit', 'FULL_STACK' => 'Both in full (Owner only)'];

    // ---------- split rules ----------
    /** The rule of a shop valid on a date: the shop's own (latest start, then highest id), else the Default rule (shop 0). */
    public static function split(int $ent, int $branchId, string $date): ?object
    {
        foreach ([$branchId, 0] as $b) {
            $r = DB::table('t_inv_profit_splits')->where('fk_id_ent', $ent)->where('fk_id_branch', $b)->where('f_active', 1)->where('d_effective_from', '<=', $date)->orderByDesc('d_effective_from')->orderByDesc('id')->first();
            if ($r) return $r;
        }
        return null;
    }

    public static function saveSplit(int $ent, array $d): array
    {
        if (Context::role() !== 'OWNER') throw new \InvalidArgumentException('Only the Owner changes a profit split.');
        $om = $d['owner_mode'] ?? 'SHARE'; $vm = $d['va_mode'] ?? 'SHARE'; $hm = $d['handler_mode'] ?? 'SHARE';
        if (! in_array($om, ['SHARE', 'FIXED', 'FIXED_SHARE'], true) || ! in_array($hm, ['SHARE', 'FIXED', 'FIXED_SHARE'], true) || ! in_array($vm, ['SHARE', 'SALARY', 'SALARY_SHARE'], true)) throw new \InvalidArgumentException('Choose the mode of the Owner, the VA and the Handler.');
        if (empty($d['d_effective_from']) || ! strtotime($d['d_effective_from'])) throw new \InvalidArgumentException('Give the date this split starts.');
        $branch = (int) ($d['fk_id_branch'] ?? 0);
        if ($branch && ! DB::table('t_branches')->where('id', $branch)->where('fk_id_ent', $ent)->exists()) throw new \InvalidArgumentException('Choose a shop of this company, or "Default (all shops)".');
        // a mode clears the % or the fee it does not use
        $p = ['investor_pct' => (float) ($d['investor_pct'] ?? 0), 'owner_pct' => $om === 'FIXED' ? 0.0 : (float) ($d['owner_pct'] ?? 0), 'va_pct' => $vm === 'SALARY' ? 0.0 : (float) ($d['va_pct'] ?? 0), 'handler_pct' => $hm === 'FIXED' ? 0.0 : (float) ($d['handler_pct'] ?? 0)];
        foreach ($p as $v) if ($v < 0 || $v > 100) throw new \InvalidArgumentException('Percentages must be between 0 and 100.');
        $sum = array_sum($p);
        if (abs($sum - 100) > 0.001) throw new \InvalidArgumentException('The four percentages must add up to exactly 100%. They add up to ' . rtrim(rtrim(number_format($sum, 3), '0'), '.') . '%.');
        $of = $om === 'SHARE' ? 0.0 : (float) ($d['owner_fixed'] ?? 0); $hf = $hm === 'SHARE' ? 0.0 : (float) ($d['handler_fixed'] ?? 0); $vs = $vm === 'SHARE' ? null : (isset($d['va_salary_override']) && $d['va_salary_override'] !== '' ? (float) $d['va_salary_override'] : null);
        if ($om !== 'SHARE' && $of <= 0) throw new \InvalidArgumentException('Enter the Account Owner fixed fee.');
        if ($hm !== 'SHARE' && $hf <= 0) throw new \InvalidArgumentException('Enter the Handler fixed fee.');
        if ($vm !== 'SHARE' && ($vs === null || $vs <= 0)) throw new \InvalidArgumentException('Enter the VA salary for the month.');
        $from = date('Y-m-d', strtotime($d['d_effective_from']));
        $locked = DB::table('aud_runs')->where('fk_id_ent', $ent)->where('status', 'LOCKED')->when($branch, fn ($q) => $q->where('fk_id_branch', $branch))->where('period_month', '>=', substr($from, 0, 7))->max('period_month');
        if ($locked) throw new \InvalidArgumentException('Month ' . date('M Y', strtotime($locked . '-01')) . ' is locked – a split cannot start in or before a locked month. Start it from the next open month.');
        $row = ['fk_id_ent' => $ent, 'fk_id_branch' => $branch, 'd_effective_from' => $from] + $p + ['owner_mode' => $om, 'owner_fixed' => $of, 'va_mode' => $vm, 'va_salary_override' => $vs, 'handler_mode' => $hm, 'handler_fixed' => $hf,
            'fk_id_handler_party' => (int) ($d['fk_id_handler_party'] ?? 0), 'notes' => mb_substr((string) ($d['notes'] ?? ''), 0, 500), 'f_active' => 1, 'created_by' => Context::userId(), 'created_at' => now()];
        $id = DB::table('t_inv_profit_splits')->insertGetId($row);
        Audit::log('t_inv_profit_splits', $id, 'INSERT', $row, null, $ent);
        return ['id' => $id, 'warning' => in_array('FIXED_SHARE', [$om, $hm], true) ? 'Reminder: a fixed fee plus a profit share pays the same party twice for the same role.' : null];
    }

    public static function retireSplit(int $ent, int $id): void
    {
        if (Context::role() !== 'OWNER') throw new \InvalidArgumentException('Only the Owner changes a profit split.');
        if (! DB::table('t_inv_profit_splits')->where('id', $id)->where('fk_id_ent', $ent)->where('f_active', 1)->update(['f_active' => 0])) throw new \InvalidArgumentException('Profit split not found.');
        Audit::log('t_inv_profit_splits', $id, 'UPDATE', ['f_active' => [1, 0]], 'Profit split removed. Settled months keep the split they were calculated with.', $ent);
    }

    public static function splits(int $ent): array
    {
        return DB::table('t_inv_profit_splits as s')->leftJoin('t_branches as b', 'b.id', '=', 's.fk_id_branch')->where('s.fk_id_ent', $ent)->where('s.f_active', 1)->orderBy('s.fk_id_branch')->orderByDesc('s.d_effective_from')->orderByDesc('s.id')
            ->get(['s.*', 'b.shop_name as shop'])->map(function ($s) { $s->shop = $s->fk_id_branch ? $s->shop : 'Default (all shops)'; $s->handler = $s->fk_id_handler_party ? PartnerService::name((int) $s->fk_id_handler_party) : null; return $s; })->all();
    }

    // ---------- route ----------
    public static function route(int $ent, int $branchId, string $month): string
    {
        $d = $month . '-01';
        $r = DB::table('t_branch_profit_routes')->where('fk_id_company', $ent)->where('fk_id_branch', $branchId)->where('d_start', '<=', $d)->where(fn ($w) => $w->whereNull('d_end')->orWhere('d_end', '>=', $d))->orderByDesc('d_start')->orderByDesc('id')->value('profit_route');
        return $r ?: Choices::get('DEFAULT_PROFIT_ROUTE', $ent);
    }

    public static function setRoute(int $ent, int $branchId, string $route, string $fromMonth, string $reason): void
    {
        if (Context::role() !== 'OWNER') throw new \InvalidArgumentException('Only the Owner changes the profit route.');
        if (! isset(self::ROUTES[$route])) throw new \InvalidArgumentException('Choose the route.');
        if (! preg_match('/^\d{4}-\d{2}$/', $fromMonth)) throw new \InvalidArgumentException('Choose the month the route starts.');
        if (trim($reason) === '') throw new \InvalidArgumentException('Give the reason for the change.');
        if (! DB::table('t_branches')->where('id', $branchId)->where('fk_id_ent', $ent)->exists()) throw new \InvalidArgumentException('Choose the shop.');
        $locked = DB::table('aud_runs')->where('fk_id_ent', $ent)->where('fk_id_branch', $branchId)->where('status', 'LOCKED')->where('period_month', '>=', $fromMonth)->max('period_month');
        if ($locked) throw new \InvalidArgumentException('Month ' . date('M Y', strtotime($locked . '-01')) . ' is locked – the route cannot change before it.');
        DB::transaction(function () use ($ent, $branchId, $route, $fromMonth, $reason) {
            $start = $fromMonth . '-01';
            DB::table('t_branch_profit_routes')->where('fk_id_company', $ent)->where('fk_id_branch', $branchId)->where('d_start', '>=', $start)->delete();   // open months only: replaced, so a shop × month never has two routes
            DB::table('t_branch_profit_routes')->where('fk_id_company', $ent)->where('fk_id_branch', $branchId)->where(fn ($w) => $w->whereNull('d_end')->orWhere('d_end', '>=', $start))->update(['d_end' => date('Y-m-d', strtotime("$start -1 day"))]);
            $id = DB::table('t_branch_profit_routes')->insertGetId(['fk_id_company' => $ent, 'fk_id_branch' => $branchId, 'profit_route' => $route, 'd_start' => $start, 'reason' => $reason, 'created_by' => Context::userId(), 'created_at' => now()]);
            DB::table('aud_runs')->where('fk_id_ent', $ent)->where('fk_id_branch', $branchId)->where('status', '<>', 'LOCKED')->where('period_month', '>=', $fromMonth)->update(['profit_route' => $route]);
            Audit::log('t_branch_profit_routes', $id, 'INSERT', ['shop' => $branchId, 'route' => $route, 'from' => $fromMonth], $reason, $ent);
        });
    }

    /** What the last locked month of this shop left as a loss to carry. */
    public static function lossBroughtForward(int $ent, int $branchId, string $month): float
    {
        $r = DB::table('aud_runs')->where('fk_id_ent', $ent)->where('fk_id_branch', $branchId)->where('status', 'LOCKED')->where('period_month', '<', $month)->orderByDesc('period_month')->orderByDesc('id')->first(['snapshot_json']);
        return $r ? round((float) (json_decode((string) $r->snapshot_json, true)['shares']['loss_cf'] ?? 0), 2) : 0.0;
    }

    /**
     * R-FIN-75. $own = profit of his own units of the PO, $others = profit of the other units, $ownsAny = he owns a lot of that PO.
     * Returns [own-lot profit, the base his share % is taken on, the most the share may be (null = no cap)].
     */
    public static function stack(string $rule, float $own, float $others, bool $ownsAny, ?float $capPct): array
    {
        $total = $own + $others;
        return match ($rule) {
            'NONE' => [$own, $ownsAny ? 0.0 : $total, null],
            'CAPPED' => [$own, $total, $capPct !== null ? round(max(0, $total) * $capPct / 100, 2) : null],
            'FULL_STACK' => [$own, $total, null],
            default => [$own, $ownsAny ? $others : $total, null],   // SPLIT_UNITS
        };
    }

    // ---------- statements ----------
    private static function pdf(string $html): string
    {
        $o = new Options(); $o->set('isRemoteEnabled', false); $o->set('defaultFont', 'DejaVu Sans');
        $d = new Dompdf($o); $d->loadHtml($html, 'UTF-8'); $d->setPaper('A4'); $d->render();
        return $d->output();
    }

    private static function m(float $v): string { return ($v < 0 ? '−' : '') . number_format(abs($v), 2); }

    /** Make (or remake as the next version) the statement of one partner – or of the company when $partner is null – for a locked run. */
    public static function makeStatement(int $ent, object $run, ?int $partner, ?string $banner = null): int
    {
        $snap = json_decode((string) $run->snapshot_json, true) ?: []; $sh = $snap['shares'] ?? []; $fig = $snap['figures'] ?? []; $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES);
        $shop = (string) DB::table('t_branches')->where('id', $run->fk_id_branch)->value('shop_name'); $company = (string) (DB::table('t_enterprise')->where('id', $ent)->value('ent_name') ?? ''); $base = Fx::base($ent);
        $monthName = date('F Y', strtotime($run->period_month . '-01')); $end = date('Y-m-t', strtotime($run->period_month . '-01'));
        $old = DB::table('fin_statements')->where('period_id', $run->id)->when($partner, fn ($q) => $q->where('investor_id', $partner), fn ($q) => $q->whereNull('investor_id'))->max('version'); $v = (int) $old + 1;
        $row = fn (string $a, $b, bool $bold = false) => '<tr><td>' . ($bold ? '<b>' . $e($a) . '</b>' : $e($a)) . '</td><td class="n">' . ($bold ? '<b>' . $b . '</b>' : $b) . '</td></tr>';
        $w = '';
        foreach ($sh['waterfall'] ?? [] as $st) $w .= $row($st['label'], self::m((float) $st['amount']), ! empty($st['total']));
        $lines = $sh['lines'] ?? []; $mine = $partner ? array_values(array_filter($lines, fn ($l) => (int) $l['partner'] === $partner)) : $lines; $tot = 0.0; $lt = '';
        foreach ($mine as $l) { $tot += $l['profit'] + $l['fee'];
            $lt .= '<tr>' . ($partner ? '' : '<td>' . $e($l['partner_name'] ?? '') . '</td>') . '<td>' . $e(($l['kind'] ?? 'SHARE') === 'OWN_LOT' ? 'Own lots – ' . ($l['scope_name'] ?? '') : ($l['scope_name'] ?? '')) . '</td><td class="n">' . rtrim(rtrim(number_format((float) $l['pct'], 3), '0'), '.') . '%</td><td class="n">' . self::m((float) $l['base']) . '</td><td class="n">' . self::m((float) $l['profit']) . '</td><td class="n">' . self::m((float) $l['fee']) . '</td></tr>'; }
        $adj = $partner ? DB::table('fin_investor_ledger')->where('company_id', $ent)->where('investor_id', $partner)->where('type', 'ADJUSTMENT')->where('reference_no', 'ADJ ' . $run->run_code)->orderBy('id')->get(['entry_date', 'amount', 'note']) : collect();
        $acc = $partner ? PartnerService::account($ent, $partner, $end) : null; $accNow = $partner && $adj->isNotEmpty() ? PartnerService::account($ent, $partner) : null;
        $html = '<html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans;font-size:10.5px;color:#111}h1{font-size:17px;margin:0}h2{font-size:12px;margin:16px 0 4px;border-bottom:1px solid #999;padding-bottom:2px}table{width:100%;border-collapse:collapse}td,th{padding:3px 5px;border-bottom:1px solid #ddd;text-align:left}th{background:#f1f1f1}.n{text-align:right}.banner{border:1px solid #b45309;background:#fff7ed;padding:6px;margin:8px 0}.mut{color:#555}</style></head><body>'
            . '<h1>' . ($partner ? 'Partner statement' : 'Company statement') . '</h1><div class="mut">' . $e($company) . ' · ' . $e($shop) . ' · ' . $e($monthName) . ' · ' . $e($run->run_code) . ' · version ' . $v . ' · amounts in ' . $e($base) . '</div>'
            . ($partner ? '<div><b>' . $e(PartnerService::name($partner)) . '</b></div>' : '')
            . ($banner ? '<div class="banner"><b>This version replaces version ' . ($v - 1) . '.</b> ' . $e($banner) . '</div>' : '')
            . '<h2>How the profit of the month was reached</h2><table>' . $row('Sales confirmed', self::m((float) ($fig['sales'] ?? 0))) . $row('Shop profit' . (! empty($fig['share_after_overheads']) ? ' after its share of the overheads' : ''), self::m((float) ($fig['profit_to_share'] ?? $fig['profit'] ?? 0)), true) . $w . '</table>'
            . '<h2>' . ($partner ? 'Your share' : 'Partner shares') . '</h2>' . ($mine ? '<table><tr>' . ($partner ? '' : '<th>Partner</th>') . '<th>Scope</th><th class="n">%</th><th class="n">Base</th><th class="n">Profit</th><th class="n">Fee</th></tr>' . $lt . '<tr><td colspan="' . ($partner ? 4 : 5) . '"><b>Total</b></td><td class="n"><b>' . self::m($tot) . '</b></td></tr></table>' : '<div class="mut">No share in this month.</div>')
            . ($adj->isNotEmpty() ? '<h2>Adjustments to this month (booked forward, never in the locked month)</h2><table>' . $adj->map(fn ($a) => '<tr><td>' . $e($a->entry_date) . '</td><td>' . $e($a->note) . '</td><td class="n">' . self::m((float) $a->amount) . '</td></tr>')->implode('') . '</table>' : '')
            . ($acc ? '<h2>Your account as at ' . $e($end) . '</h2><table>' . $row('Capital paid in', self::m((float) $acc['capital'])) . $row('Profit and fees to date', self::m((float) $acc['profit_to_date'])) . $row('Withdrawn', self::m((float) $acc['withdrawn'])) . $row('Balance the company owes you', self::m((float) $acc['balance']), true) . ($accNow ? $row('Balance today, with the adjustment(s)', self::m((float) $accNow['balance']), true) : '') . '</table>' : '')
            . (! $partner ? '<h2>Other parties</h2><table>' . implode('', array_map(fn ($r) => $row($r['label'], self::m((float) $r['amount'])), $sh['roles'] ?? [])) . $row('Left in the company', self::m((float) ($sh['left_for_company'] ?? 0)), true) . '</table>' : '')
            . '<h2>Owner note</h2><div>' . $e($run->owner_note) . '</div>'
            . (! empty($snap['checks']) && array_filter($snap['checks'], fn ($c) => $c['status'] === 'WAIVED') ? '<h2>Checks waived by the Owner</h2><table>' . implode('', array_map(fn ($c) => '<tr><td>' . $e($c['code']) . '</td><td>' . $e($c['waive_reason'] ?? '') . '</td></tr>', array_filter($snap['checks'], fn ($c) => $c['status'] === 'WAIVED'))) . '</table>' : '')
            . '<div class="mut" style="margin-top:14px">Locked ' . $e($run->locked_at) . '. A locked month is never edited; a correction is booked in the next open month and shown on a new version of this statement.</div></body></html>';
        $path = 'statements/' . $ent . '/' . substr($run->period_month, 0, 4) . '/' . $run->period_month . '_' . preg_replace('/[^A-Za-z0-9_-]/', '', str_replace(' ', '_', $shop)) . '_' . $run->run_code . '/' . ($partner ? "partner_{$partner}_v$v.pdf" : "company_v$v.pdf");
        try { Storage::disk('local')->put($path, self::pdf($html)); $type = 'PDF'; }
        catch (\Throwable $ex) { report($ex); Storage::disk('local')->put($path = preg_replace('/\.pdf$/', '.pending.html', $path), $html); $type = 'HTML'; }   // saved as data; the screen shows "PDF pending"
        DB::table('fin_statements')->where('period_id', $run->id)->when($partner, fn ($q) => $q->where('investor_id', $partner), fn ($q) => $q->whereNull('investor_id'))->update(['superseded' => 1]);
        return DB::table('fin_statements')->insertGetId(['period_id' => $run->id, 'investor_id' => $partner, 'version' => $v, 'superseded' => 0, 'file_path' => $path, 'file_type' => $type, 'partner_profit' => $partner ? round($tot, 2) : null, 'partner_balance' => $acc['balance'] ?? null, 'created_at' => now()]);
    }

    /** At the lock: the company statement and one per partner with a share. */
    public static function makeStatements(int $ent, int $runId): int
    {
        $run = DB::table('aud_runs')->where('id', $runId)->first(); $sh = json_decode((string) $run->snapshot_json, true)['shares'] ?? []; $n = 1;
        self::makeStatement($ent, $run, null);
        foreach (array_unique(array_map(fn ($l) => (int) $l['partner'], $sh['lines'] ?? [])) as $p) { self::makeStatement($ent, $run, $p); $n++; }
        return $n;
    }

    public static function statementFiles(int $ent, ?int $partner = null, ?int $runId = null): array
    {
        return DB::table('fin_statements as s')->join('aud_runs as r', 'r.id', '=', 's.period_id')->leftJoin('t_branches as b', 'b.id', '=', 'r.fk_id_branch')->where('r.fk_id_ent', $ent)
            ->when($partner, fn ($q) => $q->where('s.investor_id', $partner))->when($runId, fn ($q) => $q->where('s.period_id', $runId))->orderByDesc('r.period_month')->orderBy('s.investor_id')->orderByDesc('s.version')
            ->get(['s.id', 's.period_id as run', 's.investor_id', 's.version', 's.superseded', 's.file_type', 's.partner_profit', 's.created_at', 'r.period_month', 'r.run_code', 'b.shop_name as shop'])
            ->map(function ($s) { $s->partner_name = $s->investor_id ? PartnerService::name((int) $s->investor_id) : 'Company'; $s->pending = $s->file_type !== 'PDF'; return $s; })->all();
    }

    /** [bytes, file name] of a statement; a partner login may read only his own. */
    public static function statementFile(int $ent, int $id, ?int $onlyPartner): array
    {
        $s = DB::table('fin_statements as s')->join('aud_runs as r', 'r.id', '=', 's.period_id')->where('s.id', $id)->where('r.fk_id_ent', $ent)->first(['s.*', 'r.period_month', 'r.run_code']) ?? throw new \InvalidArgumentException('Statement not found.');
        if ($onlyPartner && (int) $s->investor_id !== $onlyPartner) throw new \InvalidArgumentException('You can only open your own statements.');
        if ($s->file_type !== 'PDF' || ! Storage::disk('local')->exists($s->file_path)) throw new \InvalidArgumentException('The PDF of this statement is not ready yet (PDF pending). The figures are on the screen.');
        return [Storage::disk('local')->get($s->file_path), 'statement_' . $s->period_month . '_' . ($s->investor_id ? 'partner_' . $s->investor_id : 'company') . '_v' . $s->version . '.pdf'];
    }

    // ---------- adjustment to a locked month (R-FIN-47) ----------
    public static function adjust(int $ent, int $runId, int $partner, float $amount, string $note): array
    {
        if (Context::role() !== 'OWNER') throw new \InvalidArgumentException('Only the Owner adds an adjustment.');
        return DB::transaction(function () use ($ent, $runId, $partner, $amount, $note) {
            $run = DB::table('aud_runs')->where('id', $runId)->where('fk_id_ent', $ent)->lockForUpdate()->first() ?? throw new \InvalidArgumentException('Month not found.');
            if ($run->status !== 'LOCKED') throw new \InvalidArgumentException('Adjustments are only for LOCKED months. An open month is simply corrected.');
            if (round($amount, 2) == 0.0) throw new \InvalidArgumentException('Amount cannot be 0.');
            if (trim($note) === '') throw new \InvalidArgumentException('A note is mandatory.');
            if (! DB::table('fin_investor_agreements')->where('company_id', $ent)->where('investor_id', $partner)->exists() && ! DB::table('fin_investor_ledger')->where('company_id', $ent)->where('investor_id', $partner)->exists()) throw new \InvalidArgumentException('Choose a partner of this company.');
            // the shop's next month that is not locked (a month always exists, so nothing waits)
            $m = $run->period_month;
            do { $m = date('Y-m', strtotime($m . '-01 +1 month')); } while (DB::table('aud_runs')->where('fk_id_ent', $ent)->where('fk_id_branch', $run->fk_id_branch)->where('period_month', $m)->where('status', 'LOCKED')->exists());
            $date = $m . '-01'; $gl = (string) Settings::get('GL_LOCKED_UPTO', $ent, ''); if ($gl !== '' && $date <= $gl) $date = date('Y-m-d', strtotime("$gl +1 day"));
            $amount = round($amount, 2); $from = date('Y-m-01', strtotime($run->period_month . '-01')); $to = date('Y-m-t', strtotime($from));
            $id = DB::table('fin_investor_ledger')->insertGetId(['investor_id' => $partner, 'company_id' => $ent, 'period_id' => $run->id, 'entry_date' => $date, 'type' => 'ADJUSTMENT', 'amount' => $amount, 'reference_no' => 'ADJ ' . $run->run_code,
                'note' => mb_substr("Adjustment to period $from to $to: " . trim($note), 0, 255), 'created_by' => Context::userId(), 'created_at' => now()]);
            $name = PartnerService::name($partner);
            Ledger::post($ent, $date, 'PARTNER_ADJUST', $id, [['account' => '7000', $amount > 0 ? 'debit' : 'credit' => abs($amount), 'ref_id' => $partner], ['account' => '2100', $amount > 0 ? 'credit' : 'debit' => abs($amount), 'ref_id' => $partner]], ['memo' => "Adjustment to {$run->period_month} · $name"]);
            $st = self::makeStatement($ent, $run, $partner, 'Adjustment of ' . self::m($amount) . ' booked on ' . $date . ': ' . trim($note));
            Audit::log('aud_runs', $run->id, 'UPDATE', ['adjustment' => $amount, 'partner' => $partner, 'booked_on' => $date], $note, $ent);
            EventBus::fire('STATEMENT_READY', 'fin_statements', $st, ['partner' => $partner, 'run' => $run->id, 'updated' => true], $ent);
            return ['ledger_id' => $id, 'booked_on' => $date, 'statement' => $st, 'version' => (int) DB::table('fin_statements')->where('id', $st)->value('version')];
        });
    }
}
