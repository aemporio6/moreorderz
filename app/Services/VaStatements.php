<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * VA statement per shop + month (RULES_A_SALES R-A_SALES-65, one formula with R-C-40):
 * after-fee profit = sale − buy − fee; VA share = VA % × after-fee profit; net to store = after-fee profit − VA share − expenses.
 * With the choice VA_EXPENSES_BEFORE_SHARE = 1 the share is worked out on (after-fee profit − expenses).
 * Fee, VA and VA % are read from the import batch – never typed. Expenses and their detail are typed.
 */
class VaStatements
{
    /** The pure formula. */
    public static function calc(float $sale, float $buy, float $fee, float $expenses, float $pct, bool $expensesFirst): array
    {
        $after = round($sale - $buy - $fee, 2); $share = round(($expensesFirst ? $after - $expenses : $after) * $pct / 100, 2);
        return ['after_fee_profit' => $after, 'va_share_amount' => $share, 'net_to_store' => round($after - $share - $expenses, 2)];
    }

    private static function month(string $m): string { if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) throw new \InvalidArgumentException('Choose the month.'); return $m; }

    /** What the statement of a shop + month would say now (nothing is saved). */
    public static function preview(int $shopId, string $month, float $expenses = 0.0): array
    {
        $month = self::month($month); $ent = (int) Context::ent(); [$y, $m] = array_map('intval', explode('-', $month));
        $shop = DB::table('t_branches')->where('id', $shopId)->where('fk_id_ent', $ent)->first() ?? throw new \InvalidArgumentException('Shop not found.');
        if (! Perm::shopAllowed(\Illuminate\Support\Facades\Auth::user(), $shopId)) abort(403, "You don't have rights for this shop.");
        $b = SalesImport::batchOf($shopId, $m, $y, 'Normal') ?? throw new \InvalidArgumentException('Shop ' . SalesImport::label($shop) . ' has no sales import for ' . SalesImport::monthName($m, $y) . '. Import the month first.');
        SalesReports::syncShares((int) $b->id); $b = DB::table('t_sale_imports')->where('id', $b->id)->first();
        $t = DB::table('t_sales')->where('fk_id_import', $b->id)->where('status', '<>', 'Cancelled')->selectRaw('COALESCE(SUM(total_amount),0) sale, COALESCE(SUM(buy_amount),0) buy, COUNT(*) n')->first();
        $va = (int) ($b->fk_id_va_account ?: $shop->fk_id_seller); $acc = $va ? DB::table('t_account')->where('id', $va)->first() : null; $first = Choices::get('VA_EXPENSES_BEFORE_SHARE', $ent, $shopId) === '1';
        $c = self::calc((float) $t->sale, (float) $t->buy, (float) $b->marketplace_fee, $expenses, (float) $b->va_share_percentage, $first);
        return ['shop' => $shopId, 'shop_label' => SalesImport::label($shop), 'shop_no' => $shop->shop_no, 'month' => $month, 'month_name' => SalesImport::monthName($m, $y), 'batch' => (int) $b->id, 'batch_status' => $b->status, 'lines' => (int) $t->n,
            'va' => $va ?: null, 'va_name' => $acc ? trim(($acc->account_code ? $acc->account_code . ' - ' : '') . trim($acc->first_name . ' ' . $acc->sur_name)) : null,
            'sale_total' => round((float) $t->sale, 2), 'buy_total' => round((float) $t->buy, 2), 'fee_total' => round((float) $b->marketplace_fee, 2), 'expenses' => round($expenses, 2), 'va_share_pct' => (float) $b->va_share_percentage, 'expenses_before_share' => $first,
            'basis_label' => $first ? 'after-fee profit less expenses' : 'after-fee profit', 'last' => DB::table('va_statements')->where('fk_id_ent', $ent)->where('fk_id_branch', $shopId)->where('period_month', $month . '-01')->orderByDesc('version_no')->first(['id', 'version_no', 'status', 'expenses', 'expenses_detail'])] + $c;
    }

    /** Save a new version of the statement (DRAFT → waits for the Owner). Expenses and their detail are required (0 is allowed). */
    public static function generate(int $shopId, string $month, mixed $expenses, ?string $detail, ?object $u): array
    {
        Perm::need($u, 'orders.vaStatement');
        if ($expenses === null || trim((string) $expenses) === '' || ! is_numeric($expenses) || (float) $expenses < 0) throw new \InvalidArgumentException('Enter the expenses. Use 0 if there are none.');
        if ((float) $expenses > 0 && trim((string) $detail) === '') throw new \InvalidArgumentException('Write what the expenses are (Expenses detail).');
        return DB::transaction(function () use ($shopId, $month, $expenses, $detail, $u) {
            $p = self::preview($shopId, $month, (float) $expenses); $ent = (int) Context::ent();
            if (! $p['va']) throw new \InvalidArgumentException('Shop ' . $p['shop_label'] . ' has no VA (seller). Set the VA of the shop first.');
            $v = (int) DB::table('va_statements')->where('fk_id_ent', $ent)->where('fk_id_branch', $shopId)->where('period_month', $month . '-01')->lockForUpdate()->max('version_no') + 1;
            DB::table('va_statements')->where('fk_id_ent', $ent)->where('fk_id_branch', $shopId)->where('period_month', $month . '-01')->whereIn('status', ['DRAFT', 'WAITING'])->update(['status' => 'REPLACED']);
            $id = (int) DB::table('va_statements')->insertGetId(['fk_id_ent' => $ent, 'fk_id_branch' => $shopId, 'fk_id_va' => $p['va'], 'period_month' => $month . '-01', 'fk_id_batch' => $p['batch'], 'version_no' => $v, 'sale_total' => $p['sale_total'], 'buy_total' => $p['buy_total'], 'fee_total' => $p['fee_total'],
                'expenses' => $p['expenses'], 'expenses_detail' => trim((string) $detail) ?: null, 'profit' => $p['after_fee_profit'], 'after_fee_profit' => $p['after_fee_profit'], 'va_share_basis' => $p['expenses_before_share'] ? 'after_fee_less_expenses' : 'after_fee', 'va_share_pct' => $p['va_share_pct'], 'va_share_amount' => $p['va_share_amount'],
                'net_to_store' => $p['net_to_store'], 'f_expenses_before_share' => $p['expenses_before_share'] ? 1 : 0, 'status' => 'WAITING', 'created_by' => $u->id ?? null, 'created_at' => now()]);
            $file = self::pdf($id);
            DB::table('t_sale_buy_main')->where('fk_id_branch', $shopId)->where('yr', (int) substr($month, 0, 4))->where('mon', (int) substr($month, 5, 2))->update(['f_summary_generated' => 1]);      // the old month row, when it exists
            $owners = DB::table('t_users')->where('fk_id_ent', $ent)->where('role_code', 'OWNER')->where('f_active', 1)->pluck('id');
            foreach ($owners as $o) Notify::push((int) $o, 'APPROVAL', "VA statement to approve: {$p['shop_label']} {$p['month_name']} (version $v)", '#/va-statements');
            Audit::log('va_statements', $id, 'INSERT', ['shop' => $shopId, 'month' => $month, 'version' => $v, 'va_share' => $p['va_share_amount']], null, $ent);
            return ['message' => "Statement version $v saved. It waits for the Owner's approval.", 'id' => $id, 'version' => $v, 'file' => $file] + $p;
        });
    }

    public static function fileName(object $s): string
    {
        $no = preg_replace('/[^A-Za-z0-9\-]/', '', (string) DB::table('t_branches')->where('id', $s->fk_id_branch)->value('shop_no'));
        return 'va_statement_' . $no . '_' . substr((string) $s->period_month, 0, 7) . '.pdf';
    }

    /** Writes the PDF of one version (kept; a new version never overwrites an old file). Returns the stored path. */
    public static function pdf(int $id): string
    {
        $s = DB::table('va_statements')->where('id', $id)->first() ?? throw new \InvalidArgumentException('Statement not found.');
        $shop = DB::table('t_branches')->where('id', $s->fk_id_branch)->first(); $va = DB::table('t_account')->where('id', $s->fk_id_va)->first(); $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); $m = fn ($v) => number_format((float) $v, 2);
        $rows = [['Sale total', $m($s->sale_total)], ['Buy total', $m($s->buy_total)], ['Fee (sale)', $m($s->fee_total)], ['After-fee profit', $m($s->after_fee_profit)]];
        if ((int) $s->f_expenses_before_share) $rows[] = ['Expenses' . ($s->expenses_detail ? ' – ' . $s->expenses_detail : ''), $m($s->expenses)];
        $rows[] = ['VA share ' . rtrim(rtrim(number_format((float) $s->va_share_pct, 2, '.', ''), '0'), '.') . ' % of ' . ((int) $s->f_expenses_before_share ? 'after-fee profit less expenses' : 'after-fee profit'), $m($s->va_share_amount)];
        if (! (int) $s->f_expenses_before_share) $rows[] = ['Expenses' . ($s->expenses_detail ? ' – ' . $s->expenses_detail : ''), $m($s->expenses)];
        $rows[] = ['Net to store', $m($s->net_to_store)];
        $html = '<html><head><meta charset="utf-8"><style>body{font-family:"DejaVu Sans";font-size:11px}h1{font-size:18px;margin:0 0 4px}table{width:100%;border-collapse:collapse;margin-top:14px}td{border-bottom:0.5px solid #b8c4cf;padding:6px 4px}td.n{text-align:right}tr.b td{font-weight:bold;background:#f1f3f5}.s{color:#555}</style></head><body>'
            . '<h1>VA statement</h1><div class="s">Shop: ' . $e(SalesImport::label($shop)) . '<br>VA: ' . $e(trim(($va->account_code ?? '') . ' - ' . trim(($va->first_name ?? '') . ' ' . ($va->sur_name ?? '')), ' -')) . '<br>Period: ' . $e(date('F Y', strtotime((string) $s->period_month))) . ' · version ' . (int) $s->version_no . ' · ' . $e($s->status === 'APPROVED' ? 'approved' : 'not approved yet') . '</div><table>'
            . implode('', array_map(fn ($r) => '<tr' . (in_array($r[0], ['After-fee profit', 'Net to store'], true) || str_starts_with($r[0], 'VA share') ? ' class="b"' : '') . '><td>' . $e($r[0]) . '</td><td class="n">' . $e($r[1]) . '</td></tr>', $rows)) . '</table></body></html>';
        $dir = storage_path('app/va_statements/' . $id); if (! is_dir($dir)) mkdir($dir, 0775, true);
        $path = $dir . '/' . self::fileName($s); file_put_contents($path, PrintService::pdf($html, 210, 297));
        $rel = 'va_statements/' . $id . '/' . self::fileName($s); DB::table('va_statements')->where('id', $id)->update(['pdf_file' => $rel]);
        return $rel;
    }

    /** Owner approval: the statement is final, the VA share and the salary of the month become payables for Finance (one each – a later version updates them), the VA gets the statement. */
    public static function approve(int $id, ?object $u): array
    {
        Perm::need($u, 'orders.vaStatementApprove');
        return DB::transaction(function () use ($id, $u) {
            $s = DB::table('va_statements')->where('id', $id)->where('fk_id_ent', Context::ent())->lockForUpdate()->first() ?? throw new \InvalidArgumentException('Statement not found.');
            if ($s->status !== 'WAITING') throw new \InvalidArgumentException($s->status === 'APPROVED' ? 'This statement is already approved.' : 'Only the newest version that waits can be approved. This one is ' . strtolower($s->status) . '.');
            $ent = (int) $s->fk_id_ent; $ym = substr((string) $s->period_month, 0, 7); $end = date('Y-m-t', strtotime((string) $s->period_month)); $shop = DB::table('t_branches')->where('id', $s->fk_id_branch)->first();
            DB::table('va_statements')->where('fk_id_ent', $ent)->where('fk_id_branch', $s->fk_id_branch)->where('period_month', $s->period_month)->where('status', 'APPROVED')->update(['status' => 'REPLACED']);
            DB::table('va_statements')->where('id', $id)->update(['status' => 'APPROVED', 'approved_by' => $u->id, 'approved_at' => now()]);
            // payable 1: the VA share of this shop + month (paid through bank category VA_SHARE_PAYOUT – the payout goes to the payable account, never to cost again)
            self::commit($ent, "VA_SHARE:{$s->fk_id_va}:{$s->fk_id_branch}:$ym", ['source_table' => 'va_statements', 'source_id' => $id, 'what' => mb_substr('VA share ' . SalesImport::label($shop) . ' ' . date('M Y', strtotime((string) $s->period_month)), 0, 120), 'due_on' => $end,
                'amount' => max(0, (float) $s->va_share_amount), 'amount_gbp' => max(0, (float) $s->va_share_amount), 'fk_id_payee' => $s->fk_id_va, 'category_code' => 'VA_SHARE_PAYOUT', 'fk_id_va_statement' => $id]);
            // payable 2: one salary per VA × job-history row × month, whatever the number of shops or versions
            $job = DB::table('t_account_job_history')->where('fk_id_account', $s->fk_id_va)->where('fk_id_marketplace', $shop->fk_id_marketplace)->where(fn ($w) => $w->whereNull('d_job_start_date')->orWhere('d_job_start_date', '<=', $end))->where(fn ($w) => $w->whereNull('d_end_date')->orWhere('d_end_date', '>=', $end))->orderByDesc('d_job_start_date')->orderByDesc('id')->first();
            $salary = null;
            if ($job && (float) $job->salary > 0) {
                $cur = Fx::code((int) $job->fk_id_currency ?: null, $ent); $rate = (float) $job->pound_exchange_rate;      // the job row's own £ rate first, else the month-end rate
                $gbp = $rate > 0 && abs($rate - 1) > 0.000001 ? round((float) $job->salary / $rate, 2) : ($cur === Fx::base($ent) ? (float) $job->salary : Fx::toBase((float) $job->salary, $cur, $end, $ent)); $salary = $gbp;
                self::commit($ent, "SALARY:{$s->fk_id_va}:{$job->id}:$ym", ['source_table' => 't_account_job_history', 'source_id' => $job->id, 'what' => mb_substr('VA salary ' . date('M Y', strtotime((string) $s->period_month)), 0, 120), 'due_on' => $end, 'amount' => (float) $job->salary, 'amount_gbp' => $gbp,
                    'fk_id_payee' => $s->fk_id_va, 'category_code' => 'SALARY_VA', 'fk_id_currency' => (int) $job->fk_id_currency ?: null, 'fk_id_va_statement' => $id]);
            }
            self::pdf($id); $sent = self::send($id);
            Audit::log('va_statements', $id, 'APPROVE', ['va_share' => (float) $s->va_share_amount, 'salary_gbp' => $salary], null, $ent);
            EventBus::fire('VA_STATEMENT_APPROVED', 'va_statements', $id, ['shop' => (int) $s->fk_id_branch, 'month' => $ym, 'va' => (int) $s->fk_id_va, 'va_share' => (float) $s->va_share_amount, 'version' => (int) $s->version_no], $ent);
            if ($s->created_by) Notify::push((int) $s->created_by, 'APPROVAL', 'VA statement approved: ' . SalesImport::label($shop) . ' ' . date('M Y', strtotime((string) $s->period_month)), '#/va-statements');
            return ['message' => 'Approved. The VA share is now a payable for Finance' . ($sent ? ' and the statement was sent to the VA.' : '. The VA has no WhatsApp number or e-mail, so nothing was sent.'), 'sent' => $sent, 'salary_gbp' => $salary];
        });
    }

    private static function commit(int $ent, string $key, array $d): void
    {
        $have = DB::table('fin_cash_commitments')->where('dedupe_key', $key)->first();
        if ($have) { if ($have->status !== 'PAID') DB::table('fin_cash_commitments')->where('id', $have->id)->update($d + ['status' => 'COMMITTED', 'updated_at' => now()]); return; }
        DB::table('fin_cash_commitments')->insert($d + ['fk_id_enterprise' => $ent, 'dedupe_key' => $key, 'status' => 'COMMITTED', 'updated_at' => now()]);
    }

    public static function reject(int $id, string $reason, ?object $u): array
    {
        Perm::need($u, 'orders.vaStatementApprove'); if (mb_strlen(trim($reason)) < 3) throw new \InvalidArgumentException('Write the reason.');
        $s = DB::table('va_statements')->where('id', $id)->where('fk_id_ent', Context::ent())->first() ?? throw new \InvalidArgumentException('Statement not found.');
        if ($s->status !== 'WAITING') throw new \InvalidArgumentException('Only a statement that waits can be sent back.');
        DB::table('va_statements')->where('id', $id)->update(['status' => 'REJECTED', 'reject_reason' => mb_substr(trim($reason), 0, 255)]);
        if ($s->created_by) Notify::push((int) $s->created_by, 'APPROVAL', 'VA statement sent back: ' . mb_substr(trim($reason), 0, 120), '#/va-statements');
        Audit::log('va_statements', $id, 'REJECT', [], trim($reason), (int) $s->fk_id_ent);
        return ['message' => 'Sent back with your reason.'];
    }

    /** WhatsApp (and e-mail with the PDF) to the VA. Returns the channels used. */
    public static function send(int $id): array
    {
        $s = DB::table('va_statements')->where('id', $id)->first(); $shop = DB::table('t_branches')->where('id', $s->fk_id_branch)->first(); $out = [];
        $vars = ['shop' => SalesImport::label($shop), 'month' => date('F Y', strtotime((string) $s->period_month)), 'profit' => number_format((float) $s->after_fee_profit, 2), 'pct' => rtrim(rtrim(number_format((float) $s->va_share_pct, 2, '.', ''), '0'), '.'), 'share' => number_format((float) $s->va_share_amount, 2), 'link' => rtrim((string) config('app.url'), '/') . '/#/va-statements'];
        if ($p = Connect\Messenger::whatsappOf((int) $s->fk_id_va)) { Connect\Messenger::queueWa((int) $s->fk_id_ent, (int) $s->fk_id_va, $p, 'VA_STATEMENT', $vars, 'va_statements', $id); $out[] = 'whatsapp'; }
        if ($e = Connect\Messenger::emailOf((int) $s->fk_id_va)) { [$body] = Connect\Messenger::render('VA_STATEMENT', $vars); Connect\Messenger::queueEmail((int) $s->fk_id_ent, (int) $s->fk_id_va, $e, 'Your statement ' . $vars['month'] . ' – ' . $vars['shop'], $body, $s->pdf_file ? storage_path('app/' . $s->pdf_file) : null, 'VA_STATEMENT', 'va_statements', $id); $out[] = 'email'; }
        if ($out) DB::table('va_statements')->where('id', $id)->update(['sent_at' => now()]);
        return $out;
    }

    /** A bank line categorised as VA share payout / VA salary to this person closes the open payable of that amount (oldest first). */
    public static function bankPaid(int $ent, string $category, ?int $payee, float $amountGbp, int $bankLineId): ?int
    {
        if (! in_array($category, ['VA_SHARE_PAYOUT', 'SALARY_VA'], true) || ! $payee) return null;
        $c = DB::table('fin_cash_commitments')->where('fk_id_enterprise', $ent)->where('category_code', $category)->where('fk_id_payee', $payee)->where('status', 'COMMITTED')->whereBetween('amount_gbp', [$amountGbp - 0.01, $amountGbp + 0.01])->orderBy('due_on')->first();
        if (! $c) return null;
        DB::table('fin_cash_commitments')->where('id', $c->id)->update(['status' => 'PAID', 'fk_id_bank_line' => $bankLineId, 'updated_at' => now()]);
        return (int) $c->id;
    }

    /** The list: newest month first. A VA sees only his own, approved statements. */
    public static function list(?object $u, array $f = []): array
    {
        $ent = (int) Context::ent(); $own = $u && ($u->role_code ?? '') === 'VA' ? (int) DB::table('t_account')->where('fk_id_ent', $ent)->where('login_id', $u->login_id)->value('id') : null;
        $q = DB::table('va_statements as s')->join('t_branches as b', 'b.id', '=', 's.fk_id_branch')->leftJoin('t_account as a', 'a.id', '=', 's.fk_id_va')->where('s.fk_id_ent', $ent); Perm::mine($q, 's.fk_id_branch');
        if ($own !== null) $q->where('s.fk_id_va', $own ?: -1)->where('s.status', 'APPROVED');
        if (! empty($f['shop'])) $q->where('s.fk_id_branch', (int) $f['shop']); if (empty($f['all'])) $q->whereNotIn('s.status', ['REPLACED']);
        $rows = $q->orderByDesc('s.period_month')->orderBy('b.shop_no')->orderByDesc('s.version_no')->limit(300)->get(['s.*', 'b.shop_no', 'b.shop_name', DB::raw("TRIM(CONCAT_WS(' ', a.first_name, a.sur_name)) as va_name"), 'a.account_code'])
            ->map(fn ($s) => ['id' => $s->id, 'shop' => $s->shop_no . ' (' . $s->shop_name . ')', 'va' => trim(($s->account_code ? $s->account_code . ' - ' : '') . $s->va_name), 'month' => date('F Y', strtotime((string) $s->period_month)), 'version' => (int) $s->version_no, 'sale' => (float) $s->sale_total, 'buy' => (float) $s->buy_total, 'fee' => (float) $s->fee_total,
                'after_fee' => (float) $s->after_fee_profit, 'pct' => (float) $s->va_share_pct, 'share' => (float) $s->va_share_amount, 'expenses' => (float) $s->expenses, 'detail' => $s->expenses_detail, 'net' => (float) $s->net_to_store, 'status' => $s->status, 'reason' => $s->reject_reason, 'has_pdf' => (bool) $s->pdf_file, 'sent' => (bool) $s->sent_at])->all();
        return ['rows' => $rows, 'can_make' => $u && Perm::has($u, 'orders.vaStatement'), 'can_approve' => $u && Perm::has($u, 'orders.vaStatementApprove'), 'shops' => $own !== null ? [] : array_map(fn ($x) => ['id' => $x['id'], 'name' => $x['label']], ShopLabel::list($u, ['active_only' => true]))];
    }
}
